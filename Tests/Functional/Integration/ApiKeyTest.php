<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Functional\Integration;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\AiEmailSectionsBundle\Integration\AiEmailSectionsIntegration;
use MauticPlugin\AiEmailSectionsBundle\Integration\Config;

/**
 * The auth tab stores the key encrypted, which is the whole point of putting
 * it there. What the configuration reads has to be the key itself and not the
 * ciphertext, or every request goes out authenticated with a base64 blob.
 *
 * The pilot never caught this: LM Studio accepts any key, including none.
 */
final class ApiKeyTest extends MauticMysqlTestCase
{
    public function testReturnsTheKeyAndNotItsCiphertext(): void
    {
        $stored = $this->persistIntegrationWithEncryptedKey('sk-ant-secret-value');

        // Guards against the assertion below passing for the wrong reason,
        // i.e. an encryption helper that is a no-op in this environment.
        $this->assertNotSame('sk-ant-secret-value', $stored, 'The auth tab must store ciphertext.');

        $this->assertSame('sk-ant-secret-value', $this->config()->getApiKey());
    }

    public function testReturnsAnEmptyStringWhenNoKeyWasEverSaved(): void
    {
        $integration = new Integration();
        $integration->setName(AiEmailSectionsIntegration::NAME);
        $integration->setIsPublished(true);
        $this->em->persist($integration);
        $this->em->flush();
        $this->em->clear();

        $this->assertSame('', $this->config()->getApiKey());
    }

    private function config(): Config
    {
        return static::getContainer()->get(Config::class);
    }

    private function persistIntegrationWithEncryptedKey(string $key): string
    {
        /** @var EncryptionHelper $encryption */
        $encryption = static::getContainer()->get('mautic.helper.encryption');

        $ciphertext = $encryption->encrypt($key);

        $integration = new Integration();
        $integration->setName(AiEmailSectionsIntegration::NAME);
        $integration->setIsPublished(true);
        $integration->setApiKeys(['api_key' => $ciphertext]);

        $this->em->persist($integration);
        $this->em->flush();
        $this->em->clear();

        return $ciphertext;
    }
}
