# AGENTS.md — RELink Resolver implementation guidance

This file defines repository-wide implementation guidance for coding agents and human contributors working on the RELink Reference Resolver.

## 1. Authority and precedence

Implementation MUST follow the RELink specifications in this repository. When this file conflicts with a Frozen specification, the Frozen specification wins.

Authoritative specification set for implementation:

- `docs/specs/manifest-0.1.md` — Frozen
- `docs/specs/manifest-0.1-extension-policy.md` — Frozen
- `docs/specs/manifest-0.1.schema.json` — Frozen Manifest 0.1 set
- `docs/specs/resolver-manifest-conformance-0.1.md` — Frozen
- `docs/specs/web-runtime-integration-0.1.md` — Frozen
- `docs/specs/reference-resolver-architecture-0.1.md` — Frozen
- `docs/specs/reference-resolver-deployment-profiles-0.1.md` — Frozen
- `docs/specs/resolver-lifecycle-0.1.md` — Frozen by recorded immutable blob / Issue #3 freeze record
- `docs/specs/resolver-core-0.1.md` — Frozen (2026-09-01; immutable commit `8fe279395b1ee13202c52d01659d631c9f6c0b90`, blob `6b8fba7aece07d5886f20e0887eb97ae347fa7fa`)

The English specification text governs interpretation. Japanese documents are official translations.

Resolver Core 0.1 の実装は、上記の凍結版と整合する範囲で conformance を主張できる。意味論の変更は後続 Core version/profile で扱う。

## 2. Product boundary

The Reference Resolver is deliberately small.

```text
Physical Anchor
    ↓
Resolver
    ↓
Canonical Entity Identity
    ↓
Current Description Location
    ↓
AR-XML
    ↓
Runtime
    ↓
Capability
```

Preserve these invariants:

```text
Entity      ≠ Location
Capability  ≠ Interface
Resolution  ≠ Authentication
Description ≠ Execution
```

The Resolver MUST NOT become a device-management, capability-execution, Trust, ownership, or current-device-IP service.

Forbidden design drift includes:

- UUID → current device IP resolution
- construction or redirection to device management consoles
- capability discovery or invocation in the Resolver
- AR-XML interpretation in the Resolver
- periodic device-address reporting as a Resolver requirement
- treating Anchor UUID as a password, bearer token, or capability token
- inventing L2/authentication semantics inside the L1 implementation

## 3. Architectural style

Use a pragmatic combination of **DDD tactical boundaries**, **Hexagonal / Ports-and-Adapters architecture**, and **application-service orchestration**.

Do not introduce DDD ceremony that does not protect a domain invariant.

Preferred dependency direction:

```text
HTTP / CLI / Admin UI adapters
        ↓
Application services / use cases
        ↓
Domain model + policies
        ↓
Ports
        ↓
SQLite / HTTP client / clock / logging adapters
```

Dependencies MUST point inward. Domain and application code MUST NOT depend on Apache, PHP superglobals, SQLite-specific APIs, HTML rendering, or container runtime details.

### 3.1 Suggested bounded responsibilities

**Resolver Core domain/application**
- Anchor UUID parsing/identity
- lifecycle-aware resolution decision
- Description Location validation
- Core result classification
- cache/CORS/security-header policy representation where appropriate

**Lifecycle domain/application**
- `ACTIVE`, `SUSPENDED`, `RETIRED`
- permitted transitions only
- RETIRED terminal invariant
- same-state no-op semantics
- transition failure classification
- concurrency expectations

**Manifest application/domain**
- deterministic Manifest representation from stored metadata
- Frozen Manifest validation rules required of the producer
- no public AR-XML fetch during Manifest retrieval

**Maintenance/Admin application**
- registration
- Description Location update
- lifecycle transitions
- search/list/detail
- bounded history
- resolution test
- optional reachability/integrity publishing tooling

**Infrastructure**
- SQLite repositories
- transactions
- HTTP server adapters
- administrative outbound HTTP adapter
- filesystem/configuration
- logging

## 4. Domain model rules

Model protocol concepts explicitly; do not pass loosely structured associative arrays through the core.

Prefer small immutable value objects or equivalent strongly validated domain values for concepts such as:

- Anchor UUID
- Description Location
- Canonical Entity Identity
- lifecycle state
- integrity metadata
- record version / concurrency token

Keep Entity Identity, Resolver URL, and Description Location distinct in names and types.

Lifecycle initial registration is not a transition from a synthetic `NONEXISTENT` state.

A same-state lifecycle request may be an idempotent no-op, but MUST NOT be recorded as a lifecycle transition event.

## 5. Ports and adapters

Define ports around behavior, not around database tables.

Expected port categories include, as needed:

- Resolver record repository
- lifecycle/history repository or transaction-capable persistence boundary
- clock
- administrative outbound resource fetcher
- configuration/environment access
- audit/logger

Public resolution MUST NOT call an outbound AR-XML fetch port.

Administrative outbound fetch MUST remain an explicit privileged operation and MUST NOT be reachable accidentally from public Resolver/Manifest request handling.

## 6. Persistence and transaction rules

Reference persistence is SQLite, but application/domain semantics MUST NOT depend on SQLite syntax.

All database operations incorporating untrusted values MUST use parameterized queries or an equivalently injection-safe binding mechanism.

Use transactions for logically atomic changes, especially:

- lifecycle state + required history event
- Description Location/integrity metadata updates where consistency requires one logical commit
- integrity publishing commit checks

Integrity publishing MUST use compare-before-commit semantics sufficient to prevent storing a digest computed for an obsolete Description Location/version.

Never place SQLite database, journal/WAL, shared-memory files, backups, migration snapshots, secret-bearing configuration, or administrative exports in a directly web-addressable path.

## 7. Security boundaries

Security requirements are architectural, not optional polish.

### Public surface

- public L1 is GET-only/read-only
- UUID knowledge grants no administrative authority
- public Resolver resolution MUST NOT fetch AR-XML
- unsupported methods/status behavior MUST follow specifications
- public unknown and SUSPENDED behavior must preserve the defined non-distinction

### Administrative surface

Production authentication, session establishment, sensitive inspection, and mutation MUST use HTTPS or an equivalently protected channel.

Administrative search/list/detail/history/diagnostics/mutation require deployment administrative access controls unless explicitly defined public by a specification.

Browser ambient-credential mutation endpoints MUST implement CSRF protection.

GET/HEAD administrative routes MUST NOT perform state-changing operations.

All untrusted values rendered into administrative HTML MUST use context-appropriate escaping. Do not render untrusted stored content as raw HTML.

Authenticated administrative responses SHOULD default to `Cache-Control: no-store` where practical.

### Administrative outbound fetch / SSRF

Treat every supplied or derived destination as untrusted.

Apply configured outbound network policy before/while dereferencing each hop.

Requirements include:

- HTTPS requirements defined by the applicable specification/profile
- redirect re-evaluation
- bounded redirects
- connect/read timeout
- response-size/resource bounds
- no ambient credentials by default
- policy enforcement against the address actually used for the connection when policy depends on resolved address ranges, or an equivalent DNS-rebinding-safe mechanism

Do not implement a universal protocol-level private/local IP ban. Deployment policy decides allowed/denied destinations.

Container networking is not an SSRF defense by itself.

## 8. HTTP and Web behavior

Do not replace ordinary Web semantics with Resolver-specific client behavior.

Core ACTIVE resolution uses ordinary HTTP redirect behavior defined by the specifications.

Do not make Manifest retrieval a prerequisite for baseline L1 resolution.

Do not fetch, parse, or validate AR-XML merely to produce a Core resolution response.

Maintain separation between:

```text
public Resolver response
administrative diagnostics/fetch
AR-XML Runtime behavior
```

## 9. Testing strategy — TDD required

Use **test-driven development** for behavior changes.

### 9.1 テスト環境と実行コマンド

リファレンス実装では、PHP の単体テストフレームワークとして PHPUnit 11 を使用する。依存関係とバージョンは `composer.json` に宣言し、`composer.lock` で固定する。テスト設定は `phpunit.xml.dist` に定義する。

ネイティブテスト環境:

- PHP 8.3 以降
- PHP 拡張 `pdo_sqlite`、`dom`、`mbstring`、`xml`、`zip`
- Composer 2

リポジトリルートで、ネイティブのテストスイートを次のように実行する:

```sh
composer install
vendor/bin/phpunit
```

Container プロファイルでは、同じテスト依存関係をイメージ内にインストールする。次のように実行する:

```sh
docker compose build
docker compose run --rm resolver vendor/bin/phpunit
```

PHPStan 2 をレベル 6 で使用し、`src`、`public`、`bin`、`bootstrap.php` を静的解析する。リポジトリルートでは次のように実行する:

```sh
composer analyse
```

Container で確認する場合は、次のコマンドで同じ解析を実行する:

```sh
docker compose run --rm resolver composer analyse
```

テストスイートは、アプリケーションおよびドメインの振る舞いを検証する PHPUnit 単体テストと、マイグレーション、履歴トランザクション、楽観的同時実行制御を検証する PHPUnit SQLite 統合テストで構成する。HTTP/Apache、Native/Container 同等性、管理画面の認証・CSRF、バックアップ/リストア、外部ネットワークポリシー、Frozen Conformance Catalog 全件実行については、引き続き `relink-testbed` を正とする。ローカル PHPUnit の結果を、これらの testbed 専用ケースの合格結果として報告してはならない。

### 9.2 実装後の確認フロー

実装またはテストを変更した場合は、次の順序で確認する:

1. `composer validate --no-check-publish` で Composer 設定とロックファイルを検証する。
2. `composer test` で PHPUnit の全テストを実行する。
3. `composer analyse` で PHPStan レベル6のエラーがないことを確認する。
4. `find src public tests bin -name '*.php' -print0 | xargs -0 -n1 php -l` で PHP 構文を検査する。
5. `git diff --check` で空白エラーを検査する。

Container を使用する場合は、1〜4をそれぞれ `docker compose run --rm resolver <command>` で実行する。PHPUnit、PHPStan、構文検査のいずれかが失敗した場合は合格として報告せず、原因を修正してから再実行する。`relink-testbed` の対象ケースは、このローカル確認フローの合格結果に含めない。

For every normative behavior:

1. identify the governing specification section and, where applicable, Frozen Conformance Catalog case ID;
2. write or update a failing test first;
3. implement the smallest change that makes the test pass;
4. refactor while keeping tests green;
5. run the confirmation flow in 9.2 and keep all applicable checks green.

Do not invent new `RES-*`, `LIFE-*`, `MAN-*`, `INT-*`, `NET-*`, or other Frozen Catalog case identifiers. The Frozen Conformance Catalog owns those IDs.

### Test layers

**Domain unit tests**
- lifecycle transition table
- RETIRED terminal behavior
- same-state no-op
- validation/value objects
- failure classification

**Application tests**
- resolution decisions
- registration/update flows
- lifecycle transaction behavior
- Manifest generation
- integrity publishing conflict behavior
- public/admin separation

**Adapter/integration tests**
- SQLite persistence/transactions
- HTTP routing/status/headers
- Admin authentication/CSRF integration
- outbound-fetch network-policy enforcement
- backup/restore where executable in CI

**Conformance-facing tests**
- map implementation behavior to Frozen Catalog case IDs
- keep protocol tests independent from internal class/table layout

Tests MUST NOT assert internal structure when externally observable behavior is the contract.

## 10. Implementation language and baseline stack

Reference implementation baseline:

```text
Apache + PHP + SQLite
```

Native and Container deployments are equivalent official deployment profiles. Docker is packaging, not a protocol variant.

Prefer standard PHP/platform capabilities and small dependencies. Do not add a framework or package merely for convenience if it obscures protocol behavior or creates avoidable attack surface.

If a dependency is introduced, document:

- why it is needed
- maintained status
- license compatibility
- security implications
- whether the same behavior is realistically achievable with the standard stack

## 11. File/layout guidance

Keep public web-served files distinct from private state/configuration.

A reasonable conceptual layout is:

```text
public/              HTTP entry points and public assets only
src/Domain/          domain concepts/invariants
src/Application/     use cases/application services
src/Ports/           interfaces owned by inner layers
src/Adapters/        SQLite/HTTP/logging/etc. adapters
src/Admin/           admin delivery/UI adapters if useful
config/              non-secret defaults/schema; not web-served
tests/               unit/application/integration tests
migrations/          SQLite schema migrations
deploy/              Apache/Docker operational files
```

This is guidance, not a frozen directory contract. Preserve dependency direction even if names differ.

## 12. Migration and compatibility discipline

SQLite schema changes MUST use explicit migrations. Do not silently rebuild or discard existing Resolver state.

Migration/restore procedures must preserve, as applicable:

- Anchor UUIDs
- lifecycle state
- current Description Location
- Manifest metadata
- bounded retained history

RETIRED Anchor UUIDs MUST NOT be reused for a different Resolver record/Entity.

## 13. Logging and privacy

Log operationally useful events without turning logs into a second unrestricted data store.

- avoid secrets and credential material
- minimize unnecessary URLs/query data where possible
- encode/structure untrusted values so control characters cannot forge log entries
- distinguish public request logs, administrative audit events, and lifecycle transition history conceptually

Lifecycle transition history is not an append-only cryptographic ledger and does not establish Trust.

## 14. Change discipline for Frozen specifications

Do not edit Frozen specification semantics as part of implementation work.

If implementation reveals a suspected specification defect:

1. stop the affected semantic change;
2. document the conflict in an issue;
3. cite the exact specification section and observed behavior;
4. propose an erratum only if non-semantic;
5. otherwise propose a later version/profile.

Do not “fix” the implementation by silently deviating from a MUST.

## 15. Pull request / agent completion requirements

A coding task is complete only when:

- behavior is covered by tests
- relevant Frozen Catalog cases are identified where applicable
- security boundaries remain intact
- no forbidden Resolver responsibility was introduced
- migrations/configuration/deployment changes are documented
- Native and Container semantics remain equivalent when deployment files are affected
- the issue acceptance criteria are demonstrably satisfied
- no production secret, generated database, backup, or local environment file is committed

In the final implementation summary, report:

- specifications/sections implemented
- tests added/changed
- conformance case IDs covered
- migrations/config changes
- known deviations or unsupported optional features
- security-sensitive decisions

## 16. Goal of the first implementation

The first Reference Resolver should be boring, inspectable, deterministic, and easy to test.

Optimize for:

```text
specification fidelity
> security boundary clarity
> testability
> operational simplicity
> framework convenience
```

Do not optimize for feature count. Do not add Trust/L2, capability execution, device management, or unrelated platform abstractions to the 0.1 implementation.
