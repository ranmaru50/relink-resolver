# RELink Resolver / Manifest Conformance Catalog 0.1 日本語版

Status: Draft conformance specification  
Version: 0.1  
Scope: Resolver Core 0.1、Resolver Lifecycle 0.1、Frozen Manifest 0.1

> この文書は `resolver-manifest-conformance-0.1.md` の日本語版です。解釈に差異がある場合は英語版を基準とします。

## 1. 目的

本書は、RELink Resolver Core 0.1、Resolver Lifecycle 0.1、Frozen RELink Manifest 0.1について、実装方式に依存しない決定的なconformance caseを定義します。

このcatalogは `relink-testbed` 側で実行可能テストとして実装されることを想定しますが、実際のテストコードは本リポジトリの範囲外です。

```text
relink-resolver
= protocol + conformance definition

relink-testbed
= executable test implementation
```

テストはPHP、SQLite、framework、process、deployment内部構造ではなく、外部から観測可能なprotocol behaviorとrepresentation semanticsを検証しなければなりません。

## 2. Case model

各caseは次の要素を持ちます。

- **ID**: 安定したcatalog identifier
- **Precondition**: 外部挙動に関係するstate / fixture
- **Action/Input**: system under testへ与えるrequest / representation
- **Expected**: 要求されるobservable result
- **Reference**: 根拠となるRELink仕様箇所

Testbed実装は1つのcatalog caseを複数の実行可能testに分割しても構いませんが、reportではcatalog IDを保持することを推奨します。

## 3. Result class

Testbedは少なくとも次を区別することを推奨します。

```text
PASS
FAIL
NOT-APPLICABLE
UNSUPPORTED-OPTIONAL
```

`UNSUPPORTED-OPTIONAL`は、Manifest integrity verificationなどbaseline conformanceで必須ではないOPTIONAL機能にのみ使用します。

## 4. Resolver resolution

- **RES-001** ACTIVE UUID → `303 See Other` + current validated HTTPS Description Location
- **RES-002** Description Location更新後、新しいorigin requestでは新Locationを返す。既存cacheはadvertised freshness内だけ旧値を返し得る
- **RES-003** 未登録のvalid UUID → `404 Not Found`
- **RES-004** unsafe / invalid stored Locationを外部へemitしてはならず、`500 Internal Server Error`を返すことを推奨

Reference: Resolver Core §§7-9, 11-14; Lifecycle §15.

## 5. Identifier handling

- **ID-001** lowercase RFC 9562 UUIDを受理
- **ID-002** uppercase UUIDを同一UUID valueとして受理
- **ID-003** mixed-case UUIDを受理
- **ID-004** malformed UUID → `400 Bad Request`
- **ID-005** valid UUID version-specific bitsからResolver semanticsを導出しない

Reference: Resolver Core §6.

## 6. HTTP method / level request

- **HTTP-001** public GETはread-onlyでResolver stateを変更しない
- **HTTP-002** unsupported method → `405 Method Not Allowed` + `Allow: GET`
- **HTTP-003** unsupported-method responseはACTIVE/SUSPENDED/unknownを区別しない
- **HTTP-004** unsupported `l`はL1へsilent downgradeせず、`501 Not Implemented`を推奨
- **HTTP-005** defining levelなしのreserved `p`はL1として処理せず、`400 Bad Request`を推奨

Reference: Resolver Core §§5, 7, 12.

## 7. Redirect / Transport

- **REDIR-001** Resolver前のordinary HTTPS redirectを許容
- **REDIR-002** Resolver `303`の後、consumer policyに従いAR-XMLへ到達
- **REDIR-003** Resolver後の追加HTTPS redirectを許容
- **REDIR-004** Resolver到達前のHTTPS→HTTP downgradeを拒否
- **REDIR-005** Resolver後からfinal AR-XMLまでのHTTPS→HTTP downgradeを拒否
- **REDIR-006** final AR-XML URLはHTTPS
- **REDIR-007** redirect loopはbounded redirect / loop detectionにより無限処理しない

Reference: Resolver Core §§10, 16-17.

## 8. Lifecycle

- **LIFE-001** ACTIVE → Core GET `303`
- **LIFE-002** SUSPENDED → `404`
- **LIFE-003** RETIRED → `410`
- **LIFE-004** ACTIVE → SUSPENDEDは許可
- **LIFE-005** SUSPENDED → ACTIVEは許可
- **LIFE-006** ACTIVE → RETIREDは許可
- **LIFE-007** SUSPENDED → RETIREDは許可
- **LIFE-008** RETIRED → ACTIVEは禁止
- **LIFE-009** RETIRED → SUSPENDEDは禁止
- **LIFE-010** lifecycleとDescription Locationは独立
- **LIFE-011** Reference Resolverがhistoryを保持する場合、previous/new state/timeが実際のtransitionと整合
- **LIFE-012** stale cacheはorigin stateを再定義しない

Reference: Resolver Lifecycle 0.1 §§3-16.

## 9. Cache / CORS

- **CACHE-001** ACTIVE `303`はexplicit cache policyを返す
- **CACHE-002** Reference defaultは `Cache-Control: public, max-age=60`
- **CACHE-003** `400/404/500/501/503`はReference profileで`no-store`推奨
- **CACHE-004** `410`はfinite cache可能
- **CORS-001** browser-oriented public Coreでは `Access-Control-Allow-Origin: *` 推奨
- **CORS-002** Resolver CORS成功はAR-XML origin CORS許可を意味しない

Reference: Resolver Core §§14-15.

## 10. Manifest baseline

- **MAN-001** required 5 fieldsを持つminimal valid Manifestを受理
- **MAN-002** `manifestVersion != "0.1"` はManifest 0.1として解釈しない
- **MAN-003** JSON5-only syntaxはwire representationとして拒否
- **MAN-004** duplicate object-member nameはnested extensionを含めて拒否
- **MAN-005** deterministic `/uuid/manifest`では`anchor.id`とpath UUIDが同一UUID valueでなければinvalid
- **MAN-006** `description.location`変更時に`entity.id`変更を要求しない
- **MAN-007** `entity.id`をURIだからという理由だけでdereferenceしない
- **MAN-008** L1の`description.location`はHTTPS必須
- **MAN-009** Manifest endpoint不在でもCore L1は成立
- **MAN-010** ACTIVE public Manifest → `200 application/json`
- **MAN-011** SUSPENDED public Manifest → `404`
- **MAN-012** RETIRED public Manifest → `410`
- **MAN-013** unknown UUID Manifest → `404`

Reference: Frozen Manifest 0.1 §§5-13, 23.

## 11. Manifest transport

- **MNET-001** L1 Manifest retrievalはHTTPS-only
- **MNET-002** Manifest redirect chainのHTTPS→HTTP downgradeを拒否
- **MNET-003** L1処理対象のfinal Manifest URLはHTTPS

Reference: Frozen Manifest 0.1 §11.1.

## 12. Manifest integrity

以下はManifest 0.1 integrity-verification supportをclaimするconsumerに適用します。

- **INT-001** integrity absentでもbaseline Manifestはvalid
- **INT-002** valid `sha-256` digest match → verification success
- **INT-003** digest mismatch → integrity failure。policyがverification必須ならcapability discovery/invocationへ進まない
- **INT-004** unsupported algorithmをverifiedとして報告しない
- **INT-005** `sha-256` digestは64文字lowercase hexでなければinvalid
- **INT-006** intermediate redirect bodyはdigest inputではない
- **INT-007** digest inputはHTTP content-coding処理後、character decode/XML parse前のfinal representation body octets
- **INT-008** digest matchをauthentication / authenticity / freshness / rollback protection / authorization / L2として扱わない

Reference: Frozen Manifest 0.1 §9.2.

## 13. Extension

- **EXT-001** unknown non-critical top-level memberはforward compatibilityとして原則無視可能
- **EXT-002** unknown vendor extensionはbaseline processingを壊さず無視可能
- **EXT-003** extensionは`description.location`をoverrideできない
- **EXT-004** extensionは`lifecycle.status`をoverride / reinterpretできない
- **EXT-005** extensionは`description.integrity` semanticsを再定義できない
- **EXT-006** `trusted` / `verified` / `signature` / `publicKey`等の名前だけでTrust semanticsを得ない
- **EXT-007** vendor extensionをbaseline Manifest 0.1 / Core L1 dependencyにしてはならない

Reference: Frozen Manifest 0.1 §12; Extension Policy §§2-6.

## 14. Resource consumption

- **LIMIT-001** consumerはdocumented finite body-size limit超過Manifestを拒否可能
- **LIMIT-002** finite JSON nesting limitを推奨
- **LIMIT-003** finite member/element/time/memory limitを推奨

Universal numeric limitではなく、実装がdocumented limitを一貫して適用することを検証します。

Reference: Frozen Manifest 0.1 §17.

## 15. JSON Schema

- **SCHEMA-001** Schema validation successだけではconformance成立としない
- **SCHEMA-002** validatorが`format`をannotationとして扱ってもUUID/URIのnormative semantic checksを省略しない

Reference: Frozen Manifest 0.1 §21.

## 16. Testbed implementation boundary

`relink-testbed`側の実行実装は任意の言語・frameworkを利用できます。

Testbedは、少なくとも次のfixtureを制御できることを推奨します。

- Resolver lifecycle state
- Description Location mutation
- HTTP redirects / downgrade path
- cache headers
- malformed / duplicate-member Manifest
- content-coded AR-XML response
- integrity match / mismatch
- extension payload
- bounded-resource cases

外部から確認可能な挙動がある場合、内部datastore stateだけを見てprotocol conformanceと判定してはなりません。

## 17. Reporting

Conformance reportでは次を記録することを推奨します。

```text
catalog version
implementation under test
execution environment
case ID
result
optional diagnostic detail
```

OPTIONAL機能未対応とbaseline failureを明確に分離します。

## 18. Scope boundary

本catalogは次を定義しません。

- production Resolver code
- database schema
- Docker / native deployment implementation
- AR-XML capability conformance全体
- L2 / Trust authentication tests
- vendor-specific application behavior

## 19. Summary

```text
Resolver Core
    RES-* ID-* HTTP-* REDIR-* CACHE-* CORS-*

Lifecycle
    LIFE-*

Manifest
    MAN-* MNET-* SCHEMA-*

Optional integrity
    INT-*

Extension compatibility
    EXT-*

Resource hardening
    LIMIT-*
```

本catalogは、後続の `relink-testbed` 実装へ渡すprotocol-side handoff contractです。