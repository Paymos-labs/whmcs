# Paymos for WHMCS

Official WHMCS gateway module for Paymos. It puts a stablecoin option on the
client's invoice, and when the transfer confirms on chain it books the payment
through WHMCS itself — so provisioning, service activation and every automation
rule you already run fire exactly as they do for any other gateway.

WHMCS is a billing system, not a storefront, and this module is built for that
shape. It works off invoices rather than carts, and passes the WHMCS client id
through so a payment stays attached to the right account. The invoice keeps the
currency WHMCS raised it in; the rate the client pays at is locked when they pay,
so the amount you billed is the amount that settles.

## Requirements

- WHMCS 8.13 or 9.0;
- PHP 7.4 or later with the `curl`, `hash`, `json` and `openssl` extensions;
- a System URL on **HTTPS** — connecting is refused over plain HTTP;
- a Paymos account with the billing project open in the dashboard.

No Composer step and no runtime dependency to resolve: the Paymos PHP SDK travels
inside the package. The admin side ships in English, Russian, German, Spanish,
Turkish and Simplified Chinese, and follows the language the WHMCS admin is using.

## Install and connect

1. Download the latest package from [GitHub Releases](https://github.com/paymos-labs/whmcs/releases/latest).
2. Extract the archive over your WHMCS root — it lays down `modules/gateways/` and `includes/hooks/`.
3. Open **Configuration → System Settings → Payment Gateways**, and activate Paymos from **All Payment Gateways**.
4. Open the project that should receive this installation's invoices in the Paymos dashboard; that current project is used automatically.
5. Click **Connect Paymos** on the payment gateways page.
6. Approve it in the Paymos tab — the page shows the System URL it is binding and the project it will bill into, and there is no second project selector anywhere in the flow.

Every merchant downloads the same archive, and it carries no API key, API secret,
project id, webhook secret, OAuth token or device code. On its own it accepts
nothing; the approval in step 6 is what makes this installation live.

That one approval provisions both environments. Paymos takes your single active
Payment key for each, or creates one where none exists, and registers the Invoice
webhook for this installation — reusing an existing webhook only when its callback
URL, category and project all match, and leaving a different webhook already
sitting at that address alone rather than overwriting it. The device token from the
approval is short-lived and thrown away; every Merchant API call after it is
HMAC-signed.

The callback address registered for this installation:

```text
https://your-store.example/modules/gateways/callback/paymosgateway.php
```

After that the module has three settings, and none of them is a credential: **Mode**
(Sandbox or Live), **Button Text**, and a read-only **Connection** line telling you
whether this installation is connected.

## Secret storage

Credentials and temporary device state are stored as an AES-256-GCM envelope in
tblconfiguration keyed from the WHMCS encryption hash. Saved secrets are not
rendered back into the administration page, and there is no field to type them
into. Reconnect after you change the System URL, revoke a Payment key, or rotate
a webhook secret.

## What your client sees

The invoice grows a **Pay with Paymos** button, which opens the hosted Paymos
checkout. Your client picks a token and network there from the set your project
allows, sends the transfer, and watches the status change on the page. No Paymos
account is required of them, and no email is collected at checkout.

An invoice that already has a live Paymos invoice at the same amount, currency,
project and environment reuses it, so refreshing the page does not scatter
half-finished invoices across your account. Change the amount and the next click
mints a fresh Paymos invoice under a new reference — useful when you re-issue a
line item at a corrected price.

## What happens to the WHMCS invoice

Only one event class touches the invoice. On `invoice.paid` or `invoice.paid_over`
the module calls WHMCS's own `addInvoicePayment`, the balance clears, the invoice
turns **Paid**, and WHMCS takes it from there.

Everything before that is recorded in **Billing → Gateway Log** rather than on the
invoice — `invoice.confirming` while the transfer gathers confirmations,
`invoice.underpaid_waiting` when part of the amount arrived, `invoice.expired` and
`invoice.cancelled` when the window closed. The invoice simply stays Unpaid and the
client can pay it again. That is deliberate: a hosting invoice that flipped through
five intermediate states would trip suspension and dunning rules that were never
written for them.

Before any of that, a terminal event is verified twice — the signature, then the
invoice pulled back from the Merchant API and compared against the stored snapshot.
Two more guards follow. A WHMCS invoice already marked Paid ignores every later
complete-or-downgrade event, so a `paid` followed by a `paid_over` cannot book a
second payment and hand your client an overpayment credit. And a paid event whose
amount no longer matches the invoice is logged for manual review instead of being
applied.

## Renewals and recurring services

Paymos has no stored payment method and performs no automatic charges. A blockchain
transfer is pushed by its owner; nobody can pull one on a schedule. So a renewal
invoice generated by WHMCS is paid the same way the first one was — your client
opens it and pays.

Everything else about recurring billing is untouched. WHMCS still generates the
invoice on its own cycle, still sends its own reminders, and still runs suspension
and termination on your rules; the payment simply arrives when the client sends it.
If your business depends on silently charging a card on the renewal date, this is
the honest boundary to know about before you install.

## Test in Sandbox before you go live

Set **Mode** to Sandbox, open a test invoice as a client, and click through to the
hosted checkout. Sandbox payments do not confirm on a timer — open that invoice in
the Paymos dashboard and trigger the outcome you want to rehearse. The same events
are emitted as a real transfer would produce, so what you verify in Sandbox is what
runs in Live.

Then set **Mode** to Live. Both environments were provisioned in the single connect,
so there is no second setup and nothing to reconnect.

## Delivery and the cron safety net

Webhooks are signed with `X-Webhook-Signature` — `t={timestamp},v1={hmac_hex}`,
HMAC-SHA256, compared in constant time, and tolerant of both secrets during a
rotation. Each delivery carries a stable event id that survives retries, and the
module keeps those ids, so a redelivered event answers 200 without being applied
twice.

Missed deliveries are swept up by the WHMCS cron. On `AfterCronJob`, no more often
than once every ten minutes, the module takes up to 50 unpaid Paymos invoices from
the last 24 hours, fetches each one from the API, and runs it through the same path
a webhook would take — reverse verification, amount guard and already-paid guard all
still apply.

## Troubleshooting

**Connect refuses to start.** The System URL has to begin with `https://`. WHMCS
reports it under Configuration → System Settings → General Settings, and the module
compares the approval response against it, so a mismatch there is also what makes a
previously working connection stop.

**The client paid and the invoice is still Unpaid.** Look in the Gateway Log first.
`Manual review` has two causes, and the entry's text tells them apart. Either the
invoice amount changed after the Paymos invoice was created, or the amount due,
the mode or the project changed while the old Paymos invoice was already paid,
still payable, or could not be read. In the second case no new invoice is issued
and the client is asked to contact you, so check the old invoice in the Paymos
dashboard first. Reconcile either case by hand. `Snapshot mismatch` means the stored record and the
live invoice disagree, and nothing will be applied until they do.

**Nothing at all in the Gateway Log.** Then the callback never arrived. Request the
callback URL above from outside your network: it must be reachable without HTTP
auth, without an IP allowlist, and without a redirect, because redirects are not
followed.

**Refunding a client.** There is no reversal to issue. Send a withdrawal from your
Paymos balance to the address you agreed with the client, and record it against the
invoice in WHMCS the way you would any manual credit.

- [Documentation](https://paymos.io/docs/cms-whmcs)
- [Source](https://github.com/paymos-labs/whmcs)
- [Changelog](CHANGELOG.md)
- [Support](mailto:support@paymos.io)
