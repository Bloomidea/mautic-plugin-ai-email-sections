<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Unit\Service;

use MauticPlugin\AiEmailSectionsBundle\Service\GenerationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The telemetry write needs the last raw response. Reaching for it with end()
 * from outside blows up with "Cannot modify readonly property", because end()
 * takes its argument by reference to move the array pointer. The outcome
 * exposes it through a method so callers never have to.
 */
final class GenerationOutcomeTest extends TestCase
{
    public function testExposesTheLastRawResponse(): void
    {
        $outcome = new GenerationOutcome('<mj-section></mj-section>', 2, [], ['first', 'second']);

        $this->assertSame('second', $outcome->lastRawResponse());
    }

    public function testLastRawResponseIsNullWithoutAnyResponse(): void
    {
        $outcome = new GenerationOutcome('<mj-section></mj-section>', 1);

        $this->assertNull($outcome->lastRawResponse());
    }

    public function testReadingTheLastResponseDoesNotTouchTheReadonlyProperty(): void
    {
        $outcome = new GenerationOutcome('<mj-section></mj-section>', 3, [], ['a', 'b', 'c']);

        $outcome->lastRawResponse();

        $this->assertSame(['a', 'b', 'c'], $outcome->rawResponses);
    }
}
