<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerResolver;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialGenerator;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialHasher;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialVerifier;
use Tmonier\SyliusPunchoutCxmlPlugin\Controller\CancelAction;
use Tmonier\SyliusPunchoutCxmlPlugin\Controller\CxmlEndpointAction;
use Tmonier\SyliusPunchoutCxmlPlugin\Controller\StartAction;
use Tmonier\SyliusPunchoutCxmlPlugin\Controller\TransferAction;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\OrderMessageWriter;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\PayloadIdGenerator;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SafeXmlLoader;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SetupRequestReader;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SetupResponseWriter;
use Tmonier\SyliusPunchoutCxmlPlugin\Menu\AdminMenuListener;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\ReturnUrlValidator;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\SessionFactory;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\ShopperAuthenticator;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\StartTokenConsumer;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\StartTokenIssuer;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\NetPriceCalculator;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\TransferLineExtractor;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    // cXML core
    $services->set('tmonier_punchout.cxml.safe_xml_loader', SafeXmlLoader::class)
        ->public();
    $services->set('tmonier_punchout.cxml.payload_id_generator', PayloadIdGenerator::class);
    $services->set('tmonier_punchout.cxml.setup_request_reader', SetupRequestReader::class);
    $services->set('tmonier_punchout.cxml.setup_response_writer', SetupResponseWriter::class)
        ->arg('$payloadIdGenerator', service('tmonier_punchout.cxml.payload_id_generator'));
    $services->set('tmonier_punchout.cxml.order_message_writer', OrderMessageWriter::class)
        ->arg('$payloadIdGenerator', service('tmonier_punchout.cxml.payload_id_generator'));

    // Buyer
    $services->set('tmonier_punchout.buyer.resolver', BuyerResolver::class);
    $services->set('tmonier_punchout.buyer.credential_hasher', CredentialHasher::class);
    $services->set('tmonier_punchout.buyer.credential_verifier', CredentialVerifier::class)
        ->arg('$hasher', service('tmonier_punchout.buyer.credential_hasher'));
    $services->set('tmonier_punchout.buyer.credential_generator', CredentialGenerator::class);

    // Security
    $services->set('tmonier_punchout.security.return_url_validator', ReturnUrlValidator::class);

    // Session
    $services->set('tmonier_punchout.session.factory', SessionFactory::class);
    $services->set('tmonier_punchout.session.current', CurrentPunchoutSession::class);
    $services->set('tmonier_punchout.session.start_token_issuer', StartTokenIssuer::class);
    $services->set('tmonier_punchout.session.start_token_consumer', StartTokenConsumer::class);
    $services->set('tmonier_punchout.session.shopper_authenticator', ShopperAuthenticator::class);

    // Transfer
    $services->set('tmonier_punchout.transfer.net_price_calculator', NetPriceCalculator::class);
    $services->set('tmonier_punchout.transfer.transfer_line_extractor', TransferLineExtractor::class)
        ->arg('$netPriceCalculator', service('tmonier_punchout.transfer.net_price_calculator'));

    // Controllers
    $services->set('tmonier_punchout.controller.cxml_endpoint', CxmlEndpointAction::class)
        ->tag('controller.service_arguments')
        ->public();
    $services->set('tmonier_punchout.controller.start', StartAction::class)
        ->tag('controller.service_arguments')
        ->public();
    $services->set('tmonier_punchout.controller.transfer', TransferAction::class)
        ->tag('controller.service_arguments')
        ->public();
    $services->set('tmonier_punchout.controller.cancel', CancelAction::class)
        ->tag('controller.service_arguments')
        ->public();

    // Admin menu
    $services->set('tmonier_punchout.menu.admin_menu_listener', AdminMenuListener::class)
        ->tag('kernel.event_listener', [
            'event' => 'sylius.menu.admin.main',
            'method' => 'addPunchoutSection',
        ]);
};
