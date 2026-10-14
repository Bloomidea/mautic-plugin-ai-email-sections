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

    public function testFlagsAnEditThatCameBackAsItWasSent(): void
    {
        $source = '<mj-section><mj-column><mj-text><p>Ol&#xE1;</p></mj-text></mj-column></mj-section>';
        $client = new ScriptedLlmClient([$source]);

        $outcome = $this->service($client)->generate('edit', 'largura total de 600 px', $source, 'default');

        $this->assertTrue($outcome->unchanged);
        $this->assertSame(1, $outcome->attempts);
    }

    /**
     * Production generations 75, 109 and 124 came back identical apart from
     * how the same characters were written (&nbsp; against U+00A0, ç against
     * &#xE7;), plus the model's own indentation. None of that is a change.
     */
    public function testIgnoresDifferencesInHowTheSameMarkupIsWritten(): void
    {
        $source = '<mj-section><mj-column><mj-text><p>Devoluções&nbsp;simples</p></mj-text></mj-column></mj-section>';
        $output = "<mj-section>\n  <mj-column>\n    <mj-text><p>Devolu&#xE7;&#xF5;es\u{00A0}simples</p></mj-text>\n  </mj-column>\n</mj-section>";

        $outcome = $this->service(new ScriptedLlmClient([$output]))->generate('edit', 'uniformiza os botões', $source, 'default');

        $this->assertTrue($outcome->unchanged);
    }

    public function testASpaceBetweenWordsIsAChange(): void
    {
        $source = '<mj-section><mj-column><mj-text><p><strong>Novo</strong><em>drop</em></p></mj-text></mj-column></mj-section>';
        $output = '<mj-section><mj-column><mj-text><p><strong>Novo</strong> <em>drop</em></p></mj-text></mj-column></mj-section>';

        $outcome = $this->service(new ScriptedLlmClient([$output]))->generate('edit', 'separa as palavras', $source, 'default');

        $this->assertFalse($outcome->unchanged);
    }

    public function testAnEditThatChangedSomethingIsNotFlagged(): void
    {
        $source = '<mj-section><mj-column><mj-text>Hello</mj-text></mj-column></mj-section>';
        $output = '<mj-section background-color="#fafafa"><mj-column><mj-text>Hello</mj-text></mj-column></mj-section>';

        $outcome = $this->service(new ScriptedLlmClient([$output]))->generate('edit', 'fundo creme', $source, 'default');

        $this->assertFalse($outcome->unchanged);
    }

    public function testCreateModeIsNeverUnchanged(): void
    {
        $outcome = $this->service(new ScriptedLlmClient([self::VALID]))->generate('create', 'grelha', null, 'default');

        $this->assertFalse($outcome->unchanged);
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
