<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Service;

use MauticPlugin\AiEmailSectionsBundle\Exception\LlmUnavailableException;

interface LlmClientInterface
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @throws LlmUnavailableException
     */
    public function complete(array $messages): string;
}
