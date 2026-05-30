# Changelog

All notable changes to the Paymos for WHMCS gateway module are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [1.0.0] - 2026-05-30

### Added
- Initial release.
- USDT and USDC payments across all major EVM chains plus Tron and TON.
- WHMCS gateway module pattern with `paymosgateway_link()` / `_callback()` hooks.
- HMAC-SHA256 webhook signature verification with secret-rotation grace period.
- Reverse verification on every callback (pulls the live invoice before marking paid).
- 10-minute reconciler hook (`paymosgateway_reconcile.php`) for missed webhooks.
- WHMCS 8.13+ and 9.0 compatibility, no Composer runtime dependencies.
- Sandbox / Live mode switch in WHMCS admin.
- API credentials and signing secret pre-injected by the dashboard ZIP generator.
