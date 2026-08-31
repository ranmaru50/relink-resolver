# RELink Manifest 0.1 — Extension Policy 日本語版

Status: Frozen normative supplement（公式日本語訳）  
Version: 0.1  
Freeze date: 2026-08-31  
Applies to: `docs/specs/manifest-0.1.md` §12

> この文書は Frozen 状態の `manifest-0.1-extension-policy.md` の公式日本語訳です。要件語 **MUST / SHOULD / MAY** 等は英語版と同じ意味を保持します。解釈に差異がある場合は英語版 Frozen 文書を優先します。

## 1. 目的

本書は、RELink Manifest 0.1 のrequired minimal representationを変更することなく、extensionおよびunknown-member rulesを厳密化します。

設計目的は、将来のRELink-defined memberに対するforward compatibilityを維持しながら、vendor-specific metadataがRELink standard member namespaceを占有または再定義することを防ぐことです。

責務分離は次のとおりです。

```text
RELink standard namespace
    manifestVersion
    anchor
    entity
    description
    lifecycle
    extensions
    future RELink-defined members

Vendor / profile namespace
    extensions[<extension-name>]
```

このsupplementはManifest 0.1 wire format、required member、Resolver Core behavior、AR-XML semantics、Trust/L2 semanticsを変更しません。

このExtension PolicyはManifest 0.1と同時にFreezeされています。editorial correctionとnon-semantic errataは0.1内で適用して MAY ですが、interoperable extension behavior、namespace ownership、compatibility rule、security semanticsを変更する場合は後続Manifest versionまたは別途version管理されたprofileが必要です。

英語版がnormative sourceです。公式日本語訳と矛盾する場合は英語版Frozen文書を優先します。

## 2. Unknown members

Manifest 0.1 Consumerは:

- Manifest 0.1でsemanticsが定義されるmemberをすべて MUST validate
- later specificationが別processing semanticsを明示しない限り、理解しないunknown memberを SHOULD ignore
- unknown memberをauthentication、authorization、Trust evidence、RELink security level proofとして扱っては MUST NOT
- unknown memberにManifest 0.1 defined memberをoverride、replace、negate、reinterpretさせては MUST NOT
- baseline Manifest 0.1 processingのためにunknown memberを要求しては MUST NOT

unknown top-level memberは主として将来RELink specificationとのforward compatibility用に予約されます。その存在だけでvendor-extension mechanismと解釈してはなりません。

Producerはvendor-specificまたはdeployment-specific memberをtop-level Manifest namespaceへ直接追加しないことを SHOULD します。

## 3. Vendor / profile extensions

vendor-specific、product-specific、experimental、deployment-profile metadataはtop-level `extensions` object配下へ置くことを SHOULD します。

```json
{
  "extensions": {
    "com.example.relink/device": {
      "model": "RX-100",
      "firmwareFamily": "3.x"
    }
  }
}
```

Extension nameは:

- non-empty JSON object-member nameで MUST
- extension producer/specification ownerがcontrolすることを SHOULD
- reverse-domain nameまたはabsolute URI等のcollision-resistant identifierを SHOULD 使用
- unrelated scalar fieldごとではなくcoherent extension namespaceを識別することを SHOULD

例:

```text
com.example.relink/device
org.example/profile-v1
https://example.com/relink/extensions/device
```

Manifest 0.1はextension nameの処理だけのためにonline registry、DNS lookup、URI dereference、licensing service、external authorityを要求しません。

## 4. Extension isolation

compatible extensionはManifest 0.1 core modelに意味上従属していなければなりません。

Extensionは以下をしては MUST NOT です。

- `manifestVersion`の意味を再定義
- `anchor.id`をreplace/override
- `entity.id`をreplace/override
- `description.location`をreplace/override
- `lifecycle.status`をreplace/override
- `description.integrity`の意味またはverification semanticsを再定義
- Resolver Core resolutionをextension processingに依存させる
- baseline Manifest 0.1 interpretationをextension processingに依存させる
- AR-XML modelの意味を変更する形でcapability/invocation semanticsを複製する
- extensionの存在だけでauthentication、authorization、Trust verification、高いRELink security level達成をclaimする

例えば次のextensionをstandard Description Locationの代替に使ってはなりません。

```json
{
  "description": {
    "location": "https://standard.example/entity.arxml"
  },
  "extensions": {
    "com.example/override": {
      "actualLocation": "https://vendor.example/entity.arxml"
    }
  }
}
```

vendor-aware Consumerであっても、extensionが存在するという理由だけで`actualLocation`をManifest 0.1の`description.location`として再解釈してはなりません。

## 5. Optional processing

Consumerは既知extensionを1つ以上実装して MAY です。

Extensionを実装しないConsumerは:

- unknown extensionを SHOULD ignore
- required standard memberがvalidならbaseline Manifest 0.1 processingを MUST 継続
- extensionを定義する別仕様が別途negotiated profileとして異なるbehaviorを明示しない限り、unknown optional extensionだけを理由にfailureとしては MUST NOT

ProducerはextensionをManifest 0.1 conformance全般の必須条件として記述してはなりません。

Application固有operationにextensionが必要なら、そのrequirementはapplication/profileに属し、baseline Manifest 0.1 conformanceを再定義してはなりません。

## 6. Security boundary

Manifest 0.1はvendor/profile extensionにintrinsic Trust semanticsを付与しません。

Extensionは将来Trust、L2、application、deployment-specific specification向けmetadataを保持して MAY ですが、verificationとfailure semanticsはその別仕様で定義しなければなりません。

`trusted`、`verified`、`signature`、`publicKey`等の名前を持つfieldであっても、その名前や存在だけでRELink Trust semanticsを獲得してはなりません。

```text
Manifest extension metadata
≠ authentication
≠ authorization
≠ Trust verification
≠ L2 achievement
```

## 7. Standardization path

vendor/profile extensionは、独立implementation間のinteroperabilityに共通semanticsが必要になった場合、将来RELink-defined standard member候補になって MAY です。

標準化は後続Manifest specification/profileで次を SHOULD 定義します。

- standard member name
- exact semantics
- required/optional status
- compatibility behavior
- 必要な場合、既存vendor extensionからのmigration

既存vendor extension nameは、使用実績だけで自動的にRELink standard reserved nameにはなりません。

## 8. Compatibility summary

```text
Unknown standard-looking member
    → forward compatibilityのため SHOULD ignore

Vendor-specific metadata
    → SHOULD use extensions[namespace]

Unknown extension
    → SHOULD ignore

Extension
    → MUST NOT override standard semantics
    → MUST NOT become a baseline dependency
    → MUST NOT imply Trust/L2
```

Minimal Manifest 0.1 representationは変更されません。
