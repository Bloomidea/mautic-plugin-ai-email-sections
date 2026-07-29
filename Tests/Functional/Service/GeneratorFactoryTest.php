<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Functional\Service;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\AiEmailSectionsBundle\Integration\AiEmailSectionsIntegration;
use MauticPlugin\AiEmailSectionsBundle\Integration\Config;
use MauticPlugin\AiEmailSectionsBundle\Service\AnthropicClient;
use MauticPlugin\AiEmailSectionsBundle\Service\GeneratorFactory;
use MauticPlugin\AiEmailSectionsBundle\Service\OpenAiCompatibleClient;

/**
 * The provider is stored on the Integration and read at runtime, so the wrong
 * branch here is a 400 from the provider and nothing else. Cheap to pin down.
 */
final class GeneratorFactoryTest extends MauticMysqlTestCase
{
    public function testBuildsTheOpenAiCompatibleClientByDefault(): void
    {
        $this->persistIntegration([]);

        $this->assertInstanceOf(OpenAiCompatibleClient::class, $this->factory()->createClient());
    }

    public function testBuildsTheAnthropicClientWhenThatProviderIsChosen(): void
    {
        $this->persistIntegration(['integration' => ['provider' => Config::PROVIDER_ANTHROPIC]]);

        $this->assertInstanceOf(AnthropicClient::class, $this->factory()->createClient());
    }

    /**
     * Pointing the OpenAI-compatible client at a proxy is the other way to
     * reach Anthropic, and it must stay available.
     */
    public function testKeepsTheOpenAiCompatibleClientWhenTheProviderIsAProxy(): void
    {
        $this->persistIntegration([
            'integration' => [
                'provider' => Config::PROVIDER_OPENAI,
                'base_url' => 'http://litellm.internal/v1',
                'model'    => 'claude-opus-5',
            ],
        ]);

        $this->assertInstanceOf(OpenAiCompatibleClient::class, $this->factory()->createClient());
    }

    private function factory(): GeneratorFactory
    {
        return static::getContainer()->get(GeneratorFactory::class);
    }

    /**
     * @param mixed[] $featureSettings
     */
    private function persistIntegration(array $featureSettings): void
    {
        $integration = new Integration();
        $integration->setName(AiEmailSectionsIntegration::NAME);
        $integration->setIsPublished(true);
        $integration->setFeatureSettings($featureSettings);

        $this->em->persist($integration);
        $this->em->flush();
        $this->em->clear();
    }
}
