<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Exception;

/**
 * The provider did not answer, answered with an error, or blew the timeout.
 */
final class LlmUnavailableException extends \RuntimeException
{
    public static function timedOut(int $seconds): self
    {
        return new self(sprintf('The model did not respond within %d seconds.', $seconds));
    }

    public static function unreachable(string $reason): self
    {
        return new self(sprintf('Could not reach the model: %s', $reason));
    }
}
