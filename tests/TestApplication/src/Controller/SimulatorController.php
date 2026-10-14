<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Buyer simulator: plays the procurement system for development and functional tests.
 *
 * THIS CONTROLLER IS NEVER LOADED IN PRODUCTION (TestApplication routes only).
 *
 * GET  /simulator/cxml     form of the PunchOutSetupRequest
 * POST /simulator/cxml     sends it to /punchout/cxml (sub-request, no network) and shows the start URL
 * POST /simulator/receive  receives the PunchOutOrderMessage posted back by the shopper's browser,
 *                          decodes it and validates it against the cXML 1.2.071 DTD
 * GET  /simulator/csrf-token?intent=X  CSRF token generated within a request (test helper)
 */
final readonly class SimulatorController
{
    private const DTD = __DIR__ . '/../../../Resources/dtd/1.2.071/cXML.dtd';

    public function __construct(
        private Environment $twig,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private HttpKernelInterface $httpKernel,
    ) {
    }

    public function send(Request $request): Response
    {
        $result = null;
        $startUrl = null;
        $error = null;

        if ($request->isMethod('POST')) {
            // Server-to-server call played in-process: the endpoint gets its own throw-away session,
            // never the shopper's browser session.
            $setup = Request::create('/punchout/cxml', 'POST', server: ['CONTENT_TYPE' => 'text/xml; charset=UTF-8', 'HTTP_HOST' => $request->getHttpHost(), 'HTTPS' => $request->isSecure() ? 'on' : 'off'], content: $this->setupRequest($request));
            $setup->setSession(new Session(new MockArraySessionStorage()));
            $response = $this->httpKernel->handle($setup, HttpKernelInterface::SUB_REQUEST);
            $result = (string) $response->getContent();
            if (1 === preg_match('#<URL>([^<]+)</URL>#', $result, $matches)) {
                $startUrl = html_entity_decode($matches[1], \ENT_XML1);
            } else {
                $error = 'The supplier refused the setup request (see the response below).';
            }
        }

        return new Response($this->twig->render('simulator/send.html.twig', [
            'result' => $result,
            'startUrl' => $startUrl,
            'error' => $error,
        ]));
    }

    public function receive(Request $request): Response
    {
        $encoded = $request->request->getString('cXML-base64');
        $cxml = $encoded !== '' ? base64_decode($encoded, true) : false;
        $cxml = is_string($cxml) ? $cxml : '';

        return new Response($this->twig->render('simulator/receive.html.twig', [
            'cxml' => $cxml,
            'dtdErrors' => $cxml !== '' ? $this->dtdErrors($cxml) : null,
            'items' => $cxml !== '' ? $this->items($cxml) : [],
        ]));
    }

    public function csrfToken(Request $request): JsonResponse
    {
        return new JsonResponse(['token' => $this->csrfTokenManager->getToken($request->query->getString('intent'))->getValue()]);
    }

    /**
     * Validates our own output against the local DTD (the network URI is replaced by the local file).
     *
     * @return list<string>
     */
    private function dtdErrors(string $cxml): array
    {
        $local = (string) preg_replace('#(?:PUBLIC\s+"[^"]+"\s+|SYSTEM\s+)"[^"]*cXML\.dtd"#', 'SYSTEM "file://' . realpath(self::DTD) . '"', $cxml, 1);
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new \DOMDocument();
            $document->loadXML($local, \LIBXML_NONET | \LIBXML_DTDLOAD | \LIBXML_DTDVALID);

            return array_values(array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors()));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return list<array{part: string, quantity: string, price: string}> */
    private function items(string $cxml): array
    {
        $document = new \DOMDocument();
        if (!@$document->loadXML($cxml, \LIBXML_NONET)) {
            return [];
        }
        $xpath = new \DOMXPath($document);
        $items = [];
        foreach ($xpath->query('//ItemIn') ?: [] as $itemIn) {
            if (!$itemIn instanceof \DOMElement) {
                continue;
            }
            $items[] = [
                'part' => (string) $xpath->evaluate('string(ItemID/SupplierPartID)', $itemIn),
                'quantity' => $itemIn->getAttribute('quantity'),
                'price' => (string) $xpath->evaluate('string(ItemDetail/UnitPrice/Money)', $itemIn),
            ];
        }

        return $items;
    }

    private function setupRequest(Request $request): string
    {
        $field = static fn (string $name, string $default = ''): string => htmlspecialchars($request->request->getString($name, $default), \ENT_XML1);
        $returnUrl = htmlspecialchars($request->getSchemeAndHttpHost() . '/simulator/receive', \ENT_XML1);
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339);
        $payloadId = bin2hex(random_bytes(8)) . '@simulator.local';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.071/cXML.dtd">
            <cXML payloadID="{$payloadId}" timestamp="{$timestamp}">
                <Header>
                    <From><Credential domain="{$field('from_domain', 'AribaNetworkUserId')}"><Identity>{$field('from_identity')}</Identity></Credential></From>
                    <To><Credential domain="{$field('supplier_domain', 'NetworkId')}"><Identity>{$field('supplier_identity')}</Identity></Credential></To>
                    <Sender>
                        <Credential domain="{$field('sender_domain', 'AribaNetworkUserId')}"><Identity>{$field('sender_identity')}</Identity><SharedSecret>{$field('shared_secret')}</SharedSecret></Credential>
                        <UserAgent>PunchOut simulator</UserAgent>
                    </Sender>
                </Header>
                <Request deploymentMode="test">
                    <PunchOutSetupRequest operation="{$field('operation', 'create')}">
                        <BuyerCookie>SIM-{$payloadId}</BuyerCookie>
                        <BrowserFormPost><URL>{$returnUrl}</URL></BrowserFormPost>
                    </PunchOutSetupRequest>
                </Request>
            </cXML>
            XML;
    }
}
