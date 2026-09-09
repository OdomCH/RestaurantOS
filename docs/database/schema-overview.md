# Schema Overview

> **Purpose.** The map: what the modules are, which tables belong to each, and
> the reasoning behind the decisions that were genuine choices rather than
> mechanical consequences.
>
> **This document does not list columns.** The authoritative column, type,
> nullability and default specification is [05-database-design.md](../05-database-design.md).
> Relationships are in [relationships.md](relationships.md), indexes in
> [indexes.md](indexes.md), and rules in [business-constraints.md](business-constraints.md).

**Status:** ✅ **Implemented.** 38 domain tables (46 including Laravel's own), 40
migrations, 34 Eloquent models, 18 PHP enums and 8 seeder classes exist under
`backend/`. Verified by `migrate:fresh --seed`.

---

## 1. Module map

| Module | Tables | Owns |
|---|---|---|
| **Identity** | `users`, `password_reset_tokens`, `sessions` | Who can sign in, and their employment record |
| **Access control** | `roles`, `permissions`, `role_permission`, `role_user` | What each person may do, and where |
| **Core** | `branches`, `settings`, `tax_rates`, `daily_sequences` | The locations and their configuration |
| **Catalogue** | `categories`, `products`, `product_variants`, `branch_product` | What is on the menu, and at what price |
| **CRM** | `customers`, `loyalty_tiers`, `loyalty_rules`, `loyalty_transactions` | Members and their points |
| **Sales** | `orders`, `order_items`, `order_status_histories`, `payments`, `refunds`, `cash_drawer_sessions` | Every sale and every cent taken |
| **Kitchen** | `kitchen_stations`, `kitchen_tickets`, `kitchen_ticket_items` | Preparation routing and timing |
| **Inventory** | `units`, `ingredients`, `recipes`, `recipe_items`, `inventories`, `stock_transactions`, `stock_transfers`, `stock_transfer_items` | What is in the building, and where it went |
| **Finance** | `expense_categories`, `expenses` | Costs other than stock |
| **Platform** | `notifications`, `audit_logs` | Alerts and the record of who did what |
| **Framework** | `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations` | Laravel infrastructure |

Sanctum's `personal_access_tokens` is **not yet created** — it arrives with the
package on Day 3 (Authentication). Nothing in this design depends on it.

---

## 2. The four decisions that shaped everything else

### 2.1 Every branch-owned table carries `branch_id`

Not a lookup through a join, not a nullable "company-wide" escape hatch: a real,
`NOT NULL`, indexed column on `orders`, `payments`, `inventories`,
`stock_transactions`, `expenses`, `kitchen_tickets` and `cash_drawer_sessions`.

Multi-branch was designed in from the first table rather than retrofitted, which
is why there is no query in the system capable of spending one branch's stock at
another. The cost is one extra column on a dozen tables. The alternative — deriving
the branch by joining back to `orders` every time — makes every report slower and
every isolation bug silent.

### 2.2 Ledgers are the truth; balances are a cache

`stock_transactions` and `loyalty_transactions` are append-only and complete.
`inventories.quantity_on_hand` and `customers.loyalty_points_balance` are
materialised sums that exist only because summing millions of rows on every POS
keystroke is not viable.

Each cached balance has a reconciliation job that compares it against its ledger
nightly and **raises an alert rather than silently repairing** — a silent repair
hides the bug that caused the drift.

### 2.3 Financial history is snapshotted, never referenced

`order_items` stores the name, SKU, unit price, tax rate and unit cost that
applied at the moment of sale. Repricing a product tomorrow cannot change what a
customer paid today. This is deliberate denormalisation, and omitting it would be
a correctness bug rather than a tidier schema.

### 2.4 Status is `VARCHAR` + `CHECK`, mirrored by a PHP enum

MySQL `ENUM` needs an `ALTER TABLE` to add a value, and that locks an
800 000-row table. `VARCHAR(30)` with a `CHECK` generated from
`app/Enums/*` gives the same protection, an alterable vocabulary, and one
definition shared by the database, the validator and the API.

---

## 3. Primary key strategy

**`BIGINT UNSIGNED AUTO_INCREMENT` everywhere**, with one exception:
`notifications.id` is a UUID because that is Laravel's own contract for the
notifications table.

| Criterion | `BIGINT AUTO_INCREMENT` | UUID / ULID |
|---|---|---|
| **Index size** | 8 bytes. Every secondary index in InnoDB carries a copy of the PK, so this multiplies across ~70 indexes. | 16 bytes binary, 36 as a string — roughly double to quadruple, on every index. |
| **Insert performance** | Monotonic: new rows land at the end of the clustered index. `stock_transactions` takes eight million inserts a year and this matters. | Random UUIDv4 scatters inserts across the B-tree and fragments pages. ULID and UUIDv7 fix the ordering but not the width. |
| **Scalability** | One primary writer, which is the deployment topology. 2^63 rows is not a real ceiling. | Wins only when ids must be generated offline or merged across databases — neither applies. |
| **Security** | Sequential ids are enumerable. | Opaque. |
| **Developer experience** | Trivial to read in a log, a URL, or a support call. | Unreadable aloud; harder to debug. |

**Enumeration is a real concern and is answered elsewhere, on purpose.** Hiding
ids is not authorisation: every endpoint applies a branch scope and a policy, so
guessing `/orders/1234` returns 403 rather than another branch's order
([04](../04-user-roles-permissions.md) §5). Where an identifier is genuinely
public — an order number on a printed receipt — the exposed value is
`order_number` (`PP1-20260905-0042`), not the primary key.

`created_at DESC` also works as an ordering proxy on an auto-increment id, which
several reports rely on.

---

## 4. Users and employees: one table, and why

The brief asks whether authentication and employee information belong together.
They do, here.

**The relationship would be strictly one-to-one.** Every employee needs to sign
in; every account belongs to an employee. A 1:1 split with no independent
lifecycle buys nothing, costs a join on the hot authentication path, and creates
a class of bugs where the two rows disagree about whether someone is active.

So `users` carries `employee_code`, `position`, `hire_date` and `branch_id`
alongside the credentials.

**When the split would be right — and what to do then:**

| Trigger | Why it changes the answer |
|---|---|
| Staff who never log in (kitchen porters, cleaners on a rota) | An employee record with no credentials is no longer a 1:1 |
| Employment history across rehiring | One person, several employment periods — genuinely one-to-many |
| Payroll: salary, contract, tax identifiers | A different security boundary; most staff must not read it |

The migration path is additive and non-breaking: create `employees`, move the
four employment columns, and add `users.employee_id`. Because nothing outside
the user profile reads those columns today, the change touches one model.

`branch_id` on `users` is the **home** branch only. Wider reach is
`role_user.branch_id`, so one person can be a Manager at Phnom Penh and a
Cashier at Siem Reap on a single account.

---

## 5. Where the price lives

`products.base_price`, with a signed `product_variants.price_delta` and an
optional `branch_product.price_override`.

```text
effective price = COALESCE(branch_product.price_override, products.base_price)
                + COALESCE(product_variants.price_delta, 0)
```

Storing an absolute price on each variant was the alternative. It loses: a
menu-wide 10 % rise would then mean updating every variant at every branch, and
any missed row silently sells at the old price. The delta model makes that one
`UPDATE`.

The trade-off is real — a variant cannot be priced entirely independently of its
product — and it matches how restaurant menus are actually repriced.

**None of this affects a completed sale**, because `order_items.unit_price` is a
snapshot. The formula above resolves the price *at the moment of sale* and then
the answer is frozen.

---

## 6. Kitchen: three tables, no duplication

The brief asks whether the kitchen needs its own tables. It needs two, and they
duplicate nothing.

A single order routes to **several stations**: the barista makes the drinks while
the grill cooks the food. Those finish at different times. `orders.status` holds
exactly one value, and "drinks ready, food still cooking" is two — so the state
cannot live on the order.

- `kitchen_stations` — one physical display per branch (BAR, GRILL), with its own SLA.
- `kitchen_tickets` — one order's work at one station: routing, timing, status.
- `kitchen_ticket_items` — which order lines are on which ticket. It points at
  `order_items` and copies nothing, so a corrected line is corrected everywhere.

The required workflow (Pending → Accepted → Preparing → Ready → Completed) stays
on the **order**. The ticket status is the per-station view of it, and the order
advances when its tickets do.

---

## 7. Tables added beyond the brief, and why each earns its place

| Table | Why it is needed |
|---|---|
| `units` | Recipes use ml and g; stock is kept in l and kg. Conversion needs a factor and a family, which is a table, not an enum. |
| `tax_rates` | A rate changes over time and historic orders must keep the rate that applied. |
| `daily_sequences` | Gapless order numbers under concurrency. `SELECT MAX(...) + 1` is a race that produces duplicate receipts. |
| `settings` | Rates, thresholds and toggles that differ per branch and must not require a deployment to change. |
| `branch_product` | Per-branch availability and price override — the menu is global, what a branch sells is not. |
| `order_status_histories` | Who moved the order, when, and why it was cancelled. The five timestamps on `orders` cannot record an actor or a reason. |
| `refunds` | A refund is a new fact, not an edit of the payment. Reducing `payments.amount` would destroy the evidence that money was taken. |
| `cash_drawer_sessions` | Ties takings to a shift, which is what makes a till variance attributable. |
| `kitchen_stations`, `kitchen_tickets`, `kitchen_ticket_items` | §6. |
| `loyalty_tiers`, `loyalty_rules` | Tiers and rates are configuration; hard-coding "$1 = 1 point" makes a business decision a deployment. |
| `expense_categories` | The category decides whether a cost sits in COGS or operating expense, which changes the profit report. |
| `inventories` | The materialised branch balance that keeps the POS fast. |

**Not added:** a `kitchen_orders` table duplicating orders; an `employees` table
(§4); an `order_taxes` breakdown table (one rate per line covers the requirement,
and the rate is snapshotted); a `product_images` table (one image per product is
the requirement, so a path column suffices).

---

## 8. Implementation inventory

| Artefact | Location | Count |
|---|---|---|
| Migrations | `backend/database/migrations/2026_09_08_*` | 40 |
| Models | `backend/app/Models/` | 34 |
| Enums | `backend/app/Enums/` | 18 |
| Seeders | `backend/database/seeders/` | 8 |
| Schema helper | `backend/app/Support/Database/SchemaSupport.php` | 1 |
| Append-only trait | `backend/app/Models/Concerns/AppendOnly.php` | 1 |
