<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Unit\Service;

use MauticPlugin\AiEmailSectionsBundle\Service\MjmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Preservation invariants for edit mode.
 *
 * The risk here is not invalid MJML. It is the model returning something valid
 * that destroys hand-made work.
 *
 * Some prompts below are deliberately in Portuguese: the intent-detection
 * keyword lists are multilingual, and these cases prove the non-English
 * entries actually match.
 */
final class MjmlValidatorPreservationTest extends TestCase
{
    private const PLACEHOLDER = 'https://placehold.co/600x400';

    private MjmlValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new MjmlValidator(self::PLACEHOLDER);
    }

    public function testRejectsImageSourceTheModelInvented(): void
    {
        $source = '<mj-section><mj-column><mj-image src="https://example.com/real.jpg"></mj-image></mj-column></mj-section>';
        $output = '<mj-section><mj-column><mj-image src="https://unsplash.com/inventada.jpg"></mj-image></mj-column></mj-section>';

        $result = $this->validator->validate($output, $source, 'muda o fundo para creme');

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('image', implode(' ', $result->getErrors()));
    }

    public function testAcceptsConfiguredPlaceholderAsNewImageSource(): void
    {
        $source = '<mj-section><mj-column><mj-text>Sem imagem</mj-text></mj-column></mj-section>';
        $output = '<mj-section><mj-column><mj-text>Sem imagem</mj-text><mj-image src="'.self::PLACEHOLDER.'"></mj-image></mj-column></mj-section>';

        $result = $this->validator->validate($output, $source, 'acrescenta uma imagem');

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
    }

    public function testRejectsRemovalOfMauticToken(): void
    {
        $source = '<mj-section><mj-column><mj-text>Hello {contactfield=firstname}</mj-text></mj-column></mj-section>';
        $output = '<mj-section><mj-column><mj-text>Hello customer</mj-text></mj-column></mj-section>';

        $result = $this->validator->validate($output, $source, 'troca o botão para castanho');

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('contactfield=firstname', implode(' ', $result->getErrors()));
    }

    public function testRejectsRemovedLinkWhenPromptDoesNotMentionLinks(): void
    {
        $source = '<mj-section><mj-column><mj-button href="https://example.com/shop">Comprar</mj-button></mj-column></mj-section>';
        $output = '<mj-section><mj-column><mj-text>Comprar</mj-text></mj-column></mj-section>';

        $result = $this->validator->validate($output, $source, 'muda o fundo para creme');

        $this->assertFalse($result->isValid());
    }

    public function testAllowsRemovedLinkWhenPromptMentionsButton(): void
    {
        $source = '<mj-section><mj-column><mj-button href="https://example.com/shop">Comprar</mj-button></mj-column></mj-section>';
        $output = '<mj-section><mj-column><mj-text>Comprar</mj-text></mj-column></mj-section>';

        $result = $this->validator->validate($output, $source, 'tira o botão');

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
    }

    public function testDowngradesLinkLossToWarningOnFinalAttempt(): void
    {
        $source = '<mj-section><mj-column><mj-button href="https://example.com/shop">Comprar</mj-button></mj-column></mj-section>';
        $output = '<mj-section><mj-column><mj-text>Comprar</mj-text></mj-column></mj-section>';

        $result = $this->validator->validate($output, $source, 'muda o fundo para creme', true);

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
        $this->assertNotEmpty($result->getWarnings());
    }

    public function testRejectsTextShrinkingBelowSixtyPercent(): void
    {
        $long   = str_repeat('Uma frase com algum comprimento real. ', 10);
        $source = '<mj-section><mj-column><mj-text>'.$long.'</mj-text></mj-column></mj-section>';
        $output = '<mj-section><mj-column><mj-text>Curto.</mj-text></mj-column></mj-section>';

        $result = $this->validator->validate($output, $source, 'muda a cor do texto para castanho');

        $this->assertFalse($result->isValid());
    }

    public function testAllowsTextShrinkingWhenPromptAsksToShorten(): void
    {
        $long   = str_repeat('Uma frase com algum comprimento real. ', 10);
        $source = '<mj-section><mj-column><mj-text>'.$long.'</mj-text></mj-column></mj-section>';
        $output = '<mj-section><mj-column><mj-text>Curto.</mj-text></mj-column></mj-section>';

        $result = $this->validator->validate($output, $source, 'encurta o texto para metade');

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
    }

    public function testKeepsHardInvariantsFailingEvenOnFinalAttempt(): void
    {
        $source = '<mj-section><mj-column><mj-text>Hello {contactfield=firstname}</mj-text></mj-column></mj-section>';
        $output = '<mj-section><mj-column><mj-text>Hello customer</mj-text></mj-column></mj-section>';

        $result = $this->validator->validate($output, $source, 'muda o fundo', true);

        $this->assertFalse($result->isValid());
    }

    public function testSkipsPreservationChecksWithoutSource(): void
    {
        $output = '<mj-section><mj-column><mj-image src="https://qualquer.pt/nova.jpg"></mj-image></mj-column></mj-section>';

        $this->assertTrue($this->validator->validate($output)->isValid());
    }
}
