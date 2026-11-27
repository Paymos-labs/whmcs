# Changelog

All notable changes to the Paymos for WHMCS gateway module are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [Unreleased]

## [1.8.3] - 2026-11-27

- chore: rebuild canonical CMS package

### Added
- GitHub Actions CI workflow (`.github/workflows/ci.yml`) running `php tests/run.php` across PHP `7.4`, `8.2`, `8.4`, and `8.5` against the pinned `Paymos-labs/php-sdk` `v1.11.0`.

### Changed
- Replaced the module logo with a 400×80 raster PNG (`logo.png`) in `whmcs.json` and removed the obsolete `logo.svg`, because WHMCS 9.1 wraps gateway logos in `data:image/png;base64,` and rendered SVG files broken.
- Updated `README.md` for WHMCS 8.13–9.1 activation (**Configuration → Apps & Integrations → Payments → Paymos → Activate**), the fail-closed `Convert To For Processing` currency guard, and WHMCS 9.1 `InvoiceImmutability` native full refund support.

### Fixed
- **Connect UI injection (`paymosgateway_connect.php`):** `document.querySelector("form")` matched the Blend header IntelliSearch form, leaving `#paymos-connect-button` hidden (`0×0`) and injecting it on the password-confirmation screen and when Paymos was inactive. The hook now targets `#configpaymosgatewayForm` exclusively.
- **Connect CSRF handling on WHMCS 9.1 (`paymosgateway_connect.php`):** `AdminAreaFooterOutput` passes `$vars['csrfToken']` rather than `$vars['token']` on WHMCS 9.1, causing `paymos_connect_action` (`start` and `poll`) to fail `check_token('WHMCS.admin.default')`. The hook now resolves the CSRF token through `$vars['csrfToken']`, `$vars['token']`, and `generate_token('plain')`, with an `input[name="token"]` fallback from the form.
- **Refund panel POST failing with `409 refund_module_inactive` (`paymosgateway_refunds.php`):** The refund hook processed `POST paymos_refund_action` before WHMCS loaded `includes/gatewayfunctions.php`, so `getGatewayVariables()` was unavailable. The hook now loads `includes/gatewayfunctions.php` before checking gateway variables and returns localized 409 error messages.
- **Full refund projection on WHMCS 9.1 `InvoiceImmutability` (`RefundAdapter`):** Calling `localAPI('AddTransaction')` and `localAPI('UpdateInvoice', status => 'Refunded')` failed on WHMCS 9.1 (`Cannot change invoice status to Refunded without appropriate billing records`), leaving the invoice `Paid` with a balance due while marking `projection_state = applied`. Completed `full` refunds now project via WHMCS's native `refundInvoicePayment(..., sendtogateway = false)`, creating the refund transaction and Credit Note (`invoice_billing_adjustment_credit`) and transitioning the invoice to `Refunded`; `inspect()` validates both 2-row (WHMCS 8.13) and 3-row (WHMCS 9.1) ledgers, and legacy `UpdateInvoice` failures move the intent to retryable review instead of recording `applied`.
- **Transaction write guard on real WHMCS (`RefundWriteGuard`):** The guard registered on the non-existent `WHMCS\Billing\Transaction` model instead of WHMCS's real `WHMCS\Billing\Payment\Transaction`, leaving Paymos invoice transactions unguarded at runtime. It now binds to `WHMCS\Billing\Payment\Transaction` (retaining `WHMCS\Billing\Transaction` for legacy test stubs) and allows authorized `RefundInvoicePayment` projections while rejecting unauthorized transaction writes and deletions.
- **Double-payment renewal after `invoice.paid_late` (`GatewayLink`):** After `invoice.paid_late`, the local row kept `status = 'expired'` with `paid_late_noted` set, allowing a client view or cron reminder render to create a second Paymos invoice. `GatewayLink` now blocks replacement whenever `paid_late_noted` or a live `paid_late_at` timestamp is present (including during `closeBeforeReplacing()` without extra HTTP calls), logs `Manual review`, and renders the localized `replacement_blocked` notice.
- **Hidden `Connection` status row (`Config::moduleConfig()`):** Declaring `Connection` with `'Type' => 'System'` caused WHMCS 9.1 to hide the row from the Payment Gateways table. It now uses `'Type' => ''` with a localized `'Description'` (retaining `'Value'` for compatibility) so the connection status is visible.
- **False `Snapshot mismatch` log noise on open invoices (`Reconciler`):** Checking `StatusMapper::invoiceAction(...) !== ACTION_IGNORE` inside `snapshotMatches()` logged a false `Snapshot mismatch` on every cron run for every open `awaiting_client` invoice. `snapshotMatches()` now validates only `project_id`, `external_order_id`, `amount`, and `currency`, and non-actionable invoices are skipped quietly.
- **Store-default client and six-language admin localization (`Translation`, `Config`, `RefundAdapter`, `lang/*.php`):** Clients with an empty profile language fell back to English instead of `$_SESSION['Language']` or WHMCS's default language (`WHMCS\Config\Setting` / `tblconfiguration.Language`), and gateway settings plus the refund panel were hardcoded in English. `Translation` now resolves session, store-default, and admin languages, and all six catalogs (`english`, `russian`, `german`, `spanish`, `turkish`, `chinese`) provide all 33 localized keys.
- **Refund panel UX, native refund error logging, and `Convert To For Processing` guard (`RefundAdapter`, `paymosgateway_refunds.php`, `GatewayLink`, `CurrencyMismatchException`):** Refund panel AJAX failures now render safely via `.text()` in `.paymos-refund-error`; irrelevant or completed refund buttons are hidden (`Refund overpayment` appears only for `paid_over` invoices without a completed overpayment refund) and empty tokens are skipped in JS; `nativeRefund()` logs a localized failure message with `review_reason` on `failed`/`cancelled`/`review` instead of `"refund in progress"`; and `GatewayLink::render()` fails closed with `CurrencyMismatchException` before calling Paymos when WHMCS's `Convert To For Processing` converts an invoice into a different currency, logging `Currency mismatch: disable Convert To For Processing` and displaying `payment_cannot_be_used`.
- **Missing line items on pending create intent recovery (`GatewayLink::recoverPendingIntent()`):** Replaying an unbound `creating` invoice intent now extracts and sends `products[]` line items identically to a clean first-attempt create.

## [1.8.2] - 2026-11-26

- docs(plugins): add 6-language READMEs (EN/zh-CN/ru/es/de/tr) and YouTube walkthrough previews across all 25 Paymos-labs repos
- chore: bundle Paymos PHP SDK v1.13.0

### Added
- GitHub Actions CI workflow (`.github/workflows/ci.yml`) running `php tests/run.php` across PHP `7.4`, `8.2`, `8.4`, and `8.5` against the pinned `Paymos-labs/php-sdk` `v1.11.0`.

### Changed
- Replaced the module logo with a 400×80 raster PNG (`logo.png`) in `whmcs.json` and removed the obsolete `logo.svg`, because WHMCS 9.1 wraps gateway logos in `data:image/png;base64,` and rendered SVG files broken.
- Updated `README.md` for WHMCS 8.13–9.1 activation (**Configuration → Apps & Integrations → Payments → Paymos → Activate**), the fail-closed `Convert To For Processing` currency guard, and WHMCS 9.1 `InvoiceImmutability` native full refund support.

### Fixed
- **Connect UI injection (`paymosgateway_connect.php`):** `document.querySelector("form")` matched the Blend header IntelliSearch form, leaving `#paymos-connect-button` hidden (`0×0`) and injecting it on the password-confirmation screen and when Paymos was inactive. The hook now targets `#configpaymosgatewayForm` exclusively.
- **Connect CSRF handling on WHMCS 9.1 (`paymosgateway_connect.php`):** `AdminAreaFooterOutput` passes `$vars['csrfToken']` rather than `$vars['token']` on WHMCS 9.1, causing `paymos_connect_action` (`start` and `poll`) to fail `check_token('WHMCS.admin.default')`. The hook now resolves the CSRF token through `$vars['csrfToken']`, `$vars['token']`, and `generate_token('plain')`, with an `input[name="token"]` fallback from the form.
- **Refund panel POST failing with `409 refund_module_inactive` (`paymosgateway_refunds.php`):** The refund hook processed `POST paymos_refund_action` before WHMCS loaded `includes/gatewayfunctions.php`, so `getGatewayVariables()` was unavailable. The hook now loads `includes/gatewayfunctions.php` before checking gateway variables and returns localized 409 error messages.
- **Full refund projection on WHMCS 9.1 `InvoiceImmutability` (`RefundAdapter`):** Calling `localAPI('AddTransaction')` and `localAPI('UpdateInvoice', status => 'Refunded')` failed on WHMCS 9.1 (`Cannot change invoice status to Refunded without appropriate billing records`), leaving the invoice `Paid` with a balance due while marking `projection_state = applied`. Completed `full` refunds now project via WHMCS's native `refundInvoicePayment(..., sendtogateway = false)`, creating the refund transaction and Credit Note (`invoice_billing_adjustment_credit`) and transitioning the invoice to `Refunded`; `inspect()` validates both 2-row (WHMCS 8.13) and 3-row (WHMCS 9.1) ledgers, and legacy `UpdateInvoice` failures move the intent to retryable review instead of recording `applied`.
- **Transaction write guard on real WHMCS (`RefundWriteGuard`):** The guard registered on the non-existent `WHMCS\Billing\Transaction` model instead of WHMCS's real `WHMCS\Billing\Payment\Transaction`, leaving Paymos invoice transactions unguarded at runtime. It now binds to `WHMCS\Billing\Payment\Transaction` (retaining `WHMCS\Billing\Transaction` for legacy test stubs) and allows authorized `RefundInvoicePayment` projections while rejecting unauthorized transaction writes and deletions.
- **Double-payment renewal after `invoice.paid_late` (`GatewayLink`):** After `invoice.paid_late`, the local row kept `status = 'expired'` with `paid_late_noted` set, allowing a client view or cron reminder render to create a second Paymos invoice. `GatewayLink` now blocks replacement whenever `paid_late_noted` or a live `paid_late_at` timestamp is present (including during `closeBeforeReplacing()` without extra HTTP calls), logs `Manual review`, and renders the localized `replacement_blocked` notice.
- **Hidden `Connection` status row (`Config::moduleConfig()`):** Declaring `Connection` with `'Type' => 'System'` caused WHMCS 9.1 to hide the row from the Payment Gateways table. It now uses `'Type' => ''` with a localized `'Description'` (retaining `'Value'` for compatibility) so the connection status is visible.
- **False `Snapshot mismatch` log noise on open invoices (`Reconciler`):** Checking `StatusMapper::invoiceAction(...) !== ACTION_IGNORE` inside `snapshotMatches()` logged a false `Snapshot mismatch` on every cron run for every open `awaiting_client` invoice. `snapshotMatches()` now validates only `project_id`, `external_order_id`, `amount`, and `currency`, and non-actionable invoices are skipped quietly.
- **Store-default client and six-language admin localization (`Translation`, `Config`, `RefundAdapter`, `lang/*.php`):** Clients with an empty profile language fell back to English instead of `$_SESSION['Language']` or WHMCS's default language (`WHMCS\Config\Setting` / `tblconfiguration.Language`), and gateway settings plus the refund panel were hardcoded in English. `Translation` now resolves session, store-default, and admin languages, and all six catalogs (`english`, `russian`, `german`, `spanish`, `turkish`, `chinese`) provide all 33 localized keys.
- **Refund panel UX, native refund error logging, and `Convert To For Processing` guard (`RefundAdapter`, `paymosgateway_refunds.php`, `GatewayLink`, `CurrencyMismatchException`):** Refund panel AJAX failures now render safely via `.text()` in `.paymos-refund-error`; irrelevant or completed refund buttons are hidden (`Refund overpayment` appears only for `paid_over` invoices without a completed overpayment refund) and empty tokens are skipped in JS; `nativeRefund()` logs a localized failure message with `review_reason` on `failed`/`cancelled`/`review` instead of `"refund in progress"`; and `GatewayLink::render()` fails closed with `CurrencyMismatchException` before calling Paymos when WHMCS's `Convert To For Processing` converts an invoice into a different currency, logging `Currency mismatch: disable Convert To For Processing` and displaying `payment_cannot_be_used`.
- **Missing line items on pending create intent recovery (`GatewayLink::recoverPendingIntent()`):** Replaying an unbound `creating` invoice intent now extracts and sends `products[]` line items identically to a clean first-attempt create.

## [1.8.1] - 2026-11-25

- chore: rebuild canonical CMS package

### Added
- GitHub Actions CI workflow (`.github/workflows/ci.yml`) running `php tests/run.php` across PHP `7.4`, `8.2`, `8.4`, and `8.5` against the pinned `Paymos-labs/php-sdk` `v1.11.0`.

### Changed
- Replaced the module logo with a 400×80 raster PNG (`logo.png`) in `whmcs.json` and removed the obsolete `logo.svg`, because WHMCS 9.1 wraps gateway logos in `data:image/png;base64,` and rendered SVG files broken.
- Updated `README.md` for WHMCS 8.13–9.1 activation (**Configuration → Apps & Integrations → Payments → Paymos → Activate**), the fail-closed `Convert To For Processing` currency guard, and WHMCS 9.1 `InvoiceImmutability` native full refund support.

### Fixed
- **Connect UI injection (`paymosgateway_connect.php`):** `document.querySelector("form")` matched the Blend header IntelliSearch form, leaving `#paymos-connect-button` hidden (`0×0`) and injecting it on the password-confirmation screen and when Paymos was inactive. The hook now targets `#configpaymosgatewayForm` exclusively.
- **Connect CSRF handling on WHMCS 9.1 (`paymosgateway_connect.php`):** `AdminAreaFooterOutput` passes `$vars['csrfToken']` rather than `$vars['token']` on WHMCS 9.1, causing `paymos_connect_action` (`start` and `poll`) to fail `check_token('WHMCS.admin.default')`. The hook now resolves the CSRF token through `$vars['csrfToken']`, `$vars['token']`, and `generate_token('plain')`, with an `input[name="token"]` fallback from the form.
- **Refund panel POST failing with `409 refund_module_inactive` (`paymosgateway_refunds.php`):** The refund hook processed `POST paymos_refund_action` before WHMCS loaded `includes/gatewayfunctions.php`, so `getGatewayVariables()` was unavailable. The hook now loads `includes/gatewayfunctions.php` before checking gateway variables and returns localized 409 error messages.
- **Full refund projection on WHMCS 9.1 `InvoiceImmutability` (`RefundAdapter`):** Calling `localAPI('AddTransaction')` and `localAPI('UpdateInvoice', status => 'Refunded')` failed on WHMCS 9.1 (`Cannot change invoice status to Refunded without appropriate billing records`), leaving the invoice `Paid` with a balance due while marking `projection_state = applied`. Completed `full` refunds now project via WHMCS's native `refundInvoicePayment(..., sendtogateway = false)`, creating the refund transaction and Credit Note (`invoice_billing_adjustment_credit`) and transitioning the invoice to `Refunded`; `inspect()` validates both 2-row (WHMCS 8.13) and 3-row (WHMCS 9.1) ledgers, and legacy `UpdateInvoice` failures move the intent to retryable review instead of recording `applied`.
- **Transaction write guard on real WHMCS (`RefundWriteGuard`):** The guard registered on the non-existent `WHMCS\Billing\Transaction` model instead of WHMCS's real `WHMCS\Billing\Payment\Transaction`, leaving Paymos invoice transactions unguarded at runtime. It now binds to `WHMCS\Billing\Payment\Transaction` (retaining `WHMCS\Billing\Transaction` for legacy test stubs) and allows authorized `RefundInvoicePayment` projections while rejecting unauthorized transaction writes and deletions.
- **Double-payment renewal after `invoice.paid_late` (`GatewayLink`):** After `invoice.paid_late`, the local row kept `status = 'expired'` with `paid_late_noted` set, allowing a client view or cron reminder render to create a second Paymos invoice. `GatewayLink` now blocks replacement whenever `paid_late_noted` or a live `paid_late_at` timestamp is present (including during `closeBeforeReplacing()` without extra HTTP calls), logs `Manual review`, and renders the localized `replacement_blocked` notice.
- **Hidden `Connection` status row (`Config::moduleConfig()`):** Declaring `Connection` with `'Type' => 'System'` caused WHMCS 9.1 to hide the row from the Payment Gateways table. It now uses `'Type' => ''` with a localized `'Description'` (retaining `'Value'` for compatibility) so the connection status is visible.
- **False `Snapshot mismatch` log noise on open invoices (`Reconciler`):** Checking `StatusMapper::invoiceAction(...) !== ACTION_IGNORE` inside `snapshotMatches()` logged a false `Snapshot mismatch` on every cron run for every open `awaiting_client` invoice. `snapshotMatches()` now validates only `project_id`, `external_order_id`, `amount`, and `currency`, and non-actionable invoices are skipped quietly.
- **Store-default client and six-language admin localization (`Translation`, `Config`, `RefundAdapter`, `lang/*.php`):** Clients with an empty profile language fell back to English instead of `$_SESSION['Language']` or WHMCS's default language (`WHMCS\Config\Setting` / `tblconfiguration.Language`), and gateway settings plus the refund panel were hardcoded in English. `Translation` now resolves session, store-default, and admin languages, and all six catalogs (`english`, `russian`, `german`, `spanish`, `turkish`, `chinese`) provide all 33 localized keys.
- **Refund panel UX, native refund error logging, and `Convert To For Processing` guard (`RefundAdapter`, `paymosgateway_refunds.php`, `GatewayLink`, `CurrencyMismatchException`):** Refund panel AJAX failures now render safely via `.text()` in `.paymos-refund-error`; irrelevant or completed refund buttons are hidden (`Refund overpayment` appears only for `paid_over` invoices without a completed overpayment refund) and empty tokens are skipped in JS; `nativeRefund()` logs a localized failure message with `review_reason` on `failed`/`cancelled`/`review` instead of `"refund in progress"`; and `GatewayLink::render()` fails closed with `CurrencyMismatchException` before calling Paymos when WHMCS's `Convert To For Processing` converts an invoice into a different currency, logging `Currency mismatch: disable Convert To For Processing` and displaying `payment_cannot_be_used`.
- **Missing line items on pending create intent recovery (`GatewayLink::recoverPendingIntent()`):** Replaying an unbound `creating` invoice intent now extracts and sends `products[]` line items identically to a clean first-attempt create.

## [1.8.0] - 2026-11-25

- feat(platform): integrate pending main WIP — drop fiat_exchange_rates/VES, blockchain/worker fix batches 1-7, compact auth email layout, status-worker, plugins, forge and docs
- chore: bundle Paymos PHP SDK v1.13.0
- chore: rebuild canonical CMS package

### Added
- GitHub Actions CI workflow (`.github/workflows/ci.yml`) running `php tests/run.php` across PHP `7.4`, `8.2`, `8.4`, and `8.5` against the pinned `Paymos-labs/php-sdk` `v1.11.0`.

### Changed
- Replaced the module logo with a 400×80 raster PNG (`logo.png`) in `whmcs.json` and removed the obsolete `logo.svg`, because WHMCS 9.1 wraps gateway logos in `data:image/png;base64,` and rendered SVG files broken.
- Updated `README.md` for WHMCS 8.13–9.1 activation (**Configuration → Apps & Integrations → Payments → Paymos → Activate**), the fail-closed `Convert To For Processing` currency guard, and WHMCS 9.1 `InvoiceImmutability` native full refund support.

### Fixed
- **Connect UI injection (`paymosgateway_connect.php`):** `document.querySelector("form")` matched the Blend header IntelliSearch form, leaving `#paymos-connect-button` hidden (`0×0`) and injecting it on the password-confirmation screen and when Paymos was inactive. The hook now targets `#configpaymosgatewayForm` exclusively.
- **Connect CSRF handling on WHMCS 9.1 (`paymosgateway_connect.php`):** `AdminAreaFooterOutput` passes `$vars['csrfToken']` rather than `$vars['token']` on WHMCS 9.1, causing `paymos_connect_action` (`start` and `poll`) to fail `check_token('WHMCS.admin.default')`. The hook now resolves the CSRF token through `$vars['csrfToken']`, `$vars['token']`, and `generate_token('plain')`, with an `input[name="token"]` fallback from the form.
- **Refund panel POST failing with `409 refund_module_inactive` (`paymosgateway_refunds.php`):** The refund hook processed `POST paymos_refund_action` before WHMCS loaded `includes/gatewayfunctions.php`, so `getGatewayVariables()` was unavailable. The hook now loads `includes/gatewayfunctions.php` before checking gateway variables and returns localized 409 error messages.
- **Full refund projection on WHMCS 9.1 `InvoiceImmutability` (`RefundAdapter`):** Calling `localAPI('AddTransaction')` and `localAPI('UpdateInvoice', status => 'Refunded')` failed on WHMCS 9.1 (`Cannot change invoice status to Refunded without appropriate billing records`), leaving the invoice `Paid` with a balance due while marking `projection_state = applied`. Completed `full` refunds now project via WHMCS's native `refundInvoicePayment(..., sendtogateway = false)`, creating the refund transaction and Credit Note (`invoice_billing_adjustment_credit`) and transitioning the invoice to `Refunded`; `inspect()` validates both 2-row (WHMCS 8.13) and 3-row (WHMCS 9.1) ledgers, and legacy `UpdateInvoice` failures move the intent to retryable review instead of recording `applied`.
- **Transaction write guard on real WHMCS (`RefundWriteGuard`):** The guard registered on the non-existent `WHMCS\Billing\Transaction` model instead of WHMCS's real `WHMCS\Billing\Payment\Transaction`, leaving Paymos invoice transactions unguarded at runtime. It now binds to `WHMCS\Billing\Payment\Transaction` (retaining `WHMCS\Billing\Transaction` for legacy test stubs) and allows authorized `RefundInvoicePayment` projections while rejecting unauthorized transaction writes and deletions.
- **Double-payment renewal after `invoice.paid_late` (`GatewayLink`):** After `invoice.paid_late`, the local row kept `status = 'expired'` with `paid_late_noted` set, allowing a client view or cron reminder render to create a second Paymos invoice. `GatewayLink` now blocks replacement whenever `paid_late_noted` or a live `paid_late_at` timestamp is present (including during `closeBeforeReplacing()` without extra HTTP calls), logs `Manual review`, and renders the localized `replacement_blocked` notice.
- **Hidden `Connection` status row (`Config::moduleConfig()`):** Declaring `Connection` with `'Type' => 'System'` caused WHMCS 9.1 to hide the row from the Payment Gateways table. It now uses `'Type' => ''` with a localized `'Description'` (retaining `'Value'` for compatibility) so the connection status is visible.
- **False `Snapshot mismatch` log noise on open invoices (`Reconciler`):** Checking `StatusMapper::invoiceAction(...) !== ACTION_IGNORE` inside `snapshotMatches()` logged a false `Snapshot mismatch` on every cron run for every open `awaiting_client` invoice. `snapshotMatches()` now validates only `project_id`, `external_order_id`, `amount`, and `currency`, and non-actionable invoices are skipped quietly.
- **Store-default client and six-language admin localization (`Translation`, `Config`, `RefundAdapter`, `lang/*.php`):** Clients with an empty profile language fell back to English instead of `$_SESSION['Language']` or WHMCS's default language (`WHMCS\Config\Setting` / `tblconfiguration.Language`), and gateway settings plus the refund panel were hardcoded in English. `Translation` now resolves session, store-default, and admin languages, and all six catalogs (`english`, `russian`, `german`, `spanish`, `turkish`, `chinese`) provide all 33 localized keys.
- **Refund panel UX, native refund error logging, and `Convert To For Processing` guard (`RefundAdapter`, `paymosgateway_refunds.php`, `GatewayLink`, `CurrencyMismatchException`):** Refund panel AJAX failures now render safely via `.text()` in `.paymos-refund-error`; irrelevant or completed refund buttons are hidden (`Refund overpayment` appears only for `paid_over` invoices without a completed overpayment refund) and empty tokens are skipped in JS; `nativeRefund()` logs a localized failure message with `review_reason` on `failed`/`cancelled`/`review` instead of `"refund in progress"`; and `GatewayLink::render()` fails closed with `CurrencyMismatchException` before calling Paymos when WHMCS's `Convert To For Processing` converts an invoice into a different currency, logging `Currency mismatch: disable Convert To For Processing` and displaying `payment_cannot_be_used`.
- **Missing line items on pending create intent recovery (`GatewayLink::recoverPendingIntent()`):** Replaying an unbound `creating` invoice intent now extracts and sends `products[]` line items identically to a clean first-attempt create.

## [1.7.6] - 2026-11-22

- fix(whmcs): initialize $_SESSION in CLI test bootstrap for PHP 7.4 CI
- test(whmcs): guard unset($_SESSION[...]) for PHP 7.4 CLI CI compatibility

### Added
- GitHub Actions CI workflow (`.github/workflows/ci.yml`) running `php tests/run.php` across PHP `7.4`, `8.2`, `8.4`, and `8.5` against the pinned `Paymos-labs/php-sdk` `v1.11.0`.

### Changed
- Replaced the module logo with a 400×80 raster PNG (`logo.png`) in `whmcs.json` and removed the obsolete `logo.svg`, because WHMCS 9.1 wraps gateway logos in `data:image/png;base64,` and rendered SVG files broken.
- Updated `README.md` for WHMCS 8.13–9.1 activation (**Configuration → Apps & Integrations → Payments → Paymos → Activate**), the fail-closed `Convert To For Processing` currency guard, and WHMCS 9.1 `InvoiceImmutability` native full refund support.

### Fixed
- **Connect UI injection (`paymosgateway_connect.php`):** `document.querySelector("form")` matched the Blend header IntelliSearch form, leaving `#paymos-connect-button` hidden (`0×0`) and injecting it on the password-confirmation screen and when Paymos was inactive. The hook now targets `#configpaymosgatewayForm` exclusively.
- **Connect CSRF handling on WHMCS 9.1 (`paymosgateway_connect.php`):** `AdminAreaFooterOutput` passes `$vars['csrfToken']` rather than `$vars['token']` on WHMCS 9.1, causing `paymos_connect_action` (`start` and `poll`) to fail `check_token('WHMCS.admin.default')`. The hook now resolves the CSRF token through `$vars['csrfToken']`, `$vars['token']`, and `generate_token('plain')`, with an `input[name="token"]` fallback from the form.
- **Refund panel POST failing with `409 refund_module_inactive` (`paymosgateway_refunds.php`):** The refund hook processed `POST paymos_refund_action` before WHMCS loaded `includes/gatewayfunctions.php`, so `getGatewayVariables()` was unavailable. The hook now loads `includes/gatewayfunctions.php` before checking gateway variables and returns localized 409 error messages.
- **Full refund projection on WHMCS 9.1 `InvoiceImmutability` (`RefundAdapter`):** Calling `localAPI('AddTransaction')` and `localAPI('UpdateInvoice', status => 'Refunded')` failed on WHMCS 9.1 (`Cannot change invoice status to Refunded without appropriate billing records`), leaving the invoice `Paid` with a balance due while marking `projection_state = applied`. Completed `full` refunds now project via WHMCS's native `refundInvoicePayment(..., sendtogateway = false)`, creating the refund transaction and Credit Note (`invoice_billing_adjustment_credit`) and transitioning the invoice to `Refunded`; `inspect()` validates both 2-row (WHMCS 8.13) and 3-row (WHMCS 9.1) ledgers, and legacy `UpdateInvoice` failures move the intent to retryable review instead of recording `applied`.
- **Transaction write guard on real WHMCS (`RefundWriteGuard`):** The guard registered on the non-existent `WHMCS\Billing\Transaction` model instead of WHMCS's real `WHMCS\Billing\Payment\Transaction`, leaving Paymos invoice transactions unguarded at runtime. It now binds to `WHMCS\Billing\Payment\Transaction` (retaining `WHMCS\Billing\Transaction` for legacy test stubs) and allows authorized `RefundInvoicePayment` projections while rejecting unauthorized transaction writes and deletions.
- **Double-payment renewal after `invoice.paid_late` (`GatewayLink`):** After `invoice.paid_late`, the local row kept `status = 'expired'` with `paid_late_noted` set, allowing a client view or cron reminder render to create a second Paymos invoice. `GatewayLink` now blocks replacement whenever `paid_late_noted` or a live `paid_late_at` timestamp is present (including during `closeBeforeReplacing()` without extra HTTP calls), logs `Manual review`, and renders the localized `replacement_blocked` notice.
- **Hidden `Connection` status row (`Config::moduleConfig()`):** Declaring `Connection` with `'Type' => 'System'` caused WHMCS 9.1 to hide the row from the Payment Gateways table. It now uses `'Type' => ''` with a localized `'Description'` (retaining `'Value'` for compatibility) so the connection status is visible.
- **False `Snapshot mismatch` log noise on open invoices (`Reconciler`):** Checking `StatusMapper::invoiceAction(...) !== ACTION_IGNORE` inside `snapshotMatches()` logged a false `Snapshot mismatch` on every cron run for every open `awaiting_client` invoice. `snapshotMatches()` now validates only `project_id`, `external_order_id`, `amount`, and `currency`, and non-actionable invoices are skipped quietly.
- **Store-default client and six-language admin localization (`Translation`, `Config`, `RefundAdapter`, `lang/*.php`):** Clients with an empty profile language fell back to English instead of `$_SESSION['Language']` or WHMCS's default language (`WHMCS\Config\Setting` / `tblconfiguration.Language`), and gateway settings plus the refund panel were hardcoded in English. `Translation` now resolves session, store-default, and admin languages, and all six catalogs (`english`, `russian`, `german`, `spanish`, `turkish`, `chinese`) provide all 33 localized keys.
- **Refund panel UX, native refund error logging, and `Convert To For Processing` guard (`RefundAdapter`, `paymosgateway_refunds.php`, `GatewayLink`, `CurrencyMismatchException`):** Refund panel AJAX failures now render safely via `.text()` in `.paymos-refund-error`; irrelevant or completed refund buttons are hidden (`Refund overpayment` appears only for `paid_over` invoices without a completed overpayment refund) and empty tokens are skipped in JS; `nativeRefund()` logs a localized failure message with `review_reason` on `failed`/`cancelled`/`review` instead of `"refund in progress"`; and `GatewayLink::render()` fails closed with `CurrencyMismatchException` before calling Paymos when WHMCS's `Convert To For Processing` converts an invoice into a different currency, logging `Currency mismatch: disable Convert To For Processing` and displaying `payment_cannot_be_used`.
- **Missing line items on pending create intent recovery (`GatewayLink::recoverPendingIntent()`):** Replaying an unbound `creating` invoice intent now extracts and sends `products[]` line items identically to a clean first-attempt create.

## [1.7.5] - 2026-11-22

- fix(whmcs): resolve all 12 live WHMCS 9.1 E2E defects and add CI

### Added
- GitHub Actions CI workflow (`.github/workflows/ci.yml`) running `php tests/run.php` across PHP `7.4`, `8.2`, `8.4`, and `8.5` against the pinned `Paymos-labs/php-sdk` `v1.11.0`.

### Changed
- Replaced the module logo with a 400×80 raster PNG (`logo.png`) in `whmcs.json` and removed the obsolete `logo.svg`, because WHMCS 9.1 wraps gateway logos in `data:image/png;base64,` and rendered SVG files broken.
- Updated `README.md` for WHMCS 8.13–9.1 activation (**Configuration → Apps & Integrations → Payments → Paymos → Activate**), the fail-closed `Convert To For Processing` currency guard, and WHMCS 9.1 `InvoiceImmutability` native full refund support.

### Fixed
- **Connect UI injection (`paymosgateway_connect.php`):** `document.querySelector("form")` matched the Blend header IntelliSearch form, leaving `#paymos-connect-button` hidden (`0×0`) and injecting it on the password-confirmation screen and when Paymos was inactive. The hook now targets `#configpaymosgatewayForm` exclusively.
- **Connect CSRF handling on WHMCS 9.1 (`paymosgateway_connect.php`):** `AdminAreaFooterOutput` passes `$vars['csrfToken']` rather than `$vars['token']` on WHMCS 9.1, causing `paymos_connect_action` (`start` and `poll`) to fail `check_token('WHMCS.admin.default')`. The hook now resolves the CSRF token through `$vars['csrfToken']`, `$vars['token']`, and `generate_token('plain')`, with an `input[name="token"]` fallback from the form.
- **Refund panel POST failing with `409 refund_module_inactive` (`paymosgateway_refunds.php`):** The refund hook processed `POST paymos_refund_action` before WHMCS loaded `includes/gatewayfunctions.php`, so `getGatewayVariables()` was unavailable. The hook now loads `includes/gatewayfunctions.php` before checking gateway variables and returns localized 409 error messages.
- **Full refund projection on WHMCS 9.1 `InvoiceImmutability` (`RefundAdapter`):** Calling `localAPI('AddTransaction')` and `localAPI('UpdateInvoice', status => 'Refunded')` failed on WHMCS 9.1 (`Cannot change invoice status to Refunded without appropriate billing records`), leaving the invoice `Paid` with a balance due while marking `projection_state = applied`. Completed `full` refunds now project via WHMCS's native `refundInvoicePayment(..., sendtogateway = false)`, creating the refund transaction and Credit Note (`invoice_billing_adjustment_credit`) and transitioning the invoice to `Refunded`; `inspect()` validates both 2-row (WHMCS 8.13) and 3-row (WHMCS 9.1) ledgers, and legacy `UpdateInvoice` failures move the intent to retryable review instead of recording `applied`.
- **Transaction write guard on real WHMCS (`RefundWriteGuard`):** The guard registered on the non-existent `WHMCS\Billing\Transaction` model instead of WHMCS's real `WHMCS\Billing\Payment\Transaction`, leaving Paymos invoice transactions unguarded at runtime. It now binds to `WHMCS\Billing\Payment\Transaction` (retaining `WHMCS\Billing\Transaction` for legacy test stubs) and allows authorized `RefundInvoicePayment` projections while rejecting unauthorized transaction writes and deletions.
- **Double-payment renewal after `invoice.paid_late` (`GatewayLink`):** After `invoice.paid_late`, the local row kept `status = 'expired'` with `paid_late_noted` set, allowing a client view or cron reminder render to create a second Paymos invoice. `GatewayLink` now blocks replacement whenever `paid_late_noted` or a live `paid_late_at` timestamp is present (including during `closeBeforeReplacing()` without extra HTTP calls), logs `Manual review`, and renders the localized `replacement_blocked` notice.
- **Hidden `Connection` status row (`Config::moduleConfig()`):** Declaring `Connection` with `'Type' => 'System'` caused WHMCS 9.1 to hide the row from the Payment Gateways table. It now uses `'Type' => ''` with a localized `'Description'` (retaining `'Value'` for compatibility) so the connection status is visible.
- **False `Snapshot mismatch` log noise on open invoices (`Reconciler`):** Checking `StatusMapper::invoiceAction(...) !== ACTION_IGNORE` inside `snapshotMatches()` logged a false `Snapshot mismatch` on every cron run for every open `awaiting_client` invoice. `snapshotMatches()` now validates only `project_id`, `external_order_id`, `amount`, and `currency`, and non-actionable invoices are skipped quietly.
- **Store-default client and six-language admin localization (`Translation`, `Config`, `RefundAdapter`, `lang/*.php`):** Clients with an empty profile language fell back to English instead of `$_SESSION['Language']` or WHMCS's default language (`WHMCS\Config\Setting` / `tblconfiguration.Language`), and gateway settings plus the refund panel were hardcoded in English. `Translation` now resolves session, store-default, and admin languages, and all six catalogs (`english`, `russian`, `german`, `spanish`, `turkish`, `chinese`) provide all 33 localized keys.
- **Refund panel UX, native refund error logging, and `Convert To For Processing` guard (`RefundAdapter`, `paymosgateway_refunds.php`, `GatewayLink`, `CurrencyMismatchException`):** Refund panel AJAX failures now render safely via `.text()` in `.paymos-refund-error`; irrelevant or completed refund buttons are hidden (`Refund overpayment` appears only for `paid_over` invoices without a completed overpayment refund) and empty tokens are skipped in JS; `nativeRefund()` logs a localized failure message with `review_reason` on `failed`/`cancelled`/`review` instead of `"refund in progress"`; and `GatewayLink::render()` fails closed with `CurrencyMismatchException` before calling Paymos when WHMCS's `Convert To For Processing` converts an invoice into a different currency, logging `Currency mismatch: disable Convert To For Processing` and displaying `payment_cannot_be_used`.
- **Missing line items on pending create intent recovery (`GatewayLink::recoverPendingIntent()`):** Replaying an unbound `creating` invoice intent now extracts and sends `products[]` line items identically to a clean first-attempt create.

## [1.7.4] - 2026-11-22

- fix(plugins,sdk,ci): fix PrestaShop/Shopware6/CMS locale & scalar bugs, Python webhook digits, atomic mirror pushes, token redaction, and release CI reliability (BUG-1434..1437, BUG-1461..1467, BUG-1478..1481)
- chore: rebuild canonical CMS package

## [1.7.3] - 2026-11-21

- chore: rebuild canonical CMS package

## [1.7.2] - 2026-11-20

- chore: rebuild canonical CMS package

## [1.7.1] - 2026-11-20

- refactor(checkout): remove product images from invoice products
- chore: bundle Paymos PHP SDK v1.11.1
- chore: rebuild canonical CMS package

## [1.7.0] - 2026-11-16

- feat(plugins): add products[] to OpenCart, PrestaShop, WHMCS, and CS-Cart (9.2)
- chore: bundle Paymos PHP SDK v1.11.0
- chore: rebuild canonical CMS package

## [1.6.12] - 2026-11-16

- chore: rebuild canonical CMS package

## [1.6.11] - 2026-11-15

- chore: rebuild canonical CMS package

## [1.6.10] - 2026-11-15

- fix(plugins): guard PHP 8.5 setAccessible deprecations and Drupal SDK path in tests
- fix(refunds): decouple refund RBAC from Withdraw and unify 12 CMS refund adapters
- chore: rebuild canonical CMS package

## [1.6.9] - 2026-11-14

- chore: rebuild canonical CMS package

## [1.6.8] - 2026-11-14

- chore: rebuild canonical CMS package

## [1.6.7] - 2026-11-13

- chore: bundle Paymos PHP SDK v1.10.2

## [1.6.6] - 2026-11-13

- chore: rebuild canonical CMS package

## [1.6.5] - 2026-11-13

- fix(plugins,publicweb): SDK webhook credit recipes, CMS recovery, redirects and CSP logs (audit 2026-11-13)
- chore: bundle Paymos PHP SDK v1.10.1
- chore: rebuild canonical CMS package

## [1.6.4] - 2026-11-13

- chore: rebuild canonical CMS package

## [1.6.3] - 2026-11-13

- chore: rebuild canonical CMS package

## [1.6.2] - 2026-11-11

- chore: rebuild canonical CMS package

## [1.6.1] - 2026-11-10

- chore: rebuild canonical CMS package

## [1.6.0] - 2026-11-10

- feat(notifications): rewrite all 71 email and 52 Telegram RU templates, lifecycle series, and R01-R34 audit fixes
- chore: bundle Paymos PHP SDK v1.10.0
- chore: rebuild canonical CMS package

## [1.5.8] - 2026-11-08

- chore: rebuild canonical CMS package

## [1.5.7] - 2026-11-08

- chore: rebuild canonical CMS package

## [1.5.6] - 2026-11-07

- chore: rebuild canonical CMS package

## [1.5.5] - 2026-11-06

- fix(cms): align marketplace manifests, refund copy, store links, and release guards
- chore: bundle Paymos PHP SDK v1.9.1
- chore: rebuild canonical CMS package

## [1.5.4] - 2026-11-03

- chore: bundle Paymos PHP SDK v1.9.1

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.5.3] - 2026-11-03

- chore: bundle Paymos PHP SDK v1.9.0
- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.5.2] - 2026-11-01

- refactor(invoices): remove the multiple-payment toggle
- chore: bundle Paymos PHP SDK v1.8.1
- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.5.1] - 2026-10-30

- chore: bundle Paymos PHP SDK v1.8.0
- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.5.0] - 2026-10-29

- fix(ton): return friendly addresses with existing webhook schema
- fix(whmcs): guard refund-bearing writes on Paymos invoices
- feat(whmcs): copy for the refund panel, its notices and the cron checks
- feat(whmcs): native refund entry, callback, reconciler and admin panel
- chore: bundle Paymos PHP SDK v1.7.0
- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.4.9] - 2026-10-27

- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.4.8] - 2026-10-27

- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.4.7] - 2026-10-27

- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.4.6] - 2026-10-26

- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.4.5] - 2026-10-26

- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.4.4] - 2026-10-24

- fix(plugins): fence webhook reservations and correct store packaging
- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.4.3] - 2026-10-23

- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.4.2] - 2026-10-23

- fix(plugins): harden WordPress payment recovery and release packages
- chore: bundle Paymos PHP SDK v1.6.1
- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.4.1] - 2026-10-14

- Fix invoice-late-payment audit findings (tasks 4.1-4.8)

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.4.0] - 2026-10-11

- feat(invoices): accept full late payments without reopening invoices
- chore: bundle Paymos PHP SDK v1.6.0

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.22] - 2026-10-07

- chore: rebuild canonical CMS package

## [1.3.21] - 2026-10-07

- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.20] - 2026-10-07

- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.19] - 2026-10-07

- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.18] - 2026-10-03

- fix: устранить дефекты реестра после повторного аудита

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.17] - 2026-09-29

- chore: bundle Paymos PHP SDK v1.5.0

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.16] - 2026-09-26

- chore: bundle Paymos PHP SDK v1.4.4

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.15] - 2026-09-25

- docs(plugins): переводы сообщения о заблокированной замене счёта и сверка минимальных версий
- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues, and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.14] - 2026-09-25

- fix(magento): BUG-180 при заблокированной замене счёта покупателя просят связаться с магазином, а не платить другим способом
- fix(plugins): BUG-166 старый счёт закрывается на сервере до выпуска нового; открытый, оплаченный или 404 — в ручную проверку
- chore: bundle Paymos PHP SDK v1.4.3
- chore: rebuild canonical CMS package

### Fixed
- When the invoice page could not replace the Paymos invoice because the old
  one may still be paid (BUG-166), the client read "Paymos is temporarily
  unavailable. Please contact support." and could pick another gateway for the
  same invoice (BUG-180). That case now shows the new `replacement_blocked`
  line, the SDK's buyer message, in all six catalogues (English in the five
  others until translated), and no longer writes a second `Error` entry to the
  gateway log beside the `Manual review` one. The failure notices moved from
  `paymosgateway_link()` into `GatewayLink::failureNotice()`, unchanged for
  every other failure.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the amount due, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and a `Manual review` entry naming the old invoice goes to
  the gateway log. A 404 on the read of the live invoice no longer cuts a new
  one either.
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.13] - 2026-09-25

- fix(plugins): BUG-163/BUG-164 остальные плагины — замена счёта только по ответу сервера закреплена тестами, комментарии о сроке счёта исправлены
- fix(whmcs): BUG-159 реконсилятор закрывает строку только на «Invoice ID Not Found»
- fix(whmcs): BUG-158 оплата в валюте обработки уходит в ручную проверку, а не зачисляется в чужой валюте
- fix(plugins): BUG-103 вебхук, который ещё обрабатывается, больше не отвечается 200 «duplicate»
- fix(plugins): BUG-090 оплата больше не ведёт на истёкший или проваленный счёт Paymos
- fix(plugins): BUG-135 поздний нефинальный вебхук больше не оживляет проваленный или отменённый заказ
- fix(plugins): BUG-132 WHMCS принимает оплату остатка счёта с частичной предоплатой
- fix(plugins): BUG-104 реконсилятор WHMCS больше не зовёт колбэк-хелперы, которые делают die
- fix(plugins): BUG-136 WHMCS записывает комиссию в валюте счёта, а не в токене
- chore: bundle Paymos PHP SDK v1.4.2

### Fixed
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.
- The gateway fee was booked in the wrong unit. `data.payment.fee` is
  denominated in the paid token (USDT/USDC), and it went into
  `addInvoicePayment` as if it were the invoice currency: a 9000 RUB invoice
  paid with 100 USDT and a 1 USDT fee recorded a 1.00 RUB fee instead of about
  90 RUB. The fee is now converted at `data.payment.exchange_rate`; when the
  token and the invoice currency are the same it is taken as is, and when it
  cannot be converted the module records 0.00.
- The reconciler could end the whole WHMCS cron. It ran inside `AfterCronJob`
  through `checkCbInvoiceID()` and `checkCbTransID()`, which end the process
  with `die()` on an unknown invoice or an already-recorded transaction: one
  deleted WHMCS invoice stopped every later row, and every later `AfterCronJob`
  hook, on each run for a day. The cron path now looks the invoice up through
  `GetInvoice` and the transaction in `tblaccounts`, closes the row of a deleted
  invoice so it is not fetched again, and no longer lets one failing row stop
  the rest.
- A payment on a partly paid invoice went to manual review. WHMCS hands the
  module the amount still due, so after 40.00 of a 100.00 invoice was paid the
  Paymos invoice was cut for 60.00 — and the callback then compared that 60.00
  with the 100.00 total and refused it. The snapshot now keeps the WHMCS total
  next to the amount due: the total is checked against the current total, the
  charge against the payment, and WHMCS is credited with what the Paymos
  invoice charged.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- The pay button could lead to an expired invoice forever. A Paymos invoice
  lives 30 minutes from the moment it is cut, and a WHMCS invoice is often paid
  days after it is first viewed; the module kept handing back the same link
  while the amount, currency, project and environment matched. It now reads
  the live invoice before reusing the link and cuts a new one when the old one
  ended unpaid or expired. A paid invoice keeps its link.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- With "Convert To For Processing" set, a payment was credited in the wrong
  currency. WHMCS converts the amount before the module sees it, so a 100.00 EUR
  invoice was charged as 108.00 USD, and the callback credited 108.00 to the EUR
  invoice; the fee was converted into USD and booked as EUR. The module now reads
  the invoice currency from the client (`GetClientsDetails`, `currency_code`) and
  sends a payment charged in any other currency to manual review. When WHMCS
  cannot report the currency, nothing is recorded and the webhook or the next
  cron run tries again.
- The reconciler closed a row for good on any `GetInvoice` failure. A database
  or API error was read as a deleted invoice, so a paid invoice stopped being
  reconciled after one bad cron run. The row is now closed only when WHMCS
  answers `Invoice ID Not Found`; on any other error it stays for the next run.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.12] - 2026-09-23

- chore: rebuild canonical CMS package

### Fixed
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.11] - 2026-09-21

- chore: rebuild canonical CMS package

### Fixed
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.10] - 2026-09-15

- chore: bundle Paymos PHP SDK v1.4.1

### Fixed
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.9] - 2026-08-30

- chore: rebuild canonical CMS package

### Fixed
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.8] - 2026-08-30

- fix(plugins): phase 4 debts — no installs in the wild, code lands now
- fix(plugins): CMS marketplace readiness spec, phases 1-3
- chore: rebuild canonical CMS package

### Fixed
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.7] - 2026-08-30

- fix(plugins): implicitly nullable factory params break Magento DI compile on PHP 8.5
- chore: rebuild canonical CMS package

### Fixed
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.6] - 2026-08-28

- release: the changelog rot had a cause, and it was not the one I named
- audit: the shipped plugin and SDK docs described a product we stopped shipping
- docs(plugins): eight README stubs become the front pages they already were
- docs(plugins): the changelogs stopped in June and the audit never reached them
- fix(whmcs): four of the six shipped catalogues were unreachable
- chore: bundle Paymos PHP SDK v1.4.0
- chore: rebuild canonical CMS package

### Fixed
- Four of the six shipped catalogues were unreachable. The language resolver
  answered exactly two things — `russian` or `english` — so `german.php`,
  `spanish.php`, `turkish.php` and `chinese.php` sat in the package and were
  never read; a German store showed English. It now accepts both WHMCS's English
  language names and ISO codes, and returns only a language we ship a catalogue
  for.

### Changed
- Whether to surface the API's English error detail is decided by
  `Translation::isEnglish()` instead of comparing the pay button's label against
  its English text — a coupling that would have leaked raw English into a
  translated page the moment one catalogue left that label untranslated.

## [1.3.5] - 2026-08-08

- chore: bundle Paymos PHP SDK v1.3.2

## [1.3.4] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.3.3] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.3.2] - 2026-08-07

- fix(plugins): open the approval tab in the six remaining CMS plugins

## [1.3.1] - 2026-08-07

- chore: bundle Paymos PHP SDK v1.3.1

## [1.3.0] - 2026-08-06

- feat(locales): Spanish blog and plugin catalogs
- feat(locales): German blog corpus, plugin catalogs and bot text
- feat(locales): tr + zh-Hans platform rollout — resx, bots, plugins
- feat(localization): import platform locale foundation
- fix(ecosystem): recover SDK releases
- chore: bundle Paymos PHP SDK v1.3.0

## [1.2.0] - 2026-08-03

- feat: add localization coverage and gateway translations

## [1.1.3] - 2026-08-03

- chore: bundle Paymos PHP SDK v1.3.0
- chore: rebuild canonical CMS package

## [1.1.2] - 2026-08-02

- chore: rebuild canonical CMS package

## [1.1.1] - 2026-08-02

- fix(ecosystem): recover SDK releases
- chore: bundle Paymos PHP SDK v1.2.1
- chore: rebuild canonical CMS package

## [1.1.0] - 2026-07-21

- feat(docs): make the developer surface consumable by LLM agents
- chore: bundle Paymos PHP SDK v1.2.0
- chore: rebuild canonical CMS package

## [1.0.7] - 2026-07-19

- chore: bundle Paymos PHP SDK v1.1.1

## [1.0.6] - 2026-07-13

- chore: rebuild canonical CMS package

## [1.0.5] - 2026-07-12

- fix(plugins): align CMS guidance with secure Connect

## [1.0.4] - 2026-07-12

- chore: rebuild canonical CMS package

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
