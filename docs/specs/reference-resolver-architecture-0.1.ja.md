# RELink Reference Resolver Architecture 0.1 日本語版

Status: Draft reference architecture  
Version: 0.1  
Scope: First Reference Resolver implementation

> この文書は `reference-resolver-architecture-0.1.md` の公式日本語版です。解釈に差異がある場合は英語版を基準とします。

## 1. 目的

本書は、最初のRELink Reference Resolver実装に対する非コードのarchitecture / maintenance requirementsを定義します。

実装baselineは次です。

```text
Apache
PHP
SQLite
```

本書はcomponent boundary、data responsibility、public/admin separation、persistence/history、operational security、non-goalを規定します。具体的なPHP class、DB DDL、framework、deployment packagingは規定しません。

## 2. Governing specifications

Reference Resolverは、適用されるRELink仕様およびFrozen handoff contractへ適合しなければなりません。

- Resolver Core 0.1
- Resolver Lifecycle 0.1
- Frozen Manifest 0.1 / Extension Policy
- Frozen Resolver / Manifest Conformance Catalog 0.1
- Runtime interoperabilityに関係する場合はFrozen Web Runtime Integration Contract 0.1

Reference実装の都合でprotocol semanticsを再定義してはなりません。

## 3. Architecture separation

次の分離を維持します。

```text
Public resolution ≠ Maintenance administration
Resolver Core     ≠ Manifest
Manifest          ≠ Trust
Resolution        ≠ Capability execution
Persistence model ≠ Public protocol
```

概念構造:

```text
Public surface
GET /relink/{uuid}
GET /relink/{uuid}/manifest   (optional)
        ↓
Public application boundary
        ↓
Repository / persistence port
        ↓
SQLite

Administrative surface
/admin/
        ↓
authentication + authorization
        ↓
Maintenance application boundary
        ↓
Repository / history port
        ↓
SQLite
```

Public/Adminが同一deployable application内にあっても、route、authorization requirement、responsibilityは分離しなければなりません。

## 4. Public Resolver surface

Public Resolver CoreはGET-only / read-onlyでなければなりません。

Core resolutionはResolver Core/Lifecycleのobservable behaviorを実装します。

```text
ACTIVE    → 303 + current validated HTTPS Description Location
SUSPENDED → 404
RETIRED   → 410
unknown   → 404
```

Unsupported method、malformed UUID、unsupported `l`、reserved `p`、cache/CORS/error behaviorは governing spec と Frozen Conformance Catalog に従います。

Public Core pathでadministrative mutationを行ってはなりません。

Public Core pathはadministrator authenticationを要求してはならず、Anchor UUIDをauthorization credentialとして扱ってはなりません。

## 5. Manifest surface

Reference Resolverは次のdeterministic Manifest resourceを提供しても構いません。

```text
GET /relink/{uuid}/manifest
```

実装する場合はFrozen Manifest 0.1 / Extension Policyへ適合するstrict JSONを出力しなければなりません。

Manifest生成はstored Resolver/Entity metadataから行います。Public request中にManifestを生成・refresh・verifyするためだけにAR-XMLをfetch/parseしてはなりません。

`description.integrity`を保存している場合はManifestでemitしても構いません。Digestの計算/refreshは明示的なpublishing/maintenance operationの責務であり、public request pathの責務ではありません。

Manifest supportを通常ACTIVE Core L1 resolutionの依存にしてはなりません。

## 6. Persistence responsibilities

Persistence layerは、少なくとも次のlogical informationを表現できなければなりません。

```text
Anchor UUID
current lifecycle state
current Description Location
Manifest使用時のCanonical Entity Identity
optional Manifest media type metadata
optional Manifest integrity metadata
created/updated administrative metadata
bounded material history
```

具体的SQLite schemaはimplementation-definedです。

DB schema、primary key、table name、row ID、index、migration方式をpublic protocol semanticsへ漏らしてはなりません。

Anchor UUIDはpublic Resolver record identifierであり、secret/bearer credentialとして扱ってはなりません。

## 7. Description Location validation

Administrative create/updateは、current public mappingとしてcommitする前にDescription Locationをvalidateしなければなりません。

L1ではstored public Description Locationはabsolute HTTPS URIであり、安全にHTTP `Location`へemit可能でなければなりません。

少なくとも次をrejectします。

```text
relative URI
non-HTTPS URI for L1
malformed absolute URI
CR/LF / header injection material
```

URI syntax/header安全性のvalidationはTrust、authorization、reachability、AR-XML validityを意味しません。

Maintenance surfaceはreachability/validation toolを提供しても構いませんが、Resolver Core resolution semanticsとは分離しなければなりません。

## 8. Lifecycle administration

Administrative lifecycle mutationはResolver Lifecycle 0.1に従います。

```text
ACTIVE    → SUSPENDED
SUSPENDED → ACTIVE
ACTIVE    → RETIRED
SUSPENDED → RETIRED
```

Lifecycle 0.1では`RETIRED`はterminalです。

Lifecycle transitionとmaterial history eventは、後続public readから見てatomicにcommitすることを推奨します。

RETIRED recordをsilentにreactivateしてはなりません。

## 9. Maintenance UI minimum capabilities

最初のReference Resolver maintenance surfaceは少なくとも次を提供することを推奨します。

1. UUID registration
2. Description Location update
3. lifecycle/status update
4. search
5. list view
6. record detail
7. history view
8. resolution test

Manifestを有効にする場合、record detailにはCanonical Entity Identityとmaintenanceに必要なoptional Manifest metadataも表示することを推奨します。

Convenience validationを提供しても構いませんが、その結果をTrust、authentication、ownership proof、capability authorizationとして表現してはなりません。

## 10. UUID registration

Registrationではsyntactically validなUUIDを持つ新規Resolver recordを作成しなければなりません。

Reference Resolverはdeployment policyに従ってUUIDを生成またはcaller-supplied UUIDを受理して構いませんが、Resolver Core UUID semanticsを維持しなければなりません。

Registrationはconforming current mapping/lifecycle stateに必要な最小dataを要求することを推奨します。

新規recordは原則ACTIVEとし、deployment policyがpublication前SUSPENDEDを明示する場合は例外とします。

同一UUID valueのduplicate registrationで複数のcurrent recordを曖昧に作成してはなりません。

## 11. Description Location update

Maintenance surfaceはCanonical Entity Identityと独立してcurrent Description Locationを変更できなければなりません。

Description Location変更だけを理由に`entity.id`変更を要求してはなりません。

Commit後のfresh origin resolutionは新current Locationを返さなければなりません。既存intermediary cacheは以前advertiseされたHTTP freshnessに従います。

Material mapping changeについてprevious/new valueをbounded historyへ記録することを推奨します。

## 12. History requirements

Reference Resolverはmaterial administrative changeについてbounded historyを保持することを推奨します。

- lifecycle transition
- Description Location change
- deployment policyが許す場合のCanonical Entity Identity change
- Manifest integrity metadata change
- public Resolver/Manifest behaviorへ重大な影響を与えるconfiguration change

Retained history eventは概念上次を復元できる情報を持つことを推奨します。

```text
event time
record UUID
change type
previous value/state where applicable
new value/state where applicable
administrative actor identifier where available
optional reason/note
```

Historyはadministrative/operational recordであり、cryptographic authenticity、ownership、Trust、L2 authorityを確立しません。

Retentionはcount/age/storage/archive policyでboundedにして構いませんが、policyをdocumentすることを推奨します。

## 13. Search / list / detail

Administrative search/list/detailはmaintenance surface上で動作し、public Resolver semanticsを変更してはなりません。

Searchは最低でもUUIDと、lifecycle state / Description Location textなど運用上有用なcurrent metadataを対象にすることを推奨します。

Admin list/detailはpublic endpointにないinternal maintenance metadataを表示して構いません。

Maintenance UIが表示できるという理由だけでpublic APIへhistory/internal DB metadataを公開してはなりません。

## 14. Resolution test

Maintenance UIは現在recordが生成するexternally observable resultを確認するresolution testを提供することを推奨します。

最低限次を区別します。

```text
Core route result
HTTP status
Location where applicable
cache/CORS-relevant headers
Manifest result where enabled
```

Resolution testはraw DB stateだけで成功判定せず、public behaviorまたは同等application boundaryを試験することを推奨します。

AR-XML reachability checkを提供する場合はResolver resolution successと明確に分離表示しなければなりません。

## 15. Administrative authentication / authorization

Administrative mutation surfaceはdeployment-appropriateなauthentication / authorizationで保護しなければなりません。

Anchor UUIDをmutation authorizationに使用してはなりません。

Baseline L1 public auth ruleはMaintenance UI authenticationを定義しません。

最初のReference Resolverは特定IdP、password scheme、WebAuthn、reverse-proxy auth、external authorization serviceを必須とはしません。ただしdeployment choiceはunauthenticated mutationに対する実効的な保護を提供しなければなりません。

Admin sessionはsecure transport、必要に応じたCSRF protection、session expiry、privilege checkなど標準Web security controlを使用することを推奨します。

## 16. Public/admin privilege separation

Deploymentはpublic request pathのprivilegeを最小化することを推奨します。

可能な範囲で:

- public handlerはmutation capabilityを持たない
- admin mutation codeはauthenticated admin pathからのみ到達可能
- DB/file permissionはleast privilege
- public/admin routingを明確に区別
- admin errorのsensitive implementation detailをpublicへ露出しない

Separate virtual host/process/filesystem permission/reverse proxy rule等を使って構いませんが、特定mechanismはprotocol conformance要件ではありません。

## 17. Input/output security

Public/admin inputはすべてuntrustedとして扱います。

Request size、field length、list/search pagination、generated Manifest sizeにはbounded limitを設定することを推奨します。

Public redirect emissionはresponse splitting/header injectionを防止しなければなりません。

Public Manifest JSONはstrict JSONで生成し、duplicate object-member nameを生成してはなりません。

Admin outputはUI injection防止のためcontext escapeすることを推奨します。

SQLiteアクセス実装ではparameterized query等のsafe data bindingを使用することを推奨します。

これらはReference実装security requirementであり、Resolver protocolの意味を変更しません。

## 18. Logging / privacy

Reference Resolverはtroubleshooting/abuse analysisに必要なoperational logを保持することを推奨しますが、logをprotocol stateとして扱いません。

Logは不要なsensitive dataを最小化すべきです。Query string、IP address、user-agent、administrator identity、submitted URL等にはprivacy impactがあるため、documented retention/access policyに従うことを推奨します。

Public resolution loggingを、Entityがcurrent network addressを定期reportする要件へ変えてはなりません。

## 19. Availability / abuse controls

Request size limit、rate limiting、connection/time limit、bounded admin query等のdeployment-appropriateなabuse controlを持つことを推奨します。

Availability controlでprotocol semanticsをsilentに変更してはなりません。Overload/temp backend unavailable時はfabricated successful mappingではなく適用HTTP failure behaviorを返すべきです。

## 20. Backup / restore / migration

SQLiteにはcurrent mappingとadministrative historyがあるため、deploymentはbackup/restore procedureを定義することを推奨します。

RestoreはUUID identity、lifecycle state、current Description Location、retained history consistencyを維持することを推奨します。

Schema migrationはimplementation concernです。Internal storage変更だけを理由にpublic Resolver semanticsを変更してはなりません。

## 21. Optional publishing/integrity tooling

Maintenance UIはselected AR-XML representationについてManifest `description.integrity`を計算/更新する明示的publishing operationを提供しても構いません。

そのtoolは:

- public resolution request pathと分離する
- Frozen Manifest 0.1 byte semanticsを使用する
- 処理対象representation/locationを明確化することを推奨
- digest successをEntity authentication、ownership proof、freshness、anti-rollback、authorization、L2と表現しない

Public resolutionのたびにDescriptionをfetchしてintegrity metadataを自動更新してはなりません。

## 22. Resolver CoreでAR-XMLを解釈しない

Public Resolver Core pathはUUID解決のためにAR-XMLをparseしてはなりません。

Reference Resolverは次を行ってはなりません。

- resolution中のcapability discovery
- AR-XMLからcapability endpoint構築
- capability invocation
- device management UI生成
- Resolver mapping mechanismとしてcurrent device IP resolution
- Description resolutionの代わりにmanagement console redirect
- periodic device-IP reporting要求

AR-XMLをfetchするoptional admin diagnosticはCore resolution behaviorと明示的に分離しなければなりません。

## 23. Manifest extension / vendor metadata

Reference implementation固有のpublic Manifest metadataはFrozen Extension Policyを使用することを推奨します。

Vendor/deployment metadataが`extensions`に属する場合、ad-hoc top-level memberとして追加しないことを推奨します。

Extensionはstandard `description.location`、lifecycle、integrity、Core resolution、Trust semanticsをoverrideしてはなりません。

Administrative-only metadataはManifestへ公開する必要はありません。

## 24. Configuration responsibilities

Deployment configurationは例えば次を含めて構いません。

```text
public base path
admin base path
SQLite database location
cache defaults within allowed profile
logging policy
rate limits
administrative authentication integration
Manifest enable/disable setting
history retention policy
```

Core-only implementationがunsupported security levelをL1へsilent reinterpretできるconfigurationにしてはなりません。

Secretをrepository defaultへcommitしたりpublic diagnostic outputへ露出してはなりません。

## 25. Implementation handoff

後続実装taskは具体的PHP structure/SQLite schemaを選択できますが、少なくとも次のlogical boundaryを維持することを推奨します。

```text
HTTP/public adapter
HTTP/admin adapter
Core resolution service
Manifest representation service
Maintenance service
Persistence/repository boundary
History/audit boundary
Authentication/authorization adapter
```

Implementation acceptanceではFrozen Resolver / Manifest Conformance Catalog 0.1をprotocol baselineとし、authenticated maintenance behaviorを別testすることを推奨します。

## 26. Non-goals

Reference Resolver Architecture 0.1は次を定義しません。

- L2 authentication protocol
- ownership-transfer protocol
- public-key binding/signature
- capability execution
- device configuration protocol
- dynamic device-IP lookup/reporting architecture
- concrete DB DDL
- concrete PHP framework/class name
- Docker/native packaging detail
- patent/FTOの法的結論

## 27. Summary

```text
Public Resolver
    = minimal GET-only resolution

Optional Manifest Endpoint
    = stored Entity-level metadata representation

Maintenance UI
    = authenticated administrative mutation and inspection

SQLite
    = implementation persistence, not protocol semantics

History
    = bounded administrative trace, not Trust
```

Reference ResolverはRELinkの中心的な分離を維持しなければなりません。

```text
Resolver Core = minimal resolution
Manifest      = richer metadata
Trust         = downstream/later layer
Runtime       = description consumption and execution
AR-XML        = Entity Interface Description
```
