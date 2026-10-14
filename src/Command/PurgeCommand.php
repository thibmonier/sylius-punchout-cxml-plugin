<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tmonier\SyliusPunchoutCxmlPlugin\Gdpr\RetentionPurger;

/** US-025: run daily (cron) to delete the journal entries and sessions older than the retention. */
#[AsCommand(name: 'tmonier:punchout:purge', description: 'Deletes the PunchOut journal entries and sessions older than the retention')]
final class PurgeCommand extends Command
{
    public function __construct(
        private readonly RetentionPurger $purger,
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be deleted, delete nothing')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention in days (default: retention_days)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = $input->getOption('days');
        $days = is_string($days) && ctype_digit($days) ? (int) $days : $this->retentionDays;
        if ($days < 1) {
            $io->error('The retention is at least one day.');

            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');
        $counts = $this->purger->purge(new \DateTimeImmutable(sprintf('-%d days', $days)), $dryRun);

        $io->success(sprintf(
            '%s %d journal entries and %d sessions older than %d days.',
            $dryRun ? 'Would delete' : 'Deleted',
            $counts['logs'],
            $counts['sessions'],
            $days,
        ));

        return Command::SUCCESS;
    }
}
