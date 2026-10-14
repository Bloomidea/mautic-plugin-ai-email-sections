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

    /**
     * Mautic's own ?v suffix comes from configuration and survives a deploy
     * that changes the file, so a CDN or a browser kept serving the old panel
     * after the 14/10/2026 deploy. The URL has to change with the content.
     */
    public function testTheAssetUrlsChangeWithTheirContent(): void
    {
        $this->publishIntegration();

        $this->client->request('GET', '/s/dashboard');
        $content = (string) $this->client->getResponse()->getContent();

        foreach (['index.js', 'index.css'] as $file) {
            $hash = substr((string) md5_file(__DIR__.'/../../../Assets/dist/'.$file), 0, 8);

            $this->assertStringContainsString('AiEmailSectionsBundle/Assets/dist/'.$file.'?h='.$hash, $content);
        }
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

    /**
     * The settings are printed inside an inline <script> block. json_encode
     * without JSON_HEX_TAG leaves "</script>" intact, and a config value
     * carrying one would terminate the block and inject markup into every
     * administration page. The value is admin-authored, but "only an admin can
     * store the payload" is not a defence a security review accepts.
     */
    public function testAConfigValueCannotBreakOutOfTheInlineScriptBlock(): void
    {
        $integration = new Integration();
        $integration->setName(AiEmailSectionsIntegration::NAME);
        $integration->setIsPublished(true);
        $integration->setFeatureSettings([
            'integration' => [
                'placeholder_image' => 'https://x.example/a.png</script><script>alert(1)</script>',
            ],
        ]);

        $this->em->persist($integration);
        $this->em->flush();
        $this->em->clear();

        $this->client->request('GET', '/s/dashboard');
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('MauticAiEmailSectionsConfig', $content);
        // The payload must be present (the config carried it) but neutralised.
        $this->assertStringContainsString('x.example', $content);
        $this->assertStringNotContainsString('</script><script>alert(1)', $content);
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
