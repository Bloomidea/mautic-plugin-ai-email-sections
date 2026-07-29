<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Integration\Support;

use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormAuthInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormFeatureSettingsInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use MauticPlugin\AiEmailSectionsBundle\Form\Type\ConfigAuthType;
use MauticPlugin\AiEmailSectionsBundle\Form\Type\ConfigFeaturesType;
use MauticPlugin\AiEmailSectionsBundle\Integration\AiEmailSectionsIntegration;

/**
 * Splits the configuration across two tabs.
 *
 * The auth tab is encrypted by the IntegrationsBundle and stored in api_keys.
 * It is the only place the provider key may live. Everything else (URL, model,
 * temperature, limits) sits in featureSettings, in the clear.
 */
class ConfigSupport extends AiEmailSectionsIntegration implements ConfigFormInterface, ConfigFormAuthInterface, ConfigFormFeatureSettingsInterface
{
    use DefaultConfigFormTrait;

    public function getAuthConfigFormName(): string
    {
        return ConfigAuthType::class;
    }

    public function getFeatureSettingsConfigFormName(): string
    {
        return ConfigFeaturesType::class;
    }
}
