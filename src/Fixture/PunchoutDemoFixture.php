<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;

/**
 * Installs a demo cXML buyer linked to a customer of the default fixtures, for the buyer simulator.
 *
 * It has no secret: generate one in the admin (PunchOut buyers > edit > "Regenerate the secret"),
 * it is shown once there. No secret is ever printed or hard-coded.
 */
final class PunchoutDemoFixture extends AbstractFixture
{
    public const CODE = 'demo-punchout-buyer';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @param array{customer_email: string, channel_code: string|null, verify_customer: bool} $options */
    public function load(array $options): void
    {
        $existing = $this->entityManager->getRepository(Buyer::class)->findOneBy(['code' => self::CODE]);
        if ($existing !== null) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }

        $customer = $this->entityManager->getRepository(CustomerInterface::class)->findOneBy(['emailCanonical' => mb_strtolower($options['customer_email'])]);
        $channel = $options['channel_code'] !== null
            ? $this->entityManager->getRepository(ChannelInterface::class)->findOneBy(['code' => $options['channel_code']])
            : $this->entityManager->getRepository(ChannelInterface::class)->findOneBy([], ['id' => 'ASC']);
        if (!$customer instanceof CustomerInterface || !$channel instanceof ChannelInterface) {
            throw new \RuntimeException(sprintf('The PunchOut demo fixture needs the customer "%s" and a channel: load it after the shop fixtures.', $options['customer_email']));
        }

        // The demo channel requires verified accounts while the fixture customers are not verified.
        $user = $customer->getUser();
        if ($options['verify_customer'] && $user instanceof ShopUserInterface && !$user->isVerified()) {
            $user->setVerifiedAt(new \DateTime());
        }

        $buyer = new Buyer();
        $buyer->setCode(self::CODE);
        $buyer->setName('Demo PunchOut Buyer');
        $buyer->setProtocol('cxml');
        $buyer->setEnabled(true);
        $buyer->setChannel($channel);
        $buyer->setCustomer($customer);
        // The buyer simulator runs on the shop itself (local dev server).
        $buyer->setReturnDomains(['localhost']);
        foreach ([BuyerIdentity::ROLE_FROM => 'demo-buyer@example.com', BuyerIdentity::ROLE_SENDER => 'demo-system@example.com'] as $role => $value) {
            $identity = new BuyerIdentity();
            $identity->setRole($role);
            $identity->setDomain('AribaNetworkUserId');
            $identity->setIdentity($value);
            $buyer->addIdentity($identity);
        }

        $this->entityManager->persist($buyer);
        $this->entityManager->flush();
    }

    public function getName(): string
    {
        return 'tmonier_punchout_demo';
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->scalarNode('customer_email')->defaultValue('shop@example.com')->cannotBeEmpty()->end()
                ->scalarNode('channel_code')->defaultNull()->end()
                ->booleanNode('verify_customer')->defaultTrue()->end()
            ->end()
        ;
    }
}
