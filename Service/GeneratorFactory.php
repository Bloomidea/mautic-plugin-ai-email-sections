<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Service;

use MauticPlugin\AiEmailSectionsBundle\Integration\Config;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the GeneratorService from the configuration stored on the Integration.
 *
 * It exists because the configuration is read from the database at runtime and
 * cannot be injected as a container parameter.
 */
class GeneratorFactory
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClientInterface $httpClient,
        private readonly BuilderTokenProvider $tokenProvider,
    ) {
    }

    public function create(): GeneratorService
    {
        return new GeneratorService(
            $this->createClient(),
            new PromptBuilder(
                $this->promptsDir(),
                $this->config->getPlaceholderImage(),
                $this->config->getBrandBrief(),
                $this->availableTokens(),
            ),
            new MjmlValidator($this->config->getPlaceholderImage()),
        );
    }

    /**
     * Public because it is the one branch worth pinning down on its own: the
     * wrong client for the configured provider is a 400 and nothing else.
     */
    public function createClient(): LlmClientInterface
    {
        if (Config::PROVIDER_ANTHROPIC === $this->config->getProvider()) {
            return new AnthropicClient(
                $this->httpClient,
                $this->config->getBaseUrl(),
                $this->config->getApiKey(),
                $this->config->getModel(),
                $this->config->getMaxTokens(),
                $this->config->getTimeoutSeconds(),
            );
        }

        return new OpenAiCompatibleClient(
            $this->httpClient,
            $this->config->getBaseUrl(),
            $this->config->getApiKey(),
            $this->config->getModel(),
            $this->config->getTemperature(),
            $this->config->getMaxTokens(),
            $this->config->getTimeoutSeconds(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function availableTokens(): array
    {
        if (!$this->config->areTokensEnabled()) {
            return [];
        }

        return $this->tokenProvider->all($this->config->getExcludedTokens());
    }

    private function promptsDir(): string
    {
        return __DIR__.'/../Resources/prompts';
    }
}
