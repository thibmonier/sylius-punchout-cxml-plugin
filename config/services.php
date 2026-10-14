<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerResolver;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialGenerator;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialHasher;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialVerifier;
use Tmonier\SyliusPunchoutCxmlPlugin\Cart\PunchoutCartContext;
use Tmonier\SyliusPunchoutCxmlPlugin\Cart\PunchoutCartFactory;
use Tmonier\SyliusPunchoutCxmlPlugin\Controller\CancelAction;
use Tmonier\SyliusPunchoutCxmlPlugin\Controller\CxmlEndpointAction;
use Tmonier\SyliusPunchoutCxmlPlugin\Controller\StartAction;
use Tmonier\SyliusPunchoutCxmlPlugin\Controller\TransferAction;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\OrderMessageWriter;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\PayloadIdGenerator;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SafeXmlLoader;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SetupRequestReader;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SetupResponseWriter;
use Tmonier\SyliusPunchoutCxmlPlugin\Fixture\PunchoutDemoFixture;
use Tmonier\SyliusPunchoutCxmlPlugin\Menu\AdminMenuListener;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\ReturnUrlValidator;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\SessionFactory;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\ShopperAuthenticator;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\StartTokenConsumer;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\StartTokenIssuer;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\NetPriceCalculator;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\TransferLineExtractor;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // cXML core
    $services->set(SafeXmlLoader::class)->public();
    $services->set(PayloadIdGenerator::class);
    $services->set(SetupRequestReader::class);
    $services->set(SetupResponseWriter::class);
    $services->set(OrderMessageWriter::class);

    // Named aliases for backwards-compatible service IDs
    $services->alias('tmonier_punchout.cxml.safe_xml_loader', SafeXmlLoader::class)->public();
    $services->alias('tmonier_punchout.cxml.payload_id_generator', PayloadIdGenerator::class);
    $services->alias('tmonier_punchout.cxml.setup_request_reader', SetupRequestReader::class);
    $services->alias('tmonier_punchout.cxml.setup_response_writer', SetupResponseWriter::class);
    $services->alias('tmonier_punchout.cxml.order_message_writer', OrderMessageWriter::class);

    // Buyer
    $services->set(BuyerResolver::class);
    $services->set(CredentialHasher::class)->public();
    $services->set(CredentialVerifier::class);
    $services->set(CredentialGenerator::class)->public();

    $services->alias('tmonier_punchout.buyer.resolver', BuyerResolver::class);
    $services->alias('tmonier_punchout.buyer.credential_hasher', CredentialHasher::class)->public();
    $services->alias('tmonier_punchout.buyer.credential_verifier', CredentialVerifier::class);
    $services->alias('tmonier_punchout.buyer.credential_generator', CredentialGenerator::class)->public();

    // Security
    $services->set(ReturnUrlValidator::class);
    $services->alias('tmonier_punchout.security.return_url_validator', ReturnUrlValidator::class);

    // Session
    $services->set(SessionFactory::class);
    $services->set(CurrentPunchoutSession::class);
    $services->set(StartTokenIssuer::class)->public();
    $services->set(StartTokenConsumer::class);
    $services->set(ShopperAuthenticator::class);

    $services->alias('tmonier_punchout.session.factory', SessionFactory::class);
    $services->alias('tmonier_punchout.session.current', CurrentPunchoutSession::class);
    $services->alias('tmonier_punchout.session.start_token_issuer', StartTokenIssuer::class)->public();
    $services->alias('tmonier_punchout.session.start_token_consumer', StartTokenConsumer::class);
    $services->alias('tmonier_punchout.session.shopper_authenticator', ShopperAuthenticator::class);

    // Cart
    $services->set(PunchoutCartContext::class)
        ->tag('sylius.context.cart', ['priority' => 0]);
    $services->set(PunchoutCartFactory::class);

    // Transfer
    $services->set(NetPriceCalculator::class);
    $services->set(TransferLineExtractor::class);

    $services->alias('tmonier_punchout.transfer.net_price_calculator', NetPriceCalculator::class);
    $services->alias('tmonier_punchout.transfer.transfer_line_extractor', TransferLineExtractor::class);

    // Controllers
    $services->set(CxmlEndpointAction::class)
        ->tag('controller.service_arguments')
        ->public();
    $services->set(StartAction::class)
        ->tag('controller.service_arguments')
        ->public();
    $services->set(TransferAction::class)
        ->tag('controller.service_arguments')
        ->public();
    $services->set(CancelAction::class)
        ->tag('controller.service_arguments')
        ->public();

    $services->alias('tmonier_punchout.controller.cxml_endpoint', CxmlEndpointAction::class)->public();
    $services->alias('tmonier_punchout.controller.start', StartAction::class)->public();
    $services->alias('tmonier_punchout.controller.transfer', TransferAction::class)->public();
    $services->alias('tmonier_punchout.controller.cancel', CancelAction::class)->public();

    // Fixtures
    $services->set(PunchoutDemoFixture::class)
        ->tag('sylius_fixtures.fixture');

    // Admin menu
    $services->set(AdminMenuListener::class)
        ->tag('kernel.event_listener', [
            'event' => 'sylius.menu.admin.main',
            'method' => 'addPunchoutSection',
        ]);
};
