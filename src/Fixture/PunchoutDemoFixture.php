<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerCredential;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialGenerator;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialHasher;

/**
 * Installs a demo PunchOut buyer with a freshly generated secret.
 *
 * The secret is NEVER hard-coded: it is generated at fixture time via
 * CredentialGenerator::generate() and printed to stdout so the operator
 * can copy it into their procurement system's test configuration.
 *
 * Usage: bin/console sylius:fixtures:load --group tmonier_punchout_demo
 */
final class PunchoutDemoFixture extends AbstractFixture
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CredentialGenerator $credentialGenerator,
        private readonly CredentialHasher $credentialHasher,
    ) {
    }

    public function load(array $options): void
    {
        // Remove existing demo buyer if present
        $repo = $this->entityManager->getRepository(Buyer::class);
        $existing = $repo->findOneBy(['name' => 'Demo PunchOut Buyer']);
        if ($existing !== null) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }

        $buyer = new Buyer();
        $buyer->setCode('demo-punchout-buyer');
        $buyer->setName('Demo PunchOut Buyer');
        $buyer->setProtocol('cxml');
        $buyer->setEnabled(true);

        // From identity
        $fromIdentity = new BuyerIdentity();
        $fromIdentity->setRole(BuyerIdentity::ROLE_FROM);
        $fromIdentity->setDomain('AribaNetworkUserId');
        $fromIdentity->setIdentity('demo-buyer@example.com');
        $fromIdentity->setBuyer($buyer);
        $buyer->addIdentity($fromIdentity);

        // Sender identity
        $senderIdentity = new BuyerIdentity();
        $senderIdentity->setRole(BuyerIdentity::ROLE_SENDER);
        $senderIdentity->setDomain('AribaNetworkUserId');
        $senderIdentity->setIdentity('demo-system@example.com');
        $senderIdentity->setBuyer($buyer);
        $buyer->addIdentity($senderIdentity);

        // Generate a secret at runtime — NEVER hard-coded
        $plainSecret = $this->credentialGenerator->generate();
        $credential = new BuyerCredential();
        $credential->setPasswordHash($this->credentialHasher->hash($plainSecret));
        $credential->setBuyer($buyer);
        $buyer->addCredential($credential);

        $this->entityManager->persist($buyer);
        $this->entityManager->flush();

        // Print the secret to stdout so the operator can use it
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════╗\n";
        echo "║  PunchOut Demo Buyer installed                           ║\n";
        echo "╠══════════════════════════════════════════════════════════╣\n";
        echo "║  From domain:     AribaNetworkUserId                     ║\n";
        echo "║  From identity:   demo-buyer@example.com                 ║\n";
        echo "║  Sender domain:   AribaNetworkUserId                     ║\n";
        echo "║  Sender identity: demo-system@example.com                ║\n";
        echo '║  SharedSecret:    ' . str_pad($plainSecret, 41) . " ║\n";
        echo "║                                                          ║\n";
        echo "║  Simulator URL: /simulator/cxml                          ║\n";
        echo "╚══════════════════════════════════════════════════════════╝\n";
        echo "\n";
    }

    public function getName(): string
    {
        return 'tmonier_punchout_demo';
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        // No configuration options needed
    }
}
