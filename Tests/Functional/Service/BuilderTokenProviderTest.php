<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Functional\Service;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\AiEmailSectionsBundle\Service\BuilderTokenProvider;

/**
 * The token list has to come from the same place the builder's own token picker
 * gets it: the EMAIL_ON_BUILD event. Reading lead_fields directly would return
 * contact fields only, and would miss everything other bundles and plugins
 * register, going stale whenever the install changes.
 */
final class BuilderTokenProviderTest extends MauticMysqlTestCase
{
    public function testCollectsTheTokensTheBuilderItselfOffers(): void
    {
        $tokens = $this->provider()->all();

        $this->assertNotSame([], $tokens);
        $this->assertArrayHasKey('{unsubscribe_url}', $tokens, 'Core email tokens must be present.');
    }

    /**
     * Contact fields arrive through the same event, so no separate query is
     * needed for them and none should be added.
     */
    public function testContactFieldsComeThroughTheSameEvent(): void
    {
        $tokens = $this->provider()->all();

        $keys = implode(' ', array_keys($tokens));
        $this->assertStringContainsString('contactfield=', $keys);
    }

    /**
     * getTokens(false) drops the legacy {leadfield=...} aliases, which are the
     * same fields under an older spelling. Sending both wastes input tokens on
     * every generation and invites the model to use the deprecated form.
     */
    public function testDropsTheLegacyLeadfieldAliases(): void
    {
        foreach (array_keys($this->provider()->all()) as $token) {
            $this->assertStringNotContainsString('{leadfield', $token);
        }
    }

    public function testExclusionsAreRemoved(): void
    {
        $all = $this->provider()->all();
        $one = array_key_first($all);

        $filtered = $this->provider()->all([$one]);

        $this->assertArrayNotHasKey($one, $filtered);
        $this->assertCount(count($all) - 1, $filtered);
    }

    /**
     * An administrator types what they see in the builder, which is the token
     * with its braces. Accepting the bare name too costs nothing and avoids a
     * setting that silently does nothing.
     */
    public function testExclusionsMatchWithOrWithoutBraces(): void
    {
        $this->assertArrayNotHasKey('{unsubscribe_url}', $this->provider()->all(['unsubscribe_url']));
        $this->assertArrayNotHasKey('{unsubscribe_url}', $this->provider()->all(['{unsubscribe_url}']));
    }

    private function provider(): BuilderTokenProvider
    {
        return static::getContainer()->get(BuilderTokenProvider::class);
    }
}
