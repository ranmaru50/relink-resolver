# RELink Resolver L2 Trust and Authority Model 0.1 日本語版 — Draft

Status: Draft (not frozen) — Issue #28 work product
Version: 0.1-draft
Scope: L2 trust / authority layer above frozen Resolver Core 0.1

> この文書は `resolver-l2-0.1-draft.md` の公式日本語訳（草案段階）です。仕様上の要件語は英語版と同じ **MUST / SHOULD / MAY** を保持します。解釈に差異がある場合は英語版を基準とします。

英文が proposed normative source です。この draft が安定した時点で日本語規範翻訳を整備します。本 draft は Frozen specification として扱ってはなりません（MUST NOT）。実装の conformance 主張が本 draft を引用してはなりません（MUST NOT）。

Freeze policy（ prospective ）: この層が Frozen される場合、意味論の変更は Resolver Core 0.1 と同じパターンに従い、後続の L2 version または別途 versioning された profile を必要とします。

## 1. 目的

本 draft は Issue #28 に従い、Frozen な Resolver Core 0.1 L1 モデルの上に構築される、別 versioning の Trust / Authority レイヤーとして RELink Resolver L2 を定義します。

```text
L1
= resolution

L2
= trust / authenticity / authority / freshness over resolution information
```

L2 は、ある Anchor UUID について以下の問いに答えます。

```text
Who asserted this resolution information?
Was the signed statement modified?
Is the signer authorized for this Entity?
Is the statement sufficiently current?
Has authority been transferred or revoked?
```

L2 は Frozen な Resolver Core 0.1 L1 semantics を再定義・弱化・順序変更・置き換えしてはなりません（MUST NOT）。

```text
Anchor UUID
  ↓
Resolver
  ├─ L1 resolution
  │    → 303 → AR-XML
  │
  └─ L2 trust material
       → signed resolution statement
       → authority / freshness / key information
```

## 2. 要件語

本書中の **MUST**, **MUST NOT**, **REQUIRED**, **SHALL**, **SHALL NOT**, **SHOULD**, **SHOULD NOT**, **RECOMMENDED**, **NOT RECOMMENDED**, **MAY**, **OPTIONAL** は、すべて大文字で記載されている場合に限り BCP 14（RFC 2119 / RFC 8174）に従って解釈します。

## 3. 用語

### 3.1 L1 / Resolver Core

Frozen な Resolver Core 0.1 の public resolution：`l` と `p` を含まない `GET /{resolver-service}/{uuid}` が validated な HTTPS Description Location へ `303` を返す。L1 は定義により public・anonymous・read-only・untrusted network input である。

### 3.2 L2 Request

本仕様が定義する `l=2` を含む Resolver リクエスト。`l` は requested RELink security level であり achieved level ではない（Resolver Core §5 から変更なし）。

### 3.3 L2 Trust Statement

ひとつの Anchor UUID に対する resolution information を主張する、署名付き・canonical・machine-readable な JSON object。§7 を参照。

### 3.4 Entity Authority

ある Anchor UUID に対する cryptographic な authority binding：ある authority epoch において、その Anchor に対する L2 statement を発行する権限を持つ署名鍵の集合。

### 3.5 Authority Epoch

ひとつの authority binding が支配する generation の連続区間。transfer は新たな epoch を生成する。現 epoch より前に発行された statement は current として受理してはならない（MUST NOT）。

### 3.6 Trust Anchor Binding

ある Anchor UUID（および任意で Canonical Entity Identity）を初期 authority に紐付ける deployment-policy record。L2 自身ではなく registration / bootstrap チャネルにより確立される。§9.3 を参照。

### 3.7 Verifier

L2 trust material を取得し、§15 の L2 verification procedure を適用して deterministic な verification state を生成する consumer。

### 3.8 Generation

authority epoch 内の各 L2 statement に結び付けられる、anti-rollback 順序付けに使用される単調増加整数。§11 を参照。

## 4. 概念的区別

L2 は以下の概念を明確に区別しなければならない（MUST）。ひとつの概念を満たす機構を、別の概念を満たすものとして surface してはならない（MUST NOT）。

```text
Integrity          ≠ Authenticity
Authenticity       ≠ Authority
Authority          ≠ Freshness
Freshness          ≠ Ownership
Ownership          ≠ Authorization
Authorization      ≠ Resolution
```

特に以下の点が重要である：

- Manifest 0.1 の `description.integrity` は content pinning であり、L2 の authenticity・authority・freshness・revocation・anti-rollback・ownership・authorization ではない。
- `entity.id` は identifier data であり、authority material ではない。
- Anchor UUID は credential ではない。Anchor UUID の知識は L2 authority を付与しない。
- Administrative authentication（「who may mutate resolver records」）は L2 authority（「who is authoritative for an Entity」）ではない。§16 を参照。
- HTTPS origin の制御そのものは Entity authority ではない。Web PKI origin authentication は transport を認証するのであり、Entity の署名権限ではない。

Baseline L2 guarantees：

- G1 resolution statement の authenticity（現 authority による署名）
- G2 署名済み resolution metadata の integrity（署名対象 payload 内）
- G3 Anchor に対する signer の authority
- G4 freshness / expiry
- G5 anti-rollback / anti-stale 挙動
- G6 authority transfer semantics
- G7 signing authority の revocation / retirement
- G8 deterministic verification state

Optional extensions（non-baseline）：delegation chains、cross-anchor delegation、profile attestation、外部サービスによる timestamp、transparency logs。Extensions は baseline L2 conformance に必須であってはならない（MUST NOT）。

## 5. L1 boundary と design principles

L2 は以下をしてはならない（MUST NOT）：

1. L1 UUID lookup semantics を変更する；
2. L1 lifecycle mapping（`ACTIVE`→303、`SUSPENDED`→404、`RETIRED`→410）を変更する；
3. unsupported な requested `l` を silent に L1 として処理する（Core の変更不能なルール）；
4. L2 request を silent に L1 へ downgrade する；
5. すべての L1 consumer に trust verification を強制する；
6. Resolver に L2 response 構築のために AR-XML を fetch・parse・validate させる；
7. Capability の discovery・authorization・execution を Resolver へ移す；
8. Description Location semantics・cache・CORS・privacy・error boundary を再定義する；
9. L2 statement issuance の前提として Manifest retrieval を要求する、または L2 resolution の前提とする；
10. frozen Core の SHOULD レベルのルール（unsupported `l` の応答コード、`p` の応答コード、`no-store` cache guidance）を Core-only deployment に対する MUST ルールへ変更する。

L2 は追加的（additive）かつ独立に観測可能でなければならない（MUST）：

```text
L1 absent of L2     → unchanged Core 0.1 behavior
L2 present          → additional signed trust material
L2 verification     → consumer-side decision, never a Resolver transport side effect
```

L2 verification に失敗した statement は public L1 response を変化させてはならない（MUST NOT）。L2 verification の成功も public L1 response を変化させてはならない（MUST NOT）。Consumer は L2 results を resolution に係る metadata として扱い、L1 HTTP status の代替としてはならない（MUST NOT）。

`l=2` を受理した Core 0.1 のみ実装 Resolver は、リクエストを L1 として処理してはならず（MUST NOT）、L2 として受理してはならず（MUST NOT）、L3 として受理してはならず（MUST NOT）、L1 降格を適用してはならない（MUST NOT）。リクエストは unsupported level として、Frozen Resolver Core 0.1 の §5 および §14.2 が定めるとおりに扱われ、SHOULD レベルの `501 Not Implemented` と `Cache-Control: no-store` guidance を含む。L2 の要件は L2 を support する実装を束縛する。L2 は frozen Core の SHOULD ルールを Core-only deployment に対する MUST ルールへ変換してはならない（MUST NOT）。

## 6. L2 request と resource shape

### 6.1 L2 statement request

```text
GET /{resolver-service}/{uuid}?l=2
```

L2 を support する Resolver と ACTIVE record に対して：

```http
200 OK
Content-Type: application/json
Cache-Control: <bounded by remaining validity, §14.2>
```

conforming な L2 Trust Statement とともに返される。

Public status behavior は Resolver Core と Lifecycle 0.1 に準拠する：

| State | L2 endpoint behavior |
| --- | --- |
| ACTIVE | `200` + L2 Trust Statement |
| SUSPENDED | `404 Not Found`（unknown/SUSPENDED の非区別を維持） |
| RETIRED | `410 Gone` |
| unknown | `404 Not Found` |
| malformed UUID | `400 Bad Request` |
| unsupported method | registration lookup より前に `405 Method Not Allowed` |
| internal failure | `500` / `503` |

SUSPENDED と RETIRED の L2 statement は public path で提供してはならない（MUST NOT）。public Manifest surface と同一の privacy 理由による。

各行は支配する Resolver Core 0.1 / Lifecycle 0.1 rule の規範的強度を継承する。L2 0.1 は Core-only deployment に対する MUST レベルの status 要件を追加しない。上記の固定コードは L2 を support する実装のみを束縛する。

### 6.2 `p` parameter

Baseline L2 は必須の `p` semantics を定義しない。`p` を定義する `l` を含まずに受理した Core 0.1 のみ実装 Resolver は、リクエストを L1 として処理してはならず（MUST NOT）、L2 として受理してはならず（MUST NOT）、L3 として受理してはならず（MUST NOT）、Frozen Resolver Core 0.1 §5 が定めるとおりに扱われ、SHOULD レベルの `400 Bad Request` guidance を含む。L2 を support する実装では、L2 が定義しない semantics で `p` を含む `l=2` リクエストは record lookup より前に `400 Bad Request` を返さなければならない（MUST）。この固定応答コードは L2 を support する実装のみを束縛する。`p` は将来、L2 profile identifier・nonce・challenge binding を運ぶ可能性がある（OPEN、§21）。

### 6.3 Authority material request

```text
GET /{resolver-service}/{uuid}/authority
```

ACTIVE record に対しては `200` + baseline 0.1 response form。status mapping は §6.1 と同一。Authority Record は署名済み L2 object（`statementType = "authority"`）であり、必須メンバー・wire format・受理順序・chain rules・anti-rollback rules は §§9.5–9.8 に定義される。初期 Authority Record は自己署名できる（MAY）。Trust Anchor Binding（§9.3）により受理される。

Baseline 0.1 response form: response body は strict JSON object

```text
{ "records": [ Record_0, Record_1, ..., Record_G ] }
```

`recordGeneration` の厳格昇順、各要素は §9.5 の署名済み `{ payload, signature }` Record。Array framing は unsigned transport であり、Record は自己完結の署名済み object のまま保たれるため、任意の transport（array、paging、policy channel）でも signing target は保持される。Verifier は Record `G` を受理する前にすべての Record `0..G` を持有しなければならない（MUST）；chain 内の欠落 Record は chain gap であり、result は `UNVERIFIED`（§9.6）。contiguous かつ厳格昇順でない array は authority material として受理してはならない（MUST NOT）。

### 6.4 Non-mutation rule

Public L2 endpoints は GET のみ・read-only・anonymous である。L2 は public な `PUT`/`PATCH` mutation、認証付き public mutation、Resolver 側 trust enforcement を導入してはならない（MUST NOT）。認証付き statement 提出は administrative surface に留まる（§16）。

### 6.5 Forward compatibility

`2` 以外の `l` values はここで定義されない。Deployment は unsupported な `l` を L2・L1・L3 として受理してはならない（MUST NOT）。Consumer は `l=2` を含むリクエストというだけで L2 achievement を主張してはならない（MUST NOT）；achievement は verifier の result であり request ではない。

## 7. L2 Trust Statement

### 7.1 構造

L2 Trust Statement は、top-level メンバーが 2 つの strict JSON object である：

```text
payload    – signed content, canonical JSON object
signature  – base64url encoding of the signature over the canonical payload octets
```

Signing target は §7.3 で定義される。`signature` メンバーは signing target に含まれない。`payload` 内に `signature` を持つ statement は受理してはならない（MUST NOT）（`INVALID`）。

Statement のどこかに duplicate object-member names が存在する場合、Frozen Manifest 0.1 の duplicate-member 規則に従い `INVALID` として拒否しなければならない（MUST）。

### 7.2 Required payload members

```text
l2Version          = "0.1"
statementType      = "resolution"     (baseline; "transfer", "revocation", "rotation" are follow-ups)
anchorId           = RFC 9562 UUID, lowercase
entityId           = absolute URI (Canonical Entity Identity; Manifest 0.1 vocabulary)
descriptionLocation = absolute HTTPS URI (the asserted current Description Location)
lifecycleStatus    = "active" | "suspended" | "retired" (statement is issued only for
                     ACTIVE on the public path; the value MUST equal the record's state)
keyId              = key identifier of the signing key (§10.1)
algorithm          = signing algorithm identifier (§10.8)
issuedAt           = non-negative integer, Unix epoch seconds
expiresAt          = non-negative integer, Unix epoch seconds, > issuedAt
generation         = non-negative integer, monotonic within an authority epoch
epochId            = stable epoch identifier (§9.5, §12.2); MUST equal the verifier's
                     current accepted epoch at evaluation (§11.3, §12.3)
```

`l2Version` は文字列 `"0.1"` でなければならない（MUST）。Verifier は他の `l2Version` values を L2 0.1 と解釈してはならない（MUST NOT）。unsupported な `l2Version` は fail closed でなければならない（ignorable extension ではない）。

Optional payload members（baseline）：REQUIRED なものはなく、追加メンバーはすべて §7.5 に従って扱われる。

`transfer` payload（§12.2）と Authority Record payload（§9.5）は、そこに定義されるとおり `recordGeneration`、`epochId` / `newEpochId`、`statementFloor` を追加する。Statement の `generation` 系列と Record の `recordGeneration` 系列は別系列であり、互いに比較してはならない（MUST NOT）。§7.3 の canonicalization・signing rules は Authority Record と transfer statement を含むすべての statement type に適用される。

### 7.3 Canonical serialization と signing bytes

Signing target は、独立した実装が同一 bytes を計算するよう、一意に確定しなければならない（MUST）。

Canonical serialization は RFC 8785（JSON Canonicalization Scheme, JCS）を parse した `payload` object に適用したものである。Signing target はその JCS serialization の UTF-8 encoding。JCS は object member order（member names の UTF-16 code-unit order、RFC 8785 §3.2.3 に準拠：name は UTF-16 code-unit sequence であり、非 BMP name は surrogate pair として sort される。scalar code-point 値によるではない）、string escaping、number serialization を固定する。§7.2 の列挙は display order であり serialization order ではない。Appendix A.4 は、scalar code-point sorting では順序が異なって決まる非 BMP keys を持つ vector によりこの規則を固定する。

Wire canonicality：

1. wire に提示される `payload` 自体が JCS serialization でなければならない（MUST）；
2. Verifier は raw wire text において parse 前に duplicate object-member names を検出しなければならず（MUST）、wire text の非 canonicality（member order・whitespace・number form・escaping）に対し statement を `INVALID` として拒否しなければならない（MUST）；
3. wire が canonical であるため、`1` と `1.0` が同時に現れることはできない。JCS は canonical な number form を出力し、integer-valued fields は wire integers として現れなければならない。

Integer fields（`issuedAt`・`expiresAt`・`generation`・`recordGeneration`・`effectiveGeneration`・`revokedFrom`）は、JCS canonical form の非負整数であり、IEEE-754 double の safe-integer range（`0`〜`2^53 - 1`）内でなければならない。Fractional・exponent form・non-integer・negative・range 外の値は `INVALID` を生成しなければならない（MUST）。

`payload` 内の nested objects（unknown extension members を含む）は JCS に従い sorted keys で canonicalize され、extension bytes は再現可能である。`keyId`・`algorithm`・UUID・`l2Version`・`statementType`・string values は exact strings であり、`keyId` の大文字小文字は保持される（§10.1）。

Signing target は XML canonicalization、transport framing、content coding、consumer の JSON parser の癖に依存してはならない（MUST NOT）。Test vectors は Appendix A にある。

### 7.4 Statement-to-L1 consistency

Statement の `descriptionLocation` は authority による現 Description Location の主張である。観測された L1 `303` response と L2 を比較する verifier は、標準的な HTTP field-value 抽出の後の、観測された `Location` field value と statement の `descriptionLocation` の exact・case-sensitive・byte-level の一致を要求しなければならない（MUST）。不一致は `MISMATCH` を生成しなければならない（§15）。Baseline はこの exact rule を超える URI normalization を適用してはならない（MUST NOT）（normalization rules は OPEN、§21）。

一致の成功は、署名された主張と観測された L1 response の整合チェックである。それ自体は authority・authenticity・freshness ではない。

### 7.5 Extensions

- `payload` 内の unknown members は §7.3 により JCS で canonicalize され、signature に覆われる。受理しなければならず（MUST）、定義済みメンバーの semantics を override してはならず（MUST NOT）、verifier により security-critical evidence として扱われてはならない（MUST NOT）。
- `payload` の外（statement object 内など）の extensions は署名されない。verifier は trust 判断においてこれらを無視しなければならない（MUST）。
- Required 0.1 member の意味を変える L2 extension は互換ではなく、後続の L2 version を必要とする。

### 7.6 Manifest independence

L2 Trust Statement は Manifest endpoint なし・Manifest retrieval なしの deployment で発行・検証できなければならない（MUST）。Manifest-association semantics（Manifest を参照する `statementType` extension、content pinning reference）は OPEN（§21）である。Manifest 0.1 は ordinary L1 運用および L2 baseline に対して optional のままである。L2 profile は Manifest support を明示的に要求できるが、その profile は別途 versioning される。

## 8. Identity と authority model

### 8.1 Roles

```text
Anchor UUID              → resolution record key (not identity, not credential)
Canonical Entity Identity→ what Entity is this (stable, location-independent)
Entity Authority         → who may sign for this Anchor UUID
Signer                   → the key that signed a particular statement
```

関係は Anchor UUID に anchor される：L2 0.1 の authority は Anchor UUID ごとに binding される。Statement が運ぶ Canonical Entity Identity は、その Anchor の deployment registration record と一致しなければならない（MUST）。`entityId` が trusted な registration / authority material と一致しない statement に対して verifier は `UNKNOWN_AUTHORITY` を生成しなければならない（§15、§9.4）。

### 8.2 Authority binding rules

1. ある Anchor UUID に current な authority binding は高々 1 つである。
2. Authority binding は、key identifiers、その有効性 window、現 epoch の受理済み最小 generation からなる。
3. Authority は以下によってのみ確立される：
   - Trust Anchor Binding（bootstrap、§9.3）；
   - 現 authority が署名した key rotation（§10.3）；
   - outgoing authority が署名した authority transfer（§12）。
4. Authority は以下から導出してはならない（MUST NOT）：
   - Anchor UUID の所持；
   - Resolver HTTPS origin の制御；
   - Web PKI origin authentication；
   - Resolver における administrative login；
   - Manifest の存在、`entity.id` content、`description.integrity`；
   - DNS・hosting・CDN・redirect infrastructure。
5. Authority は L2 baseline では direct である。Delegated・chained・profile-defined authority は baseline から明示的に除外される（§4、§18、§21）。

### 8.3 Conflicting authority

ある Anchor UUID の trust material が、時系列に重複する 2 つの current authorities を、同一 binding としてではなく主張する場合、verifier は `CONFLICT` を生成し、恣意的に一方を選んで受理してはならない（MUST NOT）。Fail-closed。Conflicting bindings は first-seen・last-seen・shortest-key・strongest-algorithm・recency・origin preference では解決できない。

## 9. Trust anchors と trust material distribution

### 9.1 Verifier trust inputs

Verifier は最低以下を持たなければならない（MUST）：

```text
trust anchor bindings      (per Anchor UUID, from a policy channel)
current authority material (Authority Record, rotations, transfers)
statement-under-verification
observed L1 resolution result (for §7.4 consistency, when L2 is verified against L1)
```

### 9.2 Trust material sources

Authority material は以下から取得できる（MAY）：

1. L2 endpoint（§6.3）；
2. Trust Anchor Binding を確立した policy channel；
3. deployment 固有の out-of-band distribution（provisioning、device pairing、factory issuance、operator-published trust list）。

Test 対象 statement と同一 origin から取得された trust material は独立した authority source ではない：Authority Record は、その provenance が Trust Anchor Binding に辿る受理済み authority binding において current な鍵により検証される場合にのみ受理される。まだ受理されていない鍵の下で検証される Authority Record は、自身の authority を bootstrap してはならない（MUST NOT）。

### 9.3 Bootstrap

ある Anchor の初期 authority は、deployment の registration / administrative チャネルにより registration 時に確立される：Trust Anchor Binding は初期 epoch について、`anchorId`、初期 algorithm identifier、§10.8 の algorithm-specific encoding による初期 public key bytes、§10.1 に従い計算された `keyId`、初期 `epochId`、初期 `statementFloor` を記録する。Binding は full-length の public key material を含まなければならない（MUST）；`keyId` 単独は key material ではない。初期 Authority Record は自己署名しうる（MAY）。Verifier は bootstrap を自身の policy チャネル経由でのみ受理する。Bootstrap は authority binding を確立するのであり、Entity authentication・physical device の ownership・AR-XML validity ではない。

Trust Anchor Binding の provenance・format・provisioning mechanism は deployment profile の関心事であり、L2 wire semantics ではない（OPEN、§21）。

### 9.4 Trust material consistency

Verifier は受理済み binding と矛盾する authority material を拒否しなければならない（MUST）。受理済み binding に存在しない鍵による statement には `UNKNOWN_AUTHORITY`、矛盾する bindings には §8.3 に従い `CONFLICT`、authority material が利用不能（欠落・取得不能・§11.4 で fresh でない）の場合は `UNVERIFIED` を生成しなければならない（MUST）。

Verifier は、Record entry を key material として受理する前に、その entry の `publicKey` bytes から各 `keyId` を再計算しなければならない（MUST）（§10.1）。再計算された keyId が記載された keyId と等しくない entry を含む Record は、authority material として受理してはならず（MUST NOT）、その material の下で署名された statement は chain phase で `UNKNOWN_AUTHORITY` と解決する（§15.2）。

### 9.5 Authority Record format

Authority Record は署名済み L2 object であり、§7.1 に従い `{ payload, signature }`、required payload members は以下の通り：

```text
l2Version          = "0.1"
statementType      = "authority"
anchorId
entityId           (MUST match the record's Canonical Entity Identity)
recordGeneration   = non-negative integer, strictly increasing per Anchor
signerKeyId        = key that signed this Record
algorithm
issuedAt
expiresAt
epochId            = the epoch this Record establishes. Record 0 carries the Trust Anchor
                     Binding's initial epochId (§9.3); a transfer-introduced Record G
                     carries the transfer's newEpochId (§12.2)
prevRecordDigest   = lowercase hex SHA-256 of the canonical wire bytes of Record G-1
                     (§7.3 target); null for Record 0
statementFloor     = statement generation floor established by this epoch; required on
                     epoch-establishing Records (baseline 0)
keys               = array of key entries
```

各 key entry：

```text
keyId
publicKey          = base64url (RFC 4648 §5, '=' stripped) of the algorithm-specific
                     public key encoding (§10.8); full-length key material on the wire
algorithm
validFrom          (Unix seconds)
validUntil         (Unix seconds; retired keys use an explicit past validUntil)
status             = "current" | "retired" | "revoked" | "compromised"
revokedFrom        = generation (required for status "revoked" and "compromised")
```

Record payload は §7.3 に従い、wire canonicality を含めて canonicalize および署名する。Records は authority endpoint（§6.3）で serve され、policy チャネル（§9.2）経由で配布してもよい（MAY）。Record G の鍵集合は `recordGeneration = G` における受理済み鍵群の完全な現行スナップショットである。`recordGeneration = G+1..` のいずれかの Record に署名しうるすべての鍵は、その `publicKey` bytes とともに Record `G` に存在しなければならず（MUST）、これにより Record `G` のみを保持する Verifier も wire material だけで Record `G+1` の署名を検証できる。`prevRecordDigest` は Record `G` を Record `G-1` に byte-for-byte で束ね（bind）、chain walk は各ステップでこの binding を検証する。Revocation・retirement・compromise・rotation の状態変化は successive Records により表現される。

### 9.6 Chain acceptance

Verifier は Trust Anchor Binding（§9.3）からの chain walk により Records を受理しなければならない（MUST）：

1. `recordGeneration = 0` の Record は Trust Anchor Binding（`anchorId`、initial `keyId`、初期 public key bytes、初期 `epochId`、初期 `statementFloor`、initial epoch start）と一致しなければならず（MUST）、binding の初期 public key material の下で検証されなければならない（MUST）；
2. `recordGeneration = G > 0` の Record は次の条件でのみ受理しなければならない（MUST）：Records `0..G-1` が受理済みであり Record G が次の連続 generation であること；Record G の `prevRecordDigest` が Record `G-1` の canonical wire bytes の SHA-256 に等しいこと（§9.5）；Record G は自身の `signerKeyId` の下で、Record `G-1` に存在するその鍵の `publicKey` bytes を用いて検証されること；`signerKeyId` が Record `G-1` に `status = "current"` かつ `validFrom <= issuedAt(G) <= validUntil` で存在すること；Record G が導入する各鍵は rotation により（Record `G-1` で `current` な鍵により署名される、§10.3）または `recordGeneration >= effectiveGeneration` で有効な検証済み transfer（§12）により承認されていること；
3. 新鍵の導入を自己署名する Record（signer が Record `G-1` で `current` でない）は受理してはならない（MUST NOT）：これは key-substitution defense であり、Record boundary において適用されるのであって、歴史的な鍵集合に対してではない；
4. chain walk で検証不能な Record は authority material として受理してはならない（MUST NOT）。結果は §15.2 の順序に従い、`UNVERIFIED`（material 未提示）または `UNKNOWN_AUTHORITY`（signer が受理済み chain の外）；
5. L2 0.1 acceptance 条件：初期 Trust Anchor Binding のみを保持する Verifier は、rotation・transfer・revocation の後で、署名済み Record chain だけを用いて現行署名鍵に到達できなければならない（MUST）。到達できないものは L2 0.1 に失敗する。この acceptance criteria は catalog-owned の follow-up work である（§20、§21）。

### 9.7 Authority-material anti-rollback と equivocation

- Verifier は Anchor ごとの最高受理 `recordGeneration` を追跡しなければならない（MUST）；
- 受理済み maximum 未満の Record は受理してはならない（MUST NOT）（authority rollback 試行；§15.2 に従い `STALE`）；
- 受理済み maximum で再提示される Record は、§7.3 canonicalization の後で受理済み Record と byte-identical でなければならない（MUST）；同一 `recordGeneration` で異なる payload は `CONFLICT` を生成しなければならない（MUST）（equivocation、§17）；
- Verifier は同一 `recordGeneration` で異なる payload を持つ 2 つの Record を受理してはならず（MUST NOT）、一方を選択してはならない（MUST NOT）。

### 9.8 State loss

Verifier の state 喪失（受理済み Records、chain position、generation maxima）の後、Verifier は policy チャネル（§9.2）経由で自身の Trust Anchor Binding から chain を walk し、authority material を再確立しなければならない（MUST）。chain provenance なしに first contact で受信した authority material は信頼してはならない（MUST NOT）。信頼済み lower bound を持たない Verifier は first-contact material から `VERIFIED` を主張してはならない（MUST NOT）。validity window 内の古いデータは「古い」と識別できないからである。

## 10. Key lifecycle

### 10.1 Key identifiers

`keyId` は public key の canonical encoding の SHA-256 digest の先頭 8 bytes の standard base64url（RFC 4648 §5）でなければならない（MUST）。`=` padding は除去する。Base64url は case-sensitive であり、`A`-`Z` と `a`-`z` は異なる文字、8 bytes はちょうど 11 characters を生成する（6 bytes は 8 characters に、残り 2 bytes は 1 つの `=` pad を含む 3 characters にエンコードされ、pad を除去する）。Verifier は `keyId` を exact・case-sensitive な string equality で比較しなければならない（MUST）。Public key encoding は algorithm ごとに §10.8 で定義され、wire に提示されるすべての key entry はその encoding を `publicKey` として運ぶ（§9.5）。Verifier は entry を受理する前に、提示された `publicKey` bytes から `keyId` を再計算しなければならない（MUST）（§9.4 の fail-closed 規則）。Verifier は `keyId` を opaque lookup key として扱い、authorization claim として扱ってはならない（MUST NOT）。

### 10.2 Baseline algorithms

Baseline L2 verifier は EdDSA Ed25519（algorithm identifier `ed25519`）を support しなければならない（MUST）。ES256（`ES256`、NIST P-256）は第 2 supported algorithm として RECOMMENDED である。

Baseline L2 deployment は baseline において proprietary cryptographic schemes・licensed products・external trust services・specialized hardware・patent-dependent mechanisms を要求してはならない（MUST NOT）。

### 10.3 Rotation

Key rotation は現 authority による署名行為である。Baseline モデルは Authority Record により rotation を表現する：新 key の validity window は、署名時点で current な鍵により署名された Record により確立される。Authority Record は新 key 単独では構築できなければならない（MUST NOT）。Record chain acceptance（§9.6）が、どの Record が window を確立するかを統治する。

Rotation window は overlap すべきである（SHOULD）。Rotation は authority binding の epoch を変更してはならない（MUST NOT）；generation は rotation を跨いで単調に増加し続ける。

### 10.4 Overlapping rotation windows

Authority Record は key ごとに以下を定義すべきである（SHOULD）：

```text
validFrom      (Unix seconds)
validUntil     (Unix seconds; retired keys use an explicit past validUntil)
```

Statement は、`issuedAt` を含む validity window を持つ鍵により署名された場合にのみ受理される（§15）。

### 10.5 Retired keys

Retired key の `validUntil` は明示的で past である。Retired key により署名された statement は current として受理してはならない（MUST NOT）；statement がそうで構造妥当な場合、retirement が既知なら `UNVERIFIED`（`VERIFIED` ではない）。Retirement は compromise ではない。

### 10.6 Revoked keys

Natural `validUntil` より前に revoke された key は、revocation の effective generation 以降 non-current として扱わなければならない（MUST）。Revoked key により署名された statement は、fresh な revocation evidence が利用可能な場合 `REVOKED` を生成しなければならない（§13）。Revoked-key material は rotation authority として受理してはならない（MUST NOT）。

### 10.7 No single permanent key

Baseline は deployment ごとに単一の永久鍵を hard-coding することでは満たされない（MUST NOT）。Deployment は短命 signing keys と rotation、bootstrap 用の offline root/attestation key、文書化された rotation practice を使用すべきである（SHOULD）。鍵保管の運用は deployment の関心事である。

### 10.8 Algorithm agility

`algorithm` は登録済み identifier string である。Known baseline identifiers：

```text
ed25519  – EdDSA over Ed25519; public key encoding: raw 32-byte public key,
           signature 64 bytes
ES256    – ECDSA over NIST P-256 with SHA-256; JOSE JWS "ES256" signature encoding
```

Unknown・unsupported な `algorithm` を受理した verifier は検証を試みてはならず（MUST NOT）、`UNSUPPORTED_ALGORITHM` を生成しなければならない（MUST）（fail-closed であり、`VERIFIED` でも `UNVERIFIED` でもない）。Algorithms の追加は後続の L2 version または別途 versioning された profile を必要とする。

## 11. Freshness と anti-rollback

### 11.1 Statement validity window

Verifier の受理済み現在時刻 `T` が `issuedAt <= T <= expiresAt` を満たすとき、statement は validity window 内である。

### 11.2 Verifier clock

Verifier は文書化された maximum accepted clock skew tolerance を維持しなければならない（MUST）。信頼できる clock を持たない verifier は `VERIFIED` を主張してはならない（MUST NOT）；freshness components を評価できず、結果は `UNVERIFIED` でなければならない（MUST）。

Statement の `expiresAt - issuedAt` は deployment・cache・revocation・incident-response 要件に適し bounded であるべきである（SHOULD）。Revocation の即時性が重要な場合、より短い validity が RECOMMENDED である。

### 11.3 Anti-rollback

Verifier は Anchor UUID と `epochId` ごとに、current として受理した最高 statement generation を tracking しなければならない（MUST）。Epoch の statement floor は、その epoch を確立する Record が確立した `statementFloor` であり（§9.5、§12.2）、baseline floor は `0` である。Statement generations は epoch ごとの系列であり、Record の `recordGeneration` と比較できない（§7.2）。

`epochId` が verifier の現受理 epoch でない statement は current として受理してはならない（MUST NOT）：`STALE`（cross-epoch replay）。現 epoch 内では、`generation` が受理済み最大より小さい statement は `STALE` を生成しなければならない（MUST）。受理済み最大と等しい statement は freshness progress として再受理してはならない（MUST NOT）；同一受理済み statement の replay は validity window 内でのみ許可され、それを覆う revocation の後は受理してはならない（MUST NOT）。

Epoch 内の generation numbers は successive accepted statements に対して strictly increasing でなければならない（MUST）。受理済み statement と同一の `epochId` と `generation` を持ち、payload が異なる statement は `CONFLICT` を生成しなければならない（MUST）（statement equivocation、§9.7 の mirror）。

### 11.4 Authority material freshness と revocation coverage

Authority material はそれ自身の validity window を持つ。Expired authority material は current authority として受理してはならない（MUST NOT）。Retrieval failure・expired authority material・chain-walk gap・coverage gap は `UNVERIFIED` を生成しなければならず（MUST）、`VERIFIED` ではない。

時刻 `T` における `VERIFIED` は、verifier の revocation snapshot に対する point-in-time judgment である：chain walk で検証された最高受理済み Record（§9.6）であり、その key entries は `status` と `revokedFrom` を運ぶ。評価時刻 `T` において、snapshot は最高受理済み Record でなければならず（MUST）、deployment の文書化された bounded freshness upper bound 内で受理済みでなければならず（MUST）、statement の署名鍵・statement の `epochId`・受理済み chain を覆わなければならない（MUST）、`T` で fresh でなければならない（MUST）。Freshness upper bound より古い snapshot、missing snapshot、incomplete chain、coverage gap は `UNVERIFIED` を生成しなければならず（MUST）、`VERIFIED` は決して生成しない。

`T` における `VERIFIED` は `T` より後の revocation について何も主張しない：後 occurrence の revocation は継続的に保証されず、`VERIFIED` を future currency の保証として提示してはならず（MUST NOT）、consumer は任意の `T' > T` で currency を再評価しなければならない（MUST）（§13.5）。Baseline coverage は key-status based である：`revokedFrom` を持つ `revoked` および `compromised` key entries（§13.4）；per-statement revocation は baseline の保証ではない（§13.1、§21 OPEN）。Statement validity windows は statement を束縛し、snapshot freshness upper bounds は revocation knowledge を束縛するのであり、statement expiry を束縛しない。Revocation publication latency は statement validity windows と snapshot freshness upper bounds とに同等オーダーであるべきである（SHOULD）（§11.6、§21）。

### 11.5 Offline verification

Offline verifier（network・trusted-clock service なし）は signature・key binding・structure・§7.4 consistency・recorded authority material を検証できる（MAY）。Trusted clock がなく、local trusted time が文書化された trusted time source に基づるときのみ freshness を評価できる；そうでない場合結果は `UNVERIFIED` でなければならず、freshness verdict として surface してはならない（MUST NOT）。Fresh な revocation snapshot（§11.4）がない場合 `REVOKED` を決定できず、`UNVERIFIED` を upgrade してはならない（MUST NOT）。Online verification の nonce/challenge mechanism は follow-up である（§21）。

### 11.6 Clock skew と time integrity

Deployment は skew tolerance・cache lifetime・revocation latency が同等オーダーになるよう validity windows を制限すべきである（SHOULD）。`issuedAt` は signer の主張であり third-party timestamp ではない。Third-party timestamping は non-baseline extension である。

## 12. Ownership / authority transfer

### 12.1 Transfer は明示的な authority 遷移

Transfer は署名済み transfer statement により表現されなければならず（MUST）、Description Location の暗黙的な administrative update、Authority Record に鍵が現れること、lifecycle change により表現されてはならない（MUST NOT）。

### 12.2 Transfer statement（follow-up resource; model はここで固定）

```text
statementType       = "transfer"
anchorId
entityId            (MUST match the record's Canonical Entity Identity)
outgoingKeyId
incomingKeyId
recordGeneration    (chain position per §9.6; strictly above the last accepted Record)
effectiveGeneration (recordGeneration + 1: the first Record position signed by the
                     incoming key)
newEpochId          (stable identifier of the new epoch, carried by Record G, §9.5)
issuedAt
expiresAt
```

Transfer statement は outgoing authority key により署名されなければならない（MUST）。Bootstrap から最初の transfer は適用されない；初期 binding は Trust Anchor Binding に由来する。

`outgoingKeyId` が verifier の受理済み current authority でない transfer statement は `UNKNOWN_AUTHORITY` を生成しなければならない（MUST）。Fresh でない transfer statement は epoch を生成してはならない（MUST NOT）。Verifier は unsigned・自己 attest・`incomingKeyId` 署名・administrative 署名・origin 署名・DNS 署名・CDN 署名の transfer 主張を受理してはならない（MUST NOT）。

Transfer は authority Record chain に固定順序で束縛されなければならない（MUST）：

1. transfer statement は `recordGeneration = G`、最高受理済み Record `G-1` より厳密に大きい位置で提示され（MUST）、outgoing key（Record `G-1` で `current`）により署名され、`newEpochId` が設定されていなければならない（MUST）；
2. verifier は transfer statement を outgoing key の下で検証する；
3. Record `G` は outgoing key により署名されなければならず（MUST）、検証済み transfer により導入されなければならず（MUST）、`newEpochId` と新 epoch の `statementFloor`（baseline `0`）を运ばなければならない（MUST）；incoming key を `current` として、`issuedAt(G)` を含む window で導入する；
4. verifier は Record `G` を epoch switch として atomic に受理しなければならない（MUST）：outgoing key は Record `G` の受理以降 non-current、incoming key は Record `G + 1` 以降の署名に対して current となる；
5. incoming key により署名された最初の Record は `recordGeneration = G + 1` でなければならず（MUST）、§9.6 に従って受理されなければならない（MUST）；
6. 受理済み最大未満の transfer と Records は authority rollback である（§9.7）。

Signer state は直前に受理された Record（Record `G` に対しては `G-1`）に対してだけ判定されなければならず（MUST）、より早い Record に対して判定してはならない（MUST NOT）。

### 12.3 Epoch semantics

Record `G` での epoch switch の後、すべての statement は Record `G` が確立した `epochId` を运ばなければならない（MUST）（§7.2、§9.5）。prior epoch の `epochId` を运ぶ statement は current として受理してはならない（MUST NOT）（`STALE`、§11.3）。新 epoch 内では、statement generations は Record `G` が確立した `statementFloor` から開始し（§11.3）、strictly increasing でなければならない（MUST）。Outgoing authority は新 epoch で署名してはならない（MUST NOT）；incoming authority は Record `G + 1` より前に署名してはならない（MUST NOT）。

### 12.4 Transfer は 0.1 で不可逆

Transfer は silent に取り消されてはならない（MUST NOT）。現鍵による transfer 移動方向の新しい transfer は §12.2 に従い許可される。`RETIRED` record の revival と RETIRED Anchor の再 binding は存在しない（Lifecycle 0.1 terminality）。

### 12.5 Transfer と lifecycle

Transfer は lifecycle state を変化させてはならない（MUST NOT）。Lifecycle transitions は Lifecycle 0.1 に従い、L2 transfer は suspension・retirement・reactivation・physical Entity の ownership・capability authorization・administrative rights と解釈されてはならない（MUST NOT）。

```text
Lifecycle state  ≠ Authority binding
Ownership        ≠ Capability authorization
```

### 12.6 Transfer race conditions

同一 outgoing key からの競合する複数 transfer は fail-closed で解決されなければならない（MUST）：verifier はひとつの Anchor に対して複数の current epochs を受理してはならない（MUST NOT）（§8.3、`CONFLICT`）。Submission-time concurrency control は administrative surface の deployment の関心事である。

## 13. Revocation

### 13.1 Revocable objects

```text
key            (baseline: a key's validity window before natural expiry, via the Record
                chain, §13.4)
statement      (extension, non-baseline: per-statement revocation is a follow-up surface;
                baseline coverage is key-status based, §11.4, §13.4, §21 OPEN)
authority binding (revocation of the current authority in favor of a successor;
                   expressed as transfer, not as revocation)
```

### 13.2 Revoking authority

Revocation は現 authority（revocable object を所有する鍵）、または transfer 後の受理済み successor epoch により署名されなければならない（MUST）。Revoked key は revoke できない。受理済み binding に存在しない鍵は revoke できない。Administrative actor の login は L2 revocation authority ではない（§16）。

### 13.3 Revocation discovery

Revocation evidence は authority material（§9.2）、利用可能な場合 revocation material resources（follow-up、§21）から発見される。Revocation evidence はそれ自身が fresh で、受理済み authority の下で検証可能でなければならない（MUST）（§11.4）。

### 13.4 Revocation effects

Fresh で検証済み key revocation：key の window は revocation の effective generation で終了する；その key 下で generation ≥ effective generation の statement は `REVOKED`；未満の statement は §11.3 に従う。Per-statement revocation は baseline の保証ではない（§13.1）：baseline coverage は key-status based である（§11.4）。定義される場合、statement revocation extension は、verifier の次の受理済み評価において revoked statements の verifier caches を無効化しなければならない（MUST）（§21 OPEN）。

Revocation・retirement・compromise は Record chain operations として表現される（§9.5）：署名鍵は Record `G-1` の下で `current` であり、Record `G` は target key を `revokedFrom` を伴う `status = "revoked"` または `"compromised"` とマークする。

受理済み chain で `compromised` とマークされた鍵は、いかなる signature・いかなる generation・いかなる時点においても、verifier により current として受理されてはならない（MUST NOT）。Compromise の後で古い `generation`/`issuedAt` values を再署名した compromised key は、verifier が current として受理してはならない signature を生成する：独立に信頼された evidence なしで、verifier は compromised key からの signature を current と認識してはならない（MUST NOT）（§17、leak-replay）。Fresh な coverage の下で評価された compromised key による signature は、`REVOKED` として surface されなければならず（MUST）、`VERIFIED` として surface してはならない（MUST NOT）。

### 13.5 Fail-closed revocation availability

Baseline は `VERIFIED` のために fresh な revocation snapshot を要求する（§11.4）。Revocation chain state が利用不能な場合、snapshot が stale・incomplete・最高受理済み Record でない場合、chain walk を完了できない場合、結果は `UNVERIFIED`（L2-VERIFIED 主張に対して fail-closed）。Revoked-but-cached statements は current として surface してはならない（MUST NOT）。Revoked material が HTTP cache に存在することは、RFC 9111 cache rules に従い HTTP-reusable のままとしてもよい（MAY）（§14.2）；HTTP reusability は separate judgment であり、L2 verifier の currency decision（§§11, 13）は cache freshness から導出されてはならず（MUST NOT）、revoked material を current として受理してはならない（MUST NOT）。

### 13.6 Offline revocation

Offline verifier は locally retained な fresh revocation evidence なしで statements を `REVOKED` と判定してはならない（MUST NOT）；revocation coverage を評価できない場合 `UNVERIFIED` が要求される結果である。Offline verification は revocation-safe と表現してはならない（MUST NOT）。

## 14. HTTP・cache・privacy behavior for L2 material

### 14.1 Public surface

L2 public material は anonymous・read-only・GET-only・HTTPS-only であり、L1 と同一の redirect downgrade rule を持つ：L2 material dereference chain における HTTPS-to-HTTP downgrade は dereference を失敗させなければならず（MUST）、downgrade してはならない（MUST NOT）。CORS guidance は Resolver Core に従う。

### 14.2 Cache policy for L2 statements

`200` の L2 statement response は statement の残存有効期限を決して越えない explicit cache policy を送らなければならない（MUST）：

```text
Cache-Control: public, max-age = min(deployment bound, expiresAt - now)
```

`400`/`404`/`405`/`500`/`501`/`503` は `Cache-Control: no-store` が推奨される（SHOULD）。`410 Gone` は retirement が terminal であるため Core 0.1 と同じくキャッシュされうる（MAY）。

These cache rules は stored L2 responses の HTTP reusability を統治する（RFC 9111）。Normal cache は RFC 9111 rules に上記 explicit TTL を加えて適用し、freshness window の間、revocation・rotation・transfer・epoch transition の automatic knowledge を持たない；HTTP caches は revocation 検出を要求されてはならず（MUST NOT）、revocation と epoch-transition events は HTTP cache behavior として model してはならない（MUST NOT）。既知 revocation・transfer・epoch transition による L2 trust results の invalidation は L2 verifier 側で定義される（§13.4、§13.5）：verifier は known-revoked・known-superseded・prior-epoch material を current として surface してはならず（MUST NOT）、次の受理済み評価で再評価しなければならない（MUST）。Immediate な L2 freshness を要求する issuers は、short `max-age`・`no-store`・revalidation directives を選択しなければならず（MUST）、その選択を記録しなければならない（MUST）。L2 verifier の currency decision（§§11, 13）は separate judgment であり、HTTP-reusable response は reuse により L2-current となるものではない。

### 14.3 Privacy

L2 requests の Anchor UUID は L1 と同じ注意で loggable operational metadata として扱われなければならず（MUST）、`l`/`p` query parameters の最小化扱いを含む。L2 trust material は policy を越える admin surfaces に cache されてはならない（MUST NOT）。Revocation・transfer material は privacy-sensitivity を持つ；deployment は log retention を最小化すべきである（SHOULD）。

### 14.4 Referrer policy

Resolver Core と同じ：redirect responses に `Referrer-Policy: no-referrer` が送られるべきである（SHOULD）。L2 responses は Core と同じ referrer-minimization guidance を追従すべきである（SHOULD）。

## 15. Verification result model

Verifier は statement 評価に対し exactly one deterministic primary state を生成しなければならない（MUST）。Verifier は structural/signature failure を、trust information の欠落と、freshness failure と区別できなければならない（MUST）。

```text
VERIFIED
    canonical parse, signature under accepted current key within window, accepted
    authority binding, freshness valid, fresh revocation snapshot at T covering key,
    epoch, and chain (§11.4), statement epochId match (§11.3, §12.3), §7.4 consistency
    satisfied in `l1-bound` mode (§15.1)
UNVERIFIED
    verification incomplete: missing trust material, unavailable authority, missing
    trusted clock, revocation snapshot missing, stale, incomplete, or not the highest
    accepted Record, offline limitation
INVALID
    structural failure: strict-JSON violation, duplicate members, l2Version violation,
    non-canonical payload, signature failure, payload/signature separation violation,
    keyId/publicKey recomputation mismatch (§9.4, §10.1)
EXPIRED
    structurally valid statement with T outside [issuedAt, expiresAt]
REVOKED
    fresh revocation snapshot marks the signing key compromised, or revoked with
    `revokedFrom` ≤ evaluation scope (§11.4, §13.4)
STALE
    statement generation below accepted maximum / statement floor, prior-epoch `epochId`,
    accepted rollback attempt
UNKNOWN_AUTHORITY
    signer key not in accepted authority binding, entityId mismatch, unsigned transfer
    attempt, outgoing key not current
UNSUPPORTED_ALGORITHM
    algorithm identifier unknown/unsupported by the verifier
CONFLICT
    contradictory current authority bindings for the same Anchor with overlapping validity
MISMATCH
    statement's descriptionLocation does not match the observed L1 Location (§7.4)
ANCHOR_MISMATCH
    statement's anchorId does not equal the verifier's expected anchorId (§15.1)
```

状態の意味：

- **VERIFIED**：canonical parse、受理済み window 内 current key による署名、受理済み authority binding、freshness 妥当、key・epoch・chain を覆す `T` での fresh な revocation snapshot（§11.4）、statement の `epochId` match（§11.3、§12.3）、`l1-bound` mode での §7.4 consistency（§15.1）がすべて成立。
- **UNVERIFIED**：検証未完：trust material 欠落、authority 利用不能、trusted clock 欠如、revocation snapshot の欠落・stale・incomplete・最高受理済み Record でないこと、offline 制約。
- **INVALID**：構造失敗：strict-JSON 違反、duplicate members、`l2Version` 違反、非 canonical payload、signature 失敗、payload/signature 分離則の違反、keyId/publicKey recomputation 不一致（§9.4、§10.1）。
- **EXPIRED**：構造妥当だが `T` が `[issuedAt, expiresAt]` 外。
- **REVOKED**：fresh な revocation snapshot が署名鍵を compromised と、または `revokedFrom` ≤ evaluation scope で revoked とマークする（§11.4、§13.4）。
- **STALE**：statement generation が受理済み最大／statement floor 未満、prior-epoch の `epochId`、受理済み rollback 試行。
- **UNKNOWN_AUTHORITY**：signer key が受理済み authority binding にない、`entityId` 不一致、unsigned transfer 試行、outgoing key が current でない。
- **UNSUPPORTED_ALGORITHM**：algorithm identifier が verifier により unknown／unsupported。
- **CONFLICT**：同一 Anchor に有効期間が重複する矛盾する current authority bindings。
- **MISMATCH**：statement の `descriptionLocation` が観測された L1 `Location` と一致しない（§7.4）。
- **ANCHOR_MISMATCH**：statement の `anchorId` が verifier の expected anchorId と一致しない（§15.1）。

`UNVERIFIED` は `VERIFIED` として surface してはならず（MUST NOT）、Entity の failure として surface してはならない（MUST NOT）。`UNVERIFIED` は trust knowledge の欠落であり trust verdict ではない。L2 verification conformance を主張する consumer はこれら状態を暴露しなければならず（MUST）、boolean にしてはならず（MUST NOT）、`UNVERIFIED` を "allowed" へ silent に潰してはならない（MUST NOT）。

```text
L1 status            ≠ L2 verification state
L2 state VERIFIED    ≠ Entity is safe / authorized / reachable / non-local
L2 state VERIFIED    ≠ capability authorization
L2 state UNVERIFIED  ≠ AR-XML parse failure
L2 failure           ≠ network failure, integrity failure, or L1 failure
```

Verifier は L2 verification の一部として AR-XML を fetch してはならず（MUST NOT）、capabilities を実行してはならず（MUST NOT）、L2 verification を dereference 許可として扱ってはならない（MUST NOT）（§16、network policy は L1 が置く場所に留まる）。

### 15.1 Verification modes

Verifier は 2 つの modes のいずれかで評価しなければならず（MUST）、結果に `verificationMode` として記録する：

```text
statement-only   the statement is verified against the verifier's accepted authority
                 material; §7.4 consistency is not evaluated
l1-bound         the statement is verified against the verifier's accepted authority
                 material AND §7.4 consistency is evaluated against the observed L1 303
                 response for the same expected Anchor
```

Verifier は expected `anchorId` を out of band で取得しなければならない（MUST）。original request の Anchor UUID によるのであり、statement・response・wire からではない。expected Anchor と `anchorId` が一致しない statement は `ANCHOR_MISMATCH` として拒否しなければならない（MUST）：同じ Description Location を指す別 Anchor に対する有効な署名は、expected Anchor に対する有効な主張ではない。Location equality alone は `anchorId` equality を上書きしてはならない（MUST NOT）。

`statement-only` results は「current L1 resolution confirmed by signature」として presented・stated・displayed してはならない（MUST NOT）。`VERIFIED` results はその mode を暴露しなければならない（MUST）。modeless boolean は許可されない。`statement-only` mode では、`VERIFIED` は L1 observation を評価せずに signature・authority・freshness・revocation-snapshot checks が成立したことを意味する。`l1-bound` mode では、`VERIFIED` はさらに §7.4 の成立を意味する。

`l1-bound` 評価が要求され L1 observation を取得できない場合、結果は `UNVERIFIED` であり、`MISMATCH` でも `VERIFIED` でもない。

### 15.2 Precedence order

複数の failure conditions が applicable な場合、結果は以下の規範的順序で最初の failing phase の状態とする。全 phases が pass すれば `VERIFIED`。

```text
1.  wire canonicality, strict parse, duplicate members, integer range, l2Version,
    payload/signature separation, keyId/publicKey recomputation match (§9.4, §10.1)
    INVALID
2.  expected anchorId match (§15.1)                                    ANCHOR_MISMATCH
3.  algorithm identifier known and supported (§10.8)                   UNSUPPORTED_ALGORITHM
4.  trusted clock available (§11.2)                                    UNVERIFIED
5.  authority material present, chain walk 0..G complete, Record contiguity,
    prevRecordDigest binding, signerKeyId resolvable with publicKey in the accepted
    chain (§9.6)                                                       UNVERIFIED (chain gap,
    missing material, no trusted lower bound §9.8); UNKNOWN_AUTHORITY (signer outside the
    accepted chain; keyId/publicKey recomputation failure §9.4)
6.  revocation snapshot fresh at T: highest accepted Record, within the freshness upper
    bound, covering the signing key, epochId, and chain (§11.4)       UNVERIFIED on gap,
    staleness, coverage gap
7.  Record chain position: Record below accepted maximum, same-generation byte-identity,
    epoch establishment (§9.7, §12.2, §12.3)                          STALE / CONFLICT
8.  signature verification under the signerKeyId publicKey bytes (§9.6)
    INVALID
9.  signer key status in the fresh snapshot, at issuedAt: compromised, revoked
    (revokedFrom ≤ evaluation scope), retired, outside validity window, entityId
    mismatch                                                          REVOKED (compromised,
    revoked); UNVERIFIED (retired, §10.5; outside window, §10.4);
    UNKNOWN_AUTHORITY (entityId mismatch, §15)
10. statement freshness [issuedAt, expiresAt] vs T; statement epochId match; statement
    generation vs accepted maximum and statement floor; statement equivocation
    (§11.3, §12.3)                                                    EXPIRED; STALE (prior-epoch
    epochId, below floor, below accepted maximum); CONFLICT (equivocation)
11. l1-bound mode, §7.4 (§15.1)                                        UNVERIFIED on missing
    observation; MISMATCH on equality failure
```

phase 内では条件は列挙順に applicable となり、各 branch は exactly one result を得る。受理済み chain に存在しない signer は phase 5 で `UNKNOWN_AUTHORITY` として失败し、phase 8-9 には到達しない；`keyId`/`publicKey` recomputation 不一致は Record に対して phase 1 で `INVALID`、従属する statement に対して phase 5 で失败し、§9.4 と整合する。Retired key は `UNVERIFIED`（§10.5）を生成し、`REVOKED` は決して生成しない；`REVOKED` は fresh な snapshot を要求し、phase 6 が phase 9 に先立ってそれを保証する。受理済み maximum で validity window 内に replay された statement は freshness progress ではない（§11.3）。Authority rollback（受理済み maximum 未満の Record）は `STALE`。同一 generation で異なる payload の Record は `CONFLICT`。受理済み chain の外の signer は `UNKNOWN_AUTHORITY`。Verifier は phases を並べ替えてはならず（MUST NOT）、severity・recency・origin preference で選択してはならない（MUST NOT）。結果はどの phase が failed かを記録しなければならない（MUST）。

## 16. L1・Manifest 0.1・administration との関係

### 16.1 L1

L1 は有効・無変更・単独で十分、のまま。L2 verification は追加的である。L2 consumer は L1 clients の trust を再実装せず検証でき、L1-only consumers は完全な conforming のままでなければならない（MUST）。L2 は Manifest・AR-XML parsing・trust checks を Core path に強制してはならない（MUST NOT）。Statement の description assertion は L1 が観測したものに対してのみ検証される；L2 は L1 redirect processing を置き換えてはならない（MUST NOT）。

### 16.2 Manifest 0.1

Manifest は ordinary L1 と L2 baseline に対し optional のままである。L2 は selected Manifest fields を署名し、Manifest を参照し、L2 extension により Manifest を拡張し、別 resource を使用し、Manifest 皆無の deployment をサポートできる。L2 statement の `entityId` と `descriptionLocation` は Frozen Manifest 0.1 の語彙を再定義せず再利用する。L2 profile は Manifest retrieval を要求できる；その profile は別途 versioning される。Manifest 0.1 integrity pinning は L2 verification semantics へ統合してはならない（MUST NOT）。

### 16.3 Administrative authentication

```text
Admin authentication
= who may mutate resolver records

L2 authority
= who is cryptographically/semantically authoritative for an Entity
```

両者は区別される。Administrative login・session・CSRF・operator identity は L2 authority の証明ではない。L2 authority は administrative credential ではない。Registration bootstrap は administrative channel を経由し初期 Trust Anchor Binding を生成できる（MAY）；これは policy connection であり protocol identity ではない。L2 は OAuth・OIDC・SSO・RBAC・WebAuthn・passwords・sessions・CSRF・device pairing・他の administrative authentication mechanisms を要求・置換・定義・禁止してはならない（MUST NOT）。

### 16.4 Reference Resolver boundary

L2 statement issuance は AR-XML の fetch・parse・validation を前提としてはならない（MUST NOT）。L2 は management UI を構築できず、device IP を解決できず、capabilities を invoke できず、public surface に mutation を暴露できない。Reference Resolver の L2 support は追加的な optional surface であり、Core conformance claims は L2 なしで有効なままである。

## 17. 脅威モデルと security analysis

L2 baseline は最低以下を扱わなければならない（MUST）。各脅威は本 draft の規範的 rule にマップされる。

| Threat | Baseline mechanism |
| --- | --- |
| Replay | validity window + per-epoch generation + accepted-maximum（§11.3） |
| Rollback | monotonic Record generations、per-epoch statement floors、`STALE`（§9.7、§11.3、§12.3） |
| Stale signed data | `expiresAt`、epoch floor、`EXPIRED`/`STALE`（§11） |
| Key compromise | revocation、short windows、fail-closed revocation、Records 内の compromised flag、never-current signatures（§10.6、§13.4） |
| Key substitution | Authority Record chain：prior-current key が署名し、新 key 単独では構築不能；key material は wire に `publicKey` として运び、`keyId` は再計算される（§9.4、§9.5、§9.6、§10.3） |
| Conflicting authorities | `CONFLICT`、fail-closed、reconciliation heuristics 禁止（§8.3） |
| Downgrade to L1 | frozen Core `l` rules を維持（SHOULD-level `501` guidance）、never L1（§6.1、§5） |
| Signature stripping | signing target = canonical payload octets；signature in payload 拒否；`VERIFIED` に signature 必須（§7.1、§15 `INVALID`） |
| Canonicalization ambiguity | 単一の JCS scheme（RFC 8785）、wire canonicality、raw wire text での duplicate-member detection、UTF-16 code-unit member order、非 BMP test vector（§7.3、Appendix A） |
| Clock skew | documented skew tolerance、bounded validity、trusted clock なしで `UNVERIFIED`（§11.2） |
| Trust-anchor compromise | bootstrap provenance は policy channel；authority material の自己 bootstrap 禁止（§9.2–9.3） |
| Transfer race | outgoing-key signature 必須、単一の current epoch、`CONFLICT`（§12.2、§12.6） |
| Revoked-but-cached statements | verifier-side invalidation、cache は残存有効期限で bound、L2 currency は HTTP reuse と separate（§13.4、§13.5、§14.2） |
| Offline verification limits | clock/revocation gaps に `UNVERIFIED`、offline gap から `VERIFIED` を生成しない（§11.5、§13.6） |
| Compromised key re-signing old generation/issuedAt | compromised flag は Record chain を伝播、compromised key からの署名は never current、`VERIFIED` に `T` で fresh な revocation snapshot 必須（§13.4、§11.4） |
| Authority Record rollback / equivocation | `recordGeneration` contiguity、accepted maximum、同一 generation での byte-identity、`STALE`/`CONFLICT`（§9.7、§15.2） |
| Cross-anchor statement substitution | expected `anchorId` を out of band で取得、`ANCHOR_MISMATCH`、Location equality alone は決して上書きしない（§15.1） |
| First-contact authority bootstrap | Trust Anchor Binding からの chain walk、state loss 後の再確立、first-contact から `VERIFIED` なし（§9.6、§9.8） |
| keyId ↔ publicKey substitution | `keyId` は wire の `publicKey` bytes から recomputed、Records は full key material を运び、chain acceptance は binding material から（§9.3、§9.4、§9.5、§10.1） |
| cross-epoch replay / generation mixing | statement `epochId` は signing target に含まれる、per-epoch statement floors、`recordGeneration` と statement `generation` は別系列（`STALE`、§11.3、§12.2、§12.3） |

明示的な non-goal boundary：L2 は physical Entity authentication・device authorization・capability authorization・AR-XML semantic validation・general PKI replacement を提供せず、`VERIFIED` はそれらのいずれとしても surface してはならない（MUST NOT）（§15、§21）。

## 18. Non-goals

L2 0.1 は以下を定義してはならない（MUST NOT）：

1. Capability authorization、OAuth そのもの、Entity operations の user authentication；
2. AR-XML semantic validation、Profile・Capability Contract resolution；
3. device IP / network address resolution、automatic Capability execution；
4. generic PKI replacement；
5. minimal model を越える full decentralized identity architecture；
6. public Core GET path への new public mutation semantics；
7. mandatory Manifest；
8. baseline 必須としての append-only ledgers・blockchains・transparency logs・third-party timestamping；
9. baseline 必須としての proprietary crypto・licensed authentication products・specialized hardware・patent-dependent mechanisms；
10. Frozen Resolver Core 0.1・Lifecycle 0.1・Manifest 0.1・Extension Policy・Runtime Integration Contract 0.1・Conformance Catalog 0.1・Reference Resolver Architecture 0.1・Deployment Profiles 0.1 の変更。

## 19. Baseline と extensions

Baseline mandatory guarantees：G1–G8（§4）。L2 0.1 baseline conformance を主張する verifier は以下を実装しなければならない（MUST）：UTF-16 code-unit member order を持つ JCS canonical serialization（§7.3）、`ed25519` verification、statement parsing、public key material を含む Trust Anchor Binding からの Record chain acceptance（§9.6）、wire `publicKey` bytes からの keyId recomputation（§9.4、§10.1）、authority-material anti-rollback と equivocation handling（§9.7）、freshness、per-epoch statement anti-rollback、revocation snapshot（§11.4）、verification modes と §15.2 precedence order を含む §15 の full state model、`l1-bound` mode での §7.4 consistency、要求されるすべての fail-closed behavior。

Optional extensions：`ES256` と later algorithms（§10.8）、delegation / chained authority、nonce/challenge online checks、per-statement revocation（§13.1）、Manifest association、third-party timestamps、transparency logs、`p` による profile identifiers。Extensions は baseline conformance 必須であってはならず（MUST NOT）、baseline semantics を再定義してはならず（MUST NOT）、baseline verifier により security-critical と扱われてはならない（MUST NOT）。

## 20. Conformance と testbed handoff

L2 conformance catalog は follow-up work（Issue #28 deliverables sequence）：

```text
Resolver L2 specification
  ↓
L2 conformance catalog
  ↓
Reference Resolver implementation
  ↓
relink-testbed executable scenarios
  ↓
Reference Lab integration
```

Follow-up catalog で想定される L2 targets（identifiers は catalog が所有し、本 draft は所有しない）：

```text
L2-SERVER        (statement/authority material endpoint behavior)
L2-PRODUCER      (canonical serialization, signing, key lifecycle practices)
L2-VERIFIER      (state model, all §15 states, fail-closed rules)
L2-AUTHORITY     (rotation/transfer/revocation signature semantics)
```

L2-VERIFIER / L2-AUTHORITY acceptance は次を含む：public key material を运ぶ initial Trust Anchor Binding からの chain acceptance で rotation・transfer・revocation の後に wire を経て current key に到達すること（§9.3、§9.6 item 5）、§9.7 の equivocation handling、`VERIFIED` のための revocation snapshot freshness（§11.4）、§15.2 の precedence cases、Appendix A の canonical および signature vectors（非 BMP UTF-16 code-unit vector を含む）。Identifiers は catalog が所有し、本 draft は発明してはならない（MUST NOT）。

本 draft は `RES-*`・`LIFE-*`・`MAN-*`・`INT-*`・`NET-*`・`RT-*`・L2 catalog case identifiers を発明してはならない（MUST NOT）。Frozen Catalog が 0.1 identifiers を、future L2 catalog が L2 identifiers を所有する。Local PHPUnit tests は L2 catalog の存在後にのみ L2 catalog へマップする；repository confirmation flow（`composer validate`、`composer test`、`composer analyse`、`php -l`、`git diff --check`）は implementation work に適用され、local results は relink-testbed results として報告してはならない（MUST NOT）。

## 21. Follow-up 決定を要する Open issues

1. L2 profiles の `p` semantics、online freshness の nonce/challenge binding；
2. transfer/revocation の public resources 化（paths・status codes・discovery・submission channel：administrative か L2-signed material か）；per-statement revocation の non-baseline extension 化（§13.1、§13.4）；revocation status そのものは Record chain operation である（§9.5、§13.4）；
3. Trust Anchor Binding provisioning profile（factory pairing・operator trust lists・device-bundled anchors、binding public key material §9.3、epochId と statementFloor の確立）とその conformance；
4. §7.4 の exact byte equality を超える URI comparison normalization；
5. `statementType` registry governance と version negotiation；
6. delegation / chained authority design；
7. administrative・export・archival 場面での SUSPENDED/RETIRED lifecycle edges の authority material；
8. revocation propagation latency targets、§11.4 baseline 要求を超える numeric snapshot freshness upper bounds（profile parameters、0.1 では固定しない）、cache invalidation interfaces、§11.4 baseline を超える coverage horizon profiles；
9. ES256 の MUST 化、algorithm を追加する profile mechanism；
10. L2 conformance catalog case set（§9.6 chain-acceptance、§9.7 equivocation、§11.4 revocation snapshot、§15.2 precedence、Appendix A を超える signature test vectors を含む）；
11. 安定化後の日本語規範翻訳。

## 22. Acceptance-criteria mapping（Issue #28）

| Issue #28 acceptance criterion | Draft coverage |
| --- | --- |
| L2 is a separate versioned layer, frozen Core 0.1 semantics unchanged | §1、§5、freeze policy |
| Integrity / authenticity / authority / freshness / ownership / authorization distinguished | §4、§8.2、§15、§16.3 |
| Signed / verifiable object defined | §7（payload、JCS canonical serialization、signing bytes）、Appendix A vectors |
| Signer identity and Entity authority rules defined | §8、§§9.1–9.8（wire `publicKey` material を持つ Authority Record format、chain acceptance、anti-rollback、state loss） |
| Key discovery and rotation semantics defined | §§9.5–9.7、§10（validity windows、rotation Records、wire publicKey からの keyId recomputation、epochId）；§21 の OPEN items |
| Freshness and anti-rollback defined | §11（statement anti-rollback、authority-material anti-rollback §9.7、revocation snapshot §11.4） |
| Ownership / authority transfer semantics defined | §12 |
| Revocation semantics defined | §13、§9.5、§13.4（revocation・retirement・compromise は Record chain operations）；revocation material resource は OPEN、§21 |
| Verification result states deterministic and documented | §15（modes、precedence order、`ANCHOR_MISMATCH`） |
| Relationship to Manifest 0.1 explicit | §16.2、§7.6 |
| Relationship to administrative authentication explicit | §16.3 |
| L1 downgrade / fallback explicit and fail-closed | §5、§6.1、§6.5、§17 |
| Security analysis covers replay, rollback, key compromise, conflicting authority, signature stripping, Record rollback/equivocation, cross-anchor substitution, compromised-key re-signing, first-contact bootstrap, keyId/publicKey substitution, cross-epoch replay | §17 |
| Capability execution and authorization out of scope | §18、§16.4、§15 |

すべての criteria は仕様レベルで draft にカバーされている；implementation・L2 conformance catalog・testbed scenarios は §20 の follow-up のままである。

## 23. Design summary

```text
L2
= trust / authenticity / authority / freshness over resolution information
= separately versioned additive layer above frozen L1

Request:
    GET /{resolver-service}/{uuid}?l=2
    ACTIVE    → 200 + L2 Trust Statement
    SUSPENDED → 404, RETIRED → 410, unknown → 404
    Core-only Resolver → frozen Core §§5/14.2 guidance (SHOULD 501), never L1

Signed object:
    strict JSON { payload, signature }
    signing target = UTF-8 of JCS(payload) (RFC 8785); wire payload is canonical
    member names in UTF-16 code-unit order (RFC 8785 §3.2.3); non-BMP vector pinned
    duplicate members forbidden
    l2Version = "0.1", statementType = "resolution"
    anchorId, entityId, descriptionLocation, lifecycleStatus,
    keyId, algorithm, issuedAt, expiresAt, generation, epochId

Authority:
    bound per Anchor UUID
    bootstrap = Trust Anchor Binding via policy channel, with initial public key material,
                epochId, statementFloor
    rotation  = signed by prior-current authority, overlapping windows
    transfer  = explicit signed transfer, outgoing key signs Record G, newEpochId,
                incoming key signs from Record G+1, statement floor reset
    revocation= signed by current authority, fail-closed
    one current binding per anchor; conflicts → CONFLICT
    Authority Record = signed snapshot, recordGeneration strictly increasing,
    publicKey on the wire, prevRecordDigest chain binding, epochId, statementFloor
    chain walk 0..G from the Trust Anchor Binding; key never signs its own introduction
    Record rollback → STALE; same generation, different payload → CONFLICT

Keys:
    keyId = base64url(SHA-256(public key)[0..7]), 11 chars, padding stripped,
    case-sensitive comparison; verifier recomputes keyId from wire publicKey bytes
    baseline MUST: ed25519; RECOMMENDED: ES256
    algorithm agility with fail-closed UNSUPPORTED_ALGORITHM
    no single permanent key

Freshness:
    [issuedAt, expiresAt], skew tolerance documented
    per-epoch statement generations, statement floors, epochId match,
    replay/rollback → STALE
    no trusted clock → UNVERIFIED, never VERIFIED
    VERIFIED at T requires the revocation snapshot fresh at T within a bounded
    freshness upper bound (§11.4); VERIFIED at T makes no claim about T' > T

Verifier states (deterministic, no boolean):
    VERIFIED | UNVERIFIED | INVALID | EXPIRED | REVOKED | STALE |
    UNKNOWN_AUTHORITY | UNSUPPORTED_ALGORITHM | CONFLICT | MISMATCH | ANCHOR_MISMATCH
Verification modes: statement-only | l1-bound; result records mode and failing phase
(§15.1, §15.2 precedence order)

Boundaries:
    L1 unchanged; L2 additive
    Manifest optional; integrity pinning ≠ trust
    admin auth ≠ L2 authority
    HTTPS origin ≠ Entity authority
    Anchor UUID not a credential
    no AR-XML fetch/parse in L2 path
    no capability discovery/authorization/execution in L2
    L2 failure ≠ network / integrity / parse failure
```

この最小境界は意図的であり、Frozen Manifest 0.1・Resolver Core 0.1・Lifecycle 0.1 が基盤として維持する上に、L2 が trust 層を追加する。L2 の失敗は network / integrity / parse / L1 の失敗と区別され、`UNVERIFIED` は trust 知識の欠落であって trust verdict ではない。

---

## 付録 A. 決定性 Ed25519 + JCS テストベクトル（informative）

この付録は、§ 7.3 の signing target、signature コーディング、canonical serialization 規則（object member の UTF-16 code-unit order によるソート、RFC 8785 § 3.2.3、§ 7.3）をコードに固定する。ベクトルは下記の固定 seed から、RFC 8032 Ed25519、RFC 8785 JCS、SHA-256、SHA-512、base64url（RFC 4648 § 5）のみを用いて再生できる。Conformance テストケースの case 識別子は catalog-owned の follow-up 作業（§ 20）であり、この付録は reference 値を提供するものであり、case 識別子を提供しない。

### A.1 固定 key material

Seed string, UTF-8: `relink-l2-0.1-draft-test-vector-seed-1`

```
seed           = SHA-256(seed string)
private seed   = seed
scalar a       = SHA-512(private seed), first 32 bytes as little-endian, clamped:
                 clear bits 0..2, clear bit 255, set bit 254 (RFC 8032 5.6 step 1)
prefix         = SHA-512(private seed), bytes 32..63
r              = SHA-512(prefix + signing target), little-endian, mod L
R              = r * B, encoded
S              = (r + k * a) mod L,  k = SHA-512(R + A + signing target), little-endian
signature      = R + S (64 bytes)
public key A   = a * B, 32-byte encoding
keyId          = base64url(first 8 bytes of SHA-256(A), '=' stripped) (§10.1)
```

```
private seed (hex) = 62d7fed7cdbced7fb568f23de5442ab57ebe2fe567f76ac092d85ffd882c693e
public key A (hex) = b3edee607589a8e02950718078cc737507e1f47b678b933635f673ed45919fe2
SHA-256(A) (hex)   = 10230445dbe957492b6d67f2a0d1d66d655e167c9fc1a58f93f2fbf788acbdbb
keyId              = ECMERdvpV0k   (11 chars; first 8 bytes of SHA-256(A): 10230445dbe95749)
```

検証者は `A` から `keyId` を § 10.1 に従って再計算 MUST、`ECMERdvpV0k` と一致 MUST。この key を紹介するすべての Record の wire `publicKey` は `A` と一致 MUST（§ 9.4、§ 10.1）。

### A.2 Vector 1: resolution statement、signing target に `epochId` を含む

Payload #1（display order で表示。wire payload はこの object を canonical form で serialization したもの MUST、§ 7.3）:

```json
{
  "l2Version": "0.1",
  "statementType": "resolution",
  "anchorId": "550e8400-e29b-41d4-a716-446655440000",
  "entityId": "urn:relink:entity:thermal-sensor-42",
  "descriptionLocation": "https://sensors.example/thermal/42/ar-xml",
  "lifecycleStatus": "active",
  "keyId": "ECMERdvpV0k",
  "algorithm": "ed25519",
  "issuedAt": 1769996400,
  "expiresAt": 1770000000,
  "generation": 7,
  "epochId": "epoch-0"
}
```

JCS canonical string（signing target、1 line）:

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","descriptionLocation":"https://sensors.example/thermal/42/ar-xml","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-0","expiresAt":1770000000,"generation":7,"issuedAt":1769996400,"keyId":"ECMERdvpV0k","l2Version":"0.1","lifecycleStatus":"active","statementType":"resolution"}
```

Signing target を UTF-8 octets として hex で表示（364 bytes、line breaks は表示専用）:

```
7b22616c676f726974686d223a2265643235353139222c22616e63686f724964
223a2235353065383430302d653239622d343164342d613731362d3434363635
35343430303030222c226465736372697074696f6e4c6f636174696f6e223a22
68747470733a2f2f73656e736f72732e6578616d706c652f746865726d616c2f
34322f61722d786d6c222c22656e746974794964223a2275726e3a72656c696e
6b3a656e746974793a746865726d616c2d73656e736f722d3432222c2265706f
63684964223a2265706f63682d30222c22657870697265734174223a31373730
3030303030302c2267656e65726174696f6e223a372c22697373756564417422
3a313736393939363430302c226b65794964223a2245434d455264767056306b
222c226c3256657273696f6e223a22302e31222c226c6966656379636c655374
61747573223a22616374697665222c2273746174656d656e7454797065223a22
7265736f6c7574696f6e227d
```

Signature（Ed25519(private seed, signing target)、base64url は '=' を stripped、86 chars）:

```
z2u8xgPs0p-OJcBwycTyYmTTc23uwniF0JgzK02jEvqaoYWkNdpIfB6yQ5-LbZbjRHxfaZHpTlUFavYoO8nQBw
```

wire statement object は `{ "payload": <canonical object above>, "signature": <signature above> }`（§ 7.1）。public key `A` に対する verification は MUST succeed、signing target のいずれかの octet を変更した対象に対する verification は MUST fail。statement の `epochId` は signing target に含まれる（§ 7.2）: canonical string から `epochId` を省略する実装は異なる signing target を生成し、このベクトルを fail する。

### A.3 Vector 2: extension members

Payload #2 = payload #1 に extension member を 1 つ追加したもの:

```json
"extExample": { "z": 1, "a": [2, 3] }
```

JCS canonical string（signing target、1 line。`extExample` は `expiresAt` の後、`generation` の前に serialization され、extension object 内で `a` は `z` より前）:

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","descriptionLocation":"https://sensors.example/thermal/42/ar-xml","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-0","expiresAt":1770000000,"extExample":{"a":[2,3],"z":1},"generation":7,"issuedAt":1769996400,"keyId":"ECMERdvpV0k","l2Version":"0.1","lifecycleStatus":"active","statementType":"resolution"}
```

Signing target を UTF-8 octets として hex で表示（395 bytes、line breaks は表示専用）:

```
7b22616c676f726974686d223a2265643235353139222c22616e63686f724964
223a2235353065383430302d653239622d343164342d613731362d3434363635
35343430303030222c226465736372697074696f6e4c6f636174696f6e223a22
68747470733a2f2f73656e736f72732e6578616d706c652f746865726d616c2f
34322f61722d786d6c222c22656e746974794964223a2275726e3a72656c696e
6b3a656e746974793a746865726d616c2d73656e736f722d3432222c2265706f
63684964223a2265706f63682d30222c22657870697265734174223a31373730
3030303030302c226578744578616d706c65223a7b2261223a5b322c335d2c22
7a223a317d2c2267656e65726174696f6e223a372c226973737565644174223a
313736393939363430302c226b65794964223a2245434d455264767056306b22
2c226c3256657273696f6e223a22302e31222c226c6966656379636c65537461
747573223a22616374697665222c2273746174656d656e7454797065223a2272
65736f6c7574696f6e227d
```

Signature（86 chars）:

```
HChT88MiaoViTfGVUMcfrPTikpbeMs8PYlBozWkBkTlJQWzhPWNTZ1B5MB7Q_pf2k7DqmGxiu-REmKhdKMgkBA
```

### A.4 Vector 3: 非 BMP 拡張 keys（UTF-16 code-unit order 検出）

Payload #3 = payload #2 に extension member `extUnicode` を 1 つ追加したもの。member names は `a` (U+0061)、`z` (U+007A)、U+00E9、U+1F600（UTF-16: D83D DE00）、U+E000。下記 display で非 ASCII keys は JSON escape で表記しているが、payload ではリテラル文字である:

```json
"extUnicode": { "a": 1, "z": 1, "\u00e9": 2, "\ud83d\ude00": 3, "\ue000": 4 }
```

UTF-16 code-unit order（RFC 8785 § 3.2.3、§ 7.3 の規則）は `a` (0061) < `z` (007A) < U+00E9 (00E9) < U+1F600 (D83D DE00) < U+E000 (E000)。scalar code-point order では U+E000 (57344) が U+1F600 (128512) より前に来る。object members を scalar code-point で sort するすべての実装は異なる canonical string を生成し、このベクトルを fail する。

JCS canonical string（signing target、1 line。非 ASCII printable members は as-is で出力されるため、canonical string は literal の U+00E9、U+1F600、U+E000 を含む。U+E000 は PUA code point であり、viewer により box、space、無表示になることがある。）:

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","descriptionLocation":"https://sensors.example/thermal/42/ar-xml","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-0","expiresAt":1770000000,"extExample":{"a":[2,3],"z":1},"extUnicode":{"a":1,"z":1,"é":2,"😀":3,"":4},"generation":7,"issuedAt":1769996400,"keyId":"ECMERdvpV0k","l2Version":"0.1","lifecycleStatus":"active","statementType":"resolution"}
```

Signing target を UTF-8 octets として hex で表示（446 bytes、line breaks は表示専用、U+00E9 = C3 A9、U+1F600 = F0 9F 98 80、U+E000 = EE 80 80）:

```
7b22616c676f726974686d223a2265643235353139222c22616e63686f724964
223a2235353065383430302d653239622d343164342d613731362d3434363635
35343430303030222c226465736372697074696f6e4c6f636174696f6e223a22
68747470733a2f2f73656e736f72732e6578616d706c652f746865726d616c2f
34322f61722d786d6c222c22656e746974794964223a2275726e3a72656c696e
6b3a656e746974793a746865726d616c2d73656e736f722d3432222c2265706f
63684964223a2265706f63682d30222c22657870697265734174223a31373730
3030303030302c226578744578616d706c65223a7b2261223a5b322c335d2c22
7a223a317d2c22657874556e69636f6465223a7b2261223a312c227a223a312c
22c3a9223a322c22f09f9880223a332c22ee8080223a347d2c2267656e657261
74696f6e223a372c226973737565644174223a313736393939363430302c226b
65794964223a2245434d455264767056306b222c226c3256657273696f6e223a
22302e31222c226c6966656379636c65537461747573223a2261637469766522
2c2273746174656d656e7454797065223a227265736f6c7574696f6e227d
```

Signature（86 chars）:

```
nNEFPVF4vUZWiUU9eadPkEqQEb_pNBXR3yksE5IHTNrYASA8Fg4hubCdDr9PR4vPLxzaBRzeeYdYH-O4XArjCQ
```

### A.5 Reproduction（再現手順）

1. § A.1 に従い private seed を計算し、public key `A` を導出し、`keyId` を § 10.1 に従って再計算し、§ A.1 の値をチェックする。
2. すべての payload を JCS で serialize する: object member names は UTF-16 code-unit order（RFC 8785 § 3.2.3）で sort、minimal escaping（C0 control、`"`、`\`、DEL は `\uXXXX` に escape、printable 非 ASCII は as-is で出力）、整数は plain decimal、whitespace なし。結果は上記の canonical string と byte-for-byte で一致 MUST。
3. canonical string の UTF-8 octets に deterministic Ed25519（RFC 8032 § 5.2）で signature を生成する。base64url signature（'=' を stripped）は上記の signature と byte-for-byte で一致 MUST。
4. `A` に対して verification する。変更された signing target に対する verification は MUST fail。Vector 3 は U+1F600 member が U+E000 member より前に serialization されるコードで MUST verify、scalar code-point sorting は canonical bytes を再生 MUST NOT。
