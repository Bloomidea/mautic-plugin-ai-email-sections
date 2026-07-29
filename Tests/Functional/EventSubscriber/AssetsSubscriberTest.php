<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Functional\EventSubscriber;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\AiEmailSectionsBundle\Integration\AiEmailSectionsIntegration;

/**
 * Proves the plugin actually reaches the browser, which is the only thing that
 * decides whether the block shows up in the builder.
 */
final class AssetsSubscriberTest extends MauticMysqlTestCase
{
    public function testDoesNotInjectAnythingWhileTheIntegrationIsUnpublished(): void
    {
        $this->client->request('GET', '/s/dashboard');
        $this->assertResponseIsSuccessful();

        $this->assertStringNotContainsString(
            'AiEmailSectionsBundle/Assets/dist/index.js',
            (string) $this->client->getResponse()->getContent(),
            'Disabling the plugin must not affect the builder.'
        );
    }

    public function testInjectsTheBuilderPluginWhenPublished(): void
    {
        $this->publishIntegration();

        $this->client->request('GET', '/s/dashboard');
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('AiEmailSectionsBundle/Assets/dist/index.js', $content);
        $this->assertStringContainsString('MauticAiEmailSectionsConfig', $content);
        $this->assertStringContainsString('/s/ai-email-sections/generate', $content);
    }

    public function testNeverLeaksTheApiKeyToTheBrowser(): void
    {
        $integration = $this->publishIntegration();
        $integration->setApiKeys(['api_key' => 'sk-super-secreta']);

        $this->em->persist($integration);
        $this->em->flush();
        $this->em->clear();

        $this->client->request('GET', '/s/dashboard');

        $this->assertStringNotContainsString(
            'sk-super-secreta',
            (string) $this->client->getResponse()->getContent()
        );
    }

    private function publishIntegration(): Integration
    {
        $integration = new Integration();
        $integration->setName(AiEmailSectionsIntegration::NAME);
        $integration->setIsPublished(true);

        $this->em->persist($integration);
        $this->em->flush();
        $this->em->clear();

        return $integration;
    }
}
