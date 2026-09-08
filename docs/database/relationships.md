# Relationships

> **Purpose.** Every relationship in the schema, its cardinality, and the delete
> rule chosen for it — with the reasoning where the choice was not obvious.
>
> Columns are in [05-database-design.md](../05-database-design.md); the visual
> diagrams are in [06-erd.md](../06-erd.md).

**Status:** ✅ Implemented. 88 foreign keys, each with an explicit
`ON DELETE` and `ON UPDATE` rule. Verified: 144 Eloquent relationship methods
resolve against the live schema.

---

## 1. The delete-rule policy

`ON UPDATE CASCADE` on every foreign key. Primary keys are immutable in practice,
so this is harmless and removes a class of migration friction.

`ON DELETE` is chosen per relationship from three options:

| Rule | Meaning | When it is right |
|---|---|---|
| `RESTRICT` | Refuse to delete the parent while children exist | **The default.** Master data referenced by history: branches, users, products, ingredients, customers with a ledger. |
| `CASCADE` | Delete the children with the parent | Only when a child is **wholly owned** and meaningless alone: order lines, recipe lines, transfer lines, pivot rows. |
| `SET NULL` | Keep the child, forget the reference | Optional references where the fact survives the actor: `orders.customer_id`, `audit_logs.user_id`, every `approved_by` / `voided_by`. |

**`RESTRICT` is the default on purpose.** A refused delete is an inconvenience; a
cascaded one is a silent loss of financial history that nobody notices until an
audit.

---

## 2. Dangerous cascades that were explicitly rejected

These are the cases where `CASCADE` looks natural and would be a disaster.

| Relationship | If it cascaded | Chosen rule |
|---|---|---|
| `branches` to `orders` | Closing a branch erases its entire sales history. Last year's revenue changes retrospectively. | `RESTRICT` |
| `branches` to `stock_transfers` (both ends) | Deleting one branch destroys transfers the **other** branch still needs to explain its own stock. | `RESTRICT` on both keys |
| `users` to `orders` | A cashier leaves, their shift's takings vanish. | `RESTRICT` |
| `users` to `audit_logs` | Deleting a user erases the record of what they did, which is precisely what a bad actor would try. | `SET NULL` |
| `customers` to `orders` | A right-to-erasure request would delete the sales too, changing the day's takings. | `SET NULL`, plus `anonymised_at` to null the personal fields while the order survives |
| `products` to `order_items` | Removing a discontinued product rewrites every past receipt containing it. | `RESTRICT` — this is what keeps `product_id` trustworthy for reporting |
| `ingredients` to `stock_transactions` | Deleting an ingredient destroys the ledger that explains the balances. | `RESTRICT` |
| `orders` to `payments` | Deleting an order deletes the money taken for it. | `RESTRICT` |
| `payments` to `refunds` | The same, one level down. | `RESTRICT` |
| `customers` to `loyalty_transactions` | A points dispute becomes unanswerable. | `RESTRICT` |

The practical effect: **history-bearing rows are never hard-deleted.** Soft
deletes (`deleted_at`) handle "remove it from the UI"; the row and its
references stay.

---

## 3. Relationship catalogue

### 3.1 Identity and access

| Parent | Child | Cardinality | ON DELETE | Note |
|---|---|---|---|---|
| `branches` | `users` | 1 : 0..N | `SET NULL` | Home branch. Closing a branch must not delete staff. |
| `users` / `roles` | `role_user` | M : N | `CASCADE` both | Plus `branch_id` on the pivot: role grants are branch-scoped. |
| `roles` / `permissions` | `role_permission` | M : N | `CASCADE` both | The grant either exists or it does not. |
| `users` | `role_user.assigned_by` | 1 : 0..N | `SET NULL` | Keep the assignment when the granter leaves. |
| `branches` | `role_user.branch_id` | 1 : 0..N | `CASCADE` | A scoped grant to a deleted branch is meaningless. |

### 3.2 Core and catalogue

| Parent | Child | Cardinality | ON DELETE | Note |
|---|---|---|---|---|
| `branches` | `settings` | 1 : 0..N | `CASCADE` | A branch override without its branch is dead weight. |
| `branches` | `daily_sequences` | 1 : 0..N | `CASCADE` | Counters, not history. |
| `categories` | `categories` | 1 : 0..N (self) | `RESTRICT` | Max depth 2, enforced in the service. Never orphan a subtree. |
| `kitchen_stations` | `categories` | 1 : 0..N | `SET NULL` | Removing a station falls back to the default screen. |
| `categories` | `products` | 1 : 0..N | `RESTRICT` | A category with products cannot be deleted. |
| `tax_rates` | `products` | 1 : 0..N | `RESTRICT` | |
| `products` | `product_variants` | 1 : 0..N | `CASCADE` | A variant has no meaning without its product. |
| `products` / `branches` | `branch_product` | M : N | `CASCADE` both | Only overrides are stored; a missing row means inherit. |

### 3.3 Sales

| Parent | Child | Cardinality | ON DELETE | Note |
|---|---|---|---|---|
| `branches` | `orders` | 1 : 0..N | `RESTRICT` | |
| `users` | `orders` | 1 : 0..N | `RESTRICT` | The cashier. |
| `customers` | `orders` | 1 : 0..N | `SET NULL` | Optional; most walk-ins have none. |
| `cash_drawer_sessions` | `orders`, `payments` | 1 : 0..N | `SET NULL` | |
| `orders` | `order_items` | 1 : 1..N | `CASCADE` | Lines are wholly owned. |
| `orders` | `order_status_histories` | 1 : 1..N | `CASCADE` | |
| `orders` | `kitchen_tickets` | 1 : 0..N | `CASCADE` | |
| `orders` | `payments` | 1 : 0..N | `RESTRICT` | Several rows make a split payment. |
| `payments` | `refunds` | 1 : 0..N | `RESTRICT` | |
| `products` | `order_items` | 1 : 0..N | `RESTRICT` | |
| `product_variants` | `order_items` | 1 : 0..N | `RESTRICT` | |
| `users` | `voided_by`, `cancelled_by`, `discount_approved_by` | 1 : 0..N | `SET NULL` | The action survives the actor. |

### 3.4 Kitchen

| Parent | Child | Cardinality | ON DELETE | Note |
|---|---|---|---|---|
| `branches` | `kitchen_stations` | 1 : 0..N | `CASCADE` | Stations are branch fixtures, not history. |
| `kitchen_stations` | `kitchen_tickets` | 1 : 0..N | `SET NULL` | The ticket falls back to the default screen. |
| `kitchen_tickets` | `kitchen_ticket_items` | 1 : 1..N | `CASCADE` | |
| `order_items` | `kitchen_ticket_items` | 1 : 0..N | `CASCADE` | Removing a line removes its kitchen work. |

### 3.5 Inventory

| Parent | Child | Cardinality | ON DELETE | Note |
|---|---|---|---|---|
| `units` | `units` | 1 : 0..N (self) | `RESTRICT` | `base_unit_id`; deleting kg would orphan g. |
| `units` | `ingredients`, `recipe_items`, `stock_transactions`, `stock_transfer_items` | 1 : 0..N | `RESTRICT` | |
| `products` | `recipes` | 1 : 0..N (versioned) | `CASCADE` | |
| `product_variants` | `recipes` | 1 : 0..N | `CASCADE` | Null means it applies to all variants. |
| `recipes` | `recipe_items` | 1 : 1..N | `CASCADE` | |
| `ingredients` | `recipe_items` | 1 : 0..N | `RESTRICT` | |
| `branches` / `ingredients` | `inventories` | M : N | `CASCADE` / `RESTRICT` | **One row per pair. This is branch isolation.** |
| `branches`, `ingredients` | `stock_transactions` | 1 : 0..N | `RESTRICT` | Append-only ledger. |
| `branches` | `stock_transfers` | 1 : 0..N **twice** | `RESTRICT` | `from_branch_id` and `to_branch_id`, which may not be equal. |
| `stock_transfers` | `stock_transfer_items` | 1 : 1..N | `CASCADE` | |

### 3.6 CRM, finance and platform

| Parent | Child | Cardinality | ON DELETE | Note |
|---|---|---|---|---|
| `loyalty_tiers` | `customers` | 1 : 0..N | `SET NULL` | |
| `customers` | `loyalty_transactions` | 1 : 0..N | `RESTRICT` | |
| `orders` | `loyalty_transactions` | 1 : 0..N | `SET NULL` | Null for manual adjustments and expiries. |
| `loyalty_transactions` | `reverses_transaction_id` | 1 : 0..1 (self) | `RESTRICT` | Links a clawback to the earn it undoes. |
| `expense_categories` | `expenses` | 1 : 0..N | `RESTRICT` | |
| `branches` | `expenses` | 1 : 0..N | `RESTRICT` | |
| `users` | `expenses.created_by` | 1 : 0..N | `RESTRICT` | |
| `users` | `approved_by`, `rejected_by`, `paid_by` | 1 : 0..N | `SET NULL` | |
| `branches` | `notifications` | 1 : 0..N | `CASCADE` | Alerts are transient. |
| `users`, `branches` | `audit_logs` | 1 : 0..N | `SET NULL` | Never lose the evidence. |

---

## 4. Polymorphic relationships

Two, both justified and both indexed.

| Relation | Columns | Points at | Why polymorphic |
|---|---|---|---|
| `stock_transactions.reference` | `reference_type`, `reference_id` | `Order`, `StockTransfer`, `Refund` | A movement can originate from any of three unrelated flows. Three nullable FK columns would be wider and would still need a check that exactly one is set. |
| `audit_logs.auditable` | `auditable_type`, `auditable_id` | Any model | An audit log restricted to a fixed list would need a migration per newly audited model. |

Both give up database-level referential integrity, which is the price of the
pattern. Both are used only for **backwards** lookups ("what did this order
consume?"), never as a join in a hot query, and both are covered by a composite
index.

`notifications.notifiable` is Laravel's own polymorphic contract and is left as
the framework defines it.

---

## 5. Self-referencing relationships

| Table | Column | Guard against a cycle |
|---|---|---|
| `categories` | `parent_id` | Depth capped at 2 in `CategoryService` (C1) |
| `units` | `base_unit_id` | Base units hold `NULL`; one level only, and seeded |
| `loyalty_transactions` | `reverses_transaction_id` | A reversal may not itself be reversed |

**No circular table dependencies exist.** The migration order in
[05](../05-database-design.md) §10.1 is a valid topological sort, which is what
proves it: every foreign key points at a table created earlier.

---

## 6. Eloquent mapping

Each relationship above has a matching method on the model. The naming is
consistent so that a reader can predict it:

| Database | Eloquent | Example |
|---|---|---|
| FK on this table | `belongsTo` | `Order::branch()` |
| FK on the other table | `hasMany` | `Branch::orders()` |
| Pivot table | `belongsToMany` | `User::roles()`, `Product::branches()` |
| Two FKs to the same table | `belongsTo` with an explicit key, named for the role | `StockTransfer::fromBranch()`, `toBranch()` |
| Actor columns | named for the column | `Order::cancelledBy()`, `Expense::approvedBy()` |
| Polymorphic | `morphTo` / `morphMany` | `StockTransaction::reference()`, `Order::stockTransactions()` |

Verification: `144 relations checked, 0 errors` — every method resolves its
related model and key against the migrated schema.
