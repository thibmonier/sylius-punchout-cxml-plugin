# Sylius PunchOut cXML/OCI Plugin

> Français : [README.fr.md](README.fr.md) <!-- vitrine:lang -->

[![Build](https://github.com/thibmonier/sylius-punchout-cxml-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-punchout-cxml-plugin/actions)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

PunchOut for Sylius 2: B2B buyers open your shop from their procurement system (SAP Ariba, Coupa,
Jaggaer, Oracle…), shop at their own prices and send the cart back to their purchase requisition.

Version 1.1: cXML PunchOut `create`, `edit` and `inspect`, `ProfileRequest`, purchase orders
(`OrderRequest`) turned into Sylius orders, SAP OCI 5.0 entry, return, `VALIDATE` and `DETAIL`, cart
transfer and cancel, UNSPSC codes and units, optional tax and shipping, exchange log — with replay
protection, rate limiting and iframe support — buyer admin, buyer simulator, retention purge, GDPR
erasure and diagnostic command.
Specification and roadmap: [`project-management/`](project-management/).

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

## Purchase orders (cXML OrderRequest)

Once the requisition is approved, the procurement system can post the purchase order
(`OrderRequest`) to the same endpoint. It is accepted only for buyers with a **payment method of
purchase orders** (e.g. an offline "on account" method; optional shipping method, else the first one
available for the delivery address). The order is created as a regular Sylius order (number, stock,
e-mails), with the `ShipTo` / `BillTo` addresses and the purchase order number in its notes:

- one order per buyer and `orderID`: a repost — even of the very same document after a timeout —
  answers 200 with the same order; the same `orderID` with another content is refused (400);
- an item no longer sold or out of stock, a quantity outside 1 to 9999, a country the channel does
  not serve, or a net unit price (or currency) different from the purchase order refuses the whole
  purchase order (cXML 400, lines named): the buyer starts a PunchOut `edit` again;
- `update` / `delete` purchase orders and `deploymentMode="test"` documents create no order (450);
- at most `rate_limits.order_requests` new purchase orders per buyer (60 per hour by default).

The order belongs to the buyer's customer, as an authorized customer (not a guest order), so
plugins that restrict on-account payment to known customers accept it.

**Event.** Once the order is committed, `Tmonier\SyliusPunchoutCxmlPlugin\Order\PunchoutOrderCreated`
is dispatched once, carrying `purchaseOrderId` (the `orderID` of the buyer's purchase order), the
Sylius `order` and the `buyer`. A repost or a refused purchase order dispatches nothing. Listen to it
instead of reading the order notes. Listeners run synchronously in the request and must not throw:
an exception is logged and the cXML answer stays 200, since the order is already committed.

**PunchOut carts cannot be completed.** A guard on the `complete` transition of `sylius_order_checkout`
(Symfony Workflow and winzou) refuses to complete a cart opened by a PunchOut session, which closes
`PATCH /api/v2/shop/orders/{token}/complete`. Orders built from an `OrderRequest` and shop orders are
not affected. Limit: with `keep_transferred_carts`, the retention purge deletes the session and keeps
the cart, which is then no longer recognised as a PunchOut cart.

**Doctrine.** The relations to the Sylius order, customer and channel target `OrderInterface`,
`CustomerInterface` and `ChannelInterface`. Sylius resolves them to your model classes
(`resolve_target_entities` is registered by Sylius for its resources): an application that overrides
those models needs no entry of its own. Check with `bin/console doctrine:schema:validate`.

The shared secret of such a buyer can now place orders on account: keep it as safe as a payment
credential. See [ADR-0008](docs/adr/0008-order-request.md).

## SAP OCI

A buyer with the **OCI** protocol enters with `POST https://<shop>/punchout/oci/<buyer code>` and the
parameters `USERNAME`, `PASSWORD` and `HOOK_URL` (set the HTTP method of the catalog call to POST in
SAP). A `GET` entry is refused (405) unless `oci_allow_get: true`: it would put the password in the
access logs of every proxy on the way and in the browser history. The username is set on the buyer, the password is the
buyer secret (same Argon2id hash, rotation and imposed value as a cXML secret); failures are limited
like cXML authentications. An OCI buyer never authenticates on the cXML endpoint. `HOOK_URL` must match the buyer's return domains. The shopper is logged in
at once, no start URL.

*Transfer* posts the OCI 5.0 fields to `HOOK_URL`: `NEW_ITEM-DESCRIPTION` (40 characters),
`QUANTITY`, `UNIT`, `PRICE` (net), `CURRENCY`, `PRICEUNIT`, `VENDORMAT`, `EXT_PRODUCT_ID`, the UNSPSC
category when the item has one and the full description in `NEW_ITEM-LONGTEXT_n:132[]`; the `~`
fields received at the entry (`~OkCode`, `~TARGET`, `~CALLER`) are sent back. The form targets
`returntarget`, else `~TARGET`, else `_top`, in the charset `http_content_charset` (UTF-8 by default).
*Cancel* sends no item.

OCI functions (same credentials and `HOOK_URL`): `FUNCTION=VALIDATE` with `PRODUCTID` (the
`EXT_PRODUCT_ID` sent by the transfer) and `QUANTITY` sends the item straight back at the buyer's
current price, without opening a session (no item when it is no longer sold); `FUNCTION=DETAIL` with
`PRODUCTID` opens its product page in a PunchOut session. Other functions (`SOURCING`,
`BACKGROUND_SEARCH`…) are refused (HTTP 400).

## Requisitions, classification and log

- **edit** rebuilds the cart from the items of the requisition (`SupplierPartAuxiliaryID` written by
  the transfer, else `SupplierPartID`); items no longer sold are left out and reported. **inspect**
  shows the requisition read only; going back sends a message without items.
- Each line carries a **UNSPSC** `Classification` and a `UnitOfMeasure`: set them on the product
  (code and unit) or the taxon (code, inherited by its products and sub-taxons) in the admin; the
  buyer defines the defaults. Decorate `tmonier_punchout.classification.item_classifier` to take them
  from elsewhere.
- The buyer chooses whether the **tax** and the **shipping** of the cart are sent.
- **PunchOut log** (admin): every exchange with its cXML status and the SHA-256 of its body; with
  `log_bodies: true`, the body itself, shared secrets removed and e-mail addresses masked.

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
# config/packages/tmonier_punchout.yaml — admin grids, twig hooks and default supplier identity
imports:
    - { resource: "@TmonierSyliusPunchoutCxmlPlugin/config/config.yaml" }
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
        domain: '%env(PUNCHOUT_SUPPLIER_DOMAIN)%'      # defaults to NetworkId
        identity: '%env(PUNCHOUT_SUPPLIER_IDENTITY)%'  # defaults to "supplier": set your Ariba Network ID or equivalent
    start_token_ttl: 300             # seconds
    session_lifetime: 28800          # seconds an open PunchOut session lasts
    replay_window: 900               # accepted clock skew of the setup timestamp, in seconds
    max_request_bytes: 262144        # 256 KiB
    max_items_out: 500               # items of a requisition (edit / inspect)
    log_bodies: false                # keep the masked bodies in the PunchOut log
    retention_days: 90               # log entries and sessions kept (tmonier:punchout:purge)
    rate_limits:
        setup: { limit: 30, interval: '1 minute' }                                 # per client address
        authentication_failures: { limit: 10, interval: '1 minute' }               # per identity and address
        authentication_failures_per_identity: { limit: 50, interval: '1 minute' }  # per identity, all addresses: further failures get 429, right credentials still pass
        oci_entry: { limit: 30, interval: '1 minute' }                             # OCI entries per client address
        order_requests: { limit: 60, interval: '1 hour' }                          # new purchase orders per buyer
    keep_transferred_carts: false
    oci_allow_get: false             # accept the OCI entry by GET (not recommended)
    allowed_extrinsics: [UserEmail, UniqueName, CostCenter]
```

## Setting up a buyer

In the admin, **PunchOut buyers > Create**: code, name, channel, the Sylius customer the sessions log
in as (mandatory), for an OCI buyer its username, for a cXML buyer its `From` and `Sender` identities (one `domain identity` per line, e.g.
`NetworkId AN01000000001`), the allowed return hosts (`s1.ariba.com` for exactly this host,
`*.ariba.com` for its subdomains; never a public suffix such as `*.github.io`) and, if the procurement system shows the shop in
an iframe, its origins (`https://s1.ariba.com`).

The shared secret is generated on creation and **shown once**. *Generate a new secret* adds one next
to the current one (rotation without downtime: at most two are active), then revoke the old one; a
secret imposed by the procurement system can be entered instead (16 characters minimum). Give the
procurement system your setup URL (`https://<shop>/punchout/cxml`), your supplier identity and the
secret.

## Company accounts

With [tmonier/sylius-company-accounts-plugin](https://github.com/thibmonier/sylius-company-accounts-plugin)
installed, a buyer can be attached to a company: its linked customer must then be an active member
of that company (the doctor command reports it when this is no longer true). Without that plugin the
company field does not appear.

## Console commands

```bash
bin/console tmonier:punchout:doctor                 # configuration and buyers check (exit 1 if an enabled buyer cannot be used)
bin/console tmonier:punchout:purge [--dry-run]      # run daily: deletes the log entries and sessions older than retention_days
bin/console tmonier:punchout:erase <email> [--dry-run]
```

`erase` removes the personal data of a person from the PunchOut tables: the Extrinsic values of the
sessions of the buyers logged in as this Sylius customer or naming the address, and the bodies of
their log entries (and of any entry showing the address in its masked form, e.g. `c***@bigcorp.example`).
Rows, dates, types and statuses stay for the statistics. The Sylius customer itself is handled by the
shop. Carts kept with `keep_transferred_carts` are regular Sylius carts: `sylius:remove-expired-carts`
removes them.

## Behind a proxy or a load balancer

The setup rate limit is per client address: declare your proxies so that Symfony sees the real
address, without trusting more than them (a forged `X-Forwarded-For` would otherwise bypass it):

```yaml
# config/packages/framework.yaml
framework:
    trusted_proxies: '10.0.0.0/8'   # your proxies / load balancer only
    trusted_headers: ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-host']
```

Also set `framework.trusted_hosts` to your shop host names: the URLs returned to the procurement
system are built from the request host.

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

<!-- vitrine:demo-b2b:start -->
## B2B demo

This plugin is used in the [Sylius B2B demo](https://github.com/thibmonier/sylius-b2b-demo): a Sylius 2.3 shop with fictitious sample data where the B2B plugins work together. The source code, setup and fixtures of the demo are in that repository.

The demo is an example, not a production setup.
<!-- vitrine:demo-b2b:end -->

## License

MIT — see [LICENSE](LICENSE).
