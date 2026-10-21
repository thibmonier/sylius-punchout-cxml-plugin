<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialVerifier;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\LoggedExchange;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\MessageLog;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\MessageLogger;
use Tmonier\SyliusPunchoutCxmlPlugin\Oci\OciContext;
use Tmonier\SyliusPunchoutCxmlPlugin\Oci\OciFormWriter;
use Tmonier\SyliusPunchoutCxmlPlugin\Oci\OciFunctions;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\AuthenticationThrottle;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\InvalidReturnUrlException;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\RateLimitKeys;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\ReturnUrlValidator;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\SessionFactory;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\SessionStarter;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\ReturnFormResponder;

/**
 * GET|POST /punchout/oci/{buyerCode} (US-012): SAP opens the catalog in the shopper's browser with
 * USERNAME, PASSWORD and HOOK_URL. The buyer must use the OCI protocol; the username is compared in
 * constant time and the password verified like a cXML secret (Argon2id, dummy check when the buyer is
 * unknown); failures are limited per buyer code and address and per buyer code. The session then
 * starts at once, without a start token. POST only unless `oci_allow_get` is enabled. FUNCTION=VALIDATE
 * and DETAIL are handled (US-031, US-032), other functions refused (400).
 */
final readonly class OciEntryAction
{
    public const MESSAGE_TYPE = 'OciEntry';

    /** Browsing (no FUNCTION) and the OCI functions handled; SOURCING, BACKGROUND_SEARCH… are refused. */
    private const FUNCTIONS = ['', 'VALIDATE', 'DETAIL'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CredentialVerifier $credentialVerifier,
        private ReturnUrlValidator $returnUrlValidator,
        private SessionFactory $sessionFactory,
        private SessionStarter $sessionStarter,
        private MessageLogger $messageLogger,
        private RateLimiterFactoryInterface $entryLimiter,
        private AuthenticationThrottle $throttle,
        private OciFunctions $ociFunctions,
        private OciFormWriter $ociFormWriter,
        private ReturnFormResponder $returnFormResponder,
        private bool $allowGet = false,
    ) {
    }

    public function __invoke(Request $request, string $buyerCode): Response
    {
        // A GET puts the password in every access log on the way and in the browser history (opt-in).
        if (!$this->allowGet && !$request->isMethod('POST')) {
            return new Response('Method Not Allowed', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST', 'Cache-Control' => 'no-store']);
        }

        /** @var array<array-key, mixed> $parameters */
        $parameters = $request->request->all() + $request->query->all();
        $exchange = new LoggedExchange();
        $exchange->messageType = self::MESSAGE_TYPE;
        $exchange->identity = 'oci|' . $buyerCode;
        $response = $this->handle($request, $buyerCode, $parameters, $exchange);

        // Rate-limited requests are not stored (the throttle writes a warning to the application log).
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
        $function = strtoupper($this->string($parameters, 'FUNCTION'));
        if ($hookUrl === '' || !in_array($function, self::FUNCTIONS, true)) {
            return $this->plain('Bad Request', Response::HTTP_BAD_REQUEST);
        }

        $identity = (string) $exchange->identity;
        if ($this->throttle->isBlocked($identity, $request->getClientIp())) {
            return $this->plain('Too Many Requests', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $buyer = $this->entityManager->getRepository(Buyer::class)->findOneBy(['code' => $buyerCode]);
        $username = $buyer instanceof Buyer && $buyer->getProtocol() === 'oci' ? (string) $buyer->getOciUsername() : '';
        $usernameValid = $username !== '' && hash_equals($username, $this->string($parameters, 'USERNAME'));
        // Always one Argon2id verification, whether the buyer and the username are right or not.
        $passwordValid = $this->credentialVerifier->verify($this->string($parameters, 'PASSWORD'), $usernameValid ? $buyer : null);
        if (!$buyer instanceof Buyer || !$usernameValid || !$passwordValid) {
            return $this->throttle->fail($identity, $request->getClientIp())
                ? $this->plain('Too Many Requests', Response::HTTP_TOO_MANY_REQUESTS)
                : $this->plain('Forbidden', Response::HTTP_FORBIDDEN);
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

        if ($function === 'VALIDATE') {
            return $this->validate($buyer, $parameters, $hookUrl);
        }

        $session = $this->sessionFactory->create($buyer, 'create', '', $hookUrl);
        $session->setOciContext(OciContext::fromParameters($parameters));
        $session->setStatus(PunchoutSession::STATUS_ACTIVE);
        $this->entityManager->persist($session);

        // DETAIL (US-032): the product page, in a regular PunchOut session.
        $target = $function === 'DETAIL' ? $this->ociFunctions->detailUrl($buyer, $this->string($parameters, 'PRODUCTID')) : null;

        return $this->sessionStarter->start($session, $request, $target);
    }

    /**
     * VALIDATE (US-031): the item at the current price goes straight back to SAP, no session.
     *
     * @param array<array-key, mixed> $parameters
     */
    private function validate(Buyer $buyer, array $parameters, string $hookUrl): Response
    {
        $context = OciContext::fromParameters($parameters);
        $quantity = $this->string($parameters, 'QUANTITY');
        $lines = $this->ociFunctions->validate($buyer, $this->string($parameters, 'PRODUCTID'), ctype_digit($quantity) ? (int) $quantity : 1);
        $fields = $this->ociFormWriter->fields($lines, $context);
        $this->messageLogger->log(MessageLog::DIRECTION_OUT, 'OciValidate', '', 200, OciFormWriter::asText($fields), $buyer, protocol: MessageLog::PROTOCOL_OCI);

        return $this->returnFormResponder->respond($hookUrl, 'oci_transfer', $this->ociFormWriter->formParameters($fields, $context));
    }

    /** @param array<array-key, mixed> $parameters */
    private function string(array $parameters, string $name): string
    {
        return is_string($parameters[$name] ?? null) ? $parameters[$name] : '';
    }

    /**
     * The parameters as logged: one "name=value" line each, any password-like parameter replaced by
     * "***", the query of HOOK_URL (SAP context) left out.
     *
     * @param array<array-key, mixed> $parameters
     */
    private function withoutPassword(array $parameters): string
    {
        $lines = [];
        foreach ($parameters as $name => $value) {
            $value = is_string($value) ? $value : '[array]';
            if (1 === preg_match('/pass|pwd|secret|token/i', (string) $name)) {
                $value = '***';
            } elseif ($name === 'HOOK_URL') {
                $value = strtok($value, '?#') ?: '';
            }
            $lines[] = $name . '=' . $value;
        }

        return implode("\n", $lines);
    }

    private function plain(string $message, int $status): Response
    {
        return new Response($message, $status, ['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
