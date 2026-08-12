# Therius Payment Orchestration for PrestaShop

Allows PrestaShop merchants to process payments via the Therius Payment Orchestration Platform.

## Installation
1. Copy the `therius-plugin-prestashop` folder to your PrestaShop `modules/` directory as `therius`.
2. In the PrestaShop Admin, go to Modules > Module Manager, find "Therius Payments" and click "Install".
3. Configure the Sandbox and Production keys in the module configuration.

## Configuration & Environments (Sandbox / Production)

The module supports Sandbox and Production side by side — both sets of credentials are stored
at once, and the **Test mode** switch picks which one is actually used at checkout.

1. In the PrestaShop Admin, go to **Modules > Module Manager**, find "Therius Payments", and
   open its **Configure** screen.
2. Fill in **Merchant Code**, then, per environment:
   - **Test**: Test Publishable Key, Test Private Key, Test Checkout Config ID, Test Webhook Secret.
   - **Live**: Live Publishable Key, Live Private Key, Live Checkout Config ID, Live Webhook Secret.
3. Toggle **Test mode** — Enabled routes checkout to `https://api-sandbox.therius.io` using the
   Test credentials; Disabled routes to `https://api.therius.io` using the Live credentials.
4. Click **Save**.

## Webhooks
Configure the following webhook URL in your Therius Dashboard (Developers -> Webhooks):
`https://<your-store-url>/module/therius/webhook`

Configure one webhook in **Sandbox** mode and one in **Production** mode if you use both, and
copy each environment's signing secret into the matching **Test Webhook Secret** / **Live
Webhook Secret** field above. Every incoming webhook call is verified against that secret
(`X-Therius-Signature`, HMAC-SHA256) before anything is applied — unsigned or mis-signed
requests are rejected with `401`.

Subscribe to:
- `payment.authorized`
- `payment.captured`
- `payment.refused`
- `payment.refunded`
- `payment.refund_failed`
- `payment.cancelled`
- `payment.chargeback`
- `payment.capture_failed`

Note: `payment.chargeback` is accepted but not currently mapped to an order
state change (PrestaShop has no built-in "on-hold" equivalent used elsewhere
in this module) - it is acknowledged with 200 and otherwise ignored.

**Configuring this webhook is not optional.** This checkout charges the
card before the PrestaShop order exists - the order is normally created by
the customer's own browser POSTing back after a successful payment. If that
POST never lands (closed tab, dropped connection, a conflicting script on
the page), a `payment.captured`/`payment.authorized` webhook delivery is
the only remaining way the store finds out the charge happened and builds
the missing order. Without a configured webhook, a payment can succeed with
no order ever created.

## Known Limitations

- **Recovered-order amount can drift from the amount actually charged.** When the webhook has
  to build the missing order (the recovery path above), it prices the order at the cart's
  *current* total at the time the webhook arrives, not the amount that was actually charged at
  purchase time. In practice this only matters if the cart's contents change in the (normally
  few-second) gap between charge and webhook delivery — rare, but worth knowing about if you see
  a recovered order's total not matching its payment amount.
