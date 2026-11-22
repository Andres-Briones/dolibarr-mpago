# Mpago — Mercado Pago payments for Dolibarr (Argentina)

Dolibarr external module that lets a customer pay an invoice or an order
online with Mercado Pago **Checkout Pro** (Orders API), in Argentine pesos.

## What it does

- Adds a "Pay with Mercado Pago" button to Dolibarr's public payment page
  (`public/payment/newpayment.php`) for customer invoices and customer orders.
- On click it creates an order at Mercado Pago (`POST /v1/orders`) for the
  amount still due and sends the buyer to Mercado Pago's checkout.
- Records the payment in Dolibarr **exactly once**, whichever comes first:
  the buyer's return to the site, the webhook notification, or a periodic
  check. The state is always read from Mercado Pago
  (`GET /v1/orders/{id}`); nothing is trusted from the return URL or from
  the notification body.
- Invoice: validated if still a draft, payment and bank line created,
  invoice closed when fully paid.
- Order: an invoice is created from the order and validated, the order is
  classified billed, then the invoice is paid as above. If the order was
  already invoiced by hand and that invoice is the only unpaid one, the
  payment goes to it.
- If the amount paid differs from the amount requested, what was really
  paid is recorded and a warning is kept (setup page, "Payments that need
  attention").

## Requirements

- Dolibarr 22, 23 or 24, with the Invoices and Banks modules; Orders if
  orders are to be paid; Scheduled jobs (cron) running every minute or so.
- PHP 8.1 or later with curl and openssl. No Composer dependency.
- Company currency ARS.
- A Mercado Pago seller account with an application of type
  **Checkout Pro** whose API type is **Orders API**.
- Dolibarr reachable from the Internet over HTTPS (for the webhook and for
  the buyer's return).

## Install

1. Copy or symlink this directory into your Dolibarr tree as `htdocs/custom/mpago`.
2. In Dolibarr, go to Setup > Modules and enable "Mercado Pago".
3. Make sure Dolibarr's scheduled jobs are run by the system cron
   (`scripts/cron/cron_run_jobs.php`). The module adds two jobs:
   "process notifications" (every minute) and "check open payments"
   (every 15 minutes).

## Setup

### In the Mercado Pago panel ("Tus integraciones")

1. Create an application: product **Checkout Pro**, API type **Orders API**
   (the API type cannot be changed afterwards).
2. Copy the **Access Token**: the test one for trials, the production one
   to charge for real (production credentials must be activated first).
3. Open **Webhooks > Configurar notificaciones**. For the test mode and
   for the production mode, paste the notification address shown on the
   module's setup page
   (`https://<your-dolibarr>/custom/mpago/public/webhook.php?entity=1`)
   and select the event **Order (Mercado Pago)**. Save.
4. On the same screen, reveal the **secret signature** ("Firma secreta")
   and copy it. There is one per application.

### In Dolibarr (Setup > Modules > Mercado Pago > setup)

- **Access token** and **Webhook secret signature**: paste them. They are
  stored encrypted and never shown again; leave the fields empty to keep
  the stored values.
- **Bank account**: the Dolibarr account that represents your Mercado Pago
  balance. Payments are booked there.
- **Payment mode**: payment mode written on the payments (credit card by
  default).
- **User for automatic actions**: invoices are validated and payments
  recorded in the name of this user; it needs the permissions to create
  and validate invoices and to record payments and bank lines.
- Optional: checkout validity in hours (24 by default), maximum number of
  instalments, text on the card statement.
- **Test the connection** sends one read request with the saved token.

Whether payments are test or real ones depends only on which access token
is saved; there is no separate mode switch. The button is offered only
when the setup is complete.

Payment links are the usual Dolibarr ones (invoice or order card, "Online
payment" link, or the `__ONLINE_PAYMENT_URL__` substitution in e-mails).

## For other modules

The return page shows a link back to your own page when a hook answers.
Context `mpagoreturn`, method `getReturnLink`, parameters `source`
(`order` or `invoice`), `fk_source`, `ref_source` and `result` (`paid`,
`pending` or `failed`). Put the address in `$this->results['url']` and,
optionally, the text of the link in `$this->results['label']`.

The module also answers the hook `tiendaKeepUnpaidOrder` of
the shop module `dolibarr-tienda` (context `tiendacron`): before the
shop cancels an unpaid order, the order is read again at Mercado Pago and
kept when it was paid or is still being processed.

## Testing with test cards

With the **test access token** saved, open a payment link in a private
browser window (no Mercado Pago login needed) and pay with one of Mercado
Pago's test cards, for example Mastercard `5031 7557 3453 0604` or Visa
`4509 9535 6623 3704`, CVV `123`, expiry `11/30`. The cardholder name
decides the outcome: `APRO` (with DNI `12345678`) approves, `OTHE`
rejects, `CONT` leaves the payment pending. Test order ids start with
`ORDTST`. Unpaid test orders expire after one hour at Mercado Pago.

The webhook needs a public HTTPS address even in test mode; without one,
payments are still recorded by the return page and by the periodic check.

## Known limits of version 0.1

- **No fees**: the Orders API does not return Mercado Pago's commission or
  withholdings. The gross amount is recorded; the fees must be booked from
  Mercado Pago's reports.
- **No refunds or chargebacks**: a refund made at Mercado Pago is not
  reflected in Dolibarr (the status of the order is updated and a warning
  is logged, nothing else).
- **ARS only**, and only documents in the company currency.
- Only invoices (draft or validated, not credit notes) and orders; the
  whole amount still due is requested (the `amount=` parameter of the
  payment link is ignored).
- One item per payment ("company + document ref"), no itemised lines.
- No e-mail is sent by the module; Mercado Pago notifies the buyer.
- Notifications are accepted whatever the age of their signed timestamp:
  a replayed notification only makes the module read the order again, and
  whether Mercado Pago re-signs its retries is not documented. The hidden
  constant `MPAGO_WEBHOOK_TOLERANCE` (seconds) sets a limit.
- A payment received for an order that was cancelled meanwhile is still
  recorded (invoice, payment, bank line) and listed on the setup page
  under the payments that need attention. The cancelled order itself is
  left as it is (not classified billed): somebody has to decide between
  delivering and refunding.
- On Dolibarr 22, PHP raises a notice at each call to Mercado Pago
  (`CURLOPT_SSL_VERIFYHOST no longer accepts the value 1`): Dolibarr 22's
  `getURLContent()` passes a boolean there. The certificate is still
  verified.

## Tests

The tests need the development environment of the workspace (`dev/`): a
running Dolibarr instance and the QA tools.

```sh
# php -l, phpcs (Dolibarr ruleset), PHPStan level 10, PHPUnit
dev/gate.sh dolibarr-mpago
DOLI_VERSION=23.0.4 dev/gate.sh dolibarr-mpago
DOLI_VERSION=22.0.5 dev/gate.sh dolibarr-mpago

# HTTP-level checks through the web server (commits test documents to the instance)
dolibarr-mpago/test/http/run.sh
DOLI_VERSION=23.0.4 HTTP_PORT=8081 dolibarr-mpago/test/http/run.sh
```

PHPUnit runs against a fake Mercado Pago API (`test/fake/router.php`,
started on a free local port by the tests) inside database transactions
that are rolled back. Nothing in the tests talks to Mercado Pago. The
hidden constant `MPAGO_API_BASE_URL` is what points the module at the
fake; it must be absent in production.

## Licence

GPL-3.0-or-later. See [COPYING](COPYING).
