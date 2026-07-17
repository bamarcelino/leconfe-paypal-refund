# PaypalRefund — Leconfe Plugin

Adds a **Refund via PayPal** action to the Payment Detail page of the scheduled-conference panel, for payments completed through the official **PaypalPayment** plugin.

Tested against Leconfe 1.4.6 and PaypalPayment 1.1.0. Licensed under GPL-3.0, matching the Leconfe ecosystem.

## What it does

- Shows a "PayPal Refund" section on the Payment Detail page for any payment where `payment_method = paypal`, `paid_at` is set and a `paypal_payment_id` meta exists.
- Visible only to users who can update the current scheduled conference (same permission rule the official plugin uses for showing the PayPal token).
- Supports **full refunds** (default) and **partial refunds** (lower the amount in the modal).
- Optional toggle to mark the payment as unpaid again (reopens the queue so the participant can pay again; the expiry date is extended if already past, so the core cleanup lottery does not delete the reopened queue).
- Records everything as payment metas: `paypal_refund_id`, `paypal_refund_state`, `paypal_refund_amount`, `paypal_refund_currency`, `paypal_refund_sale_id`, `paypal_refunded_at`, `paypal_refunded_by`. Once refunded, the action disappears and a status section with the refund details is shown instead (idempotency guard).
- Writes an audit line to the Laravel log on success and on failure.

## How it talks to PayPal

It reuses the credentials (Client ID / Secret, sandbox toggle) already configured in the official PaypalPayment plugin — nothing new to configure. It calls the PayPal REST v1 Payments API directly through Laravel's HTTP client:

1. `POST /v1/oauth2/token` — client-credentials authentication.
2. `GET /v1/payments/payment/{PAY-…}` — resolves the **sale id** from the stored `paypal_payment_id`.
3. `POST /v1/payments/sale/{saleId}/refund` — empty body for a full refund, or `{"amount": {...}}` for a partial one.

This is the same API generation used by the official plugin's checkout flow (Omnipay `PayPal_Rest`, PAY-* ids), so every payment the plugin created can be refunded here. No extra Composer dependencies are required.

## Installation

1. Copy the `PaypalRefund` folder into the Leconfe `plugins/` directory (or upload the zip through Administration → Plugins, if your instance allows plugin upload).
2. Enable the plugin in the plugin list.
3. Requirements: the official **PaypalPayment** plugin must be installed, enabled and configured. If it is missing, this plugin stays dormant.

## Limitations and notes

- PayPal allows refunds only within its own window (180 days after the transaction) and requires sufficient balance in the merchant account; outside those conditions PayPal returns an error, which is shown verbatim in the failure notification.
- Leconfe's core has no "refunded" payment state (states are only paid/unpaid), so the refund is represented via metas plus, optionally, reverting the payment to unpaid. Certificates, registration status side effects and participant notifications are intentionally not touched — handle those according to your workflow.
- Refunds issued directly on paypal.com are not detected (there is no webhook); this plugin only tracks refunds it issued itself.
- A partial refund can be issued only once per payment through this plugin (the idempotency guard blocks a second attempt, to keep bookkeeping unambiguous). Issue any remaining amount directly on paypal.com if ever needed.

## Receipt override

Since Leconfe's receipt template has no extension hook, the plugin prepends its own view location so a shadowed copy of `receipt.blade.php` takes precedence (core files are never modified). Refunded payments render a notice under the receipt header:

- **Full refund** — red "REFUNDED" block; the receipt is marked as no longer valid as proof of payment.
- **Partial refund** — amber "PARTIAL REFUND" block; the receipt remains valid for the remaining amount, which is shown explicitly.

Because this is a shadowed copy of a core template, re-sync it if a future Leconfe release changes the receipt layout.

## Changelog

- **1.1.1** — Partial refunds no longer invalidate the receipt; amber notice with remaining valid amount; refund type badge and remaining amount in the panel status section.
- **1.1.0** — Receipt override showing a refund notice.
- **1.0.1** — Moved the PaypalPayment presence check out of boot (plugin registration order race); case-insensitive payment method check.
- **1.0.0** — Initial release: full/partial refund action on the Payment Detail page via the PayPal REST v1 API, reusing the official plugin's credentials; refund metadata, idempotency guard, optional payment reopening, audit logging.

## Author

**Bruno Cesar Alves Marcelino**  
Author and Developer

Developed under **Scientia International**.
