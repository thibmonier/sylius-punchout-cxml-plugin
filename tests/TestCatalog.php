<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerCredential;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialGenerator;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialHasher;

/**
 * Creates the minimal test data needed by functional and integration tests.
 *
 * Secrets are generated at runtime. NEVER use hard-coded credentials.
 */
final readonly class TestCatalog
{
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * @return array{buyer: Buyer, secret: string}
     */
    public function createBuyer(
        string $fromDomain = 'AribaNetworkUserId',
        string $fromIdentity = 'test-buyer@example.com',
        string $senderDomain = 'AribaNetworkUserId',
        string $senderIdentity = 'test-system@example.com',
    ): array {
        $em = $this->container->get(EntityManagerInterface::class);
        $hasher = $this->container->get(CredentialHasher::class);
        $generator = $this->container->get(CredentialGenerator::class);

        // Generate a unique secret at runtime
        $plainSecret = $generator->generate();

        $buyer = new Buyer();
        $buyer->setCode('test-buyer-' . bin2hex(random_bytes(8)));
        $buyer->setName('Test Buyer ' . bin2hex(random_bytes(4)));
        $buyer->setProtocol('cxml');
        $buyer->setEnabled(true);

        $from = new BuyerIdentity();
        $from->setRole(BuyerIdentity::ROLE_FROM);
        $from->setDomain($fromDomain);
        $from->setIdentity($fromIdentity);
        $from->setBuyer($buyer);
        $buyer->addIdentity($from);

        $sender = new BuyerIdentity();
        $sender->setRole(BuyerIdentity::ROLE_SENDER);
        $sender->setDomain($senderDomain);
        $sender->setIdentity($senderIdentity);
        $sender->setBuyer($buyer);
        $buyer->addIdentity($sender);

        $credential = new BuyerCredential();
        $credential->setPasswordHash($hasher->hash($plainSecret));
        $credential->setBuyer($buyer);
        $buyer->addCredential($credential);

        $em->persist($buyer);
        $em->flush();

        return ['buyer' => $buyer, 'secret' => $plainSecret];
    }

    private function getChannel(): ?ChannelInterface
    {
        // Returns the default Sylius channel installed by fixtures
        $repo = $this->container->get('sylius.repository.channel');
        return $repo->findOneBy([]);
    }
}
