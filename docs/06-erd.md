# 06 — Entity Relationship Diagrams

> **Document purpose.** Visualise the relationships defined in
> [05-database-design.md](05-database-design.md). This document shows **structure and cardinality**;
> it deliberately does not restate column types, nullability or index definitions — those live in
> [05](05-database-design.md), which remains the single authoritative schema reference.

**Prerequisite:** [05-database-design.md](05-database-design.md).

**Status:** 🟡 MVP — Planned.

---

## 1. How to read these diagrams

### 1.1 Notation

Mermaid crow's-foot notation:

| Symbol | Meaning |
|---|---|
| `\|\|--\|\|` | Exactly one to exactly one |
| `\|\|--o{` | Exactly one to zero or many |
| `\|\|--\|{` | Exactly one to one or many |
| `}o--o{` | Many to many (through a pivot) |
| `PK` | Primary key |
| `FK` | Foreign key |
| `UK` | Unique key |

Attribute types are shown in simplified form (`decimal`, not `DECIMAL(12,2)`) because the Mermaid ER
grammar does not accept parentheses in a type. Precise types are in [05](05-database-design.md) §5.

### 1.2 Diagram set

The full schema is 41 tables — one diagram would be unreadable. It is presented as one high-level map
plus seven module diagrams that overlap where they share a table.

| § | Diagram | Tables |
|---|---|---|
| 2 | High-level map | All modules, no attributes |
| 3 | Identity and access | `users`, `roles`, `permissions`, pivots, `branches` |
| 4 | Catalogue | `categories`, `products`, `product_variants`, `branch_product`, `tax_rates` |
| 5 | Sales | `orders`, `order_items`, `payments`, `refunds`, histories, drawer sessions |
| 6 | Kitchen | `kitchen_stations`, `kitchen_tickets`, `kitchen_ticket_items` |
| 7 | Inventory | `ingredients`, `units`, `recipes`, `inventories`, `stock_transactions` |
| 8 | Stock transfers | `stock_transfers`, `stock_transfer_items` |
| 9 | Customers and loyalty | `customers`, tiers, rules, `loyalty_transactions` |
| 10 | Finance and platform | `expenses`, `notifications`, `audit_logs`, `settings` |

---

## 2. High-level map

Table-level only. Colour is not used; grouping is by subgraph.

```mermaid
flowchart TB
    subgraph IAM["Identity and access"]
        users
        roles
        permissions
        role_user
        role_permission
    end

    subgraph CORE["Core"]
        branches
        settings
        tax_rates
        daily_sequences
    end

    subgraph CATALOG["Catalogue"]
        categories
        products
        product_variants
        branch_product
    end

    subgraph CRM["Customers"]
        customers
        loyalty_tiers
        loyalty_rules
        loyalty_transactions
    end

    subgraph SALES["Sales"]
        orders
        order_items
        order_status_histories
        payments
        refunds
        cash_drawer_sessions
    end

    subgraph KITCHEN["Kitchen"]
        kitchen_stations
        kitchen_tickets
        kitchen_ticket_items
    end

    subgraph INV["Inventory"]
        units
        ingredients
        recipes
        recipe_items
        inventories
        stock_transactions
        stock_transfers
        stock_transfer_items
    end

    subgraph FIN["Finance"]
        expense_categories
        expenses
    end

    subgraph PLAT["Platform"]
        notifications
        audit_logs
    end

    branches --> users
    branches --> orders
    branches --> inventories
    branches --> expenses
    branches --> kitchen_stations
    branches --> cash_drawer_sessions
    branches --> daily_sequences
    branches --> branch_product
    branches --> settings

    users --> role_user --> roles --> role_permission --> permissions
    users --> orders
    users --> cash_drawer_sessions

    categories --> products --> product_variants
    products --> branch_product
    tax_rates --> products
    categories --> kitchen_stations

    customers --> orders
    loyalty_tiers --> customers
    customers --> loyalty_transactions
    orders --> loyalty_transactions
    loyalty_rules -.->|configures| loyalty_transactions

    orders --> order_items
    orders --> order_status_histories
    orders --> payments --> refunds
    orders --> kitchen_tickets --> kitchen_ticket_items
    order_items --> kitchen_ticket_items
    kitchen_stations --> kitchen_tickets
    cash_drawer_sessions --> payments

    products --> order_items
    product_variants --> order_items

    products --> recipes --> recipe_items --> ingredients
    units --> ingredients
    units --> recipe_items
    ingredients --> inventories
    ingredients --> stock_transactions
    orders -.->|recipe explosion| stock_transactions
    stock_transfers --> stock_transfer_items --> ingredients
    stock_transfers -.-> stock_transactions

    expense_categories --> expenses

    users -.-> notifications
    users -.-> audit_logs
```

Dotted edges are **polymorphic or behavioural** links rather than declared foreign keys:

- `orders ⇢ stock_transactions` — the ledger row records `reference_type = Order`, `reference_id`.
- `stock_transfers ⇢ stock_transactions` — same mechanism.
- `loyalty_rules ⇢ loyalty_transactions` — rules are read at calculation time and snapshotted into the
  resulting points, not joined.
- `users ⇢ audit_logs` / `notifications` — polymorphic `auditable` / `notifiable`.

---

## 3. Identity and access

```mermaid
erDiagram
    BRANCHES ||--o{ USERS : "is home branch of"
    BRANCHES ||--o{ ROLE_USER : "scopes"
    USERS ||--o{ ROLE_USER : "holds"
    ROLES ||--o{ ROLE_USER : "assigned through"
    ROLES ||--o{ ROLE_PERMISSION : "grants"
    PERMISSIONS ||--o{ ROLE_PERMISSION : "granted through"
    USERS ||--o{ PERSONAL_ACCESS_TOKENS : "authenticates with"
    USERS ||--o{ ROLE_USER : "assigned by"

    BRANCHES {
        bigint id PK
        varchar code UK
        varchar name
        varchar timezone
        char currency_code
        time business_day_start
        boolean is_active
        timestamp deleted_at
    }

    USERS {
        bigint id PK
        bigint branch_id FK "nullable, home branch"
        varchar name
        varchar email UK
        varchar password "hashed"
        varchar pin_hash "nullable, POS PIN"
        boolean is_active
        timestamp last_login_at
        timestamp deleted_at
    }

    ROLES {
        bigint id PK
        varchar name UK "super_admin admin manager cashier kitchen staff"
        varchar display_name
        smallint rank "100 80 60 40 40 20"
        boolean is_system
    }

    PERMISSIONS {
        bigint id PK
        varchar name UK "module.action"
        varchar group
        boolean is_dangerous
    }

    ROLE_USER {
        bigint user_id PK_FK
        bigint role_id PK_FK
        bigint branch_id FK "nullable, scoped assignment"
        bigint assigned_by FK
        bigint branch_key "generated, uniqueness helper"
    }

    ROLE_PERMISSION {
        bigint role_id PK_FK
        bigint permission_id PK_FK
    }

    PERSONAL_ACCESS_TOKENS {
        bigint id PK
        varchar tokenable_type
        bigint tokenable_id
        varchar name "device label"
        varchar token UK "sha256 hash"
        text abilities
        timestamp last_used_at
        timestamp expires_at
    }
```

**Cardinality notes**

| Relationship | Rule |
|---|---|
| `users` → `branches` | Optional. Super Admin and Admin have no home branch. |
| `users` ↔ `roles` | Many-to-many. A user **must** hold at least one role to be functional; a user with zero roles authenticates but is denied everywhere ([04](04-user-roles-permissions.md) E2). |
| `role_user.branch_id` | Nullable. `NULL` means the role applies at the home branch or across the role's natural reach. The `branch_key` generated column exists solely so the unique constraint works with `NULL`s ([05](05-database-design.md) §5.2). |
| `role_permission` | Pure pivot, no timestamps. Changes are captured in `audit_logs`. |

---

## 4. Catalogue

```mermaid
erDiagram
    CATEGORIES ||--o{ CATEGORIES : "parent of, max depth 2"
    CATEGORIES ||--o{ PRODUCTS : "contains"
    CATEGORIES }o--|| KITCHEN_STATIONS : "routes tickets to"
    PRODUCTS ||--o{ PRODUCT_VARIANTS : "has"
    PRODUCTS ||--o{ BRANCH_PRODUCT : "priced per branch by"
    BRANCHES ||--o{ BRANCH_PRODUCT : "overrides"
    TAX_RATES ||--o{ PRODUCTS : "applies to"
    PRODUCTS ||--o{ RECIPES : "is made by"
    PRODUCT_VARIANTS ||--o{ RECIPES : "may specialise"

    CATEGORIES {
        bigint id PK
        bigint parent_id FK "nullable, self"
        bigint kitchen_station_id FK "nullable"
        varchar name
        varchar slug UK "unique with deleted_at"
        smallint sort_order
        boolean is_active
        timestamp deleted_at
    }

    PRODUCTS {
        bigint id PK
        bigint category_id FK
        bigint tax_rate_id FK "nullable, else branch default"
        varchar sku UK "unique with deleted_at"
        varchar name
        decimal base_price
        decimal cost_price "nullable, manual cost when no recipe"
        boolean is_active
        boolean is_available
        boolean track_inventory
        boolean has_variants
        smallint preparation_minutes
        timestamp deleted_at
    }

    PRODUCT_VARIANTS {
        bigint id PK
        bigint product_id FK
        varchar sku UK
        varchar name "Large Small"
        decimal price_delta "signed"
        decimal cost_delta
        boolean is_default "exactly one per product"
        boolean is_active
        timestamp deleted_at
    }

    BRANCH_PRODUCT {
        bigint branch_id PK_FK
        bigint product_id PK_FK
        decimal price_override "nullable"
        boolean is_available
    }

    TAX_RATES {
        bigint id PK
        varchar code UK
        decimal rate "fraction not percent"
        boolean is_inclusive
        boolean is_active
        date effective_from
        date effective_to
    }
```

**Effective price** is resolved from three sources — product base price, branch override, variant delta.
The single authoritative formula is in [09-business-rules.md](09-business-rules.md) §3, and the SQL in
[05](05-database-design.md) §9.

**Why `branch_product` is a pivot with payload.** Storing the override on `products` would need one
column per branch. Storing it on a separate `product_prices` table with an effective date range was
considered and rejected as unnecessary for MVP: menu price history is not a requirement, and
`order_items.unit_price` already preserves what was actually charged.

---

## 5. Sales

The core transactional cluster.

```mermaid
erDiagram
    BRANCHES ||--o{ ORDERS : "records"
    USERS ||--o{ ORDERS : "creates"
    CUSTOMERS |o--o{ ORDERS : "may be attached to"
    CASH_DRAWER_SESSIONS |o--o{ ORDERS : "groups"
    ORDERS ||--|{ ORDER_ITEMS : "contains"
    ORDERS ||--|{ ORDER_STATUS_HISTORIES : "transitions through"
    ORDERS ||--o{ PAYMENTS : "settled by"
    PAYMENTS ||--o{ REFUNDS : "reversed by"
    ORDERS ||--o{ REFUNDS : "reduced by"
    PRODUCTS ||--o{ ORDER_ITEMS : "sold as"
    PRODUCT_VARIANTS |o--o{ ORDER_ITEMS : "specialises"
    USERS ||--o{ CASH_DRAWER_SESSIONS : "operates"
    CASH_DRAWER_SESSIONS ||--o{ PAYMENTS : "collects"

    ORDERS {
        bigint id PK
        bigint branch_id FK
        varchar order_number UK "unique per branch"
        date business_date "branch local day"
        varchar order_type "dine_in takeaway delivery"
        varchar table_number "nullable, dine_in only"
        bigint customer_id FK "nullable"
        bigint user_id FK "cashier"
        varchar status "pending accepted preparing ready completed cancelled"
        varchar payment_status "derived, never client set"
        decimal subtotal
        decimal discount_amount
        decimal loyalty_discount_amount
        decimal taxable_amount
        decimal tax_amount
        decimal service_charge_amount
        decimal rounding_adjustment "signed"
        decimal grand_total
        decimal paid_total "derived"
        decimal refunded_total
        decimal change_due
        decimal cogs_total
        timestamp inventory_deducted_at "idempotency guard"
        int version "optimistic lock"
        varchar idempotency_key UK
        timestamp deleted_at
    }

    ORDER_ITEMS {
        bigint id PK
        bigint order_id FK
        bigint product_id FK
        bigint product_variant_id FK "nullable"
        varchar name_snapshot "history, not a copy"
        varchar sku_snapshot
        decimal quantity
        decimal unit_price "snapshot"
        decimal line_subtotal
        decimal line_discount_amount "own plus allocated share"
        decimal taxable_amount
        decimal tax_rate_snapshot
        boolean is_tax_inclusive
        decimal line_tax_amount
        decimal line_total
        decimal unit_cost_snapshot "drives COGS"
        decimal line_cogs
        varchar status
        varchar note
    }

    ORDER_STATUS_HISTORIES {
        bigint id PK
        bigint order_id FK
        varchar from_status "nullable on first row"
        varchar to_status
        bigint changed_by FK "nullable for system"
        varchar reason "mandatory on cancel"
        int duration_seconds
        timestamp created_at "append only"
    }

    PAYMENTS {
        bigint id PK
        bigint order_id FK
        bigint branch_id FK "denormalised"
        varchar payment_number UK
        varchar method "cash card qr bank_transfer"
        decimal amount "applied to order"
        decimal tendered_amount "cash only"
        decimal change_amount "cash only"
        varchar status "pending captured failed voided refunded"
        varchar reference "mandatory for non cash"
        char card_last_four "only card data stored"
        bigint received_by FK
        decimal refunded_total
        varchar idempotency_key UK
        timestamp paid_at
    }

    REFUNDS {
        bigint id PK
        bigint payment_id FK
        bigint order_id FK "denormalised"
        bigint branch_id FK
        varchar refund_number UK
        decimal amount
        varchar method "may differ from original"
        varchar reason "mandatory"
        boolean restock_inventory
        bigint requested_by FK
        bigint approved_by FK
        timestamp refunded_at
    }

    CASH_DRAWER_SESSIONS {
        bigint id PK
        bigint branch_id FK
        bigint user_id FK
        date business_date
        decimal opening_float
        decimal expected_cash
        decimal counted_cash
        decimal variance "signed"
        varchar status "open closed"
        varchar open_guard UK "generated, one open per user"
    }
```

**Cardinality notes**

| Relationship | Notation | Reason |
|---|---|---|
| `orders → order_items` | `\|\|--\|{` one-or-many | An order with no lines is meaningless and is rejected at validation. |
| `orders → order_status_histories` | `\|\|--\|{` one-or-many | Creation itself writes the first history row (`NULL → pending`). |
| `orders → payments` | `\|\|--o{` zero-or-many | Pending orders are unpaid; split tender creates several. |
| `customers → orders` | `\|o--o{` optional both ways | Walk-in sales have no customer; a customer may have no orders. |
| `payments → refunds` | `\|\|--o{` | A refund always names the payment it reverses, so the money path is traceable. |
| `refunds → orders` | `\|\|--o{` | Denormalised order link so order-level refund totals need no join through payments. |

**Why `payments.branch_id` is denormalised.** Every payment query is branch-scoped
([04](04-user-roles-permissions.md) §5.2). Without the column, the global scope would have to join
`orders` on every read, including the high-frequency shift report. The value is copied at insert and
never changes, because an order never moves branch.

---

## 6. Kitchen

```mermaid
erDiagram
    BRANCHES ||--o{ KITCHEN_STATIONS : "operates"
    KITCHEN_STATIONS ||--o{ KITCHEN_TICKETS : "receives"
    ORDERS ||--o{ KITCHEN_TICKETS : "generates"
    KITCHEN_TICKETS ||--|{ KITCHEN_TICKET_ITEMS : "lists"
    ORDER_ITEMS ||--o{ KITCHEN_TICKET_ITEMS : "appears as"
    USERS |o--o{ KITCHEN_TICKETS : "prepares"

    KITCHEN_STATIONS {
        bigint id PK
        bigint branch_id FK
        varchar code UK "unique per branch"
        varchar name "BAR GRILL COLD"
        smallint sla_minutes
        boolean is_active
    }

    KITCHEN_TICKETS {
        bigint id PK
        bigint order_id FK
        bigint branch_id FK
        bigint kitchen_station_id FK "nullable, default screen"
        varchar ticket_number
        varchar status "queued preparing ready served cancelled"
        tinyint priority
        smallint sla_minutes "snapshot"
        timestamp queued_at
        timestamp started_at
        timestamp ready_at
        bigint prepared_by FK
        int version "optimistic lock"
    }

    KITCHEN_TICKET_ITEMS {
        bigint id PK
        bigint kitchen_ticket_id FK
        bigint order_item_id FK
        varchar status
        timestamp prepared_at
        bigint prepared_by FK
    }
```

**One ticket per order per station.** An order containing a latte and a burger produces two tickets —
one for `BAR`, one for `GRILL` — each holding only its own lines. This is why
`kitchen_ticket_items` exists rather than the KDS reading `order_items` directly: the same order's lines
are split across screens, and each split needs independent status.

The `uq_kt_order_station (order_id, kitchen_station_id)` constraint means re-running ticket generation
for an order is idempotent.

---

## 7. Inventory

The module with the most demanding integrity requirements.

```mermaid
erDiagram
    UNITS ||--o{ UNITS : "converts to base"
    UNITS ||--o{ INGREDIENTS : "stock kept in"
    UNITS ||--o{ RECIPE_ITEMS : "measured in"
    UNITS ||--o{ STOCK_TRANSACTIONS : "recorded in"
    PRODUCTS ||--o{ RECIPES : "produced by"
    PRODUCT_VARIANTS |o--o{ RECIPES : "specialises"
    RECIPES ||--|{ RECIPE_ITEMS : "consumes"
    INGREDIENTS ||--o{ RECIPE_ITEMS : "used in"
    BRANCHES ||--o{ INVENTORIES : "holds"
    INGREDIENTS ||--o{ INVENTORIES : "stocked as"
    BRANCHES ||--o{ STOCK_TRANSACTIONS : "records"
    INGREDIENTS ||--o{ STOCK_TRANSACTIONS : "moved by"
    USERS |o--o{ STOCK_TRANSACTIONS : "performs"

    UNITS {
        bigint id PK
        varchar code UK "kg g l ml pcs"
        varchar family "mass volume count"
        bigint base_unit_id FK "nullable, null means base"
        decimal conversion_factor "to base unit"
        tinyint precision_digits
    }

    INGREDIENTS {
        bigint id PK
        varchar name UK "unique with deleted_at"
        varchar code UK
        bigint unit_id FK "stock keeping unit"
        varchar category "reporting only"
        decimal default_cost_per_unit
        decimal reorder_level
        boolean is_perishable
        boolean is_active
        timestamp deleted_at
    }

    RECIPES {
        bigint id PK
        bigint product_id FK
        bigint product_variant_id FK "nullable, null applies to all"
        smallint version "never edited in place"
        decimal yield_quantity
        boolean is_active "one active per product variant"
        timestamp deleted_at
    }

    RECIPE_ITEMS {
        bigint id PK
        bigint recipe_id FK
        bigint ingredient_id FK "at most once per recipe"
        decimal quantity "per unit of yield"
        bigint unit_id FK "may differ from stock unit"
        decimal wastage_percent
        boolean is_optional "does not block sale"
    }

    INVENTORIES {
        bigint id PK
        bigint branch_id FK
        bigint ingredient_id FK
        decimal quantity_on_hand "materialised balance"
        decimal reserved_quantity "post MVP"
        decimal in_transit_quantity
        decimal average_cost "weighted"
        timestamp last_counted_at
        timestamp low_stock_notified_at "dedupe"
    }

    STOCK_TRANSACTIONS {
        bigint id PK
        bigint branch_id FK
        bigint ingredient_id FK
        varchar type "stock_in stock_out adjustment transfer_in transfer_out sale_deduction sale_reversal wastage count_correction"
        decimal quantity_change "signed, never zero"
        bigint unit_id FK
        decimal unit_cost
        decimal total_cost
        decimal balance_after "point in time balance"
        decimal average_cost_after
        varchar reference_type "polymorphic source"
        bigint reference_id
        varchar reason "mandatory for adjustment"
        bigint performed_by FK
        timestamp occurred_at "business time"
        timestamp created_at "append only, no updated_at"
    }
```

### 7.1 The two most important relationships

**`inventories` is a cache of `stock_transactions`.**

```text
inventories.quantity_on_hand  ==  SUM(stock_transactions.quantity_change)
                                  WHERE branch_id = X AND ingredient_id = Y
```

This invariant (C8) is verified nightly and is the definition of correctness for the whole inventory
module. A discrepancy is a bug, never something to be quietly corrected.

**`recipes` is the bridge between selling and stocking.** It is the only path by which an
`order_item` becomes a `stock_transaction`:

```mermaid
flowchart LR
    OI["order_items<br/>Cappuccino qty 2"] --> R{"recipes<br/>variant match,<br/>else generic"}
    R --> RI["recipe_items<br/>Milk 0.20 L<br/>Coffee 0.02 kg"]
    RI --> UC["UnitConverter<br/>recipe unit to stock unit"]
    UC --> ST["stock_transactions<br/>Milk -0.40 L<br/>Coffee -0.04 kg"]
    ST --> INV["inventories<br/>balances updated"]
```

Worked arithmetic, including wastage and unit conversion, is in
[14-inventory-workflow.md](14-inventory-workflow.md) §4.

### 7.2 Self-referencing units

`units.base_unit_id` points at the family's base unit; the base row has `NULL`. Conversion multiplies
by `conversion_factor` to reach the base, then divides by the target's factor. Cross-family conversion
is rejected (FR-INV-003) — the `family` column exists precisely so that "0.5 litres of flour" fails
loudly instead of silently deducting the wrong amount.

---

## 8. Stock transfers

```mermaid
erDiagram
    BRANCHES ||--o{ STOCK_TRANSFERS : "sends from"
    BRANCHES ||--o{ STOCK_TRANSFERS : "receives at"
    STOCK_TRANSFERS ||--|{ STOCK_TRANSFER_ITEMS : "moves"
    INGREDIENTS ||--o{ STOCK_TRANSFER_ITEMS : "transferred as"
    UNITS ||--o{ STOCK_TRANSFER_ITEMS : "measured in"
    USERS ||--o{ STOCK_TRANSFERS : "requests"
    USERS |o--o{ STOCK_TRANSFERS : "approves"
    USERS |o--o{ STOCK_TRANSFERS : "dispatches"
    USERS |o--o{ STOCK_TRANSFERS : "receives"

    STOCK_TRANSFERS {
        bigint id PK
        varchar transfer_number UK
        bigint from_branch_id FK "must differ from to"
        bigint to_branch_id FK
        varchar status "draft pending approved in_transit received partially_received rejected cancelled"
        bigint requested_by FK
        bigint approved_by FK "authority at source branch"
        bigint dispatched_by FK
        bigint received_by FK
        decimal total_cost
        boolean has_variance
        varchar rejection_reason
        timestamp deleted_at
    }

    STOCK_TRANSFER_ITEMS {
        bigint id PK
        bigint stock_transfer_id FK
        bigint ingredient_id FK "once per transfer"
        bigint unit_id FK
        decimal requested_quantity
        decimal approved_quantity "may be reduced"
        decimal dispatched_quantity
        decimal received_quantity
        decimal variance_quantity "received minus dispatched"
        decimal unit_cost "source average at dispatch"
        varchar variance_reason "mandatory when variance"
    }
```

**Two foreign keys to the same table.** `stock_transfers` references `branches` twice. This is the one
place where the branch-scope rule "visible when `branch_id` is in scope" does not apply — a transfer is
visible when **either** endpoint is in scope ([04](04-user-roles-permissions.md) §5.2). It requires its
own tests.

**Four quantity columns, not one.** Requested, approved, dispatched and received are genuinely
different facts and diverge routinely: a branch requests 10 kg, the source approves 8, dispatches 8 and
the destination receives 7.5. Collapsing them would destroy the variance trail that makes shrinkage
detectable.

---

## 9. Customers and loyalty

```mermaid
erDiagram
    LOYALTY_TIERS ||--o{ CUSTOMERS : "classifies"
    CUSTOMERS ||--o{ ORDERS : "places"
    CUSTOMERS ||--o{ LOYALTY_TRANSACTIONS : "accrues"
    ORDERS |o--o{ LOYALTY_TRANSACTIONS : "triggers"
    LOYALTY_TRANSACTIONS |o--o{ LOYALTY_TRANSACTIONS : "reverses"
    USERS |o--o{ LOYALTY_TRANSACTIONS : "performs adjustment"

    CUSTOMERS {
        bigint id PK
        varchar code UK
        varchar first_name
        varchar phone UK "unique with deleted_at, POS lookup"
        varchar email UK
        bigint loyalty_tier_id FK "nullable"
        int loyalty_points_balance "denormalised, never negative"
        int lifetime_points_earned "drives tier"
        decimal total_spent
        int total_orders
        timestamp anonymised_at "erasure marker"
        timestamp deleted_at
    }

    LOYALTY_TIERS {
        bigint id PK
        varchar name UK "Bronze Silver Gold"
        int min_lifetime_points UK
        decimal earn_rate_multiplier
        decimal discount_percent "nullable"
        json benefits "display only"
    }

    LOYALTY_RULES {
        bigint id PK
        decimal earn_points_per_currency_unit
        varchar earn_basis "subtotal net_of_discount grand_total"
        decimal redeem_value_per_point
        int min_points_to_redeem
        int redeem_increment
        decimal max_redeem_percent_of_subtotal "nullable"
        smallint points_expiry_days "nullable"
        boolean is_active "exactly one"
        date effective_from
    }

    LOYALTY_TRANSACTIONS {
        bigint id PK
        bigint customer_id FK
        bigint order_id FK "nullable"
        varchar type "earn redeem adjust expire reverse"
        int points "signed"
        int balance_after "running balance"
        decimal monetary_value
        varchar reason "mandatory for adjust"
        bigint reverses_transaction_id FK "nullable, self"
        timestamp expires_at
        bigint performed_by FK
        timestamp created_at "append only"
    }
```

**`loyalty_rules` has no foreign key to anything.** It is configuration read at calculation time. The
resulting points are written to the ledger with the values already applied, so a later rate change
never rewrites history — the same snapshot principle used for prices and tax.

**Self-reference for reversals.** When a completed order is refunded, a `reverse` row is written
pointing at the original `earn` row via `reverses_transaction_id`. The original is never deleted or
edited, which is what makes the balance reconstructable (FR-CUS-006).

---

## 10. Finance and platform

```mermaid
erDiagram
    BRANCHES ||--o{ EXPENSES : "incurs"
    EXPENSE_CATEGORIES ||--o{ EXPENSES : "classifies"
    USERS ||--o{ EXPENSES : "creates"
    USERS |o--o{ EXPENSES : "approves, never own"
    BRANCHES ||--o{ SETTINGS : "overrides"
    USERS |o--o{ AUDIT_LOGS : "acts in"
    BRANCHES |o--o{ AUDIT_LOGS : "scopes"
    USERS ||--o{ NOTIFICATIONS : "receives"
    BRANCHES ||--o{ DAILY_SEQUENCES : "numbers documents for"

    EXPENSE_CATEGORIES {
        bigint id PK
        varchar code UK
        varchar name
        boolean is_cogs_related "affects profit report"
        boolean requires_approval
    }

    EXPENSES {
        bigint id PK
        bigint branch_id FK
        bigint expense_category_id FK
        varchar expense_number UK
        decimal amount
        decimal tax_amount
        decimal total_amount
        date expense_date
        varchar receipt_path
        varchar status "draft pending approved rejected paid"
        bigint created_by FK
        bigint approved_by FK "must differ from created_by"
        timestamp deleted_at
    }

    SETTINGS {
        bigint id PK
        bigint branch_id FK "nullable means global"
        varchar key UK "unique with branch"
        text value
        varchar type "string integer decimal boolean json"
        boolean is_public "rates are never public"
        bigint updated_by FK
    }

    DAILY_SEQUENCES {
        bigint id PK
        bigint branch_id FK
        varchar scope "order expense transfer refund"
        date sequence_date UK "unique with branch and scope"
        int last_number
    }

    NOTIFICATIONS {
        char id PK "uuid"
        varchar type
        varchar notifiable_type
        bigint notifiable_id
        json data
        varchar severity "info warning critical"
        bigint branch_id FK "nullable"
        timestamp read_at
    }

    AUDIT_LOGS {
        bigint id PK
        bigint user_id FK "nullable for system"
        bigint impersonator_id FK "real actor"
        bigint branch_id FK
        varchar event "created updated deleted voided approved"
        varchar auditable_type "polymorphic"
        bigint auditable_id
        json old_values "redacted"
        json new_values "redacted"
        varchar ip_address
        char request_id "correlates to app logs"
        timestamp created_at "append only"
    }
```

**`audit_logs` has no foreign key to its target.** The `auditable_type` / `auditable_id` pair is
polymorphic and deliberately unconstrained: an audit row must survive the deletion of the thing it
describes. A foreign key would make it impossible to record a hard delete.

**`daily_sequences` looks trivial but is load-bearing.** It is the only thing standing between
concurrent order creation and duplicate order numbers ([02](02-system-architecture.md) §7).

---

## 11. Cross-module integrity map

The invariants that span modules. Each has a reconciliation job in [05](05-database-design.md) §11 and a
test in [24](24-qa-test-plan.md).

```mermaid
flowchart LR
    subgraph I1["Money"]
        A1["SUM payments.amount<br/>where status = captured"] ---|must equal| A2["orders.paid_total"]
        B1["SUM order_items.line_total<br/>plus adjustments"] ---|must equal| B2["orders.grand_total"]
        C1["SUM refunds.amount"] ---|must equal| C2["orders.refunded_total"]
    end

    subgraph I2["Stock"]
        D1["SUM stock_transactions.quantity_change"] ---|must equal| D2["inventories.quantity_on_hand"]
        E1["Recipe explosion of order_items"] ---|must equal| E2["stock_transactions where reference = order"]
    end

    subgraph I3["Loyalty"]
        F1["SUM loyalty_transactions.points"] ---|must equal| F2["customers.loyalty_points_balance"]
    end

    subgraph I4["Lifecycle"]
        G1["orders.status"] ---|last row of| G2["order_status_histories"]
        H1["All kitchen_tickets ready"] ---|implies| H2["orders.status = ready"]
    end
```

| # | Invariant | Constraint ID | Verified by |
|---|---|---|---|
| 1 | Captured payments equal `paid_total` | C6 | Hourly job + `PaymentService` assertion |
| 2 | Line totals equal `grand_total` | C5 | Nightly job + post-write assertion |
| 3 | Refunds equal `refunded_total` | — | Hourly job |
| 4 | Ledger equals balance | C8 | Nightly job |
| 5 | Order consumption matches its recipe | — | Feature test per product |
| 6 | Loyalty ledger equals balance | C7 | Nightly job |
| 7 | Status equals the latest history row | C9 | State machine + test |
| 8 | All tickets ready implies order ready | — | [13](13-kitchen-workflow.md) §5 |

---

## 12. Deletion impact map

What happens when a record is removed. Derived from the foreign key rules in
[05](05-database-design.md) §1.6.

```mermaid
flowchart TD
    DB["Delete a branch"] --> DB1["Soft delete only"]
    DB1 --> DB2["users.branch_id -> NULL"]
    DB1 --> DB3["orders, payments, inventories: RESTRICT<br/>hard delete impossible"]
    DB1 --> DB4["branch_product, settings, daily_sequences: CASCADE"]

    DP["Delete a product"] --> DP1["Soft delete only"]
    DP1 --> DP2["order_items: RESTRICT<br/>history preserved via snapshots"]
    DP1 --> DP3["product_variants, recipes, branch_product: CASCADE"]

    DO["Delete an order"] --> DO1["Soft delete only"]
    DO1 --> DO2["order_items, histories, kitchen_tickets: CASCADE"]
    DO1 --> DO3["payments: RESTRICT<br/>a paid order cannot be removed"]
    DO1 --> DO4["stock_transactions: untouched<br/>ledger is never deleted"]

    DC["Delete a customer"] --> DC1["Soft delete, or anonymise"]
    DC1 --> DC2["orders.customer_id -> NULL on hard delete"]
    DC1 --> DC3["loyalty_transactions: RESTRICT"]
    DC1 --> DC4["Anonymise preferred: financial totals survive"]

    DI["Delete an ingredient"] --> DI1["Soft delete only"]
    DI1 --> DI2["recipe_items, stock_transactions: RESTRICT"]
    DI1 --> DI3["inventories: CASCADE only if branch removed"]

    DU["Delete a user"] --> DU1["Soft delete only"]
    DU1 --> DU2["orders, payments: RESTRICT"]
    DU1 --> DU3["role_user: CASCADE"]
    DU1 --> DU4["audit_logs.user_id -> NULL"]
```

**Rule of thumb:** anything touching money, stock or history is `RESTRICT` plus a soft delete. Anything
that is purely a child of its parent is `CASCADE`. Anything optional is `SET NULL`.

---

## 13. Testing considerations

| Area | Test |
|---|---|
| Cardinality | For each `\|{` (one-or-many) relationship, assert the parent cannot be persisted without at least one child. |
| Optionality | For each `o` relationship, assert a `NULL` is accepted. |
| Self-reference | Category depth 3 rejected; unit conversion chains resolve; a loyalty reversal cannot reverse itself. |
| Dual FK | Transfer visibility from source, destination and a third branch. |
| Polymorphic | Audit row survives hard deletion of its target; notification resolves its notifiable. |
| Deletion rules | One test per edge in §12. |
| Cross-module invariants | One test per row in §11, run after a randomised 500-operation simulation. |
| Diagram accuracy | A CI check compares the tables named in this document against `SHOW TABLES` and fails on drift. |

> **Keeping the ERD honest.** Diagrams rot faster than prose. The CI check above is the only thing that
> will keep this document trustworthy once implementation begins. It is listed as a task in
> [31-development-roadmap.md](31-development-roadmap.md) Phase 1.

---

## 14. Related documents

[05-database-design.md](05-database-design.md) ·
[09-business-rules.md](09-business-rules.md) ·
[11-order-workflow.md](11-order-workflow.md) ·
[14-inventory-workflow.md](14-inventory-workflow.md) ·
[15-stock-transfer.md](15-stock-transfer.md) ·
[16-customer-loyalty.md](16-customer-loyalty.md)
