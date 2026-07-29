<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Functional\Command;

use Doctrine\ORM\Tools\SchemaTool;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\AiEmailSectionsBundle\Entity\Generation;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Telemetry keeps the prompt, the source section and the generated MJML of
 * every request. That is what makes the table useful and also what makes it
 * grow without bound, so retention has to be enforced by something that runs.
 */
final class PurgeGenerationsCommandTest extends MauticMysqlTestCase
{
    /**
     * Creating the plugin's table is DDL, and MySQL commits the surrounding
     * transaction implicitly when it runs, so the rollback this base class uses
     * to isolate tests has nothing left to roll back. Each test drops and
     * recreates the table itself instead.
     */
    protected $useCleanupRollback = false;

    /**
     * Plugin tables are created by mautic:plugins:reload at install time, not by
     * the schema the test database is built from, so this one has to be made
     * here or every query below fails on a missing table.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = $this->em->getClassMetadata(Generation::class);
        $table    = $metadata->getTableName();

        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS '.$table);
        (new SchemaTool($this->em))->createSchema([$metadata]);
    }

    public function testDeletesRowsOlderThanTheRetentionWindow(): void
    {
        $this->persistGeneration(200);
        $this->persistGeneration(181);
        $this->persistGeneration(179);
        $this->persistGeneration(1);

        $tester = $this->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(2, $this->countRows(), 'Only the rows inside the window should survive.');
    }

    public function testRetentionIsConfigurable(): void
    {
        $this->persistGeneration(40);
        $this->persistGeneration(20);

        $this->execute(['--days' => '30']);

        $this->assertSame(1, $this->countRows());
    }

    /**
     * A dry run is what makes this safe to point at production the first time.
     */
    public function testDryRunReportsWithoutDeleting(): void
    {
        $this->persistGeneration(200);

        $tester = $this->execute(['--dry-run' => true]);

        $this->assertSame(1, $this->countRows(), 'A dry run must not delete.');
        $this->assertStringContainsString('1', $tester->getDisplay());
    }

    public function testRefusesANonPositiveRetention(): void
    {
        $this->persistGeneration(500);

        $tester = $this->execute(['--days' => '0']);

        $this->assertNotSame(0, $tester->getStatusCode(), 'Zero days would delete everything.');
        $this->assertSame(1, $this->countRows());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): CommandTester
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('mautic:ai-email-sections:purge'));
        $tester->execute($input);

        return $tester;
    }

    private function persistGeneration(int $daysAgo): void
    {
        $generation = new Generation();
        $generation->setMode('create');
        $generation->setPrompt('grelha de produtos');
        $generation->setThemeId('default');
        $generation->setModel('claude-sonnet-5');
        $generation->setStatus('ok');
        $generation->setAttempts(1);
        $generation->setLatencyMs(1200);

        $this->em->persist($generation);
        $this->em->flush();

        // createdAt is set in the constructor and has no setter, deliberately:
        // a creation time that can be rewritten is not a creation time. Backdate
        // the row itself, which is the only place a fixture legitimately can.
        // The table name has to come from the metadata: Mautic prefixes tables,
        // so the literal is right in production and wrong under test.
        $this->em->getConnection()->executeStatement(
            sprintf(
                'UPDATE %s SET created_at = :when WHERE id = :id',
                $this->em->getClassMetadata(Generation::class)->getTableName()
            ),
            [
                'when' => (new \DateTimeImmutable(sprintf('-%d days', $daysAgo)))->format('Y-m-d H:i:s'),
                'id'   => $generation->getId(),
            ]
        );
    }

    private function countRows(): int
    {
        $this->em->clear();

        return (int) $this->em->createQuery(
            'SELECT COUNT(g.id) FROM '.Generation::class.' g'
        )->getSingleScalarResult();
    }
}
