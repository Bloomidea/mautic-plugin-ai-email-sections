<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Functional\Integration;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\AiEmailSectionsBundle\Integration\AiEmailSectionsIntegration;
use MauticPlugin\AiEmailSectionsBundle\Integration\Config;

/**
 * IntegrationFeatureSettingsType nests the plugin's own form under an
 * "integration" key, so featureSettings looks like
 * ['integration' => ['base_url' => ...]] and not ['base_url' => ...].
 *
 * Reading the top level silently returns the defaults and every configured
 * value is ignored, which is invisible until a generation goes to the wrong
 * endpoint.
 */
final class ConfigTest extends MauticMysqlTestCase
{
    public function testReadsFeatureSettingsNestedUnderTheIntegrationKey(): void
    {
        $this->persistIntegration([
            'integration' => [
                'base_url'          => 'http://host.docker.internal:1234/v1',
                'model'             => 'qwen2.5-coder-32b-instruct-mlx',
                'temperature'       => '0.2',
                'max_tokens'        => '900',
                'placeholder_image' => 'https://example.test/ph.png',
                'rate_limit'        => '7',
                'timeout'           => '20',
                'theme'             => 'default',
            ],
        ]);

        $config = $this->config();

        $this->assertSame('http://host.docker.internal:1234/v1', $config->getBaseUrl());
        $this->assertSame('qwen2.5-coder-32b-instruct-mlx', $config->getModel());
        $this->assertSame(0.2, $config->getTemperature());
        $this->assertSame(900, $config->getMaxTokens());
        $this->assertSame('https://example.test/ph.png', $config->getPlaceholderImage());
        $this->assertSame(7, $config->getRateLimitPerHour());
        $this->assertSame(20, $config->getTimeoutSeconds());
    }

    public function testStillReadsFlatFeatureSettings(): void
    {
        $this->persistIntegration(['base_url' => 'http://flat.test/v1']);

        $this->assertSame('http://flat.test/v1', $this->config()->getBaseUrl());
    }

    public function testFallsBackToDefaultsWhenNothingIsConfigured(): void
    {
        $this->persistIntegration([]);

        $this->assertSame(Config::DEFAULT_BASE_URL, $this->config()->getBaseUrl());
        $this->assertSame(Config::DEFAULT_MODEL, $this->config()->getModel());
    }

    /**
     * Installs configured before the provider existed have no such key stored.
     * They must keep talking to the same endpoint they were talking to.
     */
    public function testDefaultsToTheOpenAiCompatibleProviderForInstallsThatNeverSawTheField(): void
    {
        $this->persistIntegration(['integration' => ['base_url' => 'http://host.docker.internal:1234/v1']]);

        $this->assertSame(Config::PROVIDER_OPENAI, $this->config()->getProvider());
    }

    public function testReadsTheConfiguredProvider(): void
    {
        $this->persistIntegration(['integration' => ['provider' => Config::PROVIDER_ANTHROPIC]]);

        $this->assertSame(Config::PROVIDER_ANTHROPIC, $this->config()->getProvider());
    }

    /**
     * The value comes out of a database column, not out of the form, so it can
     * hold anything. Falling back beats fataling on a typo.
     */
    public function testIgnoresAProviderItDoesNotKnow(): void
    {
        $this->persistIntegration(['integration' => ['provider' => 'gemini']]);

        $this->assertSame(Config::PROVIDER_OPENAI, $this->config()->getProvider());
    }

    /**
     * Switching the dropdown and saving must work without also retyping the
     * endpoint and the model, which differ per provider.
     */
    public function testDefaultsTheBaseUrlAndModelToTheChosenProvider(): void
    {
        $this->persistIntegration(['integration' => ['provider' => Config::PROVIDER_ANTHROPIC]]);

        $config = $this->config();

        $this->assertSame(Config::DEFAULT_ANTHROPIC_BASE_URL, $config->getBaseUrl());
        $this->assertSame(Config::DEFAULT_ANTHROPIC_MODEL, $config->getModel());
    }

    public function testAnExplicitBaseUrlStillWinsOverTheProviderDefault(): void
    {
        $this->persistIntegration([
            'integration' => [
                'provider' => Config::PROVIDER_ANTHROPIC,
                'base_url' => 'http://litellm.internal/v1',
                'model'    => 'claude-sonnet-5',
            ],
        ]);

        $config = $this->config();

        $this->assertSame('http://litellm.internal/v1', $config->getBaseUrl());
        $this->assertSame('claude-sonnet-5', $config->getModel());
    }

    /**
     * The stored value goes straight into the HTTP client as the target of
     * server-side requests. A scheme other than http(s) has no legitimate use
     * there, so anything else falls back to the provider default instead of
     * turning the plugin into a proxy for whatever an admin session stores.
     */
    public function testABaseUrlWithoutAnHttpSchemeFallsBackToTheProviderDefault(): void
    {
        $this->persistIntegration([
            'integration' => [
                'provider' => Config::PROVIDER_ANTHROPIC,
                'base_url' => 'file:///etc/passwd',
            ],
        ]);

        $this->assertSame(Config::DEFAULT_ANTHROPIC_BASE_URL, $this->config()->getBaseUrl());
    }

    public function testASchemeRelativeBaseUrlAlsoFallsBack(): void
    {
        $this->persistIntegration(['integration' => ['base_url' => 'litellm.internal/v1']]);

        $this->assertSame(Config::DEFAULT_BASE_URL, $this->config()->getBaseUrl());
    }

    public function testReadsTheBrandBrief(): void
    {
        $this->persistIntegration(['integration' => ['brand' => 'We sell handmade shoes. Address the reader informally.']]);

        $this->assertSame('We sell handmade shoes. Address the reader informally.', $this->config()->getBrandBrief());
    }

    public function testTheBrandBriefIsEmptyWhenNotConfigured(): void
    {
        $this->persistIntegration([]);

        $this->assertSame('', $this->config()->getBrandBrief());
    }

    /**
     * On by default: a generator that cannot personalise is the poorer default,
     * and the list costs around 550 input tokens on this install.
     */
    public function testTokensAreOfferedByDefault(): void
    {
        $this->persistIntegration([]);

        $this->assertTrue($this->config()->areTokensEnabled());
    }

    public function testTokensCanBeTurnedOff(): void
    {
        $this->persistIntegration(['integration' => ['tokens_enabled' => 0]]);

        $this->assertFalse($this->config()->areTokensEnabled());
    }

    public function testExclusionsAreSplitOnNewlinesAndCommas(): void
    {
        $this->persistIntegration(['integration' => ['tokens_excluded' => "{unsubscribe_url}\n{dnc_url}, {webview_url}"]]);

        $this->assertSame(
            ['{unsubscribe_url}', '{dnc_url}', '{webview_url}'],
            $this->config()->getExcludedTokens()
        );
    }

    public function testNoExclusionsIsAnEmptyList(): void
    {
        $this->persistIntegration([]);

        $this->assertSame([], $this->config()->getExcludedTokens());
    }

    private function config(): Config
    {
        return static::getContainer()->get(Config::class);
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
