<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Service;

use MauticPlugin\AiEmailSectionsBundle\Exception\LlmUnavailableException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Anthropic Messages API client.
 *
 * A second implementation rather than a flag on the OpenAI-compatible one,
 * because the two agree on almost nothing: different endpoint, different auth
 * header, the system prompt is a top-level field instead of a turn, the answer
 * arrives as a list of blocks instead of a single string, and the sampling
 * parameters the other client always sends are rejected outright here.
 *
 * Reaching Anthropic through a proxy that speaks the OpenAI shape (LiteLLM)
 * also works and needs none of this. This exists so a single-provider install
 * does not have to run one.
 */
final class AnthropicClient implements LlmClientInterface
{
    /**
     * Pinned on purpose. The API is versioned by header and unversioned
     * requests are rejected.
     */
    public const API_VERSION = '2023-06-01';

    /**
     * max_tokens caps thinking and answer together, and thinking is on by
     * default on the current models. A budget sized for the visible text alone
     * gets spent reasoning, and the call comes back truncated with nothing
     * usable in it. The configured value is treated as a floor to clear, not
     * as the ceiling the other provider means by the same word.
     */
    public const MINIMUM_MAX_TOKENS = 8000;

    /**
     * Writing one themed section from a system prompt that already spells out
     * the rules is a well-specified task, and low effort is strong on it while
     * keeping latency close to what the local model gave us. Raise it here if
     * generations start needing more than one attempt.
     */
    public const EFFORT = 'low';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens,
        private readonly int $timeoutSeconds,
    ) {
    }

    public function complete(array $messages): string
    {
        [$system, $turns] = $this->split($messages);

        $payload = [
            'model'      => $this->model,
            'max_tokens' => max($this->maxTokens, self::MINIMUM_MAX_TOKENS),
            'messages'   => $turns,
            // Effort is the depth control here. There is no temperature: it
            // was removed from the current models and returns a 400.
            'output_config' => ['effort' => self::EFFORT],
        ];

        if ('' !== $system) {
            $payload['system'] = $system;
        }

        try {
            $response = $this->http->request('POST', $this->endpoint(), [
                'headers' => [
                    'Content-Type'      => 'application/json',
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                ],
                'json' => $payload,
                // As with the other client: "timeout" is the idle gap between
                // received bytes, "max_duration" is the budget for the call.
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

    /**
     * Splits the conversation the PromptBuilder produces into the shape this
     * API expects: system prompt on its own, everything else as turns.
     *
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @return array{0: string, 1: array<int, array{role: string, content: string}>}
     */
    private function split(array $messages): array
    {
        $system = [];
        $turns  = [];

        foreach ($messages as $message) {
            if ('system' === $message['role']) {
                $system[] = $message['content'];
                continue;
            }

            $turns[] = ['role' => $message['role'], 'content' => $message['content']];
        }

        return [implode("\n\n", $system), $turns];
    }

    private function endpoint(): string
    {
        $base = rtrim($this->baseUrl, '/');

        // Both https://api.anthropic.com and https://api.anthropic.com/v1 are
        // forms people copy out of documentation. Neither should produce
        // /v1/v1/messages.
        if (str_ends_with($base, '/v1')) {
            return $base.'/messages';
        }

        return $base.'/v1/messages';
    }

    private function extractContent(string $body): string
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw LlmUnavailableException::unreachable('the response was not valid JSON');
        }

        $stopReason = $decoded['stop_reason'] ?? null;

        // A declined request is a successful HTTP 200 with an empty body, so
        // this has to be checked before reading the content.
        if ('refusal' === $stopReason) {
            $category = $decoded['stop_details']['category'] ?? 'unspecified';

            throw LlmUnavailableException::unreachable(sprintf('the provider refused the request (%s)', is_string($category) ? $category : 'unspecified'));
        }

        if ('max_tokens' === $stopReason) {
            throw LlmUnavailableException::unreachable('the answer was cut off by the max tokens limit; raise it in the plugin configuration');
        }

        $text = '';

        foreach ($decoded['content'] ?? [] as $block) {
            // Thinking blocks travel alongside the answer and carry no usable
            // text: the raw chain of thought is never returned.
            if (is_array($block) && 'text' === ($block['type'] ?? null) && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        if ('' === trim($text)) {
            throw LlmUnavailableException::unreachable('the response carried no content');
        }

        return $text;
    }
}
