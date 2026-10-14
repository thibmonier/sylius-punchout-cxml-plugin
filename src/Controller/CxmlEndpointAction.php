<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerResolver;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialVerifier;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\CxmlStatus;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\Exception\CxmlParseException;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\Exception\XmlLoadException;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SafeXmlLoader;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SetupRequestReader;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SetupResponseWriter;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\InvalidReturnUrlException;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\ReturnUrlValidator;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\SessionFactory;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\StartTokenIssuer;

/**
 * POST /punchout/cxml
 *
 * Processes cXML PunchOutSetupRequest messages.
 *
 * CRITICAL: This action MUST NEVER touch the Symfony session.
 * No Set-Cookie header must be emitted.
 */
final readonly class CxmlEndpointAction
{
    public function __construct(
        private SafeXmlLoader $xmlLoader,
        private SetupRequestReader $requestReader,
        private SetupResponseWriter $responseWriter,
        private BuyerResolver $buyerResolver,
        private CredentialVerifier $credentialVerifier,
        private ReturnUrlValidator $returnUrlValidator,
        private SessionFactory $sessionFactory,
        private StartTokenIssuer $startTokenIssuer,
        private UrlGeneratorInterface $urlGenerator,
        private int $maxRequestBytes = 262144,
        private int $startTokenTtl = 300,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // Never use the session on this endpoint
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage(),
        ));

        $body = $request->getContent();

        // Size check
        if (\strlen($body) > $this->maxRequestBytes) {
            return $this->errorResponse(CxmlStatus::NOT_ACCEPTABLE, 'Request too large');
        }

        // Content-type check (allow text/xml, application/xml, application/cxml+xml)
        $contentType = $request->headers->get('Content-Type') ?? '';
        if (!str_contains($contentType, 'xml')) {
            return $this->errorResponse(CxmlStatus::NOT_ACCEPTABLE, 'Content-Type must be XML');
        }

        // Parse XML
        try {
            $doc = $this->xmlLoader->load($body);
        } catch (XmlLoadException) {
            return $this->errorResponse(CxmlStatus::NOT_ACCEPTABLE, 'XML parse error');
        }

        // Read the setup request
        try {
            $setupData = $this->requestReader->read($doc);
        } catch (CxmlParseException) {
            return $this->errorResponse(CxmlStatus::OPERATION_FAILED, 'Invalid cXML structure');
        }

        // Missing or empty SharedSecret → immediate 401 with dummy verify (timing-safe)
        if ($setupData->sharedSecret === '') {
            $this->credentialVerifier->verify('_empty_secret_dummy_', null);

            return $this->errorResponse(CxmlStatus::UNAUTHORIZED, 'Unauthorized');
        }

        // Resolve buyer by identities + verify secret (constant-time even when buyer is unknown)
        $buyer = $this->buyerResolver->resolveByIdentities(
            $setupData->fromDomain,
            $setupData->fromIdentity,
            $setupData->senderDomain,
            $setupData->senderIdentity,
        );

        $secretValid = $this->credentialVerifier->verify($setupData->sharedSecret, $buyer);

        // Unknown buyer or wrong secret → 401 (same response to prevent identity enumeration)
        if (!$buyer instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface || !$secretValid) {
            return $this->errorResponse(CxmlStatus::UNAUTHORIZED, 'Unauthorized');
        }

        // Disabled buyer → 403 (identity is known but access is forbidden)
        if (!$buyer->isEnabled()) {
            return $this->errorResponse(CxmlStatus::FORBIDDEN, 'Forbidden');
        }

        // Validate return URL (HTTPS mandatory; domain whitelist if configured)
        try {
            $this->returnUrlValidator->validate($setupData->browserFormPostUrl, $buyer);
        } catch (InvalidReturnUrlException) {
            return $this->errorResponse(CxmlStatus::FORBIDDEN, 'Return URL not allowed');
        }

        // Create session and issue start token (R4: persist buyer From identities)
        $session = $this->sessionFactory->create(
            $buyer,
            $setupData->operation,
            $setupData->buyerCookie,
            $setupData->browserFormPostUrl,
            $setupData->extrinsics,
            $setupData->itemsOut,
            $setupData->fromDomain,
            $setupData->fromIdentity,
        );

        $token = $this->startTokenIssuer->issue($session, $this->startTokenTtl);

        $startUrl = $this->urlGenerator->generate(
            'tmonier_punchout_start',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $hostname = $request->getHost();
        $xml = $this->responseWriter->write(
            $startUrl,
            $setupData->payloadId,
            $buyer->getChannel()?->getHostname() ?? $hostname,
            $buyer->getChannel()?->getCode() ?? 'supplier',
            $hostname,
        );

        return $this->xmlResponse($xml, Response::HTTP_OK);
    }

    private function errorResponse(int $code, string $text): Response
    {
        $xml = $this->responseWriter->writeError($code, $text);

        return $this->xmlResponse($xml, 200);
    }

    private function xmlResponse(string $xml, int $httpStatus = 200): Response
    {
        $response = new Response($xml, $httpStatus);
        $response->headers->set('Content-Type', 'text/xml; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-store');
        // Explicitly ensure no session cookie is set
        $response->headers->remove('Set-Cookie');

        return $response;
    }
}
