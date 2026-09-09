# Business Constraints

> **Purpose.** Every rule the data must obey, where it is enforced, and why it is
> enforced there rather than somewhere else.
>
> The guiding principle: **the database enforces what it can, the application
> enforces the rest, and nothing important is enforced only by the user
> interface.** A background job, a console command or a manual fix will bypass
> the UI and the ORM sooner or later.

**Status:** ✅ Implemented. 43 CHECK constraints, 40 unique constraints, 88 foreign
keys and 8 immutability triggers, counted from the migrations.

---

## 1. Where each rule lives

| Layer | Catches | Examples |
|---|---|---|
| **Database constraint** | Everything, including direct SQL | Negative money, zero-quantity ledger rows, a transfer to the same branch, an unknown status value |
| **Database trigger** | Any `UPDATE`/`DELETE` on a ledger | Tampering with `stock_transactions` or `audit_logs` |
| **Model event** | Ordinary application mistakes | `AppendOnly` refusing `save()` on a ledger row |
| **Service / policy** | Rules needing context SQL does not have | Approver is not the creator, discount ceiling per role, state-machine transitions |
| **Form request** | Shape and type of input | Required fields, formats, references that exist |

---

## 2. Database-enforced constraints

### 2.1 Status vocabularies

Every status column is `VARCHAR(30)` with a `CHECK`, generated from the matching
PHP enum in `app/Enums`, so the database and the application cannot drift apart.

| Constraint | Column | Values |
|---|---|---|
| `chk_orders_status` | `orders.status` | pending, accepted, preparing, ready, completed, cancelled |
| `chk_orders_payment_status` | `orders.payment_status` | unpaid, partially_paid, paid, refunded, partially_refunded |
| `chk_orders_order_type` | `orders.order_type` | dine_in, takeaway, delivery |
| `chk_order_items_status` | `order_items.status` | pending, preparing, ready, served, voided |
| `chk_payments_method` | `payments.method` | cash, card, qr, bank_transfer |
| `chk_payments_status` | `payments.status` | pending, captured, failed, voided, refunded, partially_refunded |
| `chk_stock_transactions_type` | `stock_transactions.type` | stock_in, stock_out, adjustment, transfer_in, transfer_out, sale_deduction, sale_reversal, wastage, count_correction |
| `chk_stock_transfers_status` | `stock_transfers.status` | draft, pending, approved, in_transit, received, partially_received, rejected, cancelled |
| `chk_expenses_status` | `expenses.status` | draft, pending, approved, rejected, paid |
| `chk_kitchen_tickets_status` | `kitchen_tickets.status` | queued, preparing, ready, served, cancelled |
| `chk_loyalty_transactions_type` | `loyalty_transactions.type` | earn, redeem, adjust, expire, reverse |
| `chk_units_family` | `units.family` | mass, volume, count |
| `chk_cash_drawer_sessions_status` | `cash_drawer_sessions.status` | open, closed |
| `chk_notifications_severity` | `notifications.severity` | info, warning, critical |
| Plus | `discount_type`, `settings.type`, `loyalty_rules.earn_basis`, `daily_sequences.scope`, `expenses.payment_method`, `refunds.method`, `kitchen_ticket_items.status` | |

**Why not MySQL `ENUM`:** adding a value to an `ENUM` requires an `ALTER TABLE`
that locks the table. On an 800 000-row `orders` that is an outage. A `VARCHAR`
with a `CHECK` is alterable, readable, and shared with the application layer.

### 2.2 Arithmetic and sanity

| Constraint | Rule | What it prevents |
|---|---|---|
| `chk_orders_amounts` | subtotal, discount, tax, grand total, paid and refunded totals all `>= 0` | Negative money anywhere on an order |
| `chk_orders_discount_bound` | `discount_amount <= subtotal` | A discount larger than the order |
| `chk_order_items_quantity` | `quantity > 0` | A zero or negative line |
| `chk_order_items_discount_bound` | `line_discount_amount <= line_subtotal` | A line discounted below zero |
| `chk_payments_amount` | `amount > 0` | A zero payment |
| `chk_payments_tendered` | `tendered_amount IS NULL OR >= amount` | Change owed on money never handed over |
| `chk_payments_refunded_bound` | `refunded_total <= amount` | Refunding more than was taken |
| `chk_expenses_total` | `total_amount = amount + tax_amount` | A total that does not add up |
| `chk_customers_points` | `loyalty_points_balance >= 0` | A negative points balance |
| `chk_stock_transactions_nonzero` | `quantity_change <> 0` | A movement that moved nothing — a bug, not a fact |
| `chk_stock_transfers_branches` | `from_branch_id <> to_branch_id` | **A branch transferring stock to itself** (FR-TRF-001) |
| `chk_recipe_items_wastage` | `0 <= wastage_percent < 100` | A recipe that consumes infinite stock |
| `chk_units_conversion_factor` | `> 0` | Division by zero during conversion |
| `chk_recipes_yield` | `yield_quantity > 0` | The same |

### 2.3 Ledger immutability

`stock_transactions`, `loyalty_transactions`, `audit_logs` and
`order_status_histories` are append-only, defended three times over:

1. **`AppendOnly` trait** — model events throw on `updating` and `deleting`.
2. **Database grant** — the application user has only `SELECT` and `INSERT` on
   these tables (deployment concern).
3. **`BEFORE UPDATE` / `BEFORE DELETE` triggers** — `SIGNAL SQLSTATE '45000'`,
   which holds even against a direct console session using the migration user.

Corrections are made by inserting a compensating row, so both the mistake and the
fix stay visible.

---

## 3. Application-enforced rules

Rules the database cannot express. Every one needs a test.

| ID | Rule | Enforced in | Why not in the database |
|---|---|---|---|
| C1 | Category depth <= 2 | `CategoryService` | Recursive depth is not expressible in a CHECK |
| C2 | Exactly one active recipe per (product, variant) | `RecipeService` | Partial uniqueness; MySQL has no filtered index |
| C3 | Exactly one active loyalty rule | `LoyaltyRuleService` | The same |
| C4 | Exactly one default variant per product | `ProductVariantService` | The same |
| C5 | `orders.grand_total` equals the calculated value | `OrderCalculator` plus a post-write assertion | Requires summing child rows |
| C6 | Sum of captured payments equals `orders.paid_total` | `PaymentService`, checked hourly | Cross-table aggregate |
| C7 | Sum of loyalty points equals the cached balance | Nightly reconciliation | Cross-table aggregate |
| C8 | Sum of stock movements equals `quantity_on_hand` | Nightly reconciliation | Cross-table aggregate over millions of rows |
| C9 | Order transitions follow the state machine | `OrderStateMachine` | Needs the previous value and the actor |
| C10 | An expense approver is never its creator | `ExpensePolicy` | The approver is set by a later UPDATE |
| C11 | A transfer approver holds authority at the **source** branch | `TransferPolicy` | Needs the permission graph |
| C12 | A recipe unit and its ingredient unit share a family | `UnitConverter` | Needs a join to validate |
| C13 | Inventory is deducted at most once per order | `InventoryService`, guarded by `inventory_deducted_at` | Idempotency, not a value constraint |
| C14 | Non-cash payments carry a reference | `StorePaymentRequest` | Conditional on another column, and the error must reach the user as a field-level message |
| C15 | At most one open drawer per user per branch | Generated-column unique index **and** a service check | Partial uniqueness; the index does the real work |
| C16 | Negative stock refused unless configured | `InventoryService` | The rule depends on a setting, and a CHECK cannot be conditional |
| C17 | A discount above the role threshold needs approval | `DiscountPolicy` | The threshold varies per role and branch |

---

## 4. State machines

Every status column has exactly one legal set of transitions, defined in the enum
beside the values themselves.

### 4.1 Order

```text
pending --> accepted --> preparing --> ready --> completed
   |           |             |           |
   +-----------+-------------+-----------+--> cancelled
```

- `completed` and `cancelled` are terminal. Nothing leaves them.
- Cancelling requires a reason, and `order_status_histories` records who did it.
- Reaching `completed` triggers the recipe explosion, guarded by
  `inventory_deducted_at` so a retry cannot deduct twice.

### 4.2 Kitchen ticket

```text
queued --> preparing --> ready --> served
   |           |           |
   |           |           +--> preparing    (recall: kitchen.recall_ticket)
   +-----------+--> ready                    (skip:   kitchen.skip_preparing)
   +-----------+--> cancelled
```

The skip and recall paths exist because kitchens really do work that way. Both
are permission-gated, so the exception stays visible.

### 4.3 Stock transfer

```text
draft --> pending --> approved --> in_transit --> received
  |          |                              +--> partially_received
  |          +--> rejected
  +----------+--> cancelled      (never after dispatch)
```

Between dispatch and receipt the stock is deducted at the source and counted as
`in_transit_quantity` at the destination - available at neither, which is the
honest answer to "where is it?".

### 4.4 Expense

```text
draft --> pending --> approved --> paid
             +--> rejected --> draft
```

Only a draft may be edited or deleted by its creator.

### 4.5 Payment status is two different things

`payments.status` is the state of **one tender**. `orders.payment_status` is
**derived** from all of them and is never set directly:

| Condition | `orders.payment_status` |
|---|---|
| No captured payment | `unpaid` |
| `0 < paid_total < grand_total` | `partially_paid` |
| `paid_total >= grand_total` | `paid` |
| `0 < refunded_total < paid_total` | `partially_refunded` |
| `refunded_total >= paid_total` | `refunded` |

---

## 5. Status value conventions

One vocabulary, applied everywhere:

| Rule | Example |
|---|---|
| `lower_snake_case`, always | `partially_received`, never `Partially Received` |
| Stored as the enum value, displayed via a translation | The database holds `in_transit`; the UI shows "In Transit" |
| No boolean masquerading as a status | `is_active` is a boolean; `status` is a lifecycle |
| No `NULL` status | Every status column is `NOT NULL` with a default |
| The same word means the same thing everywhere | `cancelled` is terminal in every table that uses it |

There is deliberately no `active` / `Active` / `ACTIVE` ambiguity: the CHECK
constraint rejects any casing but the canonical one.

---

## 6. What is NOT enforced on SQLite

The test suite runs on SQLite for speed; production is MySQL 8. Three constructs
do not exist on SQLite and are skipped there by `SchemaSupport`:

| Construct | On MySQL | On SQLite |
|---|---|---|
| CHECK constraints | 43, added by migration `003900` | Skipped - SQLite cannot ADD CONSTRAINT to an existing table |
| Ledger triggers | 8, added by migration `004000` | Skipped - the `AppendOnly` trait still applies |
| FULLTEXT index | `ft_products_name` | Skipped - search falls back to a prefix LIKE |

Foreign keys, unique constraints and generated columns work on both.

**Consequence:** any test asserting a CHECK, a trigger or FULLTEXT behaviour
**must** run against MySQL. A green SQLite suite does not prove these constraints
exist.

---

## 7. Database QA checklist

Run against **MySQL**, on a fresh `migrate:fresh --seed`.

### Structure

- [x] Every table has a primary key - 38 of 38 domain tables (`branch_product` and `role_permission` use composite keys)
- [x] Consistent PK strategy: `BIGINT UNSIGNED AUTO_INCREMENT`, except Laravel's UUID `notifications`
- [x] Every foreign key declares `ON DELETE` and `ON UPDATE`
- [x] Every foreign key is indexed
- [x] No circular table dependencies - the migration order is a valid topological sort
- [x] Migrations run in order on an empty database
- [x] `migrate:fresh` succeeds; rollback succeeds on MySQL, with one documented exception (`extend_users_table`, rule M1)

### Integrity

- [x] Required fields are `NOT NULL`; optional fields allow `NULL`
- [x] Unique constraints exist and match the documented scope
- [x] Soft-deleted rows may reuse a SKU, slug or ingredient name
- [x] Emails, branch codes and document numbers are **not** reusable
- [x] Generated-column uniqueness works for `role_user`, `settings` and `cash_drawer_sessions`
- [ ] No orphan records - verify with a left-join sweep after the first data import
- [ ] Deleting a referenced parent is refused for every RESTRICT key - one test per key

### Business rules

- [x] Inventory is branch-specific: one `inventories` row per (branch, ingredient), and no global balance exists anywhere
- [x] Branch isolation: every branch-owned table has a `NOT NULL branch_id`
- [x] The stock ledger reconciles to the balance - verified on seeded data, 0 mismatches
- [x] A branch cannot transfer stock to itself
- [x] Ledger rows cannot be updated or deleted
- [x] Recipe explosion resolves units and wastage correctly - verified on seeded data
- [ ] Order history preserves old prices: create an order, reprice and rename the product, reload, assert the line is unchanged. **Blocked until the order service exists (Day 4).**
- [ ] Payment totals reconcile to the order - same dependency
- [ ] Transfer quantities flow correctly through all four stages

### Seeding

- [x] Seeders are idempotent - a second run adds nothing
- [x] No hard-coded production credentials anywhere
- [x] `SuperAdminSeeder` refuses to run in production without an explicit password
- [x] `DemoDataSeeder` refuses to run in production at all
- [x] Demo accounts use the reserved `.test` domain
- [x] No financial rate is seeded by default - tax, service charge and loyalty rates must be configured

### Still to verify

- [ ] Run the whole suite against MySQL 8 in CI. **The CHECK constraints and triggers in section 2 are unverified until this happens** - they are skipped on SQLite.
- [ ] EXPLAIN assertions on the named queries in [indexes.md](indexes.md) section 2
- [ ] Concurrency: two terminals allocating an order number at the same moment
- [ ] Concurrency: two sales deducting the last unit of an ingredient
