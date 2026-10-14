<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Doctor\BuyerChecker;

/**
 * US-028: checks the configuration and the buyers. Warnings (configuration that breaks some
 * procurement systems) leave the exit code at 0; an enabled buyer that cannot open a session fails it.
 */
#[AsCommand(name: 'tmonier:punchout:doctor', description: 'Checks the PunchOut configuration and buyers')]
final class DoctorCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BuyerChecker $buyerChecker,
        private readonly ParameterBagInterface $parameters,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $buyers = $this->entityManager->getRepository(Buyer::class)->findBy([], ['code' => 'ASC']);

        $io->section('Configuration');
        $warnings = $this->configurationWarnings($buyers);
        foreach ($warnings as $warning) {
            $io->warning($warning);
        }
        if ($warnings === []) {
            $io->writeln('OK');
        }

        $io->section('Buyers');
        $failed = false;
        foreach ($buyers as $buyer) {
            $problems = $this->buyerChecker->problems($buyer);
            if ($problems === []) {
                $io->writeln(sprintf('<info>✔</info> %s (%s)', $buyer->getCode(), $buyer->getProtocol()));

                continue;
            }
            $failed = $failed || $buyer->isEnabled();
            $io->writeln(sprintf('<error>✘</error> %s (%s%s): %s', $buyer->getCode(), $buyer->getProtocol(), $buyer->isEnabled() ? '' : ', disabled', implode('; ', $problems)));
        }
        if ($buyers === []) {
            $io->writeln('No buyer yet: create one in the admin (PunchOut > Buyers).');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<Buyer> $buyers
     *
     * @return list<string>
     */
    private function configurationWarnings(array $buyers): array
    {
        $warnings = [];
        $options = $this->parameters->has('session.storage.options') ? $this->parameters->get('session.storage.options') : [];
        $options = is_array($options) ? $options : [];
        $sameSite = is_string($options['cookie_samesite'] ?? null) ? strtolower($options['cookie_samesite']) : 'lax';
        if ($sameSite !== 'none' || ($options['cookie_secure'] ?? null) !== true) {
            $framed = array_filter($buyers, static fn (Buyer $buyer): bool => $buyer->getIframeOrigins() !== []);
            $warnings[] = sprintf(
                'The session cookie is SameSite=%s%s: a shop shown in an iframe of the procurement system (SAP Ariba, Coupa…) loses its session%s. Set framework.session.cookie_samesite: none and cookie_secure: true (HTTPS), and keep CSRF protection on the forms.',
                ucfirst($sameSite),
                ($options['cookie_secure'] ?? null) === true ? '' : ', not Secure',
                $framed !== [] ? sprintf(' (%d buyer(s) with iframe origins)', count($framed)) : '',
            );
        }
        if ($this->parameters->get('tmonier_punchout.supplier_identity.identity') === 'supplier') {
            $warnings[] = 'supplier_identity.identity is still "supplier": set your identity in the procurement network (e.g. your Ariba Network ID).';
        }
        $insecureHosts = $this->parameters->get('tmonier_punchout.insecure_return_url_hosts');
        if (is_array($insecureHosts) && $insecureHosts !== []) {
            $warnings[] = sprintf('insecure_return_url_hosts allows plain HTTP returns to %s: development only.', implode(', ', array_filter($insecureHosts, is_string(...))));
        }
        if ($this->parameters->get('tmonier_punchout.log_bodies') === true) {
            $warnings[] = 'log_bodies is enabled: masked message bodies are stored in the journal. Keep it off in production unless you are debugging.';
        }

        return $warnings;
    }
}
