<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Service;

/**
 * Outcome of a successful generation, carrying what telemetry needs.
 */
final class GenerationOutcome
{
    /**
     * @param string[] $warnings
     * @param string[] $rawResponses
     */
    public function __construct(
        public readonly string $mjml,
        public readonly int $attempts,
        public readonly array $warnings = [],
        public readonly array $rawResponses = [],
        public readonly int $latencyMs = 0,
    ) {
    }

    /**
     * Telemetry stores the response that finally passed validation.
     *
     * This lives here on purpose: end() takes its argument by reference to move
     * the array pointer, so calling it on $outcome->rawResponses from outside
     * fails with "Cannot modify readonly property".
     */
    public function lastRawResponse(): ?string
    {
        if ([] === $this->rawResponses) {
            return null;
        }

        return $this->rawResponses[array_key_last($this->rawResponses)];
    }
}
