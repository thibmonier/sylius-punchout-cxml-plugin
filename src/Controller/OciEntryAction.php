<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialVerifier;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\LoggedExchange;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\MessageLog;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\MessageLogger;
use Tmonier\SyliusPunchoutCxmlPlugin\Oci\OciContext;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\InvalidReturnUrlException;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\RateLimitKeys;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\ReturnUrlValidator;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\SessionFactory;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\SessionStarter;

/**
 * GET|POST /punchout/oci/{buyerCode} (US-012): SAP opens the catalog in the shopper's browser with
 * USERNAME, PASSWORD and HOOK_URL. The buyer must use the OCI protocol; the username is compared in
 * constant time and the password verified like a cXML secret (Argon2id, dummy check when the buyer is
 * unknown); failures are limited per buyer code and address and per buyer code. The session then
 * starts at once, without a start token. OCI functions (FUNCTION=DETAIL, VALIDATE…) come with v1.1.
 */
final readonly class OciEntryAction
{
    public const MESSAGE_TYPE = 'OciEntry';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CredentialVerifier $credentialVerifier,
        private ReturnUrlValidator $returnUrlValidator,
        private SessionFactory $sessionFactory,
        private SessionStarter $sessionStarter,
        private MessageLogger $messageLogger,
        private RateLimiterFactory $entryLimiter,
        private RateLimiterFactory $authenticationFailureLimiter,
        private RateLimiterFactory $identityFailureLimiter,
    ) {
    }

    public function __invoke(Request $request, string $buyerCode): Response
    {
        /** @var array<array-key, mixed> $parameters */
        $parameters = $request->request->all() + $request->query->all();
        $exchange = new LoggedExchange();
        $exchange->messageType = self::MESSAGE_TYPE;
        $exchange->identity = 'oci|' . $buyerCode;
        $response = $this->handle($request, $buyerCode, $parameters, $exchange);

        if ($response->getStatusCode() !== Response::HTTP_TOO_MANY_REQUESTS) {
            $this->messageLogger->log(
                MessageLog::DIRECTION_IN,
                self::MESSAGE_TYPE,
                '',
                $response->getStatusCode() === Response::HTTP_SEE_OTHER ? Response::HTTP_OK : $response->getStatusCode(),
                $this->withoutPassword($parameters),
                $exchange->buyer,
                authenticated: $exchange->buyer instanceof Buyer,
                context: ['ip' => $request->getClientIp(), 'identity' => $exchange->identity],
                protocol: MessageLog::PROTOCOL_OCI,
            );
        }

        return $response;
    }

    /** @param array<array-key, mixed> $parameters */
    private function handle(Request $request, string $buyerCode, array $parameters, LoggedExchange $exchange): Response
    {
        $limit = $this->entryLimiter->create(RateLimitKeys::ip($request->getClientIp()))->consume();
        if (!$limit->isAccepted()) {
            return $this->plain('Too Many Requests', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $hookUrl = $this->string($parameters, 'HOOK_URL');
        if ($hookUrl === '' || $this->string($parameters, 'FUNCTION') !== '') {
            return $this->plain('Bad Request', Response::HTTP_BAD_REQUEST);
        }

        $failures = [
            $this->authenticationFailureLimiter->create($exchange->identity . '|' . RateLimitKeys::ip($request->getClientIp())),
            $this->identityFailureLimiter->create((string) $exchange->identity),
        ];
        if ($failures[0]->consume(0)->getRemainingTokens() === 0 || $failures[1]->consume(0)->getRemainingTokens() === 0) {
            return $this->plain('Too Many Requests', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $buyer = $this->entityManager->getRepository(Buyer::class)->findOneBy(['code' => $buyerCode]);
        $username = $buyer instanceof Buyer && $buyer->getProtocol() === 'oci' ? (string) $buyer->getOciUsername() : '';
        $usernameValid = $username !== '' && hash_equals($username, $this->string($parameters, 'USERNAME'));
        // Always one Argon2id verification, whether the buyer and the username are right or not.
        $passwordValid = $this->credentialVerifier->verify($this->string($parameters, 'PASSWORD'), $usernameValid ? $buyer : null);
        if (!$buyer instanceof Buyer || !$usernameValid || !$passwordValid) {
            array_map(static fn (LimiterInterface $limiter): \Symfony\Component\RateLimiter\RateLimit => $limiter->consume(), $failures);

            return $this->plain('Forbidden', Response::HTTP_FORBIDDEN);
        }
        $exchange->buyer = $buyer;

        if (!$buyer->isEnabled()) {
            return $this->plain('Forbidden', Response::HTTP_FORBIDDEN);
        }

        try {
            $this->returnUrlValidator->validate($hookUrl, $buyer);
        } catch (InvalidReturnUrlException) {
            return $this->plain('Return URL not allowed', Response::HTTP_FORBIDDEN);
        }

        $session = $this->sessionFactory->create($buyer, 'create', '', $hookUrl);
        $session->setOciContext(OciContext::fromParameters($parameters));
        $session->setStatus(PunchoutSession::STATUS_ACTIVE);
        $this->entityManager->persist($session);

        return $this->sessionStarter->start($session, $request);
    }

    /** @param array<array-key, mixed> $parameters */
    private function string(array $parameters, string $name): string
    {
        return is_string($parameters[$name] ?? null) ? $parameters[$name] : '';
    }

    /**
     * The parameters as logged: one "name=value" line each, the password replaced by "***".
     *
     * @param array<array-key, mixed> $parameters
     */
    private function withoutPassword(array $parameters): string
    {
        $lines = [];
        foreach ($parameters as $name => $value) {
            $lines[] = $name . '=' . ($name === 'PASSWORD' ? '***' : (is_string($value) ? $value : '[array]'));
        }

        return implode("\n", $lines);
    }

    private function plain(string $message, int $status): Response
    {
        return new Response($message, $status, ['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
