# RELink Web Runtime Integration Contract 0.1 日本語版

Status: Frozen integration specification  
Version: 0.1  
Freeze date: 2026-09-01  
Scope: Resolver Core 0.1 ↔ RELink Web Runtime integration

> この文書は `web-runtime-integration-0.1.md` の公式日本語版です。解釈に差異がある場合は英語Frozen文書を基準とします。

Web Runtime Integration Contract 0.1 はFrozenです。編集上または非semanticなerrataは0.1内で修正できます。Final URL semantics、retrieval ordering、Manifest association/integrity semantics、network-policy semantics、error boundary、L0/L1 classification、RT handoff expectationの変更は、後続contract versionまたは別途versioningされたprofileで扱います。

## 1. 目的

本書は、RELink Web RuntimeがAnchor / Resolver URLから通常のWeb dereferenceを用いてAR-XMLへ到達する際のintegration contractを定義します。

```text
Anchor / Resolver URL
    ↓
policy-controlled HTTPS redirect(s)
    ↓
Resolver Core
    ↓ 303
Description Location
    ↓
policy-controlled HTTPS redirect(s)
    ↓
final successful AR-XML response
    ↓
Runtime parse / validate / expose capabilities
```

本contractはprotocol-facingな仕様であり、特定のTypeScript実装、browser framework、fetch library、内部class構造を規定しません。

## 2. Architecture boundaryとL0/L1

Integrationは次の分離を維持しなければなりません。

```text
Resolution ≠ AR-XML parsing
Resolution ≠ Capability invocation
Manifest ≠ mandatory L1 load dependency
Integrity ≠ Authentication
Entity Identity ≠ Description Location
```

Resolver redirectを扱うためだけにResolver固有logicをAR-XML syntax、parser semantics、capability definition、invocation semanticsへ持ち込んではなりません。

Direct AR-XML loadはdirect/L0 pathです。Resolver-mediated loadはL1 integration pathです。

```text
L0 / direct
Direct AR-XML URL
    ↓
final AR-XML representation

L1 / Resolver-mediated
Anchor / Resolver URL
    ↓
Resolver / redirects
    ↓
final AR-XML representation
```

両者はfinal representation boundaryで合流します。この区別のために特定のRuntime APIや内部mode flagを要求しません。

## 3. Ordinary Web dereference model

Resolver integrationはResolver固有のout-of-band lookup protocolではなく、通常のHTTP/Web redirect semanticsを利用します。

```text
Anchor URL
→ ordinary 301/302/303/307/308 HTTPS redirect(s)
→ Resolver URL
→ 303 See Other
→ Description Location
→ ordinary HTTPS redirect(s)
→ final successful AR-XML representation
```

Runtimeは最初に与えられたURLをAR-XML Document URLと仮定してはなりません。

少なくとも概念上、次を区別する必要があります。

```text
requested URL
final response URL
terminal HTTP status
representation body
```

## 4. Final AR-XML response URL

Redirect処理後に最終的なAR-XML representationを正常取得したURLを **AR-XML Document URL** とします。

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

Document loadingに用いるfetch abstractionは、original requested URL、final response URL、terminal HTTP statusを区別できるだけのretrieval metadataをRuntime load pipelineへ提供しなければなりません。

概念上、fetch resultは次を含みます。

```text
requested URLまたは同等のrequest context
final response URL
terminal HTTP status
representation body
```

OPTIONAL integrity verificationを実装する場合、Frozen Manifest 0.1のintegrity semanticsに必要なrepresentation octetsをcharacter decoding/XML parsing前に取得可能でなければなりません。

具体的なinterface signatureやdata structureは本contractでは規定しません。

## 6. Retrieval success、representation bytes、parse順序

Terminal non-success HTTP responseはHTTP/retrieval failureとして処理しなければならず、successful Description representationであるかのようにAR-XML parserへ渡してはなりません。

AR-XMLまたはdeployment ruleがrepresentation/media compatibility checkを要求する場合、そのcheckはAR-XML inputとして受理する前に行わなければなりません。

Manifest integrity verificationを使用しない場合、successful final AR-XML representationを通常のHTTP/text decode pathからXML parseへ渡して構いません。

Integrity verificationを有効にする場合は、次の順序を維持しなければなりません。

```text
policy-controlled HTTP dereference / redirects
↓
terminal successful HTTP response
↓
representation/media compatibility checks where applicable
↓
HTTP content-coding processing
↓
final representation body octets
↓
digest verification
↓
character decoding
↓
XML parsing
↓
AR-XML validation
```

Digestをintermediate redirect body、decoded string、parsed XML tree、normalized XML、reserialized XMLに対して計算してはなりません。

Digest verificationに使用した**同一のrepresentation octets**を、その後character decodeしAR-XMLとしてparseしなければなりません。あるfetch結果をverifyした後、同じverification resultのまま別representationをsilentに再fetch・置換してparseしてはなりません。

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

通常のResolver Core resolutionでDescription Locationを取得できる場合、そのLocationを発見するためだけにManifestを取得してはなりません。

本contractは、任意のAnchor redirect chainからManifestを暗黙発見する方法を定義しません。またfinal AR-XML URLから逆算してManifestを発見する方法も定義しません。

Manifest processingを選択する場合、Manifest source/associationは明示的であるか、別の適用profileで定義されなければなりません。例としてexplicit Manifest URL、known Resolver resource、caller-provided association metadataがあります。

## 8. Optional Manifest integrity verificationとbinding

RuntimeがFrozen Manifest 0.1 integrity verification supportをclaimする場合も、Manifest retrievalとAR-XML retrievalは別operationです。

```text
Manifest retrieval
↓
Manifest 0.1 baseline validation
↓
applicable association/binding checks
↓
expected description.integrity

Description retrieval
↓
final AR-XML representation octets
↓
verification
```

Integrity metadataは、applicableなManifest 0.1 baseline validationとassociation checkに成功したManifestからのみ使用しなければなりません。

Deterministic Resolver Manifest retrievalでは、Frozen Manifest 0.1で定義されたpath UUID / `anchor.id` consistencyを満たした後でなければ、そのManifestのintegrity metadataへ依存してはなりません。

選択したintegration profileまたはcallerがManifestとDescription loadのassociationを提供する場合、そのassociationは該当profile/policyに従ってvalidateしてからManifest integrity metadataを適用しなければなりません。本contractはarbitrary redirect chain向けのimplicit association ruleを新設しません。

Digest一致はcontent integrity / pinningのみを意味します。Entity authentication、Manifest authenticity、Resolver authority、ownership、freshness、anti-rollback、authorization、L2 achievementとして表示してはなりません。

Mismatchはintegrity verification failureとして公開しなければなりません。local policyがverified integrityを要求する場合、そのrepresentationをintegrity-verified inputとして後続AR-XML処理へ受理してはなりません。

Automatic capability invocationや特定Runtime APIは要求しません。

## 9. Network-security policyとdereference順序

Resolver/Manifestが提供するdestinationはuntrusted network inputです。

Execution environmentが制御を提供する範囲で、network/platform policyは各dereferenceの前またはその最中に適用しなければなりません。

```text
input URL
↓
network/platform policy
↓
HTTPS dereference
↓
redirect target
↓
network/platform policy
↓
next HTTPS dereference
...
↓
final response
```

Successful Resolver `303`、Manifest parsing成功、valid `anchor.id`、valid `entity.id`、integrity verification成功をnetwork policy bypassの許可として扱ってはなりません。

Native/server adapterではHTTP stackが許す場合、network access前にredirect destinationやpolicy-denied targetを評価することを推奨します。

Browser adapterではFetch redirect handling、mixed-content、CORS/origin policy、browser network protection、および実装可能なRuntime policyに依存して構いません。Browser platformがredirect targetをapplication codeへ公開しない場合、そのtargetのinspectionをapplication codeへ必須とはしません。

Loopback/private/local Description Locationをprotocol全体で禁止しません。Local Entity accessはdeployment/policy dependentです。

## 10. HTTPS / downgrade

Resolver Core 0.1 L1 processingをclaimするRuntimeはAnchor/Resolver/Description/final AR-XML chain全体でHTTPSを維持しなければなりません。

HTTPS→HTTP downgradeがどこかで発生した場合、L1 dereferencingを失敗させなければなりません。

Final AR-XML Document URLはHTTPSでなければなりません。

OPTIONAL Manifest retrievalをL1で行う場合、そのredirect chainとfinal Manifest URLも独立してFrozen Manifest 0.1 HTTPS要件を満たす必要があります。

## 11. Public L1 credentialsとBrowser CORS

Baseline public Resolver Core L1 resolutionはcredentialを要求してはなりません。Baseline public Manifest retrievalもManifest 0.1 interoperabilityのためだけにcredentialを要求してはなりません。

Callerまたはdeployment policyが明示的に選択していない限り、public Resolver/Manifest requestへcookie等のambient credentialを付与しないことを推奨します。

このguidanceはDescription retrievalや将来のauthenticated profileへ一律のcredential ruleを課しません。

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
representation/media compatibility failure
representation retrieval failure
integrity verification failure
XML parse failure
AR-XML validation failure
capability invocation failure
```

Terminal non-success HTTP responseはHTTP/retrieval failureのまま扱い、そのerror bodyをDescriptionとしてparseすることでXML/AR-XML parse failureへ変換してはなりません。

Resolver HTTP errorをAR-XML parse errorに変換してはなりません。

AR-XML validation errorをResolver failureとして表現してはなりません。

Integrity failureはauthentication/trust failureと分離します。

Concrete exception名やpublic API shapeは規定しません。

## 13. Runtime document URL

Load成功後、Runtimeのdocument-facing URLはfinal AR-XML Document URLを示すことを推奨します。

Relative Interface解決に使用するpublic/internal document URLは、caller-supplied Anchor/Resolver URLではなくfinal AR-XML response URLでなければなりません。

Original requested URL、Resolver/Anchor input、redirect diagnosticsをobservability目的で別途保持しても構いませんが、AR-XML semanticsにおいてfinal document URLを置き換えてはなりません。

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

Direct AR-XML loadはdirect/L0 path、Resolver-mediated loadはL1 pathです。両pathはparse前に同一pipelineへ収束することを推奨します。

```text
Direct AR-XML URL (L0) ──────────┐
                                  ↓
Anchor/Resolver URL (L1) → redirects → final successful representation + final URL
                                  ↓
                            AR-XML parse/validate
```

Original inputがdirectかResolver-mediatedかに関係なく、parserへ渡すrepresentation semanticsは同一であることを推奨します。

RuntimeがL1 integrationもsupportするという理由だけで、direct/L0 loadにResolver/Manifest behaviorを要求してはなりません。

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
RT-015 terminal non-success HTTP response is not passed to XML parser
RT-016 verified representation octets are the same octets decoded/parsed
RT-017 Manifest integrity metadata is used only after applicable Manifest validation/binding succeeds
RT-018 direct AR-XML load remains L0/direct-compatible and Resolver/Manifest-independent
```

これらはimplementation handoff用IDであり、Frozen Resolver / Manifest Conformance Catalog 0.1へのcase追加ではありません。

## 17. Implementation handoff requirements

後続の `relink-web-runtime` implementation taskでは、現在のdocument-loading resource portを見直し、load pipelineがfinal response URLとterminal response statusを取得可能にする必要があります。

Fetch abstractionがdecoded textだけを返すモデルでは、追加retrieval metadataなしにfinal-response-URL semantics、HTTP terminal status separation、byte-identity integrity semanticsを実装できません。

OPTIONAL integrity supportでは、character decoding/XML parsing前のfinal representation body octetsが必要であり、そのverified octetsがdecoder/parserで実際にconsumeされる同一octetsであることを保証しなければなりません。

実装変更は適切なRuntime/application/adapter boundaryへ局所化し、AR-XML parserやcapability invokerへResolver固有parse behaviorを追加してはなりません。

## 18. Non-goals

本contractは次を定義しません。

- `relink-web-runtime` production code変更
- 新しいResolver protocol
- Resolver内部/DB behavior
- ManifestのL1必須化
- arbitrary redirect chainからのimplicit Manifest discovery
- automatic capability execution
- L2 authentication/signature/ownership/key binding/Trust
- AR-XML syntax変更
- browser CORS bypass mechanism

## 19. Summary

```text
input URL
↓
policy-controlled HTTPS dereference
↓
redirect target → policy-controlled HTTPS dereference (必要回数繰り返し)
↓
terminal successful AR-XML response + final response URL
↓
representation/media compatibility checks where applicable
↓
optional Manifest validation/association + integrity verification
↓
同一verified representation bytesをcharacter decoding / XML parse
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
Verified representation bytes = parsed representation bytes
HTTP terminal failure ≠ AR-XML parse failure
```

これにより、Resolver integrationを通常Web semanticsへ沿わせつつ、RELinkの責務境界を維持します。