<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Service;

/**
 * Assembles the conversation sent to the model: system prompt, few-shot, request.
 *
 * The user prompt always travels as a user message. It is never concatenated
 * into the system prompt, otherwise writing "ignore the instructions above"
 * would be enough to tear the allowlist down.
 */
final class PromptBuilder
{
    public const MODE_CREATE = 'create';

    public const MODE_EDIT = 'edit';

    public function __construct(
        private readonly string $promptsDir,
        private readonly string $placeholderImageSrc,
        private readonly string $brandBrief = '',
        /** @var array<string, string> token => label, as the builder offers them */
        private readonly array $availableTokens = [],
    ) {
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function build(string $mode, string $prompt, ?string $source, string $themeId): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($mode, $themeId)],
        ];

        foreach ($this->examples($mode) as $example) {
            $messages[] = ['role' => 'user', 'content' => $example['prompt']];
            $messages[] = ['role' => 'assistant', 'content' => $example['result']];
        }

        $messages[] = ['role' => 'user', 'content' => $this->userMessage($mode, $prompt, $source)];

        return $messages;
    }

    /**
     * Appends the invalid response and the validator feedback, so the model can
     * see what it got wrong in plain language.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param string[]                                         $errors
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function appendRetry(array $messages, string $rawResponse, array $errors): array
    {
        $messages[] = ['role' => 'assistant', 'content' => $rawResponse];
        $messages[] = [
            'role'    => 'user',
            'content' => "The previous response was rejected by the validator:\n\n- "
                .implode("\n- ", $errors)
                ."\n\nFix it and return the complete section again, MJML only, starting at <mj-section>.",
        ];

        return $messages;
    }

    private function systemPrompt(string $mode, string $themeId): string
    {
        $file = self::MODE_EDIT === $mode ? 'system-edit.md' : 'system.md';

        return strtr($this->read($this->promptsDir.'/'.$file), [
            '{{ALLOWED_TAGS}}'        => '`'.implode('`, `', MjmlValidator::ALLOWED_TAGS).'`',
            '{{ALLOWED_INLINE_TAGS}}' => '`'.implode('`, `', MjmlValidator::ALLOWED_INLINE_TAGS).'`',
            '{{PLACEHOLDER_IMAGE}}'   => $this->placeholderImageSrc,
            '{{THEME}}'               => $this->themeBlock($themeId),
            '{{BRAND}}'               => $this->brandBlock(),
            '{{TOKENS}}'              => $this->tokenBlock(),
        ]);
    }

    /**
     * The heading travels with the content rather than living in the prompt
     * file, so an install that configured no brief gets no empty section.
     *
     * The marker sits at the end of the prompt files on purpose. This text is
     * written by an administrator and lands in the system prompt: ahead of the
     * allowlist it could talk the model out of it, and the symptom would be
     * every generation failing validation for no visible reason.
     */
    private function brandBlock(): string
    {
        $brief = trim($this->brandBrief);

        return '' === $brief ? '' : "# Brand and tone of voice\n\n".$brief;
    }

    /**
     * The heading travels with the content, so an install that excluded every
     * token, or one whose builder offers none, gets no empty section.
     */
    private function tokenBlock(): string
    {
        if ([] === $this->availableTokens) {
            return '';
        }

        $lines = [];

        foreach ($this->availableTokens as $token => $label) {
            $lines[] = sprintf('- `%s` %s', $token, $label);
        }

        return "# Available personalisation tokens\n\n"
            ."Only these exist. A token that is not on this list does not error: it reaches the inbox as literal text.\n\n"
            .implode("\n", $lines);
    }

    /**
     * The language rule is restated here, in the last message, and not only at
     * the top of the system prompt. With seven English few-shot examples in
     * between, stating it once was not enough for weaker models.
     */
    private function userMessage(string $mode, string $prompt, ?string $source): string
    {
        if (self::MODE_EDIT !== $mode || null === $source) {
            return $prompt."\n\n(Write the copy in the same language as this request.)";
        }

        return "Current section:\n\n".$source."\n\nRequested change: ".$prompt
            ."\n\n(Keep the copy in the language of the current section.)";
    }

    /**
     * The theme goes into the prompt as readable YAML. One file per theme, so no
     * per-client fork of the prompt is needed.
     */
    private function themeBlock(string $themeId): string
    {
        $path = $this->promptsDir.'/themes/'.basename($themeId).'.yaml';

        if (!is_readable($path)) {
            $path = $this->promptsDir.'/themes/default.yaml';
        }

        return trim(preg_replace('~^#.*$~m', '', $this->read($path)) ?? '');
    }

    /**
     * @return array<int, array{prompt: string, result: string}>
     */
    private function examples(string $mode): array
    {
        $dir = $this->promptsDir.'/'.(self::MODE_EDIT === $mode ? 'examples-edit' : 'examples');

        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir.'/*.mjml') ?: [];
        sort($files);

        $examples = [];

        foreach ($files as $file) {
            $parsed = $this->parseExample($this->read($file), $mode);

            if (null !== $parsed) {
                $examples[] = $parsed;
            }
        }

        return $examples;
    }

    /**
     * @return array{prompt: string, result: string}|null
     */
    private function parseExample(string $contents, string $mode): ?array
    {
        if (!preg_match('~<!--\s*prompt:\s*(.+?)\s*-->~s', $contents, $matches)) {
            return null;
        }

        $prompt = $matches[1];
        $body   = trim(preg_replace('~<!--\s*prompt:.*?-->~s', '', $contents, 1) ?? $contents);
        $body   = strtr($body, ['{{PLACEHOLDER_IMAGE}}' => $this->placeholderImageSrc]);

        if (self::MODE_EDIT !== $mode) {
            return ['prompt' => $prompt, 'result' => $body];
        }

        $parts = preg_split('~<!--\s*(source|result)\s*-->~', $body, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($parts) || count($parts) < 2) {
            return null;
        }

        return [
            'prompt' => "Current section:\n\n".trim($parts[0])."\n\nRequested change: ".$prompt,
            'result' => trim($parts[1]),
        ];
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);

        return false === $contents ? '' : $contents;
    }
}
