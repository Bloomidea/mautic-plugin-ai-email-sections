<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Functional\Integration;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\AiEmailSectionsBundle\Form\Type\ConfigAuthType;
use MauticPlugin\AiEmailSectionsBundle\Form\Type\ConfigFeaturesType;
use MauticPlugin\AiEmailSectionsBundle\Integration\AiEmailSectionsIntegration;
use MauticPlugin\AiEmailSectionsBundle\Integration\Config;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The two configuration tabs are built differently by the IntegrationsBundle,
 * and getting the option contract wrong shows an alert instead of the modal.
 *
 * IntegrationConfigType passes "integration" to the auth form.
 * IntegrationFeatureSettingsType adds the feature settings form with nothing
 * but ['label' => false].
 */
final class ConfigFormTest extends MauticMysqlTestCase
{
    public function testAuthFormBuildsWhenGivenTheIntegrationOption(): void
    {
        $form = $this->formFactory()->create(ConfigAuthType::class, [], [
            'integration' => $this->integration(),
        ]);

        $this->assertTrue($form->has('api_key'), 'The API key belongs on the encrypted auth tab.');
    }

    public function testFeatureSettingsFormBuildsWithoutTheIntegrationOption(): void
    {
        $form = $this->formFactory()->create(ConfigFeaturesType::class);

        foreach (['provider', 'brand', 'base_url', 'model', 'temperature', 'max_tokens', 'placeholder_image', 'rate_limit', 'timeout', 'theme'] as $field) {
            $this->assertTrue($form->has($field), sprintf('Missing "%s" on the feature settings tab.', $field));
        }
    }

    /**
     * Only the two the factory knows how to build. Anything else silently
     * falls back to the OpenAI-compatible client and talks the wrong protocol.
     */
    public function testTheProviderFieldOffersExactlyTheSupportedProviders(): void
    {
        $form = $this->formFactory()->create(ConfigFeaturesType::class);

        $choices = $form->get('provider')->getConfig()->getOption('choices');

        $this->assertSame(
            [Config::PROVIDER_OPENAI, Config::PROVIDER_ANTHROPIC],
            array_values($choices)
        );
    }

    public function testTheApiKeyIsNotOnTheFeatureSettingsTab(): void
    {
        $form = $this->formFactory()->create(ConfigFeaturesType::class);

        $this->assertFalse($form->has('api_key'), 'The API key must never sit on the unencrypted tab.');
    }

    /**
     * A "data" option on the field would win over the stored value on every
     * render, so opening the configuration and saving it again would silently
     * reset the provider back to the default.
     */
    public function testTheProviderFieldShowsWhatWasSaved(): void
    {
        $form = $this->formFactory()->create(ConfigFeaturesType::class, ['provider' => Config::PROVIDER_ANTHROPIC]);

        $this->assertSame(Config::PROVIDER_ANTHROPIC, $form->get('provider')->getData());
    }

    /**
     * The brief goes into the system prompt on every generation. Without a cap
     * an administrator can quietly make each call much more expensive.
     */
    public function testTheBrandFieldIsLengthCapped(): void
    {
        $form = $this->formFactory()->create(ConfigFeaturesType::class);

        $constraints = $form->get('brand')->getConfig()->getOption('constraints');
        $lengths     = array_filter($constraints, static fn ($c) => $c instanceof Length);

        $this->assertCount(1, $lengths, 'The brand brief needs a Length constraint.');
        $this->assertSame(Config::MAX_BRAND_BRIEF_CHARS, reset($lengths)->max);
    }

    /**
     * The defaults follow the selected provider, so a placeholder naming one
     * provider's value contradicts what an empty field actually does. With
     * Anthropic selected, these fields were showing the local model's endpoint
     * and name while the request went to api.anthropic.com with a Claude model.
     */
    public function testTheEndpointAndModelPlaceholdersDoNotNameOneProvidersValue(): void
    {
        $form = $this->formFactory()->create(ConfigFeaturesType::class);

        foreach (['base_url' => Config::DEFAULT_BASE_URL, 'model' => Config::DEFAULT_MODEL] as $field => $openAiDefault) {
            $placeholder = $form->get($field)->getConfig()->getOption('attr')['placeholder'] ?? '';

            $this->assertNotSame($openAiDefault, $placeholder, sprintf('"%s" must not advertise the OpenAI-compatible default.', $field));
            $this->assertNotSame(Config::DEFAULT_ANTHROPIC_BASE_URL, $placeholder);
            $this->assertNotSame(Config::DEFAULT_ANTHROPIC_MODEL, $placeholder);
            $this->assertNotSame('', $placeholder, sprintf('"%s" still needs to tell the user what empty means.', $field));
        }
    }

    /**
     * Both are read by the OpenAI-compatible client only. The Anthropic client
     * never sends a sampling parameter, and treats the token limit as a floor
     * rather than a ceiling. A field that silently means something else under
     * one provider has to say so.
     */
    public function testTheSamplingFieldsExplainTheirAnthropicBehaviour(): void
    {
        $form = $this->formFactory()->create(ConfigFeaturesType::class);

        foreach (['temperature', 'max_tokens'] as $field) {
            $this->assertArrayHasKey(
                'tooltip',
                $form->get($field)->getConfig()->getOption('attr'),
                sprintf('"%s" behaves differently under Anthropic and needs to say so.', $field)
            );
        }
    }

    private function formFactory(): FormFactoryInterface
    {
        return static::getContainer()->get('form.factory');
    }

    private function integration(): Integration
    {
        $integration = new Integration();
        $integration->setName(AiEmailSectionsIntegration::NAME);
        $integration->setIsPublished(true);

        return $integration;
    }
}
