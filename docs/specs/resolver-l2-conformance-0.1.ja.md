# RELink Resolver L2 Conformance Catalog 0.1 日本語版

Status: Draft 0.1, tied to the merged L2 0.1 draft; freezes with that draft  
Version: 0.1-draft  
Freeze date: not frozen; freeze applies when the L2 0.1 draft freezes  
Scope: Resolver L2 0.1 draft（全sectionとAppendix A test vectors）、§20の4つのconformance target

> この文書は `resolver-l2-conformance-0.1.md` の日本語版です。解釈に差異がある場合は英語版を基準とします。
>
> **Freeze policy:** このcatalogは、紐付けられたL2 0.1 draftと同時にFrozenされます。編集上または非semanticなerrataは0.1内で修正できます。Conformance target、result semantics、case identifier、normative case expectation、baseline/optional分類、trust material semanticsの変更は、後続catalog versionで扱います。

## 1. 目的

本書は、Resolver L2 0.1について、実装方式に依存しない決定的なconformance caseを定義します。本書はL2 0.1 draft §20が名指すconformance catalogであり、§20 handoff chain（Resolver L2 spec、L2 conformance catalog、Reference Resolver実装、`relink-testbed` scenarios、Reference Lab）の第2成果物です。

Case identifier（`L2-VER-*`、`L2-AUT-*`、`L2-PRO-*`、`L2-SRV-*）は本書が所有し、L2 draftが所有しません。L2 draftはcase identifierを定義・予約・発明してはなりません（MUST NOT）；これらのidentifierはcatalog freeze時にL2 draftと同時にFrozenされます。

このcatalogは `relink-testbed` 側で実行可能testとして実装されることを想定しますが、実際のtest codeは本リポジトリの範囲外です。

```text
relink-resolver
= protocol + conformance definition

relink-testbed
= executable test implementation
```

TestはPHP、SQLite、framework、process、deployment内部構造ではなく、外部から観測可能なprotocol behaviorとrepresentation semanticsを検証しなければなりません（MUST）。本リポジトリ内のlocal PHPUnit resultsを `relink-testbed` resultsとしてreportしてはなりません（MUST NOT）。

## 2. Conformance targetとcase model

各catalog caseは、誰を試験するかを明示しなければなりません（MUST）。L2 draft §20が名指すL2 conformance targetは次のとおりです。

```text
L2-SERVER
L2-PRODUCER
L2-VERIFIER
L2-AUTHORITY
```

- **L2-SERVER**: public surface上のstatementおよびauthority material endpointの挙動；
- **L2-PRODUCER**: canonical serialization、signing bytes生成、key lifecycle issuance practice；
- **L2-VERIFIER**: result state model、§15の全state、fail-closed rules；
- **L2-AUTHORITY**: rotation、transfer、trail、revocationのsignature semantics。

1つの実装が複数targetをclaimしても構いませんが、異なるtargetの結果を曖昧な「Resolver L2 PASS」へ統合してはなりません（MUST NOT）。

各caseは次の要素を持ちます。

- **ID**: 安定したcatalog identifier
- **Target**: conformance role
- **Strength**: 主に試験するnormative strength（`MUST` / `SHOULD` / `MAY`）
- **Precondition**: 外部挙動に関係するstate / fixture
- **Action/Input**: system under testへ与えるrequest / statement / Record / raw representation
- **Expected**: 要求されるobservable result
- **Reference**: 根拠となるRELink L2仕様箇所

Testbed実装は1つのcatalog caseを複数の実行可能testに分割しても構いませんが、reportではcatalog IDを保持することを推奨します。

## 3. Result classとnormative strength

Testbedは少なくとも次を区別することを推奨します。

```text
PASS
FAIL
PASS-WITH-DEVIATION
NOT-APPLICABLE
UNSUPPORTED-OPTIONAL
```

**MUST / MUST NOT** を満たさない場合、対象targetのconformance failureであり `FAIL` とreportしなければなりません（MUST）。

**SHOULD / SHOULD NOT** からの逸脱は必ずreportしなければなりません（MUST）。ただし、実装が妥当な理由をdocumentしている場合、baseline conformance failureとは自動的にみなしません。その場合は、別profileがmandatoryとしていない限り `PASS-WITH-DEVIATION` を推奨します。

**MAY** caseは許容されるbehaviorまたはoptional interoperabilityを試験します。optional behaviorがないことをbaseline failureとしてはなりません（MUST NOT）。`UNSUPPORTED-OPTIONAL`は、statement revocation（extension、§13.1、§21）やES256 key agility（§10.2）など、該当baseline targetが要求しないOPTIONAL機能にのみ使用します。

## 4. Conformance set

```text
L2 Verifier Conformance
= L2-VER-* cases

L2 Authority Conformance
= L2-AUT-* cases

L2 Producer Conformance
= L2-PRO-* cases

L2 Server Conformance
= L2-SRV-* cases
```

Conformance reportでは、実行したsetを明示することを推奨します。

L2 0.1 baselineは、単一baseline algorithmとしてのed25519、Record chain、trail付きtransfer、key revocation、§15 state model、`statement-only` verification modeです。Statement revocation、ES256、Manifest integrity pinningはL2 0.1 baselineの範囲外です。Manifest integrity pinningはL2 conformanceに一切統合されません。

## 5. Verifier cases

### L2-VER-001 — Fresh snapshotによるVERIFIED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Trust Anchor Binding（anchor `550e8400-e29b-41d4-a716-446655440000`、entity `urn:relink:entity:thermal-sensor-42`、key1 `ECMERdvpV0k`、`epoch-0`、floor 0）；Records 0、1、2、3はchain walkによりaccepted；`T = 1769998400`、`W = 600`；key3 `G8VbXumewVs` 下のstatement A（`epoch-1`、`generation = 1`、`issuedAt` 1769998300、`expiresAt` 1770001200）はcanonical wire上。  
**Action:** verifierは`statement-only` modeで`T`においてstatement Aを評価する。  
**Expected:** `VERIFIED`：snapshot Record 3はfresh（`1769998200 <= T <= 1770001800`、`T - issuedAt = 200 <= W`）、key3はsnapshot内で`current`、window `1769997600..1770001200`がstatement `issuedAt`を含む、canonical payload bytes上のsignatureは検証成功、`epochId`はaccepted epochと一致、`generation`はfloor上。  
**Reference:** L2 0.1 draft §15.2、§11.4、§9.6；Appendix A.5。

### L2-VER-002 — keyIdはwire publicKeyから再計算
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** canonical wire上の任意のstatementまたはRecord entry。  
**Action:** verifierはentryを受理する前に§10.1に従いwire `publicKey`から`keyId`を再計算し、case-sensitiveな完全一致で比較する。  
**Expected:** 再計算`keyId`がwire `keyId`と異なるentryはauthority materialとして受理してはならず（MUST NOT）、そのkey下のstatementはchain phase（phase 5、`UNKNOWN_AUTHORITY`）で失敗する。  
**Reference:** L2 0.1 draft §9.4、§10.1、§15.2。

### L2-VER-003 — Wire canonicalityを最初に検査
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** raw wire textがverifierへ提示される。  
**Action:** verifierはstrict parse、raw wire text内のduplicate member検出、integer range、`l2Version`、payload/signature分離、`keyId`再計算を、trust evaluationに先立って適用する。  
**Expected:** phase 1で`INVALID`、`UNVERIFIED`ではない；phase 1でrejectedされたRecordに依存するstatementはphase 5（`UNKNOWN_AUTHORITY`）で失敗し、phase 6ではない。  
**Reference:** L2 0.1 draft §7.2、§7.3、§15.2 phase 1。

Testbedは親catalog IDを保持したまま、少なくとも次のsubcaseを実装することを推奨します。

```text
L2-VER-003/duplicate-members
L2-VER-003/duplicate-members-in-transfer-payload
L2-VER-003/non-integer-timestamp
L2-VER-003/l2version-mismatch
L2-VER-003/signature-inside-payload
L2-VER-003/keyid-recompute-mismatch
```

### L2-VER-004 — ANCHOR_MISMATCHはphase 2
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** verifierはexpected anchor `550e8400-e29b-41d4-a716-446655440000`で設定；statementの`anchorId`は異なる値。  
**Action:** verifierはstatementを評価する。  
**Expected:** trust chain、freshness、signature evaluationより先に、phase 2で`ANCHOR_MISMATCH`。  
**Reference:** L2 0.1 draft §15.1、§15.2 phase 2。

### L2-VER-005 — UNSUPPORTED_ALGORITHMはphase 3
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** statementは§10.2 baseline外のproprietary、licensed、unknown algorithmを宣言。  
**Action:** verifierはstatementを評価する。  
**Expected:** phase 3で`UNSUPPORTED_ALGORITHM`；fail-closedであり、`VERIFIED`でも`UNVERIFIED`でもない。  
**Reference:** L2 0.1 draft §10.2、§10.8、§15.2 phase 3。

### L2-VER-006 — Trusted clockがない場合UNVERIFIED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** verifierにtrusted clock sourceがない。  
**Action:** verifierは任意のstatementを評価する。  
**Expected:** phase 4で`UNVERIFIED`；verifierはfreshness judgmentsを行ってはならず（MUST NOT）、resultを`VERIFIED`やEntity failureとしてsurfaceしてはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §11.2、§15.2 phase 4。

### L2-VER-007 — Trust material欠落はphase 5でUNVERIFIED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** verifierのauthority materialはstatement signerへ到達せず、chain walkにgapがある。  
**Action:** verifierはstatementを評価する。  
**Expected:** `UNVERIFIED`はphase 5（chain walk gap、material欠落、trusted lower boundなし）であり、phase 6ではない；`UNKNOWN_AUTHORITY`はaccepted chainの外に解明できるsignerに対してのみ。  
**Reference:** L2 0.1 draft §9.6、§9.8、§15.2 phase 5。

### L2-VER-008 — Signerがaccepted chain外ならUNKNOWN_AUTHORITY
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** statementはaccepted Record chain内で解明できないkeyで署名。  
**Action:** verifierはstatementを評価する。  
**Expected:** phase 5で`UNKNOWN_AUTHORITY`；`VERIFIED`ではなく、trust knowledge failureとして呈示される`UNVERIFIED`でもなく、Entity failureでもない。  
**Reference:** L2 0.1 draft §9.4、§15.2 phase 5。

### L2-VER-009 — Record 0 self-signはTrust Anchor Binding経由
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Trust Anchor Bindingは`anchorId`、initial algorithm `ed25519`、full-length key1 `publicKey`、§10.1で再計算された`keyId` `ECMERdvpV0k`、initial `epoch-0`、initial floor 0を持つ。  
**Action:** verifierはRecord 0（self-signed、`recordGeneration = 0`、`prevRecordDigest` null、floor 0、`issuedAt` 1769996400、`expiresAt` 1770000000）を受理する。  
**Expected:** Record 0はTrust Anchor Bindingを届けpolicy channel経由でのみ受理される；chain provenanceのないfirst-contact materialはtrustしてはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §9.3、§9.8；Appendix A.5（Record 0）。

### L2-VER-010 — Record 1はretirement truncation cap付きでaccepted
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Record 0 accepted；Record 1（`recordGeneration = 1`、signer key1、`issuedAt` 1769997000、`expiresAt` 1770000600、`epoch-0`、`prevRecordDigest` `36ce3c7bf819434ebc79e534649873081ee0999af85c798c6586235680809afb`）はkey2 `4KKXd7XSJW8`を`current`（`validFrom` 1769997000）として導入し、key1を`validUntil` 1769997000でretireする。  
**Action:** verifierはTrust Anchor Bindingからchainをwalkする。  
**Expected:** Record 1 accepted（signer key1はRecord 0 snapshotで`current`、digest一致、retirement `validUntil`は`min(previous validUntil, issuedAt(1))`にcap）；chain accepted maximumは1になる。  
**Reference:** L2 0.1 draft §9.6、§9.5；Appendix A.5（Record 1）。

### L2-VER-011 — Record 2のepoch switchはtrail経由でaccepted
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0、1 accepted；Record 2（`recordGeneration = 2`、`signerKeyId` `4KKXd7XSJW8`、`issuedAt` 1769997600、`epoch-1`）はtrail `outgoingKeyId` `4KKXd7XSJW8` → `incomingKeyId` `G8VbXumewVs`、trail `epochId` `epoch-0`、`newEpochId` `epoch-1`、trail canonical payload上のtrail signatureを組み込む。  
**Action:** verifierはRecord 2をwalkし、Record 1で`current`かつwindow `1769997000..1770000600`が`issuedAt(2)`を含むkey2下でRecord signatureを検証し、Record 1に記録されたkey2の`publicKey`下でtrail signatureを検証する。  
**Expected:** Record 2 accepted；§12.2の全matching rule確認済み；floor baseline 0でatomic epoch switch to `epoch-1`；key3は`current`、window `1769997600..1770001200`。  
**Reference:** L2 0.1 draft §9.6、§12.2；Appendix A.5（Record 2）。

### L2-VER-012 — Record 3はincoming keyによる最初のRecordとしてaccepted
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0..2 accepted；Record 3（`recordGeneration = 3`、signer key3 `G8VbXumewVs`、`issuedAt` 1769998200、`expiresAt` 1770001800、`epoch-1`、`prevRecordDigest` `39808d467859fcad7589d70d54e0666038ac039d4956964ddda751f10f3e3234`）。  
**Action:** verifierはRecord 3をwalk；key3 window `1769997600..1770001200`は`issuedAt(3)`を含み、signer key3はRecord 2に`publicKey` bytesとして存在。  
**Expected:** Record 3 accepted；chain accepted maximumは3になる；`T = 1769998400`でのrevocation snapshotはRecord 3、fresh（`200 <= W = 600`）。  
**Reference:** L2 0.1 draft §9.6、§11.4；Appendix A.5（Record 3）。

### L2-VER-013 — Snapshot Record kはstatement freshnessより先に選定
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** `T = 1769998400`、`W = 600`でeffective prefix `0..3`；`k = 3`（non-decreasing ruleで`issuedAt(k) <= T`を満たす最高accepted `recordGeneration`）。  
**Action:** verifierはsnapshot Recordを最初に選定し、snapshot freshnessを検査し、その後statement freshnessを評価する。  
**Expected:** snapshot Record 3は独立に選定・freshness検査され、statementは`T = 1769998400`でfresh（`1769998300 <= T <= 1770001200`）；snapshot freshnessはRecord 3の`[issuedAt, expiresAt]`と`W`で評価され、statement fieldではない。  
**Reference:** L2 0.1 draft §11.4、§15.2 phase 6、phase 10。

### L2-VER-014 — Tampered trailはchainをRecord 1で停止
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Record 2のtrailがmissing、tampered、duplicated、mismatched。  
**Action:** verifierはTrust Anchor Bindingからchainをwalkする。  
**Expected:** Record 2はtrail verification、key-authority acceptanceの一部としてphase 5でrejected；chainはRecord 1で停止；statement Aはphase 6で`UNVERIFIED`（fresh snapshotはkey3、`epoch-1`、chainをカバーしない）。  
**Reference:** L2 0.1 draft §9.6、§12.2、§15.2 phase 5、phase 6；Appendix A.5。

### L2-VER-015 — Record 4 accepted；S6はphase 10でSTALE
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0..3 accepted；Record 4（`recordGeneration = 4`、`epoch-1`、signer key3、`issuedAt` 1770000900、`expiresAt` 1770002400、key4 `ECYh7yJFAn8`を`current` `1770000900..1770002400`として導入）；statement S6（key4、`epoch-2`、`generation = 1`、`issuedAt` 1770001100、`expiresAt` 1770002400）；`T6 = 1770001200`、`W = 600`。  
**Action:** verifierは`T6`でS6を評価する。  
**Expected:** Records 0..4 accepted（key3 window `1769997600..1770001200`は`issuedAt(4)`を含む）；snapshot Record 4は`T6`でfresh（`300 <= W`）；`epoch-2`はaccepted epochではなくrejected Record 5でのみ導入されるため、phase 9はskipされphase 10は`STALE`を出す — `VERIFIED`でも`UNVERIFIED`でも`REVOKED`でもない。  
**Reference:** L2 0.1 draft §15.2 phase 10、§13.4；Appendix A.5（Record 4、S6）。

### L2-VER-016 — Record 5はsignatureではなくtrail matchingでrejected
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0..4 accepted；Record 5（`G = 5`、`epoch-2`、signer key4、`issuedAt` 1770001500）はtrail `epoch-1` → `epoch-2`、key4 → key5 `cIbUqaV2qxI`を組み込み、Record 5はkey3を`current`のまま保ちkey5を2つの`current` keysの1つとして持つ。  
**Action:** verifierはRecord 5をwalkする。  
**Expected:** Record 5はtrail matching、phase 5でrejected：§12.2はRecord 4で`current`の全key（key3とkey4）を`validUntil = issuedAt(5)`でretireし、trail-approved incoming key key5をRecord 5唯一の`current` keyとすることを要求する。Signatureはkey4 `publicKey`下でcryptographically検証される；rejectionはmatching rule failureでありsignatureではない；chainはRecord 4で停止；key4 authority下で`epoch-2`を導入するRecordsは受理してはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §12.2、§9.6；Appendix A.5（Record 5）。

### L2-VER-017 — Record 6はcontiguityでrejected；epoch 2と3はnever accepted
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Record 5 rejected（maximumは4のまま）；Record 6（`G = 6`、`epoch-3`、signer key4、`issuedAt` 1770001800）はtrail `epoch-2` → `epoch-3`を組み込む。  
**Action:** verifierはRecord 6をwalkする。  
**Expected:** Record 6はchain walkでrejected：`prevRecordDigest`はnever acceptedのRecordを参照し、contiguity失敗；chain accepted maximumは4のまま；`epoch-2`と`epoch-3`はnever accepted epochs。  
**Reference:** L2 0.1 draft §9.6、§12.2；Appendix A.5（Record 6）。

### L2-VER-018 — issuedAtがTを超えるRecordはeffective prefix外
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0..3 accepted plus Record 4F（`recordGeneration = 4`、`epoch-1`、signer key3、`issuedAt` 1769999000、`expiresAt` 1770002000、keys arrayはRecord 3と同じ）；`T = 1769998400`、`W = 600`；statement A。  
**Action:** verifierは`T`でstatement Aを評価する。  
**Expected:** Record 4Fはchain walkでaccepted；`T`でのeffective prefixは`0..3` — Record 4Fは`issuedAt > T`でprefix外；snapshot Record 3はfreshでstatement Aはsnapshot Record 3経由で`VERIFIED`；Record 4Fのkeys、floor、epoch dataは`T`でrevocation、epoch、floor、key evidenceとして寄与してはならない（MUST NOT）；`issuedAt > T`のRecordの存在はphase 6 failureではない。  
**Reference:** L2 0.1 draft §11.4、§9.7、§15.2 phase 6；Appendix A.5（Record 4F）。

### L2-VER-019 — T′でfresh snapshotがなくUNVERIFIED；back-off禁止
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0..3、4F accepted；`T′ = 1770000000`、`W = 600`。  
**Action:** verifierは`T′`でstatement Aを評価する。  
**Expected:** effective prefixは`0..4`；effective-latest Record 4Fはnot fresh（`T′ - 1769999000 > W`）；Record 3も`T′`でnot fresh（`T′ - 1769998200 > W`）；Record 4FからRecord 3へのback-offはいずれのケースでも禁止；fresh snapshotがないため`UNVERIFIED`。`T`のshift、`issuedAt`のrewrite、back-off、window外Recordの選択は`VERIFIED`を生んではならない（MUST NOT）。  
**Reference:** L2 0.1 draft §11.4、§9.7、§15.2 phase 6；Appendix A.5（Record 4F）。

### L2-VER-020 — Effective-latest Recordがnot freshならstatementはUNVERIFIED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** scenario D、chain Records `0..3, 4E` accepted；Record 4E（`recordGeneration = 4`、signer key3、`issuedAt` 1769998250、`expiresAt` 1769998300）；`T = 1769998700`、`W = 600`；statement S7はstatement Aとbyte-identical、`T`でfresh（`T - issuedAt = 400 <= W`）。  
**Action:** verifierは`T`でS7を評価する。  
**Expected:** effective prefixは`0..4`、effective-latest Recordは4E、その`expiresAt` `1769998300 < T`：snapshotはnot fresh；S7はphase 6で`UNVERIFIED`、`VERIFIED`ではない。Record 3は`T`でfresh（`500 <= W`）；Record 3へのback-offは禁止 — effective prefixのeffective-latest Recordが`T`でnot freshなら、window内のolder Recordを選択してはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §11.4、§15.2 phase 6；Appendix A.5（Record 4E、S7、scenario D）。

### L2-VER-021 — VERIFIEDはsnapshotがfresh境界Tでのみ成立
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** scenario D chain `0..3, 4E`；`T = 1769998300`、`W = 600`；statement S7。  
**Action:** verifierは`T = 1769998300`でS7を評価する。  
**Expected:** Record 4Eは`T`でfresh（`T <= expiresAt` `1769998300`はequality、`T - issuedAt = 50 <= W`）かつS7は`T`でeffective；`VERIFIED`はこの`T`でのみ成立し、S7自体がnot effective（`issuedAt 1769998300 > T`）な`T = 1769998700`ではnever成立（phase 10で失敗、phase 6ではない）。  
**Reference:** L2 0.1 draft §11.4、§15.2 phase 6、phase 10；Appendix A.5（scenario D）。

### L2-VER-022 — Effective prefixとkはnon-decreasing rule
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** non-decreasing `issuedAt`のaccepted chain `0..5`；`T`はchain内；`recordGeneration = 5`のRecordは`issuedAt > T`。  
**Action:** verifierは`T`でeffective prefixを計算する。  
**Expected:** effective prefixは全`issuedAt <= T`を満たす最長contiguous accepted prefix；`k`はnon-decreasing ruleで`issuedAt(k) <= T`を満たす最高accepted `recordGeneration`；`issuedAt > T`のRecordsはprefixから除外され、その存在はphase 6 failureではない。  
**Reference:** L2 0.1 draft §11.4、§15.2 phase 6；Appendix A.5（Records 4F、4B、5V）。

### L2-VER-023 — Retransmissionはfreshness pointをrefreshしない
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0..2は`T = 1769998400`で繰り返しdelivery；Record 3はfresh。  
**Action:** verifierはRecords 0..2をre-fetch、retransmit、re-present、revalidateする。  
**Expected:** snapshot freshnessは動かない：freshness pointはsnapshot Record 3のsigned `issuedAt`であり唯一のfreshness point；re-fetch、retransmission、re-presentation、HTTP revalidationは絶対に更新しない（NEVER）。  
**Reference:** L2 0.1 draft §11.4、§9.7；Appendix A.5。

### L2-VER-024 — Clock skew toleranceはfreshnessをmanufactureしない
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** documented skew allowance `S`；snapshotは`T`に対して`[issuedAt + W, expiresAt]`外。  
**Action:** verifierはskew条件下で任意のstatementを評価する。  
**Expected:** verifierは`T`をshiftし（MUST NOT）、`issuedAt`をrewriteし（MUST NOT）、freshnessをmanufactureするためにwindow外Recordを選択してはならない（MUST NOT）；`S`はdocumented境界内のclock toleranceのみ吸収する。  
**Reference:** L2 0.1 draft §11.2、§11.6、§11.4。

### L2-VER-025 — Prior-epoch statementはSTALE、phase 9はskip
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** scenario B、chain `0..3` accepted、accepted epoch `epoch-1`；statement S5（key2 `4KKXd7XSJW8`、`epoch-0`、`generation = 1`、`issuedAt` 1769997200、`expiresAt` 1770001000）；`T = 1769998400`、`W = 600`；key2はsnapshot Record 3で`retired`。  
**Action:** verifierは`T`でS5を評価する。  
**Expected:** S5はwindow-valid；`epoch-0`はaccepted epochと異なるためphase 9のkey-status judgmentはskip — key statusは評価されず — phase 10はprior epochに対して`STALE`を出す。S5は`VERIFIED`、`REVOKED`、`UNVERIFIED`、`CONFLICT`であってはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §15.2 phase 9、phase 10、§13.4、§13.1；Appendix A.5（S5）。

### L2-VER-026 — Revocation境界でREVOKED、VERIFIEDはnever
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** scenario E、common accepted prefix `0..3, 4A, 4R`；Record 4R（`recordGeneration = 5`、signer key3、`issuedAt` 1769998400）はkey3を`revokedFrom = 5`でrevoke；statement S8はkey3下；`T = 1769998400`、`W = 600`；effective prefix `0..5`、snapshot Record 4R fresh（`T - issuedAt = 0`）。  
**Action:** verifierは`T`でS8を評価する。  
**Expected:** S8はrevocation境界でphase 9の`REVOKED`、fresh evidence付き；`VERIFIED`はnever；revoked keyを`current`としてsurfaceしてはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §13.4、§15.2 phase 9；Appendix A.5（Record 4R、S8、scenario E）。

### L2-VER-027 — Window内のidentical statement replayは受理
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** accepted chain、fresh snapshot；statementは`[issuedAt, expiresAt]`内でbyte-identicalにreplay。  
**Action:** verifierはreplayed presentationを評価する。  
**Expected:** window内のidentical statement replayは受理される（全phase通過なら`VERIFIED`）；resultは通過したphaseを記録し、byte-identical presentationへのreplay penaltyはない。  
**Reference:** L2 0.1 draft §15.2 phase 7、phase 10；§17 replay row。

### L2-VER-028 — 同一epoch・同一generationでpayloadが異なる場合CONFLICT
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** accepted chain；同一`epochId`・同一`generation`・payloadが異なる2つのstatements。  
**Action:** verifierは2番目のstatementを評価する。  
**Expected:** phase 10で`CONFLICT`（equivocation）；2つのpayload間のheuristic選択なしのfail-closed。  
**Reference:** L2 0.1 draft §9.7、§15.2 phase 10、phase 7。

### L2-VER-029 — Accepted maximum以下のbyte-identical Record positionはSTALE
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** accepted chain maximum `G`；statement positionはaccepted maximum以下のbyte-identical position。  
**Action:** verifierはstatement positionを評価する。  
**Expected:** phase 7で`STALE`：accepted maximum以下のbyte-identical positionは`STALE`；same-generation byte-identical presentationはreplay；same-epoch、same-generation、payload相違は`CONFLICT`。  
**Reference:** L2 0.1 draft §15.2 phase 7、§9.7。

### L2-VER-030 — Statement generationがfloor未満ならSTALE
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** accepted chainにstatement floor；statement `generation`はfloor未満。  
**Action:** verifierはstatementを評価する。  
**Expected:** below-floor generationに対してphase 10で`STALE`、`VERIFIED`はnever。  
**Reference:** L2 0.1 draft §12.3、§15.2 phase 10；Appendix A.5（floor baseline 0）。

### L2-VER-031 — Statementがwindow外ならEXPIRED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** fresh snapshot；accepted epochのstatementで`expiresAt < T`。  
**Action:** verifierは`T`でstatementを評価する。  
**Expected:** phase 10で`EXPIRED`；`VERIFIED`でも`UNVERIFIED`でもない。  
**Reference:** L2 0.1 draft §11.1、§15.2 phase 10。

### L2-VER-032 — VERIFIEDはT以遠をassertしない
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** statementは`T`で`VERIFIED`；後続`T′ > T`でのevaluation。  
**Action:** verifierは`T′`で再評価する。  
**Expected:** `VERIFIED`は`T`の後は何もassertしない；verifierは`T′`でeffectiveなsnapshotで再評価する；`T′`でのcached `VERIFIED`をassertしてはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §13.5、§11.4、§15.2。

### L2-VER-033 — Snapshotはstatement epochをカバーしなければならない
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** statement epochは`T`でeffectiveなrevocation snapshotにカバーされない；accepted epochはsnapshot Record kに記録。  
**Action:** verifierはstatementを評価する。  
**Expected:** VERIFIEDはeffective prefix外のRecordsからのevidenceに依存してはならない（MUST NOT）；fresh snapshotが記録しないepochのstatementはphase 6で`UNVERIFIED`；phase 10のepoch比較はsnapshotが記録したaccepted epochに対して行う。  
**Reference:** L2 0.1 draft §11.4、§15.2 phase 6、phase 10。

### L2-VER-034 — Retired key下のstatementはUNVERIFIED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** keyはfresh snapshotで`retired`；statementはそのkey下、accepted epoch、window-valid。  
**Action:** verifierはstatementを評価する。  
**Expected:** retirementが既知ならphase 9で`UNVERIFIED`；retirementはcompromiseではなく、resultは`REVOKED`でも`VERIFIED`でもない。  
**Reference:** L2 0.1 draft §10.5、§13.4、§15.2 phase 9。

### L2-VER-035 — Revoked key下のstatementはfresh evidenceでREVOKED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** fresh snapshotはrevocation Recordをカバー；revoked key下のstatementの`issuedAt`はrevocation Record `issuedAt`以上。  
**Action:** verifierはstatementを評価する。  
**Expected:** phase 9で`REVOKED`；revocation `issuedAt`未満のstatementsは§11.3 anti-rollbackに従う；revoked materialはrotation authorityとして使ってはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §13.4、§11.3、§15.2 phase 9；Appendix A.5（Record 4R）。

### L2-VER-036 — Terminal keyの再登録はrejected
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** keyはaccepted historyで`revoked`、`compromised`、`retired`；後続Recordは同じ`keyId`を異なる`publicKey`に対して再登録するか`current`へ戻そうとする。  
**Action:** verifierはchainをwalkし、再登録key下のstatementを評価する。  
**Expected:** Recordはchain acceptanceでrejected（terminal irreversibility）；statementはphase 5で`UNKNOWN_AUTHORITY`、historyがrevocationを示すならphase 9で`REVOKED`、retired keysなら`UNVERIFIED`で失敗し、`VERIFIED`はnever。  
**Reference:** L2 0.1 draft §9.5、§13.4、§15.2 phase 5、phase 9。

### L2-VER-037 — revokedFrom mutationはrejected；tipはrevocation Recordに留まる
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** scenario E、common accepted prefix `0..3, 4A, 4R`、`T = 1769998400`、`W = 600`；negative branchはRecord 4Cのみを追加（Record 4Bとbyte-identicalで`key3.revokedFrom = 1`のみ相違）。  
**Action:** verifierはchainをwalkし`T`でS8を評価する。  
**Expected:** Record 4Cはphase 5でrejected（post-establishment `revokedFrom`の単独mutation）；chain tipはgeneration 5に留まる；Record 4Cはnever snapshot、key3をrestoreできない、`S8`を`REVOKED`から変更してはならない（MUST NOT）；`issuedAt > T`のRecords 4B/4Cはeffective prefixの一部ではない。  
**Reference:** L2 0.1 draft §9.5、§13.5、§11.4；Appendix A.5（scenario E）。

### L2-VER-038 — l1-bound mode：Location一致はverify
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** `l1-bound` mode；同一expected AnchorのL1 303 observation記録済み；statement `descriptionLocation`はobserved Locationと一致。  
**Action:** verifierは`l1-bound` modeでstatementを評価する。  
**Expected:** §7.4 consistency確認済みで`VERIFIED`；resultは`verificationMode`を記録する。  
**Reference:** L2 0.1 draft §15.1、§7.4、§15.2 phase 11。

### L2-VER-039 — l1-bound mode：descriptionLocation不一致はMISMATCH
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** `l1-bound` mode；observed L1 303 Locationはstatement `descriptionLocation`と相違。  
**Action:** verifierはstatementを評価する。  
**Expected:** phase 11で`MISMATCH`、phases 1..10全て通過後のみ。  
**Reference:** L2 0.1 draft §7.4、§15.2 phase 11。

### L2-VER-040 — l1-bound mode：L1 observation欠落はUNVERIFIED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** `l1-bound` mode；expected Anchorに対するL1 observation記録なし。  
**Action:** verifierはstatementを評価する。  
**Expected:** phase 11で`UNVERIFIED`；`l1-standalone`はL1 requestを行わず`statement-only` semanticsを実行；L1 observation欠落は`MISMATCH`ではない。  
**Reference:** L2 0.1 draft §15.1、§15.2 phase 11。

### L2-VER-041 — Stateはstateとしてsurface、booleanはnever
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** verifierは上記いずれかのcaseのresultを生成。  
**Action:** resultをreportする。  
**Expected:** resultは§15のstateのいずれかでbooleanではない；`UNVERIFIED`は`VERIFIED`としてもEntity failureとしてもsurfaceしてはならない（MUST NOT）；L2 conformanceをclaimするconsumerはstate `VERIFIED`、`UNVERIFIED`、`INVALID`、`EXPIRED`、`REVOKED`、`STALE`、`UNKNOWN_AUTHORITY`、`UNSUPPORTED_ALGORITHM`、`CONFLICT`、`MISMATCH`、`ANCHOR_MISMATCH`をexposeしなければならない（MUST）。  
**Reference:** L2 0.1 draft §15、§15.1、§15.2。

### L2-VER-042 — Phase orderは固定；最初のnon-null resultが勝つ
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** fixture setで複数phase同時に失敗するstatement。  
**Action:** verifierは§15.2 phase order 1..11で評価する。  
**Expected:** verifierはphaseをreorderし（MUST NOT）、severity-pickもしてはならない（MUST NOT）；最初のnon-null phase resultが答えであり、resultはどのphaseが失敗したかを記録する（wire→anchor→algorithm→clock→chain→snapshot→position→signature→key status→freshness→L1 consistencyの順）。  
**Reference:** L2 0.1 draft §15.2。

### L2-VER-043 — Snapshot failureはfail-closed；HTTP cacheはL2 currencyではない
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** snapshot unavailable、stale、incomplete、empty；chain-walk failure；statement revocationがcached。  
**Action:** verifierはstatementを評価する。  
**Expected:** snapshot failureまたはchain-walk failureで`UNVERIFIED`；revoked evidenceをcachedでもcurrentとしてsurfaceしてはならない（MUST NOT）；HTTP cache reusability（RFC 9111）はL2 currencyと別であり、HTTP-reusable cached statementをL2-currentとしてreportしてはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §13.5、§14.2。

### L2-VER-044 — OfflineはREVOKEDをmanufactureしない
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** verifierはofflineでfresh evidence到達不能；last known key statusのみ。  
**Action:** verifierはstatementをofflineで評価する。  
**Expected:** `REVOKED`はlocalに保持されたfresh evidenceがあるときのみreport；なければ`UNVERIFIED`；offline operationはrevocation-safeではない。  
**Reference:** L2 0.1 draft §11.5、§13.6。

### L2-VER-045 — Statement revocationはextension、baselineではない
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** statement revocationはextensionとして実装；特定statementをrevokeするRecord、fresh coverage。  
**Action:** verifierはrevoked statementを評価する。  
**Expected:** statement revocation実装時、statementは同一phase discipline下でfresh evidenceにより`REVOKED`を報告；statement revocation supportの欠如はbaseline failureとしてreportしてはならず（MUST NOT）、`UNSUPPORTED-OPTIONAL`としてreportされる。  
**Reference:** L2 0.1 draft §13.1、§21、§19。

## 6. Authority cases

### L2-AUT-001 — Bootstrap：chain provenanceのないfirst-contact materialはtrustしない
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** verifierはauthority materialのため新originへcontact；Trust Anchor Binding記録なし。  
**Action:** verifierはRecordsとTrust Anchor Bindingを受領。  
**Expected:** statementと同一originのmaterialはindependentではない；未accept key下でverifyするRecordsは自身のauthorityをbootstrapしてはならず（MUST NOT）、Trust Anchor Bindingはpolicy channel経由でのみ受理；provenance handlingはdeployment-profile concern。  
**Reference:** L2 0.1 draft §9.2、§9.3、§9.8、§21。

### L2-AUT-002 — prevRecordDigestはpayloadをbind、signatureはbindしない
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** fixture setのRecords 1..3。  
**Action:** verifierは各`prevRecordDigest`をRecord `G-1`のJCS canonical `payload` memberのSHA-256（lowercase hex）として再計算する。  
**Expected:** digestsはfixture値（`36ce3c7bf819434ebc79e534649873081ee0999af85c798c6586235680809afb`、`870b931dd8f9ad01a6a037524842549ad60fcde1066dbc9caf4f3e6140102f61`、`39808d467859fcad7589d70d54e0666038ac039d4956964ddda751f10f3e3234`）と一致；signatureとenvelopeはdigestに含まれない；Record 0の`prevRecordDigest`はnull；envelopeをbindするdigestのRecordはchain walkでrejectしなければならない（MUST）。  
**Reference:** L2 0.1 draft §9.5、§9.6；Appendix A.5。

### L2-AUT-003 — generation厳密増加、issuedAt非減少、contiguity
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** `recordGeneration`がskip、regress、または`issuedAt`がregressするRecord chain。  
**Action:** verifierはchainをwalkする。  
**Expected:** `recordGeneration`は0からaccepted maximumまで厳密増加かつcontiguousでなければならず（MUST）、`issuedAt`は非減少でなければならない（MUST）；gap、regression、skipped generationは最初のoffending Recordでchainを切断する（break）。  
**Reference:** L2 0.1 draft §9.5、§9.6、§11.4。

### L2-AUT-004 — Keys-array entry identityは保持
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record chain；Recordが既存entryの`keyId`、`publicKey`、`algorithm`、`validFrom`を変え、entryを削除する。  
**Action:** verifierはchainをwalkする。  
**Expected:** entryのidentity変更never、entry削除never、`keyId`の異なる`publicKey`への再登録never；offending Recordはchain acceptanceでrejected。  
**Reference:** L2 0.1 draft §9.5、§9.4、§9.6。

### L2-AUT-005 — revokedFromは一度設定されimmutable
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record `G`（Record 4R、`revokedFrom = 5`）はkey3を初めてrevoke；Record 4Cはgeneration 6で`key3.revokedFrom`を1へ変更しようとする。  
**Action:** verifierはchainをwalkする。  
**Expected:** `revokedFrom`は`current` entriesに欠落、初のrevoking Recordの`recordGeneration`に設定、以後immutable・unchangeable・non-removable；Record 4Cはpost-establishmentの単独mutationとしてphase 5でrejected。  
**Reference:** L2 0.1 draft §9.5；Appendix A.5（Records 4R、4C、scenario E）。

### L2-AUT-006 — Terminal statesはnever currentへ戻らない
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** keyはaccepted historyで`revoked`、`compromised`、`retired`。  
**Action:** 後続Recordがkeyを`current`へ出したり再登録する。  
**Expected:** Recordはchain walkでrejected；revoked、compromised、retired keyはnever `current`へ戻らない；再登録はrejected。  
**Reference:** L2 0.1 draft §9.5、§13.4。

### L2-AUT-007 — Retirement truncation cap
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record `G`はsuccessor keyを導入しoutgoing keyをretire、outgoing keyのprior `validUntil`は`issuedAt(G)`を超える。  
**Action:** verifierはRecord `G`をwalkする。  
**Expected:** retired entryの`validUntil`は`min(previous validUntil, issuedAt(G))`にcapされなければならず（MUST）（Record 1：key1 `validUntil` 1769997000 = `issuedAt(1)`；Record 2：key2 `validUntil` 1769997600 = `issuedAt(2)`）；outgoing keyをuntruncated windowで`current`のまま残すRecord、`validUntil > issuedAt(G)`のretired entryを持つRecordはchain acceptanceでrejectしなければならない（MUST）。  
**Reference:** L2 0.1 draft §9.5、§10.5、§12.2；Appendix A.5（Records 1、2）。

### L2-AUT-008 — statementFloorは全Recordに必須
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** `statementFloor`欠落Records；epochをestablishするRecords；floorをrepeatするRecords。  
**Action:** verifierはchainをwalkする。  
**Expected:** `statementFloor`は全Recordに必須；epoch-establishing Recordはfloor baseline 0を設定（Records 2と5 fixtureは0）；non-transfer Recordは`G-1`のfloorをrepeat；`statementFloor`欠落Recordはrejected。  
**Reference:** L2 0.1 draft §9.5、§12.3；Appendix A.5。

### L2-AUT-009 — transfer memberはepoch導入RecordにREQUIRED、それ以外はFORBIDDEN
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** `epochId`がRecord `G-1`と相違するRecord；`transfer`を持つnon-transfer Record。  
**Action:** verifierはchainをwalkする。  
**Expected:** `epochId`が`G-1`と相違するRecordはfull trailを持つ`transfer`を運ばなければならない（REQUIRED）；`epochId`が`G-1`と一致するRecordは`transfer`を持ってはならない（FORBIDDEN）；両違反はchain acceptanceでRecordをrejectする。  
**Reference:** L2 0.1 draft §9.5、§12.1、§12.2。

### L2-AUT-010 — Trail field setはRecord Gと完全一致
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record 2 fixture trail：`outgoingKeyId` `4KKXd7XSJW8`、`incomingKeyId` `G8VbXumewVs`、trail `epochId` `epoch-0`、`newEpochId` `epoch-1`、`recordGeneration` 2、`statementFloor` 0、`algorithm` `ed25519`、`anchorId`、`entityId`、`l2Version`はRecord 2と一致。  
**Action:** verifierはRecord 1とRecord 2に対して§12.2の全matching ruleを検査する。  
**Expected:** `outgoingKeyId`はRecord 2 `signerKeyId`と一致；`incomingKeyId`はRecord 2が`current`として導入するkeyIdと一致し`validFrom`はRecord 2 `issuedAt`と一致；trail `epochId`はRecord 1 `epochId`と一致；`newEpochId`はRecord 2 `epochId`と一致しchain historyにnever出現；trail `recordGeneration`と`statementFloor`はRecord 2の値と一致；いずれのmismatchもRecord 2をrejectする。  
**Reference:** L2 0.1 draft §12.2；Appendix A.5（Record 2 trail）。

### L2-AUT-011 — Trail forbidden members
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** trail payloadが`keyId`、`generation`、`issuedAt`、`expiresAt`、`descriptionLocation`、`lifecycleStatus`、`effectiveGeneration`のいずれかを持つ。  
**Action:** verifierはRecord `G`をwalkする。  
**Expected:** Recordはrejected：trailはforbidden memberを持ってはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §12.2。

### L2-AUT-012 — Trail signature targetとwindow
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record 2 trailはoutgoing key下でtrail canonical payload上で署名。  
**Action:** verifierはtrail bytesをカバーするRecord 2 signatureを検証後、Record `G-1`（Record 1）に記録されたoutgoing keyの`publicKey`下でtrail signatureを検証する。  
**Expected:** trail signatureはRecord 1に運ばれるkey2 `publicKey`下で検証され、`validFrom <= issuedAt(2) <= validUntil`（`1769997000 <= 1769997600 <= 1770000600`）でのみ受理；Record signatureはtrail canonical bytesをカバー。  
**Reference:** L2 0.1 draft §12.2、§7.3；Appendix A.5（Record 2 trail signature）。

### L2-AUT-013 — Window外で検証されるsignature bytesは受理してはならない
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record 5V（`G = 5`、`epoch-2`、signer key4、`issuedAt` 1770002700、`expiresAt` 1770003900、trailはRecord 5 trailとbyte-identical、`prevRecordDigest` `cf347228c0a32f5b47f10bff1ef86ca3e20b34172dceda39b706280db0a0665d`）。  
**Action:** verifierはRecord 5Vをwalkする。  
**Expected:** key4はRecord 4で`current`、window `1770000900..1770002400`は`issuedAt(5V) = 1770002700`を含まない；trail signature bytesは検証されるが、Recordは受理してはならない（MUST NOT）；chain accepted maximumは4に留まる；key4 window終了後、key4 authority下で`epoch-2`を導入するrecordsは受理してはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §12.2、§9.6、§15.2 phase 5；Appendix A.5（Record 5V）。

### L2-AUT-014 — Missing／unverifiable／duplicated／mismatched trailはchainを停止
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** 何らかのtrail defectを持つRecord `G`。  
**Action:** verifierはRecord `G`をwalkする。  
**Expected:** Record `G`はrejected、chainは`G-1`で停止、依存statementsは`UNVERIFIED`を報告（§9.6）；signerがaccepted prefix内で解明できる場合、defectはnever `UNKNOWN_AUTHORITY`を生じない。  
**Reference:** L2 0.1 draft §9.6、§12.2、§15.2 phase 5、phase 6。

### L2-AUT-015 — Atomic epoch switch：incoming keyは唯一のcurrent key
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** 新epochをestablishするRecord `G`。  
**Action:** verifierはRecord `G`をwalkする。  
**Expected:** Record `G`は`newEpochId`とfloor baseline 0を運び、Record `G-1`の全`current` keysをtruncation cap `min(previous validUntil, issuedAt(G))`で`retired`として印し、trail-approved incoming keyはRecord `G`唯一の`current` key；prior-epoch keyを`current`のまま残すRecord（Record 5 fixture、key3）はrejectしなければならない（MUST）。  
**Reference:** L2 0.1 draft §12.2、§9.5、§12.3；Appendix A.5（Record 5 rejection）。

### L2-AUT-016 — Trailにvalidity windowなし；validityはRecord Gから継承
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** accepted historical trail。  
**Action:** verifierはoutgoing keyの記録window外のはるかに後の`T`でtrailを再検証する。  
**Expected:** trail payloadはvalidity windowを運ばない；validityはchain acceptanceを通じRecord `G`から継承；historical trailsは任意の後続`T`で検証可能、trail verificationはtrailのみをproveしcurrent key statusをproveしない。  
**Reference:** L2 0.1 draft §12.2、§13.4。

### L2-AUT-017 — Epochはchained transferでのみ生成
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** standalone trail、policy-channel trail、supplementary trail、unsigned trail、self-attested trail、incoming key署名trail、administrative、origin、DNS、CDNのnew epoch主張。  
**Action:** verifierは主張を評価する。  
**Expected:** これらはepochを生成しない；新epochをestablishするのは、last accepted `G-1`の次のcontiguous generationのRecord `G`、matching embedded trailのみ。    
**Reference:** L2 0.1 draft §12.2、§9.6。

### L2-AUT-018 — Rotationはcurrent authorityのsigned act
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record 1によるkey2導入rotation。  
**Action:** verifierはRecord 1をwalkする。  
**Expected:** new keyのwindowはsigning時`current`のkey（Record 0のkey1）が署名するRecordによりestablishされ、new keyは自身のwindowを単独でconstructできない；rotationはepochを変えてはならない（`epoch-0`はRecord 1で保持）；generationsは単調増加；overlapping windowsはSHOULD（documented理由なら`PASS-WITH-DEVIATION`）。  
**Reference:** L2 0.1 draft §10.3、§10.4；Appendix A.5（Record 1）。

### L2-AUT-019 — Record G+1のsignerはRecord Gに存在
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record 4（signer key3）はRecord 3に追随；signerが直前Recordのkeys arrayに欠落する任意のRecord。  
**Action:** verifierはRecord `G+1`をwalkする。  
**Expected:** Record `G+1`のsignerはRecord `G`に`publicKey` bytesとして存在しなければならない（key3はRecord 3に存在）；`G`に欠落するsignerは`G+1`でchain acceptanceを切断する（break）、phase 5。  
**Reference:** L2 0.1 draft §9.6、§12.2；Appendix A.5（Record 4、Record 3）。

### L2-AUT-020 — State loss recoveryとcompromise後のrecovery
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** verifierは全Record stateを喪失；別に、chain historyを署名したkeyがcompromise。  
**Action:** verifierはauthorityを再確立する。  
**Expected:** recoveryはpolicy channel経由のTrust Anchor Bindingからのchain walk、trusted lower boundなしでは`VERIFIED` claimを生成できない；chain historyを署名したkeyのcompromise後のrecoveryはNEW Trust Anchor Bindingでre-bootstrapしなければならない（MUST）；compromised initial key下で再構成されたchainはauthority stateとして受理してはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §9.8、§13.4。

### L2-AUT-021 — Self-bootstrapping materialはrejected
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** statementと同一originに提示されるRecord、未accept key下でverify。  
**Action:** verifierはbootstrap materialとしてRecordを評価する。  
**Expected:** statementと同一originのmaterialはindependentではない；Recordは自身のauthorityをbootstrapしてはならず（MUST NOT）、chain walkは受理前に停止する。  
**Reference:** L2 0.1 draft §9.2、§9.3、§9.8。

### L2-AUT-022 — Chain-level keyId recomputation failure
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record entryのwire `keyId`はwire `publicKey`からの再計算`keyId`と一致しない。  
**Action:** verifierはchainをwalkする。  
**Expected:** Recordはauthority materialとしてnot accepted、そのkey下のstatementはphase 5で`UNKNOWN_AUTHORITY`で失敗、statement evaluation上の`INVALID`としてreportしてはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §9.4、§10.1、§15.2 phase 5。

### L2-AUT-023 — Epochはnever二度acceptedされない
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** accepted epochs `epoch-0`、`epoch-1`；chain historyに既acceptのepochを`newEpochId`として提案するtrail。  
**Action:** verifierはRecord `G`をwalkする。  
**Expected:** `newEpochId`はchain historyにnever acceptedのepochでなければならない；Records 5と6のrejected後の`epoch-2`と`epoch-3`はunacceptedのまま、`epoch-0`や`epoch-1`への再進入はchain acceptanceでrejected。  
**Reference:** L2 0.1 draft §9.6、§12.2；Appendix A.5（Records 5、6）。

## 7. Producer cases

### L2-PRO-001 — 単一JCS canonical serializationがsigning target
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** producerはstatement payloadを署名。  
**Action:** producerはpayloadをJCS（RFC 8785）、UTF-16 code-unit member orderでserializeし、canonical payload octetsを署名。  
**Expected:** 同一payloadに対しcanonical serializationはちょうど1つ；signing targetはcanonical payload bytesでありwire envelopeではない；wire canonical stringはpayload逐語（§7.3）。  
**Reference:** L2 0.1 draft §7.3；Appendix A.2。

### L2-PRO-002 — Vector 1はbyte-identicalに再現
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** Appendix A.2 Vector 1 statement payload、signing targetに`epochId`。  
**Action:** producerはcanonical stringとsignatureを再現。  
**Expected:** canonical stringはappendix fixtureとbyte-identical、fixture `publicKey`下でEd25519 signatureは検証成功、`epochId`を含むcanonical stringがsigning target。  
**Reference:** L2 0.1 draft §7.3、§7.2；Appendix A.2、Appendix A.6 reproduction steps。

### L2-PRO-003 — Vector 2：extension membersはUTF-16 code-unit orderでserialize
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** Appendix A.3 Vector 2 statement、extension members付き。  
**Action:** producerはextension members付きでserialize。  
**Expected:** extension membersはrequired membersと並びUTF-16 code-unit orderでsort、canonical stringはappendix fixtureとbyte-identical。  
**Reference:** L2 0.1 draft §7.5、§7.3；Appendix A.3、Appendix A.6。

### L2-PRO-004 — Vector 3：non-BMP UTF-16 ordering fixture
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** Appendix A.4 Vector 3 statement、non-BMP characters入り。  
**Action:** producerはserialize。  
**Expected:** surrogate pairsはappendix fixture通りUTF-16 code unitsでorder、canonical outputはbyte-identical；producerはmemberをlocale、code page、insertion orderでreorderしてはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §7.3、§17 canonicalization row；Appendix A.4、Appendix A.6。

### L2-PRO-005 — Trail canonical bytesはRecord signatureにカバー
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** Record 2 fixture。  
**Action:** producerは`transfer.payload`がtrail canonical string逐語のRecord 2をemitし、Record payloadを署名。  
**Expected:** trail canonical stringは`transfer.payload`として逐語出現、Record signatureはtrail bytesをカバー、wire Recordはtrail canonical bytesをbyte-identicalに含む。  
**Reference:** L2 0.1 draft §12.2、§7.3；Appendix A.5（Record 2）。

### L2-PRO-006 — Producersはduplicate membersをnever emitしない
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** producerはduplicate member nameを含むpayload（`transfer.payload`内含む）を構築。  
**Action:** producerはserialize。  
**Expected:** canonical outputはduplicate member nameを持ってはならず（MUST NOT）、duplicate-member wire textはproducer failure、verifier-side検出はL2-VER-003で試験。  
**Reference:** L2 0.1 draft §7.2、§7.3、§17 canonicalization row。

### L2-PRO-007 — Signatureはenvelopeのみ
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** producerはpayloadを署名。  
**Action:** producerはwire objectをemit。  
**Expected:** signature bytesはenvelopeにのみ出現、payload member内にはnever、`signature`を持つpayloadはproducer failureかつphase 1でrejectされるwire。  
**Reference:** L2 0.1 draft §7.2、§7.3、§17 signature-stripping row。

### L2-PRO-008 — keyId derivationとencodings
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** producerはkeyをpublish。  
**Action:** producerはcanonical `publicKey` encodingから`keyId`を計算。  
**Expected:** `keyId`はcanonical `publicKey` encodingのSHA-256の先頭8 bytesのbase64url（RFC 4648 §5）、`=` strip、ちょうど11 characters、case-sensitive exact comparison、opaque lookup key；ed25519 `publicKey`はraw 32 bytes、signature 64 bytes、§10.8準拠。  
**Reference:** L2 0.1 draft §10.1、§10.8；Appendix A.5 key derivation blocks。

### L2-PRO-009 — Producer key lifecycle practices
**Target:** L2-PRODUCER  
**Strength:** MUST baseline、SHOULD windows  
**Precondition:** producerはrotation、revocation、retirementにわたりRecordsを発行。  
**Action:** producerはRecordsとkey windowsを発行。  
**Expected:** revoked、compromised、retired materialは新たなauthority actをnever署名（revoked materialはrotation authorityではなく、retired keysはnever署名、compromised keysは任何signature・generation・timeで`current`ではない）；rotationはsigning時`current`のkeyのみが発行；short-lived key windows with offline rootはSHOULD（documented理由なら`PASS-WITH-DEVIATION`）；単一permanent keyはSHOULD NOT。  
**Reference:** L2 0.1 draft §13.4、§10.6、§10.7、§10.3。

## 8. Server cases

### L2-SRV-001 — Statement surfaceはCoreに対するadditive
**Target:** L2-SERVER  
**Strength:** MUST  
**Precondition:** Coreにregistered Anchorに対しL2 statementsをservingするResolver。  
**Action:** consumerは§6.1に従いL2 statementをrequest。  
**Expected:** statement endpointはCore 303 resolution pathを変えずに応答；unchanged path上のCore-only consumerはfully conformingのまま；L2はManifest、AR-XML、trustをCore pathへ強制してはならない（MUST NOT）。  
**Reference:** L2 0.1 draft §6.1、§16.1、§16.4。

### L2-SRV-002 — Authority materialは自有surfaceでserving
**Target:** L2-SERVER  
**Strength:** MUST  
**Precondition:** Anchorに対するRecord chain 0..N。  
**Action:** consumerは§6.3に従いauthority materialをrequest。  
**Expected:** endpointはRecord chainをwire canonical form、Record 0からcontiguous、各RecordはTrust Anchor Bindingに対して検証可能な形でserving；material requestsはstatementと同一public surface rulesで応答。  
**Reference:** L2 0.1 draft §6.3、§9.5。

### L2-SRV-003 — Statement／material endpointsはnever mutate
**Target:** L2-SERVER  
**Strength:** MUST  
**Precondition:** 任意のstatementまたはmaterial request、unsafe methods含む。  
**Action:** statement、material、mutation attemptをrequest。  
**Expected:** public surfaceはread-only；requestsはbindings、Records、keys、epochs、floors、revocation stateを変えてはならず（MUST NOT）、mutationはpublic surfaceで到達不能。  
**Reference:** L2 0.1 draft §6.4、§14.1。

### L2-SRV-004 — Extensionsに対するforward compatibility
**Target:** L2-SERVER  
**Strength:** MUST  
**Precondition:** serverにunknown extension memberを含むwire object。  
**Action:** serverはrequestを処理。  
**Expected:** serverはrequest pathのunknown extension membersでwireをrejectしてはならず（MUST NOT）、payload signing target内のregistered extension membersをbyte-identicalに保持し、L2 case identifierを定義してはならない（MUST NOT）；extensionsはadditive、never required、never prohibitive。  
**Reference:** L2 0.1 draft §6.5、§7.5、§19。

### L2-SRV-005 — Statementsのcache policy
**Target:** L2-SERVER  
**Strength:** MUST  
**Precondition:** serverはstatementsをserving。  
**Action:** serverはcache policyを設定しchoiceを記録。  
**Expected:** statementsは適度にshort `max-age`、no-store、revalidationを使用；serverはcache-policy choiceを記録しなければならず（MUST）、HTTP cache reusabilityはL2 currencyと別、`VERIFIED`は§11で定義されHTTP freshnessではない。  
**Reference:** L2 0.1 draft §14.2、§13.5、§11.4。

### L2-SRV-006 — Referrer-Policy：no-referrerはSHOULD
**Target:** L2-SERVER  
**Strength:** SHOULD  
**Precondition:** statementおよびmaterial endpoints。  
**Action:** serverは`Referrer-Policy`を設定。  
**Expected:** `Referrer-Policy: no-referrer` SHOULD設定；逸脱はdocumented理由付き`PASS-WITH-DEVIATION`としてreportしなければならない（MUST）。  
**Reference:** L2 0.1 draft §14.4。

### L2-SRV-007 — Privacy：Anchor UUIDsはL1-level metadata
**Target:** L2-SERVER  
**Strength:** MUST  
**Precondition:** serverはstatement requestsをlogs。  
**Action:** serverはrequest metadataを扱う。  
**Expected:** Anchor UUIDsはL1と同レベルのloggable metadataとして最小化；`l`と`p` parametersはL2ためにCore定義を超えて拡張してはならない（MUST NOT）；privacy postureは§14.3に従う。  
**Reference:** L2 0.1 draft §14.3。

### L2-SRV-008 — Reference Resolver boundary
**Target:** L2-SERVER  
**Strength:** MUST  
**Precondition:** Reference ResolverはL2 statementsを発行。  
**Action:** statementsとmaterialを発行。  
**Expected:** L2 issuanceはAR-XMLをfetch・parse・validateしてはならず（MUST NOT）、management UI、device IP、capability endpointsをinvokeしてはならず、public mutationsを実行してはならず、surfaceはadditiveかつoptional；Core conformanceはL2なしでvalid；administrative authenticationはL2 authorityではなく（admin channelsはpolicy connections、protocol identityではない）；L2はOAuth、OIDC、SSO、RBAC、WebAuthn、他のadmin mechanismをrequire・replace・prohibitしてはならず；501 guidanceはL1 pathを決してdowngradeしない。  
**Reference:** L2 0.1 draft §16.3、§16.4、§6.1、§17 downgrade row。

## 9. Testbed handoff notes

§20 chainは次のとおり：

```text
Resolver L2 0.1 spec (this repository, merged)
L2 conformance catalog (this document)
Reference Resolver implementation (this repository)
relink-testbed executable scenarios
Reference Lab integration
```

- このcatalogはcatalogが所有するcase identifiersを定義する；L2 draftは`RES-*`、`LIFE-*`、`MAN-*`、`INT-*`、`NET-*`、`RT-*`、L2、case IDsを発明してはならず（MUST NOT）、本書は0.1 setのため`L2-VER-*`、`L2-AUT-*`、`L2-PRO-*`、`L2-SRV-*`を割り当て、L2 draftと同時にFrozenされる。
- 実行可能scenariosは`relink-testbed`に存在しなければならず（MUST）、`relink-resolver`内のlocal PHPUnit resultsは`relink-testbed` resultsとしてreportしてはならない（MUST NOT）。
- §20 targetsへのcoverage mapping：L2-VER-*はL2-VERIFIER（state model、§15 states、phase order、fail-closed）、L2-AUT-*はL2-AUTHORITY（Record chain、trail、rotation、revocation、bootstrap、state loss）、L2-PRO-*はL2-PRODUCER（canonical serialization、signing bytes、keyId derivation、wire discipline）、L2-SRV-*はL2-SERVER（statementとmaterial endpoints、cache、privacy、forward compatibility、Reference Resolver boundary）を試験する。
- Reference Resolver implementationは`composer validate`、`composer test`、`composer analyse`、`php -l`、`git diff --check`を通らなければならず（MUST）、これらはimplementation workに適用し本書には適用しない。
- Reportは実行したconformance set(s)をtarget別に明示することを推奨し、targetsを1つのoverall PASS lineへcollapseしてはならない（MUST NOT）。
- Case subdivision、result classes、strength handling、reportingは§§2-4に従い、Testbedは§5-§8の全caseを、親IDを保持しつつ必要に応じてsubdivideして実装する。
