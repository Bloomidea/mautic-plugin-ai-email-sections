<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Unit\Service;

use MauticPlugin\AiEmailSectionsBundle\Service\MjmlValidator;
use PHPUnit\Framework\TestCase;

final class MjmlValidatorTest extends TestCase
{
    private MjmlValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new MjmlValidator('https://placehold.co/600x400');
    }

    /**
     * Models percent-encode the braces when a token is used as a link target.
     * Mautic only replaces the literal form, so left alone the recipient gets a
     * link to a page that does not exist.
     */
    public function testDecodesPercentEncodedTokensInLinkTargets(): void
    {
        $result = $this->validator->validate(
            '<mj-section><mj-column><mj-text><p><a href="%7Bunsubscribe_url%7D">Unsubscribe</a></p></mj-text></mj-column></mj-section>'
        );

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
        $this->assertStringContainsString('href="{unsubscribe_url}"', $result->getMjml());
        $this->assertStringNotContainsString('%7B', $result->getMjml());
    }

    public function testDecodesTokensWithAnArgument(): void
    {
        $result = $this->validator->validate(
            '<mj-section><mj-column><mj-button href="%7bwebview_url%7d">View</mj-button><mj-text><p>Hi %7Bcontactfield=firstname%7D</p></mj-text></mj-column></mj-section>'
        );

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
        $this->assertStringContainsString('href="{webview_url}"', $result->getMjml());
        $this->assertStringContainsString('{contactfield=firstname}', $result->getMjml());
    }

    /**
     * The rewrite is narrow on purpose: an encoded brace that is not a token
     * belongs to the URL and has to survive.
     */
    public function testLeavesUnrelatedPercentEncodingAlone(): void
    {
        $result = $this->validator->validate(
            '<mj-section><mj-column><mj-button href="https://example.com/s?q=%7B%22a%22:1%7D">Go</mj-button></mj-column></mj-section>'
        );

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
        $this->assertStringContainsString('%7B%22a%22:1%7D', $result->getMjml());
    }

    public function testAcceptsValidSection(): void
    {
        $result = $this->validator->validate('<mj-section><mj-column><mj-text>Hello</mj-text></mj-column></mj-section>');

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
    }

    public function testAcceptsMultipleSiblingSections(): void
    {
        $mjml = '<mj-section><mj-column><mj-text>One</mj-text></mj-column></mj-section>'
            .'<mj-section><mj-column><mj-text>Two</mj-text></mj-column></mj-section>';

        $this->assertTrue($this->validator->validate($mjml)->isValid());
    }

    public function testRejectsTagOutsideAllowlist(): void
    {
        $mjml = '<mj-section><mj-column><mj-carousel><mj-carousel-image src="https://a.pt/x.png"/></mj-carousel></mj-column></mj-section>';

        $result = $this->validator->validate($mjml);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('mj-carousel', implode(' ', $result->getErrors()));
    }

    public function testRejectsTopLevelNodeThatIsNotSection(): void
    {
        $result = $this->validator->validate('<mj-column><mj-text>Loose</mj-text></mj-column>');

        $this->assertFalse($result->isValid());
    }

    public function testRejectsProseBeforeTheBlock(): void
    {
        $mjml = 'Sure! Here is the section you asked for:'
            .'<mj-section><mj-column><mj-text>Hello</mj-text></mj-column></mj-section>';

        $this->assertFalse($this->validator->validate($mjml)->isValid());
    }

    public function testRejectsEventHandlerAttributes(): void
    {
        $mjml = '<mj-section><mj-column><mj-text onclick="alert(1)">Hello</mj-text></mj-column></mj-section>';

        $result = $this->validator->validate($mjml);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('onclick', implode(' ', $result->getErrors()));
    }

    public function testRejectsMalformedMarkup(): void
    {
        $mjml = '<mj-section><mj-column><mj-text>Cut off midway';

        $this->assertFalse($this->validator->validate($mjml)->isValid());
    }

    public function testRejectsMarkupDeeperThanEightLevels(): void
    {
        $inner = str_repeat('<mj-group>', 9).'<mj-text>Deep</mj-text>'.str_repeat('</mj-group>', 9);
        $mjml  = '<mj-section><mj-column>'.$inner.'</mj-column></mj-section>';

        $result = $this->validator->validate($mjml);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('depth', implode(' ', $result->getErrors()));
    }

    public function testRejectsMarkupLargerThanTwelveKilobytes(): void
    {
        $filler = str_repeat('a', 13 * 1024);
        $mjml   = '<mj-section><mj-column><mj-text>'.$filler.'</mj-text></mj-column></mj-section>';

        $result = $this->validator->validate($mjml);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('12', implode(' ', $result->getErrors()));
    }

    public function testRewritesDangerousUrlSchemeToHash(): void
    {
        $mjml = '<mj-section><mj-column><mj-button href="javascript:alert(1)">Go</mj-button></mj-column></mj-section>';

        $result = $this->validator->validate($mjml);

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
        $this->assertStringContainsString('href="#"', $result->getMjml());
        $this->assertStringNotContainsString('javascript:', $result->getMjml());
    }

    public function testAcceptsMauticTokenInsideHref(): void
    {
        $mjml = '<mj-section><mj-column><mj-button href="{unsubscribe_url}">Unsubscribe</mj-button></mj-column></mj-section>';

        $this->assertTrue($this->validator->validate($mjml)->isValid());
    }

    public function testAcceptsInlineHtmlInsideText(): void
    {
        $mjml = '<mj-section><mj-column><mj-text><p>First<br>paragraph with <strong>bold</strong></p></mj-text></mj-column></mj-section>';

        $result = $this->validator->validate($mjml);

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
        $this->assertStringContainsString('<strong>bold</strong>', $result->getMjml());
    }

    public function testAcceptsNonBreakingSpaceEntity(): void
    {
        $mjml = '<mj-section><mj-column><mj-text>Hello&nbsp;world</mj-text></mj-column></mj-section>';

        $result = $this->validator->validate($mjml);

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
    }

    public function testRejectsScriptInsideText(): void
    {
        $mjml = '<mj-section><mj-column><mj-text><script>alert(1)</script></mj-text></mj-column></mj-section>';

        $result = $this->validator->validate($mjml);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('script', implode(' ', $result->getErrors()));
    }

    public function testRejectsEventHandlerInsideTextMarkup(): void
    {
        $mjml = '<mj-section><mj-column><mj-text><a href="https://a.pt" onmouseover="steal()">Link</a></mj-text></mj-column></mj-section>';

        $this->assertFalse($this->validator->validate($mjml)->isValid());
    }

    public function testNormalisesSelfClosingImageToExplicitClosingTag(): void
    {
        $mjml = '<mj-section><mj-column><mj-image src="https://a.pt/x.png" /></mj-column></mj-section>';

        $result = $this->validator->validate($mjml);

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
        $this->assertStringContainsString('</mj-image>', $result->getMjml());
        $this->assertStringNotContainsString('/>', $result->getMjml());
    }

    public function testAcceptsBareAmpersandInsideUrlAttribute(): void
    {
        $mjml = '<mj-section><mj-column><mj-image src="https://a.pt/x.png?utm=1&campanha=natal" /></mj-column></mj-section>';

        $result = $this->validator->validate($mjml);

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
        $this->assertStringContainsString('campanha=natal', $result->getMjml());
    }

    public function testKeepsFirstOfDuplicatedAttributes(): void
    {
        $mjml = '<mj-section><mj-column><mj-text color="#111111" color="#222222">Hello</mj-text></mj-column></mj-section>';

        $result = $this->validator->validate($mjml);

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
        $this->assertStringContainsString('#111111', $result->getMjml());
        $this->assertStringNotContainsString('#222222', $result->getMjml());
    }

    public function testWarnsWhenImageHasNoAltText(): void
    {
        $mjml = '<mj-section><mj-column><mj-image src="https://placehold.co/600x400"></mj-image></mj-column></mj-section>';

        $result = $this->validator->validate($mjml);

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
        $this->assertNotEmpty($result->getWarnings());
        $this->assertStringContainsString('alt', $result->getWarnings()[0]);
    }

    public function testAcceptsImageWithAltTextWithoutWarning(): void
    {
        $mjml = '<mj-section><mj-column><mj-image src="https://placehold.co/600x400" alt="Merino wool coat"></mj-image></mj-column></mj-section>';

        $result = $this->validator->validate($mjml);

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
        $this->assertSame([], $result->getWarnings());
    }

    /**
     * An explicit alt="" is the standard way to mark a decorative image, so it
     * is a deliberate choice rather than an omission.
     */
    public function testAcceptsEmptyAltAsDecorativeWithoutWarning(): void
    {
        $mjml = '<mj-section><mj-column><mj-image src="https://placehold.co/600x400" alt=""></mj-image></mj-column></mj-section>';

        $result = $this->validator->validate($mjml);

        $this->assertTrue($result->isValid(), implode(' | ', $result->getErrors()));
        $this->assertSame([], $result->getWarnings());
    }
}
