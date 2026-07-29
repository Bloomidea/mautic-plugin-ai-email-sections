<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Integration;

use Mautic\IntegrationsBundle\Integration\BasicIntegration;
use Mautic\IntegrationsBundle\Integration\ConfigurationTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\BasicInterface;

class AiEmailSectionsIntegration extends BasicIntegration implements BasicInterface
{
    use ConfigurationTrait;

    public const NAME = 'aiemailsections';

    public const DISPLAY_NAME = 'AI Email Sections';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return self::DISPLAY_NAME;
    }

    public function getIcon(): string
    {
        return 'plugins/AiEmailSectionsBundle/Assets/img/icon.png';
    }
}
