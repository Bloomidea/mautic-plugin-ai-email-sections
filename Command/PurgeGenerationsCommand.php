<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Command;

use MauticPlugin\AiEmailSectionsBundle\Entity\GenerationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Enforces retention on the telemetry table.
 *
 * Every generation stores the prompt, the source section and the MJML that came
 * back. That is what makes the table worth having, and also what makes it grow
 * without bound. Run it from cron, daily.
 */
#[AsCommand(
    name: 'mautic:ai-email-sections:purge',
    description: 'Deletes AI Email Sections generation records older than the retention window.',
)]
class PurgeGenerationsCommand extends Command
{
    public const DEFAULT_RETENTION_DAYS = 180;

    public function __construct(private readonly GenerationRepository $repository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_REQUIRED,
                'Retention window in days. Records older than this are deleted.',
                (string) self::DEFAULT_RETENTION_DAYS
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted, and delete nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');

        // A window of zero would delete the whole table, which is not a
        // retention policy and is more likely a typo or an unset variable.
        if ($days < 1) {
            $io->error('Retention must be at least one day.');

            return Command::INVALID;
        }

        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $days));

        if ($input->getOption('dry-run')) {
            $io->info(sprintf(
                '%d records are older than %s and would be deleted.',
                $this->repository->countOlderThan($cutoff),
                $cutoff->format('Y-m-d H:i')
            ));

            return Command::SUCCESS;
        }

        $deleted = $this->repository->deleteOlderThan($cutoff);

        $io->success(sprintf('Deleted %d records older than %s.', $deleted, $cutoff->format('Y-m-d H:i')));

        return Command::SUCCESS;
    }
}
