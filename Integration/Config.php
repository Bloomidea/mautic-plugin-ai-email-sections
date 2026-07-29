<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Integration;

use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\PluginBundle\Entity\Integration;

/**
 * Reads the plugin configuration.
 *
 * The API key lives in apiKeys, which the IntegrationsBundle encrypts and
 * decrypts on its own. Everything else lives in featureSettings, in the clear.
 */
class Config
{
    /**
     * "openai" covers every endpoint that speaks the OpenAI shape: OpenAI
     * itself, LiteLLM, Ollama, LM Studio. "anthropic" is the Messages API,
     * which shares none of it and needs its own client.
     */
    public const PROVIDER_OPENAI    = 'openai';
    public const PROVIDER_ANTHROPIC = 'anthropic';

    /**
     * Installs configured before this field existed have nothing stored and
     * must keep talking to the endpoint they were already talking to.
     */
    public const DEFAULT_PROVIDER = self::PROVIDER_OPENAI;

    public const DEFAULT_BASE_URL       = 'http://localhost:4000/v1';
    public const DEFAULT_MODEL          = 'qwen2.5:32b';

    public const DEFAULT_ANTHROPIC_BASE_URL = 'https://api.anthropic.com';
    /**
     * Sonnet rather than Opus on the strength of a 12-case comparison run
     * through this exact pipeline: same first-attempt rate, same validator
     * warnings, comparable copy, at roughly half the latency and half the cost.
     * It was also the only model to get both the layout and the copy right on
     * the hardest case. See tools/prompt-bench in the deployment repository.
     */
    public const DEFAULT_ANTHROPIC_MODEL    = 'claude-sonnet-5';
    public const DEFAULT_TEMPERATURE        = 0.4;
    public const DEFAULT_MAX_TOKENS         = 1500;
    public const DEFAULT_PLACEHOLDER        = 'https://placehold.co/600x400/eeeeee/999999.png?text=Imagem';
    public const DEFAULT_RATE_LIMIT         = 30;
    public const DEFAULT_THEME              = 'default';
    /**
     * Generous on purpose. A 32B model running locally spends most of this on
     * the cold start, loading tens of gigabytes into memory on the first call.
     * A hosted provider answers in a fraction of it and never notices.
     */
    public const DEFAULT_TIMEOUT        = 180;
    public const DEFAULT_MAX_SOURCE_KB  = 12;

    /**
     * The brief travels in the system prompt on every single generation, so an
     * unbounded one quietly makes each call more expensive.
     */
    public const MAX_BRAND_BRIEF_CHARS = 2000;

    public function __construct(
        private IntegrationsHelper $integrationsHelper,
    ) {
    }

    public function isPublished(): bool
    {
        try {
            return (bool) $this->getIntegrationEntity()->getIsPublished();
        } catch (IntegrationNotFoundException) {
            return false;
        }
    }

    /**
     * @return self::PROVIDER_*
     */
    public function getProvider(): string
    {
        $provider = $this->string('provider', self::DEFAULT_PROVIDER);

        // The value comes out of a database column rather than out of the
        // form, so it can hold anything. Falling back beats fataling.
        return in_array($provider, [self::PROVIDER_OPENAI, self::PROVIDER_ANTHROPIC], true)
            ? $provider
            : self::DEFAULT_PROVIDER;
    }

    /**
     * The default follows the provider, so switching the dropdown and saving
     * works without also retyping the endpoint.
     */
    public function getBaseUrl(): string
    {
        $default = self::PROVIDER_ANTHROPIC === $this->getProvider()
            ? self::DEFAULT_ANTHROPIC_BASE_URL
            : self::DEFAULT_BASE_URL;

        return $this->string('base_url', $default);
    }

    public function getApiKey(): string
    {
        try {
            $keys = $this->getIntegrationEntity()->getApiKeys();
        } catch (IntegrationNotFoundException) {
            return '';
        }

        return is_array($keys) && isset($keys['api_key']) ? (string) $keys['api_key'] : '';
    }

    public function getModel(): string
    {
        $default = self::PROVIDER_ANTHROPIC === $this->getProvider()
            ? self::DEFAULT_ANTHROPIC_MODEL
            : self::DEFAULT_MODEL;

        return $this->string('model', $default);
    }

    public function getTemperature(): float
    {
        $settings = $this->getFeatureSettings();

        return isset($settings['temperature']) ? (float) $settings['temperature'] : self::DEFAULT_TEMPERATURE;
    }

    public function getMaxTokens(): int
    {
        return $this->int('max_tokens', self::DEFAULT_MAX_TOKENS);
    }

    /**
     * Free text describing what the company sells and how it speaks. Empty on a
     * fresh install: the plugin works without it, it just writes more generic
     * copy.
     */
    public function getBrandBrief(): string
    {
        return $this->string('brand', '');
    }

    /**
     * On by default. A generator that cannot personalise is the poorer default,
     * and the list is small: 58 tokens is roughly 550 input tokens per call.
     */
    public function areTokensEnabled(): bool
    {
        $settings = $this->getFeatureSettings();

        return !isset($settings['tokens_enabled']) || (bool) $settings['tokens_enabled'];
    }

    /**
     * Tokens the administrator does not want offered. Accepts one per line or
     * comma separated, with or without braces, because both are what someone
     * types after copying from the builder.
     *
     * @return string[]
     */
    public function getExcludedTokens(): array
    {
        $raw = $this->string('tokens_excluded', '');

        if ('' === $raw) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('~[\r\n,]+~', $raw) ?: [])));
    }

    public function getPlaceholderImage(): string
    {
        return $this->string('placeholder_image', self::DEFAULT_PLACEHOLDER);
    }

    public function getRateLimitPerHour(): int
    {
        return $this->int('rate_limit', self::DEFAULT_RATE_LIMIT);
    }

    public function getThemeId(): string
    {
        return $this->string('theme', self::DEFAULT_THEME);
    }

    public function getTimeoutSeconds(): int
    {
        return $this->int('timeout', self::DEFAULT_TIMEOUT);
    }

    public function getMaxSourceBytes(): int
    {
        return self::DEFAULT_MAX_SOURCE_KB * 1024;
    }

    /**
     * IntegrationFeatureSettingsType nests the plugin's own form under an
     * "integration" key, so what gets stored is
     * ['integration' => ['base_url' => ...]] and not ['base_url' => ...].
     *
     * Reading the top level only would silently fall back to the defaults and
     * ignore everything the user configured.
     *
     * @return mixed[]
     */
    public function getFeatureSettings(): array
    {
        try {
            $settings = $this->getIntegrationEntity()->getFeatureSettings() ?: [];
        } catch (IntegrationNotFoundException) {
            return [];
        }

        if (isset($settings['integration']) && is_array($settings['integration'])) {
            return $settings['integration'] + $settings;
        }

        return $settings;
    }

    /**
     * @throws IntegrationNotFoundException
     */
    public function getIntegrationEntity(): Integration
    {
        return $this->integrationsHelper->getIntegration(AiEmailSectionsIntegration::NAME)
            ->getIntegrationConfiguration();
    }

    private function string(string $key, string $default): string
    {
        $settings = $this->getFeatureSettings();
        $value    = isset($settings[$key]) ? trim((string) $settings[$key]) : '';

        return '' === $value ? $default : $value;
    }

    private function int(string $key, int $default): int
    {
        $settings = $this->getFeatureSettings();

        return isset($settings[$key]) && '' !== $settings[$key] ? (int) $settings[$key] : $default;
    }
}
