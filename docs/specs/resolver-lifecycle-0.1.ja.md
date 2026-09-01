# RELink Resolver Lifecycle 0.1 日本語版

Status: Draft specification  
Version: 0.1  
Scope: Resolver record lifecycle and state-transition model

> この文書は `resolver-lifecycle-0.1.md` の日本語版です。仕様上の要件語は英語版と同じ **MUST / SHOULD / MAY** を保持します。解釈に差異がある場合は英語版を基準とします。

## 1. 目的

本仕様は、実装詳細から独立して RELink Resolver record の lifecycle state machine を定義します。

Resolver Core 0.1ですでに定義されている lifecycle state と public HTTP semantics を再利用し、Frozen RELink Manifest 0.1 の lifecycle vocabulary と整合させます。

```text
Resolver Core = public lifecycle effect
Lifecycle 0.1 = state-transition model + maintenance/history rules
Manifest 0.1 = descriptive lifecycle metadata
Trust/L2 = future authorization/authenticity rules
```

本仕様は Resolver Core に authentication、ownership transfer、Trust、capability execution、device management、AR-XML semantics を追加しません。

## 2. 要件語

本書中の **MUST**, **MUST NOT**, **REQUIRED**, **SHOULD**, **SHOULD NOT**, **MAY**, **OPTIONAL** は、すべて大文字で記載されている場合に限り、BCP 14（RFC 2119 および RFC 8174）に従って解釈します。

## 3. Lifecycle states

Resolver Lifecycle 0.1 は次の3状態のみを定義します。

```text
ACTIVE
SUSPENDED
RETIRED
```

意味は次のとおりです。

- **ACTIVE**: 通常の public L1 resolution が現在利用可能な状態。
- **SUSPENDED**: Resolver には既知だが、通常の public L1 resolution を一時的に停止している状態。
- **RETIRED**: 通常の RELink resolution から恒久的に退役した状態。

これらの状態名と意味は Resolver Core 0.1 と整合しなければなりません（MUST）。

Frozen Manifest 0.1 との対応は次のとおりです。

```text
ACTIVE    ↔ active
SUSPENDED ↔ suspended
RETIRED   ↔ retired
```

## 4. Public HTTP mapping

Lifecycle 0.1 は Resolver Core の public response semantics を再定義しません。適合実装は次を保持しなければなりません（MUST）。

| State | Resolver Core public L1 behavior |
| --- | --- |
| ACTIVE | 現在の検証済みHTTPS Description Locationへ `303 See Other` |
| SUSPENDED | `404 Not Found` |
| RETIRED | `410 Gone` |

Public interface は unknown UUID と SUSPENDED UUID を意図的に区別しません。

Lifecycle reason、transition history、actor metadata、timestamp、administrative annotation は、後続のversioned RELink specificationが別profileを明示しない限り、上記public status-code mappingを変更してはなりません（MUST NOT）。

## 5. State-transition model

許可されるstate transitionは次のみです。

```text
ACTIVE    → SUSPENDED
SUSPENDED → ACTIVE
ACTIVE    → RETIRED
SUSPENDED → RETIRED
```

State machineは次のとおりです。

```text
          suspend
ACTIVE ─────────────→ SUSPENDED
  │                     │
  │ retire              │ reactivate
  │                     ↓
  │                   ACTIVE
  │                     │
  └──────────────┐      │ retire
                 ↓      ↓
               RETIRED
```

上記にないtransition requestはmaintenance layerが拒否しなければなりません（MUST）。

指定されたsource stateがrecordの実際のcurrent stateと異なる場合、別transitionへ黙って変換してはなりません（MUST NOT）。

現在と同じstateを再設定する操作は、administrative implementationがidempotent no-opとして扱っても構いません（MAY）。ただしそのno-opを別のlifecycle transitionとして記録してはなりません（MUST NOT）。

## 6. RETIREDはterminal

RETIREDはResolver Lifecycle 0.1におけるterminal stateです。

RETIREDからACTIVEまたはSUSPENDEDへtransitionしてはなりません（MUST NOT）。

```text
RETIRED → ACTIVE      forbidden
RETIRED → SUSPENDED   forbidden
```

Retirement後に新しいresolvable recordが必要な場合、0.1 semanticsではretired recordを復活させるのではなく、新しいResolver record / Anchor identityを使用しなければなりません（MUST）。

将来のversioned RELink specificationはmigration/recovery semanticsを定義できますが（MAY）、Lifecycle 0.1からそのようなsemanticsを推測してはなりません。

## 7. Initial registration state

新規登録されたResolver recordは、外部から観測可能になる前に明示的なlifecycle stateを持たなければなりません（MUST）。

Reference Resolverは次のinitial registrationをサポートすることが推奨されます（SHOULD）。

- ACTIVE: 通常のpublic resolutionを開始できる場合。
- SUSPENDED: staging済みだがpublic resolutionをまだ開始しない場合。

通常の新規登録でRETIRED recordを作成することは推奨されません（SHOULD NOT）。ただしimport、archival restoration、migration toolingは既存historyを保存する目的でhistorical RETIRED recordを復元しても構いません（MAY）。

Initial stateの選択はregistrantのauthenticationやownershipを確立しません。

## 8. Suspension semantics

SUSPENDEDは一時的なpublic unavailabilityを意味し、削除を意味しません。

SUSPENDED recordは次を満たします。

- Resolver内部では既知のままである。
- current Description LocationやManifest関連metadataを内部保持してもよい（MAY）。
- PublicにはResolver Coreで定義されたSUSPENDED behaviorを返さなければならない（MUST）。
- 後にACTIVEへ戻してもよい（MAY）。
- RETIREDへ恒久移行してもよい（MAY）。

SuspensionはPhysical Entity、AR-XML document、owner、authorityのauthentication、revocation、compromise、Trust statusを意味してはなりません（MUST NOT）。

## 9. Retirement semantics

RETIREDはLifecycle 0.1においてResolver recordを通常のRELink resolutionから恒久的に撤回することを意味します。

RETIRED recordは次を満たします。

- lifecycle/history目的で内部識別可能な状態を維持する。
- Public Resolver Core pathでは `410 Gone` を返さなければならない（MUST）。
- ACTIVE/SUSPENDEDへ戻ってはならない（MUST NOT）。
- administrative metadata、Description Location history、Manifest snapshot、audit/history recordを内部保持してもよい（MAY）。
- 同一Anchor UUIDを別Entityまたは別Resolver recordへ再利用する許可と解釈してはならない（MUST NOT）。

Retirementはlifecycle statementであり、ownership transferやTrust statementではありません。

## 10. Transition reasons と administrative metadata

Lifecycle transitionはadministrative reasonまたはnoteを持っても構いません（MAY）。

Reason metadataは次を満たします。

- Public Resolver Core responseに必須としてはならない（MUST NOT）。
- Lifecycle stateのnormative meaningを変更してはならない（MUST NOT）。
- 存在するだけでtransitionをauthorizeしてはならない（MUST NOT）。
- Administrative metadataとして扱うことが推奨される（SHOULD）。
- Authenticated administration、export、audit、diagnostic contextで公開してもよい（MAY）。
- 後続仕様が明示しない限りpublic Core responseへ公開しないことが推奨される（SHOULD NOT）。

Reason taxonomy、free-text format、localization、組織固有codeはimplementation/profile concernであり、Lifecycle 0.1では標準化しません。

Frozen Manifest 0.1はlifecycle reason fieldを要求しません。Lifecycle 0.1はreason表現のためにFrozen Manifest 0.1を変更することを要求してはなりません（MUST NOT）。

## 11. Transition history

Reference Resolverは重要なlifecycle transitionのbounded historyを保持することが推奨されます（SHOULD）。

保持する各transition eventについて、少なくとも次を表現可能であることが推奨されます（SHOULD）。

```text
record identifier
previous state
new state
transition time
```

追加で次を保持しても構いません（MAY）。

```text
administrative reason
implementation-defined actor/reference metadata
request/correlation identifier
other diagnostic metadata
```

Actor/reference metadataはoperational metadataにすぎません。Lifecycle 0.1はactor authentication、authorization、identity proof、ownership、Trust semanticsを定義しません。

History eventを保持している間、実際のtransitionを誤って示すようにprevious/new stateやtimestampを書き換えないことが推奨されます（SHOULD NOT）。

Bounded retention、archival、compaction、privacy minimization、log rotationは許可されます。Lifecycle 0.1はappend-only ledger、blockchain、external transparency service、third-party timestamping、specialized storage technologyを要求しません。

## 12. State-change atomicity

Implementation/storage modelが許す場合、lifecycle transitionはResolver recordのcurrent lifecycle stateとatomicにcommitすることが推奨されます（SHOULD）。

Reference Resolverは次のようなexternally observable intermediate stateを避けることが推奨されます（SHOULD）。

- Public Resolverはnew stateを返すがadministrative stateはold stateのまま。
- Transition historyは完了を示すがeffective Resolver record stateは変更されていない。

安全にtransitionを完了できない場合、partial applyするよりprevious lifecycle stateを有効なまま保持し、administrative failureを報告することが推奨されます（SHOULD）。

これはconsistency requirementであり、特定のdatabase transaction mechanismを定義しません。

## 13. Description Location とtransition

Lifecycle state transition自体はcurrent Description Locationを変更しません。

```text
ACTIVE → SUSPENDED
```

ではstored Description Locationを変更せず保持しても構いません（MAY）。

```text
SUSPENDED → ACTIVE
```

では、response時点でResolver Core validation requirementsを満たす限り、以前のstored Description Locationを使用してreactivateしても構いません（MAY）。

Deploymentはadministrative lifecycle workflowの前後または一部としてDescription Locationを更新しても構いません（MAY）が、Location mutationとlifecycle mutationは別のsemantic operationです。

```text
Lifecycle state ≠ Description Location
```

RETIRED recordはhistory/audit目的でlast known Description Locationを内部保持しても構いません（MAY）が、Public Resolverはそこへredirectせず `410 Gone` を返さなければなりません（MUST）。

## 14. Manifestとの関係

Frozen Manifest 0.1はlifecycle stateを次のようにdescriptive metadataとして表現します。

```json
{
  "lifecycle": {
    "status": "active"
  }
}
```

Lifecycle 0.1はResolver stateとManifest lifecycle valueの固定mappingを保持しなければなりません（MUST）。

あるlogical stateのResolver recordからManifest representationを生成する場合、serialized `lifecycle.status` はそのrecordのlifecycle stateに対応しなければなりません（MUST）。

Public Manifest retrieval behaviorはFrozen Manifest 0.1に従います。

```text
ACTIVE    → 200 Manifest
SUSPENDED → 404
RETIRED   → 410
```

Public SUSPENDED/RETIRED retrievalでは通常Manifest representationを返さないため、`suspended`と`retired`値はadministration、export、archival、testing、future profileで利用できます。

Lifecycle 0.1はFrozen Manifest 0.1を再定義したり、新しいManifest fieldを要求してはなりません（MUST NOT）。

## 15. Transition時のcache behavior

Lifecycle transitionがcommitされた後、originは後続requestに対してnew stateのeffective responseを返します。

例:

```text
ACTIVE → SUSPENDED
origin response changes from 303 to 404
```

```text
ACTIVE → RETIRED
origin response changes from 303 to 410
```

```text
SUSPENDED → ACTIVE
origin response changes from 404 to 303
```

既存HTTP cacheは、transition前に保存されたresponseがordinary HTTP caching semanticsとそのresponseのcache policyに従ってfreshである間、以前のresponseを返し続ける場合があります（MAY）。

したがってLifecycle 0.1は、以前にcacheされたresponseのinstantaneous global invalidationを保証しません。

ImplementationはResolver Core cache policyを用いてstale lifecycle visibilityをboundedにすることが推奨されます（SHOULD）。

特に:

- successful `303` はCoreで要求されるexplicit cache policyを使用しなければならない（MUST）。
- SUSPENDED/unknown `404` は `Cache-Control: no-store` が推奨される（SHOULD）。
- RETIRED `410` はRETIREDがterminalであるためfinite cache lifetimeを使用してもよい（MAY）。
- Administrative toolingはcache-purge integrationをimplementation featureとして提供してもよい（MAY）が、Lifecycle 0.1 conformanceには必須ではない。

State transitionはResolver originでcommit済みとみなすためにcache-purge serviceへ依存してはなりません（MUST NOT）。

## 16. Concurrent administrative transitions

Administrative implementationは、同じprior stateに対して競合する2つのlifecycle transitionが両方acceptされることを防ぐのに十分なconcurrency controlを使用することが推奨されます（SHOULD）。

概念的には次の順序です。

```text
expected current state
        ↓
validate permitted transition
        ↓
commit new state
```

Commit前にactual current stateが変わった場合、stale assumptionに基づきsilent applyするより、administrative operationをfailさせるかnew stateに対してretryすることが推奨されます（SHOULD）。

Lifecycle 0.1はoptimistic locking、database row locking、ETag、compare-and-swap等の特定mechanismを要求しません。

## 17. Public/admin boundary

Lifecycle mutationはpublic Resolver Core GET interfaceの一部ではありません。

適合実装は次を満たします。

- Public L1 resolutionをread-onlyに保たなければならない（MUST）。
- Anchor UUIDを知っているだけでlifecycle mutationを許可してはならない（MUST NOT）。
- Mutationを実装する場合、separate administrative surfaceから提供することが推奨される（SHOULD）。
- Lifecycle 0.1をauthentication/authorization protocolとして解釈してはならない（MUST NOT）。

Administrative authentication/authorizationは、後続RELink Trust/L2 specificationがprotocol-level semanticsを定義するまではdeployment concernです。

## 18. Trust/security boundary

Lifecycle 0.1は次を定義・証明しません。

- 誰がEntityを所有するか。
- 誰がrecord transitionをauthorizeできるか。
- Entity/Resolverがauthenticか。
- Suspensionがcompromiseを意味するか。
- Retirementがownership transferを意味するか。
- Lifecycle transitionがcryptographically signedか。
- より高いRELink security levelが達成されたか。

Lifecycle stateはoperational Resolver metadataでありTrust verdictではありません。

```text
Lifecycle ≠ Authentication
Lifecycle ≠ Authorization
Lifecycle ≠ Ownership
Lifecycle ≠ Trust
```

Future L2/Trust specificationは誰がlifecycle changeをrequest/authorizeできるかを定義しても構いません（MAY）が、後続versionが明示的に置き換えない限り0.1のstate meaningを保持しなければなりません（MUST）。

## 19. Reference Resolver requirements

Lifecycle 0.1を実装するReference Resolverは、administrative capabilityとして次を提供することが推奨されます（SHOULD）。

- current lifecycle stateの表示。
- ACTIVEからSUSPENDEDへのtransition。
- SUSPENDEDからACTIVEへのreactivation。
- ACTIVE/SUSPENDEDのretirement。
- RETIREDからのtransition拒否。
- transition timeの記録。
- bounded transition historyの保持。
- optional transition reasonの記録。
- lifecycle mutationとDescription Location mutationの区別。
- current stateに対応するpublic responseのtest。

これらはadministrative requirementsでありpublic mutation APIを定義しません。

## 20. Conformance derivation とCatalog ownership

Lifecycle 0.1はlifecycle semanticsと、そこからconformance testを導出するbehaviorを定義します。ただしconformance case identifier自体は割り当てず、所有もしません。

Frozen Resolver / Manifest Conformance Catalog 0.1が、lifecycle case identifier、target、strength、expected resultのauthoritative registryです。Implementation、Testbed fixture、report、将来の参照は、Lifecycle-localな競合番号を新設・再利用せず、Frozen Catalogの`LIFE-*` identifierを使用しなければなりません（MUST）。

Lifecycle由来のconformance coverageには、適用可能な範囲で少なくとも次を含めることを推奨します（SHOULD）。

- ACTIVE / SUSPENDED / RETIREDのpublic mapping
- 許可/禁止されたlifecycle transition
- RETIRED terminal behavior
- unknown / SUSPENDEDのpublic non-distinction
- lifecycle stateとDescription Locationの独立性
- committed state change後のorigin behaviorとordinary cache freshness
- relevant conformance targetで扱われるadministrative concurrency/history behavior
- Resolver stateとFrozen Manifest lifecycle semanticsの整合性

Internal database layout、framework behavior、storage technologyはprotocol conformance testingに含めてはなりません（MUST NOT）。

## 21. Design summary

```text
States:
    ACTIVE
    SUSPENDED
    RETIRED

Transitions:
    ACTIVE    → SUSPENDED
    SUSPENDED → ACTIVE
    ACTIVE    → RETIRED
    SUSPENDED → RETIRED

Terminal:
    RETIRED

Public Core mapping:
    ACTIVE    → 303
    SUSPENDED → 404
    RETIRED   → 410

Manifest mapping:
    ACTIVE    ↔ active
    SUSPENDED ↔ suspended
    RETIRED   ↔ retired

History:
    bounded transition history recommended
    time + previous/new state
    reason/actor metadata optional

Cache:
    origin state changes immediately after commit
    existing caches follow ordinary freshness rules

Not Lifecycle responsibility:
    authentication
    authorization
    ownership transfer
    Trust
    capability execution
    AR-XML semantics
    implementation-specific storage
```

Lifecycle 0.1は、ResolverをTrust systemやdevice-management systemへ拡張せず、Resolver Core、Frozen Manifest 0.1、Reference Resolver、Resolver Testbedで再利用できる小さく決定的なstate machineを定義します。
