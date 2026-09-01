# RELink Resolver

RELink Resolver は、既存の Web インフラストラクチャを用いて、物理的・現実世界の Entity を addressable / discoverable / interactable / operable にする実験的アーキテクチャ RELink（Real Entity Link）の resolution layer です。

このリポジトリでは、RELink Resolver / Manifest 仕様、Reference Resolver、Resolver interoperability test 定義を管理します。

[English](README.md) | 日本語

## 状態

Specification-first 設計から Reference implementation への移行段階です。

RELink Manifest 0.1、Manifest 0.1 Extension Policy、および付属 JSON Schema は **2026-08-31 に Frozen** とされています。Resolver Core 0.1、Resolver Lifecycle 0.1、Resolver / Manifest Conformance Catalog 0.1、Web Runtime Integration Contract 0.1、Reference Resolver Architecture 0.1、Reference Resolver Deployment Profiles 0.1 は、実装/Testbedへの安定したhandoff baselineとして **2026-09-01 に Frozen** とされました。

Frozen Resolver Core 0.1 では editorial / non-semantic errata のみ 0.1 内で修正できます。L1 request semantics、`l`/`p` downgrade behavior、HTTP status / processing order、Description Location validation、HTTPS / network-policy semantics、lifecycle mapping、Manifest independence、public/admin responsibility boundary、Trust/L2 exclusion、Core conformance expectation の変更は、後続 Core version または別途 versioning された profile で扱います。

Frozen Resolver Lifecycle 0.1 では editorial / non-semantic errata のみ 0.1 内で修正できます。Lifecycle state、permitted transition、RETIRED terminal semantics、permitted-transition support requirement、administrative failure semantics、same-state no-op/history semantics、initial-registration semantics、public lifecycle mapping/non-distinction、cache semantics、Manifest lifecycle mapping、conformance derivation semantics の変更は、後続 Lifecycle version または別途 versioning された profile で扱います。

Frozen Manifest 0.1 では、standard member、wire semantics、integrity semantics、extension compatibility、security/trust semantics のsemantic変更は後続 Manifest version/profileで扱います。

Frozen Conformance Catalog 0.1 では、Conformance target、result semantics、case identifier、normative expectation、baseline/optional classification、security/network-policy semantics の変更は後続 catalog version/profileで扱います。

Frozen Web Runtime Integration Contract 0.1 では、Final URL semantics、retrieval ordering、Manifest association/integrity semantics、network-policy semantics、error boundary、L0/L1 classification、RT handoff expectation の変更は後続 contract version/profileで扱います。

Frozen Reference Resolver Architecture 0.1 では、Public/Admin責務境界、administrative transport/authentication/authorization semantics、CSRF、outbound-fetch/SSRF policy、integrity publishing consistency、database/input/output security、persistence/private-file boundary、implementation security acceptance の変更は後続 architecture version/profileで扱います。

Frozen Reference Resolver Deployment Profiles 0.1 では、deployment invariant、Native/Container equivalence、persistence/private-file semantics、trusted-proxy handling、administrative outbound-network policy、backup/restore semantics、security boundary、deployment acceptance expectation の変更は後続 deployment-profile version/profileで扱います。

## 仕様

- RELink Resolver Core 0.1 — **Frozen 2026-09-01**
  - [English](docs/specs/resolver-core-0.1.md)
  - [日本語](docs/specs/resolver-core-0.1.ja.md)
- RELink Resolver Lifecycle 0.1 — **Frozen 2026-09-01**
  - [English](docs/specs/resolver-lifecycle-0.1.md)
  - [日本語](docs/specs/resolver-lifecycle-0.1.ja.md)
- RELink Resolver / Manifest Conformance Catalog 0.1 — **Frozen 2026-09-01**
  - [English](docs/specs/resolver-manifest-conformance-0.1.md)
  - [日本語](docs/specs/resolver-manifest-conformance-0.1.ja.md)
- RELink Web Runtime Integration Contract 0.1 — **Frozen 2026-09-01**
  - [English](docs/specs/web-runtime-integration-0.1.md)
  - [日本語](docs/specs/web-runtime-integration-0.1.ja.md)
- RELink Reference Resolver Architecture 0.1 — **Frozen 2026-09-01**
  - [English](docs/specs/reference-resolver-architecture-0.1.md)
  - [日本語](docs/specs/reference-resolver-architecture-0.1.ja.md)
- RELink Reference Resolver Deployment Profiles 0.1 — **Frozen 2026-09-01**
  - [English](docs/specs/reference-resolver-deployment-profiles-0.1.md)
  - [日本語](docs/specs/reference-resolver-deployment-profiles-0.1.ja.md)
- RELink Manifest 0.1 — **Frozen 2026-08-31**
  - [English](docs/specs/manifest-0.1.md)
  - [日本語](docs/specs/manifest-0.1.ja.md)
  - [JSON Schema](docs/specs/manifest-0.1.schema.json)
- RELink Manifest 0.1 Extension Policy — **Frozen 2026-08-31**
  - [English](docs/specs/manifest-0.1-extension-policy.md)
  - [日本語](docs/specs/manifest-0.1-extension-policy.ja.md)

Frozen Resolver Core、Resolver Lifecycle、Manifest、Conformance Catalog、Web Runtime Integration Contract、Reference Resolver Architecture、Reference Resolver Deployment Profiles の英語文書が normative source text です。日本語文書は公式プロジェクト翻訳であり、解釈に差異がある場合は Frozen 英語文書を conformance の基準とします。

## アーキテクチャ

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

RELink は次の責務を分離します。

```text
Entity      ≠ Location
Capability  ≠ Interface
Resolution  ≠ Authentication
Description ≠ Execution
```

## Resolver Core

Resolver Core は意図的に最小限です。

```text
Anchor UUID
    ↓
Current AR-XML Description Location
```

Baseline:

```text
GET /{resolver-service}/{uuid}
    ↓
UUID lookup
    ↓
303 See Other
Location: https://...
```

L1 は public / GET-only / read-only / HTTPS-based です。Unsupported `l` や defining level semantics のない reserved `p` は L1 へsilent fallbackせずfail closedします。

Resolver Core は AR-XML をfetch/interpretせず、Entity capabilityを実行しません。Frozen Manifest 0.1 は Core responsibility を拡大せず、ACTIVE L1 resolution は Manifest retrieval/parsing なしで成立します。

## Lifecycle

Resolver Lifecycle 0.1 は implementation-independent なstate machineを定義します。

```text
ACTIVE    → SUSPENDED
SUSPENDED → ACTIVE
ACTIVE    → RETIRED
SUSPENDED → RETIRED
```

`RETIRED` は terminal。Public mapping:

```text
ACTIVE    → 303
SUSPENDED → 404
RETIRED   → 410
```

Transition reason / bounded historyはadministrative metadataであり、Lifecycleはauthentication、authorization、ownership transfer、Trust、capability executionを定義しません。

## Conformance Catalog

Frozen Resolver / Manifest Conformance Catalog 0.1 は Resolver Core、Lifecycle、Frozen Manifest 0.1、OPTIONAL integrity verification、extension compatibility、transport、cache/CORS、network-policy boundary、resource boundary の implementation-independent protocol test case を定義します。

```text
relink-resolver
= conformance definition

relink-testbed
= executable conformance implementation
```

## Web Runtime integration

Frozen Web Runtime Integration Contract 0.1 は Resolver/Web dereference から AR-XML Runtime processing へのhandoffを定義します。

```text
Requested URL ≠ necessarily AR-XML Document URL
Final AR-XML response URL = AR-XML document base URL
Verified representation bytes = parsed representation bytes
HTTP terminal failure ≠ AR-XML parse failure
```

Direct AR-XML load は L0/direct path、Resolver-mediated load は L1 pathです。Resolver-specific behaviorをAR-XML parser/capability invocation semanticsへ持ち込んではなりません。

## Reference Resolver architecture

Frozen Reference Resolver Architecture 0.1 は最初のApache/PHP/SQLite実装に対する非コード要件を定義します。

```text
Public
GET /relink/{uuid}
GET /relink/{uuid}/manifest (optional)
        ↓
read-only Resolver/Manifest services
        ↓
SQLite

Admin
/admin/
        ↓
authentication + authorization
        ↓
maintenance services + history
        ↓
SQLite
```

Public/Admin責務分離、UUID registration、Description Location/lifecycle maintenance、search/list/detail/history、resolution test、persistence、bounded history、Admin Web security、outbound-fetch/SSRF control、integrity publishing consistency、implementation security acceptanceを定義します。

## Deployment profiles

Frozen Reference Resolver Deployment Profiles 0.1 は Native / Docker の等価なpackaging/operations profileを定義します。

```text
Native
Apache + PHP + SQLite

Container
Docker-packaged Reference Resolver + persistent SQLite storage
```

両profileは同じprotocol-visible behavior、public/admin separation、durable state、TLS/proxy boundary、private-file boundary、outbound-network policy、SQLite-consistent backup/restore、migration semanticsを維持します。DockerはOPTIONALでprotocol requirementではありません。

## Manifest

Manifest は Resolver Core とは別仕様です。Minimal L1 Resolver は Manifest を必要とせず AR-XML へ直接 redirectできます。

Richer deployment は次のmetadataを公開できます。

```text
Anchor UUID
Canonical Entity Identity
Current AR-XML Location
Optional Description integrity metadata
Lifecycle metadata
Version information
Extensions
```

Default Manifest resource:

```text
GET /{resolver-service}/{uuid}/manifest
```

Manifest 0.1 は strict JSON、duplicate object-member name禁止、`extensions` namespace、OPTIONAL `description.integrity` を定義します。Integrity verification は authentication、freshness、anti-rollback、authorization、L2ではありません。

## Trust と Security

Trust、authentication、signature、authenticated mutation、ownership transfer、freshness/anti-rollback、authority mechanism は Resolver Core 0.1 / Manifest 0.1 の範囲外です。

Reference Resolverのadministrative authenticationはmaintenance surfaceを保護しますが、public L1 authentication semanticsを再定義せず、RELink L2を確立しません。

## Resolver Core の非目標

Resolver Core は次を行いません。

- current device IP resolution
- operational/management UI URL construction
- management console redirect
- device configuration
- capability invocation
- periodic Entity network-address reporting requirement
- ownership/trust chain establishment
- AR-XML capability interpretation

## 今後の成果物

Frozen 0.1 specification baseline は完成しています。今後の主な成果物:

- Reference Resolver implementation
- RELink Testbed integration definitions

## 関連プロジェクト

- RELink project site: https://ranmaru50.github.io/relink-site/
- RELink Web Runtime: https://github.com/ranmaru50/relink-web-runtime
- RELink Testbed: https://github.com/ranmaru50/relink-testbed

## 設計原則

```text
Resolver Core = minimal resolution
Manifest      = richer metadata
Trust         = later security / authority layer
Runtime       = consumer-facing interpretation and execution
```

Implementation phaseでもこの責務分離を維持します。
