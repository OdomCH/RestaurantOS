# 05 — Database Design

> **Document purpose.** Define the complete normalised MySQL schema: every table, column, data type,
> key, index, constraint, nullability and soft-delete policy. This document is the **authoritative
> source for the schema**. [06-erd.md](06-erd.md) visualises these relationships; it does not restate
> the columns.

**Prerequisites:** [01](01-project-overview.md) §8–§9, [02](02-system-architecture.md) §6–§7.

**Status:** ✅ **Implemented** (2026-09-08). Every table below exists as a Laravel migration under
`backend/database/migrations/`, with 34 models, 18 enums and 8 seeder classes. Verified by
`migrate:fresh --seed`. Three qualifications: Sanctum's `personal_access_tokens` arrives with the
package on Day 3; partitioning (§12) remains proposed; and the CHECK constraints, triggers and
FULLTEXT index are **MySQL-only** and therefore unverified until the suite runs against MySQL in CI.
See [database/schema-overview.md](database/schema-overview.md) §8 for the implementation inventory.

---

## 1. Conventions

### 1.1 Engine and character set

| Setting | Value | Reason |
|---|---|---|
| Engine | `InnoDB` | Transactions, row-level locking and foreign keys are all mandatory. |
| Character set | `utf8mb4` | Full Unicode, including emoji in customer names and order notes. |
| Collation | `utf8mb4_unicode_ci` | Accent- and case-insensitive comparison for names and search. |
| Row format | `DYNAMIC` | Required for long `utf8mb4` indexes. |
| Time zone | Server runs in **UTC**. | [01](01-project-overview.md) A10. |
| SQL mode | `STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO` | Silent truncation of a money column is unacceptable. |

### 1.2 Naming

| Object | Convention | Example |
|---|---|---|
| Table | plural, snake_case | `order_items` |
| Pivot table | singular models, alphabetical | `role_user`, `branch_product` |
| Primary key | `id` | `BIGINT UNSIGNED AUTO_INCREMENT` |
| Foreign key column | `<singular>_id` | `branch_id` |
| Boolean | `is_` / `has_` prefix | `is_active` |
| Timestamp | `_at` suffix | `completed_at` |
| Date | `_date` suffix | `expense_date` |
| Money | plural noun, no unit suffix | `grand_total` |
| Quantity | `_quantity` suffix | `received_quantity` |
| Index | `idx_<table>_<cols>` | `idx_orders_branch_status` |
| Unique | `uq_<table>_<cols>` | `uq_orders_branch_number` |
| Foreign key | `fk_<table>_<col>` | `fk_orders_branch_id` |

### 1.3 Data type policy

| Kind | Type | Rationale |
|---|---|---|
| **Money** | `DECIMAL(12,2)` | Exact. Max ≈ 9,999,999,999.99 — far beyond any single order. Never `FLOAT`/`DOUBLE`. |
| **Unit cost** | `DECIMAL(12,4)` | Four decimals because per-gram and per-millilitre costs are fractions of a cent. |
| **Quantity (sold)** | `DECIMAL(12,3)` | Supports fractional sales (0.5 kg of cake). |
| **Quantity (stock)** | `DECIMAL(14,4)` | Wider and more precise: ledger balances accumulate over years and recipes use 4 decimals. |
| **Rate / percentage** | `DECIMAL(6,4)` | `0.0700` = 7 %. Four decimals supports rates like 8.875 %. Stored as a **fraction**, not a percentage, except where a column is explicitly named `_percent`. |
| **Points** | `INT` (signed) | Loyalty points are whole numbers; the ledger needs negatives. |
| **Identifier** | `BIGINT UNSIGNED` | Uniform, avoids future migration. |
| **Short code** | `VARCHAR(20-50)` | Branch codes, SKUs, order numbers. |
| **Free text** | `VARCHAR(255)` or `TEXT` | `TEXT` only when genuinely unbounded. |
| **Enum** | `VARCHAR(30)` + a `CHECK` constraint | Not MySQL `ENUM`: adding a value to a MySQL `ENUM` requires an `ALTER TABLE` that locks the table. A `VARCHAR` + `CHECK` is alterable and readable from application enums. |
| **JSON** | `JSON` | Audit payloads and settings only. Never for queryable business data. |
| **Timestamp** | `TIMESTAMP` (UTC) | Stored UTC; rendered per branch timezone. |

> **Why rates are stored as fractions.** `0.0700` rather than `7.00` removes an entire class of
> "divided by 100 twice" bugs. Columns that break this rule carry `_percent` in the name and are
> documented individually.

### 1.4 Timestamps and soft deletes

Every table has `created_at` and `updated_at` (`TIMESTAMP NULL`) unless stated otherwise.

**Soft deletes (`deleted_at TIMESTAMP NULL`) are applied only where history must survive deletion.**
They are deliberately *absent* from ledger tables, because a ledger row is never deleted at all —
corrections are made by writing a compensating row.

| Soft deletes present | Soft deletes absent — and why |
|---|---|
| `users`, `branches`, `categories`, `products`, `product_variants`, `customers`, `ingredients`, `recipes`, `orders`, `expenses`, `stock_transfers` | `stock_transactions`, `loyalty_transactions`, `audit_logs`, `order_status_histories` — **append-only ledgers**. |
| | `order_items` — deleted only via the parent order; an item removed from a Pending order is genuinely removed. |
| | `payments` — never deleted; voided payments keep `status = 'voided'`. |
| | All pivot tables — the relationship either exists or does not. |

Every table with `deleted_at` carries an index on it, and its unique constraints include `deleted_at`
where re-use of a code after deletion must be permitted (§1.5).

### 1.5 The soft-delete + unique constraint problem

A plain `UNIQUE (sku)` on a soft-deleting table blocks re-using the SKU of a deleted product. Two
strategies are used:

| Strategy | Where | How |
|---|---|---|
| **Permanent uniqueness** | `users.email`, `branches.code`, `orders.order_number` | Plain `UNIQUE`. The value must never be reused, even after deletion — an email belongs to a person, and an order number must be permanently traceable. |
| **Reusable after deletion** | `products.sku`, `categories.slug`, `ingredients.name` | `UNIQUE (col, deleted_at)`. MySQL treats `NULL`s as distinct, so many soft-deleted rows may share a value while at most one live row holds it. |

This distinction is a frequent source of production bugs and is called out per table below.

### 1.6 Foreign key policy

| Action | Default | Applied to |
|---|---|---|
| `ON DELETE RESTRICT` | Default for all FKs | Prevents accidental loss of referenced master data. |
| `ON DELETE CASCADE` | Child rows owned wholly by a parent | `order_items`, `recipe_items`, `stock_transfer_items`, `role_user`, `role_permission`, `branch_product` |
| `ON DELETE SET NULL` | Optional references | `orders.customer_id`, `audit_logs.user_id`, `expenses.approved_by` |
| `ON UPDATE CASCADE` | All | IDs are immutable in practice; harmless. |

Foreign keys are **enforced by the database**, not only by the application. Application-only integrity
fails the moment a background job, console command or manual fix bypasses the ORM.

---

## 2. Table inventory

| # | Table | Module | Rows (est. 1 yr, 15 branches) | Soft delete | Append-only |
|---|---|---|---|---|---|
| 1 | `users` | Auth | 200 | ✔ | |
| 2 | `password_reset_tokens` | Auth | — | | |
| 3 | `personal_access_tokens` | Auth | 2 000 | | |
| 4 | `roles` | RBAC | 6 | | |
| 5 | `permissions` | RBAC | ~120 | | |
| 6 | `role_permission` | RBAC | ~400 | | |
| 7 | `role_user` | RBAC | 300 | | |
| 8 | `branches` | Core | 15 | ✔ | |
| 9 | `settings` | Core | 200 | | |
| 10 | `tax_rates` | Core | 10 | | |
| 11 | `daily_sequences` | Core | 16 000 | | |
| 12 | `categories` | Catalogue | 60 | ✔ | |
| 13 | `products` | Catalogue | 2 000 | ✔ | |
| 14 | `product_variants` | Catalogue | 4 000 | ✔ | |
| 15 | `branch_product` | Catalogue | 30 000 | | |
| 16 | `customers` | CRM | 50 000 | ✔ | |
| 17 | `loyalty_tiers` | CRM | 5 | | |
| 18 | `loyalty_rules` | CRM | 5 | | |
| 19 | `loyalty_transactions` | CRM | 400 000 | | ✔ |
| 20 | `orders` | Sales | 800 000 | ✔ | |
| 21 | `order_items` | Sales | 3 000 000 | | |
| 22 | `order_status_histories` | Sales | 4 000 000 | | ✔ |
| 23 | `payments` | Sales | 900 000 | | |
| 24 | `refunds` | Sales | 5 000 | | |
| 25 | `cash_drawer_sessions` | Sales | 12 000 | | |
| 26 | `kitchen_stations` | Kitchen | 40 | | |
| 27 | `kitchen_tickets` | Kitchen | 900 000 | | |
| 28 | `kitchen_ticket_items` | Kitchen | 3 000 000 | | |
| 29 | `units` | Inventory | 20 | | |
| 30 | `ingredients` | Inventory | 500 | ✔ | |
| 31 | `recipes` | Inventory | 2 500 | ✔ | |
| 32 | `recipe_items` | Inventory | 10 000 | | |
| 33 | `inventories` | Inventory | 7 500 | | |
| 34 | `stock_transactions` | Inventory | 8 000 000 | | ✔ |
| 35 | `stock_transfers` | Inventory | 3 000 | ✔ | |
| 36 | `stock_transfer_items` | Inventory | 15 000 | | |
| 37 | `expense_categories` | Finance | 20 | | |
| 38 | `expenses` | Finance | 20 000 | ✔ | |
| 39 | `notifications` | Platform | 200 000 | | |
| 40 | `audit_logs` | Platform | 6 000 000 | | ✔ |
| 41 | `jobs`, `failed_jobs`, `cache`, `sessions` | Framework | — | | |

The three largest tables — `stock_transactions`, `audit_logs`, `order_items` — drive the partitioning
and archival policy in §12.

---

## 3. Normalisation

The schema is in **third normal form**, with four deliberate, documented denormalisations.

### 3.1 Deliberate denormalisations

| # | Denormalisation | Why it is correct here |
|---|---|---|
| D1 | **Snapshot columns on `order_items`** (`name_snapshot`, `sku_snapshot`, `unit_price`, `tax_rate_snapshot`, `unit_cost_snapshot`) | These are *not* redundant copies of current data — they are the historical facts of the transaction. Repricing a product must not retroactively change last month's revenue. Omitting them is a correctness bug, not a normalisation improvement. |
| D2 | **`inventories.quantity_on_hand`** duplicates `SUM(stock_transactions.quantity_change)` | A materialised balance. Summing eight million ledger rows on every POS keystroke is not viable. Reconciled nightly and rebuildable on demand (FR-INV-015). |
| D3 | **`orders.subtotal`, `tax_amount`, `grand_total`** duplicate sums over `order_items` | Financial documents must be immutable. Recomputing from lines would let a later rounding-mode change alter a settled order. |
| D4 | **`customers.loyalty_points_balance`** duplicates the ledger sum | Same reasoning as D2, at much smaller scale. Reconciled by a scheduled job. |

Each denormalisation has a reconciliation job listed in §11.

### 3.2 Normalisations that were considered and applied

- Addresses are attributes of `branches`, not a separate table: there is exactly one per branch and no
  address history requirement.
- Units are a table, not an enum on `ingredients`, because unit conversion needs a factor and a family.
- Tax rates are a table, not a column, because a rate changes over time and historic orders must keep
  the rate that applied (hence `tax_rate_snapshot` on the line).

---

## 4. Enumerated values

All stored as `VARCHAR(30)` with a `CHECK`. Mirrored by PHP enums in `app/Enums/`.

| Enum | Table.column | Values |
|---|---|---|
| Order status | `orders.status` | `pending`, `accepted`, `preparing`, `ready`, `completed`, `cancelled` |
| Payment status | `orders.payment_status` | `unpaid`, `partially_paid`, `paid`, `refunded`, `partially_refunded` |
| Order type | `orders.order_type` | `dine_in`, `takeaway`, `delivery` |
| Order item status | `order_items.status` | `pending`, `preparing`, `ready`, `served`, `voided` |
| Discount type | `orders.discount_type`, `order_items.discount_type` | `none`, `percentage`, `fixed` |
| Payment method | `payments.method` | `cash`, `card`, `qr`, `bank_transfer` |
| Payment record status | `payments.status` | `pending`, `captured`, `failed`, `voided`, `refunded`, `partially_refunded` |
| Stock transaction type | `stock_transactions.type` | `stock_in`, `stock_out`, `adjustment`, `transfer_in`, `transfer_out`, `sale_deduction`, `sale_reversal`, `wastage`, `count_correction` |
| Transfer status | `stock_transfers.status` | `draft`, `pending`, `approved`, `in_transit`, `received`, `partially_received`, `rejected`, `cancelled` |
| Expense status | `expenses.status` | `draft`, `pending`, `approved`, `rejected`, `paid` |
| Kitchen ticket status | `kitchen_tickets.status` | `queued`, `preparing`, `ready`, `served`, `cancelled` |
| Loyalty transaction type | `loyalty_transactions.type` | `earn`, `redeem`, `adjust`, `expire`, `reverse` |
| Unit family | `units.family` | `mass`, `volume`, `count` |

> **Note on stock transaction types.** The four business-level types named in the requirements —
> Stock In, Stock Out, Adjustment, Transfer — are the *user-facing* vocabulary. The ledger stores finer
> types so that a sale deduction can be distinguished from manual wastage in reporting. The mapping is
> in [14-inventory-workflow.md](14-inventory-workflow.md) §3.1.

---

## 5. Schema by module

### 5.1 Authentication

#### `users`

Extends the existing Laravel `users` table ([01](01-project-overview.md) §2 — currently the framework
default).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AI | **PK** |
| `branch_id` | `BIGINT UNSIGNED` | Yes | `NULL` | **FK** → `branches.id` `ON DELETE SET NULL`. Home branch. `NULL` for Super Admin / Admin. |
| `name` | `VARCHAR(150)` | No | | |
| `email` | `VARCHAR(190)` | No | | **UNIQUE** (permanent — §1.5). 190 to stay within an index prefix limit. |
| `email_verified_at` | `TIMESTAMP` | Yes | `NULL` | |
| `password` | `VARCHAR(255)` | No | | bcrypt/argon2 hash. Never selected by default. |
| `pin_hash` | `VARCHAR(255)` | Yes | `NULL` | Hashed POS PIN (FR-AUTH-011). Never plaintext. |
| `phone` | `VARCHAR(30)` | Yes | `NULL` | |
| `avatar_path` | `VARCHAR(255)` | Yes | `NULL` | Object-storage key, not a URL. |
| `is_active` | `BOOLEAN` | No | `1` | `false` blocks all access (FR-AUTH-005). |
| `must_change_password` | `BOOLEAN` | No | `0` | Forces a reset on next login. |
| `last_login_at` | `TIMESTAMP` | Yes | `NULL` | |
| `last_login_ip` | `VARCHAR(45)` | Yes | `NULL` | IPv6-capable. |
| `failed_login_attempts` | `TINYINT UNSIGNED` | No | `0` | Reset on success. |
| `locked_until` | `TIMESTAMP` | Yes | `NULL` | Set by lockout policy. |
| `remember_token` | `VARCHAR(100)` | Yes | `NULL` | |
| `created_at` / `updated_at` / `deleted_at` | `TIMESTAMP` | Yes | `NULL` | Soft delete. |

**Indexes:** `uq_users_email (email)` · `idx_users_branch_id (branch_id)` ·
`idx_users_is_active (is_active)` · `idx_users_deleted_at (deleted_at)`

**Edge case:** a soft-deleted user keeps their email, so the address cannot be reused. This is
intentional — order history references the user, and re-issuing the address to a new person would make
the audit trail misleading.

#### `personal_access_tokens` (Sanctum)

Created by the Sanctum migration. 🟡 Sanctum is not yet installed.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `tokenable_type` / `tokenable_id` | `VARCHAR(255)` / `BIGINT UNSIGNED` | No | Polymorphic → `users`. |
| `name` | `VARCHAR(255)` | No | Device label, e.g. `pos-terminal-1`. |
| `token` | `VARCHAR(64)` | No | **UNIQUE**. SHA-256 hash — never the plaintext token. |
| `abilities` | `TEXT` | Yes | JSON array. See [08](08-authentication-authorization.md) §8. |
| `last_used_at` | `TIMESTAMP` | Yes | Drives idle expiry. |
| `expires_at` | `TIMESTAMP` | Yes | Absolute expiry. |

**Indexes:** `uq_pat_token (token)` · `idx_pat_tokenable (tokenable_type, tokenable_id)` ·
`idx_pat_expires_at (expires_at)`

### 5.2 Roles and permissions

#### `roles`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AI | **PK** |
| `name` | `VARCHAR(50)` | No | | **UNIQUE**. Slug: `super_admin`, `admin`, `manager`, `cashier`, `kitchen`, `staff`. |
| `display_name` | `VARCHAR(100)` | No | | |
| `description` | `VARCHAR(255)` | Yes | `NULL` | |
| `rank` | `SMALLINT UNSIGNED` | No | | Escalation hierarchy ([04](04-user-roles-permissions.md) §6.1): 100/80/60/40/40/20. |
| `is_system` | `BOOLEAN` | No | `0` | System roles cannot be renamed or deleted. |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | | |

**Indexes:** `uq_roles_name (name)` · `idx_roles_rank (rank)`

#### `permissions`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `name` | `VARCHAR(80)` | No | **UNIQUE**. `module.action`. |
| `group` | `VARCHAR(40)` | No | Module segment. Must equal the text before the dot. |
| `description` | `VARCHAR(255)` | Yes | Shown in the role editor. |
| `is_dangerous` | `BOOLEAN` | No | Flags `orders.void`, `payments.refund`, `inventory.adjust`, `users.impersonate` for extra UI warning and audit weight. |

**Indexes:** `uq_permissions_name (name)` · `idx_permissions_group (group)`

#### `role_permission`

| Column | Type | Null | Notes |
|---|---|---|---|
| `role_id` | `BIGINT UNSIGNED` | No | **FK** → `roles.id` `CASCADE` |
| `permission_id` | `BIGINT UNSIGNED` | No | **FK** → `permissions.id` `CASCADE` |

**PK:** `(role_id, permission_id)` · **Index:** `idx_rp_permission (permission_id)` for reverse lookups.
No timestamps — the audit log records grant changes.

#### `role_user`

| Column | Type | Null | Notes |
|---|---|---|---|
| `user_id` | `BIGINT UNSIGNED` | No | **FK** → `users.id` `CASCADE` |
| `role_id` | `BIGINT UNSIGNED` | No | **FK** → `roles.id` `CASCADE` |
| `branch_id` | `BIGINT UNSIGNED` | Yes | **FK** → `branches.id` `CASCADE`. `NULL` = role applies at the user's home branch / full reach. |
| `assigned_by` | `BIGINT UNSIGNED` | Yes | **FK** → `users.id` `SET NULL` |
| `created_at` | `TIMESTAMP` | Yes | |

**PK:** surrogate `id` is **not** used; the natural key is `(user_id, role_id, branch_id)`. Because
MySQL treats `NULL`s as distinct in a unique index, a generated column is used to enforce it:

```sql
branch_key BIGINT UNSIGNED AS (IFNULL(branch_id, 0)) STORED,
UNIQUE KEY uq_role_user (user_id, role_id, branch_key)
```

Without this, a user could be given the same role at the same `NULL` branch twice.

### 5.3 Core

#### `branches`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AI | **PK** |
| `code` | `VARCHAR(20)` | No | | **UNIQUE** (permanent). Appears in order numbers, e.g. `B1`. |
| `name` | `VARCHAR(150)` | No | | |
| `address_line1` / `address_line2` | `VARCHAR(255)` | Yes | | |
| `city` / `state` | `VARCHAR(100)` | Yes | | |
| `postal_code` | `VARCHAR(20)` | Yes | | |
| `country_code` | `CHAR(2)` | No | | ISO 3166-1 alpha-2. |
| `phone` / `email` | `VARCHAR(30)` / `VARCHAR(190)` | Yes | | |
| `timezone` | `VARCHAR(64)` | No | `UTC` | IANA name, e.g. `Asia/Bangkok`. Drives the business day. |
| `currency_code` | `CHAR(3)` | No | | ISO 4217. Immutable after the first order — changing it would reinterpret every stored amount. |
| `business_day_start` | `TIME` | No | `00:00:00` | Branch-local cut-off ([18](18-reporting.md) §3). |
| `opening_time` / `closing_time` | `TIME` | Yes | | Informational. |
| `tax_registration_number` | `VARCHAR(50)` | Yes | | Printed on receipts. |
| `receipt_footer` | `VARCHAR(500)` | Yes | | |
| `is_active` | `BOOLEAN` | No | `1` | Inactive branches reject new orders. |
| `created_at` / `updated_at` / `deleted_at` | `TIMESTAMP` | Yes | | Soft delete. |

**Indexes:** `uq_branches_code (code)` · `idx_branches_is_active (is_active)` ·
`idx_branches_deleted_at (deleted_at)`

#### `settings`

Key–value configuration with branch override. Resolution order in
[27-environment-configuration.md](27-environment-configuration.md) §5.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `branch_id` | `BIGINT UNSIGNED` | Yes | **FK** → `branches.id` `CASCADE`. `NULL` = global. |
| `key` | `VARCHAR(100)` | No | Dotted, e.g. `discount.max_percent.cashier`. |
| `value` | `TEXT` | Yes | Serialised per `type`. |
| `type` | `VARCHAR(20)` | No | `string`, `integer`, `decimal`, `boolean`, `json`. |
| `is_public` | `BOOLEAN` | No | Exposed to the SPA if true. Rates and thresholds are **not** public. |
| `updated_by` | `BIGINT UNSIGNED` | Yes | **FK** → `users.id` `SET NULL` |

**Unique:** `uq_settings_branch_key (branch_id, key)` — with the same `IFNULL(branch_id,0)` generated
column technique as `role_user`.

> **No shipped defaults for financial rates.** Per [01](01-project-overview.md) A3, the seeder does not
> populate tax rates, service charge or loyalty rates. Deployment must set them
> ([27](27-environment-configuration.md) §6). A missing rate raises a configuration error rather than
> silently defaulting to zero.

#### `tax_rates`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `code` | `VARCHAR(30)` | No | **UNIQUE**, e.g. `VAT_STD`. |
| `name` | `VARCHAR(100)` | No | |
| `rate` | `DECIMAL(6,4)` | No | Fraction (§1.3). `0.0700` = 7 %. |
| `is_inclusive` | `BOOLEAN` | No | Whether menu prices already include this tax. |
| `is_active` | `BOOLEAN` | No | |
| `effective_from` / `effective_to` | `DATE` | No / Yes | Historic rate changes. |

**Indexes:** `uq_tax_rates_code (code)` · `idx_tax_rates_active_dates (is_active, effective_from, effective_to)`

**Business rule:** the rate applied to a line is snapshotted onto `order_items.tax_rate_snapshot`. A
later rate change never alters a historic order.

#### `daily_sequences`

Guarantees gapless, concurrent-safe document numbering ([02](02-system-architecture.md) §7).

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `branch_id` | `BIGINT UNSIGNED` | No | **FK** → `branches.id` `CASCADE` |
| `scope` | `VARCHAR(20)` | No | `order`, `expense`, `transfer`, `refund`. |
| `sequence_date` | `DATE` | No | Branch business day. |
| `last_number` | `INT UNSIGNED` | No | |

**Unique:** `uq_daily_sequences (branch_id, scope, sequence_date)`

Allocation uses `INSERT ... ON DUPLICATE KEY UPDATE last_number = last_number + 1` followed by
`LAST_INSERT_ID()`, which is atomic under concurrency. Never `SELECT MAX(...) + 1`.

### 5.4 Catalogue

#### `categories`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `parent_id` | `BIGINT UNSIGNED` | Yes | **FK** → `categories.id` `RESTRICT`. Self-reference, **max depth 2** (FR-CAT-002), enforced in the application. |
| `kitchen_station_id` | `BIGINT UNSIGNED` | Yes | **FK** → `kitchen_stations.id` `SET NULL`. Routes tickets (FR-KDS-004). |
| `name` | `VARCHAR(120)` | No | |
| `slug` | `VARCHAR(140)` | No | **UNIQUE** with `deleted_at` (reusable — §1.5). |
| `description` | `VARCHAR(500)` | Yes | |
| `image_path` | `VARCHAR(255)` | Yes | |
| `sort_order` | `SMALLINT UNSIGNED` | No | Default `0`. POS display order. |
| `is_active` | `BOOLEAN` | No | Default `1`. |
| `created_at` / `updated_at` / `deleted_at` | `TIMESTAMP` | Yes | Soft delete. |

**Indexes:** `uq_categories_slug (slug, deleted_at)` · `idx_categories_parent (parent_id)` ·
`idx_categories_active_sort (is_active, sort_order)`

#### `products`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `category_id` | `BIGINT UNSIGNED` | No | **FK** → `categories.id` `RESTRICT` |
| `tax_rate_id` | `BIGINT UNSIGNED` | Yes | **FK** → `tax_rates.id` `RESTRICT`. `NULL` ⇒ branch default. |
| `sku` | `VARCHAR(50)` | No | **UNIQUE** with `deleted_at`. |
| `name` | `VARCHAR(150)` | No | |
| `slug` | `VARCHAR(170)` | No | **UNIQUE** with `deleted_at`. |
| `description` | `TEXT` | Yes | |
| `base_price` | `DECIMAL(12,2)` | No | Selling price before variant delta and branch override. `>= 0`. |
| `cost_price` | `DECIMAL(12,4)` | Yes | Manual cost for products **without** a recipe. Recipe-backed products derive cost — §5.8. |
| `image_path` | `VARCHAR(255)` | Yes | |
| `is_active` | `BOOLEAN` | No | Master switch across all branches. |
| `is_available` | `BOOLEAN` | No | Temporary "86'd" flag (FR-CAT-008). |
| `track_inventory` | `BOOLEAN` | No | Default `1`. `false` ⇒ no recipe explosion. |
| `has_variants` | `BOOLEAN` | No | Default `0`. If true, POS requires a variant selection. |
| `preparation_minutes` | `SMALLINT UNSIGNED` | Yes | Drives the KDS SLA. |
| `sort_order` | `SMALLINT UNSIGNED` | No | Default `0`. |
| `created_at` / `updated_at` / `deleted_at` | `TIMESTAMP` | Yes | Soft delete. |

**Indexes:** `uq_products_sku (sku, deleted_at)` · `uq_products_slug (slug, deleted_at)` ·
`idx_products_category (category_id)` · `idx_products_active_available (is_active, is_available)` ·
`ft_products_name (name, description)` **FULLTEXT** for POS search (FR-POS-002).

**Check:** `base_price >= 0`

#### `product_variants`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `product_id` | `BIGINT UNSIGNED` | No | **FK** → `products.id` `CASCADE` |
| `sku` | `VARCHAR(50)` | No | **UNIQUE** with `deleted_at`. |
| `name` | `VARCHAR(100)` | No | e.g. *Large*. |
| `price_delta` | `DECIMAL(12,2)` | No | Default `0.00`. **Signed** — may be negative for a smaller size. Applied to the effective base price. |
| `cost_delta` | `DECIMAL(12,4)` | No | Default `0.0000`. |
| `is_default` | `BOOLEAN` | No | Exactly one default per product — enforced in the application (§8, C4). |
| `is_active` | `BOOLEAN` | No | |
| `sort_order` | `SMALLINT UNSIGNED` | No | |
| `created_at` / `updated_at` / `deleted_at` | `TIMESTAMP` | Yes | Soft delete. |

**Indexes:** `uq_variants_sku (sku, deleted_at)` · `uq_variants_product_name (product_id, name, deleted_at)` ·
`idx_variants_product (product_id)`

> **Why a delta rather than an absolute price?** A menu-wide price rise changes one `base_price`
> instead of every variant row. The trade-off — a variant cannot be priced independently of its
> product — is acceptable and matches how restaurant menus are actually repriced.

#### `branch_product`

Per-branch availability and price override (FR-CAT-005).

| Column | Type | Null | Notes |
|---|---|---|---|
| `branch_id` | `BIGINT UNSIGNED` | No | **FK** → `branches.id` `CASCADE` |
| `product_id` | `BIGINT UNSIGNED` | No | **FK** → `products.id` `CASCADE` |
| `price_override` | `DECIMAL(12,2)` | Yes | `NULL` ⇒ use `products.base_price`. |
| `is_available` | `BOOLEAN` | No | Default `1`. |
| `updated_at` | `TIMESTAMP` | Yes | |

**PK:** `(branch_id, product_id)` · **Index:** `idx_bp_product (product_id)`

**Effective price** resolution is defined once, in [09-business-rules.md](09-business-rules.md) §3.

### 5.5 Customers and loyalty

#### `customers`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `code` | `VARCHAR(20)` | No | **UNIQUE**. Membership number. |
| `first_name` | `VARCHAR(80)` | No | |
| `last_name` | `VARCHAR(80)` | Yes | |
| `phone` | `VARCHAR(30)` | Yes | **UNIQUE** with `deleted_at`. Primary POS lookup key. |
| `email` | `VARCHAR(190)` | Yes | **UNIQUE** with `deleted_at`. |
| `birth_date` | `DATE` | Yes | |
| `gender` | `VARCHAR(20)` | Yes | Free-form; never used in logic. |
| `loyalty_tier_id` | `BIGINT UNSIGNED` | Yes | **FK** → `loyalty_tiers.id` `SET NULL` |
| `loyalty_points_balance` | `INT` | No | Default `0`. Denormalised (D4). `>= 0`. |
| `lifetime_points_earned` | `INT UNSIGNED` | No | Drives tier promotion. |
| `total_spent` | `DECIMAL(14,2)` | No | Default `0.00`. Denormalised. |
| `total_orders` | `INT UNSIGNED` | No | Default `0`. |
| `last_order_at` | `TIMESTAMP` | Yes | |
| `notes` | `TEXT` | Yes | Allergies, preferences. |
| `is_active` | `BOOLEAN` | No | |
| `anonymised_at` | `TIMESTAMP` | Yes | Set by erasure (FR-CUS-009). |
| `created_at` / `updated_at` / `deleted_at` | `TIMESTAMP` | Yes | Soft delete. |

**Indexes:** `uq_customers_code (code)` · `uq_customers_phone (phone, deleted_at)` ·
`uq_customers_email (email, deleted_at)` · `idx_customers_tier (loyalty_tier_id)` ·
`idx_customers_last_order (last_order_at)`

**Check:** `loyalty_points_balance >= 0`

#### `loyalty_tiers`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `name` | `VARCHAR(50)` | No | **UNIQUE**, e.g. *Silver*. |
| `min_lifetime_points` | `INT UNSIGNED` | No | Promotion threshold. |
| `earn_rate_multiplier` | `DECIMAL(6,3)` | No | Default `1.000`. |
| `discount_percent` | `DECIMAL(5,2)` | Yes | Automatic tier discount. `NULL` = none. |
| `benefits` | `JSON` | Yes | Display only. |
| `sort_order` | `SMALLINT UNSIGNED` | No | |
| `is_active` | `BOOLEAN` | No | |

**Indexes:** `uq_loyalty_tiers_name (name)` · `uq_loyalty_tiers_min_points (min_lifetime_points)`

#### `loyalty_rules`

Rates are configuration, never constants ([01](01-project-overview.md) A3).

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `name` | `VARCHAR(100)` | No | |
| `earn_points_per_currency_unit` | `DECIMAL(10,4)` | No | Points per 1.00 spent. |
| `earn_basis` | `VARCHAR(20)` | No | `subtotal`, `net_of_discount`, `grand_total`. Determines which figure earns. |
| `redeem_value_per_point` | `DECIMAL(10,4)` | No | Currency value of one point. |
| `min_points_to_redeem` | `INT UNSIGNED` | No | |
| `redeem_increment` | `INT UNSIGNED` | No | Default `1`. Points must redeem in multiples. |
| `max_redeem_percent_of_subtotal` | `DECIMAL(5,2)` | Yes | Cap. `NULL` = uncapped. |
| `points_expiry_days` | `SMALLINT UNSIGNED` | Yes | `NULL` = never expire. |
| `is_active` | `BOOLEAN` | No | Exactly one active rule at a time. |
| `effective_from` / `effective_to` | `DATE` | No / Yes | |

**Index:** `idx_loyalty_rules_active (is_active, effective_from, effective_to)`

#### `loyalty_transactions` — append-only

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `customer_id` | `BIGINT UNSIGNED` | No | **FK** → `customers.id` `RESTRICT` |
| `order_id` | `BIGINT UNSIGNED` | Yes | **FK** → `orders.id` `SET NULL` |
| `type` | `VARCHAR(30)` | No | `earn`, `redeem`, `adjust`, `expire`, `reverse`. |
| `points` | `INT` | No | **Signed**: positive earns, negative redeems/expiries. |
| `balance_after` | `INT` | No | Running balance. Enables reconciliation without a full scan. |
| `monetary_value` | `DECIMAL(12,2)` | Yes | Value redeemed, for reporting. |
| `reason` | `VARCHAR(255)` | Yes | Mandatory for `adjust`. |
| `reverses_transaction_id` | `BIGINT UNSIGNED` | Yes | **FK** → self `RESTRICT`. Links a reversal to its original. |
| `expires_at` | `TIMESTAMP` | Yes | For `earn` rows when expiry is configured. |
| `performed_by` | `BIGINT UNSIGNED` | Yes | **FK** → `users.id` `SET NULL` |
| `created_at` | `TIMESTAMP` | No | **No `updated_at`, no `deleted_at`.** |

**Indexes:** `idx_lt_customer_created (customer_id, created_at)` · `idx_lt_order (order_id)` ·
`idx_lt_type_expires (type, expires_at)`

**Invariant:** for any customer, `SUM(points) = customers.loyalty_points_balance`. Reconciled nightly
(§11).

### 5.6 Sales

#### `orders`

The central transactional table.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AI | **PK** |
| `branch_id` | `BIGINT UNSIGNED` | No | | **FK** → `branches.id` `RESTRICT` |
| `order_number` | `VARCHAR(30)` | No | | Human-readable, e.g. `B1-20260905-0042`. |
| `business_date` | `DATE` | No | | Branch business day ([18](18-reporting.md) §3). Indexed for reporting; **not** derivable from `created_at` without the timezone. |
| `order_type` | `VARCHAR(20)` | No | | `dine_in`, `takeaway`, `delivery`. |
| `table_number` | `VARCHAR(20)` | Yes | | Only for `dine_in`. |
| `customer_id` | `BIGINT UNSIGNED` | Yes | | **FK** → `customers.id` `SET NULL` |
| `user_id` | `BIGINT UNSIGNED` | No | | **FK** → `users.id` `RESTRICT`. Creating cashier. |
| `cash_drawer_session_id` | `BIGINT UNSIGNED` | Yes | | **FK** → `cash_drawer_sessions.id` `SET NULL` |
| `status` | `VARCHAR(20)` | No | `pending` | Lifecycle ([11](11-order-workflow.md)). |
| `payment_status` | `VARCHAR(20)` | No | `unpaid` | **Derived** — never client-set (FR-PAY-006). |
| `currency_code` | `CHAR(3)` | No | | Snapshot of the branch currency. |
| `subtotal` | `DECIMAL(12,2)` | No | `0.00` | Σ line subtotals before discount. |
| `discount_type` | `VARCHAR(20)` | No | `none` | |
| `discount_value` | `DECIMAL(12,2)` | No | `0.00` | The input: a percent or a fixed amount. |
| `discount_amount` | `DECIMAL(12,2)` | No | `0.00` | The computed money reduction. |
| `discount_reason` | `VARCHAR(255)` | Yes | | Mandatory above the threshold. |
| `discount_approved_by` | `BIGINT UNSIGNED` | Yes | | **FK** → `users.id` `SET NULL`. Who authorised an over-threshold discount. |
| `loyalty_points_redeemed` | `INT UNSIGNED` | No | `0` | |
| `loyalty_discount_amount` | `DECIMAL(12,2)` | No | `0.00` | Money value of redeemed points. Tracked separately from a manual discount so reports can distinguish them. |
| `taxable_amount` | `DECIMAL(12,2)` | No | `0.00` | Base the tax was computed on. |
| `tax_amount` | `DECIMAL(12,2)` | No | `0.00` | |
| `service_charge_rate` | `DECIMAL(6,4)` | No | `0.0000` | Snapshot. |
| `service_charge_amount` | `DECIMAL(12,2)` | No | `0.00` | |
| `rounding_adjustment` | `DECIMAL(12,2)` | No | `0.00` | Signed. Cash rounding ([09](09-business-rules.md) §8). |
| `grand_total` | `DECIMAL(12,2)` | No | `0.00` | The amount payable. |
| `paid_total` | `DECIMAL(12,2)` | No | `0.00` | Σ captured payments. Derived. |
| `refunded_total` | `DECIMAL(12,2)` | No | `0.00` | Σ refunds. |
| `change_due` | `DECIMAL(12,2)` | No | `0.00` | Cash change given. |
| `cogs_total` | `DECIMAL(12,4)` | No | `0.0000` | Σ (`unit_cost_snapshot` × qty). Materialised for reporting. |
| `note` | `VARCHAR(500)` | Yes | | |
| `placed_at` | `TIMESTAMP` | No | | Order creation. |
| `accepted_at` / `preparing_at` / `ready_at` / `completed_at` / `cancelled_at` | `TIMESTAMP` | Yes | | Lifecycle stamps. Denormalised from `order_status_histories` for cheap SLA reporting. |
| `cancelled_by` | `BIGINT UNSIGNED` | Yes | | **FK** → `users.id` `SET NULL` |
| `cancel_reason` | `VARCHAR(255)` | Yes | | Mandatory on cancel. |
| `inventory_deducted_at` | `TIMESTAMP` | Yes | | Non-null once the recipe explosion has run. **Idempotency guard** — prevents double deduction. |
| `version` | `INT UNSIGNED` | No | `0` | Optimistic locking ([02](02-system-architecture.md) §7). |
| `idempotency_key` | `VARCHAR(64)` | Yes | | **UNIQUE** with `deleted_at`. Prevents duplicate creation. |
| `created_at` / `updated_at` / `deleted_at` | `TIMESTAMP` | Yes | | Soft delete. |

**Indexes**

| Index | Columns | Serves |
|---|---|---|
| `uq_orders_branch_number` | `(branch_id, order_number)` | Uniqueness per branch. |
| `uq_orders_idempotency` | `(idempotency_key, deleted_at)` | Duplicate submission guard. |
| `idx_orders_branch_business_date` | `(branch_id, business_date)` | Daily sales reports — the highest-volume report query. |
| `idx_orders_branch_status` | `(branch_id, status)` | KDS and active-order lists. |
| `idx_orders_branch_payment_status` | `(branch_id, payment_status)` | Unsettled-order lists. |
| `idx_orders_customer` | `(customer_id)` | Customer history. |
| `idx_orders_user_business_date` | `(user_id, business_date)` | Cashier shift reports. |
| `idx_orders_placed_at` | `(placed_at)` | Time-series reporting. |
| `idx_orders_deleted_at` | `(deleted_at)` | |

**Checks:** `subtotal >= 0` · `discount_amount >= 0` · `tax_amount >= 0` · `grand_total >= 0` ·
`paid_total >= 0` · `discount_amount <= subtotal`

#### `order_items`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `order_id` | `BIGINT UNSIGNED` | No | **FK** → `orders.id` `CASCADE` |
| `product_id` | `BIGINT UNSIGNED` | No | **FK** → `products.id` `RESTRICT`. Restrict, so a sold product cannot be hard-deleted. |
| `product_variant_id` | `BIGINT UNSIGNED` | Yes | **FK** → `product_variants.id` `RESTRICT` |
| `name_snapshot` | `VARCHAR(200)` | No | Product + variant name at sale time (D1). |
| `sku_snapshot` | `VARCHAR(50)` | No | |
| `quantity` | `DECIMAL(12,3)` | No | `> 0`. |
| `unit_price` | `DECIMAL(12,2)` | No | Effective price at sale time. |
| `line_subtotal` | `DECIMAL(12,2)` | No | `round(unit_price × quantity, 2)`. |
| `discount_type` | `VARCHAR(20)` | No | Line-level discount. |
| `discount_value` | `DECIMAL(12,2)` | No | |
| `line_discount_amount` | `DECIMAL(12,2)` | No | Line discount **plus** the allocated share of the order discount ([09](09-business-rules.md) §5.3). |
| `taxable_amount` | `DECIMAL(12,2)` | No | `line_subtotal − line_discount_amount`. |
| `tax_rate_snapshot` | `DECIMAL(6,4)` | No | Rate applied (D1). |
| `is_tax_inclusive` | `BOOLEAN` | No | Snapshot of the rate's mode. |
| `line_tax_amount` | `DECIMAL(12,2)` | No | |
| `line_total` | `DECIMAL(12,2)` | No | `taxable_amount + line_tax_amount` (exclusive) or `taxable_amount` (inclusive). |
| `unit_cost_snapshot` | `DECIMAL(12,4)` | No | Cost per unit at sale time (D1). Drives COGS. |
| `line_cogs` | `DECIMAL(12,4)` | No | `unit_cost_snapshot × quantity`. |
| `status` | `VARCHAR(20)` | No | `pending`, `preparing`, `ready`, `served`, `voided`. |
| `note` | `VARCHAR(255)` | Yes | "no ice", "extra hot". |
| `voided_at` / `voided_by` | `TIMESTAMP` / `BIGINT UNSIGNED` | Yes | **FK** → `users.id` `SET NULL` |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | No soft delete (§1.4). |

**Indexes:** `idx_oi_order (order_id)` · `idx_oi_product (product_id)` ·
`idx_oi_variant (product_variant_id)` · `idx_oi_status (status)`

**Checks:** `quantity > 0` · `unit_price >= 0` · `line_discount_amount <= line_subtotal`

#### `order_status_histories` — append-only

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `order_id` | `BIGINT UNSIGNED` | No | **FK** → `orders.id` `CASCADE` |
| `from_status` | `VARCHAR(20)` | Yes | `NULL` for the initial row. |
| `to_status` | `VARCHAR(20)` | No | |
| `changed_by` | `BIGINT UNSIGNED` | Yes | **FK** → `users.id` `SET NULL`. `NULL` for system transitions. |
| `reason` | `VARCHAR(255)` | Yes | Mandatory for `cancelled`. |
| `duration_seconds` | `INT UNSIGNED` | Yes | Time spent in `from_status`. Precomputed for SLA reports. |
| `created_at` | `TIMESTAMP` | No | No `updated_at`. |

**Indexes:** `idx_osh_order_created (order_id, created_at)` · `idx_osh_to_status (to_status)`

#### `payments`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `order_id` | `BIGINT UNSIGNED` | No | **FK** → `orders.id` `RESTRICT`. Restrict — a paid order must never be hard-deleted. |
| `branch_id` | `BIGINT UNSIGNED` | No | **FK** → `branches.id` `RESTRICT`. Denormalised from the order for branch-scoped payment queries without a join. |
| `payment_number` | `VARCHAR(30)` | No | **UNIQUE**. |
| `method` | `VARCHAR(20)` | No | `cash`, `card`, `qr`, `bank_transfer`. |
| `amount` | `DECIMAL(12,2)` | No | Amount **applied to the order**. `> 0`. |
| `tendered_amount` | `DECIMAL(12,2)` | Yes | Cash only. `>= amount`. |
| `change_amount` | `DECIMAL(12,2)` | Yes | Cash only. `tendered − amount`. |
| `currency_code` | `CHAR(3)` | No | |
| `status` | `VARCHAR(20)` | No | `pending`, `captured`, `failed`, `voided`, `refunded`, `partially_refunded`. |
| `reference` | `VARCHAR(100)` | Yes | Approval code, QR reference, transfer slip. **Mandatory for non-cash** (FR-PAY-005). |
| `card_last_four` | `CHAR(4)` | Yes | The **only** card data ever stored ([22](22-security.md) §10). |
| `card_brand` | `VARCHAR(30)` | Yes | |
| `provider` | `VARCHAR(50)` | Yes | Terminal or bank name. |
| `received_by` | `BIGINT UNSIGNED` | No | **FK** → `users.id` `RESTRICT` |
| `cash_drawer_session_id` | `BIGINT UNSIGNED` | Yes | **FK** → `cash_drawer_sessions.id` `SET NULL` |
| `paid_at` | `TIMESTAMP` | No | |
| `voided_at` / `voided_by` / `void_reason` | `TIMESTAMP` / `BIGINT UNSIGNED` / `VARCHAR(255)` | Yes | **FK** → `users.id` `SET NULL` |
| `refunded_total` | `DECIMAL(12,2)` | No | Default `0.00`. Σ refunds against this payment. |
| `idempotency_key` | `VARCHAR(64)` | Yes | **UNIQUE**. |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | No soft delete (§1.4). |

**Indexes:** `uq_payments_number (payment_number)` · `uq_payments_idempotency (idempotency_key)` ·
`idx_payments_order (order_id)` · `idx_payments_branch_paid_at (branch_id, paid_at)` ·
`idx_payments_method_status (method, status)` · `idx_payments_session (cash_drawer_session_id)`

**Checks:** `amount > 0` · `tendered_amount IS NULL OR tendered_amount >= amount` ·
`refunded_total <= amount`

#### `refunds`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `payment_id` | `BIGINT UNSIGNED` | No | **FK** → `payments.id` `RESTRICT` |
| `order_id` | `BIGINT UNSIGNED` | No | **FK** → `orders.id` `RESTRICT`. Denormalised for order-level queries. |
| `branch_id` | `BIGINT UNSIGNED` | No | **FK** → `branches.id` `RESTRICT` |
| `refund_number` | `VARCHAR(30)` | No | **UNIQUE**. |
| `amount` | `DECIMAL(12,2)` | No | `> 0`. |
| `method` | `VARCHAR(20)` | No | May differ from the original (card refunded as cash). |
| `reason` | `VARCHAR(255)` | No | **Mandatory.** |
| `restock_inventory` | `BOOLEAN` | No | Whether ingredients were returned to stock. Usually `false` for food. |
| `requested_by` / `approved_by` | `BIGINT UNSIGNED` | No / Yes | **FK** → `users.id` `RESTRICT` / `SET NULL` |
| `refunded_at` | `TIMESTAMP` | No | |
| `idempotency_key` | `VARCHAR(64)` | Yes | **UNIQUE**. |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | |

**Indexes:** `uq_refunds_number (refund_number)` · `idx_refunds_payment (payment_id)` ·
`idx_refunds_order (order_id)` · `idx_refunds_branch_date (branch_id, refunded_at)`

#### `cash_drawer_sessions`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `branch_id` | `BIGINT UNSIGNED` | No | **FK** → `branches.id` `RESTRICT` |
| `user_id` | `BIGINT UNSIGNED` | No | **FK** → `users.id` `RESTRICT` |
| `business_date` | `DATE` | No | |
| `opening_float` | `DECIMAL(12,2)` | No | |
| `expected_cash` | `DECIMAL(12,2)` | Yes | Computed at close: float + cash in − cash out. |
| `counted_cash` | `DECIMAL(12,2)` | Yes | Physically counted. |
| `variance` | `DECIMAL(12,2)` | Yes | `counted − expected`. Signed. |
| `status` | `VARCHAR(20)` | No | `open`, `closed`. |
| `opened_at` / `closed_at` | `TIMESTAMP` | No / Yes | |
| `closed_by` | `BIGINT UNSIGNED` | Yes | **FK** → `users.id` `SET NULL`. May differ from `user_id` when a manager closes it. |
| `notes` | `VARCHAR(500)` | Yes | Mandatory when `ABS(variance)` exceeds the tolerance. |

**Indexes:** `idx_cds_branch_date (branch_id, business_date)` · `idx_cds_user_status (user_id, status)`

**Partial-uniqueness rule:** at most one `open` session per user per branch. MySQL has no partial
indexes, so this is enforced by a generated column:

```sql
open_guard VARCHAR(64) AS (IF(status = 'open', CONCAT(branch_id, ':', user_id), NULL)) STORED,
UNIQUE KEY uq_cds_open (open_guard)
```

### 5.7 Kitchen

#### `kitchen_stations`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `branch_id` | `BIGINT UNSIGNED` | No | **FK** → `branches.id` `CASCADE` |
| `code` | `VARCHAR(30)` | No | e.g. `BAR`, `GRILL`. |
| `name` | `VARCHAR(100)` | No | |
| `sla_minutes` | `SMALLINT UNSIGNED` | No | Default `15`. Late-ticket threshold. |
| `is_active` | `BOOLEAN` | No | |

**Unique:** `uq_stations_branch_code (branch_id, code)`

#### `kitchen_tickets`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `order_id` | `BIGINT UNSIGNED` | No | **FK** → `orders.id` `CASCADE` |
| `branch_id` | `BIGINT UNSIGNED` | No | **FK** → `branches.id` `RESTRICT` |
| `kitchen_station_id` | `BIGINT UNSIGNED` | Yes | **FK** → `kitchen_stations.id` `SET NULL`. `NULL` = the branch's default screen. |
| `ticket_number` | `VARCHAR(30)` | No | Short display number. |
| `status` | `VARCHAR(20)` | No | `queued`, `preparing`, `ready`, `served`, `cancelled`. |
| `priority` | `TINYINT UNSIGNED` | No | Default `0`; higher jumps the queue. |
| `sla_minutes` | `SMALLINT UNSIGNED` | No | Snapshot from the station. |
| `queued_at` / `started_at` / `ready_at` / `served_at` | `TIMESTAMP` | No / Yes | |
| `prepared_by` | `BIGINT UNSIGNED` | Yes | **FK** → `users.id` `SET NULL` |
| `version` | `INT UNSIGNED` | No | Optimistic locking — two screens may act at once. |

**Indexes:** `uq_kt_order_station (order_id, kitchen_station_id)` ·
`idx_kt_branch_status_queued (branch_id, status, queued_at)` — the KDS poll query ·
`idx_kt_station_status (kitchen_station_id, status)`

**One ticket per order per station.** An order with drinks and food produces two tickets.

#### `kitchen_ticket_items`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `kitchen_ticket_id` | `BIGINT UNSIGNED` | No | **FK** → `kitchen_tickets.id` `CASCADE` |
| `order_item_id` | `BIGINT UNSIGNED` | No | **FK** → `order_items.id` `CASCADE` |
| `status` | `VARCHAR(20)` | No | `queued`, `preparing`, `ready`, `cancelled`. |
| `prepared_at` / `prepared_by` | `TIMESTAMP` / `BIGINT UNSIGNED` | Yes | **FK** → `users.id` `SET NULL` |

**Unique:** `uq_kti_ticket_item (kitchen_ticket_id, order_item_id)`

### 5.8 Inventory

#### `units`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `code` | `VARCHAR(10)` | No | **UNIQUE**: `kg`, `g`, `l`, `ml`, `pcs`. |
| `name` | `VARCHAR(50)` | No | |
| `family` | `VARCHAR(20)` | No | `mass`, `volume`, `count`. **Conversion across families is rejected** (FR-INV-003). |
| `base_unit_id` | `BIGINT UNSIGNED` | Yes | **FK** → self `RESTRICT`. `NULL` marks the family's base unit. |
| `conversion_factor` | `DECIMAL(16,6)` | No | Multiplier to the base unit. `g` → `kg` = `0.001`. |
| `precision_digits` | `TINYINT UNSIGNED` | No | Display rounding. |

**Indexes:** `uq_units_code (code)` · `idx_units_family (family)`

**Seed:** `kg` (base, mass, 1), `g` (mass, 0.001), `l` (base, volume, 1), `ml` (volume, 0.001),
`pcs` (base, count, 1).

#### `ingredients`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `code` | `VARCHAR(30)` | Yes | **UNIQUE** with `deleted_at`. |
| `name` | `VARCHAR(120)` | No | **UNIQUE** with `deleted_at`. |
| `unit_id` | `BIGINT UNSIGNED` | No | **FK** → `units.id` `RESTRICT`. **Stock-keeping unit** — the unit balances are held in. |
| `category` | `VARCHAR(60)` | Yes | Dairy, dry goods, produce. Free-form; not a table, because it is reporting-only. |
| `default_cost_per_unit` | `DECIMAL(12,4)` | No | Fallback when a branch has no movement history. |
| `reorder_level` | `DECIMAL(14,4)` | No | Default `0`. Low-stock threshold (FR-INV-010). |
| `reorder_quantity` | `DECIMAL(14,4)` | Yes | Suggested purchase quantity. |
| `is_perishable` | `BOOLEAN` | No | |
| `shelf_life_days` | `SMALLINT UNSIGNED` | Yes | |
| `is_active` | `BOOLEAN` | No | |
| `created_at` / `updated_at` / `deleted_at` | `TIMESTAMP` | Yes | Soft delete. |

**Indexes:** `uq_ingredients_name (name, deleted_at)` · `uq_ingredients_code (code, deleted_at)` ·
`idx_ingredients_active (is_active)`

#### `recipes`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `product_id` | `BIGINT UNSIGNED` | No | **FK** → `products.id` `CASCADE` |
| `product_variant_id` | `BIGINT UNSIGNED` | Yes | **FK** → `product_variants.id` `CASCADE`. `NULL` = applies to all variants. |
| `version` | `SMALLINT UNSIGNED` | No | Default `1`. Recipes are versioned, never edited in place once used. |
| `yield_quantity` | `DECIMAL(12,3)` | No | Default `1.000`. Units produced per recipe execution. |
| `is_active` | `BOOLEAN` | No | Exactly one active version per (product, variant). |
| `notes` | `TEXT` | Yes | Preparation instructions shown on the KDS. |
| `created_at` / `updated_at` / `deleted_at` | `TIMESTAMP` | Yes | Soft delete. |

**Unique:** `uq_recipes_product_variant_version (product_id, product_variant_id, version, deleted_at)`
**Index:** `idx_recipes_active (product_id, is_active)`

> **Variant resolution:** the exploder prefers a recipe matching the exact `product_variant_id`; if none
> exists, it falls back to the recipe with `product_variant_id IS NULL`. Defined once in
> [14-inventory-workflow.md](14-inventory-workflow.md) §4.2.

> **Versioning rationale:** changing a live recipe would silently change COGS for products already
> costed. A new version is created and the old one deactivated, so historic costing remains explicable.

#### `recipe_items`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `recipe_id` | `BIGINT UNSIGNED` | No | **FK** → `recipes.id` `CASCADE` |
| `ingredient_id` | `BIGINT UNSIGNED` | No | **FK** → `ingredients.id` `RESTRICT` |
| `quantity` | `DECIMAL(12,4)` | No | `> 0`. Per unit of yield. |
| `unit_id` | `BIGINT UNSIGNED` | No | **FK** → `units.id` `RESTRICT`. May differ from the ingredient's stock unit; converted at explosion. |
| `wastage_percent` | `DECIMAL(5,2)` | No | Default `0.00`. Applied on deduction (FR-INV-012). |
| `is_optional` | `BOOLEAN` | No | Optional items do not block a sale when out of stock. |
| `sort_order` | `SMALLINT UNSIGNED` | No | |

**Unique:** `uq_recipe_items (recipe_id, ingredient_id)` — an ingredient may appear at most once per
recipe (FR-INV-004). Two entries for the same ingredient would make the effective quantity ambiguous.

**Check:** `quantity > 0` · `wastage_percent >= 0 AND wastage_percent < 100`

#### `inventories`

Materialised balance, one row per (branch, ingredient) — D2.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `branch_id` | `BIGINT UNSIGNED` | No | **FK** → `branches.id` `CASCADE` |
| `ingredient_id` | `BIGINT UNSIGNED` | No | **FK** → `ingredients.id` `RESTRICT` |
| `quantity_on_hand` | `DECIMAL(14,4)` | No | Default `0`. In the ingredient's stock unit. |
| `reserved_quantity` | `DECIMAL(14,4)` | No | Default `0`. 🔵 Reservation is Post-MVP; the column exists so the balance formula never changes later. |
| `in_transit_quantity` | `DECIMAL(14,4)` | No | Default `0`. Dispatched but not yet received (FR-TRF-006). |
| `average_cost` | `DECIMAL(12,4)` | No | Default `0`. Weighted average ([09](09-business-rules.md) §10). |
| `last_movement_at` | `TIMESTAMP` | Yes | |
| `last_counted_at` | `TIMESTAMP` | Yes | |
| `low_stock_notified_at` | `TIMESTAMP` | Yes | Notification de-duplication (FR-NTF-004). |

**Unique:** `uq_inventories_branch_ingredient (branch_id, ingredient_id)`
**Indexes:** `idx_inventories_branch (branch_id)` · `idx_inventories_ingredient (ingredient_id)`

**Available quantity** = `quantity_on_hand − reserved_quantity`. `in_transit_quantity` is tracked at
the **destination** and is not available anywhere until received.

**Check:** `quantity_on_hand >= 0` — applied only when `inventory.allow_negative_stock` is false.
Because a `CHECK` cannot be conditional, the constraint is enforced in the application; the DB check is
added by a separate migration only for deployments that never allow negative stock.

#### `stock_transactions` — append-only, the system of record

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `branch_id` | `BIGINT UNSIGNED` | No | **FK** → `branches.id` `RESTRICT` |
| `ingredient_id` | `BIGINT UNSIGNED` | No | **FK** → `ingredients.id` `RESTRICT` |
| `type` | `VARCHAR(30)` | No | §4. |
| `quantity_change` | `DECIMAL(14,4)` | No | **Signed.** Negative = consumption. Never zero. |
| `unit_id` | `BIGINT UNSIGNED` | No | **FK** → `units.id` `RESTRICT`. Always the stock unit after conversion. |
| `unit_cost` | `DECIMAL(12,4)` | No | Cost per unit for this movement. |
| `total_cost` | `DECIMAL(14,4)` | No | `ABS(quantity_change) × unit_cost`. |
| `balance_after` | `DECIMAL(14,4)` | No | Resulting `quantity_on_hand`. Makes any point-in-time balance readable without summing. |
| `average_cost_after` | `DECIMAL(12,4)` | No | Resulting weighted average. |
| `reference_type` | `VARCHAR(50)` | Yes | Polymorphic source: `App\Models\Order`, `StockTransfer`, `Refund`. |
| `reference_id` | `BIGINT UNSIGNED` | Yes | |
| `reason` | `VARCHAR(255)` | Yes | **Mandatory** for `adjustment`, `wastage`, `count_correction`. |
| `performed_by` | `BIGINT UNSIGNED` | Yes | **FK** → `users.id` `SET NULL`. `NULL` for system-generated rows. |
| `occurred_at` | `TIMESTAMP` | No | Business time, which may differ from `created_at` for backdated entries. |
| `created_at` | `TIMESTAMP` | No | **No `updated_at`. No `deleted_at`.** |

**Indexes**

| Index | Columns | Serves |
|---|---|---|
| `idx_st_branch_ingredient_occurred` | `(branch_id, ingredient_id, occurred_at)` | Movement report and balance rebuild — the primary access path. |
| `idx_st_reference` | `(reference_type, reference_id)` | "What did this order consume?" |
| `idx_st_type_occurred` | `(type, occurred_at)` | Wastage and adjustment reporting. |
| `idx_st_occurred_at` | `(occurred_at)` | Partition pruning and archival. |

**Check:** `quantity_change <> 0`

**Immutability is enforced at three levels:** no application code path issues `UPDATE` or `DELETE`; the
application database user's grant excludes `UPDATE`/`DELETE` on this table; and a `BEFORE UPDATE`
trigger raises an error. Corrections are made by inserting a compensating row.

#### `stock_transfers`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `transfer_number` | `VARCHAR(30)` | No | **UNIQUE**. |
| `from_branch_id` | `BIGINT UNSIGNED` | No | **FK** → `branches.id` `RESTRICT` |
| `to_branch_id` | `BIGINT UNSIGNED` | No | **FK** → `branches.id` `RESTRICT` |
| `status` | `VARCHAR(20)` | No | §4. |
| `requested_by` | `BIGINT UNSIGNED` | No | **FK** → `users.id` `RESTRICT` |
| `approved_by` / `dispatched_by` / `received_by` / `cancelled_by` | `BIGINT UNSIGNED` | Yes | **FK** → `users.id` `SET NULL` |
| `requested_at` / `submitted_at` / `approved_at` / `dispatched_at` / `received_at` / `cancelled_at` | `TIMESTAMP` | Yes | |
| `expected_arrival_at` | `TIMESTAMP` | Yes | |
| `total_cost` | `DECIMAL(14,4)` | No | Default `0`. Valuation moved. |
| `has_variance` | `BOOLEAN` | No | Set when received ≠ dispatched (FR-TRF-005). |
| `note` / `rejection_reason` | `VARCHAR(500)` / `VARCHAR(255)` | Yes | |
| `created_at` / `updated_at` / `deleted_at` | `TIMESTAMP` | Yes | Soft delete. |

**Indexes:** `uq_transfers_number (transfer_number)` · `idx_transfers_from_status (from_branch_id, status)` ·
`idx_transfers_to_status (to_branch_id, status)` · `idx_transfers_status_created (status, created_at)`

**Check:** `from_branch_id <> to_branch_id` (FR-TRF-001)

#### `stock_transfer_items`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `stock_transfer_id` | `BIGINT UNSIGNED` | No | **FK** → `stock_transfers.id` `CASCADE` |
| `ingredient_id` | `BIGINT UNSIGNED` | No | **FK** → `ingredients.id` `RESTRICT` |
| `unit_id` | `BIGINT UNSIGNED` | No | **FK** → `units.id` `RESTRICT` |
| `requested_quantity` | `DECIMAL(14,4)` | No | `> 0`. |
| `approved_quantity` | `DECIMAL(14,4)` | Yes | May be less than requested. |
| `dispatched_quantity` | `DECIMAL(14,4)` | Yes | |
| `received_quantity` | `DECIMAL(14,4)` | Yes | |
| `variance_quantity` | `DECIMAL(14,4)` | Yes | `received − dispatched`. Signed. |
| `unit_cost` | `DECIMAL(12,4)` | Yes | Source branch average cost at dispatch. |
| `variance_reason` | `VARCHAR(255)` | Yes | Mandatory when variance ≠ 0. |

**Unique:** `uq_sti_transfer_ingredient (stock_transfer_id, ingredient_id)`

### 5.9 Finance

#### `expense_categories`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `code` | `VARCHAR(30)` | No | **UNIQUE**. |
| `name` | `VARCHAR(100)` | No | |
| `is_cogs_related` | `BOOLEAN` | No | Whether it belongs in COGS rather than operating expense — affects the profit report ([18](18-reporting.md) §6). |
| `requires_approval` | `BOOLEAN` | No | |
| `is_active` | `BOOLEAN` | No | |

#### `expenses`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `branch_id` | `BIGINT UNSIGNED` | No | **FK** → `branches.id` `RESTRICT` |
| `expense_category_id` | `BIGINT UNSIGNED` | No | **FK** → `expense_categories.id` `RESTRICT` |
| `expense_number` | `VARCHAR(30)` | No | **UNIQUE**. |
| `amount` | `DECIMAL(12,2)` | No | Net. `> 0`. |
| `tax_amount` | `DECIMAL(12,2)` | No | Default `0.00`. |
| `total_amount` | `DECIMAL(12,2)` | No | `amount + tax_amount`. |
| `currency_code` | `CHAR(3)` | No | |
| `payment_method` | `VARCHAR(20)` | Yes | |
| `vendor_name` | `VARCHAR(150)` | Yes | |
| `vendor_tax_id` | `VARCHAR(50)` | Yes | |
| `description` | `VARCHAR(500)` | No | |
| `expense_date` | `DATE` | No | Business date the cost belongs to. |
| `receipt_path` | `VARCHAR(255)` | Yes | Object-storage key. |
| `status` | `VARCHAR(20)` | No | §4. |
| `created_by` | `BIGINT UNSIGNED` | No | **FK** → `users.id` `RESTRICT` |
| `submitted_at` | `TIMESTAMP` | Yes | |
| `approved_by` / `approved_at` | `BIGINT UNSIGNED` / `TIMESTAMP` | Yes | **FK** → `users.id` `SET NULL` |
| `rejected_by` / `rejected_at` / `rejection_reason` | | Yes | |
| `paid_at` / `paid_by` | | Yes | |
| `created_at` / `updated_at` / `deleted_at` | `TIMESTAMP` | Yes | Soft delete. |

**Indexes:** `uq_expenses_number (expense_number)` · `idx_expenses_branch_date (branch_id, expense_date)` ·
`idx_expenses_status (status)` · `idx_expenses_category (expense_category_id)` ·
`idx_expenses_created_by (created_by)`

**Checks:** `amount > 0` · `tax_amount >= 0` · `total_amount = amount + tax_amount`
**Rule:** `approved_by <> created_by` (FR-EXP-004) — enforced in the application, since a `CHECK` cannot
reference a value set in a later `UPDATE` reliably across MySQL versions.

### 5.10 Platform

#### `notifications`

Laravel's standard database notification table.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `CHAR(36)` | No | **PK**, UUID. |
| `type` | `VARCHAR(255)` | No | Notification class. |
| `notifiable_type` / `notifiable_id` | `VARCHAR(255)` / `BIGINT UNSIGNED` | No | Polymorphic → `users`. |
| `data` | `JSON` | No | Payload. Must not contain cost data for recipients lacking `products.view_cost`. |
| `severity` | `VARCHAR(20)` | No | `info`, `warning`, `critical`. **Added** to the standard table for filtering. |
| `branch_id` | `BIGINT UNSIGNED` | Yes | **FK** → `branches.id` `CASCADE`. **Added** for branch filtering. |
| `read_at` | `TIMESTAMP` | Yes | |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | |

**Indexes:** `idx_notifications_notifiable (notifiable_type, notifiable_id, read_at)` ·
`idx_notifications_branch_created (branch_id, created_at)`

#### `audit_logs` — append-only

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | **PK** |
| `user_id` | `BIGINT UNSIGNED` | Yes | **FK** → `users.id` `SET NULL`. `NULL` for system actions. |
| `impersonator_id` | `BIGINT UNSIGNED` | Yes | **FK** → `users.id` `SET NULL`. Real actor during impersonation. |
| `branch_id` | `BIGINT UNSIGNED` | Yes | **FK** → `branches.id` `SET NULL` |
| `event` | `VARCHAR(50)` | No | `created`, `updated`, `deleted`, `voided`, `approved`, `login_failed`, … |
| `auditable_type` / `auditable_id` | `VARCHAR(100)` / `BIGINT UNSIGNED` | No | Polymorphic target. |
| `old_values` / `new_values` | `JSON` | Yes | Changed attributes only. **Redacted** for `password`, `pin_hash`, `token`, `remember_token`. |
| `description` | `VARCHAR(500)` | Yes | Human-readable summary. |
| `ip_address` | `VARCHAR(45)` | Yes | |
| `user_agent` | `VARCHAR(500)` | Yes | |
| `request_id` | `CHAR(36)` | Yes | Correlates to application logs. |
| `route` | `VARCHAR(255)` | Yes | |
| `created_at` | `TIMESTAMP` | No | **No `updated_at`. No `deleted_at`.** |

**Indexes:** `idx_audit_auditable (auditable_type, auditable_id, created_at)` ·
`idx_audit_user_created (user_id, created_at)` · `idx_audit_event_created (event, created_at)` ·
`idx_audit_branch_created (branch_id, created_at)` · `idx_audit_request (request_id)`

---

## 6. Relationship summary

| Parent | Child | Cardinality | Delete rule |
|---|---|---|---|
| `branches` | `users` | 1 : 0..N | `SET NULL` |
| `branches` | `orders`, `payments`, `inventories`, `expenses` | 1 : 0..N | `RESTRICT` |
| `users` | `orders` | 1 : 0..N | `RESTRICT` |
| `users` ↔ `roles` | `role_user` | M : N | `CASCADE` |
| `roles` ↔ `permissions` | `role_permission` | M : N | `CASCADE` |
| `categories` | `categories` | 1 : 0..N (self, depth ≤ 2) | `RESTRICT` |
| `categories` | `products` | 1 : 0..N | `RESTRICT` |
| `products` | `product_variants` | 1 : 0..N | `CASCADE` |
| `products` ↔ `branches` | `branch_product` | M : N | `CASCADE` |
| `products` | `recipes` | 1 : 0..N (versioned) | `CASCADE` |
| `recipes` | `recipe_items` | 1 : 1..N | `CASCADE` |
| `ingredients` | `recipe_items` | 1 : 0..N | `RESTRICT` |
| `ingredients` ↔ `branches` | `inventories` | M : N | `CASCADE` / `RESTRICT` |
| `ingredients` | `stock_transactions` | 1 : 0..N | `RESTRICT` |
| `orders` | `order_items` | 1 : 1..N | `CASCADE` |
| `orders` | `payments` | 1 : 0..N | `RESTRICT` |
| `orders` | `order_status_histories` | 1 : 1..N | `CASCADE` |
| `orders` | `kitchen_tickets` | 1 : 0..N | `CASCADE` |
| `payments` | `refunds` | 1 : 0..N | `RESTRICT` |
| `customers` | `orders` | 1 : 0..N | `SET NULL` |
| `customers` | `loyalty_transactions` | 1 : 0..N | `RESTRICT` |
| `stock_transfers` | `stock_transfer_items` | 1 : 1..N | `CASCADE` |
| `branches` | `stock_transfers` | 1 : 0..N (twice: from, to) | `RESTRICT` |

Visualised in [06-erd.md](06-erd.md).

---

## 7. Indexing strategy

### 7.1 Principles

1. Every foreign key is indexed. MySQL requires it for the constraint, and it is needed for joins.
2. Composite index column order follows **equality → range → sort**.
3. Branch-scoped tables lead their composite indexes with `branch_id`, because every query filters on it.
4. Covering indexes are added only where a measured query needs them; each extra index slows writes, and
   `stock_transactions` is write-heavy.
5. Indexes are justified by a named query. An index with no query is deleted.

### 7.2 Critical query paths

| # | Query | Index used | Notes |
|---|---|---|---|
| Q1 | POS product list by branch + category | `idx_products_active_available` + `branch_product` PK | Cached 5 min; invalidated on product write. |
| Q2 | POS search by name | `ft_products_name` FULLTEXT | Falls back to `LIKE 'term%'` for prefixes under 3 characters. |
| Q3 | KDS ticket poll | `idx_kt_branch_status_queued` | Runs every 5 s per screen — the highest-frequency query in the system. |
| Q4 | Active orders for a branch | `idx_orders_branch_status` | |
| Q5 | Daily sales report | `idx_orders_branch_business_date` | |
| Q6 | Stock level check during a sale | `uq_inventories_branch_ingredient` | Point lookup under `FOR UPDATE`. |
| Q7 | Ingredient movement report | `idx_st_branch_ingredient_occurred` | |
| Q8 | "What did this order consume?" | `idx_st_reference` | |
| Q9 | Customer lookup by phone at POS | `uq_customers_phone` | |
| Q10 | Loyalty balance reconciliation | `idx_lt_customer_created` | |
| Q11 | Unread notifications | `idx_notifications_notifiable` | |
| Q12 | Audit trail for a record | `idx_audit_auditable` | |
| Q13 | Cashier shift report | `idx_orders_user_business_date` | |
| Q14 | Pending transfers for a branch | `idx_transfers_from_status`, `idx_transfers_to_status` | Two indexes because the `OR` cannot use one. |

### 7.3 Anti-patterns explicitly avoided

| Anti-pattern | Why avoided |
|---|---|
| Index on a low-cardinality boolean alone | `is_active` alone is useless; it appears only as a leading column with others. |
| `SELECT MAX(order_number) + 1` | Race condition. `daily_sequences` instead. |
| `LIKE '%term%'` on product search | Cannot use an index. FULLTEXT instead. |
| Summing `stock_transactions` for a live balance | Millions of rows. `inventories` instead (D2). |
| Foreign key without an index | MySQL creates one implicitly, but not always in the useful order. All are explicit. |
| `ENUM` columns | `ALTER TABLE` on a large table to add a value. `VARCHAR` + `CHECK` instead. |

---

## 8. Constraints summary

### 8.1 Database-enforced

| Type | Count | Examples |
|---|---|---|
| Primary keys | 41 | |
| Foreign keys | ~70 | All with an explicit delete rule. |
| Unique constraints | ~30 | §1.5. |
| Check constraints | ~25 | `quantity > 0`, `amount > 0`, `from_branch_id <> to_branch_id`. |
| Not-null | Extensive | Defaults are used only where a business default genuinely exists. |

### 8.2 Application-enforced

Rules the database cannot express. Each requires a test.

| ID | Rule | Where enforced |
|---|---|---|
| C1 | Category depth ≤ 2 | `CategoryService` |
| C2 | Exactly one active recipe per (product, variant) | `RecipeService` |
| C3 | Exactly one active `loyalty_rules` row | `LoyaltyRuleService` |
| C4 | Exactly one default variant per product | `ProductVariantService` |
| C5 | `orders.grand_total` equals the calculated value | `OrderCalculator` + a post-write assertion |
| C6 | `Σ payments.amount (captured) = orders.paid_total` | `PaymentService` |
| C7 | `Σ loyalty_transactions.points = customers.loyalty_points_balance` | Nightly reconciliation |
| C8 | `Σ stock_transactions.quantity_change = inventories.quantity_on_hand` | Nightly reconciliation |
| C9 | Order status transitions follow the state machine | `OrderStateMachine` |
| C10 | Expense approver ≠ creator | `ExpensePolicy` |
| C11 | Transfer approver has authority at the source branch | `TransferPolicy` |
| C12 | Recipe unit and ingredient unit share a family | `UnitConverter` |
| C13 | `inventory_deducted_at` set at most once per order | `InventoryService` (idempotency) |
| C14 | Non-cash payments carry a reference | `StorePaymentRequest` |
| C15 | At most one open cash drawer session per user per branch | Generated-column unique index + service check |

---

## 9. Example queries

Illustrative only; production code uses the ORM with the global branch scope applied.

**Effective product price at a branch** ([09](09-business-rules.md) §3):

```sql
SELECT p.id,
       p.name,
       COALESCE(bp.price_override, p.base_price) + COALESCE(v.price_delta, 0) AS effective_price
FROM products p
LEFT JOIN branch_product bp
       ON bp.product_id = p.id AND bp.branch_id = :branch_id
LEFT JOIN product_variants v
       ON v.product_id = p.id AND v.id = :variant_id AND v.deleted_at IS NULL
WHERE p.id = :product_id
  AND p.deleted_at IS NULL
  AND p.is_active = 1
  AND COALESCE(bp.is_available, 1) = 1;
```

**Ingredients required for an order** (the recipe explosion, expressed in SQL):

```sql
SELECT ri.ingredient_id,
       SUM(oi.quantity * ri.quantity * (1 + ri.wastage_percent / 100)
           * u.conversion_factor / iu.conversion_factor) AS required_in_stock_unit
FROM order_items oi
JOIN recipes r
  ON r.product_id = oi.product_id
 AND (r.product_variant_id = oi.product_variant_id OR r.product_variant_id IS NULL)
 AND r.is_active = 1
 AND r.deleted_at IS NULL
JOIN recipe_items ri ON ri.recipe_id = r.id
JOIN units u  ON u.id = ri.unit_id
JOIN ingredients i ON i.id = ri.ingredient_id
JOIN units iu ON iu.id = i.unit_id
WHERE oi.order_id = :order_id
  AND oi.status <> 'voided'
GROUP BY ri.ingredient_id;
```

> The variant-specific recipe must win over the generic one. The `OR ... IS NULL` above would match
> both. The application resolves the recipe **per line** before this aggregation; the SQL is shown for
> illustration. See [14-inventory-workflow.md](14-inventory-workflow.md) §4.2.

**Rebuild an inventory balance from the ledger** (FR-INV-015):

```sql
SELECT branch_id,
       ingredient_id,
       SUM(quantity_change) AS computed_balance
FROM stock_transactions
WHERE branch_id = :branch_id
GROUP BY branch_id, ingredient_id;
```

**Low-stock ingredients at a branch:**

```sql
SELECT i.name, inv.quantity_on_hand, i.reorder_level
FROM inventories inv
JOIN ingredients i ON i.id = inv.ingredient_id
WHERE inv.branch_id = :branch_id
  AND i.is_active = 1
  AND i.deleted_at IS NULL
  AND inv.quantity_on_hand <= i.reorder_level;
```

---

## 10. Migration strategy

### 10.1 Ordering

Dependencies force this order; a migration that violates it will fail on the foreign key.

```text
01  branches
02  users (modify the existing framework table)
03  roles, permissions, role_permission, role_user
04  settings, tax_rates, daily_sequences
05  units, ingredients
06  kitchen_stations
07  categories, products, product_variants, branch_product
08  recipes, recipe_items
09  inventories
10  loyalty_tiers, loyalty_rules, customers
11  cash_drawer_sessions
12  orders, order_items, order_status_histories
13  payments, refunds
14  kitchen_tickets, kitchen_ticket_items
15  stock_transactions
16  stock_transfers, stock_transfer_items
17  expense_categories, expenses
18  loyalty_transactions
19  notifications (extend Laravel's), audit_logs
20  Sanctum personal_access_tokens
21  triggers and check constraints
```

`kitchen_stations` precedes `categories` because `categories.kitchen_station_id` references it.

### 10.2 Rules

| Rule | Detail |
|---|---|
| M1 | Every migration is reversible, or its `down()` documents why not (NFR-MNT-003). |
| M2 | Never edit a migration that has run in any shared environment. Add a new one. |
| M3 | Data migrations are separate from schema migrations, and are idempotent. |
| M4 | Adding a non-nullable column to a populated table is a three-step deploy: add nullable → backfill → set not-null. |
| M5 | Index creation on large tables uses `ALGORITHM=INPLACE, LOCK=NONE`. |
| M6 | Every migration is tested against a production-sized copy before release. |
| M7 | A migration that takes a long lock is scheduled outside service hours ([26](26-deployment.md) §6). |

### 10.3 Seeders

| Seeder | Environment | Contents |
|---|---|---|
| `PermissionSeeder` | All | The full catalogue from [04](04-user-roles-permissions.md) §3. Idempotent. |
| `RoleSeeder` | All | Six system roles and the §4 matrix. Idempotent. |
| `UnitSeeder` | All | kg, g, l, ml, pcs. |
| `SuperAdminSeeder` | All | One Super Admin from environment variables. **Fails** if the password variable is unset — never a hard-coded default. |
| `SettingSeeder` | All | Non-financial defaults only. **No tax rates, no loyalty rates** ([01](01-project-overview.md) A3). |
| `DemoDataSeeder` | local, staging | Branches, menu, recipes, customers, historical orders. **Never** in production. |

---

## 11. Data integrity jobs

Each denormalisation (§3.1) has a reconciliation job. All are read-only in normal operation; they log a
discrepancy and raise a critical notification rather than silently repairing, because silent repair
hides the bug that caused the drift.

| Job | Schedule | Checks | On mismatch |
|---|---|---|---|
| `ReconcileInventoryBalances` | Nightly 03:00 | C8: ledger sum vs `inventories.quantity_on_hand` | Log, notify Admin, write a `count_correction` only with explicit operator approval. |
| `ReconcileLoyaltyBalances` | Nightly 03:15 | C7 | Log and notify. |
| `ReconcileOrderPaymentTotals` | Hourly | C6: `Σ captured payments` vs `orders.paid_total` | Log and notify — a mismatch is a money bug. |
| `ReconcileOrderTotals` | Nightly 03:30 | C5: recompute totals from lines and compare | Log only; never rewrite a settled order. |
| `ExpireLoyaltyPoints` | Daily 02:00 | Writes `expire` rows | Normal operation. |
| `CheckLowStock` | Every 15 min | `quantity_on_hand <= reorder_level` | Notify, respecting the cool-down. |
| `CloseStaleDrawerSessions` | Daily 04:00 | Sessions open > 24 h | Notify the branch manager. |

---

## 12. Growth, partitioning and archival

At 15 branches and 800 000 orders per year:

| Table | Yearly growth | Strategy |
|---|---|---|
| `stock_transactions` | ~8 M rows | 🔵 `RANGE` partition by `YEAR(occurred_at)`. Partitions older than 3 years are archived to cold storage after the balance is verified. |
| `audit_logs` | ~6 M rows | 🔵 `RANGE` partition by month. Rows older than 24 months are exported to object storage and dropped (NFR-CMP-002). |
| `order_items` | ~3 M rows | No partitioning; access is always by `order_id`. |
| `orders` | ~800 k rows | No partitioning. Kept indefinitely (NFR-CMP-001). |
| `notifications` | ~200 k rows | Read notifications older than 90 days are deleted. |
| `personal_access_tokens` | — | Expired tokens pruned daily. |

**Partitioning caveat:** MySQL requires every unique key on a partitioned table to include the
partition column. `audit_logs` has no unique key beyond `id`, so partitioning by month requires the
primary key to become `(id, created_at)`. This is why partitioning is marked 🔵 — it is a schema change
that must be planned, not applied casually.

---

## 13. Security considerations

| # | Consideration | Mitigation |
|---|---|---|
| S1 | Password or PIN exposure | Hashed; excluded from serialisation by default; redacted in `audit_logs`. |
| S2 | Card data storage | Only `card_last_four` and `card_brand`. No PAN, no CVV, no track data — [22](22-security.md) §10. |
| S3 | Cross-branch leakage | `branch_id` on every branch-owned table + a global scope + policies ([04](04-user-roles-permissions.md) §5). |
| S4 | Ledger tampering | Append-only, no `UPDATE`/`DELETE` grant, trigger-enforced. |
| S5 | Audit tampering | Same as S4. |
| S6 | SQL injection | Parameterised queries only. Filter and sort parameters are whitelisted, never interpolated. |
| S7 | Mass assignment | `$fillable` whitelists; `branch_id`, `user_id`, `status`, `payment_status`, `paid_total` are never fillable. |
| S8 | PII in backups | Backups encrypted at rest; access restricted; retention documented ([26](26-deployment.md) §9). |
| S9 | Customer erasure vs financial retention | `customers.anonymised_at`: personal fields are nulled, `orders.customer_id` is retained. Financial totals survive; the person does not. |
| S10 | Over-privileged DB user | The application user has no `DROP`, no `ALTER`, and no `UPDATE`/`DELETE` on ledger tables. Migrations run as a separate user. |

---

## 14. Testing considerations

| Area | Test |
|---|---|
| Migrations | `migrate:fresh` then `migrate:rollback` to zero, on MySQL, in CI. SQLite is not sufficient — check constraints and generated columns differ. |
| Foreign keys | Attempt to delete a referenced parent; assert the correct `RESTRICT`/`CASCADE`/`SET NULL` behaviour for each. |
| Unique constraints | Attempt duplicates; assert `422`. Then soft-delete and re-create with the same value; assert success where §1.5 permits it and failure where it does not. |
| Check constraints | Attempt negative quantities, zero-quantity ledger rows, `from_branch = to_branch`. |
| Soft deletes | Assert deleted rows are excluded from default queries and included with `withTrashed()`. |
| Snapshot immutability | Create an order, change the product's price and name, reload the order; assert the line is unchanged. |
| Ledger immutability | Attempt `UPDATE` and `DELETE` on `stock_transactions`; assert both fail. |
| Balance reconciliation | Generate 1 000 random movements; assert the ledger sum equals `quantity_on_hand`. |
| Generated columns | Assert `role_user` rejects a duplicate with `NULL` branch, and `cash_drawer_sessions` rejects a second open session. |
| Concurrency | See [24](24-qa-test-plan.md) §10 — run against MySQL, never SQLite. |
| Index effectiveness | `EXPLAIN` assertions on Q1–Q14 in a nightly job; fail on a full table scan of a large table. |
| Seeders | Idempotency: run twice, assert no duplicates and no errors. |

> **Test-database warning.** The repository currently defaults to SQLite. Feature tests that touch
> check constraints, generated columns, `FOR UPDATE` locking or FULLTEXT **must** run against MySQL.
> See [23-testing-strategy.md](23-testing-strategy.md) §4.

---

## 15. Related documents

[06-erd.md](06-erd.md) ·
[09-business-rules.md](09-business-rules.md) ·
[14-inventory-workflow.md](14-inventory-workflow.md) ·
[20-validation-rules.md](20-validation-rules.md) ·
[22-security.md](22-security.md) ·
[26-deployment.md](26-deployment.md)
