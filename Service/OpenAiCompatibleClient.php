<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Service;

use MauticPlugin\AiEmailSectionsBundle\Exception\LlmUnavailableException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAI-compatible client with a configurable base URL.
 *
 * Keeps the plugin model agnostic: point it at LiteLLM, at OpenAI, or at a
 * self-hosted Ollama, with no code change. The API key never leaves this
 * class for the browser.
 */
final class OpenAiCompatibleClient implements LlmClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly float $temperature,
        private readonly int $maxTokens,
        private readonly int $timeoutSeconds,
    ) {
    }

    public function complete(array $messages): string
    {
        $headers = ['Content-Type' => 'application/json'];

        if ('' !== $this->apiKey) {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        }

        try {
            $response = $this->http->request('POST', $this->endpoint(), [
                'headers' => $headers,
                'json'    => [
                    'model'       => $this->model,
                    'temperature' => $this->temperature,
                    'max_tokens'  => $this->maxTokens,
                    'messages'    => array_values($messages),
                ],
                // "timeout" is the idle timeout, the gap between received
                // bytes. A non-streaming completion sends nothing until the
                // whole answer is ready, so a local model that thinks for a
                // minute would trip it while working perfectly well.
                // "max_duration" is the real budget for the whole call.
                'timeout'      => $this->timeoutSeconds,
                'max_duration' => $this->timeoutSeconds,
            ]);

            $status = $response->getStatusCode();
            $body   = $response->getContent(false);
        } catch (TransportException $exception) {
            throw LlmUnavailableException::unreachable($exception->getMessage());
        } catch (ExceptionInterface $exception) {
            throw LlmUnavailableException::unreachable($exception->getMessage());
        }

        if ($status < 200 || $status >= 300) {
            throw LlmUnavailableException::unreachable(sprintf('the provider answered %d', $status));
        }

        return $this->extractContent($body);
    }

    private function endpoint(): string
    {
        return rtrim($this->baseUrl, '/').'/chat/completions';
    }

    private function extractContent(string $body): string
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw LlmUnavailableException::unreachable('the response was not valid JSON');
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;

        if (!is_string($content) || '' === trim($content)) {
            throw LlmUnavailableException::unreachable('the response carried no content');
        }

        return $content;
    }
}
