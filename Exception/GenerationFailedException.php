<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Exception;

/**
 * The model did not produce valid MJML within the allowed number of attempts.
 */
final class GenerationFailedException extends \RuntimeException
{
    /**
     * @param string[] $validationErrors
     */
    public function __construct(
        string $message,
        private readonly array $validationErrors = [],
        private readonly int $attempts = 0,
    ) {
        parent::__construct($message);
    }

    /**
     * @return string[]
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }
}
