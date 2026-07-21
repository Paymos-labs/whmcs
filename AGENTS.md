# AGENTS.md — Paymos for WHMCS

WHMCS payment gateway module for Paymos stablecoin payments. Thin shell over the bundled
Paymos PHP SDK — the SDK owns signing, webhook verification, dedup and status mapping.

## The Paymos wire contract (get these wrong and it silently breaks)

Base URL is `https://api.paymos.io`. Sandbox and live differ by credentials, not by host.

**Two different signature algorithms. Never share verification code between them.**

| | Canonical string | Encoding | Header |
|---|---|---|---|
| Outgoing API call | `{ts}\n{METHOD}\n{path}\n{query}\n{sha256hex(body)}` | **base64** | `Authorization: HMAC-SHA256 {keyId}:{sig}` |
| Incoming webhook | `{ts}.{rawBody}` | **hex** | `X-Webhook-Signature: t={ts},v1={sig}` |

- `{query}` is signed **with the leading `?`** (`?a=b`, not `a=b`); empty string when there is no query.
- `{sha256hex(body)}` is the empty string for an empty body — not the hash of `""`.
- The webhook header is `X-Webhook-Signature`. `X-Paymos-Signature` does not exist; if you see it, it is a bug.
- During secret rotation one header carries **two** `v1=` values. Try each; accept if any matches.
- Requests older than **5 minutes** are rejected. Verify webhook timestamps against the same window.
- Always verify the signature against the **raw request body**, before any JSON parse/re-encode.

**Exactly 8 invoice webhook events exist:**
`invoice.awaiting_payment`, `invoice.confirming`, `invoice.underpaid_waiting`, `invoice.paid`,
`invoice.paid_over`, `invoice.underpaid`, `invoice.expired`, `invoice.cancelled`.

**These do NOT exist. Do not handle, emit, or document them:** `invoice.created`,
`invoice.token_selected`, and the status `new`. Invoice creation fires no webhook. The buyer
choosing a token is `confirm-payment`, whose event is `invoice.confirming` (or none).

**9 invoice statuses:** `awaiting_client`, `awaiting_payment`, `confirming`, `underpaid_waiting`,
`paid`, `paid_over`, `underpaid`, `expired`, `cancelled`. Final: `paid`, `paid_over`,
`underpaid`, `expired`, `cancelled`.

**Other rules that bite:**

- **Amounts are decimal strings, never floats.** `"11.05"`. Compare as strings or with bcmath; a float round-trip loses money.
- **Invoice TTL is server-side config.** There is no lifetime/expiry request field. Never add a setting claiming to control it.
- `external_order_id` is the **idempotency key** — same value returns the original invoice (200 instead of 201).
- **Never send `merchant_id`.** The server derives it from `project_id`.
- Use a **Payment** API key. Payout keys require an IP allowlist and will fail here.
- **Reverse-verify before fulfilling.** After the signature passes on a terminal event, re-fetch the invoice from the API and re-check amount and status. Signature alone is not sufficient.
- Errors are RFC 9457 `application/problem+json`. Switch on the stable `code` field, never on `title`/`detail` text. Each error's `type` deep-links to https://paymos.io/docs/errors/codes.
- Only `awaiting_client` invoices can be cancelled; anything later returns 409 `invoice_cannot_be_cancelled`.

## Credentials

Credentials arrive through the one-time Connect flow and are stored encrypted by the host
platform. **Never** ask the merchant to paste an API secret into a settings field you add,
never log a secret or a raw webhook body, and never commit one. The published package contains
no credentials.

## Docs

Every page is available as raw Markdown — append `.md` to any URL, e.g.
https://paymos.io/docs/webhooks/verify.md. Machine-readable index: https://paymos.io/llms.txt.
Prefer these over fetching the rendered HTML.

## Verify your change

```bash
php tests/run.php
```

Run this before finishing. If it fails, fix it — do not report success.

## Platform notes

- WHMCS resolves gateway modules by filename: the module file's basename is the gateway identifier and must match the config function name. Renaming the file silently unregisters the gateway.
- The callback file lives under `modules/gateways/callback/`; WHMCS calls it directly, outside the admin session.
- Use WHMCS's own invoice/transaction helpers to record payment (`addInvoicePayment`, `logTransaction`) so the ledger stays consistent — do not write invoice tables directly.
- WHMCS's developer documentation has no machine-readable surface; rely on this file and the Paymos docs Markdown mirrors instead of guessing from web search.
