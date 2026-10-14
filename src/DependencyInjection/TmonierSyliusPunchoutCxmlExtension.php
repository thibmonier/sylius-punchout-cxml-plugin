<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class TmonierSyliusPunchoutCxmlExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{supplier_identity: array{domain: string, identity: string}, start_token_ttl: int, replay_window: int, max_request_bytes: int, retention_days: int, log_bodies: bool, keep_transferred_carts: bool, rate_limits: array<string, mixed>, allowed_extrinsics: list<string>, driver: string, resources: array<string, mixed>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $this->registerResources('tmonier_punchout', $config['driver'], $config['resources'], $container);

        foreach (['start_token_ttl', 'replay_window', 'max_request_bytes', 'retention_days', 'log_bodies', 'keep_transferred_carts', 'allowed_extrinsics'] as $key) {
            $container->setParameter('tmonier_punchout.' . $key, $config[$key]);
        }

        $container->setParameter('tmonier_punchout.supplier_identity.domain', $config['supplier_identity']['domain']);
        $container->setParameter('tmonier_punchout.supplier_identity.identity', $config['supplier_identity']['identity']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
    }

    public function prepend(ContainerBuilder $container): void
    {
        // Grids are processed before this extension is loaded.
        $configs = array_map(
            static fn (array $config): array => isset($config['resources']) ? ['resources' => $config['resources']] : [],
            $container->getExtensionConfig($this->getAlias()),
        );
        /** @var array{resources: array<string, array{classes: array{model: string}}>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);
        foreach ($config['resources'] as $name => $resource) {
            $container->setParameter(sprintf('tmonier_punchout.model.%s.class', $name), $resource['classes']['model']);
        }

        $doctrineDir = dirname(__DIR__, 2) . '/config/doctrine';
        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    // Single mapping entry with the plugin root namespace as prefix.
                    // File names use the "Subnamespace.ClassName.orm.xml" convention so that
                    // SimplifiedXmlDriver resolves them correctly (e.g. Buyer.Buyer.orm.xml →
                    // Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer).
                    // Using 'TmonierSyliusPunchoutCxmlPlugin' as key also opts out of Sylius
                    // CoreBundle's auto_mapping: true, which would otherwise register a spurious
                    // "Entity\" prefix for this bundle.
                    'TmonierSyliusPunchoutCxmlPlugin' => [
                        'type' => 'xml',
                        'dir' => $doctrineDir,
                        'prefix' => 'Tmonier\SyliusPunchoutCxmlPlugin',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);

        // Rate limiters — only if symfony/rate-limiter is available
        if (class_exists(RateLimiterFactory::class)) {
            /** @var array{rate_limits: array{setup: array{limit: int, interval: string}, oci_entry: array{limit: int, interval: string}}} $limits */
            $limits = $this->processConfiguration(new Configuration(), array_map(
                static fn (array $c): array => isset($c['rate_limits']) ? ['rate_limits' => $c['rate_limits']] : [],
                $container->getExtensionConfig($this->getAlias()),
            ));
            $limiter = static fn (int $limit, string $interval): array => [
                'policy' => 'sliding_window',
                'limit' => $limit,
                'interval' => $interval,
                'cache_pool' => 'cache.rate_limiter',
                'lock_factory' => 'lock.factory',
            ];
            $container->prependExtensionConfig('framework', ['lock' => ['enabled' => true, 'resources' => ['default' => ['flock']]]]);
            $container->prependExtensionConfig('framework', ['rate_limiter' => [
                'tmonier_punchout_setup' => $limiter($limits['rate_limits']['setup']['limit'], $limits['rate_limits']['setup']['interval']),
                'tmonier_punchout_oci_entry' => $limiter($limits['rate_limits']['oci_entry']['limit'], $limits['rate_limits']['oci_entry']['interval']),
            ]]);
        }

        $this->prependDoctrineMigrations($container);
    }

    public function getAlias(): string
    {
        return 'tmonier_sylius_punchout_cxml';
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Tmonier\SyliusPunchoutCxmlPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@TmonierSyliusPunchoutCxmlPlugin/src/Migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}
