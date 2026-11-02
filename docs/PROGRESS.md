# Implementation progress vs. blueprint (v2)

## Phase 0 — Foundation: DONE (with the gaps listed below)

| Area | Status |
|------|--------|
| Laravel 12 / Inertia / Vue 3 / TS / Tailwind 4 / Vite 7 | Done |
| Auth (register, login, logout), login + register rate limits | Done |
| Tenancy: `tenants`, membership, roles, permissions, `BelongsToTenant` fail-closed scope, verified tenant switching | Done |
| Roles/permissions catalogue (spec §4, incl. `products.images.*`, cost/profit hiding via `profit.view`/`products.costs.view`) | Done |
| Audit log (append-only, redacts secrets) | Done |
| Super admin: CLI-only grant, no implicit tenant access | Done (portal UI is Phase 5) |
| Request ID + structured log context, CSP with nonce, HSTS, hardening headers | Done |
| Responsive shell: bottom tabs <640, rail 640–1023, sidebar ≥1024, safe-area, dvh, 44px targets | Done |
| i18n (en + bn keys) | Done (Bangla full translation by Phase 2) |
| PWA: manifest, icons (any/maskable/mono/apple-touch), screenshots, SW (default-deny, network-only navigation, offline fallback), Android install banner, iOS helper, update prompt deferred while busy | Done |
| ConnectionStatus (probe + backoff + BroadcastChannel), OfflineGuard, drafts, purge on logout/switch/401/419 | Done |
| No Background Sync (CI lint `npm run lint:sw` + e2e check) | Done |
| Tests: 36 Pest, 28 Vitest, 38 Playwright (Pixel 7 + desktop Chromium) | All passing |

### Known gaps / not verified in this environment
- **iPhone/iPad (WebKit) e2e and real-device checks have NOT run** (WebKit not installable here). Projects are configured.
- **MySQL has not been exercised** (no credentials; dev/tests run on SQLite). Run migrations on MySQL 8 before pilot.
- PWA screenshots and icons are generated placeholders; replace with real brand assets/screens.
- Email/phone verification, password reset, staff invitations and the Roles UI are not built yet (needed before pilot; staff invites belong with Phase 0 follow-up).
- Manifest `shortcuts` list only Dashboard; New order/Orders/Products/Shipments are added as those modules ship.
- Web Push, Lighthouse CI budgets, visual regression and axe scans are scaffolded for later phases, not yet automated.
- iOS splash images are deferred (decision #15).

## Phase 1 — Catalog and Inventory: DONE (with the gaps listed below)

| Area | Status |
|------|--------|
| Categories, brands, products (simple/variable), variants; SKU unique per tenant across products AND variants (form + service-level guard) | Done |
| Cost hiding: `products.costs.view` gates read and write of purchase cost, inventory cost/valuation | Done |
| Stock ledger: immutable movements, weighted-average cost (bcmath), row-locked balance cache, reservations, no overselling | Done |
| Inventory screen: units, low-stock filter, adjust by delta or counted quantity (reason required, audited), history | Done |
| Product CSV/XLSX import, **data only** (IMG-I1..I6): async parse, mapping (saved per tenant), validation, preview, duplicate policy (skip / update / new SKU), chunked idempotent write, retry failed rows, error report with formula-injection protection, plan row limit | Done |
| Image manager (IMG-1..12): add (picker, camera, drag-drop), per-file progress/cancel/retry, replace, edit (alt, caption, variants, rotate, crop), primary, delete (single/multi), reorder (buttons + desktop drag), missing-images view + "next" shortcut, 409 on stale reorder/primary, limits, quota, audit | Done |
| Image pipeline: content-based validation (SVG/GIF/renamed files rejected), EXIF orientation applied then metadata dropped, WebP derivatives (detail ≤2000, card 400, thumb 150), randomized tenant-scoped paths, authenticated delivery | Done |
| PWA: thumb/card cache (capped, allowlisted, purged on logout) | Done |
| Dashboard: low-stock and missing-images metrics with drill-down | Done |
| Tests: 127 Pest, 35 Vitest, 52 Playwright (Pixel 7 + desktop Chromium) | All passing |

### Known gaps / not verified
- **Touch long-press drag-to-reorder is not built.** Touch users reorder with the always-visible Move earlier/later buttons (IMG-5 accessible alternative); desktop has drag-and-drop.
- **HEIC** is wired (Imagick reports HEIC support on this host) but has not been exercised with a real iPhone photo.
- **Concurrency/stress**: stock row-locking and image-upload/import load are not stress-tested (SQLite has no real row locks). Run on MySQL 8 with parallel workers before pilot.
- **MySQL still unverified** (schema, raw union query in inventory).
- Original uploads are retained as-is for re-processing (private, never served), so they still contain EXIF; derivatives are clean. A purge/retention job for originals and import files is not built.
- No warehouses table yet (`location` is implicit); purchase receiving, suppliers and transfers are Phase 2+ (service supports `purchase`/`return` types, no UI).
- "Require image before activation" and per-tenant image limits are read from `tenants.settings` but there is no settings screen yet.
- IMG-6 fallback (variant without its own image shows the product primary) is data-ready; nothing renders it until order/storefront screens exist.
- New Phase 1 UI strings are English only (Bangla falls back to English until Phase 2 translation).
- ImageManager component has unit tests for its reorder helpers and e2e coverage, not component-mount unit tests.
- Queue worker needs `--memory=512` (GD decoding up to 6000×6000 px).

## Phase 2 — Orders and Customers: DONE (kept deliberately lean; minimal testing as requested)

| Area | Status |
|------|--------|
| Customers: find-or-create by normalised phone (original kept), list/search, detail with order stats, tap-to-call / WhatsApp, edit | Done |
| Orders: stepped mobile creation (customer → items → delivery/charges → review), sticky total/COD summary (labelled estimate), server-confirmed totals | Done |
| Financial snapshot: price/name/discounts/charges stored per order; formula `total = subtotal − item discount − order discount + shipping + other + tax`, `COD = total − advance` (bcmath) | Done |
| Lifecycle with explicit transition rules, per-transition permission (cancel needs `orders.cancel`), status history, audit | Done |
| Stock wiring (decision #3): reserve on confirm, deduct on ship, release on cancel; confirm refused if stock is short | Done |
| Idempotency key per submit intent; replay returns the original order; per-tenant sequential order numbers | Done |
| Uncertain-result handling: dropped submit shows "could not confirm", link to orders, safe "Submit again" | Done |
| Local unsent draft for the order form (user+tenant scoped, never auto-submitted) | Done |
| Invoice page with print stylesheet; "Print / save PDF" uses the browser print/share sheet | Done |
| Bulk order import on the shared import engine: grouped by external order ID, SKU-only matching, all-or-nothing groups, duplicate external IDs skipped, error report, retry | Done |
| Mobile "More" tab (customers, inventory, imports, workspaces) | Done |
| Tests added: 12 Pest (order math, snapshot, idempotency, lifecycle+stock, isolation, permissions, import), 4 Vitest, 2 Playwright | Passing (139 PHP, 39 Vitest, 54 e2e total) |

### Known gaps
- Testing is intentionally minimal: no concurrency test for order numbering, no e2e for status buttons or invoice printing, no browser test of order import.
- Not built: multiple addresses per customer (one latest address is kept), Facebook/API sources, invoice share-as-PDF file generation (uses print dialog), packing slips/labels, `delivery_failed`/return restocking (returns module), courier columns in order import (Phase 3), tax is a manual amount (no tax config).
- Stock reservations are per order line; partial shipment and partial returns are not supported yet.
- New strings are English only (Bangla keys still missing for Phase 1–2 screens).

## Phase 0/1 gaps closed (this round)

| Item | Status |
|------|--------|
| Password reset: emailed single-use token, same response for unknown emails (no enumeration), throttled, signs out every other session | Done |
| Email verification: verification mail on signup; enforcement is a launch policy (`REQUIRE_EMAIL_VERIFICATION`, default off) | Done |
| Staff invitations: emailed single-use link (only the token hash is stored), 7-day expiry, revoke, re-invite replaces; new people create an account from the link; existing users must be signed in as the invited address | Done |
| Team screen: change role, remove member; only owners can grant/touch owners; a workspace can never lose its last owner; removal takes effect on the next request | Done |
| Roles screen (read-only matrix of built-in roles) | Done |
| Workspace settings screen: business details, "require image before activation", max images per product, lakh grouping preference | Done |
| Tests added: 13 Pest. Totals now 152 PHP, 39 Vitest, 56 Playwright, all passing | Done |

Notes: mail uses Laravel's mailer (`MAIL_MAILER=log` by default; configure SMTP before pilot). Phone verification is not built. Custom (non-built-in) roles and the lakh preference is stored but not yet applied to money display.

## Phase 4 — Accounting: DONE (core ledger, posting, reports) + design refresh

| Area | Status |
|------|--------|
| Double-entry ledger: every entry balances, DECIMAL strings only, immutable entries and lines, one entry per business event (idempotent), corrections only by reversal (original kept), closed periods block posting | Done |
| Default per-tenant chart of accounts (assets, liabilities, equity, income incl. contra, COGS, expenses) + cash/bank/MFS accounts each with their own ledger account | Done |
| Posting rules: sale on dispatch (advance cash, COD receivable, discounts contra, sales, delivery income, tax); COGS at weighted-average cost as a separate entry; opening stock and adjustments keep Inventory = stock valuation; paid inventory purchases (Dr Inventory, not COGS); returns reverse revenue only | Done |
| Expenses (paid, void by reversal), owner capital and drawings (equity, never revenue), COD settlement (delivered ≠ paid; partial settlements; courier fee expensed once), period close | Done |
| Reports computed fresh from the ledger: trial balance, profit & loss, balance sheet (A = L + E check), general ledger with running balance, direct cash flow, COD receivable | Done |
| Permissions: profit-bearing reports need `profit.view`; ledger/journal need `accounting.view`; posting needs `accounting.post`; period close owner-only | Done |
| Idempotency keys on expenses, capital/drawings and COD settlements | Done |
| `php artisan fc:accounting-backfill` posts history that predates the ledger | Done |
| Tests: 20 accounting Pest tests incl. the spec's worked example (2,000 sale + 80 delivery + 100 advance, cost 900), COD scenario, returns, equity vs income, purchase vs COGS, reconciliation, isolation | Passing |

### Design refresh
New design system with semantic tokens, light / dark / system theme (toggle in the top bar; applied before first paint; survives logout), glass top bar, grouped sidebar, tablet rail, floating mobile tab bar, redesigned sign-in, new dashboard (KPI cards with trend and period-over-period delta, sales area chart, order-pipeline donut, profit breakdown, needs-attention list, top sellers, recent orders) built on dependency-free accessible SVG charts. Dashboard metric definitions are documented in `DashboardController`. Workspace "lakh/crore" digit grouping now applies to all money display.

Totals now: 183 PHP, 39 Vitest, 64 Playwright (Pixel 7 + desktop Chromium), all passing; first-load JS ≈ 95 KB gzip.

### Accounting extras (added after the core)
Loans (liability, not income; repayments split into principal and interest expense; `Interest expense` account), expense receipt attachments (photo/PDF, content-validated, private, random names, sandboxed delivery, camera capture on phones), CSV export for every report (formula-injection safe, UTF-8 BOM for Bangla), and three more reports: expenses & advertising spend (by category, month, vendor, ad platform), owner equity (capital, drawings, net funding, loans), tax/VAT summary (marked as a draft for accountant review). Fixed along the way: the global CSP header no longer overwrites a stricter per-response policy. Tests: 193 PHP, 39 Vitest, 66 Playwright, all passing.

### Known gaps (accounting)
- Not built: supplier payables and purchase orders, payment-gateway clearing, depreciation entries, budgets, bank reconciliation, PDF report export (CSV and browser print exist), manual journal entry screen, custom chart-of-accounts editing, receivable-aging report, sales-summary report.
- Courier COD is settled manually until the Steadfast booking/webhook work (Phase 3) lands; return accounting reverses revenue only (restocking is a manual inventory adjustment until the Returns module exists).
- Dashboard aggregates orders in PHP per request (fine for pilot volumes; move to SQL aggregates/summary tables at scale). Not yet run on MySQL.
- Bangla strings for the new screens fall back to English. Touch long-press image reorder still pending.

## Phase 3 — Courier integrations (Steadfast): DONE except webhooks; REDX waits on docs

Done: connection layer (encrypted keys, test, rotate, lockout guard), **booking from the order page**, shipments list, tracking, status refresh, safe recovery after timeouts, scheduled status sync.

- **Send to courier:** button on a confirmed order (online-only, never auto-retried). Payload per the guide: `invoice` = our order number (the provider refuses duplicate invoices), name, phone normalised to 01XXXXXXXXX, address + area, `cod_amount`, optional note. The characters the provider strips (`{ } ; < > $`) are removed and lengths trimmed first.
- **No duplicate parcels:** the shipment row is committed in state `booking` *before* the network call (in-flight lock), so a double click or a second tab cannot send twice. Timeout / 5xx / unreadable reply => `booking_unknown`: nothing is retried and booking is blocked until the user presses "Check status" (reconcile). A definite rejection (422, 401, 403, 429, or an error reported inside a 200) => `failed`, and a corrected retry reuses the same invoice.
- **Reconcile (verified live):** an unknown invoice answers the same plain 401 "Unauthorized Access" as bad keys, so a 401 is ambiguous. "Not booked" is concluded only when `/get_balance` proves the keys valid AND at least 90 s have passed (the provider's cache window); otherwise the shipment stays unresolved.
- **Status:** manual "Refresh status" plus `php artisan fc:courier-sync` every 15 minutes (scheduler) for unfinished parcels. The order moves to delivered only when the courier says `delivered` and the order is already `shipped`; `cancelled` => delivery failed; `*_approval_pending` is shown but never acted on (per the guide). Delivered is not COD paid: settlement stays under Accounting → COD.
- Re-shipping after a cancelled parcel uses a new invoice (`ORD-000001-2`).
- Tests: 30 Pest (payload, headers, double click, timeouts, ambiguity, sync, permissions, isolation, secrets) and 2 Playwright against a fake Steadfast server (`tests/e2e/fake-steadfast.mjs`). Totals: 223 PHP, 39 Vitest, 72 Playwright.

### Assumptions to verify with the first real booking
The guide excerpt does not show response bodies. Parsing expects `consignment.consignment_id`, `consignment.tracking_code` and `consignment.status` for create, and `delivery_status` for status lookups. Anything else is treated as `booking_unknown` / unreadable and never as a guessed success, so a different shape cannot create wrong data, but the first live booking may need the parser adjusted. One live read-only probe confirmed the 401 behaviour; no parcel was created during development.

### Not built / open
- **Webhooks** (need the guide's webhook section: URL registration, auth/signature, payload). Polling covers it meanwhile.
- The guide has no cancel endpoint (only return requests). Return requests, pickup requests, bulk booking, balance display and label printing are not built.
- Booking does not change the order status; the user still marks the order shipped (that posts revenue and COGS). If one Steadfast account is shared by several workspaces, identical order numbers would collide at the provider.
- **REDX** needs official docs and test credentials.
