# Visa sandbox setup

REDLINE uses Stripe-hosted Checkout for outstanding USD repair invoices. Keep
`REDLINE_STRIPE_MODE` set to `test`. Test payments do not move money, but a verified
test payment **does mark the selected invoice paid in this database**. Use test
invoices and a test database.

## Private server configuration

The existing sandbox credentials have been moved from the deployment sample to
the Git-ignored `redline-stripe.private.php` at the repository root, outside `www`.
The sample now contains no credentials. Rotate credentials that have been exposed
in screenshots, shared archives, logs, or source history.

When deploying, serve only `www/` as the document root. Place the private file in
the directory immediately above the actual document root:

```text
account/
  redline-stripe.private.php
  public_html/                  <- contents of www/
    customer/visa_webhook.php
```

For a new sandbox, copy `redline-stripe.private.example.php` to that private
location, then edit the private copy with the following values:

| Setting | Value |
| --- | --- |
| `REDLINE_STRIPE_MODE` | `test` |
| `REDLINE_STRIPE_SECRET_KEY` | Sandbox secret API key beginning `sk_test_` |
| `REDLINE_STRIPE_WEBHOOK_SECRET` | Signing secret beginning `whsec_` for this endpoint in the same sandbox |
| `REDLINE_PUBLIC_URL` | Public HTTPS application URL, including any subdirectory |

Server environment variables with these names override private-file values.
Enable the PHP cURL and PDO MySQL extensions in the PHP runtime serving the site.
No publishable key is needed for this hosted redirect flow.

Checkout requests pin Stripe API `2026-11-18.endive` and restrict accepted methods
with `allowed_payment_method_types: [card]`. Stripe removed the former
`payment_method_types` creation parameter in API `2026-09-30.endive`; see the
[Stripe migration note](https://docs.stripe.com/changelog/endive/2026-09-30/remove-payment-method-types-checkout-sessions).

## Database and Stripe webhook

For an existing database, back it up and import
`www/database/migrations/20261121_visa_checkout.sql`. This adds only the payment
attempt table and can be rerun. A fresh `www/database/schema.sql` import already
includes the table; never import the full schema over existing data.

In the selected Stripe sandbox, register a **snapshot** webhook destination:

```text
https://YOUR-DOMAIN/customer/visa_webhook.php
```

Include the application's subdirectory when applicable. Subscribe to
`checkout.session.completed` and `checkout.session.async_payment_succeeded`.
Copy that endpoint's signing secret into the private configuration. See the
[Stripe webhook guide](https://docs.stripe.com/webhooks).

Open **Admin → Garage Settings**. The card section checks the key format, signing
secret format, base URL, cURL extension, and database table, and displays the exact
webhook URL. These checks do not verify API credentials or webhook delivery.

For local testing with `http://localhost:8080`, set that as the public base URL and
forward webhook events using the Stripe CLI:

```sh
stripe listen --events checkout.session.completed,checkout.session.async_payment_succeeded --forward-to http://localhost:8080/customer/visa_webhook.php
```

Use the listener's `whsec_...` secret while that listener is running. A Dashboard
webhook secret and a CLI listener secret are different.

## Try a Visa payment

Sign in as the owner of an unpaid test invoice, open **Invoices**, select it, and
choose **Test Visa / Card payment**. On the Stripe-hosted page, use test Visa
`4242 4242 4242 4242`, a future expiry such as `12/34`, and CVC `123`.
See [Stripe test cards](https://docs.stripe.com/testing).

After payment, REDLINE verifies the session server-side before recording the card
payment. Confirm that the invoice is paid, one completed card ledger entry exists,
and replaying the webhook or refreshing the return page adds no duplicate entry.
Also test cancelling checkout and a declined test card. An unchanged browser
redirect alone must never mark an invoice paid.
