<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\InvalidReturnUrlException;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\ReturnUrlValidator;

final class ReturnUrlValidatorTest extends TestCase
{
    private ReturnUrlValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ReturnUrlValidator();
    }

    #[Test]
    public function it_accepts_a_valid_https_url(): void
    {
        $this->validator->validate('https://ariba.example.com/return', null);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_rejects_http_url(): void
    {
        $this->expectException(InvalidReturnUrlException::class);
        $this->validator->validate('http://ariba.example.com/return', null);
    }

    #[Test]
    public function it_rejects_ftp_url(): void
    {
        $this->expectException(InvalidReturnUrlException::class);
        $this->validator->validate('ftp://ariba.example.com/return', null);
    }

    #[Test]
    public function it_rejects_empty_url(): void
    {
        $this->expectException(InvalidReturnUrlException::class);
        $this->validator->validate('', null);
    }

    /** @return array<string, list<string>> */
    public static function privateIpProvider(): array
    {
        return [
            'loopback' => ['https://127.0.0.1/return'],
            'RFC1918 10.x' => ['https://10.0.0.1/return'],
            'RFC1918 172.16.x' => ['https://172.16.0.1/return'],
            'RFC1918 192.168.x' => ['https://192.168.1.1/return'],
            'link-local' => ['https://169.254.0.1/return'],
            'localhost' => ['https://localhost/return'],
        ];
    }

    #[Test]
    #[DataProvider('privateIpProvider')]
    public function it_rejects_private_ip_addresses(string $url): void
    {
        $this->expectException(InvalidReturnUrlException::class);
        $this->validator->validate($url, null);
    }

    #[Test]
    public function it_rejects_url_not_in_domain_whitelist(): void
    {
        $buyer = $this->createMock(BuyerInterface::class);
        $buyer->method('getReturnDomains')->willReturn(['ariba.example.com']);

        $this->expectException(InvalidReturnUrlException::class);
        $this->validator->validate('https://evil.com/return', $buyer);
    }

    #[Test]
    public function it_accepts_url_matching_domain_whitelist(): void
    {
        $buyer = $this->createMock(BuyerInterface::class);
        $buyer->method('getReturnDomains')->willReturn(['ariba.example.com']);

        $this->validator->validate('https://ariba.example.com/cgi-bin/return', $buyer);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_accepts_any_domain_when_whitelist_is_empty(): void
    {
        $buyer = $this->createMock(BuyerInterface::class);
        $buyer->method('getReturnDomains')->willReturn([]);

        $this->validator->validate('https://any.example.com/return', $buyer);
        $this->addToAssertionCount(1);
    }
}
