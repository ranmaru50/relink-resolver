# RELink Resolver Core 0.1 日本語版

Status: Draft specification  
Version: 0.1  
Scope: L1 public resolution  

> この文書は `resolver-core-0.1.md` の日本語版です。仕様上の要件語は英語版と同じ **MUST / SHOULD / MAY** を保持します。解釈に差異がある場合は英語版を基準とします。

## 1. 目的

RELink Resolver Core は、永続的な RELink Anchor identifier を Entity の現在の AR-XML description location に対応付ける、最小の Web-facing resolution function を定義します。

中核となる関係は次のとおりです。

```text
Anchor UUID
    ↓
Resolver Core
    ↓
Current AR-XML Description Location
```

Resolver Core は RELink architecture 全体より意図的に小さく設計されています。Entity capability の記述、Trust の確立、ownership の認証、operation の実行は行いません。

責務分離は次のとおりです。

```text
Resolver Core = minimal resolution
Manifest      = richer metadata
Trust         = later security / authority layer
Runtime       = consumer-facing interpretation and execution
```

## 2. 要件語

本書中の **MUST**, **MUST NOT**, **REQUIRED**, **SHALL**, **SHALL NOT**, **SHOULD**, **SHOULD NOT**, **RECOMMENDED**, **NOT RECOMMENDED**, **MAY**, **OPTIONAL** は、すべて大文字で記載されている場合に限り、BCP 14（RFC 2119 および RFC 8174）に従って解釈します。

## 3. 用語

### 3.1 Physical Anchor

Physical Entity に関連付けられた、machine-readable または dereference 可能な物理的参照です。QR と NFC を主要な Anchor carrier と想定しますが、Resolver Core は特定の carrier technology に依存しません。

### 3.2 Anchor URL

Physical Anchor に格納される、または Physical Anchor から取得される URL です。

典型的な direct Resolver URL は次の形式です。

```text
https://{domain}/{resolver-service}/{uuid}
```

Anchor URL は Resolver に到達する前に、URL shortening service など通常の Web redirect infrastructure を経由しても構いません（MAY）。

### 3.3 Anchor UUID

Resolver lookup key として使用する UUID です。

Anchor UUID は RELink resolution record を識別します。Resolver Core はこれを opaque identifier として扱い、UUID version や bit layout から semantics、timestamp、security property、ownership、device type、network location を導出しません。

### 3.4 Resolver Resource

特定の Anchor UUID に対する Resolver request target が識別する HTTP resource です。

Resolver Resource は AR-XML document でも Physical Entity 自体でもありません。

### 3.5 Description Location

Entity の AR-XML description を取得することが期待される、現在の HTTPS URL です。

Description Location は Anchor UUID を変更せずに変更可能です。

### 3.6 Canonical Entity Identity

Entity の location-independent identity です。具体的な表現形式は Resolver Core 0.1 の normative scope 外であり、Manifest specification で定義する予定です。

Resolver Core 0.1 は Resolver URL または Description Location を Canonical Entity Identity と同一視してはなりません（MUST NOT）。

### 3.7 Runtime

RELink resource を dereference し、AR-XML を取得・解釈し、Capability を発見し、別途定義される Runtime semantics に従って将来的に Capability を実行し得る consumer です。

## 4. Core design invariants

Resolver Core 0.1 conforming implementation は、次の責務分離を維持しなければなりません（MUST）。

```text
Entity     ≠ Location
Capability ≠ Interface
Resolution ≠ Authentication
Description ≠ Execution
```

特に以下を満たします。

- Anchor UUID は Description Location が変更されても安定して維持してよい（MAY）。
- Resolver URL を Entity の operational endpoint として扱ってはならない（MUST NOT）。
- resolution success は Physical Entity、AR-XML document、owner、Runtime のいずれかが authenticated であることを意味してはならない（MUST NOT）。
- resolution success によって Entity capability を実行してはならない（MUST NOT）。

## 5. L1 security-level baseline

Resolver Core 0.1 は L1 public resolution baseline を定義します。

security-level query parameter が存在しない場合、request は L1 request として解釈しなければなりません（MUST）。

```text
no security-level query
= L1
```

将来の RELink level では、例えば次の形式を使用できます。

```text
https://{domain}/{resolver-service}/{uuid}?l={level}&p={public-parameter}
```

forward compatibility のため、以下を固定します。

- `l` は将来仕様で定義される場合、**requested security level** を表し、achieved または verified security level を表しません。
- `p` は Resolver Core 0.1 では意味を持ちません。
- Resolver Core 0.1 client は `l` と `p` を省略することを推奨します（SHOULD）。
- Resolver Core 0.1 は L2 以降の authentication、negotiation、downgrade、failure、mutation semantics を定義しません。
- 未認識 query parameter の存在を、Core 0.1 consumer が security 強度向上の証拠として解釈してはなりません（MUST NOT）。

将来の RELink level を実装する deployment は、security-level query parameter のない L1 request の意味を変更しない限り、追加 query semantics を定義してよい（MAY）。

## 6. Request target

canonical Resolver Core request form は次のとおりです。

```text
GET /{resolver-service}/{uuid}
```

`{resolver-service}` は deployment-defined です。

`{uuid}` は RFC 9562 に適合する UUID textual representation でなければなりません（MUST）。

conforming server は以下を満たします。

- RFC 9562 が許容する uppercase、lowercase、mixed-case hexadecimal UUID text を受理しなければならない（MUST）。
- UUID hexadecimal letter case に意味を付与せず比較しなければならない（MUST）。
- administrative display、log、generated URL、その他 canonicalized output では lowercase UUID text を使用することを推奨する（SHOULD）。
- supplied UUID が implementation の registration policy 上 valid である場合、lookup のために特定の UUID version のみを要求してはならない（MUST NOT）。
- UUID version-specific field から Resolver behavior を導出してはならない（MUST NOT）。

UUID path position に malformed UUID が指定された場合、`400 Bad Request` を返さなければなりません（MUST）。

syntax 上 valid だが未登録の UUID には `404 Not Found` を返さなければなりません（MUST）。

## 7. HTTP method semantics

### 7.1 GET

Resolver Core 0.1 の resolution method は `GET` のみです。

successful GET は read-only lookup を実行し、current Description Location への HTTP redirect を返します。

GET request は Resolver state を変更してはなりません（MUST NOT）。

### 7.2 その他の method

Resolver Core 0.1 は public resolution resource 上の `POST`、`PUT`、`PATCH`、`DELETE` を定義しません。

valid Resolver Core resource に unsupported method を受信した Resolver は次を返さなければなりません（MUST）。

```http
405 Method Not Allowed
Allow: GET
```

Reference Resolver または deployment が authenticated maintenance operation を提供する場合、それらは separate administrative surface で公開しなければならず（MUST）、Resolver Core 0.1 には含まれません。

## 8. Successful resolution

ACTIVE resolution record に対して Resolver は次を返さなければなりません（MUST）。

```http
303 See Other
Location: https://...
```

`Location` field は current AR-XML Description Location を識別しなければなりません（MUST）。

`Location` value は以下を満たします。

- absolute URI でなければならない（MUST）。
- conforming L1 production resolution では `https` scheme を使用しなければならない（MUST）。
- 現在 reachable という理由だけで device current IP address lookup result を指定してはならない（MUST NOT）。
- generated management-console URL であってはならない（MUST NOT）。
- Resolver が構築した operational UI であってはならない（MUST NOT）。
- Resolver が選択した capability invocation URL であってはならない（MUST NOT）。

Resolver は ordinary resolution の成功判定のために AR-XML contents を inspect してはなりません（MUST NOT）。

## 9. `303 See Other` を使用する理由

Resolver Core 0.1 は permanent redirect ではなく `303 See Other` を使用します。Description Location は別 resource であり、Entity lifecycle 中に変更され得るためです。

Resolver Resource、Physical Entity、AR-XML document は同一 resource ではありません。

GET request に対する HTTP の `303 See Other` semantics では、URI equivalence を主張せずに、元 target を記述する別 resource を `Location` target として示せます。これは RELink の次の原則と一致します。

```text
Entity ≠ Location
```

client は通常の HTTP behavior により `303` を自動 follow してよい（MAY）。

## 10. Redirect chain

RELink は Anchor URL が Resolver endpoint を直接識別することを要求しません。

次の経路は valid です。

```text
Physical Anchor
↓
ordinary short URL
↓ 301 / 302 / 303 / 307 / 308 as provided by Web infrastructure
Resolver URL
↓ 303
AR-XML Description Location
```

同様に Description Location は、AR-XML representation が得られる前に通常の Web infrastructure を経由して redirect してもよい（MAY）。

Resolver Core は consumer が follow すべき通常 redirect の回数を protocol 固有には規定しません。consumer は通常の HTTP redirect-loop detection と safety limit を適用することを推奨します（SHOULD）。

AR-XML を実際に取得した final URL は Runtime processing において重要です。relative AR-XML Interface endpoint は original Anchor URL や Resolver URL ではなく、final AR-XML document URL を基準に resolve されることを想定します。

詳細な Runtime integration contract は別仕様で定義します。

## 11. Lifecycle states

Resolver Core 0.1 は次の3つの resolution lifecycle state を定義します。

```text
ACTIVE
SUSPENDED
RETIRED
```

### 11.1 ACTIVE

ACTIVE record は現在 resolution 可能です。

public response:

```text
303 See Other
```

### 11.2 SUSPENDED

SUSPENDED record は Resolver に既知ですが、一時的に public resolution できません。

public response:

```text
404 Not Found
```

public L1 interface は unknown UUID と temporarily suspended UUID を意図的に区別しません。

maintenance interface は内部的にこの区別を保持・公開してよい（MAY）。

### 11.3 RETIRED

RETIRED record は normal RELink resolution から永久に withdrawal されたことが既知の record です。

public response:

```text
410 Gone
```

RETIRED は Resolver Core 0.1 における terminal state です。

### 11.4 State transition

Core 0.1 で許可する state transition は次のとおりです。

```text
ACTIVE    → SUSPENDED
SUSPENDED → ACTIVE
ACTIVE    → RETIRED
SUSPENDED → RETIRED
```

Resolver Core 0.1 semantics では RETIRED から他 state への transition を行ってはなりません（MUST NOT）。

lifecycle reason、administrative actor、timestamp、audit history、richer lifecycle metadata は public Core resolution response には含めません。それらは separate specification に従い administrative storage や Manifest metadata に保持できます。

## 12. HTTP status-code model

Resolver Core 0.1 は外部から観測可能な status の意味を次のように定義します。

| Status | Resolver Core における意味 |
| --- | --- |
| `303 See Other` | ACTIVE UUID を current Description Location へ正常に resolution |
| `400 Bad Request` | Anchor UUID syntax が invalid、または Core request level で malformed |
| `404 Not Found` | UUID が unknown、または既知 record が SUSPENDED |
| `405 Method Not Allowed` | public Resolver Core 0.1 が method をサポートしない |
| `410 Gone` | UUID は既知で permanent RETIRED |
| `500 Internal Server Error` | 予期しない Resolver internal failure |
| `503 Service Unavailable` | Resolver または必要な backing service が一時的に unavailable |

backing datastore unavailable など failure が temporary と判明している場合、server は `503 Service Unavailable` を優先することを推奨します（SHOULD）。

Resolver Core 0.1 は ordinary L1 resolution に `401 Unauthorized` または `403 Forbidden` を定義しません。L1 は public / anonymous だからです。

## 13. Error representation

consumer は response body を parse せず HTTP semantics のみで Resolver Core の success / failure を判定できなければなりません（MUST）。

error response body は OPTIONAL です。

structured error detail を提供する Resolver は RFC 9457 Problem Details (`application/problem+json`) を使用することを推奨します（SHOULD）。

error representation は secret、credential、private administrative metadata、private ownership information、internal datastore detail を露出してはなりません（MUST NOT）。

structured error format は diagnostic metadata であり、successful L1 resolution の dependency になってはなりません（MUST NOT）。

## 14. Cache policy

Resolver mapping は mutable です。そのため cache policy は通常の Web caching を許可しつつ、obsolete Description Location が fresh とみなされる期間を制限する必要があります。

### 14.1 Successful resolution

Resolver は `303` success response に explicit cache policy を送信しなければなりません（MUST）。

Reference Resolver profile は次を default とすることを推奨します（SHOULD）。

```http
Cache-Control: public, max-age=60
```

正確な freshness lifetime は deployment-configurable であり protocol constant ではありません。

deployment は想定する mapping update frequency に整合する freshness lifetime を選択することを推奨します（SHOULD）。

### 14.2 Malformed / unknown / temporary failure

後続 profile が別 policy を明示しない限り、Core 0.1 deployment では次の response に対して以下を使用することを推奨します（SHOULD）。

```http
Cache-Control: no-store
```

対象:

- `400 Bad Request`
- `404 Not Found`
- `500 Internal Server Error`
- `503 Service Unavailable`

`404` の negative caching を避けることで、registration や temporary suspension workflow において、以前の lookup 後すぐに public result が変化するケースを妨げません。

### 14.3 Retired record

Resolver Core 0.1 では retirement が terminal であるため、`410 Gone` response は cache してよい（MAY）。

Reference Resolver profile は heuristic caching に依存せず、finite explicit freshness lifetime を使用することを推奨します（SHOULD）。

initial recommended value は次です。

```http
Cache-Control: public, max-age=300
```

この値は Reference Resolver default であり protocol invariant ではありません。

## 15. CORS policy

Resolver Core 0.1 は browser 上で動作する Web Runtime implementation から利用できるよう設計されています。

browser Fetch access を意図する public Core response では、public L1 Resolver は次を返すことを推奨します（SHOULD）。

```http
Access-Control-Allow-Origin: *
```

L1 resolution は credential を必要とせず、public Resolver は Core GET path に credentialed CORS を要求しないことを推奨します（SHOULD NOT）。

Core 0.1 client は ordinary L1 resolution のために custom request header を要求しないことを推奨します（SHOULD NOT）。

Resolver の CORS permission は final AR-XML resource への access を許可するものではありません。AR-XML origin および relevant redirect path は独立して browser Fetch/CORS requirement を満たす必要があります。

browser navigation と browser Fetch では CORS implications が異なります。Resolver Core は browser security behavior を再定義しません。

## 16. Transport security

conforming L1 production Resolver URL は HTTPS を使用しなければなりません（MUST）。

successful L1 Description Location は HTTPS を使用しなければなりません（MUST）。

HTTPS が提供するのは contacted Web origin までの transport security です。Resolver Core 0.1 は HTTPS 単独で以下を authenticate できるとは主張しません。

- Physical Entity
- Entity ownership
- AR-XML semantic correctness
- Anchor が physical entity に正しく取り付けられていること
- deployment の administrative control を超えた resolution record 更新権限

これらは将来の Trust および authenticated-update specification に属します。

## 17. Resolver と AR-XML の責務境界

Resolver Core が知る必要がある情報は resolution に必要な最小限のみです。

Core implementation は内部に次を保持してよいです。

```text
Anchor UUID
Lifecycle status
Current AR-XML Description Location
```

加えて administration に必要な implementation metadata を保持できます。

Resolver Core は以下の知識を要求してはなりません（MUST NOT）。

- AR-XML category
- AR-XML profiles
- AR-XML capabilities
- AR-XML inputs / results
- AR-XML interfaces
- Entity current IP address
- device control protocol
- device management UI
- capability invocation state

Resolver は registered current Description Location を返す前提として AR-XML を parse してはなりません（MUST NOT）。

## 18. Resolver と Manifest の境界

Manifest は separate specification です。

conforming Resolver Core 0.1 deployment は Manifest を要求せず、ACTIVE UUID を直接 AR-XML Description Location へ resolution できなければなりません（MUST）。

```text
Resolver Core
↓ 303
AR-XML
```

richer deployment は別途定義された discovery mechanism により Manifest を追加公開してよい（MAY）。

Manifest availability、retrieval、parsing、validation、failure は normal Core 0.1 L1 resolution の prerequisite であってはなりません（MUST NOT）。

Canonical Entity Identity representation、richer lifecycle metadata、version information、future security/trust reference は minimal Core redirect response ではなく Manifest layer に属します。

## 19. L1 と将来 L2 の境界

L1 は次です。

```text
public
anonymous
read-only
GET
UUID lookup
HTTPS
303 to current AR-XML Description Location
```

Resolver Core 0.1 は以下を将来 level または別仕様に明示的に委ねます。

- Runtime-to-Resolver authentication
- ordinary HTTPS origin authentication を超える Resolver authentication
- public-key-based verification semantics
- signature
- authenticated update
- `PUT` / `PATCH` mutation protocol
- ownership / authority transfer
- security-level negotiation
- downgrade handling
- trust-chain validation

stronger security level が request されたという理由だけで、later level は既存 Anchor UUID を再定義してはなりません（MUST NOT）。

later level は identity、description、trust、execution を1 operation に統合するのではなく、L1 identity/resolution model を拡張することを推奨します（SHOULD）。

## 20. Security non-goals

Resolver Core 0.1 は以下を提供しません。

- Physical Entity authentication
- Runtime authentication
- owner authentication
- AR-XML authenticity verification
- HTTP/TLS transport mechanism を超える AR-XML integrity verification
- Resolver trust-chain establishment
- device authorization
- capability authorization
- capability execution
- ownership transfer
- local-network discovery
- current device-IP discovery
- device configuration
- management-console construction

consumer は `303 See Other` をこれらの property の証明として扱ってはなりません（MUST NOT）。

## 21. 禁止する責務拡張

RELink の architecture boundary を維持するため、Resolver Core 0.1 implementation は ordinary resolution を以下の pattern に依存させてはなりません（MUST NOT）。

```text
UUID → dynamically reported device IP → management URL
```

```text
UUID → generated control UI
```

```text
UUID → Resolver-selected command endpoint
```

```text
Resolver → direct capability invocation
```

```text
periodic device IP registration → required for ordinary identity resolution
```

deployment が unrelated device-management service を運用すること自体は許可します（MAY）が、それら service は Resolver Core semantics の外部に維持しなければなりません（MUST）。

## 22. Privacy と information disclosure

L1 は public lookup protocol です。deployment は valid Anchor URL を取得した誰もが resolution を試行できると仮定しなければなりません（MUST）。

したがって:

- public L1 resolution が返す Description Location は public disclosure に適したものとすることを推奨します（SHOULD）。
- private administrative data を public error response に埋め込んではなりません（MUST NOT）。
- unknown と SUSPENDED record は意図的に同一の `404` behavior を共有します。
- implementation は internal datastore や administrative state を不必要に露出する response 差異を避けることを推奨します（SHOULD）。

Resolver Core 0.1 は ACTIVE または RETIRED identifier の存在自体に confidentiality を提供しません。

## 23. Reference interaction

successful L1 interaction の例:

```http
GET /relink/550e8400-e29b-41d4-a716-446655440000 HTTP/1.1
Host: resolver.example
```

```http
HTTP/1.1 303 See Other
Location: https://entity.example/arxml/550e8400-e29b-41d4-a716-446655440000.xml
Cache-Control: public, max-age=60
Access-Control-Allow-Origin: *
```

その後 client は通常の HTTP semantics により Description Location を取得できます。

## 24. Conformance

**RELink Resolver Core 0.1 L1 conformance** を主張する deployment は、最低限以下を満たさなければなりません（MUST）。

1. RFC 9562 UUID lookup key を使用する HTTPS GET resolution endpoint を公開する。
2. Resolver semantics 上 UUID を opaque として扱う。
3. ACTIVE record に対し、absolute HTTPS current AR-XML Description Location を伴う `303 See Other` を返す。
4. malformed、unknown/SUSPENDED、unsupported-method、RETIRED、server-failure case に対し本仕様で定義した status semantics を返す。
5. public resolution を read-only に維持する。
6. Entity/Location、Resolution/Authentication、Description/Execution の分離を維持する。
7. ordinary L1 resolution のために Manifest、Trust、capability execution、device-network discovery を要求しない。
8. 本仕様の要求に従い Core response に explicit cache behavior を付与する。

CORS support は browser-oriented public deployment では RECOMMENDED ですが、applicable deployment profile が要求しない限り non-browser Resolver consumer に対して必須ではありません。

## 25. 関連仕様・標準

Resolver Core 0.1 は既存 Web standard を再定義せず利用します。

関連する external standard:

- RFC 9110 — HTTP Semantics
- RFC 9111 — HTTP Caching
- RFC 9562 — Universally Unique IDentifiers (UUIDs)
- RFC 9457 — Problem Details for HTTP APIs
- Fetch Standard — redirects and browser CORS processing

関連 RELink work:

- AR-XML Core 0.1
- RELink Manifest 0.1
- RELink Web Runtime integration
- RELink Resolver Testbed cases
- future RELink Trust / higher security-level specifications

## 26. Design summary

Resolver Core 0.1 は次のように要約できます。

```text
Input:
    public HTTPS GET
    /{resolver-service}/{uuid}

Default level:
    no security-level query = L1

Identifier:
    RFC 9562 UUID
    treated as opaque

ACTIVE:
    303 See Other
    Location = current HTTPS AR-XML Description Location

SUSPENDED / unknown:
    404 Not Found

RETIRED:
    410 Gone

Unsupported method:
    405 Method Not Allowed

Resolver failure:
    500 / 503

Core responsibility:
    UUID → current description location

Not Core responsibility:
    Trust
    authentication
    mutation
    device current IP
    management UI
    capability execution
    AR-XML interpretation
```

この最小境界は意図的なものです。Manifest、Trust、Runtime integration、Reference Resolver implementation、conformance testing はこの baseline の上に構築されることを想定します。
