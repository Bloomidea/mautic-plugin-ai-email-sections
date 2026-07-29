<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Unit\Service;

use MauticPlugin\AiEmailSectionsBundle\Exception\LlmUnavailableException;
use MauticPlugin\AiEmailSectionsBundle\Service\AnthropicClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The Messages API differs from the OpenAI shape in every layer that matters:
 * the endpoint, the auth header, where the system prompt lives, the response
 * envelope, and which sampling parameters are even accepted. Each of those is
 * a test here, because getting any one of them wrong is a 400 in production
 * and nothing at all in a smoke test against a local model.
 */
final class AnthropicClientTest extends TestCase
{
    public function testReturnsTheTextBlocks(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'content'     => [['type' => 'text', 'text' => '<mj-section></mj-section>']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR)));

        $this->assertSame('<mj-section></mj-section>', $this->client($http)->complete($this->messages()));
    }

    /**
     * Thinking is on by default and its blocks arrive alongside the answer with
     * an empty body, because the raw chain of thought is never returned. Handing
     * those to the MJML validator would fail every generation.
     */
    public function testIgnoresThinkingBlocksAndKeepsOnlyTheAnswer(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'content' => [
                ['type' => 'thinking', 'thinking' => ''],
                ['type' => 'text', 'text' => '<mj-section>'],
                ['type' => 'text', 'text' => '</mj-section>'],
            ],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR)));

        $this->assertSame('<mj-section></mj-section>', $this->client($http)->complete($this->messages()));
    }

    public function testPostsToTheMessagesEndpointWithTheConfiguredModel(): void
    {
        $seen = new \stdClass();
        $http = $this->capture($seen);

        $this->client($http)->complete($this->messages());

        $this->assertSame('POST', $seen->method);
        $this->assertSame('https://api.anthropic.com/v1/messages', $seen->url);
        $this->assertSame('claude-opus-5', $this->payload($seen)['model']);
    }

    /**
     * A base URL that already carries the version segment must not produce
     * /v1/v1/messages. People paste whichever form the docs showed them.
     */
    public function testDoesNotRepeatTheVersionSegmentAlreadyInTheBaseUrl(): void
    {
        $seen = new \stdClass();
        $http = $this->capture($seen);

        $this->client($http, baseUrl: 'https://api.anthropic.com/v1/')->complete($this->messages());

        $this->assertSame('https://api.anthropic.com/v1/messages', $seen->url);
    }

    /**
     * The system prompt is a top-level field, not a turn. Left in the array it
     * is rejected as an invalid role.
     */
    public function testLiftsTheSystemPromptOutOfTheConversation(): void
    {
        $seen = new \stdClass();
        $http = $this->capture($seen);

        $this->client($http)->complete([
            ['role' => 'system', 'content' => 'You write MJML.'],
            ['role' => 'user', 'content' => 'a banner'],
        ]);

        $payload = $this->payload($seen);
        $this->assertSame('You write MJML.', $payload['system']);
        $this->assertCount(1, $payload['messages']);
        $this->assertSame('user', $payload['messages'][0]['role']);
    }

    public function testKeepsTheFewShotTurnsInOrder(): void
    {
        $seen = new \stdClass();
        $http = $this->capture($seen);

        $this->client($http)->complete([
            ['role' => 'system', 'content' => 'You write MJML.'],
            ['role' => 'user', 'content' => 'example in'],
            ['role' => 'assistant', 'content' => 'example out'],
            ['role' => 'user', 'content' => 'a banner'],
        ]);

        $roles = array_column($this->payload($seen)['messages'], 'role');
        $this->assertSame(['user', 'assistant', 'user'], $roles);
    }

    /**
     * temperature, top_p and top_k were removed from the current models and
     * return a 400. The OpenAI-compatible client sends temperature on every
     * call, so this is the single easiest way to break the port.
     */
    public function testNeverSendsSamplingParameters(): void
    {
        $seen = new \stdClass();
        $http = $this->capture($seen);

        $this->client($http)->complete($this->messages());

        $payload = $this->payload($seen);
        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertArrayNotHasKey('top_p', $payload);
        $this->assertArrayNotHasKey('top_k', $payload);
    }

    public function testAuthenticatesWithTheApiKeyHeaderAndPinsTheApiVersion(): void
    {
        $seen = new \stdClass();
        $http = $this->capture($seen);

        $this->client($http)->complete($this->messages());

        $headers = strtolower(implode("\n", $seen->headers));
        $this->assertStringContainsString('x-api-key: sk-ant-teste', $headers);
        $this->assertStringContainsString('anthropic-version: '.AnthropicClient::API_VERSION, $headers);
        $this->assertStringNotContainsString('authorization:', $headers);
    }

    /**
     * max_tokens caps thinking and answer together. The 1500 that sizes an
     * OpenAI-style completion is spent reasoning before a single tag is
     * written, and the call returns truncated with nothing usable in it.
     */
    public function testGivesThinkingItsOwnHeadroomOnTopOfTheConfiguredLimit(): void
    {
        $seen = new \stdClass();
        $http = $this->capture($seen);

        $this->client($http, maxTokens: 1500)->complete($this->messages());

        $this->assertSame(AnthropicClient::MINIMUM_MAX_TOKENS, $this->payload($seen)['max_tokens']);
    }

    public function testHonoursAConfiguredLimitAboveTheFloor(): void
    {
        $seen = new \stdClass();
        $http = $this->capture($seen);

        $this->client($http, maxTokens: 32000)->complete($this->messages());

        $this->assertSame(32000, $this->payload($seen)['max_tokens']);
    }

    public function testGivesTheModelTheWholeBudgetAndNotJustTheIdleGap(): void
    {
        $seen = new \stdClass();
        $http = $this->capture($seen);

        $this->client($http)->complete($this->messages());

        $this->assertSame(45.0, (float) $seen->options['timeout']);
        $this->assertSame(45.0, (float) $seen->options['max_duration']);
    }

    /**
     * A declined request is a successful HTTP 200 with an empty body. Reading
     * content[0] blindly is how that turns into a confusing crash.
     */
    public function testThrowsWhenTheRequestIsRefused(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'content'      => [],
            'stop_reason'  => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber'],
        ], JSON_THROW_ON_ERROR)));

        $this->expectException(LlmUnavailableException::class);
        $this->expectExceptionMessageMatches('/refus/i');

        $this->client($http)->complete($this->messages());
    }

    /**
     * A truncated answer is never valid MJML, and retrying it hits the same
     * ceiling. Saying so beats three rounds of "could not generate a valid
     * block" that no amount of rephrasing fixes.
     */
    public function testThrowsWhenTheAnswerWasCutOffByTheTokenLimit(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'content'     => [['type' => 'text', 'text' => '<mj-section><mj-col']],
            'stop_reason' => 'max_tokens',
        ], JSON_THROW_ON_ERROR)));

        $this->expectException(LlmUnavailableException::class);
        $this->expectExceptionMessageMatches('/max tokens/i');

        $this->client($http)->complete($this->messages());
    }

    public function testThrowsWhenProviderReturnsAnError(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":{"type":"invalid_request_error"}}', ['http_code' => 400]));

        $this->expectException(LlmUnavailableException::class);

        $this->client($http)->complete($this->messages());
    }

    public function testThrowsWhenTransportFails(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('connection refused');
        });

        $this->expectException(LlmUnavailableException::class);

        $this->client($http)->complete($this->messages());
    }

    public function testThrowsWhenTheAnswerCarriesNoText(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'content'     => [['type' => 'thinking', 'thinking' => '']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR)));

        $this->expectException(LlmUnavailableException::class);

        $this->client($http)->complete($this->messages());
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function messages(): array
    {
        return [['role' => 'user', 'content' => 'a banner']];
    }

    /**
     * Records what the client actually put on the wire. An object, not an
     * array, so the closure and the assertions share one handle.
     */
    private function capture(\stdClass $seen): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($seen): MockResponse {
            $seen->method  = $method;
            $seen->url     = $url;
            $seen->headers = $options['headers'] ?? [];
            $seen->body    = $options['body'] ?? '';
            $seen->options = $options;

            return new MockResponse(json_encode([
                'content'     => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
            ], JSON_THROW_ON_ERROR));
        });
    }

    /**
     * @return mixed[]
     */
    private function payload(\stdClass $seen): array
    {
        return json_decode((string) $seen->body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function client(
        MockHttpClient $http,
        string $baseUrl = 'https://api.anthropic.com',
        int $maxTokens = 8000,
    ): AnthropicClient {
        return new AnthropicClient($http, $baseUrl, 'sk-ant-teste', 'claude-opus-5', $maxTokens, 45);
    }
}
