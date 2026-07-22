# Native Xero Integration, scope + build plan

Goal: when a wholesale order is paid, its invoice posts to the customer's Xero
automatically, with no third-party connector and no monthly fee. Built directly
into this plugin so it's owned and hands off with the rest of Holly's stack.

This is the "intensive" path we chose over a paid connector (MyWorks etc.). The
Xero-compat meta layer (`class-xero-compat.php`) already does half the work
(NET terms, due dates, wholesale discount on the order); this plan is the actual
Xero connection on top of it.

---

## The key unblock (faster than it sounds)

We do NOT need Holly's live books to build and validate this. Two steps:

1. **LP registers ONE Xero app** at developer.xero.com (free Xero dev account):
   OAuth2 app → `client_id` + `client_secret`, redirect URI pointed at the
   plugin's callback. This is the connector software; every client org connects
   to it. Do this once. **This is the only thing gating the build.**
2. **Test against a Xero "Demo Company"** (every Xero login has a free, resettable
   demo org). Build + validate the entire flow there, real invoices, real
   payments, zero risk to anyone's real books.
3. **Holly connects her real org at the very end** (one "Connect to Xero" click,
   or we do it together on the call). Her access is the LAST step, not a blocker.

So: LP registers the app → I build + test on the Demo Company → Holly connects.

---

## Architecture

### OAuth 2.0 (authorization code + refresh)
- One app, per-client org authorization (standard connector model).
- Scopes: `openid profile email accounting.transactions accounting.contacts offline_access`.
- **Connect:** "Connect to Xero" button → Xero authorize URL → consent → callback.
- **Callback:** `admin-post` action receives `code`, exchanges for access +
  refresh tokens, calls `/connections` to get the `tenant_id` (the org), stores
  all three encrypted (`SLW_Encryption`) in options.
- **Refresh:** access token lives 30 min; refresh token rotates each use, valid
  60 days. A `get_valid_token()` helper checks expiry, refreshes, re-stores.
  A weekly keep-alive refresh guards against the 60-day idle expiry.
- **Secrets:** `client_secret` in a `wp-config` constant (preferred) or encrypted
  option; never in the repo.

### Sync events
- **Order → Xero invoice.** On the configured trigger (default: order status
  `processing`/`completed`), find-or-create the Xero **Contact** (by email, else
  business name), then POST an ACCREC **Invoice**:
  line items from the order (description, qty, unit amount, account code, tax),
  `Date`, `DueDate` (from `_slw_net30_due_date`), `Reference` = WC order number,
  `Status` = AUTHORISED (configurable to DRAFT). Store the returned `InvoiceID`
  in order meta (`_slw_xero_invoice_id`) to update-not-duplicate on re-sync.
- **Payment → Xero payment.** On `woocommerce_order_status_completed` (or payment
  complete), POST a **Payment** against that invoice so it shows paid in Xero.
  This is Holly's exact ask: "when they pay, it adds to my account automatically."
- **Idempotency:** keyed on `_slw_xero_invoice_id`; re-runs update, never
  double-post. All calls no-op cleanly if not connected.

### Field mapping (reuses what we already write)
| WooCommerce / SLW | Xero invoice |
|---|---|
| customer email / `slw_business_name` | Contact (find or create) |
| order line items (wholesale price) | LineItems (UnitAmount, Quantity) |
| `_slw_net30_due_date` | DueDate |
| order number | Reference / InvoiceNumber |
| configured sales account code | LineItem.AccountCode |
| shipping line | LineItem (shipping account code) |

### Settings page (Wholesale → Xero)
Connect / disconnect + connected-org name, default sales account code, shipping
account code, tax handling, invoice status (Draft vs Authorised), and an
"auto-apply payment when paid" toggle. Connection status + last-sync log.

### Reliability
- Xero limits: 60 calls/min, 5,000/day per tenant. Wholesale volume is far under,
  but wrap calls with retry/backoff on 429 and log failures to the audit log so a
  transient error is visible, not silent.

---

## Build phases

1. **Auth + settings** — app config, Connect/callback, token store + refresh,
   settings page with connection status. (Test: connect the Demo Company.)
2. **Invoice sync** — contact find/create, invoice build from order, store
   InvoiceID, update-on-resync. (Test: place an order → see the invoice in Demo.)
3. **Payment sync** — post payment on paid, mark invoice paid. (Test: mark order
   paid → invoice shows paid in Demo.)
4. **Config + hardening** — account codes, tax, status, auto-pay toggle,
   retry/backoff, audit logging, disconnect flow.
5. **Go live** — Holly connects her real org (click or on the call); watch the
   first real order round-trip.

The pure order→invoice mapping (phase 2) is unit-testable with no credentials, so
that lands first alongside the auth scaffolding.

---

## Who does what
- **LP (once, unblocks everything):** register the Xero app → `client_id` +
  `client_secret` + redirect URI.
- **Me:** build phases 1-4, test end-to-end on a Xero Demo Company.
- **Holly (last step):** click "Connect to Xero" and authorize her org, or we do
  it together on the quick call. Needs her Xero login only at that final step.

## Risk / honesty
External API with OAuth token lifecycle and live financial data, so it gets built
and proven on the Demo Company before Holly's real books are ever touched. The
"no promises" in her email stands until phase 5 round-trips cleanly.
