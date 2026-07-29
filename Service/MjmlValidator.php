<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Service;

/**
 * Validates an MJML fragment returned by the model before it reaches the canvas.
 *
 * Model output is treated as untrusted. Nothing gets through unless it is on
 * this allowlist, and nothing half-validated is ever returned.
 */
final class MjmlValidator
{
    /** Accepted MJML tags. A subset of what grapesjs-mjml can parse. */
    public const ALLOWED_TAGS = [
        'mj-section', 'mj-column', 'mj-group',
        'mj-text', 'mj-image', 'mj-button', 'mj-divider', 'mj-spacer',
    ];

    /**
     * Tags MJML treats as empty and that browsers will not close on their own.
     * They must always be emitted with an explicit closing tag, or the canvas breaks.
     *
     * @see https://github.com/GrapesJS/mjml/issues/149
     */
    private const VOID_MJ_TAGS = ['mj-image', 'mj-divider', 'mj-spacer', 'mj-font'];

    /** HTML accepted inside mj-text and mj-button. */
    public const ALLOWED_INLINE_TAGS = [
        'p', 'br', 'strong', 'em', 'b', 'i', 'u', 'a',
        'ul', 'ol', 'li', 'span', 'h1', 'h2', 'h3', 'h4',
    ];

    /** MJML tags whose content is HTML rather than MJML. */
    private const RICH_TEXT_TAGS = ['mj-text', 'mj-button'];

    private const SAFE_SCHEMES = ['http', 'https', 'mailto'];

    private const MAX_BYTES = 12288;

    private const MAX_DEPTH = 8;

    /** Below this fraction of the original text, treat it as content loss. */
    private const MIN_TEXT_RATIO = 0.6;

    /**
     * Keyword based intent detection, deliberately crude.
     * Spending another model call to classify intent is not worth it.
     * The lists are multilingual on purpose: people prompt in their own
     * language, and a miss here only softens a warning, never validity.
     */
    public const DEFAULT_LINK_KEYWORDS = [
        'link', 'links', 'botão', 'botao', 'button', 'url', 'ligação', 'ligacao', 'hiperligação', 'cta',
    ];

    public const DEFAULT_SHORTEN_KEYWORDS = [
        'encurta', 'encurtar', 'resume', 'resumir', 'corta', 'cortar', 'reescreve', 'reescrever',
        'mais curto', 'metade', 'shorten', 'summarise', 'summarize', 'cut', 'rewrite', 'shorter', 'trim',
    ];

    /**
     * @param string[] $linkKeywords
     * @param string[] $shortenKeywords
     */
    public function __construct(
        private readonly string $placeholderImageSrc = '',
        private readonly array $linkKeywords = self::DEFAULT_LINK_KEYWORDS,
        private readonly array $shortenKeywords = self::DEFAULT_SHORTEN_KEYWORDS,
    ) {
    }

    /**
     * @param string|null $source       original MJML, edit mode only
     * @param bool        $finalAttempt on the final attempt the soft invariants
     *                                  downgrade to warnings instead of failing
     */
    public function validate(
        string $raw,
        ?string $source = null,
        string $prompt = '',
        bool $finalAttempt = false,
    ): ValidationResult {
        $mjml = trim($raw);

        if ('' === $mjml) {
            return ValidationResult::fail(['The model returned an empty response.']);
        }

        if (strlen($mjml) > self::MAX_BYTES) {
            return ValidationResult::fail([
                sprintf('The MJML is %d bytes and the limit is 12 KB (%d bytes).', strlen($mjml), self::MAX_BYTES),
            ]);
        }

        // Preprocessing. Without it strict XML parsing rejects perfectly good MJML:
        // the HTML inside mj-text has unclosed tags and named entities.
        $richText = [];
        $prepared = $this->extractRichText($mjml, $richText);
        $prepared = $this->dedupeAttributes($prepared);
        $prepared = $this->convertNamedEntities($prepared);

        $parseErrors = [];
        $document    = $this->parse($prepared, $parseErrors);

        if (null === $document) {
            return ValidationResult::fail($parseErrors);
        }

        $root     = $document->documentElement;
        $errors   = [];
        $warnings = [];

        $this->assertTopLevelIsSections($root, $errors);
        $this->walk($root, 0, $errors, $warnings);

        foreach ($richText as $key => $html) {
            $richText[$key] = $this->sanitiseInlineHtml($html, $errors, $warnings);
        }

        if ([] !== $errors) {
            return ValidationResult::fail($errors);
        }

        $out = $this->decodeTokenBraces(strtr($this->serialise($document, $root), $richText));

        if (null !== $source) {
            $this->checkPreservation($source, $out, $prompt, $finalAttempt, $errors, $warnings);
        }

        if ([] !== $errors) {
            return ValidationResult::fail($errors);
        }

        return ValidationResult::ok($out, $warnings);
    }

    /**
     * Preservation invariants, edit mode only.
     *
     * Images and tokens are hard rejections: they silently destroy work and the
     * user may not even notice. Links and text volume are soft, because there are
     * legitimate requests that change them, and on the final attempt they become
     * warnings: the user is looking at the result and has undo.
     *
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function checkPreservation(
        string $source,
        string $output,
        string $prompt,
        bool $finalAttempt,
        array &$errors,
        array &$warnings,
    ): void {
        $inventedImages = array_diff(
            $this->attributeValues($output, 'src'),
            $this->attributeValues($source, 'src'),
            array_filter([$this->placeholderImageSrc])
        );

        foreach ($inventedImages as $src) {
            $errors[] = sprintf('The model swapped in image %s, which did not exist. Ask for the change another way.', $src);
        }

        $lostTokens = array_diff($this->tokens($source), $this->tokens($output));

        foreach ($lostTokens as $token) {
            $errors[] = sprintf('The change would delete personalisation token %s. Nothing was applied.', $token);
        }

        $soft = [];

        if (!$this->mentions($prompt, $this->linkKeywords)) {
            $lostLinks = array_diff($this->attributeValues($source, 'href'), $this->attributeValues($output, 'href'));

            foreach ($lostLinks as $href) {
                $soft[] = sprintf('Link %s disappeared and the request never mentioned links or buttons.', $href);
            }
        }

        if (!$this->mentions($prompt, $this->shortenKeywords)) {
            $before = $this->textLength($source);
            $after  = $this->textLength($output);

            if ($before > 0 && $after < $before * self::MIN_TEXT_RATIO) {
                $soft[] = sprintf(
                    'The text shrank from %d to %d characters and the request never asked to shorten it.',
                    $before,
                    $after
                );
            }
        }

        if ($finalAttempt) {
            array_push($warnings, ...$soft);

            return;
        }

        array_push($errors, ...$soft);
    }

    /**
     * @return string[]
     */
    private function attributeValues(string $mjml, string $attribute): array
    {
        preg_match_all('~\b'.preg_quote($attribute, '~').'\s*=\s*"([^"]*)"~i', $mjml, $matches);

        return array_values(array_unique(array_filter(array_map('trim', $matches[1]))));
    }

    /**
     * Braces come back percent-encoded in link targets from two directions:
     * models write them that way, and libxml re-encodes them when it serialises
     * a URI attribute. Either way Mautic replaces the literal form only, so a
     * token used as a link ships as a link to a page that does not exist. That
     * covers the two tokens that matter most, {unsubscribe_url} and
     * {webview_url}, which is why this runs after serialisation rather than
     * before parsing.
     *
     * The pattern is deliberately narrow. An encoded brace that is not shaped
     * like a token belongs to the URL and is left alone.
     */
    private function decodeTokenBraces(string $mjml): string
    {
        return preg_replace(
            '~%7b([a-z][a-z0-9_]*(?:=[^"\'<>%]*)?)%7d~i',
            '{$1}',
            $mjml
        ) ?? $mjml;
    }

    /**
     * @return string[]
     */
    private function tokens(string $mjml): array
    {
        preg_match_all('~\{[^{}]+\}~', $mjml, $matches);

        return array_values(array_unique($matches[0]));
    }

    private function textLength(string $mjml): int
    {
        $text = html_entity_decode(strip_tags($mjml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~\s+~u', ' ', $text) ?? $text;

        return mb_strlen(trim($text), 'UTF-8');
    }

    /**
     * @param string[] $keywords
     */
    private function mentions(string $prompt, array $keywords): bool
    {
        $needle = mb_strtolower($prompt, 'UTF-8');

        foreach ($keywords as $keyword) {
            if (str_contains($needle, mb_strtolower($keyword, 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Swaps mj-text/mj-button content for opaque markers so the XML parser does
     * not trip over the HTML inside. Restored at the end, already sanitised.
     *
     * @param array<string, string> $store
     */
    private function extractRichText(string $mjml, array &$store): string
    {
        $pattern = '#(<('.implode('|', self::RICH_TEXT_TAGS).')\b[^>]*>)(.*?)(</\2>)#is';

        return preg_replace_callback(
            $pattern,
            static function (array $matches) use (&$store): string {
                $key         = 'GJSARICHTEXT'.count($store).'ENDGJSA';
                $store[$key] = $matches[3];

                return $matches[1].$key.$matches[4];
            },
            $mjml
        ) ?? $mjml;
    }

    /**
     * XML rejects repeated attributes. The model repeats them often enough, and
     * the intended value is always the first one.
     */
    private function dedupeAttributes(string $mjml): string
    {
        return preg_replace_callback(
            '#<(mj-[a-z-]+)((?:\s+[a-zA-Z0-9:_.-]+\s*=\s*"[^"]*")+)(\s*/?)>#i',
            static function (array $matches): string {
                preg_match_all('#\s+([a-zA-Z0-9:_.-]+)\s*=\s*"([^"]*)"#', $matches[2], $pairs, PREG_SET_ORDER);

                $seen = [];
                $out  = '';

                foreach ($pairs as $pair) {
                    $name = strtolower($pair[1]);

                    if (isset($seen[$name])) {
                        continue;
                    }

                    $seen[$name] = true;
                    $out .= ' '.$pair[1].'="'.$pair[2].'"';
                }

                return '<'.$matches[1].$out.$matches[3].'>';
            },
            $mjml
        ) ?? $mjml;
    }

    private function convertNamedEntities(string $mjml): string
    {
        $mjml = preg_replace_callback(
            '#&([a-zA-Z][a-zA-Z0-9]+);#',
            static function (array $matches): string {
                $decoded = html_entity_decode($matches[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($decoded === $matches[0]) {
                    return $matches[0];
                }

                $codepoint = mb_ord($decoded, 'UTF-8');

                return false === $codepoint ? $matches[0] : '&#'.$codepoint.';';
            },
            $mjml
        ) ?? $mjml;

        // Delimiter is ~ and not #, because the pattern contains literal # characters.
        return preg_replace('~&(?![a-zA-Z][a-zA-Z0-9]+;|#[0-9]+;|#x[0-9a-fA-F]+;)~', '&amp;', $mjml) ?? $mjml;
    }

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function sanitiseInlineHtml(string $html, array &$errors, array &$warnings): string
    {
        if ('' === trim($html)) {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->loadHTML(
            '<?xml encoding="UTF-8"?><gjs-inline>'.$html.'</gjs-inline>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $wrapper = $document->getElementsByTagName('gjs-inline')->item(0);

        if (!$wrapper instanceof \DOMElement) {
            return $html;
        }

        $this->walkInline($wrapper, $errors, $warnings);

        $out = '';

        foreach ($wrapper->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function walkInline(\DOMNode $node, array &$errors, array &$warnings): void
    {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            if (!in_array(strtolower($child->nodeName), self::ALLOWED_INLINE_TAGS, true)) {
                $errors[] = sprintf('The <%s> tag is not allowed inside text.', $child->nodeName);

                continue;
            }

            $this->checkAttributes($child, $errors, $warnings);
            $this->walkInline($child, $errors, $warnings);
        }
    }

    /**
     * @param string[] $errors
     */
    private function parse(string $mjml, array &$errors): ?\DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new \DOMDocument('1.0', 'UTF-8');
        $loaded   = $document->loadXML('<gjs-assistant-root>'.$mjml.'</gjs-assistant-root>', LIBXML_NONET);

        $libxmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            $messages = array_map(
                static fn (\LibXMLError $error): string => trim($error->message),
                $libxmlErrors
            );

            $errors = ['The MJML does not parse as well-formed XML: '.implode('; ', $messages ?: ['unknown error'])];

            return null;
        }

        $errors = [];

        return $document;
    }

    /**
     * @param string[] $errors
     */
    private function assertTopLevelIsSections(\DOMElement $root, array &$errors): void
    {
        $hasSection = false;

        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMText) {
                if ('' !== trim($child->textContent)) {
                    $errors[] = 'The fragment has loose text outside any tag. Return MJML only, no explanations.';
                }

                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            if ('mj-section' !== $child->nodeName) {
                $errors[] = sprintf('The top level node must be mj-section, but <%s> was returned.', $child->nodeName);

                continue;
            }

            $hasSection = true;
        }

        if (!$hasSection && [] === $errors) {
            $errors[] = 'The fragment contains no mj-section at all.';
        }
    }

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function walk(\DOMNode $node, int $depth, array &$errors, array &$warnings): void
    {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $childDepth = $depth + 1;
            $tag        = $child->nodeName;

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                $errors[] = sprintf('The <%s> tag is not allowed.', $tag);

                continue;
            }

            if ($childDepth > self::MAX_DEPTH) {
                $errors[] = sprintf('The maximum depth is %d levels and <%s> sits at level %d.', self::MAX_DEPTH, $tag, $childDepth);

                continue;
            }

            if ('mj-image' === $tag && !$child->hasAttribute('alt')) {
                $warnings[] = 'An <mj-image> carries no alt text. Screen readers and image-blocking clients get nothing.';
            }

            $this->checkAttributes($child, $errors, $warnings);
            $this->walk($child, $childDepth, $errors, $warnings);
        }
    }

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function checkAttributes(\DOMElement $element, array &$errors, array &$warnings): void
    {
        /** @var \DOMAttr $attribute */
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (str_starts_with($name, 'on')) {
                $errors[] = sprintf('The %s attribute is not allowed on <%s>.', $attribute->nodeName, $element->nodeName);

                continue;
            }

            if (!in_array($name, ['href', 'src'], true)) {
                continue;
            }

            $safe = $this->sanitiseUrl($attribute->nodeValue ?? '');

            if (null === $safe) {
                continue;
            }

            $warnings[] = sprintf('The %s on <%s> used a disallowed scheme and was replaced.', $name, $element->nodeName);
            $element->setAttribute($attribute->nodeName, $safe);
        }
    }

    /**
     * Returns the safe value to use, or null when the original is already fine.
     */
    private function sanitiseUrl(string $value): ?string
    {
        $value = trim($value);

        if ('' === $value || str_contains($value, '{')) {
            return null;
        }

        if (!preg_match('/^([a-z][a-z0-9+.-]*):/i', $value, $matches)) {
            return null;
        }

        if (in_array(strtolower($matches[1]), self::SAFE_SCHEMES, true)) {
            return null;
        }

        return '#';
    }

    private function serialise(\DOMDocument $document, \DOMElement $root): string
    {
        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $document->saveXML($child, LIBXML_NOEMPTYTAG);
        }

        return $this->expandVoidTags($out);
    }

    /**
     * LIBXML_NOEMPTYTAG expands most of them, but tags with no content at all
     * slip through on some libxml versions. This guarantees the explicit close.
     */
    private function expandVoidTags(string $mjml): string
    {
        foreach (self::VOID_MJ_TAGS as $tag) {
            $mjml = preg_replace(
                '#<'.preg_quote($tag, '#').'((?:\s[^>]*?)?)\s*/>#i',
                '<'.$tag.'$1></'.$tag.'>',
                $mjml
            ) ?? $mjml;
        }

        return $mjml;
    }
}
