# 29 — Coding Standards

> **Document purpose.** Define how code is written in RestaurantOS: style, structure, naming, and the
> domain-specific rules that protect money, stock and security. Generic style is delegated to tooling;
> this document covers the decisions tooling cannot make.

**Prerequisites:** [02-system-architecture.md](02-system-architecture.md) §3–§5.

**Status:** 🟡 MVP — Planned. Pint and oxlint are installed; no project rules configured yet.

---

## 1. Principles

| # | Principle |
|---|---|
| S1 | **Automate style, review substance.** Formatting is Pint's and oxlint's job; reviewers discuss correctness and design. |
| S2 | **Explicit over clever.** Code is read far more often than written, frequently by someone debugging at 22:00 during service. |
| S3 | **Money and stock code is held to a higher standard** than everything else. |
| S4 | **Fail loudly.** A misconfiguration or an impossible state raises; it never silently defaults. |
| S5 | **Names carry the domain.** `grand_total`, not `total2`. The vocabulary is [01](01-project-overview.md) §8. |
| S6 | **Comment why, never what.** The code says what. |

---

## 2. Tooling

| Tool | Purpose | Gate |
|---|---|---|
| Laravel Pint | PHP formatting (PSR-12 + Laravel preset) | ✔ Zero diffs |
| PHPStan / Larastan | Static analysis, level 6+ | ✔ |
| oxlint | TypeScript/React linting | ✔ Zero warnings |
| TypeScript `strict` | Type safety | ✔ |
| Prettier | Frontend formatting | ✔ |
| Custom lint rules | §9 | ✔ |

**Style is never a review comment.** If a reviewer is discussing brace placement, the tooling is
misconfigured.

---

## 3. PHP standards

### 3.1 Baseline

PSR-12 via Pint, plus:

| Rule | Detail |
|---|---|
| `declare(strict_types=1)` | **Every** PHP file. Without it, `"5 apples"` silently becomes `5` |
| Type declarations | Parameters, return types and properties always typed |
| Nullable | Explicit `?Type`, never implicit |
| Final by default | Classes are `final` unless designed for extension |
| Readonly | Value objects use `readonly` properties |
| Enums | Native backed enums, never class constants for a closed set |
| Constructor promotion | Preferred |
| Named arguments | For calls with more than three parameters or boolean flags |

### 3.2 Naming

| Element | Convention | Example |
|---|---|---|
| Class | `PascalCase`, noun | `OrderCalculator` |
| Interface | `PascalCase`, no `I` prefix | `PaymentProcessor` |
| Method | `camelCase`, verb | `calculateGrandTotal()` |
| Boolean method | `is`/`has`/`can` | `canBeCancelled()` |
| Variable | `camelCase` | `$grandTotal` |
| Constant | `SCREAMING_SNAKE` | `MAX_LINE_ITEMS` |
| Enum case | `PascalCase` | `OrderStatus::Preparing` |
| Test method | `snake_case`, states the rule | `it_rejects_a_payment_exceeding_the_balance` |

### 3.3 Class organisation

```php
final class OrderService
{
    // 1. constants
    // 2. promoted constructor dependencies
    // 3. public methods — the use cases
    // 4. private methods — helpers, in call order
}
```

Dependencies are injected via the constructor. **Facades are not used in services** — they hide
dependencies and make a class impossible to test without the framework booted. `DB::transaction()` is
the pragmatic exception.

---

## 4. Layer rules

Restating [02](02-system-architecture.md) §3.1 as enforceable rules.

### 4.1 Controllers

**Maximum ~15 lines per method.** A controller accepts a validated request, calls one service, returns
one resource.

```php
// CORRECT
public function store(StoreOrderRequest $request, OrderService $orders): JsonResponse
{
    $order = $orders->create(
        CreateOrderData::fromRequest($request),
        $request->user(),
    );

    return OrderResource::make($order)
        ->response()
        ->setStatusCode(201);
}
```

Forbidden in a controller: database queries, money arithmetic, business `if` chains, transactions.

### 4.2 Services

| Rule | Detail |
|---|---|
| One public method per use case | `create`, `accept`, `cancel` — not a `handle($action)` switch |
| Owns the transaction boundary | One use case, one transaction |
| Takes a DTO, not a `Request` | Services must be callable from a console command or a job |
| Returns a model or DTO, never a response | HTTP is the controller's concern |
| Delegates arithmetic to `Domain/` | Services orchestrate; domain objects calculate |
| Emits events after commit | `DB::afterCommit()` |

### 4.3 Domain classes

**Pure.** No database, no facades, no HTTP, no clock access except via an injected provider.

```php
final readonly class OrderCalculator
{
    public function __construct(
        private DiscountAllocator $allocator,
        private TaxCalculator $tax,
    ) {}

    public function calculate(CartData $cart, PricingContext $context): OrderTotals
    {
        // pure arithmetic — no I/O of any kind
    }
}
```

This purity is what makes the calculation test suite run in milliseconds without a database, and it is
enforced by running unit tests with the database connection disabled
([23](23-testing-strategy.md) §8.1).

### 4.4 Models

Thin. Relationships, casts, scopes, accessors. **No business logic.**

```php
final class Order extends Model
{
    use SoftDeletes, HasFactory;

    protected $fillable = ['branch_id', 'order_type', 'table_number', 'customer_id', 'note'];

    protected $casts = [
        'status'         => OrderStatus::class,
        'payment_status' => PaymentStatus::class,
        'subtotal'       => MoneyCast::class,
        'grand_total'    => MoneyCast::class,
        'business_date'  => 'date',
    ];
}
```

**Never fillable:** `status`, `payment_status`, `paid_total`, `grand_total`, `subtotal`, `tax_amount`,
`user_id`, `order_number`, `inventory_deducted_at`, `version`. These are set by services, and mass
assignment of any one of them is a security hole ([22](22-security.md) §4).

---

## 5. Money handling — mandatory rules

**The most important section in this document.**

| # | Rule |
|---|---|
| M1 | Money is **never** a `float`. Not in PHP, not in JSON, not in TypeScript |
| M2 | All arithmetic happens in integer minor units, inside a `Money` value object |
| M3 | `Money` is immutable; operations return new instances |
| M4 | Rounding is explicit and half-up ([09](09-business-rules.md) §2.2) |
| M5 | Money crosses the API boundary as a string plus a minor-unit integer |
| M6 | Two `Money` values of different currencies cannot be combined — the attempt throws |
| M7 | Division is prohibited except through an allocator that guarantees the parts sum to the whole |
| M8 | **No financial rate is ever a literal** — §9, [09](09-business-rules.md) §1 |

```php
// FORBIDDEN
$total = $price * $quantity * 1.07;
$tax   = $subtotal * 0.07;
$share = $discount / $lineCount;

// CORRECT
$lineSubtotal = $unitPrice->multiply($quantity);
$rate         = $this->taxRates->resolveFor($product, $branch);   // throws if unresolvable
$lineTax      = $lineSubtotal->applyRate($rate);
$shares       = $this->allocator->allocate($discount, $lineWeights); // sums exactly
```

**M7 exists because of a specific bug class.** Dividing 1.00 by 3 and rounding each part gives 0.33
three times — 0.99. The allocator assigns the remainder deterministically so the parts always sum to
the original ([09](09-business-rules.md) §5.3).

---

## 6. Database access

| # | Rule |
|---|---|
| DB1 | Eager-load relationships. `->with()` on every list query |
| DB2 | Query-count assertions on list endpoints in tests |
| DB3 | Chunk large iterations (`chunkById`), never `->all()` on a large table |
| DB4 | Parameter binding always; never string-concatenated SQL |
| DB5 | Filter, sort and group parameters map through a **whitelist** to fixed column names |
| DB6 | `select()` explicit columns on wide tables |
| DB7 | Lock ordering is fixed and identical everywhere ([02](02-system-architecture.md) §6, T4) |
| DB8 | Transactions are short; no HTTP calls inside one |
| DB9 | Every migration reversible, or documents why not |
| DB10 | Every new query has a supporting index, verified with `EXPLAIN` |

```php
// FORBIDDEN — injection and index abuse
$orders = DB::select("SELECT * FROM orders WHERE status = '{$status}' ORDER BY {$sort}");

// CORRECT
$sortColumn = match ($request->string('sort')->toString()) {
    'placed_at', '-placed_at' => 'placed_at',
    'grand_total'             => 'grand_total',
    default                   => 'placed_at',
};

$orders = Order::query()
    ->with(['items.product', 'payments'])
    ->where('status', $status->value)
    ->orderBy($sortColumn, $direction)
    ->cursorPaginate($perPage);
```

---

## 7. TypeScript and React standards

### 7.1 TypeScript

| Rule | Detail |
|---|---|
| `strict: true` | Non-negotiable |
| No `any` | Use `unknown` and narrow. `any` requires an inline justification comment |
| No non-null `!` | Handle the null case |
| `type` for unions and props; `interface` for extensible contracts | Consistency |
| API types generated from OpenAPI | Never hand-maintained — they drift |
| Discriminated unions for state | `{ status: 'loading' } \| { status: 'error', error: E }` |

### 7.2 React

| Rule | Detail |
|---|---|
| Function components only | |
| Custom hooks for reusable logic | `useCart`, `usePermission` |
| Server state in TanStack Query, never in a global store | |
| Local UI state in `useState`/`useReducer` | |
| `dangerouslySetInnerHTML` **banned** | Lint-enforced ([22](22-security.md) §6.4) |
| Keys are stable IDs, never array indices | |
| Effects have complete dependency arrays | |
| One component per file, named for the file | |

### 7.3 Frontend money

```ts
// FORBIDDEN
const total = items.reduce((sum, i) => sum + i.price * i.qty, 0);

// CORRECT — integer minor units
const totalMinor = items.reduce((sum, i) => sum + i.unitPriceMinor * i.quantity, 0);
// display only:
const formatted = formatMoney(totalMinor, currency);
```

And regardless: **the client total is advisory**. The authoritative figure is the server's
([02](02-system-architecture.md) §5.1).

---

## 8. Comments and documentation

| # | Rule |
|---|---|
| CM1 | Comment **why**, not what |
| CM2 | Every non-obvious business rule cites its document: `// BR-DISC-01 — see docs/09 §5.3` |
| CM3 | Docblocks only where they add information beyond the signature |
| CM4 | `@throws` documented on anything that throws a domain exception |
| CM5 | `TODO` includes an owner and an issue: `// TODO(odom): #142 handle multi-currency` |
| CM6 | No commented-out code — that is what version control is for |

```php
// CORRECT — explains a decision the code cannot
// Remainder goes to the largest line by subtotal, not the last by position, so the
// allocation is identical regardless of the order the cashier added items.
// BR-DISC-01 — docs/09-business-rules.md §5.3
$allocations[$largestLineIndex] = $discount->minus($allocatedSoFar);
```

---

## 9. Custom lint rules

Project-specific checks, each blocking CI. They encode rules that reviewers would otherwise have to
remember.

| # | Rule | Rationale |
|---|---|---|
| L1 | **No decimal literal matching a rate pattern in `app/Domain`** (`0.07`, `1.07`, `7.0` outside tests) | [09](09-business-rules.md) §1 — no hard-coded financial rates |
| L2 | **No `env()` outside `config/`** | Returns `null` once config is cached ([27](27-environment-configuration.md) §3) |
| L3 | **No `float`/`double` type on a money parameter or return** | M1 |
| L4 | Every route declares a permission or is explicitly public | [22](22-security.md) §4 |
| L5 | Every branch-owned model applies the branch global scope | [22](22-security.md) §5 |
| L6 | No `dispatch()` inside a `DB::transaction` closure without `afterCommit` | [02](02-system-architecture.md) §6, T2 |
| L7 | No `dangerouslySetInnerHTML` | XSS |
| L8 | Controller methods ≤ 20 lines | §4.1 |
| L9 | Domain classes import nothing from `Illuminate\Database` or `Illuminate\Http` | §4.3 |
| L10 | No `->all()` or `->get()` without a limit on tables over 100 k rows | DB3 |

L1 and L2 are the two that catch the most consequential mistakes: a hard-coded tax rate is
silently wrong in a second jurisdiction, and an `env()` call in a service is silently `null` in
production only.

---

## 10. Error handling in code

| # | Rule |
|---|---|
| EH1 | Throw domain exceptions, never return `false` or `null` for a business failure |
| EH2 | Every domain exception extends `DomainException` and carries its `error_code` |
| EH3 | Never catch `\Exception` broadly; catch the specific type |
| EH4 | Never swallow an exception silently — log it or rethrow |
| EH5 | Impossible states throw `LogicException`; they are bugs, not user errors |
| EH6 | Guard clauses over nested conditionals |

```php
// FORBIDDEN
try { $this->deductStock($order); } catch (\Exception $e) { /* carry on */ }

// CORRECT
try {
    $this->deductStock($order);
} catch (InsufficientStockException $e) {
    // The caller needs the per-ingredient shortfalls to show the cashier.
    throw $e;
}
```

---

## 11. Testing standards

| # | Rule |
|---|---|
| T1 | Test names state the rule: `it_refuses_to_complete_an_order_with_an_outstanding_balance` |
| T2 | Arrange–Act–Assert, visually separated |
| T3 | One behaviour per test |
| T4 | Feature tests assert response, database, audit row **and** side effects |
| T5 | No conditionals in tests — a branching test is two tests |
| T6 | Factories over hand-built fixtures |
| T7 | Freeze time when the outcome depends on it |
| T8 | Never assert on a message string; assert on `error_code` |
| T9 | Every fixed defect gets a regression test |

---

## 12. Performance in code

| # | Rule |
|---|---|
| P1 | Never query inside a loop |
| P2 | Aggregate before locking ([14](14-inventory-workflow.md) §4.1) |
| P3 | Cache expensive reads with explicit invalidation, never a bare TTL on financial data |
| P4 | Paginate every list; no unbounded collections |
| P5 | Move anything not required for correctness of the response into a queued job |
| P6 | Index every new query path, verified with `EXPLAIN` |
| P7 | The KDS poll path is performance-critical — no writes, no N+1, minimal payload |

---

## 13. Anti-patterns

| Anti-pattern | Why it is forbidden |
|---|---|
| Business logic in a controller | Untestable without HTTP; duplicated across entry points |
| Business logic in a model | Fat models become untestable and circular |
| Facades inside services | Hidden dependencies |
| `float` for money | Rounding errors that reach a bank reconciliation |
| Hard-coded rates | Unusable in a second jurisdiction; violates CON-007 |
| `env()` outside config | `null` in production only |
| Client-supplied prices or totals | Trivially manipulated |
| `SELECT MAX(id)+1` | Race condition |
| Summing a ledger for a live balance | Eight million rows |
| Silent `catch` | Hides the bug that matters |
| Boolean parameters | `save($order, true, false)` is unreadable — use named arguments or separate methods |
| God service | A class with fifteen public methods is several classes |
| Premature abstraction | An interface with one implementation is indirection, not design |
| Commented-out code | Version control exists |

---

## 14. Definition of done

A change is complete when:

- [ ] It satisfies its requirement ID
- [ ] Unit tests cover the domain logic
- [ ] Feature tests cover the endpoint, including negative and unauthorised cases
- [ ] Validation exists for every input
- [ ] Permission and branch scope are enforced server-side
- [ ] State changes write an audit row inside the transaction
- [ ] No hard-coded financial rate
- [ ] Money uses minor units throughout
- [ ] Errors return the standard envelope with a specific `error_code`
- [ ] Documentation updated in the same PR ([28](28-git-workflow.md) §10)
- [ ] Pint, oxlint, PHPStan and custom lint rules pass
- [ ] Coverage thresholds met
- [ ] No new N+1
- [ ] Reviewed and approved

---

## 15. Related documents

[02-system-architecture.md](02-system-architecture.md) ·
[09-business-rules.md](09-business-rules.md) ·
[20-validation-rules.md](20-validation-rules.md) ·
[21-error-handling.md](21-error-handling.md) ·
[22-security.md](22-security.md) ·
[23-testing-strategy.md](23-testing-strategy.md) ·
[27-environment-configuration.md](27-environment-configuration.md) ·
[28-git-workflow.md](28-git-workflow.md)
