<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerResolver;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialVerifier;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\CxmlStatus;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\Exception\CxmlParseException;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\Exception\UnsupportedOperationException;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\Exception\XmlLoadException;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\ProfileResponseWriter;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SafeXmlLoader;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SetupRequestReader;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SetupResponseWriter;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\LoggedExchange;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\MessageLog;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\MessageLogger;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\AuthenticationThrottle;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\InvalidReturnUrlException;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\RateLimitKeys;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\ReplayGuard;
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
        private ProfileResponseWriter $profileWriter,
        private BuyerResolver $buyerResolver,
        private CredentialVerifier $credentialVerifier,
        private ReturnUrlValidator $returnUrlValidator,
        private SessionFactory $sessionFactory,
        private StartTokenIssuer $startTokenIssuer,
        private UrlGeneratorInterface $urlGenerator,
        private ReplayGuard $replayGuard,
        private RateLimiterFactory $setupLimiter,
        private AuthenticationThrottle $throttle,
        private MessageLogger $messageLogger,
        private int $maxRequestBytes = 262144,
        private int $startTokenTtl = 300,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $exchange = new LoggedExchange();
        $response = $this->handle($request, $exchange);

        // Rate-limited requests are not stored (a burst must not fill the journal).
        if ($response->getStatusCode() !== Response::HTTP_TOO_MANY_REQUESTS) {
            $status = 1 === preg_match('/<Status code="(\d+)"/', (string) $response->getContent(), $matches) ? (int) $matches[1] : $response->getStatusCode();
            $this->messageLogger->log(
                MessageLog::DIRECTION_IN,
                $exchange->messageType,
                $exchange->payloadId,
                $status,
                $request->getContent(),
                $exchange->buyer,
                authenticated: $exchange->buyer instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface,
                context: ['ip' => $request->getClientIp(), 'identity' => $exchange->identity],
            );
        }

        return $response;
    }

    private function handle(Request $request, LoggedExchange $exchange): Response
    {
        // Never use the session on this endpoint
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage(),
        ));

        // Rate limit per address before any work (each authentication costs an Argon2id hash)
        $limit = $this->setupLimiter->create(RateLimitKeys::ip($request->getClientIp()))->consume();
        if (!$limit->isAccepted()) {
            return new Response('Too Many Requests', Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                'Cache-Control' => 'no-store',
            ]);
        }

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

        // What is asked: ProfileRequest or PunchOutSetupRequest (OrderRequest comes with v1.1)
        try {
            $requestName = $this->requestReader->requestName($doc);
            $header = $this->requestReader->readHeader($doc);
        } catch (CxmlParseException) {
            return $this->errorResponse(CxmlStatus::BAD_REQUEST, 'Bad Request');
        }
        $exchange->messageType = $requestName;
        $exchange->payloadId = $header->payloadId;
        $exchange->identity = mb_strtolower($header->fromDomain) . '|' . $header->fromIdentity;
        if (!in_array($requestName, ['PunchOutSetupRequest', 'ProfileRequest'], true)) {
            return $this->errorResponse(CxmlStatus::OPERATION_FAILED, 'Not Implemented');
        }

        // Failures of one identity are limited per address and per identity (AuthenticationThrottle).
        if ($this->throttle->isBlocked($exchange->identity, $request->getClientIp())) {
            return $this->tooManyRequests();
        }

        // Resolve buyer by identities + verify secret (constant-time even when buyer is unknown or the
        // secret empty). An OCI buyer never authenticates here, even with identities.
        $buyer = $this->buyerResolver->resolveByIdentities($header->fromDomain, $header->fromIdentity, $header->senderDomain, $header->senderIdentity);
        $buyer = $buyer instanceof Buyer && $buyer->getProtocol() === 'cxml' ? $buyer : null;
        $secretValid = $this->credentialVerifier->verify($header->sharedSecret !== '' ? $header->sharedSecret : '_empty_secret_dummy_', $header->sharedSecret !== '' ? $buyer : null);

        // Unknown buyer or wrong secret → 401 (same response to prevent identity enumeration)
        if (!$buyer instanceof Buyer || !$secretValid) {
            return $this->throttle->fail($exchange->identity, $request->getClientIp())
                ? $this->tooManyRequests()
                : $this->errorResponse(CxmlStatus::UNAUTHORIZED, 'Unauthorized');
        }

        $exchange->buyer = $buyer;

        // Disabled buyer → 403 (identity is known but access is forbidden)
        if (!$buyer->isEnabled()) {
            return $this->errorResponse(CxmlStatus::FORBIDDEN, 'Forbidden');
        }

        // Anti-replay: timestamp within the window, payloadID never accepted for this buyer
        if (!$this->replayGuard->accept($buyer, $header->payloadId, $header->timestamp)) {
            return $this->errorResponse(CxmlStatus::BAD_REQUEST, 'Bad Request');
        }

        if ($requestName === 'ProfileRequest') {
            return $this->xmlResponse($this->profileWriter->write(
                $this->urlGenerator->generate('tmonier_punchout_cxml_endpoint', [], UrlGeneratorInterface::ABSOLUTE_URL),
                $request->getHost(),
            ));
        }

        try {
            $setupData = $this->requestReader->read($doc);
        } catch (UnsupportedOperationException) {
            return $this->errorResponse(CxmlStatus::OPERATION_FAILED, 'Not Implemented');
        } catch (CxmlParseException) {
            return $this->errorResponse(CxmlStatus::BAD_REQUEST, 'Bad Request');
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
        $xml = $this->responseWriter->write($startUrl, $hostname);

        return $this->xmlResponse($xml, Response::HTTP_OK);
    }

    private function tooManyRequests(): Response
    {
        return new Response('Too Many Requests', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '60', 'Cache-Control' => 'no-store']);
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
