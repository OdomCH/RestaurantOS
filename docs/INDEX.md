# RestaurantOS — Documentation Index

Complete technical documentation for **RestaurantOS**, a multi-branch restaurant management system
built on React + TypeScript + Tailwind (frontend), Laravel + PHP (backend), MySQL, a REST API, and
Laravel Sanctum authentication.

---

## ⚠️ Read this first

**This documentation set is a design specification, not a description of running software.**

The repository was inspected on 2026-09-05 and re-checked on 2026-09-08. Both applications are
**unmodified framework scaffolds**: the Laravel backend has three default migrations, one model, no
`routes/api.php` and **no Sanctum**; the React frontend is the default Vite starter. Every module,
table, endpoint, permission and workflow described here is **designed, not built**.

Full verified state: [01-project-overview.md](01-project-overview.md) §2.
Build order: [31-development-roadmap.md](31-development-roadmap.md).

### Status legend

| Marker | Meaning |
|---|---|
| ✅ **Implemented** | Verified present in the repository |
| 🟡 **MVP — Planned** | In scope for MVP; designed, not built |
| 🔵 **Post-MVP — Proposed** | Deliberately deferred |
| ⚠️ **Assumption** | A decision made in the absence of a stakeholder ruling; must be confirmed |

---

## Where to start

| You are a… | Read in this order |
|---|---|
| **New team member** | [01](01-project-overview.md) → [02](02-system-architecture.md) → [06](06-erd.md) → [09](09-business-rules.md) |
| **Backend developer** | [02](02-system-architecture.md) → [05](05-database-design.md) → [07](07-api-documentation.md) → [09](09-business-rules.md) → [29](29-coding-standards.md) |
| **Frontend developer** | [02](02-system-architecture.md) → [07](07-api-documentation.md) → [10](10-pos-workflow.md) → [21](21-error-handling.md) |
| **QA engineer** | [24](24-qa-test-plan.md) → [09](09-business-rules.md) → [20](20-validation-rules.md) → [23](23-testing-strategy.md) |
| **DevOps** | [26](26-deployment.md) → [27](27-environment-configuration.md) → [22](22-security.md) → [30](30-troubleshooting.md) |
| **Project manager** | [01](01-project-overview.md) → [03](03-requirements.md) → [31](31-development-roadmap.md) |
| **Security reviewer** | [22](22-security.md) → [08](08-authentication-authorization.md) → [04](04-user-roles-permissions.md) |
| **Someone debugging at 22:00** | [30](30-troubleshooting.md) |

---

## The four documents that matter most

If you read nothing else:

| Document | Why |
|---|---|
| [09 — Business Rules](09-business-rules.md) | Every calculation in the system. The golden example (`14.44`) appears in tests, API examples and receipts identically. Change it here and everywhere else must follow. |
| [14 — Inventory Workflow](14-inventory-workflow.md) | Recipe explosion: how selling a cappuccino deducts milk and coffee. The system's defining behaviour and its highest-risk logic. |
| [04 — Roles and Permissions](04-user-roles-permissions.md) | The authorisation model. Every endpoint cites a permission defined here. |
| [05 — Database Design](05-database-design.md) | The complete schema. Every other document defers to it on columns and constraints. |

---

## All documents

### Foundation

| # | Document | Purpose | Status |
|---|---|---|---|
| 01 | [Project Overview](01-project-overview.md) | What RestaurantOS is, scope, glossary, the twelve core assumptions, and the verified state of the repository. **Start here.** | 🟡 |
| 02 | [System Architecture](02-system-architecture.md) | Layers, request lifecycle, backend and frontend structure, transaction boundaries, concurrency strategy, ten ADRs. | 🟡 |
| 03 | [Requirements](03-requirements.md) | 180+ numbered, testable requirements with acceptance criteria; constraints; ten open questions. | 🟡 |

### Access control

| # | Document | Purpose | Status |
|---|---|---|---|
| 04 | [User Roles and Permissions](04-user-roles-permissions.md) | The six roles, ~120 permissions, the full role→permission matrix, branch scoping, escalation guards. **Authoritative for permission names.** | 🟡 |
| 08 | [Authentication and Authorization](08-authentication-authorization.md) | Sanctum token lifecycle, PIN login for shared terminals, password policy, token abilities, the five-gate authorisation chain. | 🟡 |

### Data

| # | Document | Purpose | Status |
|---|---|---|---|
| 05 | [Database Design](05-database-design.md) | 41 tables: columns, types, keys, indexes, constraints, nullability, soft deletes, migration order. **Authoritative for the schema.** | 🟡 |
| 06 | [ERD](06-erd.md) | Nine Mermaid diagrams of the relationships in doc 05, plus the cross-module integrity map and deletion impact map. | 🟡 |

### API and rules

| # | Document | Purpose | Status |
|---|---|---|---|
| 07 | [API Documentation](07-api-documentation.md) | REST conventions, response envelope, money format, idempotency, and ~130 endpoints. **Authoritative for endpoints.** | 🟡 |
| 09 | [Business Rules](09-business-rules.md) | Every calculation: money arithmetic, price resolution, discounts, tax, service charge, rounding, loyalty, costing, profit. **Authoritative for formulas.** | 🟡 |
| 20 | [Validation Rules](20-validation-rules.md) | Field-level rules for every write endpoint, sanitisation policy, upload rules, custom validators. **Authoritative for validation.** | 🟡 |
| 21 | [Error Handling](21-error-handling.md) | Error envelope, the complete `error_code` catalogue, exception mapping, logging and redaction, client reaction table. **Authoritative for error codes.** | 🟡 |

### Workflows

Each workflow document covers Happy Path, Alternative Paths, Failure Paths, Validation, Permissions,
Database Changes and Audit Log Requirements.

| # | Document | Purpose | Status |
|---|---|---|---|
| 10 | [POS Workflow](10-pos-workflow.md) | Branch → category → product → cart → discount → tax → total → order → payment → receipt. | 🟡 |
| 11 | [Order Workflow](11-order-workflow.md) | The lifecycle state machine: Pending → Accepted → Preparing → Ready → Completed, plus cancellation and stock reversal. | 🟡 |
| 12 | [Payment Workflow](12-payment-workflow.md) | Cash, card, QR, bank transfer; split tender, change, voids, refunds, cash drawer reconciliation. | 🟡 |
| 13 | [Kitchen Workflow](13-kitchen-workflow.md) | KDS: ticket routing by station, transitions, status aggregation back to the order, polling design. | 🟡 |
| 14 | [Inventory Workflow](14-inventory-workflow.md) | **Recipe explosion**, the append-only stock ledger, unit conversion, weighted average costing, adjustments, counts, low-stock alerts. | 🟡 |
| 15 | [Stock Transfer](15-stock-transfer.md) | Inter-branch transfers: request → approve → dispatch → receive, in-transit tracking, variance handling. | 🟡 |
| 16 | [Customer Loyalty](16-customer-loyalty.md) | Customers, the point ledger, earning, redemption, tiers, reversals, and personal-data obligations. | 🟡 |
| 17 | [Expense Management](17-expense-management.md) | Expense lifecycle, approval chain, separation of duties, and how expenses reach the profit report. | 🟡 |
| 18 | [Reporting](18-reporting.md) | Ten reports with exact definitions, the business-day rule, reconciliation checks, caching. | 🟡 |
| 19 | [Notifications](19-notifications.md) | Event catalogue, permission-based recipient resolution, deduplication, payload rules. | 🟡 |

### Quality

| # | Document | Purpose | Status |
|---|---|---|---|
| 22 | [Security](22-security.md) | Threat model, defence in depth, audit logging, data protection, PCI position, security checklists. | 🟡 |
| 23 | [Testing Strategy](23-testing-strategy.md) | Test pyramid, tooling, **why tests must run on MySQL not SQLite**, coverage targets, CI gates. | 🟡 |
| 24 | [QA Test Plan](24-qa-test-plan.md) | 300+ concrete test cases: functional, API, integration, regression, authorisation, calculation, inventory, concurrency, security, edge and negative. | 🟡 |
| 25 | [Performance Testing](25-performance-testing.md) | Targets, restaurant load profiles, k6 scenarios, expected bottlenecks, regression policy. | 🟡 |

### Operations

| # | Document | Purpose | Status |
|---|---|---|---|
| 26 | [Deployment](26-deployment.md) | Topology, build, zero-downtime deployment, migration safety, monitoring, backup, rollback, first-deployment checklist. | 🟡 |
| 27 | [Environment Configuration](27-environment-configuration.md) | Every environment variable and database setting, the resolution order, and what must be configured before the system can take an order. | 🟡 |
| 28 | [Git Workflow](28-git-workflow.md) | Branching model, commit conventions, PR process, review standards, releases, hotfixes. | 🟡 |
| 29 | [Coding Standards](29-coding-standards.md) | PHP and TypeScript standards, layer rules, **mandatory money-handling rules**, custom lint rules, anti-patterns. | 🟡 |
| 30 | [Troubleshooting](30-troubleshooting.md) | Symptom-driven diagnostics, ordered by real frequency. Written for use during service. | 🟡 |
| 31 | [Development Roadmap](31-development-roadmap.md) | Ten phases in dependency order, risk register, open decisions, MVP definition of done. | 🟡 |

---

## Documentation Status

### Completed

All 32 documents are written, cross-referenced and internally consistent.

| Document | Notes |
|---|---|
| [01 Project Overview](01-project-overview.md) | Includes the verified repository state and twelve core assumptions |
| [02 System Architecture](02-system-architecture.md) | Ten ADRs recorded |
| [03 Requirements](03-requirements.md) | 180+ requirements with acceptance criteria |
| [04 Roles and Permissions](04-user-roles-permissions.md) | Complete permission catalogue and role matrix |
| [05 Database Design](05-database-design.md) | All 41 tables fully specified |
| [06 ERD](06-erd.md) | Nine diagrams |
| [07 API Documentation](07-api-documentation.md) | Conventions plus ~130 endpoints; critical endpoints in full request/response detail |
| [08 Authentication](08-authentication-authorization.md) | |
| [09 Business Rules](09-business-rules.md) | All formulas with worked examples |
| [10–19 Workflows](10-pos-workflow.md) | All ten documents include the full seven-part workflow structure |
| [20 Validation Rules](20-validation-rules.md) | |
| [21 Error Handling](21-error-handling.md) | Complete `error_code` catalogue |
| [22 Security](22-security.md) | Threat model and checklists |
| [23 Testing Strategy](23-testing-strategy.md) | |
| [24 QA Test Plan](24-qa-test-plan.md) | 300+ cases across all required test types |
| [25 Performance Testing](25-performance-testing.md) | |
| [26 Deployment](26-deployment.md) | |
| [27 Environment Configuration](27-environment-configuration.md) | |
| [28 Git Workflow](28-git-workflow.md) | |
| [29 Coding Standards](29-coding-standards.md) | |
| [30 Troubleshooting](30-troubleshooting.md) | |
| [31 Development Roadmap](31-development-roadmap.md) | |

### In Progress

Nothing. No implementation has begun, so no document is being revised against real code.

The first documents expected to change once Phase 0 starts:

| Document | Expected change |
|---|---|
| [01](01-project-overview.md) §2 | Status table updated as the scaffolds are replaced |
| [27](27-environment-configuration.md) | Real `.env.example` contents once MySQL and Sanctum are configured |
| [05](05-database-design.md) | Refinements as migrations are written against real MySQL behaviour |

### Planned

Documentation that will only exist once there is code to describe.

| Item | Depends on | Notes |
|---|---|---|
| **Generated OpenAPI 3.1 specification** | Phase 1 | Generated in CI from route definitions; doc 07 becomes the narrative companion (NFR-MNT-004) |
| **API client SDK documentation** | Phase 4 | Generated TypeScript types from the OpenAPI spec |
| **Operational runbooks** | Phase 9 | Per-incident procedures derived from [30](30-troubleshooting.md) |
| **User manuals** (cashier, kitchen, manager) | Phase 9 | End-user facing; different audience and register from this set |
| **Training materials** | Phase 9 | For staff onboarding at pilot branches |
| **Post-incident reviews** | Post-launch | Feed back into [30](30-troubleshooting.md) |
| **Real performance baselines** | Phase 9 | [25](25-performance-testing.md) currently holds targets, not measurements |
| **ADR log as a separate file** | When ADRs exceed ~15 | Currently summarised in [02](02-system-architecture.md) §12 |
| **Data retention and privacy policy** | Before launch | Legal document; [22](22-security.md) §8.3 is the technical input |
| **Disaster recovery plan** | Phase 9 | [26](26-deployment.md) §9 is the technical input |

Post-MVP feature documentation — WebSocket KDS, purchase orders, table management, payment gateway
integration — will be written when those items are scheduled. The full list is in
[31](31-development-roadmap.md) §13.

---

## Conventions used throughout

| Convention | Detail |
|---|---|
| **Single source of truth** | Each fact lives in exactly one document; others link to it. Schema → [05](05-database-design.md); endpoints → [07](07-api-documentation.md); formulas → [09](09-business-rules.md); validation → [20](20-validation-rules.md); error codes → [21](21-error-handling.md); permissions → [04](04-user-roles-permissions.md). |
| **Identifiers** | `FR-` functional requirement, `NFR-` non-functional, `CON-` constraint, `BR-` business rule, `TC-` test case, `ADR-` decision, `E`/`S`/`F`/`A` edge/security/failure/alternative case. |
| **Golden example** | Cappuccino Large × 2 + Croissant × 1, 10 % discount, 7 % tax ⇒ subtotal `15.00`, discount `1.50`, tax `0.94`, **grand total `14.44`**. Identical in every document, example and test. |
| **Worked arithmetic** | Every formula is followed by a worked example with real numbers. |
| **Rationale** | Non-obvious decisions state why, including rejected alternatives. |
| **Mermaid diagrams** | Architecture, ERD, state machines, sequences and workflows. |

---

## Contributing

Documentation is versioned with the code and updated in the **same pull request** as the change it
describes ([28](28-git-workflow.md) §10):

- Behaviour changes → the relevant workflow document
- Schema changes → [05](05-database-design.md) **and** [06](06-erd.md)
- New endpoint → [07](07-api-documentation.md)
- Calculation change → [09](09-business-rules.md) **and its golden values**
- Feature implemented → update the status marker 🟡 → ✅ here and in the document

**The status markers are what keep this set honest.** A document that claims ✅ without code is worse
than one that admits 🟡.
