<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Service;

/**
 * Result of validating an MJML fragment.
 *
 * An invalid result never carries MJML: nothing half-validated reaches the canvas.
 */
final class ValidationResult
{
    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function __construct(
        private readonly bool $valid,
        private readonly string $mjml,
        private readonly array $errors,
        private readonly array $warnings,
    ) {
    }

    /**
     * @param string[] $warnings
     */
    public static function ok(string $mjml, array $warnings = []): self
    {
        return new self(true, $mjml, [], $warnings);
    }

    /**
     * @param string[] $errors
     */
    public static function fail(array $errors): self
    {
        return new self(false, '', $errors, []);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getMjml(): string
    {
        return $this->mjml;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
