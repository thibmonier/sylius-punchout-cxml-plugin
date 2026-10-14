<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tmonier\SyliusPunchoutCxmlPlugin\Gdpr\PersonalDataEraser;

/** US-025: right to erasure of the PunchOut data of a person (journal bodies, Extrinsic values). */
#[AsCommand(name: 'tmonier:punchout:erase', description: 'Erases the PunchOut personal data of an e-mail address (sessions and journal), keeping the statistics')]
final class EraseCustomerCommand extends Command
{
    public function __construct(
        private readonly PersonalDataEraser $eraser,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail address of the Sylius customer or of a procurement user')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be erased, erase nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $email = $input->getArgument('email');
            $counts = $this->eraser->erase(is_string($email) ? $email : '', $dryRun);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        if (!$dryRun) {
            // Audit trail of the erasure (the address itself is not repeated in the logs).
            $this->logger->notice('PunchOut personal data erased', $counts + ['email_sha256' => hash('sha256', mb_strtolower(trim(is_string($email) ? $email : '')))]);
        }

        $io->success(sprintf(
            '%s the personal data of %d sessions and %d journal entries.',
            $dryRun ? 'Would erase' : 'Erased',
            $counts['sessions'],
            $counts['logs'],
        ));

        return Command::SUCCESS;
    }
}
