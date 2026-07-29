<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Unit\Service;

use MauticPlugin\AiEmailSectionsBundle\Service\ThemeCatalog;
use PHPUnit\Framework\TestCase;

final class ThemeCatalogTest extends TestCase
{
    private ThemeCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ThemeCatalog();
    }

    public function testListsTheThemesThatShipWithThePlugin(): void
    {
        $themes = $this->catalog->all();

        $this->assertArrayHasKey('default', $themes);
        $this->assertArrayHasKey('brienz', $themes);
        $this->assertSame('Brienz', $themes['brienz'], 'The label comes from the name: field, not the file name.');
        $this->assertSame('Default', $themes['default']);
    }

    public function testFallsBackToTheFileNameWhenTheYamlDeclaresNoName(): void
    {
        $fixture = new ThemeCatalog(__DIR__.'/../../Fixtures/themes');

        $this->assertSame(
            ['named' => 'Properly Named', 'nameless' => 'nameless'],
            $fixture->all()
        );
    }

    public function testKnowsWhichThemesExist(): void
    {
        $this->assertTrue($this->catalog->has('brienz'));
        $this->assertFalse($this->catalog->has('a-theme-nobody-wrote'));
    }

    /**
     * The id arrives from a request payload, so it reaches a filesystem path.
     */
    public function testRejectsAnIdThatTriesToLeaveTheThemesDirectory(): void
    {
        $this->assertFalse($this->catalog->has('../../../../etc/passwd'));
        $this->assertFalse($this->catalog->has('../default'));
    }

    public function testResolvesTheIdToUseGivenARequestedOne(): void
    {
        $this->assertSame('brienz', $this->catalog->resolve('brienz', 'attract'));
    }

    public function testFallsBackWhenTheRequestedThemeDoesNotExist(): void
    {
        $this->assertSame('attract', $this->catalog->resolve('nonsense', 'attract'));
        $this->assertSame('attract', $this->catalog->resolve(null, 'attract'));
    }

    /**
     * A configured default can itself go stale, if the file it names was removed.
     */
    public function testFallsBackAllTheWayToDefaultWhenNeitherExists(): void
    {
        $this->assertSame('default', $this->catalog->resolve('nonsense', 'also-nonsense'));
    }

    /**
     * Mautic themes come in language variants (mytheme-pt, mytheme-en) that
     * share one visual identity. A suffixed template resolves to its family's
     * token file instead of falling back to the configured default.
     */
    public function testResolvesALanguageVariantToItsThemeFamily(): void
    {
        $this->assertSame('brienz', $this->catalog->resolve('brienz-pt', 'default'));
        $this->assertSame('brienz', $this->catalog->resolve('brienz-en', 'default'));
    }

    public function testPrefixResolutionRequiresTheFullSegment(): void
    {
        $this->assertSame('default', $this->catalog->resolve('brienzcopy', 'default'));
    }
}
