# Indexes

> **Purpose.** Every index in the schema, the query that justifies it, and the
> indexes that were deliberately *not* created.
>
> **The rule: an index with no named query is deleted.** Each one below costs
> write throughput and disk, and `stock_transactions` takes roughly eight million
> inserts a year.

**Status:** ✅ Implemented across the 40 migrations.

---

## 1. Principles

1. **Every foreign key is indexed.** MySQL requires it for the constraint anyway,
   and it is needed for the join.
2. **Composite order is equality, then range, then sort.** A composite index can
   only be used left-to-right, so `(branch_id, business_date)` serves
   "this branch, this month" and "this branch" — but never "this month" alone.
3. **Branch-scoped tables lead with `branch_id`.** Every operational query filters
   on it, so it belongs first in almost every composite.
4. **Covering indexes only where measured.** None are added speculatively.
5. **Low-cardinality columns are never indexed alone.** `is_active` on its own
   selects half the table; it appears only as part of a composite.

---

## 2. Index catalogue, by query

### 2.1 The hot path

| Index | Table | Columns | Query it serves |
|---|---|---|---|
| `idx_kt_branch_status_queued` | `kitchen_tickets` | `(branch_id, status, queued_at)` | **The KDS poll.** Every kitchen screen, every five seconds — the highest-frequency query in the system. Equality on branch and status, then ordered by queue time. |
| `uq_inventories_branch_ingredient` | `inventories` | `(branch_id, ingredient_id)` | Stock check during a sale. A point lookup taken under `FOR UPDATE`; this must never be a scan. |
| `idx_orders_branch_status` | `orders` | `(branch_id, status)` | The active-order list on the POS. |
| `idx_products_active_available` | `products` | `(is_active, is_available)` | The POS menu grid, with the `branch_product` primary key. |
| `ft_products_name` | `products` | FULLTEXT `(name, description)` | POS product search. `LIKE` with a leading wildcard cannot use an index; FULLTEXT can. MySQL only. |

### 2.2 Reporting

| Index | Table | Columns | Query it serves |
|---|---|---|---|
| `idx_orders_branch_business_date` | `orders` | `(branch_id, business_date)` | The daily sales report. `business_date` rather than `created_at`, because the trading day is branch-local and rolls at 04:00. |
| `idx_orders_user_business_date` | `orders` | `(user_id, business_date)` | Cashier shift report. |
| `idx_orders_placed_at` | `orders` | `(placed_at)` | Time-series and hourly-trend reporting. |
| `idx_payments_branch_paid_at` | `payments` | `(branch_id, paid_at)` | Takings by branch and period. `branch_id` is denormalised onto payments precisely so this needs no join. |
| `idx_payments_method_status` | `payments` | `(method, status)` | Payment-mix report and unsettled-payment sweeps. |
| `idx_st_branch_ingredient_occurred` | `stock_transactions` | `(branch_id, ingredient_id, occurred_at)` | **The main ledger path.** Movement report, and the balance rebuild reconciliation depends on. |
| `idx_st_type_occurred` | `stock_transactions` | `(type, occurred_at)` | Wastage and adjustment reporting. |
| `idx_expenses_branch_date` | `expenses` | `(branch_id, expense_date)` | Expense report by period. |
| `idx_refunds_branch_date` | `refunds` | `(branch_id, refunded_at)` | Refund report. |

### 2.3 Lookups and traversal

| Index | Table | Columns | Query it serves |
|---|---|---|---|
| `uq_customers_phone` | `customers` | `(phone, deleted_at)` | Customer lookup at the POS — a cashier types a phone number. |
| `uq_customers_code` | `customers` | `(code)` | Membership-card scan. |
| `idx_lt_customer_created` | `loyalty_transactions` | `(customer_id, created_at)` | Points statement, and the nightly balance reconciliation. |
| `idx_lt_type_expires` | `loyalty_transactions` | `(type, expires_at)` | The daily points-expiry job. |
| `idx_st_reference` | `stock_transactions` | `(reference_type, reference_id)` | "What did this order consume?" |
| `idx_audit_auditable` | `audit_logs` | `(auditable_type, auditable_id, created_at)` | The audit trail for one record. |
| `idx_audit_user_created` | `audit_logs` | `(user_id, created_at)` | "What did this user do?" |
| `idx_audit_event_created` | `audit_logs` | `(event, created_at)` | "Show every void this month." |
| `idx_notifications_notifiable` | `notifications` | `(notifiable_type, notifiable_id, read_at)` | The unread badge. `read_at` last, so unread filtering is covered. |
| `idx_transfers_from_status` and `idx_transfers_to_status` | `stock_transfers` | `(from_branch_id, status)`, `(to_branch_id, status)` | Pending transfers for a branch. **Two indexes, not one**, because "involving my branch" is an `OR` across two columns and no single composite can serve it. |
| `idx_oi_order` | `order_items` | `(order_id)` | Loading an order's lines — the most common join in the system. |
| `idx_osh_order_created` | `order_status_histories` | `(order_id, created_at)` | Order timeline. |

---

## 3. Unique constraints

Uniqueness is a constraint first and an index second. The scope of each was a
real decision.

| Constraint | Scope | Why this scope |
|---|---|---|
| `uq_users_email` | **Global, permanent** | An email address identifies a person. Even after a soft delete it is not reissued: order history references the user, and reassigning the address would make the audit trail lie. |
| `uq_users_employee_code` | Global | |
| `uq_branches_code` | **Global, permanent** | The code is embedded in historical order numbers (`PP1-20260905-0042`). Reusing it would make two different branches indistinguishable in old data. |
| `uq_orders_branch_number` | **Per branch** | `(branch_id, order_number)`. Each branch runs its own daily sequence; a globally unique order number would need a central allocator and become a bottleneck. |
| `uq_products_sku` | **Global, reusable after deletion** | `(sku, deleted_at)`. See §3.1 for why SKU is global rather than per branch. |
| `uq_variants_sku` | Global, reusable | |
| `uq_variants_product_name` | Per product | No two "Large" variants of one product. |
| `uq_categories_slug`, `uq_products_slug` | Global, reusable | URL identity. |
| `uq_ingredients_name`, `uq_ingredients_code` | Global, reusable | Ingredients are shared master data; only the *stock* is per branch. |
| `uq_inventories_branch_ingredient` | Per pair | One balance row per ingredient per branch — the constraint that makes double-counting stock impossible. |
| `uq_stations_branch_code` | Per branch | Every branch may have a `GRILL`. |
| `uq_daily_sequences` | `(branch_id, scope, sequence_date)` | The uniqueness that makes gapless numbering atomic. |
| `uq_payments_number`, `uq_refunds_number`, `uq_expenses_number`, `uq_transfers_number` | Global | Document identity. |
| `uq_orders_idempotency`, `uq_payments_idempotency`, `uq_refunds_idempotency` | Global | The database-level duplicate-submission guard. |
| `uq_recipe_items` | `(recipe_id, ingredient_id)` | An ingredient once per recipe; twice would make the quantity ambiguous. |
| `uq_kt_order_station` | `(order_id, kitchen_station_id)` | One ticket per order per station. |
| `uq_sti_transfer_ingredient` | `(stock_transfer_id, ingredient_id)` | One line per ingredient per transfer. |

### 3.1 Why SKU is global, not per branch

The same product sold at two branches is **one product with one SKU**. That is
what makes "how much Cappuccino did the company sell last month?" a single
`GROUP BY product_id` rather than a string-matching exercise across branch
variants.

Per-branch differences are handled without touching the SKU: `branch_product`
carries the price override and the availability flag. A branch that does not sell
an item simply has `is_available = 0`.

A per-branch SKU would be right only if branches had genuinely independent
catalogues — different suppliers, different recipes, no shared reporting. That is
a different product, not this one.

### 3.2 The soft-delete problem, and the two answers

A plain `UNIQUE (sku)` on a soft-deleting table permanently blocks reuse of a
deleted product's SKU, which operators find maddening.

- **Reusable:** `UNIQUE (sku, deleted_at)`. MySQL treats each `NULL` as distinct,
  so any number of deleted rows may share a value while at most one live row
  holds it. Used for SKUs, slugs and ingredient names.
- **Permanent:** a plain `UNIQUE`. Used for emails, branch codes and document
  numbers, where reuse would corrupt the historical record.

### 3.3 Uniqueness over a nullable column

`UNIQUE (user_id, role_id, branch_id)` does **not** prevent granting the same
global role twice, because MySQL treats each `NULL` as distinct. Three tables
solve this with a stored generated column:

| Table | Generated column | Enforces |
|---|---|---|
| `role_user` | `branch_key = IFNULL(branch_id, 0)` | One grant per (user, role, branch), the global grant included |
| `settings` | `branch_key = IFNULL(branch_id, 0)` | One value per (branch, key), the global default included |
| `cash_drawer_sessions` | `open_guard`, null unless the status is open | At most one open drawer per user per branch (C15). MySQL has no partial index, and this is the standard substitute. |

---

## 4. Indexes deliberately not created

| Candidate | Why not |
|---|---|
| `orders.status` alone | Six values across 800 000 rows. Always queried with `branch_id`, which leads the composite. |
| `products.is_active` alone | Low cardinality, and already the leading column of a composite. |
| `order_items.created_at` | Order lines are only ever reached through `order_id`. |
| A covering index for the sales report | Not yet measured. A speculative covering index on a write-heavy table is a cost with no proven benefit. |
| `stock_transactions.performed_by` | No screen lists movements by operator. Add it when one exists. |
| Full-text on customer names | Lookup is by phone or membership code; nobody searches customers by fuzzy name at the till. |

---

## 5. Naming, and one honest exception

Convention: `idx_<table>_<cols>`, `uq_<table>_<cols>`, `fk_<table>_<col>`.

**Exception:** the three framework tables (`users`, `sessions`,
`password_reset_tokens`) keep Laravel's generated index names, such as
`users_email_unique`. Renaming them would mean editing a framework migration for
cosmetic gain and would break the assumptions of any future Laravel upgrade that
touches them. Every index RestaurantOS adds follows the convention, including
those added to `users`.

---

## 6. Growth, and what changes at scale

At 15 branches and 800 000 orders a year:

| Table | Yearly rows | Plan |
|---|---|---|
| `stock_transactions` | ~8 M | 🔵 Proposed: `RANGE` partition by year of `occurred_at`. |
| `audit_logs` | ~6 M | 🔵 Proposed: partition by month; export and drop beyond 24 months. |
| `order_items` | ~3 M | No partitioning — always reached by `order_id`. |
| `orders` | ~800 k | No partitioning. Retained indefinitely. |
| `notifications` | ~200 k | Delete read notifications older than 90 days. |

🔵 marks **proposed, not implemented.** MySQL requires every unique key on a
partitioned table to include the partition column, so partitioning `audit_logs`
means changing its primary key to `(id, created_at)` — a planned migration, not a
casual one.

---

## 7. Verifying an index is actually used

Indexes rot: a query changes, the index stops matching, and nothing fails — it
just gets slower. The check is `EXPLAIN` on the named queries in §2, asserted in
CI, failing on a full scan of any large table
([23-testing-strategy.md](../23-testing-strategy.md)).

Two MySQL-only notes for that suite: `EXPLAIN` output differs on SQLite, and
FULLTEXT does not exist there at all, so these assertions must run against MySQL.
