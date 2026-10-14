<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;

/** US-028: the diagnostic reports a session cookie unfit for iframes and incomplete buyers. */
final class DoctorCommandTest extends WebTestCase
{
    use DatabaseTransactionTrait;

    public function testItReportsTheLaxSessionCookieAndAnIncompleteBuyer(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $complete] = $catalog->createBuyerWithCustomer();
        ['buyer' => $incomplete] = $catalog->createBuyerWithCustomer(fromIdentity: 'nodomain-' . bin2hex(random_bytes(4)) . '@example.com', senderIdentity: 'nodomain-system-' . bin2hex(random_bytes(4)) . '@example.com');
        $incomplete->setReturnDomains([]);
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->flush();

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('tmonier:punchout:doctor'));
        $tester->execute([]);
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertStringContainsString('SameSite=Lax', $output);
        self::assertStringContainsString('cookie_samesite: none', $output);
        self::assertStringContainsString('✘ ' . $incomplete->getCode() . ' (cxml): no allowed return domain', $output);
        self::assertStringContainsString('✔ ' . $complete->getCode() . ' (cxml)', $output);
        self::assertSame(1, $tester->getStatusCode(), 'An enabled buyer that cannot be used fails the diagnostic');
    }
}
