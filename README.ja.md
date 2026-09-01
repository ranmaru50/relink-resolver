# RELink Resolver

RELink Resolver は、既存の Web インフラストラクチャを用いて、物理的・現実世界の Entity を addressable / discoverable / interactable / operable にする実験的アーキテクチャ RELink（Real Entity Link）の resolution layer です。

このリポジトリでは、RELink Resolver および Manifest の仕様、将来の Reference Resolver、Resolver interoperability test の定義を管理します。

[English](README.md) | 日本語

## 状態

現在は specification-first の設計段階です。

Resolver Core 0.1、Resolver Lifecycle 0.1、Reference Resolver Architecture 0.1、Reference Resolver Deployment Profiles 0.1 は Draft specification です。RELink Manifest 0.1、Manifest 0.1 Extension Policy、および付属 JSON Schema は **2026-08-31 に Frozen** とされています。Resolver / Manifest Conformance Catalog 0.1 と Web Runtime Integration Contract 0.1 は、実装/Testbedへ引き渡す安定したbaselineとして **2026-09-01 に Frozen** とされました。

Frozen Manifest 0.1 では、編集上または非semanticな errata は 0.1 の範囲で修正できます。一方、standard member、wire semantics、integrity semantics、extension compatibility、security / trust semantics に関する変更は、後続の Manifest version または別途 versioning された profile で扱います。

Frozen Conformance Catalog 0.1 では、編集上または非semanticな errata は 0.1 内で修正できます。Conformance target、result semantics、case identifier、normative case expectation、baseline / optional 分類、security / network-policy semantics の変更は、後続 catalog version または別途 versioning された profile で扱います。

Frozen Web Runtime Integration Contract 0.1では、編集上または非semanticなerrataは0.1内で修正できます。Final URL semantics、retrieval ordering、Manifest association/integrity semantics、network-policy semantics、error boundary、L0/L1 classification、RT handoff expectationの変更は後続contract versionまたは別途versioningされたprofileで扱います。

## 仕様

- RELink Resolver Core 0.1 — Draft
  - [English](docs/specs/resolver-core-0.1.md)
  - [日本語](docs/specs/resolver-core-0.1.ja.md)
- RELink Resolver Lifecycle 0.1 — Draft
  - [English](docs/specs/resolver-lifecycle-0.1.md)
  - [日本語](docs/specs/resolver-lifecycle-0.1.ja.md)
- RELink Resolver / Manifest Conformance Catalog 0.1 — **Frozen 2026-09-01**
  - [English](docs/specs/resolver-manifest-conformance-0.1.md)
  - [日本語](docs/specs/resolver-manifest-conformance-0.1.ja.md)
- RELink Web Runtime Integration Contract 0.1 — **Frozen 2026-09-01**
  - [English](docs/specs/web-runtime-integration-0.1.md)
  - [日本語](docs/specs/web-runtime-integration-0.1.ja.md)
- RELink Reference Resolver Architecture 0.1 — Draft
  - [English](docs/specs/reference-resolver-architecture-0.1.md)
  - [日本語](docs/specs/reference-resolver-architecture-0.1.ja.md)
- RELink Reference Resolver Deployment Profiles 0.1 — Draft
  - [English](docs/specs/reference-resolver-deployment-profiles-0.1.md)
  - [日本語](docs/specs/reference-resolver-deployment-profiles-0.1.ja.md)
- RELink Manifest 0.1 — **Frozen 2026-08-31**
  - [English](docs/specs/manifest-0.1.md)
  - [日本語](docs/specs/manifest-0.1.ja.md)
  - [JSON Schema](docs/specs/manifest-0.1.schema.json)
- RELink Manifest 0.1 Extension Policy — **Frozen 2026-08-31**
  - [English](docs/specs/manifest-0.1-extension-policy.md)
  - [日本語](docs/specs/manifest-0.1-extension-policy.ja.md)

Frozen Manifest、Conformance Catalog、Web Runtime Integration Contractの英語文書がnormative source textです。日本語文書は公式プロジェクト翻訳であり、解釈に差異がある場合は英語Frozen文書をconformanceの基準とします。

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

RELink は次の責務を明確に分離します。

```text
Entity      ≠ Location
Capability  ≠ Interface
Resolution  ≠ Authentication
Description ≠ Execution
```

## Resolver Core

Resolver Core は意図的に最小限に設計されています。

L1 baseline における主要な責務は次のとおりです。

```text
Anchor UUID
    ↓
Current AR-XML description location
```

想定される baseline interaction は次のとおりです。

```text
GET /{resolver-service}/{uuid}
    ↓
UUID lookup
    ↓
303 See Other
Location: https://...
```

L1 は public、GET-only、read-only、HTTPS-based を前提とします。

Resolver Core は AR-XML を解釈せず、Entity capability を実行しません。

Frozen Manifest 0.1 によって Resolver Core の責務が拡大することもありません。通常の ACTIVE L1 resolution は、Manifest を取得・解析しなくても成立しなければなりません。

## Lifecycle

Resolver Lifecycle 0.1 は、Resolver Core の public behaviorを維持したまま、Resolver recordのimplementation-independentなstate-transition modelを定義します。

```text
ACTIVE    → SUSPENDED
SUSPENDED → ACTIVE
ACTIVE    → RETIRED
SUSPENDED → RETIRED
```

Lifecycle 0.1では `RETIRED` はterminalです。Public L1 behaviorは次のままです。

```text
ACTIVE    → 303
SUSPENDED → 404
RETIRED   → 410
```

Lifecycle transition reasonやbounded historyはadministrative metadataです。Lifecycleはauthentication、authorization、ownership transfer、Trust、capability executionを定義しません。

## Conformance Catalog

Frozen Resolver / Manifest Conformance Catalog 0.1 は、Resolver Core、Lifecycle、Frozen Manifest 0.1、OPTIONAL integrity verification、extension compatibility、transport、cache/CORS、network-policy boundary、resource boundaryについて、実装に依存しないprotocol test caseを定義します。

Protocol expectationを定義するためcatalog自体はこのリポジトリで管理します。一方、実行可能test、fixture、runner、CI integration、report生成は `relink-testbed` 側へ委譲します。

```text
relink-resolver
= conformance definition

relink-testbed
= executable conformance implementation
```

## Web Runtime integration

Frozen Web Runtime Integration Contract 0.1は、通常のResolver/Web dereferenceからAR-XML Runtime processingへのhandoffを定義します。

中心ルールは次です。

```text
Requested URL ≠ necessarily AR-XML Document URL
Final AR-XML response URL = AR-XML document base URL
Verified representation bytes = parsed representation bytes
HTTP terminal failure ≠ AR-XML parse failure
```

Direct AR-XML loadはL0/direct path、Resolver-mediated loadはL1 pathです。Resolver固有behaviorをAR-XML parser/capability invocation semanticsへ持ち込んではなりません。

## Reference Resolver architecture

Reference Resolver Architecture 0.1は、最初のApache/PHP/SQLite実装に対する非コード要件を定義します。

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

同一applicationでdeployする場合もpublic/adminの責務を分離します。ArchitectureはUUID registration、Description Location/lifecycle maintenance、search/list/detail/history、resolution test、persistence boundary、bounded history、security requirement、implementation non-goalを定義しますが、具体的PHP classやSQLite DDLは固定しません。

## Deployment profiles

Reference Resolver Deployment Profiles 0.1は、RELink protocol semanticsを変更せず、NativeとDockerの等価なpackaging/operations profileを定義します。

```text
Native
Apache + PHP + SQLite

Container
Docker-packaged Reference Resolver + persistent SQLite storage
```

両profileは同じprotocol-visible behavior、public/admin separation、durable Resolver state、configuration responsibility、TLS/proxy boundary、backup/restore、migration semanticsを維持します。DockerはOPTIONALであり、protocol requirementではありません。

## Manifest

Manifest は Resolver Core とは分離された独立仕様です。

最小 L1 Resolver は Manifest を必要とせず、AR-XML へ直接 redirect できます。

よりrichな deployment では、次のような Entity-level resolution metadata を含む Manifest を公開できます。

```text
Anchor UUID
Canonical Entity Identity
Current AR-XML Location
Optional Description integrity metadata
Lifecycle metadata
Version information
Extensions
```

Manifest の標準的な取得 resource は Resolver Core と分離されています。

```text
GET /{resolver-service}/{uuid}/manifest
```

Manifest によって Resolver Core が execution、management、Trust service に変化してはなりません。

Manifest 0.1 の wire representation は strict JSON です。duplicate object-member name を禁止し、vendor / profile 固有 metadata は `extensions` namespace 配下へ分離します。また、`description.integrity` による OPTIONAL な AR-XML content pinning を定義します。

Integrity verification は authentication、freshness、anti-rollback、authorization、L2 のいずれでもありません。

## Trust と Security

Trust、authentication、signature、authenticated mutation、ownership transfer、freshness / anti-rollback mechanism、および関連する authority mechanism は Resolver Core 0.1 と Manifest 0.1 の範囲外です。

これらは、L1 identity / resolution model を再定義せず、後続 layer として設計することを想定しています。

Reference Resolverのadministrative authenticationはmaintenance surfaceを保護しますが、public L1 authentication semanticsを再定義せず、RELink L2を確立しません。

## Resolver Core の非目標

Resolver Core は次の処理を行いません。

- device の current IP address を解決する
- operational / management UI URL を構築する
- management console へ直接 redirect する
- device を設定する
- capability を実行する
- Entity に network address の定期報告を要求する
- ownership / trust chain を確立する
- AR-XML capability semantics を解釈する

## 今後の成果物

このリポジトリでは、次の成果物を扱う予定です。

- Resolver Core 0.1 specification
- Resolver Lifecycle 0.1 specification
- Frozen Resolver / Manifest Conformance Catalog 0.1
- Frozen Web Runtime Integration Contract 0.1
- Frozen Manifest 0.1 specification set
- Reference Resolver Architecture 0.1
- Reference Resolver Deployment Profiles 0.1
- Reference Resolver implementation
- RELink Testbed integration definitions

## 関連プロジェクト

- RELink project site: https://ranmaru50.github.io/relink-site/
- RELink Web Runtime: https://github.com/ranmaru50/relink-web-runtime
- RELink Testbed: https://github.com/ranmaru50/relink-testbed

## 設計原則

意図する責務分離は次のとおりです。

```text
Resolver Core = minimal resolution
Manifest      = richer metadata
Trust         = later security / authority layer
Runtime       = consumer-facing interpretation and execution
```

仕様が発展しても、この責務分離を維持します。
