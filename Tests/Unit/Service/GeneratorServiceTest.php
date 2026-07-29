<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Unit\Service;

use MauticPlugin\AiEmailSectionsBundle\Exception\GenerationFailedException;
use MauticPlugin\AiEmailSectionsBundle\Service\GeneratorService;
use MauticPlugin\AiEmailSectionsBundle\Service\LlmClientInterface;
use MauticPlugin\AiEmailSectionsBundle\Service\MjmlValidator;
use MauticPlugin\AiEmailSectionsBundle\Service\PromptBuilder;
use PHPUnit\Framework\TestCase;

final class GeneratorServiceTest extends TestCase
{
    private const VALID = '<mj-section><mj-column><mj-text>Hello</mj-text></mj-column></mj-section>';

    public function testReturnsMjmlOnTheFirstValidResponse(): void
    {
        $service = $this->service(new ScriptedLlmClient([self::VALID]));

        $outcome = $service->generate('create', 'grelha', null, 'default');

        $this->assertSame(1, $outcome->attempts);
        $this->assertStringContainsString('mj-section', $outcome->mjml);
    }

    public function testRetriesAfterInvalidResponse(): void
    {
        $client  = new ScriptedLlmClient(['<mj-carousel></mj-carousel>', self::VALID]);
        $outcome = $this->service($client)->generate('create', 'grelha', null, 'default');

        $this->assertSame(2, $outcome->attempts);
        $this->assertStringContainsString('mj-section', $outcome->mjml);
    }

    public function testFeedsValidationErrorsBackToTheModel(): void
    {
        $client = new ScriptedLlmClient(['<mj-carousel></mj-carousel>', self::VALID]);

        $this->service($client)->generate('create', 'grelha', null, 'default');

        $lastConversation = $client->lastMessages;
        $lastTurn         = end($lastConversation);

        $this->assertSame('user', $lastTurn['role']);
        $this->assertStringContainsString('mj-carousel', $lastTurn['content']);
    }

    public function testGivesUpAfterThreeAttempts(): void
    {
        $client = new ScriptedLlmClient(['<p>nope</p>', '<p>nope</p>', '<p>nope</p>']);

        $this->expectException(GenerationFailedException::class);

        $this->service($client)->generate('create', 'grelha', null, 'default');
    }

    public function testStripsCodeFencesFromTheResponse(): void
    {
        $client  = new ScriptedLlmClient(["```mjml\n".self::VALID."\n```"]);
        $outcome = $this->service($client)->generate('create', 'grelha', null, 'default');

        $this->assertStringStartsWith('<mj-section', $outcome->mjml);
    }

    public function testLastAttemptDowngradesSoftInvariantsToWarnings(): void
    {
        $source = '<mj-section><mj-column><mj-button href="https://example.com/shop">Comprar</mj-button></mj-column></mj-section>';
        $output = '<mj-section><mj-column><mj-text>Comprar</mj-text></mj-column></mj-section>';

        $client  = new ScriptedLlmClient([$output, $output, $output]);
        $outcome = $this->service($client)->generate('edit', 'muda o fundo para creme', $source, 'default');

        $this->assertSame(3, $outcome->attempts);
        $this->assertNotEmpty($outcome->warnings);
    }

    public function testKeepsRawResponsesForTelemetry(): void
    {
        $client  = new ScriptedLlmClient(['<mj-carousel></mj-carousel>', self::VALID]);
        $outcome = $this->service($client)->generate('create', 'grelha', null, 'default');

        $this->assertCount(2, $outcome->rawResponses);
        $this->assertStringContainsString('mj-carousel', $outcome->rawResponses[0]);
    }

    private function service(LlmClientInterface $client): GeneratorService
    {
        return new GeneratorService(
            $client,
            new PromptBuilder(__DIR__.'/../../../Resources/prompts', 'https://placehold.co/600x400'),
            new MjmlValidator('https://placehold.co/600x400'),
        );
    }
}

/**
 * Fake client returning scripted responses. Not a mock: it keeps the real
 * conversation it received, so tests can assert against it.
 */
final class ScriptedLlmClient implements LlmClientInterface
{
    /** @var array<int, array{role: string, content: string}> */
    public array $lastMessages = [];

    /**
     * @param string[] $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function complete(array $messages): string
    {
        $this->lastMessages = $messages;

        return array_shift($this->responses) ?? '';
    }
}
