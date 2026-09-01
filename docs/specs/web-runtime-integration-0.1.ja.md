# RELink Web Runtime Integration Contract 0.1 日本語版

Status: Draft integration specification  
Version: 0.1  
Scope: Resolver Core 0.1 ↔ RELink Web Runtime integration

> この文書は `web-runtime-integration-0.1.md` の日本語版です。解釈に差異がある場合は英語版を基準とします。

## 1. 目的

本書は、RELink Web RuntimeがAnchor / Resolver URLから通常のWeb dereferenceを用いてAR-XMLへ到達する際のintegration contractを定義します。

```text
Anchor / Resolver URL
    ↓
ordinary HTTPS redirect(s)
    ↓
Resolver Core
    ↓ 303
Description Location
    ↓
optional HTTPS redirect(s)
    ↓
final AR-XML response
    ↓
Runtime parse / validate / expose capabilities
```

本contractはprotocol-facingな仕様であり、特定のTypeScript実装、browser framework、fetch library、内部class構造を規定しません。

## 2. Architecture boundary

Integrationは次の分離を維持しなければなりません。

```text
Resolution ≠ AR-XML parsing
Resolution ≠ Capability invocation
Manifest ≠ mandatory L1 load dependency
Integrity ≠ Authentication
Entity Identity ≠ Description Location
```

Resolver redirectを扱うためだけにResolver固有logicをAR-XML syntax、parser semantics、capability definition、invocation semanticsへ持ち込んではなりません。

Runtimeはdirect AR-XML URL、Anchor URL、Resolver URLのいずれから開始しても構いません。ただしL1 transport / network-policy要件を満たす必要があります。

## 3. Ordinary Web dereference model

Resolver integrationはResolver固有のout-of-band lookup protocolではなく、通常のHTTP/Web redirect semanticsを利用します。

```text
Anchor URL
→ ordinary 301/302/303/307/308 redirect(s)
→ Resolver URL
→ 303 See Other
→ Description Location
→ ordinary HTTPS redirect(s)
→ final AR-XML representation
```

Runtimeは最初に与えられたURLをAR-XML Document URLと仮定してはなりません。

少なくとも概念上、次を区別する必要があります。

```text
requested URL
final response URL
representation body
```

## 4. Final AR-XML response URL

Redirect処理後に最終的なAR-XML representationを取得したURLを **AR-XML Document URL** とします。

AR-XML内でrelative URLを解決する際は、このfinal URLをdocument baseとして使用しなければなりません。

特にrelative Interface endpointは、次をbaseにしてはなりません。

- original Anchor URL
- intermediate short URL
- Resolver URL
- Resolver `303` response URL
- intermediate Description redirect URL

例:

```text
Input:
https://resolver.example/relink/550e8400-e29b-41d4-a716-446655440000

Resolver:
303 Location: https://entity.example/descriptions/current.xml

Description redirect:
302 Location: https://cdn.example/entity/a/entity.xml

Final AR-XML Document URL:
https://cdn.example/entity/a/entity.xml

AR-XML relative Interface:
./actions/start

Resolved Interface URL:
https://cdn.example/entity/a/actions/start
```

## 5. ResourceFetcher boundary

IntegrationはRuntime既存のPorts-and-Adapters architectureにおけるresource-fetch boundaryへ適合させることを推奨します。

Document loadingに用いるfetch abstractionは、original requested URLとfinal response URLを区別できるだけのretrieval metadataをRuntime load pipelineへ提供しなければなりません。

概念上、fetch resultは少なくとも次を含みます。

```text
final response URL
representation body
```

OPTIONAL integrity verificationを実装する場合、Frozen Manifest 0.1のintegrity semanticsに必要なrepresentation octetsをcharacter decoding/XML parsing前に取得可能でなければなりません。

具体的なinterface signatureやdata structureは本contractでは規定しません。

## 6. Text decoding / AR-XML parsing

Manifest integrity verificationを使用しない場合、通常のHTTP/text decode pathを通じてfinal AR-XMLをXML parseへ渡して構いません。

Integrity verificationを有効にする場合は、Frozen Manifest 0.1のbyte semanticsを維持して次の順序にしなければなりません。

```text
HTTP dereference / redirects complete
↓
HTTP content-coding processing
↓
final representation body octets
↓  optional digest verification
character decoding
↓
XML parsing
↓
AR-XML validation
```

Digestをintermediate redirect body、decoded string、parsed XML tree、normalized XML、reserialized XMLに対して計算してはなりません。

## 7. Manifest independence

通常のResolver Core 0.1 L1 loadはManifest retrievalを要求してはなりません。

Baselineは次のままです。

```text
Anchor / Resolver URL
↓
303 / redirects
↓
AR-XML
↓
Runtime
```

Runtimeはrich metadataやintegrity verificationのためにOPTIONAL Manifest retrievalを実装しても構いません。ただしcallerがManifest必須policy/profileを明示していない限り、Manifest不在、未対応、endpoint failureによって正常なCore L1 dereferenceを無効化してはなりません。

普通のResolver Core resolutionでDescription Locationを取得できる場合、そのLocationを発見するためだけにManifestを取得してはなりません。

## 8. Optional integrity verification

RuntimeがFrozen Manifest 0.1 integrity verification supportをclaimする場合も、Manifest retrievalとAR-XML retrievalは別operationです。

```text
Manifest
    ↓
expected description.integrity

Description Location
    ↓
final AR-XML representation octets
    ↓
verification
```

Digest一致はcontent integrity / pinningのみを意味します。Entity authentication、Manifest authenticity、Resolver authority、ownership、freshness、anti-rollback、authorization、L2 achievementとして表示してはなりません。

Mismatchはintegrity verification failureとして公開しなければなりません。local policyがverified integrityを要求する場合、そのrepresentationをintegrity-verified inputとして後続AR-XML処理へ受理してはなりません。

Automatic capability invocationや特定Runtime APIは要求しません。

## 9. Network-security policy

Resolver/Manifestが提供するdestinationはuntrusted network inputです。

Runtimeまたはexecution environmentは、destinationをdereferenceする前またはその間に利用可能なnetwork-security controlを適用しなければなりません。

Successful Resolver `303`、Manifest parsing成功、valid `anchor.id`、valid `entity.id`、integrity verification成功をnetwork policy bypassの許可として扱ってはなりません。

Native/server adapterではHTTP stackが許す場合、network access前にredirect destinationやpolicy-denied targetを評価することを推奨します。

Browser adapterではFetch redirect handling、mixed-content、CORS/origin policy、browser network protection、および実装可能なRuntime policyに依存して構いません。

Loopback/private/local Description Locationをprotocol全体で禁止しません。Local Entity accessはdeployment/policy dependentです。

## 10. HTTPS / downgrade

Resolver Core 0.1 L1 processingをclaimするRuntimeはAnchor/Resolver/Description/final AR-XML chain全体でHTTPSを維持しなければなりません。

HTTPS→HTTP downgradeがどこかで発生した場合、L1 dereferencingを失敗させなければなりません。

Final AR-XML Document URLはHTTPSでなければなりません。

OPTIONAL Manifest retrievalをL1で行う場合、そのredirect chainとfinal Manifest URLも独立してFrozen Manifest 0.1 HTTPS要件を満たす必要があります。

## 11. Browser CORS

Resolver integrationはbrowser Fetch/CORS semanticsと互換でなければなりません。

Browser-oriented ResolverはCore仕様に従うpublic CORSを推奨しますが、ResolverへのCORS access成功はfinal AR-XML originへのaccess許可を意味しません。

各origin / redirect pathはbrowser platform enforcementの対象です。

本integrationのためにbrowser CORSを回避するcustom proxyやResolver-side AR-XML fetchを導入してはなりません。

## 12. Error boundary

RuntimeはResolver内部実装へ依存せず、少なくとも概念上次のfailure classを分離することを推奨します。

```text
network / transport failure
HTTPS downgrade failure
network-policy rejection
HTTP terminal failure
representation retrieval failure
integrity verification failure
XML parse failure
AR-XML validation failure
capability invocation failure
```

Resolver HTTP errorをAR-XML parse errorに変換してはなりません。

AR-XML validation errorをResolver failureとして表現してはなりません。

Integrity failureはauthentication/trust failureと分離します。

Concrete exception名やpublic API shapeは規定しません。

## 13. Runtime document URL

Load成功後、Runtimeのdocument-facing URLはfinal AR-XML Document URLを示すことを推奨します。

Relative Interface解決に使用するpublic/internal document URLは、caller-supplied Anchor/Resolver URLではなくfinal AR-XML response URLでなければなりません。

Original requested URLやredirect diagnosticsをobservability目的で別途保持しても構いませんが、AR-XML semanticsにおいてfinal document URLを置き換えてはなりません。

## 14. Capability invocation boundary

Capability discovery / invocationはAR-XML Runtimeの責務です。

Resolver Coreは次を行ってはなりません。

- capability選択
- capability endpoint構築
- capability invocation
- invocation用device current IP resolution
- AR-XML処理を代替するmanagement-console URL提供

Runtimeはrelative capability Interfaceをfinal AR-XML Document URLをbaseに解決し、その後通常のinvocation/network-policy logicを適用します。

## 15. Direct AR-XML compatibility

Resolver integrationによってdirect AR-XML URL supportを削除してはなりません。

両pathはparse前に同一pipelineへ収束することを推奨します。

```text
Direct AR-XML URL ───────────────┐
                                 ↓
Anchor/Resolver URL → redirects → final representation + final URL
                                 ↓
                           AR-XML parse/validate
```

Original inputがdirectかResolver-mediatedかに関係なく、parserへ渡すrepresentation semanticsは同一であることを推奨します。

## 16. Testbed expectations

実行可能integration testは `relink-testbed` または `relink-web-runtime` 側で実装し、本仕様リポジトリでは実装しません。

Handoffでは少なくとも次を扱うことを推奨します。

```text
RT-001 direct AR-XML load preserves behavior
RT-002 Resolver 303 load succeeds
RT-003 pre-Resolver HTTPS redirect succeeds
RT-004 post-Resolver HTTPS redirect succeeds
RT-005 final response URL becomes Runtime document URL/base
RT-006 relative Interface resolves against final URL
RT-007 HTTPS→HTTP downgrade fails
RT-008 configured network-policy denial prevents fetch
RT-009 Resolver CORS does not bypass final-origin CORS
RT-010 Manifest absence does not break baseline L1 load
RT-011 integrity match uses defined final body octets
RT-012 integrity mismatch is distinct from parse/validation failure
RT-013 intermediate redirect body is excluded from digest
RT-014 content-coding semantics match Frozen Manifest 0.1
```

これらはimplementation handoff用IDであり、Frozen Resolver / Manifest Conformance Catalog 0.1へのcase追加ではありません。

## 17. Implementation handoff requirements

後続の `relink-web-runtime` implementation taskでは、現在のdocument-loading resource portを見直し、load pipelineがfinal response URLを取得可能にする必要があります。

Fetch abstractionがdecoded textだけを返すモデルでは、追加retrieval metadataなしにfinal-response-URL semanticsを実装できません。

OPTIONAL integrity supportでは、character decoding/XML parsing前のfinal representation body octetsも必要です。

実装変更は適切なRuntime/application/adapter boundaryへ局所化し、AR-XML parserやcapability invokerへResolver固有parse behaviorを追加してはなりません。

## 18. Non-goals

本contractは次を定義しません。

- `relink-web-runtime` production code変更
- 新しいResolver protocol
- Resolver内部/DB behavior
- ManifestのL1必須化
- automatic capability execution
- L2 authentication/signature/ownership/key binding/Trust
- AR-XML syntax変更
- browser CORS bypass mechanism

## 19. Summary

```text
input URL
↓
ordinary HTTPS dereference + redirects
↓
network/platform policy
↓
final AR-XML response URL + representation
↓
optional integrity verification
↓
character decoding / XML parse
↓
AR-XML validation
↓
Runtime document
↓
relative Interface resolution against final AR-XML URL
↓
separate capability invocation
```

中心ルールは次です。

```text
Requested URL ≠ necessarily AR-XML Document URL
Final AR-XML response URL = AR-XML document base URL
```

これにより、Resolver integrationを通常Web semanticsへ沿わせつつ、RELinkの責務境界を維持します。