# Sylius PunchOut cXML/OCI Plugin

[![Build](https://github.com/thibmonier/sylius-punchout-cxml-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-punchout-cxml-plugin/actions)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

PunchOut for Sylius 2: B2B buyers open your shop from their procurement system (SAP Ariba, Coupa,
Jaggaer, Oracle…), shop at their own prices and send the cart back to their purchase requisition.

> **Status: in development, not released.** Available today (sprints 1 and 2): cXML `create`
> PunchOut — setup with replay protection and rate limiting, session (iframe ready), cart transfer and
> cancel, Sylius checkout replaced by the transfer — buyer admin (secret rotation, several identities,
> mandatory return domains) and buyer simulator. Coming next: `edit` / `inspect`, UNSPSC and units,
> SAP OCI, exchange log, GDPR tools. Specification and roadmap: [`project-management/`](project-management/).

## How it works

1. The procurement system posts a `PunchOutSetupRequest` to `POST /punchout/cxml`. The buyer is
   authenticated by its `From` / `Sender` identities and shared secret (stored as an Argon2id hash)
   and gets a single-use start URL (`StartPage`).
2. The shopper's browser opens the start URL: they are logged in as the **Sylius customer linked to
   the buyer** (their prices and groups apply) in a dedicated cart, isolated from their regular cart.
3. On the cart page, *Transfer* posts a `PunchOutOrderMessage` (`cXML-base64`, `target="_top"`) back
   to the procurement system: net prices (tax included in the price removed), totals without tax and
   shipping. *Cancel* sends the same message without items. The PunchOut session is then closed.
   During the session the Sylius checkout leads back to the cart: orders are placed in the
   procurement system.

The setup endpoint never opens a session (no cookie), is rate limited per address (HTTP 429) and
refuses replayed requests (same `payloadID`, or a `timestamp` outside the replay window). The return
URL must be HTTPS and match one of the buyer's allowed domains (deny by default); it is checked at
setup time and again before the cart is sent. An open PunchOut session expires after
`session_lifetime`.

## Requirements

PHP 8.2+, Sylius 2.2.9+ or 2.3+, Symfony 6.4 / 7.4 / 8, ext-dom, ext-libxml. No third-party cXML
library: documents are read and written with DOM (see [ADR-0002](docs/adr/0002-serialisation-cxml-dom.md)).

## Installation

```bash
composer require tmonier/sylius-punchout-cxml-plugin
```

```php
// config/bundles.php
Tmonier\SyliusPunchoutCxmlPlugin\TmonierSyliusPunchoutCxmlPlugin::class => ['all' => true],
```

```yaml
# config/routes/tmonier_punchout.yaml
tmonier_sylius_punchout_cxml:
    resource: "@TmonierSyliusPunchoutCxmlPlugin/config/routes.yaml"
```

```bash
php bin/console doctrine:migrations:migrate
```

## Configuration

```yaml
# config/packages/tmonier_punchout.yaml
tmonier_sylius_punchout_cxml:
    supplier_identity:               # your identity in the messages sent back (From / Sender)
        domain: 'NetworkId'
        identity: '%env(PUNCHOUT_SUPPLIER_ID)%'
    start_token_ttl: 300             # seconds
    session_lifetime: 28800          # seconds an open PunchOut session lasts
    replay_window: 900               # accepted clock skew of the setup timestamp, in seconds
    max_request_bytes: 262144        # 256 KiB
    rate_limits:
        setup: { limit: 30, interval: '1 minute' }                    # per client address
        authentication_failures: { limit: 10, interval: '1 minute' }  # per buyer identity, any address
    keep_transferred_carts: false
    allowed_extrinsics: [UserEmail, UniqueName, CostCenter]
```

## Setting up a buyer

In the admin, **PunchOut buyers > Create**: code, name, channel, the Sylius customer the sessions log
in as (mandatory), the buyer's `From` and `Sender` identities (one `domain identity` per line, e.g.
`NetworkId AN01000000001`), the allowed return hosts (`s1.ariba.com` for exactly this host,
`*.ariba.com` for its subdomains; never a public suffix such as `*.github.io`) and, if the procurement system shows the shop in
an iframe, its origins (`https://s1.ariba.com`).

The shared secret is generated on creation and **shown once**. *Generate a new secret* adds one next
to the current one (rotation without downtime: at most two are active), then revoke the old one; a
secret imposed by the procurement system can be entered instead (16 characters minimum). Give the
procurement system your setup URL (`https://<shop>/punchout/cxml`), your supplier identity and the
secret.

## Behind a proxy or a load balancer

The setup rate limit is per client address: declare your proxies so that Symfony sees the real
address, without trusting more than them (a forged `X-Forwarded-For` would otherwise bypass it):

```yaml
# config/packages/framework.yaml
framework:
    trusted_proxies: '10.0.0.0/8'   # your proxies / load balancer only
    trusted_headers: ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-host']
```

With several application servers, share the rate limiter storage (for example a Redis cache pool
for `cache.rate_limiter` and a Redis lock store), otherwise each server counts on its own.

## Iframe (SAP Ariba)

During a PunchOut session only, shop pages may be framed by the buyer's iframe origins (CSP
`frame-ancestors`; `X-Frame-Options` stays everywhere else). The browser only sends the session
cookie to an iframe of another site when it is `SameSite=None; Secure`, which the application must
configure (HTTPS required):

```yaml
# config/packages/framework.yaml
framework:
    session:
        cookie_samesite: none
        cookie_secure: true
```

`SameSite=None` applies to the whole session cookie: the browser no longer withholds it on
cross-site requests, so CSRF protection relies on the CSRF tokens only (Sylius and this plugin use
them on every state-changing form). Serving the admin on another host or with another session cookie
keeps it out of this trade-off.

## Development

```bash
docker compose up -d mysql mailhog
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite non-unit   # needs the test database and the Sylius fixtures
vendor/bin/phpstan analyse
vendor/bin/ecs check
vendor/bin/rector process --dry-run
```

### Buyer simulator (test application only)

```bash
symfony server:start -d --port=8101 --no-tls
```

1. Admin (`http://localhost:8101/admin`, `sylius` / `sylius`) > PunchOut buyers > *Demo PunchOut
   Buyer* > *Regenerate the secret*, copy it.
2. Open `http://localhost:8101/simulator/cxml`, paste the secret, send the setup request and follow
   the start link: you shop as `shop@example.com`.
3. On the cart page, click *Transfer*: the simulator shows the `PunchOutOrderMessage` it received and
   its validation against the cXML 1.2.071 DTD.

The simulator receives the cart on `http://localhost`, allowed by the development-only option
`insecure_return_url_hosts` of the test application (keep it empty in production).

The cXML DTD in `tests/Resources/dtd/1.2.071/` comes from [cxml.org](https://www.cxml.org/) and is
only used by the tests, under the cXML.org licence terms.

## License

MIT — see [LICENSE](LICENSE).
