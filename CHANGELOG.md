# Changelog

All notable changes to the Paymos for WHMCS gateway module are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [1.0.3] - 2026-07-12

- chore: rebuild canonical CMS package

## [1.0.2] - 2026-07-12

- fix(release): align package stamping and webhook fixtures
- chore: rebuild canonical CMS package

## [1.0.1] - 2026-06-22

### Fixed
- Double payment: two distinct payment-complete-class events (paid then paid_over, or a reorg re-pay) carry different event ids and were not deduped, so a second `addInvoicePayment` booked an overpayment credit. An already-paid guard now fetches the invoice up front and ignores any further complete-or-downgrade event once it is Paid.
- Reconciler skipped every paid invoice: the amount was compared with a raw string `===`, but the server trims trailing zeros (`"100.00"` never matched `"100"`). The comparison now goes through `AmountGuard::amountsEqual` (decimal-safe).
- A late downgrade (cancelled/expired/underpaid after paid) no longer rewrites the snapshot or logs a contradicting transaction on a paid invoice.
- An amount mismatch on a reverse-verified paid invoice is held for manual review and acknowledged (200) instead of being thrown into the infinite retry path.

### Changed
- `GatewayLink` reads only `payment_url` + `invoice_id` (dropped phantom `checkout_url`/`url`/bare-`id` fallbacks not in the server contract).
- In-memory invoice store's terminal-status set aligned with the DB store (all five terminal statuses).

### Removed
- README no longer documents three settings the module does not have (Invoice Lifetime, Debug Logging, editable Display Name), the non-existent "force-check a single invoice" action, or USDC on Tron/TON (USDT settles there; USDC does not). The paid_over row no longer claims an overpayment is recorded.

## [1.0.0] - 2026-05-30

### Added
- Initial release.
- USDT and USDC payments across major EVM chains; USDT additionally on Tron and TON.
- WHMCS gateway module pattern: a `paymosgateway_link()` module function plus a `callback/paymosgateway.php` notification handler.
- HMAC-SHA256 webhook signature verification with secret-rotation grace period.
- Reverse verification on every callback (pulls the live invoice before marking paid).
- Already-paid guard so a second complete event or a late downgrade never double-pays or rewrites a paid invoice.
- Reconciler hook (`paymosgateway_reconcile.php`, throttled to ~10 minutes) for missed webhooks, with decimal-safe amount matching.
- WHMCS 8.13+ and 9.0 compatibility, no Composer runtime dependencies.
- Sandbox / Live mode switch in WHMCS admin.
- API credentials and signing secret pre-injected by the dashboard ZIP generator.
