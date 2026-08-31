# RELink Resolver / Manifest Conformance Catalog 0.1 日本語版

Status: Draft conformance specification  
Version: 0.1  
Scope: Resolver Core 0.1、Resolver Lifecycle 0.1、Frozen Manifest 0.1

> この文書は `resolver-manifest-conformance-0.1.md` の日本語版です。解釈に差異がある場合は英語版を基準とします。

## 1. 目的

本書は、RELink Resolver Core 0.1、Resolver Lifecycle 0.1、Frozen RELink Manifest 0.1について、実装方式に依存しない決定的なconformance caseを定義します。

このcatalogは `relink-testbed` 側で実行可能testとして実装されることを想定しますが、実際のtest codeは本リポジトリの範囲外です。

```text
relink-resolver
= protocol + conformance definition

relink-testbed
= executable test implementation
```

TestはPHP、SQLite、framework、process、deployment内部構造ではなく、外部から観測可能なprotocol behaviorとrepresentation semanticsを検証しなければなりません。

## 2. Conformance targetとcase model

各catalog caseは、誰を試験するかを明示しなければなりません。定義するtargetは次のとおりです。

```text
RESOLVER-SERVER
L1-CONSUMER
MANIFEST-ENDPOINT
MANIFEST-PRODUCER
MANIFEST-CONSUMER
INTEGRITY-CONSUMER
LIFECYCLE-ADMIN
REFERENCE-RESOLVER
```

1つの実装が複数targetをclaimしても構いませんが、異なるtargetの結果を曖昧な「Resolver / Manifest PASS」へ統合してはなりません。

各caseは次の要素を持ちます。

- **ID**: 安定したcatalog identifier
- **Target**: conformance role
- **Strength**: 主に試験するnormative strength（`MUST` / `SHOULD` / `MAY`）
- **Precondition**: 外部挙動に関係するstate / fixture
- **Action/Input**: system under testへ与えるrequest / raw representation
- **Expected**: 要求されるobservable result
- **Reference**: 根拠となるRELink仕様箇所

Testbed実装は1つのcatalog caseを複数の実行可能testに分割しても構いませんが、reportではcatalog IDを保持することを推奨します。

## 3. Result classとnormative strength

Testbedは少なくとも次を区別することを推奨します。

```text
PASS
FAIL
PASS-WITH-DEVIATION
NOT-APPLICABLE
UNSUPPORTED-OPTIONAL
```

**MUST / MUST NOT** を満たさない場合、対象targetのconformance failureであり `FAIL` としなければなりません。

**SHOULD / SHOULD NOT** からの逸脱は必ずreportしなければなりません。ただし、実装が妥当な理由をdocumentしている場合、baseline conformance failureとは自動的にみなしません。その場合は、別profileがmandatoryとしていない限り `PASS-WITH-DEVIATION` を推奨します。

**MAY** caseは許容されるbehaviorまたはoptional interoperabilityを試験します。optional behaviorがないことをbaseline failureとしてはなりません。

`UNSUPPORTED-OPTIONAL`は、Manifest integrity verificationなど、該当baseline targetが要求しないOPTIONAL機能にのみ使用します。

## 4. Conformance set

```text
Resolver Core Server Conformance
= RES-* + ID-* + HTTP-* + server-side CACHE-* + CORS-001

Resolver L1 Consumer Conformance
= REDIR-* + NET-* + CORS-002 where applicable

Resolver Lifecycle Administration Conformance
= LIFE-* administrative-transition cases

Reference Resolver Operational Conformance
= reference-profile SHOULD cases + LIFE-011 + documented resource/admin behavior

Manifest Producer / Endpoint Conformance
= MAN representation/endpoint subset + MNET server-side requirements

Manifest Consumer Conformance
= MAN parsing/semantic subset + MNET consumer subset + EXT-* + LIMIT-* + SCHEMA-*

Manifest Integrity Verification Conformance
= INT-* only
```

Conformance reportでは、実行したsetを明示することを推奨します。

## 5. Resolver resolution

### RES-001 — ACTIVE UUID resolves
**Target:** RESOLVER-SERVER  
**Strength:** MUST  
ACTIVE UUIDのCore GETは`303 See Other`を返し、`Location`はcurrent validated HTTPS Description Locationと一致しなければなりません。

### RES-002 — Description Location更新の反映
**Target:** RESOLVER-SERVER  
**Strength:** MUST  
A→B更新後のfresh origin requestはBを返さなければなりません。既存intermediary cacheはadvertised freshness内のみAを返し得ます。

### RES-003 — unknown UUID
**Target:** RESOLVER-SERVER  
**Strength:** MUST  
validだが未登録UUIDは`404 Not Found`。

### RES-004 — unsafe stored Locationをemitしない
**Target:** RESOLVER-SERVER  
**Strength:** unsafe value非emitはMUST、`500`はSHOULD。  
Testbedは少なくとも次のsubcaseを実装することを推奨します。

```text
RES-004/relative-uri
RES-004/http-scheme
RES-004/malformed-absolute-uri
RES-004/header-injection
```

Reference: Resolver Core §§7-9, 11-14; Lifecycle §15.

## 6. Identifier handling

**Target:** RESOLVER-SERVER

- **ID-001** valid lowercase RFC 9562 UUIDをMUST受理
- **ID-002** uppercase UUIDを同一UUID valueとしてMUST受理
- **ID-003** valid mixed-case UUIDをMUST受理
- **ID-004** malformed UUID → `400 Bad Request`
- **ID-005** supported/registered UUIDについてversion-specific bitsのみでResolver semanticsを変更してはならない

Reference: Resolver Core §6.

## 7. HTTP method / level request

**Target:** RESOLVER-SERVER

- **HTTP-001** public GETはMUST be read-only
- **HTTP-002** unsupported method → MUST `405 Method Not Allowed` + `Allow: GET`
- **HTTP-003** unsupported methodはACTIVE/SUSPENDED/RETIRED/unknownの全てで`405`
- **HTTP-004** unsupported `l`はMUST fail closed、Core-only Resolverは`501` SHOULD
- **HTTP-005** defining levelなしreserved `p`はMUST fail closed、Core-only Resolverは`400` SHOULD

HTTP-003は次を直接試験します。

```text
unsupported method + ACTIVE    → 405
unsupported method + SUSPENDED → 405
unsupported method + RETIRED   → 405
unsupported method + unknown   → 405
```

Reference: Resolver Core §§5, 7, 12.

## 8. Redirect / Transport

**Target:** 原則L1-CONSUMER

- **REDIR-001** Resolver前のordinary HTTPS redirectはMAY
- **REDIR-002** Resolver `303`の後、network policyに従ってAR-XMLへ到達
- **REDIR-003** Resolver後の追加HTTPS redirectはMAY
- **REDIR-004** Resolver前のHTTPS→HTTP downgradeはMUST fail
- **REDIR-005** Resolver後のHTTPS→HTTP downgradeはMUST fail
- **REDIR-006** final AR-XML URLはMUST HTTPS
- **REDIR-007** bounded redirect / loop detectionをSHOULD実装し、unbounded dereferenceしてはならない

Reference: Resolver Core §§10, 16-17.

## 9. Consumer Network Policy

このgroupはpolicy enforcementを試験し、private/local destinationをprotocol全体で禁止するものではありません。

- **NET-001** — **Target:** L1-CONSUMER; Resolver Locationはuntrusted inputとしてMUST policy適用
- **NET-002** — **Target:** MANIFEST-CONSUMER; Manifest `description.location`はMUST policy適用
- **NET-003** — configured policyがXをdenyする場合、enforce可能な環境ではMUST Xをfetchしない
- **NET-004** — native/server等でredirect target inspection/control可能なら、allowed→denied XへのredirectをSHOULD block
- **NET-005** — successful `303`またはManifest `200`をnetwork restriction bypassの許可として扱ってはならない

`127.0.0.1 MUST reject`のようなprotocol-wide ruleは定義しません。Local Entity accessはdeployment/policy dependentです。

Reference: Resolver Core §16; Frozen Manifest 0.1 §15.

## 10. Lifecycle

- **LIFE-001** — Target RESOLVER-SERVER; ACTIVE → `303` MUST
- **LIFE-002** — Target RESOLVER-SERVER; SUSPENDED → `404` MUST
- **LIFE-003** — Target RESOLVER-SERVER; RETIRED → `410` MUST
- **LIFE-004** — Target LIFECYCLE-ADMIN; ACTIVE → SUSPENDED MUST permit
- **LIFE-005** — Target LIFECYCLE-ADMIN; SUSPENDED → ACTIVE MUST permit
- **LIFE-006** — Target LIFECYCLE-ADMIN; ACTIVE → RETIRED MUST permit
- **LIFE-007** — Target LIFECYCLE-ADMIN; SUSPENDED → RETIRED MUST permit
- **LIFE-008** — Target LIFECYCLE-ADMIN; RETIRED → ACTIVE MUST reject
- **LIFE-009** — Target LIFECYCLE-ADMIN; RETIRED → SUSPENDED MUST reject
- **LIFE-010** — lifecycleとDescription Locationはsemanticに独立
- **LIFE-011** — Target REFERENCE-RESOLVER; retained historyはprevious/new state/time整合をSHOULD保持
- **LIFE-012** — Target RESOLVER-SERVER; originはcommitted stateをMUST反映。stale cacheはHTTP freshnessのみで規定

Reference: Resolver Lifecycle 0.1 §§3-16.

## 11. Cache / CORS

- **CACHE-001** — Target RESOLVER-SERVER; ACTIVE `303`はexplicit cache policy MUST
- **CACHE-002** — Target REFERENCE-RESOLVER; default `public, max-age=60` SHOULD
- **CACHE-003** — Target REFERENCE-RESOLVER; `400/404/500/501/503`は`no-store` SHOULD
- **CACHE-004** — Target RESOLVER-SERVER; `410`はMAY finite cache
- **CORS-001** — Target RESOLVER-SERVER; browser-oriented Coreは`Access-Control-Allow-Origin: *` SHOULD
- **CORS-002** — Target L1-CONSUMER; Resolver CORS successをAR-XML fetch permissionとして扱ってはならない

Reference: Resolver Core §§14-15.

## 12. Manifest baseline

- **MAN-001** — Target MANIFEST-CONSUMER / MANIFEST-PRODUCER; required 5 fieldsのminimal valid ManifestをMUST accept/produce
- **MAN-002** — Target MANIFEST-CONSUMER; unsupported `manifestVersion`を0.1として解釈してはならない
- **MAN-003** — Target MANIFEST-CONSUMER / PRODUCER; JSON5-only syntaxをwireでMUST reject/not produce
- **MAN-004** — duplicate memberをMUST reject/not produce
- **MAN-005** — deterministic endpointでは`anchor.id`とpath UUID一致MUST
- **MAN-006** — `description.location`変更時に`entity.id`変更を要求してはならない
- **MAN-007** — `entity.id`はURIであるだけではMUST NOT dereference
- **MAN-008** — L1 `description.location`はHTTPS MUST
- **MAN-009** — Manifest不在/失敗をCore L1失敗としてはならない
- **MAN-010** — Target MANIFEST-ENDPOINT; ACTIVE → `200 application/json` SHOULD
- **MAN-011** — SUSPENDED → `404` SHOULD
- **MAN-012** — RETIRED → `410` SHOULD
- **MAN-013** — unknown UUID → `404` SHOULD
- **MAN-014** — optional `description.integrity`不在でもbaseline Manifest validityを失わない
- **MAN-015** — integrity存在時は`algorithm`+`digest`必須。`sha-256` digestはexact 64 lowercase hex MUST

### MAN-004 fixture rule

Duplicate-member caseは、通常のJSON object構築前の**raw Manifest UTF-8 bytes/text**としてconsumerへ渡さなければなりません。Testbedが事前に`JSON.parse()`等を行いduplicate memberを消失させてはなりません。

Reference: Frozen Manifest 0.1 §§5-13, 23.

## 13. Manifest transport

- **MNET-001** — Target MANIFEST-ENDPOINT / CONSUMER; L1 Manifest retrievalはHTTPS MUST
- **MNET-002** — Target MANIFEST-CONSUMER; HTTPS→HTTP downgradeはMUST reject/prevent
- **MNET-003** — Target MANIFEST-CONSUMER; final Manifest URLはHTTPS-only chain経由のHTTPS MUST

Reference: Frozen Manifest 0.1 §11.1.

## 14. Optional Manifest integrity verification

以下は**INTEGRITY-CONSUMER** targetだけに適用します。Integrity verificationをclaimしないbaseline Manifest Consumerは `FAIL` ではなく `UNSUPPORTED-OPTIONAL` とします。

- **INT-002** — valid `sha-256` digest matchをverification successとして扱う
- **INT-003** — mismatchはMUST integrity failureとしてexposeし、policyがverified integrityを要求する場合、そのrepresentationをintegrity-verified inputとして後続AR-XML処理へ受理してはならない
- **INT-004** — unsupported algorithmをverifiedとして報告してはならない。local policyでunverifiable分類MAY
- **INT-006** — intermediate redirect bodyはdigest inputではない
- **INT-007** — digest inputはHTTP content-coding処理後、character decode/XML parse前のfinal body octets
- **INT-008** — digest matchをauthentication / Manifest authenticity / authorization / freshness / rollback protection / L2として報告してはならない

INT-003はautomatic capability discovery/invocationや特定Runtime APIを要求しません。

旧`INT-001`と`INT-005`のbaseline semanticsは、それぞれ`MAN-014`と`MAN-015`へ移しました。Testbedはhistorical report互換のためaliasを保持しても構いません。

Reference: Frozen Manifest 0.1 §9.2.

## 15. Extension

**Target:** 原則MANIFEST-CONSUMER

- **EXT-001** unknown non-critical top-level memberはSHOULD processable、標準semanticsをoverride不可
- **EXT-002** unknown vendor extensionはSHOULD ignoreしてbaseline継続
- **EXT-003** `description.location` override禁止
- **EXT-004** `lifecycle.status` override/reinterpret禁止
- **EXT-005** `description.integrity` semantics再定義禁止
- **EXT-006** `trusted` / `verified` / `signature` / `publicKey`等の名前だけでTrust semanticsを得ない
- **EXT-007** vendor extensionをbaseline Manifest/Core dependencyにしてはならない

Reference: Frozen Manifest 0.1 §12; Extension Policy §§2-6.

## 16. Resource consumption

**Target:** MANIFEST-CONSUMER

- **LIMIT-001** documented finite body-size limit超過ManifestをMAY reject
- **LIMIT-002** finite JSON nesting-depth limitをSHOULD enforce
- **LIMIT-003** finite member/element/time/memory limitsをSHOULD enforce

Universal numeric valueではなく、documented limitの存在と一貫したenforcementを試験します。

Reference: Frozen Manifest 0.1 §17.

## 17. JSON Schema

**Target:** MANIFEST-CONSUMER / validation tooling

- **SCHEMA-001** Schema successでもnormative semantic violationはMUST reject
- **SCHEMA-002** validatorが`format`をannotation扱いしてもUUID/absolute URI semanticsをMUST check

Reference: Frozen Manifest 0.1 §21.

## 18. Testbed implementation boundary

Testbedは任意の実装言語/frameworkを使用できます。

次のfixtureを制御できることを推奨します。

- Resolver lifecycle state
- Description Location mutation
- HTTP redirects / downgrade path
- configured allow/deny network-policy decisions
- cache headers
- raw malformed / duplicate-member Manifest representation
- content-coded AR-XML response
- integrity match / mismatch
- extension payload
- bounded-resource cases

外部から確認可能な挙動がある場合、内部datastore stateだけでprotocol conformanceを判定してはなりません。

## 19. Reporting

Conformance reportでは次を記録することを推奨します。

```text
catalog version
conformance target / set
implementation under test
execution environment
case ID
normative strength
result
optional deviation reason
diagnostic detail
```

`PASS-WITH-DEVIATION`をSHOULD/SHOULD NOT厳密充足と区別不能に表示してはなりません。

OPTIONAL機能未対応とbaseline failureを明確に分離します。

## 20. Scope boundary

本catalogは次を定義しません。

- production Resolver code
- database schema
- Docker / native deployment implementation
- AR-XML capability conformance全体
- L2 / Trust authentication tests
- vendor-specific application behavior

## 21. Conformance set summary

```text
Resolver Core Server
    RES-* ID-* HTTP-* CACHE-* CORS-001

Resolver L1 Consumer
    REDIR-* NET-* CORS-002

Lifecycle Administration
    LIFE-004..LIFE-010

Reference Resolver
    reference-profile SHOULD cases + LIFE-011

Manifest Producer / Endpoint
    MAN-* producer/endpoint subset + MNET-001

Manifest Consumer
    MAN-* consumer subset + MNET-* + NET-* + EXT-* + LIMIT-* + SCHEMA-*

Optional Integrity Verification
    INT-002 INT-003 INT-004 INT-006 INT-007 INT-008
```

本catalogは、後続の `relink-testbed` 実装へ渡すprotocol-side handoff contractです。