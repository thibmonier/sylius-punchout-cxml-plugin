# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- PunchOut buyer resource with admin grid, form, and 7-language translations (US-001)
- cXML endpoint `POST /punchout/cxml` with `SafeXmlLoader` (XXE protection), setup request reader, setup response writer (US-007)
- Single-use start token (SHA-256, 5 min TTL, atomic consumption), session start action, shopper authenticator (US-013)
- Transfer action with `OrderMessageWriter`, `NetPriceCalculator` (net price, tax-inclusive channel support), DTD-validated output (US-018)
- Shop cart page in a PunchOut session: banner, *Transfer* and *Cancel*; cancelling sends a `PunchOutOrderMessage` without items (US-018, US-021)
- No Sylius checkout during a PunchOut session (checkout and order routes lead back to the cart); a placed order is never served as the PunchOut cart nor deleted (US-015)
- Setup endpoint rate limited per address (HTTP 429) and protected against replay (`payloadID`, `timestamp` window); malformed requests answer cXML 400, unsupported operations 450 (US-008, US-009)
- Return domains mandatory, deny by default, `*.domain` form; iframe origins per buyer with CSP `frame-ancestors` during PunchOut sessions (US-004, US-016)
- Secret rotation (two active secrets), imposed secret, several From / Sender identities (US-002)
- Authentication failures limited per buyer identity whatever the address; strict ISO 8601 setup timestamps; expired payloadIDs purged; return hosts matched exactly (`*.domain` for subdomains); the shopper is logged out of the buyer account when the PunchOut session ends or expires; imposed secrets of 16 to 256 non-trivial characters
- Side cart and cart page without Sylius checkout in a PunchOut session (US-014); open sessions expire after `session_lifetime` (US-017)
- `ProfileRequest`; `edit` (cart rebuilt from the ItemOut) and `inspect` (read-only page) (US-010, US-022, US-023)
- UNSPSC codes per taxon and product, units of measure, buyer defaults; optional tax and shipping in the returned cart (US-005, US-019)
- Journal of the exchanges with masked bodies and an admin grid (US-024)

### Fixed

- Journal: body kept only for authenticated buyers and within `max_request_bytes`; masking done on the parsed document (secrets, Extrinsic outside the allowed list, contacts and addresses, e-mail addresses)
- Requisitions: at most `max_items_out` items, rebuilt in one query and one cart processing; inspect names only items sold on the channel
- Authentication failures limited per identity and address, and per identity from all addresses

- The setup response carried `inReplyTo` on the cXML root, which the cXML 1.2.071 DTD does not allow
- `Extrinsic` elements are read from `PunchOutSetupRequest` and only the allowed ones are kept

### Added (continued)

- Buyer simulator in TestApplication with cXML round-trip and DTD validation (US-026)
