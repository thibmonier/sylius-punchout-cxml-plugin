# CLAUDE.md

Sylius 2 plugin: B2B PunchOut cXML/OCI connector. Turns a Sylius shop into a PunchOut supplier
for procurement tools (Ariba, SAP, Coupa…). Full spec and decisions: `SPEC.md` → `project-management/`.
Public docs: `README.md`.

## Stack

PHP 8.2-8.4, Sylius 2.2/2.3, Symfony 6.4/7.4/8, ext-dom, ext-libxml (no external cXML library — ADR-002).

## Commands

PHP needs more than 128 MB to compile the test application container: prefix console and
PHPStan calls with `php -d memory_limit=2G` when running locally.

```bash
docker compose up -d mysql mailhog          # ports come from compose.override.yml (gitignored)
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite non-unit     # functional + integration, need the test DB + Sylius fixtures
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/ecs check
vendor/bin/rector process --dry-run
vendor/bin/console lint:container
```

Local env overrides (gitignored): `compose.override.yml` (MySQL on 3334, MailHog on 8053/1053),
`tests/TestApplication/.env.local` (DATABASE_URL + MAILER_DSN) and `.env.test.local` (DATABASE_URL).
Databases: `tmonier_sylius_punchout_cxml_dev` / `_test`, both loaded with the default fixture suite
plus the `tmonier_punchout_demo` demo fixture (a demo buyer whose secret is generated at install time
and printed in the command output).

Dev server: `symfony server:start -d --port=8101 --no-tls`
- Admin `sylius` / `sylius`
- Shop `shop@example.com` / `sylius`
- Simulator `/simulator/cxml` (test application only)

## DTD

cXML 1.2.071 DTD: `tests/Resources/dtd/1.2.071/cXML.dtd` (from cxml.org, test use only).
Every document emitted by the plugin is validated against this DTD in tests.

## Architecture

- `src/Cxml/` — SafeXmlLoader (XXE protection, LIBXML_NONET only), request readers, response writers (DOM, no external lib)
- `src/Buyer/` — Buyer entity, identities, credentials (Argon2id hasher), resolver
- `src/Security/` — ReturnUrlValidator (HTTPS + domain whitelist), rate limiter
- `src/Session/` — PunchoutSession entity, start-token issuer/consumer (SHA-256, atomic), shopper authenticator
- `src/Cart/` — PunchoutCartContext (priority 0), PunchoutCartFactory
- `src/Transfer/` — TransferLine DTO, NetPriceCalculator (net price, tax-inclusive channel), OrderMessageWriter
- `src/Controller/` — CxmlEndpointAction (no Set-Cookie), StartAction, TransferAction, CancelAction
- `src/Log/` — MessageLog entity, masker
- `src/Migrations/` — Schema API (MySQL + MariaDB + PostgreSQL)
- `tests/TestApplication/` — Simulator controller + templates (never loaded in production)

## Rules

- No core entity change: plugin tables only (prefix `tmonier_punchout_`).
- SafeXmlLoader: LIBXML_NONET only. Never LIBXML_NOENT / DTDLOAD / DTDVALID / PARSEHUGE.
- Endpoint /punchout/cxml: never touch the Symfony session (no Set-Cookie), Cache-Control: no-store.
- cXML timestamps: ISO 8601 with offset (e.g. +02:00), never "Z".
- Return URLs: HTTPS mandatory; reject private IPs.
- No hard-coded secrets or real credentials anywhere in the codebase (GitGuardian safe).
- Labels in `tmonier_punchout` domain; every key in en, fr, de, es, it, nl, pl.
- Functional tests run in a transaction rolled back after each test.
