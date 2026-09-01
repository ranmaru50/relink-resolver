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

次の分離を維持しなければなりません。

```text
Public resolution ≠ Maintenance administration
Resolver Core     ≠ Manifest
Manifest          ≠ Trust
Resolution        ≠ Capability execution
Persistence model ≠ Public protocol
Public resolution ≠ Administrative outbound fetch
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
protected transport
        ↓
authentication + authorization
        ↓
Maintenance application boundary
        ↓
Repository / history port
        ↓
SQLite

Optional administrative fetch tooling
        ↓
outbound network policy
        ↓
external resource
```

Public/Adminが同一deployable application内にあっても、route、authorization requirement、privilege、outbound-network capability、responsibilityは分離しなければなりません。

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

Public Core pathはresolutionのためだけにDescription resourceをfetchしてはなりません。

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

SQLite DB、journal/WAL、backup、secret-bearing configuration、administrative export等のprivate persistence artifactは、public/admin document rootから直接Web-addressableであってはなりません。

Deploymentは概念上次の境界を維持しなければなりません。

```text
web-served application files
≠
persistent/private data and secrets
```

具体的filesystem pathはdeployment-definedです。

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

Maintenance surfaceはreachability/validation toolを提供しても構いませんが、Resolver Core resolution semanticsとは分離し、§18のadministrative outbound-fetch requirementsへ従わなければなりません。

## 8. Lifecycle administration

Administrative lifecycle mutationはResolver Lifecycle 0.1に従います。

```text
ACTIVE    → SUSPENDED
SUSPENDED → ACTIVE
ACTIVE    → RETIRED
SUSPENDED → RETIRED
```

Lifecycle 0.1では`RETIRED`はterminalです。

Configured history policyが対応するmaterial history eventを要求する場合、lifecycle transitionとhistory eventは後続readから見てatomicにcommitすることを推奨します。Reference実装では同一persistence transactionを強く推奨します。

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

Administrative GET operationはread-onlyでなければなりません。GET linkのfollowやGET resource loadだけでstate changeを実行してはなりません。

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

Administrative search、list、detail、history、diagnostic、mutation surfaceは、別の適用仕様でpublicと明示されていない限り、deploymentのadministrative access controlを要求しなければなりません。

Maintenance/internal metadataを含むauthenticated admin page/APIは、明示的に正当化された同等policyがない限り、`Cache-Control: no-store`等のnon-cacheable policyを使用することを推奨します。

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

AR-XML reachability checkを提供する場合はResolver resolution successと明確に分離表示し、server-side network accessを行う場合は§18へ従わなければなりません。

## 15. Administrative authentication / authorization / transport

Production deploymentでは、administrative authentication、session establishment、sensitive maintenance dataのauthenticated inspection、mutation requestはHTTPSまたは同等に保護されたdeployment channelを使用しなければなりません。

Administrative mutation surfaceはdeployment-appropriateなauthentication / authorizationで保護しなければなりません。

Anchor UUIDをmutation authorizationに使用してはなりません。

Baseline L1 public auth ruleはMaintenance UI authenticationを定義しません。

最初のReference Resolverは特定IdP、password scheme、WebAuthn、reverse-proxy auth、external authorization serviceを必須とはしません。ただしdeployment choiceはunauthenticated mutationに対する実効的な保護を提供しなければなりません。

Deploymentがdifferentiated privilegeを定義する場合、authentication成功だけを全administrative operationへのauthorizationとして扱ってはなりません。

Browser-session実装では、cookie利用時のSecure/HttpOnly、適切なSameSite、session expiry、authentication後のsession identifier rotation等の標準Web session controlを使用することを推奨します。

Login rate limit等の合理的なlogin abuse controlも推奨します。

## 16. Administrative CSRF / mutation semantics

Cookie等のbrowser ambient credentialへ依存するadministrative mutation endpointは、deploymentに適したCSRF protectionを実装しなければなりません。

具体方式は固定しません。anti-CSRF token、Origin validation、Fetch Metadata validation、適切なSameSite policy、またはこれらの組合せを使用できます。

SameSite cookie属性だけですべてのdeploymentに対するCSRF defenseを代替できると仮定すべきではありません。

Administrative mutationはstate changeを意図したmethod/request flowを使用しなければなりません。GET/HEADはsafe/read-only operationのままとします。

## 17. Public/admin privilege separation / UI output security

Deploymentはpublic request pathのprivilegeを最小化することを推奨します。

可能な範囲で:

- public handlerはmutation capabilityを持たない
- admin mutation codeはauthenticated/authorized admin pathからのみ到達可能
- DB/file permissionはleast privilege
- public/admin routingを明確に区別
- admin errorのsensitive implementation detailをpublicへ露出しない

Separate virtual host/process/filesystem permission/reverse proxy rule等を使って構いませんが、特定mechanismはprotocol conformance要件ではありません。

Public/admin inputはすべてuntrustedとして扱います。

Administrative HTMLへ表示するuntrusted valueは、output contextに応じてescapeまたはsafe encodingしなければなりません。Description Location、`entity.id`、history note、actor identifier、extension metadata等をHTML/attribute/script/URL contextへunescaped active markupとして挿入してはなりません。

Admin実装はuntrusted valueにraw HTML rendering APIを避け、defense in depthとしてrestrictive CSPを検討することを推奨します。

Request size、field length、list/search pagination、generated Manifest sizeにはbounded limitを設定することを推奨します。

Public redirect emissionはresponse splitting/header injectionを防止しなければなりません。

Public Manifest JSONはstrict JSONで生成し、duplicate object-member nameを生成してはなりません。

Untrusted valueを組み込むすべてのdatabase operationは、parameterized queryまたは同等にinjection-safeなdata-binding mechanismを使用しなければなりません。

## 18. Administrative outbound fetch security

Administrative reachability check、AR-XML diagnostic、integrity publishing、その他Reference Resolver serverがsupplied/stored URLをdereferenceする機能は、独立したoutbound-fetch security boundaryです。

```text
Public Resolver resolution
= no Description fetch

Administrative fetch tooling
= explicit privileged operation
+ outbound network policy
```

Administrative outbound destinationおよびredirect targetはすべてuntrusted network inputとして扱わなければなりません。

Execution stackが制御を提供する範囲で、deployment-configured outbound network policyをdereference前またはその最中に適用しなければなりません。Redirect controlが利用可能な場合、redirect targetもpolicyで再評価しなければなりません。

Administrative fetch toolingは少なくとも次のbounded resource controlを持たなければなりません。

- finite redirect limit
- connect / response(read) timeout
- finite maximum response body size
- operationに適したbounded processing time / memory use

Administrative outbound fetchは、ambient cookie、client certificate、その他無関係なcredentialをdefaultで付与しないことを推奨します。Credentialは明示的なdeployment policyまたは将来のauthenticated profileが選択した場合のみ使用できます。

Outbound policyがresolved IP address/address rangeに依存する場合、そのpolicy decisionは実際にconnectionで使用されるnetwork addressへ適用されなければなりません。あるいはDNS rebinding/name-to-address changeでconfigured decisionを迂回できない同等mechanismを実装しなければなりません。

Loopback/private/link-local/cloud metadata/internal service等は、syntactically validであるだけでsafeと仮定せずdeployment policyで制御しなければなりません。

Local/private Entity resourceをprotocol-wideに禁止しません。Deploymentは意図的に許可して構いません。必要なinvariantは次です。

```text
configured outbound network policy
↓
allow / deny destination
↓
actual fetch behavior follows that decision
```

Reachability成功をTrust、Entity authentication、authorization、ownership proof、L2 achievementとして表示してはなりません。

## 19. Logging / privacy

Reference Resolverはtroubleshooting/abuse analysisに必要なoperational logを保持することを推奨しますが、logをprotocol stateとして扱いません。

Logは不要なsensitive dataを最小化すべきです。Query string、IP address、user-agent、administrator identity、submitted URL、outbound-fetch diagnostic等にはprivacy impactがあるため、documented retention/access policyに従うことを推奨します。

Logへ書くuntrusted valueは、embedded newline/control character/delimiterによって追加log entryを偽造したりstructured-log fieldを破壊したりできないよう、structuredまたはencoded formで扱うことを推奨します。

Public resolution loggingを、Entityがcurrent network addressを定期reportする要件へ変えてはなりません。

## 20. Availability / abuse controls

Request size limit、rate limiting、connection/time limit、bounded admin query等のdeployment-appropriateなabuse controlを持つことを推奨します。

Availability controlでprotocol semanticsをsilentに変更してはなりません。Overload/temp backend unavailable時はfabricated successful mappingではなく適用HTTP failure behaviorを返すべきです。

## 21. Backup / restore / migration / private-file placement

SQLiteにはcurrent mappingとadministrative historyがあるため、deploymentはbackup/restore procedureを定義することを推奨します。

RestoreはUUID identity、lifecycle state、current Description Location、retained history consistencyを維持することを推奨します。

Schema migrationはimplementation concernです。Internal storage変更だけを理由にpublic Resolver semanticsを変更してはなりません。

SQLite DB、journal/WAL、backup、migration snapshot、administrative export、secret-bearing configは、public/admin routeからHTTP取得可能なstorageへ置いてはならず、または同等のmechanismで直接HTTP retrievalを防止しなければなりません。

Backup/migration artifactはhistorical/sensitive administrative dataを含み得るため、live DBと同等以上にrestrictiveなaccess controlを持つことを推奨します。

## 22. Optional publishing/integrity tooling

Maintenance UIはselected AR-XML representationについてManifest `description.integrity`を計算/更新する明示的publishing operationを提供しても構いません。

そのtoolは:

- public resolution request pathと分離する
- §18のoutbound-fetch controlへ従う
- Frozen Manifest 0.1 byte semanticsを使用する
- 処理対象representation/locationを明確化することを推奨
- digest successをEntity authentication、ownership proof、freshness、anti-rollback、authorization、L2と表現しない

Integrity publishing operationは、計算したdigestをそのoperationで選択したDescription Locationおよびlogical record stateへbindしなければなりません。

Selection/fetchからcommitまでにcurrent Description Locationが変更された場合、そのdigestをcommitしてはなりません。別のmaterial record version/stateをconcurrency controlに使用する場合も、conflicting changeをcommit前に検出しなければなりません。

概念的には次です。

```text
select Location A + logical version V
↓
fetch A under outbound policy
↓
compute digest
↓
commit only if current Location == A
and applicable version/state still matches V
↓
otherwise conflict / retry
```

Description Location associationとintegrity metadataは、一つのlogically consistent administrative updateとしてcommitすることを推奨します。

Public resolutionのたびにDescriptionをfetchしてintegrity metadataを自動更新してはなりません。

## 23. Resolver CoreでAR-XMLを解釈しない

Public Resolver Core pathはUUID解決のためにAR-XMLをparseしてはなりません。

Reference Resolverは次を行ってはなりません。

- resolution中のcapability discovery
- AR-XMLからcapability endpoint構築
- capability invocation
- device management UI生成
- Resolver mapping mechanismとしてcurrent device IP resolution
- Description resolutionの代わりにmanagement console redirect
- periodic device-IP reporting要求

AR-XMLをfetchするoptional admin diagnosticはCore resolution behaviorと明示的に分離し、§18へ従わなければなりません。

## 24. Manifest extension / vendor metadata

Reference implementation固有のpublic Manifest metadataはFrozen Extension Policyを使用することを推奨します。

Vendor/deployment metadataが`extensions`に属する場合、ad-hoc top-level memberとして追加しないことを推奨します。

Extensionはstandard `description.location`、lifecycle、integrity、Core resolution、Trust semanticsをoverrideしてはなりません。

Administrative-only metadataはManifestへ公開する必要はありません。

## 25. Configuration responsibilities

Deployment configurationは例えば次を含めて構いません。

```text
public base path
admin base path
SQLite database location
outbound network policy
cache defaults within allowed profile
logging policy
rate limits
administrative authentication integration
Manifest enable/disable setting
history retention policy
```

Core-only implementationがunsupported security levelをL1へsilent reinterpretできるconfigurationにしてはなりません。

Secretをrepository defaultへcommitしたり、public diagnostic outputへ露出したり、直接Web-addressable pathへ保存してはなりません。

## 26. Implementation handoff

後続実装は具体的PHP structure / SQLite schemaを選択できますが、少なくとも次のlogical boundaryを維持することを推奨します。

```text
HTTP/public adapter
HTTP/admin adapter
Core resolution service
Manifest representation service
Maintenance service
Administrative outbound-fetch service/policy
Persistence/repository boundary
History/audit boundary
Authentication/authorization adapter
```

Implementation acceptanceはFrozen Resolver / Manifest Conformance Catalog 0.1をprotocol baselineとして含み、authenticated maintenance behaviorを別途testすることを推奨します。

Security acceptanceでは少なくとも次をtest/review対象とすることを推奨します。

```text
admin HTTPS/protected-channel enforcement
admin read-surface access control
CSRF-resistant browser mutation
GET/HEAD administrative read-only behavior
stored-XSS/output-encoding resistance
SQL injection resistance through parameterized/data-bound operations
private SQLite/config/backup non-addressability
outbound-fetch policy and redirect re-evaluation
DNS/address-policy rebinding resistance
bounded outbound fetches
integrity publishing conflict/TOCTOU handling
```

## 27. Non-goals

Reference Resolver Architecture 0.1は次を定義しません。

- L2 authentication protocol
- ownership-transfer protocol
- public-key binding / signature
- capability execution
- device configuration protocol
- dynamic device-IP lookup/reporting architecture
- concrete database DDL
- concrete PHP framework/class name
- Docker/native packaging details
- globally mandatoryなprivate-network denylist
- legal patent/FTO conclusion

## 28. Summary

```text
Public Resolver
    = minimal GET-only resolution
    = no Description fetch

Optional Manifest Endpoint
    = stored Entity-level metadata representation

Maintenance UI
    = protected transport
    + authentication / authorization
    + CSRF-safe mutation
    + context-safe output

Administrative Fetch Tooling
    = separate privileged SSRF-sensitive boundary
    + configured outbound policy
    + address-effective policy enforcement
    + bounded fetch behavior

SQLite / secrets / backups
    = private persistence
    ≠ web-addressable content

History
    = bounded administrative trace, not Trust
```

Reference Resolver実装はRELinkの中心的責務分離を維持しなければなりません。

```text
Resolver Core = minimal resolution
Manifest      = richer metadata
Trust         = downstream/later layer
Runtime       = description consumption and execution
AR-XML        = Entity Interface Description
```
