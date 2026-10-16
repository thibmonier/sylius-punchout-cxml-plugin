<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The relations to Sylius resources target interfaces, so an application may override the model classes. */
final class DoctrineMappingTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function mappings(): iterable
    {
        foreach (glob(__DIR__ . '/../../../config/doctrine/*.orm.xml') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('mappings')]
    public function testNoRelationTargetsAConcreteSyliusClass(string $file): void
    {
        $xml = file_get_contents($file);
        self::assertIsString($xml);
        preg_match_all('/target-entity="(Sylius\\\\[^"]+)"/', $xml, $matches);

        $concrete = array_filter($matches[1], static fn (string $target): bool => !str_ends_with($target, 'Interface'));

        self::assertSame([], array_values($concrete));
    }
}
