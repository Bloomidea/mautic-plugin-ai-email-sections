<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    // Kept out of the container: entities, exceptions, value objects, and the
    // classes GeneratorFactory builds by hand because they depend on configuration
    // read from the database at runtime.
    $excludes = [
        'Assets',
        'Entity',
        'Exception',
        'Resources',
        'Security',
        'Tests',
        'Translations',
        'node_modules',
        'vendor',
        'Service/AnthropicClient.php',
        'Service/GenerationOutcome.php',
        'Service/GeneratorService.php',
        'Service/LlmClientInterface.php',
        'Service/MjmlValidator.php',
        'Service/OpenAiCompatibleClient.php',
        'Service/PromptBuilder.php',
        'Service/ValidationResult.php',
    ];

    $services->load('MauticPlugin\\AiEmailSectionsBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    $services->load('MauticPlugin\\AiEmailSectionsBundle\\Entity\\', '../Entity/*Repository.php');

    // Aliases the IntegrationsBundle expects so the Integration shows up under
    // Settings / Plugins with its own configuration form.
    $services->alias(
        'mautic.integration.aiemailsections',
        MauticPlugin\AiEmailSectionsBundle\Integration\AiEmailSectionsIntegration::class
    );
    $services->alias(
        'aiemailsections.integration.configuration',
        MauticPlugin\AiEmailSectionsBundle\Integration\Support\ConfigSupport::class
    );
};
