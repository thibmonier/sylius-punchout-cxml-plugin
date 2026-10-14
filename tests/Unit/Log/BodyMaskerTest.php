<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SafeXmlLoader;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\BodyMasker;

final class BodyMaskerTest extends TestCase
{
    private BodyMasker $masker;

    protected function setUp(): void
    {
        $this->masker = new BodyMasker(new SafeXmlLoader(), ['UserEmail']);
    }

    #[Test]
    public function it_removes_secrets_whatever_the_markup(): void
    {
        $masked = $this->masker->mask('<cXML><Header><Sender><Credential domain="d"><SharedSecret >s3cret-one</SharedSecret ><Password xml:lang="en">
            s3cret-two</Password><CredentialMac>s3cret-three</CredentialMac></Credential></Sender></Header></cXML>');

        foreach (['s3cret-one', 's3cret-two', 's3cret-three'] as $secret) {
            self::assertStringNotContainsString($secret, $masked);
        }
        self::assertStringContainsString('<SharedSecret>***</SharedSecret>', $masked);
    }

    #[Test]
    public function it_masks_e_mail_addresses_after_decoding_and_drops_personal_blocks(): void
    {
        $masked = $this->masker->mask('<cXML><Request><PunchOutSetupRequest>'
            . '<Extrinsic name="UserEmail">jane&#64;bigcorp.example</Extrinsic>'
            . '<Extrinsic name="UserPhoneNumber">+33 1 02 03 04 05</Extrinsic>'
            . '<Contact><Name xml:lang="en">Jane Doe</Name></Contact>'
            . '<ShipTo><Address><Name xml:lang="en">Jane Doe</Name></Address></ShipTo>'
            . '</PunchOutSetupRequest></Request></cXML>');

        self::assertStringContainsString('j***@bigcorp.example', $masked);
        self::assertStringNotContainsString('jane', $masked);
        self::assertStringNotContainsString('+33', $masked, 'Extrinsics outside the allowed list are dropped');
        self::assertStringNotContainsString('Jane Doe', $masked, 'Contact and ShipTo are dropped');
    }

    #[Test]
    public function it_never_stores_an_unparseable_body(): void
    {
        self::assertSame('[unparseable body]', $this->masker->mask('<cXML><SharedSecret>s3cret'));
    }
}
