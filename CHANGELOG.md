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
- Buyer simulator in TestApplication with cXML round-trip and DTD validation (US-026)
