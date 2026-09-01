# RELink Reference Resolver Deployment Profiles 0.1 日本語版

Status: Frozen 2026-09-01  
Version: 0.1  
Scope: 最初のReference ResolverにおけるNative / Container deployment

> この文書は `reference-resolver-deployment-profiles-0.1.md` の公式日本語版です。解釈に差異がある場合は英語Frozen文書を基準とします。

Freeze policy: 0.1内ではeditorial / non-semantic errataのみ修正できます。Deployment invariant、Native/Container equivalence、persistence/private-file semantics、trusted-proxy handling、administrative outbound-network policy、backup/restore semantics、security boundary、deployment acceptance expectationのsemantic変更は、後続versionまたは別途versioningされたprofileで扱います。

## 1. 目的

本書は、最初のRELink Reference Resolverについて、実装コードを含まないdeployment requirementを定義します。ContainerをRELink protocol要件にせず、2つのofficial profileをsupportします。

```text
Native profile
Apache + PHP + SQLite

Container profile
Docker image + compose deployment + persistent SQLite storage
```

両profileは同一のexternally observable Resolver / Manifest behaviorを維持しなければなりません。Deployment topologyによってResolver Core、Lifecycle、Manifest、Conformance Catalog、Web Runtime integration、Reference Resolver architectureのsemanticsを再定義してはなりません。

## 2. Governing documents

Deploymentは、適用されるRELink仕様とhandoff documentにconformしなければなりません。

- Resolver Core 0.1
- Resolver Lifecycle 0.1
- Frozen Manifest 0.1 / Extension Policy
- Frozen Resolver / Manifest Conformance Catalog 0.1
- Frozen Web Runtime Integration Contract 0.1
- Frozen Reference Resolver Architecture 0.1

Deployment convenienceとprotocol/architecture semanticsが衝突する場合、governing protocol/architecture semanticsを優先します。

## 3. Deployment invariants

```text
Public protocol semantics ≠ deployment topology
SQLite persistence         ≠ container filesystem lifetime
TLS termination            ≠ Resolver trust semantics
Reverse proxy              ≠ Resolver protocol layer
Admin authentication       ≠ public L1 authentication
Containerization           ≠ sufficient security boundary
```

TLS termination、reverse proxy、logging、rate limiting、authenticationを周辺infrastructureへ置いても構いませんが、外部から観測されるRELink behaviorとReference Resolverのsecurity boundaryはconformingでなければなりません。

Container packagingをapplication authentication、authorization、filesystem permission、outbound network policy、secret isolationの代替として扱ってはなりません。

## 4. Native profile

Native profileはOS上に次を導入します。

```text
Apache HTTP Server
PHP runtime
SQLite support
persistent local filesystem
```

Reference Resolver implementationがsupportするPHP versionと、SQLite/database access、JSON、URI/HTTP-safe output、およびOPTIONAL Manifest integrity publishingを使う場合のcryptographic hashingに必要なPHP extensionを提供しなければなりません。

具体的package nameやversion floorはimplementation documentationに属し、protocol requirementにしてはなりません。

Apache routingはpublic Resolver resourceとadministrative resourceを適切なapplication boundaryへ分離し、それぞれのauthorization requirementを維持しなければなりません。

## 5. Container profile

Container profileはOPTIONAL packaging profileです。Conforming distributionは次を提供することを推奨します。

```text
Dockerfile
compose.yaml
.env.example
```

Apache/PHPを1 containerに含めても、内部的に等価なtopologyを使っても構いません。ただしexternally observable behaviorとpublic/admin separationはNative profileと同等でなければなりません。

Docker、Compose、container networking、image registry、volume mechanismはRELink protocol requirementではありません。

Container buildではdependency/version selectionをdocumentし、再現可能性を高めることを推奨します。Unmaintained base imageは避けることを推奨します。

## 6. Behavioral equivalence

同じlogical Resolver state/configurationに対して、Native / Container deploymentは同じprotocol-visible resultを生成可能でなければなりません。

少なくとも次を含みます。

- Resolver status / redirect
- `Location` validation/emission
- lifecycle behavior
- cache header
- CORS
- Manifest representation / lifecycle mapping
- unsupported method / `l` / `p`
- public/admin separation

Container固有proxyやport mappingによってこれらを変えてはなりません。

## 7. Configuration model

両profileはphysical configuration mechanismが異なっても、共通のlogical configuration modelを使うことを推奨します。

```text
public base path
admin base path
SQLite database path
Manifest enable/disable
history retention policy
logging settings
rate limits
trusted proxy configuration
external/public origin information where required
administrative authentication integration
outbound network policy
```

Container profileではenvironment variableを使用して構いません。`.env.example`にはexample/placeholderのみを含め、production secretを含めてはなりません。

Protocol-visible behaviorまたはsecurity policyに影響するconfigurationはdocumentされ、Native / Containerで同等の意味を持つことを推奨します。

## 8. Persistent data

SQLite dataはdurable Resolver stateであり、process/container filesystem lifetimeへ依存してはなりません。

Native deploymentはSQLite DBをpersistent writable locationへ配置し、適切なownership/permissionを設定しなければなりません。

Container deploymentは、container replacement/restart後もmapping/historyが維持されるpersistent storageを使用しなければなりません。

Persistent storage layoutは、configured SQLite modeで必要なdatabase、journal/WAL、shared-memory、その他ancillary fileすべてと互換でなければなりません。SQLite modeがancillary fileを生成する場合、fragileなsingle-file bindよりpersistent data directory等のlayoutを推奨します。

Container image layerをdurable DB storeとして扱ってはなりません。

Authoritative Resolver stateに影響しないtemporary/cache fileはephemeralでも構いません。

## 9. SQLite filesystem requirements

Persistent storageは、implementationが使用するSQLite modeに適したfilesystem semanticsを提供しなければなりません。

SQLite locking/journalingに対してunsupportedまたはoperationally unsafeなstorage arrangementを避けることを推奨します。Network/distributed filesystemはpathとして見えるだけでsafeと仮定してはなりません。

Implementation/deployment documentationでは、operatorが維持すべきSQLite journal/WAL、backup、filesystem、concurrency assumptionを明示することを推奨します。

## 10. TLS / HTTPS / trusted proxy metadata

Public L1 deploymentはResolver Coreの要求どおり外部からHTTPSで利用可能でなければなりません。

TLS terminationは以下のいずれでも構いません。

- Apache直接
- deployment-controlled reverse proxy/load balancer
- その他のHTTPS ingress

TLSがapplication processより前でterminateされる場合、origin/scheme awarenessが必要な処理のため、trusted request contextを維持しなければなりません。

Applicationはclient-supplied forwarding headerをblind trustしてはなりません。

Security decisionまたはexternally visible URL constructionに使うforwarded/proxy metadataは、明示的にconfiguredされたtrusted proxy hopまたは同等のtrusted-ingress mechanismからのみ受理しなければなりません。

Trusted ingressは、untrusted client-supplied forwarding fieldをapplicationへforwardする前にoverwrite/removeすることを推奨します。

Canonical external originがconfiguredされている場合、externally visible administrative/application URLの生成にはarbitraryなclient-supplied `Host`、`Forwarded`、`X-Forwarded-Host`等よりconfigured originを優先することを推奨します。

## 11. HSTS / secure transport

Production public HTTPS deploymentではResolver Core guidanceに整合するHSTSをsupportすることを推奨します。

HSTSはdeployment/browser security mechanismであり、Resolver authentication、Manifest authenticity、Entity authentication、L2を意味しません。

Production admin accessはsecure transportを使用しなければなりません。

## 12. Reverse proxy boundary

Reverse proxyはTLS termination、request limit、rate limiting、compression、access logging、admin access controlを提供して構いません。

Proxyは次を行ってはなりません。

- Resolver status semanticsを別のsuccess semanticsへrewrite
- SUSPENDED/unknownのrequired non-distinctionを破壊
- `303` Description Locationをmanagement/application URLへrewrite
- public/admin route separationをbypass
- L1でexternally visible HTTPS→HTTP downgradeを導入
- internal admin serviceをdefaultでpublic exposure
- untrusted client forwarding metadataでconfigured trusted-proxy boundaryをbypass

Proxy-generated errorは可能な範囲でtemporary/unavailable failure classificationを維持することを推奨します。

## 13. Public / Admin exposure

両profileはpublic surfaceとmaintenance surfaceを分離しなければなりません。

Public Resolver pathはResolver CoreどおりGET-only/read-onlyでなければなりません。

Administrative search、list、detail、history、diagnostic、mutation routeは、別仕様でpublicと明示されていない限りdeploymentのadministrative access controlを要求しなければなりません。

Container profileはdeployment convenienceのためだけにadmin port/routeをNative profileより弱い保護でpublishしてはなりません。

Operatorはapplication authに加え、network restriction、separate virtual host、reverse proxy policy、VPN等を使用して構いません。

Internal/administrative metadataを含むauthenticated maintenance responseは、明示的に正当化された同等policyがない限り `Cache-Control: no-store` 等のnon-cacheable policyを使用することを推奨します。

## 14. File / process permission

Least privilegeを推奨します。

SQLite database、SQLite journal/WAL/shared-memory file、backup、migration snapshot、secret-bearing configuration、administrative export、その他equivalent private persistence artifactは、public/admin HTTP routeから直接取得可能であってはなりません。

Deploymentは概念上次の境界を維持しなければなりません。

```text
web-served application files
≠
persistent SQLite/private data
≠
secret-bearing configuration
```

少なくとも:

- public web processは必要最小限のfilesystem/database permission
- secret/configurationをstatic/public fileとしてserveしてはならない
- admin credential/session materialをpublicly served pathへ保存してはならない

Architectureがread/write privilege分離を許す場合、それを使用することを推奨します。

## 15. Logging

両profileはtroubleshooting、security review、abuse analysisに十分なoperational logを提供することを推奨します。

Containerではstdout/stderrやmounted log storage、NativeではApache/system/application loggingを使用して構いません。

Log destination差異でprotocol semanticsを変更してはなりません。

Retention/access policyをdocumentし、不要なsensitive dataを最小化することを推奨します。Logはauthoritative Resolver stateではありません。

Logへ書くuntrusted valueは、embedded control characterで追加log recordを偽造したりstructured-log fieldを破壊したりできないようstructured/encoded formで扱うことを推奨します。

## 16. Health / diagnostics

RELink public protocol surfaceとは別にhealth/readiness diagnosticsを提供して構いません。

Resolver Core resource、Manifest resource、Entity health semanticsと混同してはなりません。

Sensitive configuration、DB contents、admin identity、secretをhealth endpointへ露出しないことを推奨します。

Minimal liveness/readinessを超える詳細diagnosticはadministrative/internal accessへ制限することを推奨します。

## 17. Backup / Restore

両profileはpersistent Resolver dataのbackup/restore手順をdocumentすることを推奨します。

Backupは、configured journal modeとfilesystem arrangementに適したtransactionally consistent SQLite backup methodで生成しなければなりません。

具体方式はimplementation/deployment-definedであり、SQLite Backup API、SQLite-aware backup tool、safe offline copy、journal/WAL-aware snapshot等を使用できます。

Backupは少なくとも次を維持することを推奨します。

```text
Anchor UUID mappings
current Description Location
lifecycle state
Manifest metadata where enabled
retained history
```

Documented persistence modelに従う限り、container replacement/image upgrade/compose recreationでdurable stateを破壊してはなりません。

Restore時に既存recordへreplacement UUIDを生成せず、semantic identity/lifecycle consistencyを維持しなければなりません。

Restore validationではUUID identity、lifecycle state、current Description Location、Manifest metadata、retained historyのlogical consistencyを確認することを推奨します。

## 18. Upgrade / Migration

Internal storage migrationによってpublic Resolver semanticsを変更してはなりません。

Persistence representationが変わるupgrade前にはrecoverableかつtransactionally consistentなbackupを作成することを推奨します。

Migrationはexplicit/bounded/failure-awareであることを推奨します。Partial migration状態でfabricated/inconsistent successful mappingをsilentにserveしてはなりません。

Native / Container packageは同じlogical data/schema version modelを使い、profile移動でprotocol-level changeを要求しないことを推奨します。

## 19. Native ↔ Container portability

Documented backup/restoreまたはmigrationにより既存datasetをNative / Container間で移動可能にすることを推奨します。

Profile migrationは次を維持しなければなりません。

- UUID identity
- lifecycle state
- current mapping
- Canonical Entity Identity where used
- integrity metadata where used
- retained administrative history（backup policy範囲内）

Packaging変更だけを理由に新しいAnchor identifierを要求してはなりません。

## 20. Secrets

Repository default、`Dockerfile`、`compose.yaml`、`.env.example`へsecretをcommitしてはなりません。

Secret storage mechanismはdeployment-specificです。Container secret、environment injection、protected file、external secret store、Native OS mechanism等を使用して構いません。

Public Anchor UUIDはsecretではありません。

## 21. Network exposure / administrative outbound fetch

Container networkingはimplementation detailです。Internal portとpublic portが異なっても構いません。

意図したpublic interfaceのみをexternal publishすることを推奨します。

SQLiteはnetwork-exposed database serviceを必要としてはなりません。

Administrative outbound-fetch toolingは、Frozen Reference Resolver Architecture 0.1が要求するoutbound-network policyをNative/Container両profileで維持しなければなりません。

Container networking、bridge isolation、namespace separationだけを十分なoutbound-fetch/SSRF policyとして扱ってはなりません。

両profileで:

- configured outbound allow/deny policyは同等の意味を維持しなければならない
- redirect targetも適用policyの対象でなければならない
- resolved addressに基づくpolicyは実際にconnectionで使われるaddressへ適用するか、同等のDNS-rebinding-resistant mechanismを使わなければならない
- host/loopback/private/link-local/cloud-metadata/internal-network destinationへの到達可否はtopologyだけでsafeと仮定せずdeployment policyで制御しなければならない
- administrative outbound fetchへdeployment environment由来のambient credential/cookie/client certificate等を自動付与しないことを推奨する

Containerからhost service/internal infrastructureへ到達可能であることは、そのdestinationをRELink policy上authorizeしません。

Entityにcurrent IPの定期reportを要求したり、ResolverがUUIDをdevice control endpointへmappingしたりするdeployment profileにしてはなりません。

## 22. Resource limits

両profileはrequest size、concurrency、execution time、memory、search/list pagination、rate limitsのoperational controlをsupportすることを推奨します。

Ordinary conforming Resolver/Manifest requestを妨げず、abusive/unbounded inputを制限するよう設定することを推奨します。

Container resource limitを追加しても構いませんがprotocol semanticsにはしません。

Administrative outbound-fetch operationはFrozen Reference Resolver Architecture 0.1が要求するbounded redirect、timeout、response-size、processing limitを維持しなければなりません。

## 23. Time / Clock

Administrative history timestamp、log、cache behavior、diagnosticsの一貫性のため、deployment clockはreasonableに同期されることを推奨します。

Clock syncはTrust、freshness proof、anti-rollback、L2を意味しません。

## 24. Distribution artifacts handoff

Container profileの後続implementation taskでは次を作成することを推奨します。

```text
Dockerfile
compose.yaml
.env.example
persistent-volume configuration/documentation
startup/upgrade documentation
```

Native profileでは次を推奨します。

```text
Apache configuration example
dependency/package requirements
filesystem layout guidance
permissions guidance
startup/upgrade documentation
```

これらartifactは本profileを実装するものであり、protocol behaviorを再定義してはなりません。

## 25. Deployment acceptance

Implementation validationでは技術的に適用可能な範囲で、Native / Containerの両profileへFrozen Resolver / Manifest Conformance Catalog 0.1を適用することを推奨します。

同一protocol-facing test caseは両profileでpassすることを推奨します。

Deployment-specific acceptanceでは少なくとも次を確認します。

```text
persistent state survives restart/redeployment
SQLite ancillary files remain compatible with persistent storage layout
admin surface remains protected
public/admin routes remain distinct
private DB/config/backup/export artifacts are not retrievable via HTTP
HTTPS/proxy configuration preserves L1 semantics
untrusted forwarded headers cannot spoof the trusted-proxy boundary
outbound fetch policy is equivalent across native/container profiles
redirect/DNS changes cannot bypass configured outbound policy
backup is transactionally consistent for the configured SQLite journal mode
backup/restore preserves UUID/state/Manifest metadata/history
profile migration preserves logical Resolver state
```

## 26. Non-goals

Deployment Profiles 0.1は次を定義しません。

- Docker必須化
- Kubernetes/orchestrator-specific architecture
- 特定Linux distribution
- exact package/version pinning
- 特定TLS CA
- 特定reverse proxy製品
- L2/Trust authentication
- device control / capability execution
- database DDL
- production implementation code
- globally mandatoryなprivate-network denylist
- patent/FTO clearance

## 27. Summary

```text
Native
Apache + PHP + SQLite

Container
Docker-packaged Reference Resolver + persistent SQLite data

Both
same protocol semantics
same logical configuration responsibilities
same durable Resolver state
same public/admin separation
same private-file boundary
same trusted-proxy boundary
same outbound network-policy semantics
same SQLite-consistent backup requirement
```

Deploymentが変更するのはpackaging/operationsであり、RELink identity/resolution semanticsではありません。