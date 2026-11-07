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
9. L2 statement issuance の前提として Manifest retrieval を要求する、または L2 resolution の前提とする。

L2 は追加的（additive）かつ独立に観測可能でなければならない（MUST）：

```text
L1 absent of L2     → unchanged Core 0.1 behavior
L2 present          → additional signed trust material
L2 verification     → consumer-side decision, never a Resolver transport side effect
```

L2 verification に失敗した statement は public L1 response を変化させてはならない（MUST NOT）。L2 verification の成功も public L1 response を変化させてはならない（MUST NOT）。Consumer は L2 results を resolution に係る metadata として扱い、L1 HTTP status の代替としてはならない（MUST NOT）。

`l=2` を受理した Core 0.1 のみ実装 Resolver は、Core 0.1 が要求するとおり fail closed でなければならない（MUST）：`Cache-Control: no-store` の `501 Not Implemented`。

## 6. L2 request と resource shape

### 6.1 L2 statement request

```text
GET /{resolver-service}/{uuid}?l=2
```

L2 を support する Resolver と ACTIVE record に対して：

```http
200 OK
Content-Type: application/json
Cache-Control: <bounded by remaining validity, §14.4>
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

### 6.2 `p` parameter

Baseline L2 は必須の `p` semantics を定義しない。L2 が定義しない semantics で `p` を含むリクエストは Core 0.1 が要求するとおり処理されなければならない（MUST）：`400 Bad Request`、L1 downgrade なし。`p` は将来、L2 profile identifier・nonce・challenge binding を運ぶ可能性がある（OPEN、§21）。

### 6.3 Authority material request

```text
GET /{resolver-service}/{uuid}/authority
```

ACTIVE record に対しては `200` + Authority Record（§9）。status mapping は §6.1 と同一。Authority Record は署名付き metadata である（現 authority による自己署名；bootstrap は trust anchor binding、§9.3）。

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
```

`l2Version` は文字列 `"0.1"` でなければならない（MUST）。Verifier は他の `l2Version` values を L2 0.1 と解釈してはならない（MUST NOT）。unsupported な `l2Version` は fail closed でなければならない（ignorable extension ではない）。

Optional payload members（baseline）：REQUIRED なものはなく、追加メンバーはすべて §7.5 に従って扱われる。

### 7.3 Canonical serialization と signing bytes

Signing target は deterministic かつ interoperable でなければならない（MUST）。

Signing target は `payload` の canonical serialization の UTF-8 encoding であり、以下に従う：

1. members は §7.2 で定義された固定順で serialize し、追加 members は member name の辞書順（lexicographic ascending）で続ける；
2. strict JSON で insignificant whitespace を含まない最小化形式；
3. UUID values は lowercase hexadecimal；
4. Unicode text は標準 JSON escaping 規則で serialize し、非 ASCII 文字は escaped せず UTF-8 で出力する；
5. duplicate members を含まず、numeric-format ambiguity を含まない：integer-valued members は exponent・fraction・`+`・leading zeros・trailing `.0` を含まない decimal integer として serialize されなければならない；
6. base64url の `keyId` digest material は §10.1 の定義に従う。

Verifier は parse した `payload` を canonicalize し、canonical octets に対して signature を検証しなければならない（MUST）。§7.3 の canonicalization 規則に失敗する payload、duplicate members を含む payload、非 canonical な数値フォーマットを含む payload、strict JSON として parse できない payload は `INVALID` を生成しなければならない（MUST）。

Signing target は XML canonicalization、transport framing、content coding、consumer の JSON parser の癖に依存してはならない（MUST NOT）。

### 7.4 Statement-to-L1 consistency

Statement の `descriptionLocation` は authority による現 Description Location の主張である。観測された L1 `303` response と L2 を比較する verifier は、標準的な HTTP field-value 抽出の後の、観測された `Location` field value と statement の `descriptionLocation` の exact・case-sensitive・byte-level の一致を要求しなければならない（MUST）。不一致は `MISMATCH` を生成しなければならない（§15）。Baseline はこの exact rule を超える URI normalization を適用してはならない（MUST NOT）（normalization rules は OPEN、§21）。

一致の成功は、署名された主張と観測された L1 response の整合チェックである。それ自体は authority・authenticity・freshness ではない。

### 7.5 Extensions

- `payload` 内の unknown members は §7.3 により signature に覆われる。受理しなければならず（MUST）、定義済みメンバーの semantics を override してはならず（MUST NOT）、verifier により security-critical evidence として扱われてはならない（MUST NOT）。
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

ある Anchor の初期 authority は、deployment の registration / administrative チャネルにより registration 時に確立される：Trust Anchor Binding は `anchorId → initial keyId` と initial epoch start を記録する。初期 Authority Record は自己署名しうる（MAY）。Verifier は bootstrap を自身の policy チャネル経由でのみ受理する。Bootstrap は authority binding を確立するのであり、Entity authentication・physical device の ownership・AR-XML validity ではない。

Trust Anchor Binding の provenance・format・provisioning mechanism は deployment profile の関心事であり、L2 wire semantics ではない（OPEN、§21）。

### 9.4 Trust material consistency

Verifier は受理済み binding と矛盾する authority material を拒否しなければならない（MUST）。受理済み binding に存在しない鍵による statement には `UNKNOWN_AUTHORITY`、矛盾する bindings には §8.3 に従い `CONFLICT`、authority material が利用不能（欠落・取得不能・§11.4 で fresh でない）の場合は `UNVERIFIED` を生成しなければならない（MUST）。

## 10. Key lifecycle

### 10.1 Key identifiers

`keyId` は public key の canonical encoding の SHA-256 digest の先頭 16 bytes の lowercase base64url string でなければならない（MUST）。Public key encoding は algorithm ごとに §10.8 で定義される。Verifier は `keyId` を opaque lookup key として扱い、authorization claim として扱ってはならない（MUST NOT）。

### 10.2 Baseline algorithms

Baseline L2 verifier は EdDSA Ed25519（algorithm identifier `ed25519`）を support しなければならない（MUST）。ES256（`ES256`、NIST P-256）は第 2 supported algorithm として RECOMMENDED である。

Baseline L2 deployment は baseline において proprietary cryptographic schemes・licensed products・external trust services・specialized hardware・patent-dependent mechanisms を要求してはならない（MUST NOT）。

### 10.3 Rotation

Key rotation は現 authority による署名行為である。Baseline モデルは Authority Record により rotation を表現する：新 key の validity window は、署名時点で current な鍵により record が確立される。Authority Record は新 key 単独では構築できなければならない（MUST NOT）。

Rotation window は overlap すべきである（SHOULD）。Rotation は authority binding の epoch を変更しない；generation は rotation を跨いで単調に増加し続ける。

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

Verifier は Anchor UUID と epoch ごとに、current として受理した最高 generation を tracking しなければならない（MUST）。`generation` が受理済み最大より小さい statement は `STALE` を生成しなければならない（MUST）。受理済み最大と等しい statement は freshness progress として再受理してはならない（MUST NOT）；同一受理済み statement の replay は validity window 内でのみ許可され、それを覆う revocation の後は受理してはならない（MUST NOT）。

Epoch 内の generation numbers は successive accepted statements に対して strictly increasing でなければならない（MUST）。Epoch floor（transfer §12、revocation §13 により設定）未満の statement は `STALE` を生成しなければならない（MUST）。

### 11.4 Authority Record freshness

Authority material はそれ自身の validity window を持つ。Expired authority material は current authority として受理してはならない（MUST NOT）。Retrieval failure・expired authority material・policy が要求するときの revocation coverage の欠落は `UNVERIFIED` を生成しなければならず（MUST）、`VERIFIED` ではない。Signer が revoked でない場合、`REVOKED` に revocation evidence は必須だが `VERIFIED` には不要である。

### 11.5 Offline verification

Offline verifier（network・trusted-clock service なし）は signature・key binding・structure・§7.4 consistency・recorded authority material を検証できる（MAY）。Trusted clock がなく、local trusted time が文書化された trusted time source に基づるときのみ freshness を評価できる；そうでない場合結果は `UNVERIFIED` でなければならず、freshness verdict として surface してはならない（MUST NOT）。Fresh revocation coverage がない場合 `REVOKED` を決定できず、`UNVERIFIED` を upgrade してはならない（MUST NOT）。Online verification の nonce/challenge mechanism は follow-up である（§21）。

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
effectiveGeneration (the first generation of the new epoch)
issuedAt
expiresAt
```

Transfer statement は outgoing authority key により署名されなければならない（MUST）。Bootstrap から最初の transfer は適用されない；初期 binding は Trust Anchor Binding に由来する。

`outgoingKeyId` が verifier の受理済み current authority でない transfer statement は `UNKNOWN_AUTHORITY` を生成しなければならない（MUST）。Fresh でない transfer statement は epoch を生成してはならない（MUST NOT）。Verifier は unsigned・自己 attest・`incomingKeyId` 署名・administrative 署名・origin 署名・DNS 署名・CDN 署名の transfer 主張を受理してはならない（MUST NOT）。

### 12.3 Epoch semantics

Generation `G` での epoch 遷移後、prior epoch の statement は current として受理してはならない（MUST NOT）（generation < epoch floor ⇒ `STALE`）。Outgoing authority は新 epoch で署名してはならない（MUST NOT）。Incoming authority は epoch floor より前に署名してはならない（MUST NOT）。

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
key            (a key's validity window before natural expiry)
statement      (a specific unexpired statement before natural expiry)
authority binding (revocation of the current authority in favor of a successor;
                   expressed as transfer, not as revocation)
```

### 13.2 Revoking authority

Revocation は現 authority（revocable object を所有する鍵）、または transfer 後の受理済み successor epoch により署名されなければならない（MUST）。Revoked key は revoke できない。受理済み binding に存在しない鍵は revoke できない。Administrative actor の login は L2 revocation authority ではない（§16）。

### 13.3 Revocation discovery

Revocation evidence は authority material（§9.2）、利用可能な場合 revocation material resources（follow-up、§21）から発見される。Revocation evidence はそれ自身が fresh で、受理済み authority の下で検証可能でなければならない（MUST）（§11.4）。

### 13.4 Revocation effects

Fresh で検証済み key revocation：key window は revocation の effective generation で終了；その key 下で generation ≥ effective の statement は `REVOKED`；未満の statement は §11.3 に従う。Fresh で検証済み statement revocation は cache の freshness に拘わらずその statement を `REVOKED` にする。Revoked statements の verifier caches は verifier の次の受理済み評価で無効化されなければならない（MUST）。

### 13.5 Fail-closed revocation availability

Policy が revocation currency を要求し revocation state が利用不能な場合、結果は `UNVERIFIED`（L2-VERIFIED 主張に対して fail-closed）。Policy が revocation coverage を要求するとき、revocation 利用不能を `VERIFIED` として扱ってはならない（MUST NOT）。Revoked-but-cached statements は current として surface してはならない（MUST NOT）。

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

Caches は L2 statement を `expiresAt` 越え、それを覆う revocation 越え、影響する epoch 遷移越えで提供してはならない（MUST NOT）。

### 14.3 Privacy

L2 requests の Anchor UUID は L1 と同じ注意で loggable operational metadata として扱われなければならず（MUST）、`l`/`p` query parameters の最小化扱いを含む。L2 trust material は policy を越える admin surfaces に cache されてはならない（MUST NOT）。Revocation・transfer material は privacy-sensitivity を持つ；deployment は log retention を最小化すべきである（SHOULD）。

### 14.4 Referrer policy

Resolver Core と同じ：redirect responses に `Referrer-Policy: no-referrer` が送られるべきである（SHOULD）。L2 responses は Core と同じ referrer-minimization guidance を追従すべきである（SHOULD）。

## 15. Verification result model

Verifier は statement 評価に対し exactly one deterministic primary state を生成しなければならない（MUST）。Verifier は structural/signature failure を、trust information の欠落と、freshness failure と区別できなければならない（MUST）。

```text
VERIFIED
    canonical parse, signature under accepted current key within window, accepted
    authority binding, freshness valid, §7.4 consistency satisfied when required,
    revocation coverage satisfied per policy
UNVERIFIED
    verification incomplete: missing trust material, unavailable authority, missing
    trusted clock, revocation coverage required by policy and unavailable, offline
    limitation
INVALID
    structural failure: strict-JSON violation, duplicate members, l2Version violation,
    non-canonical payload, signature failure, payload/signature separation violation
EXPIRED
    structurally valid statement with T outside [issuedAt, expiresAt]
REVOKED
    fresh, verified revocation evidence covers the key (generation ≥ effective) or the
    statement
STALE
    generation below accepted maximum / epoch floor; accepted rollback attempt
UNKNOWN_AUTHORITY
    signer key not in accepted authority binding, entityId mismatch, unsigned transfer
    attempt, outgoing key not current
UNSUPPORTED_ALGORITHM
    algorithm identifier unknown/unsupported by the verifier
CONFLICT
    contradictory current authority bindings for the same Anchor with overlapping validity
MISMATCH
    statement's descriptionLocation does not match the observed L1 Location (§7.4)
```

状態の意味：

- **VERIFIED**：canonical parse、受理済み window 内 current key による署名、受理済み authority binding、freshness 妥当、要求される場面の §7.4 consistency、policy に従う revocation coverage がすべて成立。
- **UNVERIFIED**：検証未完：trust material 欠落、authority 利用不能、trusted clock 欠如、policy が要求し利用不能な revocation coverage、offline 制約。
- **INVALID**：構造失敗：strict-JSON 違反、duplicate members、`l2Version` 違反、非 canonical payload、signature 失敗、payload/signature 分離則の違反。
- **EXPIRED**：構造妥当だが `T` が `[issuedAt, expiresAt]` 外。
- **REVOKED**：fresh で検証済みの revocation evidence が key（generation ≥ effective）または statement を覆う。
- **STALE**：generation が受理済み最大／epoch floor 未満；受理済み rollback 試行。
- **UNKNOWN_AUTHORITY**：signer key が受理済み authority binding にない、`entityId` 不一致、unsigned transfer 試行、outgoing key が current でない。
- **UNSUPPORTED_ALGORITHM**：algorithm identifier が verifier により unknown／unsupported。
- **CONFLICT**：同一 Anchor に有効期間が重複する矛盾する current authority bindings。
- **MISMATCH**：statement の `descriptionLocation` が観測された L1 `Location` と一致しない（§7.4）。

`UNVERIFIED` は `VERIFIED` として surface してはならず（MUST NOT）、Entity の failure として surface してはならない（MUST NOT）。`UNVERIFIED` は trust knowledge の欠落であり trust verdict ではない。L2 verification conformance を主張する consumer はこれら状態を暴露しなければならず（MUST）、boolean にしてはならず（MUST NOT）、`UNVERIFIED` を "allowed" へ silent に潰してはならない（MUST NOT）。

```text
L1 status            ≠ L2 verification state
L2 state VERIFIED    ≠ Entity is safe / authorized / reachable / non-local
L2 state VERIFIED    ≠ capability authorization
L2 state UNVERIFIED  ≠ AR-XML parse failure
L2 failure           ≠ network failure, integrity failure, or L1 failure
```

Verifier は L2 verification の一部として AR-XML を fetch してはならず（MUST NOT）、capabilities を実行してはならず（MUST NOT）、L2 verification を dereference 許可として扱ってはならない（MUST NOT）（§16、network policy は L1 が置く場所に留まる）。

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
| Replay | validity window + generation + accepted-maximum（§11.3） |
| Rollback | monotonic generations、epoch floors、`STALE`（§11.3、§12.3） |
| Stale signed data | `expiresAt`、epoch floor、`EXPIRED`/`STALE`（§11） |
| Key compromise | revocation、short windows、fail-closed revocation（§10.6、§13） |
| Key substitution | Authority Record は prior-current key が署名し、新 key 単独では構築不能（§10.3） |
| Conflicting authorities | `CONFLICT`、fail-closed、reconciliation heuristics 禁止（§8.3） |
| Downgrade to L1 | Core `l` rules を維持；`l=2` を Core-only に → `501`、L1 化しない（§6.1、§5） |
| Signature stripping | signing target = canonical payload octets；signature in payload 拒否；`VERIFIED` に signature 必須（§7.1、§15 `INVALID`） |
| Canonicalization ambiguity | 固定 serialization order、number rules、XML canonicalization 不使用（§7.3） |
| Clock skew | documented skew tolerance、bounded validity、trusted clock なしで `UNVERIFIED`（§11.2） |
| Trust-anchor compromise | bootstrap provenance は policy channel；authority material の自己 bootstrap 禁止（§9.2–9.3） |
| Transfer race | outgoing-key signature 必須、単一の current epoch、`CONFLICT`（§12.2、§12.6） |
| Revoked-but-cached statements | revocation は cache に優先、cache は残存有効期限 bound（§13.4、§14.2） |
| Offline verification limits | clock/revocation gaps に `UNVERIFIED`、offline gap から `VERIFIED` を生成しない（§11.5、§13.6） |

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

Baseline mandatory guarantees：G1–G8（§4）。L2 0.1 baseline conformance を主張する verifier は以下を実装しなければならない（MUST）：canonical serialization、`ed25519` verification、statement parsing、authority binding checks、freshness、anti-rollback、revocation state handling、§15 の full state model、§7.4 consistency、要求されるすべての fail-closed behavior。

Optional extensions：`ES256` と later algorithms（§10.8）、delegation / chained authority、nonce/challenge online checks、Manifest association、third-party timestamps、transparency logs、`p` による profile identifiers。Extensions は baseline conformance 必須であってはならず（MUST NOT）、baseline semantics を再定義してはならず（MUST NOT）、baseline verifier により security-critical と扱われてはならない（MUST NOT）。

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

本 draft は `RES-*`・`LIFE-*`・`MAN-*`・`INT-*`・`NET-*`・`RT-*`・L2 catalog case identifiers を発明してはならない（MUST NOT）。Frozen Catalog が 0.1 identifiers を、future L2 catalog が L2 identifiers を所有する。Local PHPUnit tests は L2 catalog の存在後にのみ L2 catalog へマップする；repository confirmation flow（`composer validate`、`composer test`、`composer analyse`、`php -l`、`git diff --check`）は implementation work に適用され、local results は relink-testbed results として報告してはならない（MUST NOT）。

## 21. Follow-up 決定を要する Open issues

1. L2 profiles の `p` semantics、online freshness の nonce/challenge binding；
2. `payload` 内 signed extension members の canonicalization（vendor extensions の member-order rules）；
3. transfer/revocation の public resources 化（paths・status codes・discovery・submission channel：administrative か L2-signed material か）；
4. Trust Anchor Binding provisioning profile（factory pairing・operator trust lists・device-bundled anchors）とその conformance；
5. §7.4 の exact byte equality を超える URI comparison normalization；
6. `statementType` registry governance と version negotiation；
7. delegation / chained authority design；
8. administrative・export・archival 場面での SUSPENDED/RETIRED lifecycle edges の authority material；
9. revocation propagation latency targets と cache invalidation interfaces；
10. ES256 の MUST 化、algorithm を追加する profile mechanism；
11. L2 conformance catalog case set；
12. 安定化後の日本語規範翻訳。

## 22. Acceptance-criteria mapping（Issue #28）

| Issue #28 acceptance criterion | Draft coverage |
| --- | --- |
| L2 is a separate versioned layer, frozen Core 0.1 semantics unchanged | §1、§5、freeze policy |
| Integrity / authenticity / authority / freshness / ownership / authorization distinguished | §4、§8.2、§15、§16.3 |
| Signed / verifiable object defined | §7（payload、canonical serialization、signing bytes） |
| Signer identity and Entity authority rules defined | §8、§9 |
| Key discovery and rotation semantics defined | §10（discovery windows；§21 の OPEN items） |
| Freshness and anti-rollback defined | §11 |
| Ownership / authority transfer semantics defined | §12 |
| Revocation semantics defined | §13（revocation material resource は OPEN、§21） |
| Verification result states deterministic and documented | §15 |
| Relationship to Manifest 0.1 explicit | §16.2、§7.6 |
| Relationship to administrative authentication explicit | §16.3 |
| L1 downgrade / fallback explicit and fail-closed | §5、§6.1、§6.5、§17 |
| Security analysis covers replay, rollback, key compromise, conflicting authority, signature stripping | §17 |
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
    Core-only Resolver → 501, never L1

Signed object:
    strict JSON { payload, signature }
    signing target = canonical payload octets
    duplicate members forbidden
    l2Version = "0.1", statementType = "resolution"
    anchorId, entityId, descriptionLocation, lifecycleStatus,
    keyId, algorithm, issuedAt, expiresAt, generation

Authority:
    bound per Anchor UUID
    bootstrap = Trust Anchor Binding via policy channel
    rotation  = signed by prior-current authority, overlapping windows
    transfer  = explicit signed transfer, outgoing key signs, epoch floor
    revocation= signed by current authority, fail-closed
    one current binding per anchor; conflicts → CONFLICT

Keys:
    keyId = base64url(SHA-256(public key)[0..15])
    baseline MUST: ed25519; RECOMMENDED: ES256
    algorithm agility with fail-closed UNSUPPORTED_ALGORITHM
    no single permanent key

Freshness:
    [issuedAt, expiresAt], skew tolerance documented
    generation monotonic, epoch floors, replay/rollback → STALE
    no trusted clock → UNVERIFIED, never VERIFIED

Verifier states (deterministic, no boolean):
    VERIFIED | UNVERIFIED | INVALID | EXPIRED | REVOKED | STALE |
    UNKNOWN_AUTHORITY | UNSUPPORTED_ALGORITHM | CONFLICT | MISMATCH

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
