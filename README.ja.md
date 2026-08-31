# RELink Resolver

RELink Resolver は、既存の Web インフラストラクチャを用いて、物理的・現実世界の Entity を addressable / discoverable / interactable / operable にする実験的アーキテクチャ RELink（Real Entity Link）の resolution layer です。

このリポジトリでは、RELink Resolver および Manifest の仕様、将来の Reference Resolver、Resolver interoperability test の定義を管理します。

[English](README.md) | 日本語

## 状態

現在は specification-first の設計段階です。

Resolver Core 0.1 は Draft specification です。RELink Manifest 0.1、Manifest 0.1 Extension Policy、および付属 JSON Schema は **2026-08-31 に Frozen** とされ、実装および conformance 作業のための安定した Manifest 0.1 baseline を構成します。

Frozen Manifest 0.1 では、編集上または非semanticな errata は 0.1 の範囲で修正できます。一方、standard member、wire semantics、integrity semantics、extension compatibility、security / trust semantics に関する変更は、後続の Manifest version または別途 versioning された profile で扱います。

## 仕様

- RELink Resolver Core 0.1 — Draft
  - [English](docs/specs/resolver-core-0.1.md)
  - [日本語](docs/specs/resolver-core-0.1.ja.md)
- RELink Manifest 0.1 — **Frozen 2026-08-31**
  - [English](docs/specs/manifest-0.1.md)
  - [日本語](docs/specs/manifest-0.1.ja.md)
  - [JSON Schema](docs/specs/manifest-0.1.schema.json)
- RELink Manifest 0.1 Extension Policy — **Frozen 2026-08-31**
  - [English](docs/specs/manifest-0.1-extension-policy.md)
  - [日本語](docs/specs/manifest-0.1-extension-policy.ja.md)

Frozen Manifest 0.1 の英語文書が normative source text です。日本語文書は公式プロジェクト翻訳であり、解釈に差異がある場合は英語 Frozen 文書を Manifest 0.1 conformance の基準とします。

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
- Frozen Manifest 0.1 specification set
- Reference Resolver design / implementation
- Resolver interoperability test cases
- RELink Testbed integration definitions
- Web Runtime integration notes
- Native deployment profile
- Container deployment profile

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
