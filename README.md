# Mercado Pago Headless

Provides a headless (API-only) integration between **Mercado Pago** and **Drupal Commerce** for **recurring subscriptions** using Mercado Pago preapproval plans and preapproval subscriptions.

The module is designed for decoupled front-ends (React, Vue, etc.): the client builds the payment and the module exposes REST endpoints to activate subscriptions and listen for Mercado Pago IPN (webhook) notifications.

## Features

- **Preapproval plans** — create a Mercado Pago preapproval plan per product variation from the Drupal admin UI.
- **Preapproval subscriptions** — create and cancel recurring subscriptions from a headless client via REST endpoints.
- **Webhook / IPN reconciliation** — listen to Mercado Pago notifications and reconcile order, payment, and subscription states, including inbound refunds.
- **Commerce payment gateway plugin** — `mercadopago_headless` integrates with Drupal Commerce payment methods and recurring billing (commerce_recurring).
- **X-Signature webhook verification** — optional fail-closed signature validation using Mercado Pago's official HMAC scheme.

## Requirements

- Drupal 11.x
- Drupal Commerce (`drupal/commerce` ^3)
- commerce_recurring (`drupal/commerce_recurring` ^1)
- Mercado Pago PHP SDK (`mercadopago/dx-php` ^3.8) — **must be installed in the host project's root composer.json**, not in the module:

```bash
composer require mercadopago/dx-php:3.8.0
```

> ⚠️ The module will fatal at runtime if the SDK is not installed (`MercadoPago\*` classes are loaded via Composer).

## Configuration

### Payment gateway

1. Go to **Commerce → Configuration → Payment gateways**.
2. Edit the **Mercado Pago Headless (Suscripciones)** gateway.
3. Configure the fields:

| Field | Description |
|---|---|
| `public_key` | Public key for the Mercado Pago environment (sandbox or production). |
| `access_token` | Private access token for server-to-server API calls. |
| `back_url` | Return URL after the payment state is obtained. |
| `notification_url` | Webhook URL that Mercado Pago must notify (see below). |
| `webhook_secret` | **Optional.** Signature secret configured in Mercado Pago (Webhooks → Signature secret). When set, the webhook **requires** a valid `X-Signature` (fail-closed). When empty, the webhook accepts notifications without verification and logs a security WARNING (dev only). |
| `mode` | `test` or `live`. The gateway form persists this value; the API service reads it via `getPlugin()->getMode()`. |

> 🔒 **Production note:** `access_token` and `webhook_secret` are stored in plaintext gateway configuration. Consider encrypting them (e.g. with the `key` module) before going to production.

### Webhook endpoint

The webhook endpoint is **`POST /api/mercadopago/webhook`** and is intentionally **anonymous** (Mercado Pago servers are not authenticated). Security comes from the `X-Signature` verification when `webhook_secret` is configured.

Configure this URL in the Mercado Pago dashboard as the notification URL for **Webhooks** (JSON payloads). Classic form-encoded IPNs are not supported.

## Architecture

```
┌────────────────────────────────────────────────────────────────┐
│  Client (headless: React/Vue)                                  │
│    POST /api/mercadopago/process-payment                       │
│    POST /api/mercadopago/process-subscription                  │
└───────────────────────────────┬────────────────────────────────┘
                                │
                                ▼
┌────────────────────────────────────────────────────────────────┐
│  Controllers                                                   │
│  ├─ ProcessPaymentController      activate a subscription      │
│  ├─ ProcessSubscriptionController cancel a subscription        │
│  ├─ WebhookController             receive MP notifications     │
│  └─ SubscriptionPlanContoller     admin: create a plan         │
└───────────────────────────────┬────────────────────────────────┘
                                │
                                ▼
┌────────────────────────────────────────────────────────────────┐
│  MercadoPagoApiService (mercadopago.api)                       │
│  ├─ createSuscriptionPlan()        MP preapproval plans         │
│  ├─ createPreapprovalSubscription() preapproval subscriptions  │
│  ├─ cancelSubscription()           cancel remote preapproval   │
│  └─ processNotification()          webhook topic dispatch      │
└───────────────┬──────────────────────────────┬─────────────────┘
                │                              │
                ▼                              ▼
┌───────────────────────────────┐  ┌──────────────────────────────┐
│  MercadoPagoHeadless plugin   │  │  Mercado Pago API (SDK)      │
│  (commerce payment gateway)   │  └──────────────────────────────┘
└───────────────────────────────┘
```

Key components:

- **`src/Service/MercadoPagoApiService.php`** — all Mercado Pago API calls (plans, preapprovals, cancellations) and webhook reconciliation (payment/subscription/order state transitions, refund synchronization).
- **`src/Plugin/Commerce/PaymentGateway/MercadoPagoHeadless.php`** — Commerce payment gateway plugin: creates payment methods, creates payments for renewals, cancels the remote preapproval when a saved card is deleted.
- **`src/Controller/*`** — HTTP entry points (see Routes).
- **`src/Hook/MercadopagoHooks.php`** — adds the "Crear Plan de MP" operation to product variations.

## Routes

| Route | Method | Path | Access |
|---|---|---|---|
| `mercadopago.create_subscription_plan` | GET | `/admin/commerce/product-variation/{commerce_product_variation}/create-mp-plan` | `administer commerce_product_variation` |
| `mercadopago.process_payment` | POST | `/api/mercadopago/process-payment` | `process mercadopago payment` |
| `mercadopago.process_subscription` | POST | `/api/mercadopago/process-subscription` | `process mercadopago payment` |
| `mercadopago.ipn_listener` | POST | `/api/mercadopago/webhook` | anonymous (signature-verified) |

## How it works

### 1. Create a preapproval plan (admin)

1. In the product variation admin, use the **"Crear Plan de MP"** operation.
2. The module calls Mercado Pago to create a preapproval plan and stores the returned plan ID on the variation (`field_preapproval_plan_id`).
3. The plan defines the recurring billing schedule (frequency, amount).

> ⚠️ The module **reads and writes `field_preapproval_plan_id` on the product variation** but does not declare the field anywhere in the module (no `field.storage`, no `field.field`, no schema, no install hook). The field must exist in the site's configuration (e.g. created manually or exported from another project) or the flow will fatal.

### 2. Activate a subscription (client)

The headless client POSTs to `/api/mercadopago/process-payment` with a Mercado Pago card token, payer data, and the plan/variation reference:

1. The module validates the payload and creates (or reuses) a draft Commerce order.
2. It calls `createPreapprovalSubscription()` to create the Mercado Pago preapproval, using the **order UUID** as `external_reference`.
3. It creates a Commerce payment method (with the preapproval ID as `remote_id`) and a Commerce payment in state `authorization`.
4. Mercado Pago charges the card immediately for the preapproval; subsequent renewals are **initiated by Mercado Pago**, not by Drupal.

### 3. Renewals (cron / commerce_recurring)

1. commerce_recurring cron creates renewal payments and calls the gateway plugin's `createPayment()`.
2. The plugin does **not** contact Mercado Pago — it only records the payment state in Drupal:
   - `$capture = TRUE` → state `completed` (Mercado Pago already charged).
   - `$capture = FALSE` → state `authorization` (awaiting webhook confirmation).
3. The real transaction details arrive via webhook.

### 4. Reconciliation (webhook)

Mercado Pago POSTs to `/api/mercadopago/webhook`:

1. `WebhookController` parses the JSON body and verifies the `X-Signature` if `webhook_secret` is configured.
2. `processNotification()` dispatches by topic (`payment`, `subscription_preapproval`, etc.).
3. Payments, orders, and subscriptions are updated to their real states; refunds are synchronized inbound.

### 5. Cancel a subscription

- **Client:** POST `/api/mercadopago/process-subscription` with `action: cancel` → cancels the remote preapproval and the local Commerce subscription.
- **Admin/user:** deleting a saved payment method → the gateway plugin's `deletePaymentMethod()` cancels the remote preapproval first; if the remote cancel fails, it throws `PaymentGatewayException` so the local method is NOT deleted (prevents "customer keeps being charged after removing the card").

## Security

- **Webhook signature verification** — when `webhook_secret` is configured, the webhook validates `X-Signature` (HMAC-SHA256, constant-time `hash_equals`), rejecting invalid signatures with 401. Without a configured secret the webhook accepts notifications but logs a WARNING (development only).
- **Malformed payload handling** — non-numeric `data.id` values are rejected with 400 and a WARNING log instead of a PHP `TypeError` / 500.
- **Permissions** — state-changing API routes require the `process mercadopago payment` permission.

## Known limitations

- **Classic form-encoded IPNs** are not supported; only JSON webhook payloads.
- **QR-code notifications** (`point_integration_wh`) are not signed by Mercado Pago and will be rejected (401) under fail-closed mode.
- **Custom fields are not declared by the module** — `field_preapproval_plan_id`, `billing_schedule`, and the billing-profile fields used by the payment controller must exist in site configuration.
- **commerce_product** must be enabled for product-variation flows.
- The API service reads gateway configuration by hard-coded config ID (`commerce_payment.commerce_payment_gateway.mercadopago_headless`); renaming the gateway breaks the module silently.

## Maintainers

- Willi Bautista — https://www.drupal.org/u/willibautista
