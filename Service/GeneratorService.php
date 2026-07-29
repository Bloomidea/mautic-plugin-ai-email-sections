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
                );
            }

            $lastErrors = $result->getErrors();
            $messages   = $this->promptBuilder->appendRetry($messages, $raw, $lastErrors);
        }

        throw new GenerationFailedException('Could not generate a valid block.', $lastErrors, $this->maxAttempts);
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
