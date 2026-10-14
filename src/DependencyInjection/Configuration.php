<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\DependencyInjection;

use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Sylius\Resource\Factory\Factory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\MessageLog;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\MessageLogInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSessionInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tmonier_sylius_punchout_cxml');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('supplier_identity')
                    ->info('Identity of this supplier in outgoing cXML messages (From element).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('domain')->defaultValue('NetworkId')->cannotBeEmpty()->end()
                        ->scalarNode('identity')->defaultValue('supplier')->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->integerNode('start_token_ttl')
                    ->info('Lifetime of the PunchOut start URL, in seconds.')
                    ->defaultValue(300)
                    ->min(30)
                ->end()
                ->integerNode('session_lifetime')
                    ->info('Seconds after which an open PunchOut session expires (the shopper starts again from the procurement system).')
                    ->defaultValue(28800)
                    ->min(300)
                ->end()
                ->integerNode('replay_window')
                    ->info('Accepted timestamp drift for replay detection, in seconds.')
                    ->defaultValue(900)
                    ->min(0)
                ->end()
                ->integerNode('max_request_bytes')
                    ->info('Maximum accepted cXML request body size, in bytes.')
                    ->defaultValue(262144)
                    ->min(1024)
                ->end()
                ->integerNode('retention_days')
                    ->info('Number of days before logs and closed sessions are purged.')
                    ->defaultValue(90)
                    ->min(1)
                ->end()
                ->booleanNode('log_bodies')
                    ->info('Store (masked) request/response bodies in MessageLog. Keep false in production.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('keep_transferred_carts')
                    ->info('Keep the PunchOut cart after transfer instead of deleting it during the next purge.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('rate_limits')
                    ->info('Per-IP rate limits for the PunchOut endpoints (symfony/rate-limiter).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('setup')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('limit')->defaultValue(30)->min(1)->end()
                                ->scalarNode('interval')->defaultValue('1 minute')->cannotBeEmpty()->end()
                            ->end()
                        ->end()
                        ->arrayNode('oci_entry')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('limit')->defaultValue(30)->min(1)->end()
                                ->scalarNode('interval')->defaultValue('1 minute')->cannotBeEmpty()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('allowed_extrinsics')
                    ->info('Extrinsic names that are stored in the PunchOut session; all others are discarded.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['UserEmail', 'UniqueName', 'CostCenter'])
                ->end()
                ->arrayNode('insecure_return_url_hosts')
                    ->info('Development only: hosts whose return URL may use plain HTTP and a local address (e.g. the buyer simulator on localhost). Keep empty in production.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('driver')->defaultValue(SyliusResourceBundle::DRIVER_DOCTRINE_ORM)->end()
            ->end()
        ;

        $this->addResourcesSection($rootNode);

        return $treeBuilder;
    }

    private function addResourcesSection(ArrayNodeDefinition $rootNode): void
    {
        $resources = [
            'buyer' => [Buyer::class, BuyerInterface::class],
            'punchout_session' => [PunchoutSession::class, PunchoutSessionInterface::class],
            'message_log' => [MessageLog::class, MessageLogInterface::class],
        ];

        $children = $rootNode
            ->children()
                ->arrayNode('resources')
                    ->addDefaultsIfNotSet()
                    ->children()
        ;

        foreach ($resources as $name => [$model, $interface]) {
            $children
                ->arrayNode($name)
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('classes')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('model')->defaultValue($model)->cannotBeEmpty()->end()
                                ->scalarNode('interface')->defaultValue($interface)->cannotBeEmpty()->end()
                                ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                ->scalarNode('factory')->defaultValue(Factory::class)->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ;
        }
    }
}
