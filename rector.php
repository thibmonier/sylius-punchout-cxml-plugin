<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/tests/TestApplication',
        // Doctrine entities: Collections are initialized in constructor but hydrated
        // again by Doctrine ORM after instantiation — readonly is not compatible.
        \Rector\Php81\Rector\Property\ReadOnlyPropertyRector::class => [
            __DIR__ . '/src/Buyer/Buyer.php',
            __DIR__ . '/src/Session/PunchoutSession.php',
        ],
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    );
