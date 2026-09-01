# RELink Resolver Core 0.1 日本語版

Status: Frozen 2026-09-01  
Version: 0.1  
Scope: L1 public resolution

Freeze policy: Resolver Core 0.1 は 2026-09-01 に Frozen とされました。0.1 内で許容されるのは editorial / non-semantic errata のみです。L1 request semantics、`l` / `p` downgrade behavior、HTTP status / processing order、Description Location validation、HTTPS / network-policy semantics、lifecycle mapping、Manifest independence、public/admin responsibility boundary、Trust/L2 exclusion、Core conformance expectation の変更は、後続 Resolver Core version または別途 versioning された profile で扱います。

> この文書は `resolver-core-0.1.md` の公式日本語版です。仕様上の要件語は英語版と同じ **MUST / SHOULD / MAY** を保持します。解釈に差異がある場合は Frozen 英語版を基準とします。

## 1. 目的

RELink Resolver Core は、永続的な RELink Anchor identifier を Entity の現在の AR-XML Description Location へ対応付ける最小の Web-facing resolution function を定義します。

```text
Anchor UUID
    ↓
Resolver Core
    ↓
Current AR-XML Description Location
```

Resolver Core は意図的に小さく、Entity capability の記述、Trust の確立、ownership authentication、operation execution を行いません。

```text
Resolver Core = minimal resolution
Manifest      = frozen richer Entity-level resolution metadata
Trust         = later security / authority layer
Runtime       = consumer-facing interpretation and execution
```

RELink Manifest 0.1 は 2026-08-31 に別仕様として Frozen されています。Resolver Core 0.1 は Manifest retrieval から独立しており、ACTIVE Anchor は Manifest を必要とせず current AR-XML Description Location へ直接 resolution できなければなりません（MUST）。

## 2. 要件語

本書中の **MUST**, **MUST NOT**, **REQUIRED**, **SHALL**, **SHALL NOT**, **SHOULD**, **SHOULD NOT**, **RECOMMENDED**, **NOT RECOMMENDED**, **MAY**, **OPTIONAL** は、すべて大文字で記載されている場合に限り BCP 14（RFC 2119 / RFC 8174）に従って解釈します。

## 3. 用語

### 3.1 Physical Anchor

Physical Entity に関連付けられた machine-readable または dereference 可能な物理的参照です。QR / NFC を想定できますが、Core は特定 carrier technology に依存しません。

### 3.2 Anchor URL

Physical Anchor に格納される、またはそこから取得される URL です。

```text
https://{domain}/{resolver-service}/{uuid}
```

Anchor URL は Resolver 到達前に ordinary Web redirect / URL shortener を経由しても構いません（MAY）。

### 3.3 Anchor UUID

Resolver lookup key として使用される UUID です。

Anchor UUID は RELink resolution record を識別します。Resolver Core はこれを opaque identifier として扱い、UUID version / bit layout から semantics、timestamp、security property、ownership、device type、network location を導出しません。

Anchor UUID の所持・知識を authentication / authorization とみなしてはなりません（MUST NOT）。Anchor UUID は password、bearer credential、access token、capability token ではありません。

### 3.4 Resolver Resource

Anchor UUID の Resolver request target が示す HTTP resource です。AR-XML documentでもPhysical Entity自体でもありません。

### 3.5 Description Location

Entity の AR-XML description を取得することが期待される現在の HTTPS URL です。Description Location は Anchor UUID を変更せず更新できます。

Resolver が返す Description Location は consumer 観点では **untrusted network input** です。Resolution success は target が safe / authorized であることを意味しません。

### 3.6 Canonical Entity Identity

Entity の location-independent identity です。Frozen Manifest 0.1 では `entity.id` として absolute URI / identifier-only semantics で表現されます。Resolver Core 0.1 は ordinary L1 resolution で Canonical Entity Identity を要求・返却・dereference しません。

Resolver URL または Description Location を Canonical Entity Identity と同一視してはなりません（MUST NOT）。

### 3.7 Runtime / Consumer

RELink resource を dereference し、AR-XML を取得・解釈し、capability を発見し、別 Runtime semantics に従って capability を実行し得る consumer です。

## 4. Core design invariants

Resolver Core 0.1 conforming implementation は次を維持しなければなりません（MUST）。

```text
Entity     ≠ Location
Capability ≠ Interface
Resolution ≠ Authentication
Description ≠ Execution
```

特に:

- Anchor UUID は Description Location が変わっても安定してよい（MAY）。
- Resolver URL を Entity operational endpoint として扱ってはならない（MUST NOT）。
- resolution success を Physical Entity / AR-XML / owner / Runtime の authentication と解釈してはならない（MUST NOT）。
- resolution success により Entity capability を実行してはならない（MUST NOT）。

## 5. L1 Resolution Security Profile と security-level request

Resolver Core 0.1 は **L1 Resolution Security Profile** を定義します。L1 は resolution transport/profile designation であり、Entity trust / safety / ownership / authenticity rating ではありません。

Ordinary Core 0.1 L1 request は `l` と `p` のどちらも含みません。

```text
no l, no p
= L1
```

将来 level は次の形式を利用できます。

```text
https://{domain}/{resolver-service}/{uuid}?l={level}&p={public-parameter}
```

Forward compatibility rule:

- `l` は **requested RELink security level** を示し、achieved / verified level を示さない。
- Core 0.1 が定義するのは no-`l`, no-`p` L1 request のみ。
- unsupported `l` を受け取った Core 0.1-only Resolver は L1 として silent processing してはならない（MUST NOT）。
- unsupported `l` は fail closed しなければならない（MUST）。
- Core 0.1-only Resolver は unsupported `l` に `501 Not Implemented` を返すことが推奨される（SHOULD）。
- `p` は、それを明示的に定義する level の semantics 用に予約する。
- defining `l` なしの `p` を ordinary L1 として処理してはならない（MUST NOT）。
- Core 0.1-only Resolver は supported defining `l` のない `p` に `400 Bad Request` を返すことが推奨される（SHOULD）。
- `p` により unsupported `l` を L1 として受理してはならない（MUST NOT）。
- Core 0.1 client は `l` / `p` を省略することが推奨される（SHOULD）。
- query parameter の存在を stronger security level 達成の証拠と解釈してはならない（MUST NOT）。

Later level は ordinary L1 request の意味を変更しない範囲で追加 query semantics を定義しても構いません（MAY）。

```text
no l, no p            → L1
supported l           → supported level
unsupported l         → fail closed
p without defining l  → fail closed
unsupported l         ↛ L1
p without defining l  ↛ L1
```

## 6. Request target と UUID handling

Canonical request:

```text
GET /{resolver-service}/{uuid}
```

`{resolver-service}` は deployment-defined です。`{uuid}` は RFC 9562 に適合する UUID textual representation でなければなりません（MUST）。

Server は:

- RFC 9562 が許容する upper/lower/mixed-case UUID text を受理する（MUST）。
- hexadecimal letter case に意味を付けず UUID value を比較する（MUST）。
- admin display / generated URL では lowercase を使うことが推奨される（SHOULD）。
- registration policy 上 valid なら特定 UUID version のみを lookup requirement にしてはならない（MUST NOT）。
- UUID version-specific field から Resolver behavior を導出してはならない（MUST NOT）。

Malformed UUID → `400 Bad Request`（MUST）。
Valid but unregistered UUID → `404 Not Found`（MUST）。

Externally visible Anchor UUID を生成する implementation は UUID version の metadata leakage を考慮することが推奨されます（SHOULD）。Reference Resolver は明確な理由がなければ UUIDv4 を default とすることが推奨されます（SHOULD）。

## 7. HTTP method semantics と processing order

### 7.1 GET

`GET` は Core 0.1 唯一の resolution method です。Successful GET は read-only lookup を行い current Description Location へ redirect します。GET は Resolver state を変更してはなりません（MUST NOT）。

### 7.2 Other methods

Public Core resource 上の `POST` / `PUT` / `PATCH` / `DELETE` は未定義です。

Syntactically valid Core route では unsupported-method handling を UUID registration-state lookup より先に行わなければなりません（MUST）。

したがって registered / unknown / ACTIVE / SUSPENDED / RETIRED の違いだけで unsupported method の public result を変えてはなりません（MUST NOT）。

```http
405 Method Not Allowed
Allow: GET
```

を返さなければなりません（MUST）。

Maintenance operation を提供する場合、separate administrative surface で公開しなければならず（MUST）、Core 0.1 の一部ではありません。

## 8. Successful resolution と Location validation

ACTIVE record に対して Resolver は:

```http
303 See Other
Location: https://...
```

を返さなければなりません（MUST）。`Location` は current AR-XML Description Location でなければなりません（MUST）。

Response 時に stored Location を validation しなければなりません（MUST）。Emitted value は:

- syntactically valid absolute URI（MUST）。
- conforming L1 production では `https` scheme（MUST）。
- HTTP field value として安全で header injection を許さない（MUST / MUST NOT）。
- generated management-console URL ではない（MUST NOT）。
- Resolver constructed operational UI ではない（MUST NOT）。
- Resolver-selected capability invocation URL ではない（MUST NOT）。
- ordinary Core resolution 中に dynamically reported current device IP を解決して生成したものではない（MUST NOT）。

ACTIVE record の stored data を conforming Description Location として安全にemitできない場合、その値をemitしてはならず（MUST NOT）、`500 Internal Server Error` を返すことが推奨されます（SHOULD）。

Ordinary resolution success 判定のために Resolver が AR-XML contents を inspect / fetch してはなりません（MUST NOT）。

## 9. `303 See Other` の理由

Description Location は別resourceでmutableであるため permanent redirect ではなく `303 See Other` を使用します。Resolver Resource、Physical Entity、AR-XML document は等価ではありません。

`303` は URI equivalence を主張せず別resourceを示せるため、`Entity ≠ Location` と整合します。

Client は本仕様のconsumer/platform security requirementに従い `303` をfollowしても構いません（MAY）。

## 10. Redirect chain、HTTPS downgrade resistance、trust boundary

Anchor URL が Resolver endpoint を直接示す必要はありません。

```text
Physical Anchor
↓
HTTPS short URL
↓ HTTPS redirect(s)
HTTPS Resolver URL
↓ 303
HTTPS AR-XML Description Location
↓ optional HTTPS redirect(s)
HTTPS final AR-XML URL
```

L1 processing consumer は Anchor→Resolver→Description→final AR-XML の全dereference chainで HTTPS→HTTP downgrade を許可してはなりません（MUST NOT）。Final AR-XML URL は HTTPS でなければなりません（MUST）。Downgrade redirect は dereference failure としなければなりません（MUST）。

Redirect count は Core 固有には固定せず、consumer/environment は bounded redirect、loop detection、ordinary HTTP safety limit を適用することが推奨されます（SHOULD）。

Pre-Resolver redirect infrastructure は Core 0.1 によって intended Resolver として cryptographically authenticated されません。HTTPS は contacted Web origin を ordinary Web PKI により authenticate しますが、shortener/intermediate redirect が intended Resolver を選んだことまでは証明しません。

Final AR-XML document URL は Runtime processing 上重要です。Relative AR-XML Interface endpoint は original Anchor / Resolver URL ではなく final AR-XML document URL をbaseに解決することを想定します。

## 11. Lifecycle states

```text
ACTIVE
SUSPENDED
RETIRED
```

### 11.1 ACTIVE

ACTIVE → `303 See Other`。

### 11.2 SUSPENDED

SUSPENDED → `404 Not Found`。Public L1 は unknown と SUSPENDED を意図的に区別しません。

### 11.3 RETIRED

RETIRED → `410 Gone`。RETIRED は Core 0.1 で terminal です。

### 11.4 State transitions

```text
ACTIVE    → SUSPENDED
SUSPENDED → ACTIVE
ACTIVE    → RETIRED
SUSPENDED → RETIRED
```

RETIRED からの transition は Core 0.1 semantics では行ってはなりません（MUST NOT）。

Lifecycle reason / actor / timestamp / audit history 等は public Core response の外であり、administration または separately versioned metadata specification/profile で扱います。

## 12. HTTP status-code model

| Status | Core meaning |
| --- | --- |
| `303 See Other` | ACTIVE UUID successfully resolved |
| `400 Bad Request` | invalid UUID、malformed Core request、defining levelなしのreserved `p` |
| `404 Not Found` | unknown または SUSPENDED |
| `405 Method Not Allowed` | unsupported public method。registration state と独立に判定 |
| `410 Gone` | RETIRED |
| `500 Internal Server Error` | internal failure / unsafe stored Location |
| `501 Not Implemented` | requested `l` unsupported |
| `503 Service Unavailable` | Resolver/backing service temporary unavailable |

Temporary failure と判明している場合 `503` を優先することが推奨されます（SHOULD）。Ordinary public L1 は anonymous なので `401` / `403` を定義しません。

## 13. Error representation

Consumer は body parsing なしで HTTP semantics から success/failure を判定できなければなりません（MUST）。Error body は OPTIONAL。

Structured details を提供する場合 RFC 9457 Problem Details (`application/problem+json`) が推奨されます（SHOULD）。

Error representation は secret、credential、private admin metadata、private ownership info、internal datastore details を漏らしてはなりません（MUST NOT）。Structured body を successful L1 resolution のdependencyにしてはなりません（MUST NOT）。

## 14. Cache policy

### 14.1 Successful resolution

`303` には explicit cache policy を送らなければなりません（MUST）。Reference default:

```http
Cache-Control: public, max-age=60
```

Freshness lifetime は deployment-configurable です。Rapid revocation / incident response が必要なら shorter max-age / no-store が推奨されます（SHOULD）。

### 14.2 Failure

次は `Cache-Control: no-store` が推奨されます（SHOULD）。

- `400`
- `404`
- `501`
- `500`
- `503`

### 14.3 RETIRED

`410 Gone` は RETIRED terminal のため cache しても構いません（MAY）。Reference default:

```http
Cache-Control: public, max-age=300
```

## 15. CORS policy

Browser-oriented public L1 Resolver は:

```http
Access-Control-Allow-Origin: *
```

を返すことが推奨されます（SHOULD）。L1 Core GET に credentialed CORS を要求しないことが推奨されます（SHOULD NOT）。Core client は ordinary L1 に custom header を要求しないことが推奨されます（SHOULD NOT）。

Resolver CORS は final AR-XML originへのaccessを許可しません。AR-XML origin / redirect path は独立して browser Fetch/CORS requirements を満たす必要があります。

## 16. Consumer / platform network-security policy

Resolver output は untrusted network input です。

Resolver-supplied Description Location / redirect target をdereferenceする前または最中に、consumer は execution environment で利用可能な applicable network-security controls を適用しなければなりません（MUST）。

Application codeが各redirect targetを事前inspectionできることまでは要求しません。

Server/native consumer は HTTP stack が許す場合 network access 前に destination を評価することが推奨されます（SHOULD）。Browser consumer は Fetch redirect、CORS、mixed-content、origin policy その他 browser/platform protections と、利用可能な Runtime policy に依存しても構いません（MAY）。

Resolution success を target が safe / public / non-local / trusted / authenticated / authorized である意味に解釈してはなりません（MUST NOT）。

Policy は environment に応じて scheme、hostname/address range、loopback/local、link-local、metadata endpoint、DNS rebinding、redirect destination、allow/deny list、browser restrictions 等を扱えます。

Core 0.1 は Resolver level で private/local Description Location を一律禁止しません。LAN Entity が正当な場合があるため、access decision は consumer/environment に属します。

```text
Resolver
↓
Description Location
↓
Consumer / Platform Network Policy
↓
Fetch
```

## 17. Transport security と referrer minimization

Conforming L1 production Resolver URL は HTTPS でなければなりません（MUST）。Successful Description Location も HTTPS でなければなりません（MUST）。Consumer は §10 の HTTPS-only chain を維持しなければなりません（MUST）。

Public Resolver は redirect response に:

```http
Referrer-Policy: no-referrer
```

を送ることが推奨されます（SHOULD）。Reference Resolver は default enable が推奨されます（SHOULD）。

HTTPS は contacted originへのtransport securityを提供しますが、Physical Entity、ownership、AR-XML semantics、Anchor attachment、pre-Resolver redirect choice、admin authority を証明しません。

HSTS等のorigin hardeningはDeployment Profileの責務です。

## 18. Resolver / AR-XML responsibility boundary

Core implementation が内部保持してよい最小情報:

```text
Anchor UUID
Lifecycle status
Current AR-XML Description Location
```

および administration metadata。

Core は AR-XML category/profile/capability/input/result/interface、Entity current IP、device control protocol、management UI、capability invocation state を知ることを要求してはなりません（MUST NOT）。

Registered current Description Location を返す前提として AR-XML をfetch/parseしてはなりません（MUST NOT）。これにより ordinary resolution が generic SSRF / amplification path になることも避けます。

## 19. Resolver / Manifest boundary

Manifest は別仕様です。Manifest 0.1 と Extension Policy は 2026-08-31 Frozen。

Core 0.1 deployment は ACTIVE UUID を Manifest なしで直接 AR-XML Description Location に resolve できなければなりません（MUST）。

```text
Resolver Core
↓ 303
AR-XML
```

Richer deployment は separate deterministic Manifest resource を追加しても構いません（MAY）。Manifest availability / retrieval / parsing / validation / integrity verification / failure を ordinary Core L1 prerequisite にしてはなりません（MUST NOT）。

Frozen Manifest が定義する `entity.id`、Description metadata / optional integrity、lifecycle metadata、versioning、extensions を追加 Core response semantics として再実装してはなりません（MUST NOT）。

関連仕様:

- `docs/specs/manifest-0.1.md`
- `docs/specs/manifest-0.1.schema.json`
- `docs/specs/manifest-0.1-extension-policy.md`

## 20. L1 / future L2 boundary

L1:

```text
public
anonymous
read-only
GET
UUID lookup
HTTPS-only dereference chain
303 to current AR-XML Description Location
consumer/platform network-security policy
```

Core 0.1 は Runtime-to-Resolver authentication、stronger Resolver authentication、public-key verification、signature、authenticated update、`PUT`/`PATCH` mutation、ownership/authority transfer、higher-level negotiation、trust-chain validation を later level/specification に残します。

ただし unsupported `l` と defining semantics のない `p` は L1 へ silent downgrade してはなりません（MUST NOT）。Later level は stronger level request を理由に既存 Anchor UUID を再定義してはなりません（MUST NOT）。

## 21. Security non-goals

Core 0.1 は次を提供しません。

- Physical Entity authentication
- Runtime authentication
- owner authentication
- AR-XML authenticity verification
- HTTP/TLS transport以外のAR-XML integrity verification
- pre-Resolver redirect が intended Resolver を選択したことのcryptographic proof
- Resolver trust chain
- device authorization
- capability authorization/execution
- ownership transfer
- local-network discovery
- current device-IP discovery
- device configuration
- management-console construction

Frozen Manifest 0.1 の optional content pinning は Manifest layer feature であり Core responsibility を拡大しません。

Consumer は `303`、Anchor UUID、HTTPS-only path、L1 result を上記のproofとして扱ってはなりません（MUST NOT）。

```text
L1
≠ Entity trust rating
≠ Entity safety rating
≠ authenticity proof
≠ authorization
```

## 22. Prohibited responsibility expansion

Ordinary Core resolution を次のpatternに依存させてはなりません（MUST NOT）。

```text
UUID → dynamically reported device IP → management URL
UUID → generated control UI
UUID → Resolver-selected command endpoint
Resolver → direct capability invocation
periodic device IP registration → required for ordinary identity resolution
```

Unrelated device-management service を運用しても構いません（MAY）が Core semantics 外に分離しなければなりません（MUST）。

## 23. Privacy / logging / information disclosure

L1 は public lookup protocol です。Valid Anchor URL を得た誰でも resolution を試行できる前提で運用しなければなりません（MUST）。

- public L1 Description Location は public disclosureに適することが推奨される（SHOULD）。
- private admin data を public error に含めてはならない（MUST NOT）。
- unknown/SUSPENDED は deliberate shared `404`。
- internal stateを不要に漏らすresponse differenceを避けることが推奨される（SHOULD）。
- Anchor UUIDは logs/CDN/WAF/analytics/proxyでlinkable operational metadataとして扱うことが推奨される（SHOULD）。
- log retention/access/secondary useを最小化することが推奨される（SHOULD）。
- future level query parameter loggingには注意することが推奨される（SHOULD）。

Core 0.1 は ACTIVE / RETIRED identifier existence のconfidentialityを提供しません。

## 24. Operational resilience guidance

Core は ordinary resolution 中に AR-XML/device network access を行わず bounded local resolution work のみを意図します。

Reference implementation は indexed UUID lookup、bounded request/DB timeout、connection limit、rate limit、bounded diagnostic body、malformed request safety 等のordinary controlsを使うことが推奨されます（SHOULD）。

これらはdeployment guidanceであり Core responsibility を拡大しません。

## 25. Reference interactions

成功例:

```http
GET /relink/550e8400-e29b-41d4-a716-446655440000 HTTP/1.1
Host: resolver.example
```

```http
HTTP/1.1 303 See Other
Location: https://entity.example/arxml/550e8400-e29b-41d4-a716-446655440000.xml
Cache-Control: public, max-age=60
Access-Control-Allow-Origin: *
Referrer-Policy: no-referrer
```

Consumer/environment はその後 Description Location dereference に applicable network policy を適用します。

Unsupported level:

```http
GET /relink/550e8400-e29b-41d4-a716-446655440000?l=2&p=example HTTP/1.1
```

```http
HTTP/1.1 501 Not Implemented
Cache-Control: no-store
```

Defining levelなしの `p`:

```http
GET /relink/550e8400-e29b-41d4-a716-446655440000?p=example HTTP/1.1
```

```http
HTTP/1.1 400 Bad Request
Cache-Control: no-store
```

## 26. Conformance

**Resolver Core 0.1 L1 server conformance** をclaimするdeploymentは最低限:

1. RFC 9562 UUID lookup keyを使うHTTPS GET endpointを提供する（MUST）。
2. UUIDをopaque / non-credentialとして扱う（MUST）。
3. ACTIVEにvalidated absolute HTTPS Description Location付き`303`を返す（MUST）。
4. unsupported `l`をL1へdowngradeせずfail closed（MUST）。
5. defining semanticsなしの`p`をordinary L1処理せずfail closed（MUST）。
6. malformed / unknown/SUSPENDED / unsupported method / RETIRED / unsupported level / reserved parameter / server failureのstatus semanticsを保持する（MUST）。
7. unsupported methodをregistration stateより先に処理する（MUST）。
8. public resolutionをread-onlyにする（MUST）。
9. Entity/Location、Resolution/Authentication、Description/Execution separationを保持する（MUST）。
10. ordinary resolutionにManifest、Trust、capability execution、device network discovery、AR-XML fetch/parseを要求しない（MUST）。
11. required explicit cache behaviorを送る（MUST）。

**L1 consumer processing conformance** をclaimするconsumerは最低限:

1. Anchor→Resolver→Description→AR-XML全chainでHTTPS→HTTP downgradeをprevent/reject（MUST）。
2. final AR-XML URLをHTTPSに限定（MUST）。
3. Resolver-supplied location/redirect dereference前または最中にexecution environmentで利用可能なnetwork security controlsを適用（MUST）。
4. successをauthentication/authorization/trust/safety proofと解釈しない（MUST）。
5. applicableなAR-XML relative URL processingでは final AR-XML document URLをbaseとして使う（MUST）。

Browser consumer はplatform内部redirect targetをapplication codeへ公開する必要はありません。Browser/platform enforcement と利用可能な Runtime policy の組合せで network-policy requirement を満たせます。

CORS はbrowser-oriented deploymentでRECOMMENDEDですが、non-browser consumerにはdeployment profileが要求しない限り必須ではありません。

## 27. Related specifications / standards

- RFC 9110 — HTTP Semantics
- RFC 9111 — HTTP Caching
- RFC 9562 — UUID
- RFC 9457 — Problem Details
- Referrer Policy
- Fetch Standard

Related RELink:

- AR-XML Core 0.1
- RELink Manifest 0.1 — Frozen 2026-08-31
- RELink Manifest 0.1 Extension Policy — Frozen 2026-08-31
- RELink Web Runtime Integration Contract 0.1 — Frozen 2026-09-01
- RELink Resolver Lifecycle 0.1 — Frozen 2026-09-01
- RELink Resolver / Manifest Conformance Catalog 0.1 — Frozen 2026-09-01
- RELink Reference Resolver Architecture 0.1 — Frozen 2026-09-01
- RELink Reference Resolver Deployment Profiles 0.1 — Frozen 2026-09-01
- future RELink Trust / higher security-level specifications

## 28. Design summary

```text
Input:
    public HTTPS GET
    /{resolver-service}/{uuid}

Default:
    no l, no p = L1

Unsupported l:
    fail closed
    SHOULD 501

p without defining level:
    fail closed
    SHOULD 400

Identifier:
    RFC 9562 UUID
    opaque
    not a credential

ACTIVE:
    303
    validated current HTTPS AR-XML Description Location

SUSPENDED / unknown:
    404

RETIRED:
    410

Unsupported method:
    405
    before registration-state lookup

Resolver failure:
    500 / 503

Redirect security:
    HTTPS-only
    no HTTPS→HTTP downgrade
    pre-Resolver redirect infrastructure is not authenticated as intended Resolver

Consumer boundary:
    Resolver Location = untrusted network input
    consumer/platform policy governs dereference

Core:
    UUID → current Description Location

Manifest:
    frozen separately
    not prerequisite for Core resolution

Not Core:
    Trust
    authentication
    mutation
    current device IP
    management UI
    capability execution
    AR-XML interpretation
```

この最小境界は意図的なものであり、Frozen Manifest 0.1、future Trust、Runtime integration、Reference Resolver implementation、conformance testing のbaselineです。
