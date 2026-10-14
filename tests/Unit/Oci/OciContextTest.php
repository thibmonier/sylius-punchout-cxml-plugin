<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\Oci;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusPunchoutCxmlPlugin\Oci\OciContext;

final class OciContextTest extends TestCase
{
    public function testItKeepsTheReturnParametersAndTheTildeFields(): void
    {
        self::assertSame(
            ['returntarget' => '_parent', 'http_content_charset' => 'ISO-8859-1', '~OkCode' => 'ADDI', '~TARGET' => '_top'],
            OciContext::fromParameters(['USERNAME' => 'u', 'PASSWORD' => 'p', 'returntarget' => '_parent', 'http_content_charset' => 'ISO-8859-1', '~OkCode' => 'ADDI', '~TARGET' => '_top']),
        );
    }

    public function testValuesThatWouldLeaveTheirAttributeAreDropped(): void
    {
        self::assertSame([], OciContext::fromParameters([
            'returntarget' => '_top" onload="x',
            'http_content_charset' => 'utf-8"><script>',
            '~TARGET' => 'a b',
            '~bad name' => 'x',
            '~Long' => str_repeat('x', 256),
            '~Array' => ['x'],
        ]));
    }

    public function testTheNumberOfTildeFieldsIsCapped(): void
    {
        $parameters = [];
        for ($i = 0; $i < 50; ++$i) {
            $parameters['~F' . $i] = 'x';
        }

        self::assertLessThanOrEqual(22, count(OciContext::fromParameters($parameters)));
    }
}
