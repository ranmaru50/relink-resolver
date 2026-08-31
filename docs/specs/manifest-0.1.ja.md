# RELink Manifest 0.1 日本語版

Status: Frozen specification（公式日本語訳）  
Version: 0.1  
Freeze date: 2026-08-31  
Scope: Optional Entity-level resolution metadata

> この文書は Frozen 状態の `manifest-0.1.md` の公式日本語訳です。仕様上の要件語は英語版と同じ **MUST / SHOULD / MAY** 等を保持します。解釈に差異がある場合、英語版 Frozen 文書を Manifest 0.1 の normative source とします。

## 1. 目的

RELink Manifest は、RELink Anchor と Physical Entity に関連付けられる、コンパクトで機械可読な metadata representation を定義します。

Manifest は Resolver Core より豊富な metadata を保持しますが、AR-XML の capability description、Runtime execution、Trust processing に属する情報は担当しません。

責務分離は次のとおりです。

```text
Resolver Core = minimal resolution
Manifest      = richer Entity-level resolution metadata
Trust         = authentication / authenticity / authority
Runtime       = description consumption and execution
AR-XML        = Entity Interface Description
```

Manifest 0.1 は L1 resolution に対して OPTIONAL です。

適合する Resolver Core deployment は、Manifest を取得・解析せずに ACTIVE Anchor を現在の AR-XML Description Location へ直接 resolve できなければなりません。

```text
Anchor
↓
Resolver Core
↓ 303
AR-XML
```

Manifest の提供、取得、解析、validation、failure は、通常の Resolver Core 0.1 L1 resolution の前提条件になってはなりません。

### 1.1 Freeze状態と変更方針

RELink Manifest 0.1 は 2026-08-31 に Freeze されています。

本Versionで定義された required member model、wire-format semantics、retrieval semantics、extension rules、integrity semantics、lifecycle semantics、security boundaries は、implementation と conformance work の基準として安定したものと扱います。

Freeze後は次の方針を適用します。

- editorial correction と semantic changeを伴わないerrataは Manifest 0.1 に適用して MAY です。
- clarification は Frozen仕様が要求するinteroperable behaviorを変更しない場合に限り MAY です。
- standard member の追加・削除、required/optional status の変更、wire semantics、integrity semantics、security/trust semantics の変更は、後続Manifest versionまたは別途version管理されたprofileで定義しなければなりません。
- Manifest 0.1 Extension Policy と JSON Schema は Frozen 0.1 specification set の一部であり、本書と意味上整合していなければなりません。

英語版が normative source text です。日本語版との間に不一致がある場合は英語版 Frozen 文書を優先します。

## 2. 要件語

本書中の **MUST**, **MUST NOT**, **REQUIRED**, **SHALL**, **SHALL NOT**, **SHOULD**, **SHOULD NOT**, **RECOMMENDED**, **NOT RECOMMENDED**, **MAY**, **OPTIONAL** は、すべて大文字で記載される場合に限り、BCP 14（RFC 2119 / RFC 8174）に従って解釈します。

## 3. 用語

### 3.1 Anchor UUID

Resolver lookup keyとして使用するRFC 9562 UUIDです。

Anchor UUID は RELink resolution record を識別します。credentialではなく、authentication または authorization material として扱ってはなりません。

### 3.2 Canonical Entity Identity

Manifestが記述するEntityを識別する、location-independentでstableなURIです。

Canonical Entity Identity が答えるのは、

```text
What Entity is this?
```

です。次の問いには答えません。

```text
Where is its current AR-XML document?
```

### 3.3 Description Location

EntityのAR-XML descriptionを現在取得することが期待されるHTTPS URLです。

```text
Where is the current description?
```

を表し、Canonical Entity Identityを変えずに変更可能です。

### 3.4 Manifest Resource

本仕様に適合するrepresentationを提供するHTTP resourceです。

Manifest Resourceは次と異なるものです。

- Physical Entity
- Resolver Resource
- Canonical Entity Identity
- AR-XML document

## 4. Core invariants

適合実装は次の分離を MUST で保持します。

```text
Entity Identity ≠ Resolver URL
Entity Identity ≠ Description Location
Resolver Core   ≠ Manifest
Manifest        ≠ Trust
Manifest        ≠ AR-XML
Description     ≠ Execution
```

`description.location` の変更は `entity.id` の変更を要求してはなりません。

Resolver deployment URL の変更も `entity.id` の変更を要求してはなりません。

Manifest は AR-XML に属する executable capability semantics を含んではなりません。

本仕様の optional integrity や operational-hardening feature は、authentication、authority proof、より高いRELink security level達成の証拠として解釈してはなりません。

## 5. JSON representation

Manifest 0.1 は JSON object であり、公開/wire representation は標準JSON構文に MUST で適合します。

JSON5および他のJSON互換authoring syntaxは、適合するManifest 0.1 wire representationではありません。コメント、trailing comma、single-quoted string、unquoted member name等のJSON5固有構文を公開Manifestが要求してはなりません。

実装はローカルauthoring形式としてJSON5、YAML等を受理して MAY ですが、公開Manifestは適合JSONへserializeされなければなりません。

```text
Authoring format
= implementation-defined

Published Manifest representation
= strict JSON
```

baseline Manifest 0.1 Consumer は conforming JSON のみをparseできればよく、JSON5等のauthoring syntax実装を要求されません。

### 5.1 Duplicate object-member names

適合するManifest 0.1 wire representationは、同一JSON object内にduplicate member nameを含んではなりません。

この禁止はtop-level、`anchor`、`entity`、`description`、`description.integrity`、`lifecycle`、`extensions`、extension内部objectを含むすべてのJSON objectに適用します。

Consumerはduplicate object-member nameを含むManifestを MUST でrejectします。first-wins、last-wins、merge、overwrite、implementation-specific behaviorで曖昧性を解決してはなりません。

これはvalidator、Runtime、administrative tool、将来Trust processing間のparser differentialを防止するためです。

最小適合representationは次のとおりです。

```json
{
  "manifestVersion": "0.1",
  "anchor": {
    "id": "550e8400-e29b-41d4-a716-446655440000"
  },
  "entity": {
    "id": "https://identity.example/entities/12345"
  },
  "description": {
    "location": "https://entity.example/arxml/entity.xml"
  },
  "lifecycle": {
    "status": "active"
  }
}
```

REQUIRED top-level member:

- `manifestVersion`
- `anchor`
- `entity`
- `description`
- `lifecycle`

`extensions` objectは OPTIONAL です。

`description.mediaType`、`description.integrity`を含むoptional memberはbaseline Manifest 0.1またはResolver Core 0.1 interoperabilityの前提条件になってはなりません。

## 6. `manifestVersion`

`manifestVersion` は JSON string `"0.1"` で MUST です。

Manifest 0.1 Consumer は別の `manifestVersion` を0.1として解釈してはなりません。

Manifest 0.1はSemantic Versioning semanticsを使用しません。

後続仕様がcompatibility relationshipを定義することは可能ですが、Manifest 0.1 Consumerはunsupported versionを推測せずvalidation failureとしなければなりません。

unknown non-critical memberは§12に従い無視して MAY ですが、unsupported `manifestVersion` は無視可能extensionではありません。

## 7. `anchor`

`anchor` は `id` を含むobjectでなければなりません。

`anchor.id` は:

- RFC 9562 UUID textual representationで MUST
- Manifestが関連付けられたResolver recordを識別して MUST
- lowercase hexadecimalでserializeすることを SHOULD
- opaque identifierとして扱って MUST
- password、bearer credential、access token、capability token、authentication proof、authorization proofとして扱っては MUST NOT

Deterministic Manifest endpointから取得した場合、responseの`anchor.id`はrequest pathのUUIDと同じUUID valueを識別しなければなりません。不一致はinvalid Manifestです。

Anchor-path consistencyはconsistency checkにすぎず、Manifest、Resolver authority、Physical Entity、representation供給者をauthenticateしません。

## 8. `entity`

`entity` は `id` を含むobjectでなければなりません。

`entity.id` は Canonical Entity Identity です。

`entity.id` は:

- absolute URIで MUST
-通常のDescription Location変更をまたいでstableで MUST
-慣例によってcurrent AR-XML Description Locationそのものとして扱っては MUST NOT
-Resolver Resource URLそのものとして扱っては MUST NOT
-別仕様が明示しない限りoperational endpointとして解釈しては MUST NOT
-別仕様がverification semanticsを定義しない限りuntrusted identifier dataとして扱って MUST

`entity.id` はidentifierであり、dereference instructionではありません。

URI schemeがdereference可能という理由だけでConsumerが`entity.id`をdereferenceしてはなりません。dereferenceには独立した仕様または明示的consumer policyが必要です。

Manifest 0.1はCanonical Entity IdentityのURI schemeを固定しません。`urn:uuid:`を要求せず、Anchor UUIDからの導出も要求しません。

これにより、複数Anchorが同一Entityを指す構成、Resolver service移行、将来identity modelを許容できます。

## 9. `description`

`description` は `location` を含むobjectでなければなりません。

`description.location` は:

- syntactically valid absolute URIで MUST
- Manifest 0.1 L1では `https` schemeで MUST
- current AR-XML Description Locationを識別して MUST
- `entity.id`を維持したまま変更して MAY
- Consumerからuntrusted network inputとして扱って MUST
- targetがsafe/authenticated/authorized/public/non-localであることを意味しては MUST NOT

同一Resolver recordからACTIVE Manifestを生成する場合、`description.location`は同じlogical stateでResolver Coreが返すcurrent Description Locationと一致しなければなりません。

このResolver/Manifest consistencyはauthenticationではありません。

Manifest生成にAR-XMLのfetch/parseを要求してはなりません。

### 9.1 `description.mediaType`

既知のmedia typeがある場合、実装は`description.mediaType`を含めて MAY です。

Manifest 0.1は専用AR-XML media typeを定義・登録せず、特定値を要求しません。

Consumerは`description.mediaType`をAR-XML validationの代替として扱ってはなりません。

### 9.2 `description.integrity`

content pinningに適したDescriptionについて、Manifestはoptional content-integrity metadataを含めて MAY です。

```json
{
  "description": {
    "location": "https://entity.example/arxml/entity.xml",
    "integrity": {
      "algorithm": "sha-256",
      "digest": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
    }
  }
}
```

`description.integrity`が存在する場合、`algorithm`と`digest`の両方を含むobjectでなければなりません。

`description.integrity`は OPTIONAL です。AR-XML representationがdynamic、personalized、requestごとに生成される等、stable content pinningに不適切な場合は省略を SHOULD します。

```text
description.location
= current descriptionをどこから取得するか

description.integrity
= Manifestがどのrepresentation contentを期待するか
```

この情報は誰がAR-XMLをauthor/approve/own/modifyする権限を持つかを示しません。

#### 9.2.1 `algorithm`

`algorithm` はdigest algorithm名を表すnon-empty lowercase ASCII identifierで MUST です。

Manifest 0.1は初期interoperable identifierとして`sha-256`を定義し、integrity metadataを公開する場合はこれを RECOMMENDS します。

後続仕様/profileが追加algorithmを定義することを妨げません。0.1 Consumerは将来algorithmのすべてを実装する必要はありません。

algorithm identifierはauthentication mechanismやsecurity-level indicatorではありません。

#### 9.2.2 `digest`

`digest` は`algorithm`が生成したdigest byteのlowercase hexadecimal encodingで MUST です。

`sha-256`の場合は64文字のlowercase hexadecimalでなければなりません。

Digest対象は、redirect chain完了後のfinal successful Description responseについて、ConsumerのHTTP/fetch stackがHTTP content-coding処理を終えた後、character decoding・XML parsing・application-level normalizationの前に公開するrepresentation body octetsです。

```text
HTTP request
↓
redirect processing
↓
final successful response
↓
HTTP content-coding processing
↓
representation body octets  ← digest input
↓
character decoding
↓
AR-XML parsing
```

final responseより前のredirect response bodyをdigest inputに使用してはなりません。

Digest定義はXML canonicalizationを要求しません。HTTP stackによって除去されるtransfer framingやencoded wire bytesはdigest inputではありません。

#### 9.2.3 Consumer behavior

`description.integrity`を実装しないbaseline Consumerはfieldを無視して MAY で、Manifest 0.1適合のままです。

Manifest 0.1 integrity verification supportをclaimするConsumerは:

- unknown/unsupported `algorithm`をverifiedと報告しては MUST NOT
- verification時は§9.2.2のfinal representation body octetsにdigestを計算して MUST
- digest mismatchをintegrity verification failureとして扱って MUST
- policyがintegrity verificationを要求する場合、mismatch representationでAR-XML capability discovery/invocationを継続しては MUST NOT
- unsupported algorithmをManifest全体invalidではなくunverifiableとして扱って MAY

`description.integrity`の存在またはverification成功は次を意味してはなりません。

- Physical Entity authentication
- owner authentication
- Resolver authentication
- Manifest authenticity proof
- authorization
- higher RELink security level proof
- freshness proof
- replay protection
- rollback protection
- future L2 / Trust semanticsの代替

古いManifest/Description pairが古いManifestのdigestに一致する場合、integrity verificationだけではreplay/rollbackを防げません。

Producer/admin systemはAR-XML publish時にdigestを計算・登録して MAY です。Resolver Coreやpublic Manifest retrievalがAR-XMLをfetchして計算・更新・verifyすることを要求してはなりません。

## 10. `lifecycle`

`lifecycle` は `status` を含むobjectで MUST です。

許可値:

```text
active
suspended
retired
```

Resolver Core lifecycleとの対応:

```text
ACTIVE    ↔ active
SUSPENDED ↔ suspended
RETIRED   ↔ retired
```

`lifecycle.status` はdescriptive metadataです。transitionをauthorizeせず、actor/authorityを識別しません。

reason、timestamp、actor、audit history、ownership state、transition authorizationはrequired Manifest 0.1 modelの外です。

JSON modelが3状態すべてを許可するのは、administrative export、archive、testing、future distribution profile等でもManifest representationを使用できるためです。public L1 retrievalでは通常`200 OK`で取得できるManifestはACTIVE recordを表し、SUSPENDED/RETIREDは§11のHTTP behaviorに従います。

## 11. Manifest retrieval

### 11.1 Deterministic endpoint と transport security

public Manifest 0.1 retrievalを提供するResolver deploymentは次を SHOULD で公開します。

```text
GET /{resolver-service}/{uuid}/manifest
```

Resolver Core resource:

```text
GET /{resolver-service}/{uuid}
```

とは別resourceです。

Resolver Core resourceは通常のCore semanticsを保持し、AR-XML resolutionとManifest retrievalの選択にcontent negotiationを要求してはなりません。

Manifest 0.1 L1 retrieval は HTTPS を MUST で使用します。

L1 Manifest retrievalを行うConsumerは、Manifest retrieval redirect chain全体でHTTPS→HTTP downgradeを防止またはrejectしなければなりません。

L1 processingに使用するfinal Manifest representationはHTTPS-only redirect chainを通り、final HTTPS URLから取得されていなければなりません。

browser Consumerがapplication codeで全redirect targetを事前inspectionできることを要求するものではありません。platform/browserのredirect、mixed-content、origin、Fetch等のsecurity controlを使用して MAY です。

HTTPS transport自体はPhysical Entity、ownership、Manifest authority（通常Web origin authenticationを超えるもの）、高いRELink security levelを証明しません。

### 11.2 ACTIVE

ACTIVE recordでpublic Manifestがある場合:

```http
200 OK
Content-Type: application/json
```

を SHOULD で返し、conforming Manifest representationを含めます。

### 11.3 SUSPENDED

SUSPENDED recordはpublic endpointで `404 Not Found` を SHOULD で返します。unknownとSUSPENDEDのpublic non-distinctionを維持します。

### 11.4 RETIRED

RETIRED recordは `410 Gone` を SHOULD で返します。

Retired Manifestをadministration/audit/export/archive目的で内部保持して MAY です。

### 11.5 Unknown UUID

unknown UUIDは `404 Not Found` を SHOULD で返します。

### 11.6 Manifest absence

Resolver Core 0.1 deploymentはManifest endpointを公開しなくても MAY です。

Manifest取得failureは成功しているResolver Core L1 resolutionをinvalidにしてはなりません。

## 12. Extension と unknown-member rules

Manifest 0.1 は extensible JSON です。

Consumerは:

- required 0.1 memberとsemanticsを MUST validate
- 理解しないunknown memberを SHOULD ignore
- unknown memberをsecurity-critical evidenceとして扱っては MUST NOT
- unknown memberでdefined 0.1 semanticsをoverrideしては MUST NOT

unknown top-level memberは主として将来のRELink-defined Manifest versionまたはcompatible standard additionとのforward compatibility用です。

vendor/product/deployment/experimental metadataは新規top-level memberではなくtop-level `extensions` 配下に置くことを SHOULD します。

required 0.1 memberの意味を変えるextensionはcompatible extensionではなく、後続Manifest specificationが必要です。

older conforming Consumerがignoreするextensionにsecurity-critical behaviorを依存させてはなりません。

詳細は Frozen `manifest-0.1-extension-policy.md` に従います。

### 12.1 `extensions`

vendor-specific、experimental、deployment-specific、profile-specific metadataにoptional top-level `extensions` objectを使用して MAY です。

extension nameはcollisionを避けるため、extension producerがcontrolするURI-likeまたはreverse-domain identifierを SHOULD で使用します。

```json
{
  "extensions": {
    "com.example.relink/device": {
      "model": "RX-100"
    }
  }
}
```

Manifest 0.1はいかなるextension fieldにもTrust semanticsを付与しません。

## 13. Content-Type

Manifest 0.1 representationは次を SHOULD で使用します。

```http
Content-Type: application/json
```

`application/json`としてserveするrepresentationはconforming JSONでなければならず、JSON5 syntaxに依存してはなりません。

Manifest 0.1は未登録RELink-specific media typeを主張しません。

後続仕様はdedicated structured-syntax-suffix media typeを定義して MAY ですが、Manifest version変更なしに0.1 member semanticsを変更してはなりません。

## 14. Cache、change detection、CORS guidance

public Manifest endpointはexplicit cache policyを SHOULD で送ります。

ACTIVE reference default:

```http
Cache-Control: public, max-age=60
```

`404`, `400`, `500`, `503` reference default:

```http
Cache-Control: no-store
```

`410 Gone`はretirementがterminalなためfinite cache lifetimeを使用して MAY です。

Manifest endpointは`ETag`/`Last-Modified`等の通常HTTP validatorを提供して MAY です。

これらvalidatorは通常HTTP semanticsを維持し、Entity authentication、Manifest signature、authority/freshness proof、RELink security-level indicatorとして解釈してはなりません。またManifest 0.1 conformanceの必須条件ではありません。

browser-oriented public endpointはcross-origin retrievalを意図する場合 `Access-Control-Allow-Origin: *` を SHOULD で返します。

## 15. Consumer network-security boundary

Manifest metadataはnetwork authorization decisionではありません。

`description.location`はuntrusted network inputです。

ConsumerはManifest-supplied Description Locationをdereferenceする前または途中で、execution environmentで利用可能なnetwork-security controlを MUST 適用します。

native/serverではloopback、local-network、link-local、metadata endpoint、DNS rebinding、redirect、allow/deny list等のRuntime policyを利用できます。

browserではFetch、CORS、mixed-content restriction、platform policy等を利用できます。

```text
Manifest
↓
Description Location
↓
Consumer / Platform Network Policy
↓
Fetch
```

Manifest retrieval成功はDescription Locationがsafe/authorizedであることを意味しません。

## 16. Trust と security boundary

Manifest 0.1 は metadata であり Trust protocolではありません。

Manifest 0.1は次を提供しません。

- Physical Entity authentication
- owner authentication
- Runtime authentication
- ordinary HTTPS origin authenticationを超えるResolver authority proof
- Manifest signature verification
- AR-XML signature verification
- key ownership proof
- trust-chain validation
- freshness / anti-rollback proof
- ownership transfer
- Resolver state mutation authorization
- capability authorization / execution

Consumerは`entity.id`、`anchor.id`、`description.location`、`description.integrity`、`lifecycle.status`の存在をこれらの証明として扱ってはなりません。

将来Trust/L2 specificationはManifest fieldを参照・追加して MAY ですが、verificationとfailure semanticsを別途定義しなければなりません。

`description.integrity`はcontent pinning/change detectionに限定され、将来のsignature、authenticated key binding、certificate、Web-native authentication等を制約してはなりません。

## 17. Resource-consumption guidance

Manifestはuntrusted structured inputで、unknown memberやextension dataを含む可能性があります。

Consumerはimplementationに適したfinite limitを SHOULD 設けます。例:

- response body size
- JSON nesting depth
- object-member / array-element count
- string length
- parsing time / memory

Producer/Resolverは不要に巨大・深いpublic Manifestを生成しないことを SHOULD します。

環境差が大きいため0.1はuniversal numeric limitを規定しません。Consumerはdocumented limit超過representationをrejectして MAY であり、そのこと自体でAnchorをinvalidとみなす必要はありません。

## 18. Optional pre-L2 operational hardening

正式L2/Trust protocol導入前に、deploymentは通常のoperational controlを選択的に使用して MAY です。これらはdeployment guidanceでありbaseline public resolution contractを変更してはなりません。

### 18.1 Administrative authentication

administrative create/update/suspend/retire/metadata-management surfaceはpublic L1とは独立したdeployment-appropriate authentication/authorizationを SHOULD で使用します。

Manifest 0.1は特定のWeb authentication technology、IdP、credential、session、MFA、certificate、authorization frameworkを規定しません。

Anchor UUIDを知っているだけでadministrative mutationをauthorizeしてはなりません。

### 18.2 Audit history

可能な範囲で、Description Location、optional integrity metadata、lifecycle state、Canonical Entity Identity mapping等のmaterial record changeについてbounded operational historyを SHOULD で保持します。

storage format、retention、actor identity model、immutability、external logging systemはdeployment choiceです。

Manifest 0.1はappend-only ledger、transparency log、blockchain、third-party timestamp service等を要求しません。

### 18.3 Origin と privilege separation

public Resolver/Manifest serving privilegeとadministrative mutation privilegeを分離することを SHOULD 検討します。

運用上妥当ならAR-XML hostingとResolver administrationを分離して MAY です。

distinct DNS name、hosting provider、CA、network、process、productは必須ではありません。

### 18.4 Low-dependency design rule

baseline Manifest 0.1 conformanceのためだけにproprietary crypto、licensed authentication product、external trust service、specialized hardware、patent-dependent mechanismを要求してはなりません。

operatorが独立に選択するoptional product/serviceは使用して MAY ですが、Manifest 0.1 wire semanticsを再定義したり、他実装の前提条件にしてはなりません。

## 19. Resolver / Manifest responsibility boundary

Resolver Coreがresolutionに必要として内部保持する最小情報:

```text
Anchor UUID
Lifecycle status
Current Description Location
```

Manifestが公開し得るより豊富なEntity-level view:

```text
Anchor UUID
Canonical Entity Identity
Current Description Location
Optional Description integrity metadata
Lifecycle metadata
Version information
Extensions
```

ManifestはResolver CoreにAR-XML capability、inputs/results、device current IP、management UI、command endpoint、invocation state、Trust verification resultの理解を要求してはなりません。

Manifest generationはDescription Locationをdereferenceせずに可能であることを SHOULD します。

## 20. AR-XML boundary

AR-XMLはEntityが何をできるか、Runtimeがどうinteractionするかを記述します。

Manifestはidentity/location/lifecycle metadataを記述し、optionalにrepresentation digestを提供できます。

Manifest 0.1はAR-XML capability definition、interface endpoint、input schema、result mapping、invocation semanticsを複製してはなりません。

`description.integrity`はretrieved representation bytesに作用し、AR-XML syntax、semantics、canonicalization、generation method、capability modelの変更を要求しません。

```text
Manifest
  entity.id
  description.location
  description.integrity (optional)
        ↓
      AR-XML
        ↓
  Capability / Interface description
```

## 21. JSON Schema の役割

付属JSON Schemaはmachine-readable validation aidであり、Manifest 0.1 conformanceの完全なnormative definitionではありません。

JSON Schema実装により`uuid`や`uri` formatをassertionとして強制するかannotationとして扱うかが異なるため、schema validator successだけではManifest 0.1 conformanceを証明しません。

Consumer/validatorはRFC 9562 UUID、absolute URI、HTTPS、duplicate-member rejection、lifecycle、integrity、security boundary等のnormative semantic checkを別途 MUST 適用します。

JSON Schemaとnormative textが矛盾する場合、修正されるまでnormative textを優先します。

## 22. Reference examples

### 22.1 Minimal Manifest

```json
{
  "manifestVersion": "0.1",
  "anchor": {
    "id": "550e8400-e29b-41d4-a716-446655440000"
  },
  "entity": {
    "id": "https://identity.example/entities/thermostat-42"
  },
  "description": {
    "location": "https://entity.example/descriptions/thermostat-42.arxml"
  },
  "lifecycle": {
    "status": "active"
  }
}
```

### 22.2 Optional content pinning付きManifest

```json
{
  "manifestVersion": "0.1",
  "anchor": {
    "id": "550e8400-e29b-41d4-a716-446655440000"
  },
  "entity": {
    "id": "https://identity.example/entities/thermostat-42"
  },
  "description": {
    "location": "https://entity.example/descriptions/thermostat-42.arxml",
    "integrity": {
      "algorithm": "sha-256",
      "digest": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
    }
  },
  "lifecycle": {
    "status": "active"
  }
}
```

これらの例は`entity.id`がdereference可能/operationalであることを意味しません。

2つ目の例もauthentication/freshnessを意味せず、Manifestが宣言したexpected AR-XML representation contentをpinするだけです。

## 23. Conformance

**RELink Manifest 0.1 conformance**をclaimするrepresentationは MUST で:

1. §5のconforming JSON syntaxを使用
2. duplicate object-member nameを含まない
3. `manifestVersion` = `"0.1"`
4. `anchor.id` = RFC 9562 UUID
5. `entity.id` = absolute URI、identifier-only semanticsを保持
6. `entity.id`をResolver URL/current Description Locationと意味上分離
7. L1では`description.location` = absolute HTTPS URI
8. `lifecycle.status` = `active | suspended | retired`
9. Anchor UUID knowledgeをauthentication/authorizationとして使用しない
10. capability execution、device discovery、management UI、Trust verificationをManifest 0.1 semanticsにしない
11. Resolver Core 0.1 L1成功に対してManifestをoptionalのまま維持

JSON5はconforming wire representationではありません。ローカルauthoring toolはJSON5等を受け入れて MAY ですが、公開時はconforming JSONを MUST 出力します。

`description.integrity`が存在する場合、§9.2の`algorithm`と`digest`を含まなければなりません。integrityはManifest 0.1 conformanceの必須条件ではありません。

baseline Consumerはunsupported `manifestVersion`とduplicate memberをrejectし、required/normative semantic constraintをvalidateし、HTTPS-only Manifest retrievalを維持し、`description.location`と`entity.id`をuntrusted-data boundaryに従って扱わなければなりません。JSON5やoptional integrity verificationの実装は必須ではありません。

**Manifest 0.1 integrity-verification support**をclaimするConsumerは§9.2.3を満たさなければなりません。

## 24. Design summary

```text
Manifest 0.1
= optional Entity-level resolution metadata

Status:
    frozen 2026-08-31
    semantic changes require later version/profile

Wire format:
    strict JSON
    duplicate member names forbidden
    JSON5 not conforming
    local authoring format implementation-defined

L1 Manifest retrieval:
    HTTPS-only redirect chain
    no HTTPS → HTTP downgrade

Required:
    manifestVersion = "0.1"
    anchor.id        = UUID
    entity.id        = absolute URI identifier
    description.location = HTTPS URI
    lifecycle.status = active | suspended | retired

Optional:
    description.mediaType
    description.integrity
        algorithm
        digest

Integrity:
    final representation content pinning / change detection
    after redirect + HTTP content-coding processing
    before character decoding / XML parsing
    not authentication
    not freshness
    not anti-rollback
    not authorization
    not L2

Canonical Entity Identity:
    stable
    location-independent
    untrusted identifier data
    not a dereference instruction
    scheme not fixed by 0.1

Default retrieval:
    GET /{resolver-service}/{uuid}/manifest

Content-Type:
    application/json

ACTIVE public retrieval:
    200 JSON
SUSPENDED / unknown:
    404
RETIRED:
    410

Schema:
    validation aid only
    normative text governs semantic conformance

Core rule:
    Manifest failure MUST NOT break ordinary L1 resolution

Not Manifest responsibility:
    Trust
    authentication protocol
    authorization protocol
    signatures
    freshness / anti-rollback
    ownership transfer
    device IP discovery
    management UI
    capability execution
    AR-XML capability semantics
```

Manifest 0.1は、Entity identityがstableでDescription Locationは変更可能というRELink原則を維持しつつ、Resolver Coreをmetadata/Trust/execution serviceへ拡大せず、将来AR-XML、L2、Trust、Web authentication designを制約しない形でoptional integrityとlow-cost operational hardeningを提供します。
