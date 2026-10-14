<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Buyer simulator for development and functional testing.
 *
 * THIS CONTROLLER IS NEVER LOADED IN PRODUCTION.
 * It is only registered in tests/TestApplication/config/routes/simulator.yaml
 * which is only imported in the TestApplication.
 *
 * GET  /simulator/cxml  — display the "send setup request" form
 * POST /simulator/cxml  — build + POST the cXML setup request to /punchout/cxml
 * POST /simulator/receive — receive the PunchOutOrderMessage back from the browser redirect
 * GET  /simulator/csrf-token?intent=X — generate a CSRF token within a request (test helper only)
 */
final class SimulatorController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function send(Request $request): Response
    {
        $result = null;
        $startUrl = null;
        $error = null;

        if ($request->isMethod('POST')) {
            $result = $this->postSetupRequest($request, $startUrl, $error);
        }

        return new Response(
            $this->twig->render('simulator/send.html.twig', [
                'result' => $result,
                'startUrl' => $startUrl,
                'error' => $error,
            ]),
        );
    }

    public function receive(Request $request): Response
    {
        $cxml = $request->request->get('cXML-urlencoded', '');

        return new Response(
            $this->twig->render('simulator/receive.html.twig', [
                'cxml' => $cxml,
            ]),
        );
    }

    /**
     * GET /simulator/csrf-token?intent=tmonier_punchout_transfer
     *
     * Generates (or retrieves) a CSRF token for the given intent within a real
     * HTTP request context — where the Symfony session IS active — so tests can
     * obtain a valid token without calling the CSRF manager outside a request.
     *
     * Returns JSON: { "token": "<value>" }
     *
     * THIS ENDPOINT IS ONLY AVAILABLE IN THE TEST APPLICATION.
     * It is never registered in the production routes.
     */
    public function csrfToken(Request $request): JsonResponse
    {
        $intent = $request->query->getString('intent');

        return new JsonResponse([
            'token' => $this->csrfTokenManager->getToken($intent)->getValue(),
        ]);
    }

    private function postSetupRequest(Request $request, ?string &$startUrl, ?string &$error): ?string
    {
        $fromDomain = $request->request->getString('from_domain', 'AribaNetworkUserId');
        $fromIdentity = $request->request->getString('from_identity', '');
        $senderDomain = $request->request->getString('sender_domain', 'AribaNetworkUserId');
        $senderIdentity = $request->request->getString('sender_identity', '');
        $sharedSecret = $request->request->getString('shared_secret', '');
        $supplierDomain = $request->request->getString('supplier_domain', 'NetworkId');
        $supplierIdentity = $request->request->getString('supplier_identity', '');
        $operation = $request->request->getString('operation', 'create');

        $returnUrl = $request->getSchemeAndHttpHost() . '/simulator/receive';
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339);
        $payloadId = bin2hex(random_bytes(8)) . '.' . time() . '@simulator.local';

        $xml = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.071/cXML.dtd">
            <cXML payloadID="{$payloadId}" timestamp="{$timestamp}">
                <Header>
                    <From>
                        <Credential domain="{$fromDomain}">
                            <Identity>{$fromIdentity}</Identity>
                        </Credential>
                    </From>
                    <To>
                        <Credential domain="{$supplierDomain}">
                            <Identity>{$supplierIdentity}</Identity>
                        </Credential>
                    </To>
                    <Sender>
                        <Credential domain="{$senderDomain}">
                            <Identity>{$senderIdentity}</Identity>
                            <SharedSecret>{$sharedSecret}</SharedSecret>
                        </Credential>
                        <UserAgent>Simulator/1.0</UserAgent>
                    </Sender>
                </Header>
                <Request deploymentMode="test">
                    <PunchOutSetupRequest operation="{$operation}">
                        <BuyerCookie>SIM-{$payloadId}</BuyerCookie>
                        <Extrinsic name="UserEmail">test@simulator.local</Extrinsic>
                        <BrowserFormPost>
                            <URL>{$returnUrl}</URL>
                        </BrowserFormPost>
                    </PunchOutSetupRequest>
                </Request>
            </cXML>
            XML;

        $endpointUrl = $request->getSchemeAndHttpHost() . '/punchout/cxml';

        $ch = curl_init($endpointUrl);
        curl_setopt_array($ch, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $xml,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=UTF-8'],
            \CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            $error = "HTTP {$httpCode}: " . ($response ?: 'no response');

            return null;
        }

        // Extract start URL from the response
        $doc = new \DOMDocument();
        $doc->loadXML($response, \LIBXML_NONET);
        $xpath = new \DOMXPath($doc);
        $urlNodes = $xpath->query('//StartPage/URL');
        if ($urlNodes !== false && $urlNodes->length > 0) {
            $startUrl = $urlNodes->item(0)?->textContent;
        }

        return $response;
    }
}
