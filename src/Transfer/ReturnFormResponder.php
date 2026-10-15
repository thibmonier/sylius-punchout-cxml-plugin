<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Transfer;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment as TwigEnvironment;

/**
 * The self-submitting page that posts a cart back to the procurement system (cXML transfer, OCI
 * transfer and VALIDATE): no cache, no referrer, a CSP that runs only its own script and posts only to
 * the origin of the return URL.
 */
final readonly class ReturnFormResponder
{
    public function __construct(private TwigEnvironment $twig)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function respond(string $returnUrl, string $template, array $parameters): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $parts = parse_url($returnUrl);
        $origin = sprintf(
            '%s://%s%s',
            is_array($parts) ? ($parts['scheme'] ?? 'https') : 'https',
            is_array($parts) ? ($parts['host'] ?? '') : '',
            is_array($parts) && isset($parts['port']) ? ':' . $parts['port'] : '',
        );

        $response = new Response($this->twig->render('@TmonierSyliusPunchoutCxmlPlugin/punchout/' . $template . '.html.twig', [
            'returnUrl' => $returnUrl,
            'nonce' => $nonce,
        ] + $parameters), Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Security-Policy', sprintf(
            "default-src 'none'; script-src 'nonce-%s'; form-action %s; base-uri 'none'",
            $nonce,
            $origin,
        ));

        return $response;
    }
}
