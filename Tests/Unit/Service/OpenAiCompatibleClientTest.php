<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Unit\Service;

use MauticPlugin\AiEmailSectionsBundle\Exception\LlmUnavailableException;
use MauticPlugin\AiEmailSectionsBundle\Service\OpenAiCompatibleClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiCompatibleClientTest extends TestCase
{
    public function testReturnsTheAssistantMessageContent(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => '<mj-section></mj-section>']]],
        ], JSON_THROW_ON_ERROR)));

        $client = $this->client($http);

        $this->assertSame('<mj-section></mj-section>', $client->complete([['role' => 'user', 'content' => 'hello']]));
    }

    public function testPostsToTheChatCompletionsEndpointWithTheConfiguredModel(): void
    {
        $seen = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? ''];

            return new MockResponse(json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
            ], JSON_THROW_ON_ERROR));
        });

        $this->client($http)->complete([['role' => 'user', 'content' => 'hello']]);

        $this->assertSame('POST', $seen['method']);
        $this->assertSame('http://litellm.local/v1/chat/completions', $seen['url']);

        $payload = json_decode((string) $seen['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('qwen2.5:32b', $payload['model']);
        $this->assertSame(0.4, $payload['temperature']);
        $this->assertSame('hello', $payload['messages'][0]['content']);
    }

    /**
     * Symfony's "timeout" is the idle timeout, the gap between received bytes.
     * A non-streaming completion sends nothing until the whole answer is ready,
     * so a local model that thinks for a minute trips it even though it is
     * working fine. The total budget has to be expressed with max_duration.
     */
    public function testGivesTheModelTheWholeBudgetAndNotJustTheIdleGap(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse(json_encode(['choices' => [['message' => ['content' => 'ok']]]], JSON_THROW_ON_ERROR));
        });

        $this->client($http)->complete([['role' => 'user', 'content' => 'hello']]);

        $this->assertSame(45.0, (float) $seen['timeout']);
        $this->assertSame(45.0, (float) $seen['max_duration']);
    }

    public function testSendsTheApiKeyAsBearerToken(): void
    {
        $headers = [];
        $http    = new MockHttpClient(function (string $method, string $url, array $options) use (&$headers): MockResponse {
            $headers = $options['headers'] ?? [];

            return new MockResponse(json_encode(['choices' => [['message' => ['content' => 'ok']]]], JSON_THROW_ON_ERROR));
        });

        $this->client($http)->complete([['role' => 'user', 'content' => 'hello']]);

        $this->assertContains('Authorization: Bearer sk-teste', $headers);
    }

    public function testThrowsWhenProviderReturnsAnError(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":"nope"}', ['http_code' => 500]));

        $this->expectException(LlmUnavailableException::class);

        $this->client($http)->complete([['role' => 'user', 'content' => 'hello']]);
    }

    public function testThrowsWhenTransportFails(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('connection refused');
        });

        $this->expectException(LlmUnavailableException::class);

        $this->client($http)->complete([['role' => 'user', 'content' => 'hello']]);
    }

    public function testThrowsWhenResponseHasNoChoices(): void
    {
        $http = new MockHttpClient(new MockResponse('{"choices":[]}'));

        $this->expectException(LlmUnavailableException::class);

        $this->client($http)->complete([['role' => 'user', 'content' => 'hello']]);
    }

    private function client(MockHttpClient $http): OpenAiCompatibleClient
    {
        return new OpenAiCompatibleClient(
            $http,
            'http://litellm.local/v1',
            'sk-teste',
            'qwen2.5:32b',
            0.4,
            1500,
            45,
        );
    }
}
