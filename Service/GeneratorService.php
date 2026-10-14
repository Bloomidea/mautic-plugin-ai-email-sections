<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Service;

use MauticPlugin\AiEmailSectionsBundle\Exception\GenerationFailedException;

/**
 * Generation loop: ask the model, validate, and on failure hand it back the
 * validator feedback in plain language before trying again.
 */
final class GeneratorService
{
    public function __construct(
        private readonly LlmClientInterface $client,
        private readonly PromptBuilder $promptBuilder,
        private readonly MjmlValidator $validator,
        private readonly int $maxAttempts = 3,
    ) {
    }

    public function generate(
        string $mode,
        string $prompt,
        ?string $source,
        string $themeId,
    ): GenerationOutcome {
        $messages     = $this->promptBuilder->build($mode, $prompt, $source, $themeId);
        $rawResponses = [];
        $lastErrors   = [];
        $startedAt    = microtime(true);

        for ($attempt = 1; $attempt <= $this->maxAttempts; ++$attempt) {
            $raw            = $this->client->complete($messages);
            $rawResponses[] = $raw;

            $mjml   = $this->stripFences($raw);
            $result = $this->validator->validate($mjml, $source, $prompt, $attempt === $this->maxAttempts);

            if ($result->isValid()) {
                return new GenerationOutcome(
                    $result->getMjml(),
                    $attempt,
                    $result->getWarnings(),
                    $rawResponses,
                    (int) round((microtime(true) - $startedAt) * 1000),
                    $this->isUnchanged($source, $result->getMjml()),
                );
            }

            $lastErrors = $result->getErrors();
            $messages   = $this->promptBuilder->appendRetry($messages, $raw, $lastErrors);
        }

        throw new GenerationFailedException('Could not generate a valid block.', $lastErrors, $this->maxAttempts);
    }

    /**
     * A section that comes back as it was sent is a valid answer, but swapping
     * it in silently tells the person nothing, so they send the request again:
     * 9 of 30 generations on 14/10/2026 went that way, every one a request
     * about the whole email that no single section can carry out.
     *
     * The source goes through the same validator as the output, so markup that
     * GrapesJS and libxml merely write differently does not count as a change,
     * and neither does indentation between MJML tags.
     */
    private function isUnchanged(?string $source, string $mjml): bool
    {
        if (null === $source) {
            return false;
        }

        $canonical = $this->validator->validate($source);

        return $this->normaliseWhitespace($canonical->isValid() ? $canonical->getMjml() : $source)
            === $this->normaliseWhitespace($mjml);
    }

    /**
     * Whitespace next to an MJML tag starts or ends a block and never renders,
     * so it goes. Elsewhere it is collapsed, never removed, because a space
     * between two words or two inline tags is visible.
     */
    private function normaliseWhitespace(string $mjml): string
    {
        $mjml = preg_replace('~\s+~', ' ', trim($mjml)) ?? $mjml;
        $mjml = preg_replace('~ (?=</?mj-)~', '', $mjml) ?? $mjml;

        return preg_replace('~(</?mj-[^>]*>) ~', '$1', $mjml) ?? $mjml;
    }

    /**
     * The model insists on wrapping the response in markdown fences, even when
     * the system prompt tells it not to.
     */
    private function stripFences(string $raw): string
    {
        $trimmed = trim($raw);

        if (preg_match('~^```[a-zA-Z]*\s*\n(.*?)\n?```$~s', $trimmed, $matches)) {
            return trim($matches[1]);
        }

        return $trimmed;
    }
}
