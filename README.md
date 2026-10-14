# Sylius PunchOut cXML/OCI Plugin

[![Build Status](https://github.com/tmonier/sylius-punchout-cxml-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/tmonier/sylius-punchout-cxml-plugin/actions)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

B2B PunchOut cXML/OCI connector for Sylius 2. Turns your Sylius shop into a **PunchOut supplier** for procurement systems (Ariba, SAP, Coupa, Jaggaer…).

- Receives `PunchOutSetupRequest` (create / edit / inspect) on `POST /punchout/cxml`
- Authenticates buyers, starts a dedicated PunchOut shop session with the linked Sylius customer
- Returns the cart as a `PunchOutOrderMessage` (`cXML-base64`) to the buyer's return URL
- OCI 5.0 entry and return (sprint 2)
- Admin UI: buyers, credentials, session log

> **DTD notice** — The DTD files in `tests/Resources/dtd/1.2.071/` come from [cxml.org](https://www.cxml.org/)
> and are used only in tests for XML validation. They are subject to the cXML.org licence terms.

## Requirements

- PHP 8.2+
- Sylius 2.2.9+ or 2.3+
- Symfony 6.4 / 7.4 / 8.0
- ext-dom, ext-libxml

## Installation

### 1. Require the package

```bash
composer require tmonier/sylius-punchout-cxml-plugin
```

### 2. Register the bundle

```php
// config/bundles.php
return [
    // …
    Tmonier\SyliusPunchoutCxmlPlugin\TmonierSyliusPunchoutCxmlPlugin::class => ['all' => true],
];
```

### 3. Import routes

```yaml
# config/routes/tmonier_punchout.yaml
tmonier_punchout:
    resource: "@TmonierSyliusPunchoutCxmlPlugin/config/routes.yaml"
```

### 4. Run migrations

```bash
php bin/console doctrine:migrations:migrate
```

### 5. Install assets

```bash
php bin/console assets:install
```

## Configuration

```yaml
# config/packages/tmonier_punchout.yaml
tmonier_sylius_punchout_cxml:
    supplier_identity:
        domain: 'NetworkId'
        identity: '%env(PUNCHOUT_SUPPLIER_ID)%'
    start_token_ttl: 300       # seconds
    replay_window: 900         # seconds
    max_request_bytes: 262144  # 256 KiB
    retention_days: 90
    log_bodies: false
    keep_transferred_carts: false
    rate_limits:
        setup:
            limit: 30
            interval: '1 minute'
    allowed_extrinsics: [UserEmail, UniqueName, CostCenter]
```

## Testing

```bash
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite non-unit   # needs DB + fixtures
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/ecs check
vendor/bin/rector process --dry-run
vendor/bin/console lint:container
```

Local dev server (port 8101):

```bash
symfony server:start -d --port=8101 --no-tls
# Admin: http://localhost:8101/admin  (sylius / sylius)
# Shop:  http://localhost:8101       (shop@example.com / sylius)
# Simulator: http://localhost:8101/simulator/cxml
```

## License

MIT — see [LICENSE](LICENSE).
