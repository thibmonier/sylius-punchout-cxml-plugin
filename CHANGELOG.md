# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- cXML `OrderRequest`: the approved purchase order becomes a Sylius order (opt-in per buyer with a payment method of purchase orders, one order per buyer and `orderID`, refused when an item is no longer sold or a price changed) (US-030, ADR-0008)

## [1.0.0] - 2026-10-14

First release.

### Added

- cXML PunchOut: `POST /punchout/cxml` (`PunchOutSetupRequest` `create`, `edit`, `inspect`, and `ProfileRequest`), single-use start URL, `PunchOutOrderMessage` sent back by the shopper's browser, cancel without items; every emitted document validated against the cXML 1.2.071 DTD in tests (US-007, US-010, US-013, US-018, US-021, US-022, US-023, US-027)
- SAP OCI 5.0: entry `POST /punchout/oci/{buyerCode}` (username, password as a buyer secret, `HOOK_URL` checked against the return domains), return of the cart as `NEW_ITEM-*` fields with long text and `~` fields, cancel (US-003, US-012, US-020)
- PunchOut buyers in the admin: protocol, channel, linked Sylius customer (mandatory), cXML identities or OCI username, return domains, iframe origins; secrets generated and shown once, imposed secrets, rotation with two active secrets (US-001, US-002, US-004, US-016)
- Shop in a PunchOut session: isolated cart, banner, *Transfer* and *Cancel*, side cart, no Sylius checkout; sessions expire after `session_lifetime` and log the shopper out (US-014, US-015, US-017)
- Net prices (tax-inclusive channels supported), UNSPSC codes per taxon and product, units of measure, buyer defaults, optional tax and shipping in the returned cart (US-005, US-019)
- Journal of the exchanges with an admin grid; masked bodies on demand (`log_bodies`) (US-024)
- Console commands `tmonier:punchout:purge` (retention), `tmonier:punchout:erase` (GDPR erasure, statistics kept), both with `--dry-run` (US-025), and `tmonier:punchout:doctor` (US-028)
- Optional link of a buyer to a company of tmonier/sylius-company-accounts-plugin (US-006)
- Buyer simulator in the test application (US-026)
- Translations in en, fr, de, es, it, nl, pl

### Security

- XML parsed without network access nor entity expansion (XXE); secrets hashed with Argon2id (128 MiB, t=3)
- Setup and OCI entries rate limited per address; authentication failures limited per identity and address, and per identity from all addresses without locking out the right credentials
- Replay protection (`payloadID`, `timestamp` window); return URLs HTTPS only, deny by default, exact hosts or `*.domain`
- The setup endpoint never opens a session; start redirection with `Referrer-Policy: no-referrer` and `Cache-Control: no-store`
- OCI entry by POST only unless `oci_allow_get`; an OCI buyer cannot authenticate on the cXML endpoint
- Journal bodies only for authenticated senders, masked on the parsed document (secrets, Extrinsic outside the allowed list, contacts and addresses, e-mail addresses)

[Unreleased]: https://github.com/thibmonier/sylius-punchout-cxml-plugin/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/thibmonier/sylius-punchout-cxml-plugin/releases/tag/v1.0.0
