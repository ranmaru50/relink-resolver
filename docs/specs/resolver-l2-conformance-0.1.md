# RELink Resolver L2 Conformance Catalog 0.1

Status: Draft 0.1, tied to the merged L2 0.1 draft; freezes with that draft  
Version: 0.1-draft  
Freeze date: not frozen; freeze applies when the L2 0.1 draft freezes  
Scope: Resolver L2 0.1 draft, all sections and Appendix A test vectors, across the four §20 conformance targets

> **Freeze policy:** This catalog freezes together with the L2 0.1 draft it binds to. Editorial or non-semantic errata MAY be corrected within 0.1. Changes to conformance targets, result semantics, case identifiers, normative case expectations, baseline/optional classification, or trust-material semantics require a later catalog version.

## 1. Purpose

This document defines deterministic, implementation-independent conformance cases for Resolver L2 0.1. It is the conformance catalog named by the L2 0.1 draft §20, the second deliverable of the §20 handoff chain: Resolver L2 spec, L2 conformance catalog, Reference Resolver implementation, `relink-testbed` scenarios, Reference Lab.

Case identifiers owned by this catalog (`L2-VER-*`, `L2-AUT-*`, `L2-PRO-*`, `L2-SRV-*`) are assigned here, not by the L2 draft. The L2 draft MUST NOT define, reserve, or invent case identifiers; these identifiers freeze at catalog freeze together with the L2 draft.

The catalog is intended to be implemented by `relink-testbed`, but executable test code is outside the scope of this repository.

```text
relink-resolver
= protocol + conformance definition

relink-testbed
= executable test implementation
```

Tests MUST evaluate externally observable protocol behavior and representation semantics rather than internal PHP, SQLite, framework, process, or deployment details. Local PHPUnit results in this repository MUST NOT be reported as `relink-testbed` results.

## 2. Conformance targets and case model

A catalog case MUST identify the role being tested. The defined L2 conformance targets, as named by the L2 draft §20, are:

```text
L2-SERVER
L2-PRODUCER
L2-VERIFIER
L2-AUTHORITY
```

- **L2-SERVER**: statement and authority-material endpoint behavior on the public surface;
- **L2-PRODUCER**: canonical serialization, signing-byte production, and key-lifecycle issuance practices;
- **L2-VERIFIER**: the result state model, every §15 state, and all fail-closed rules;
- **L2-AUTHORITY**: rotation, transfer, trail, and revocation signature semantics.

A single implementation MAY claim more than one target, but results for different targets MUST NOT be collapsed into an ambiguous overall “Resolver L2 PASS”.

Each test case has:

- **ID**: stable catalog identifier;
- **Target**: conformance role under test;
- **Strength**: normative strength primarily exercised by the case (`MUST`, `SHOULD`, or `MAY`);
- **Precondition**: externally relevant state or fixture;
- **Action/Input**: request, statement, Record, or raw representation presented to the system under test;
- **Expected**: required observable result;
- **Reference**: governing RELink L2 specification area.

A Testbed implementation MAY subdivide a catalog case into multiple executable tests, but SHOULD preserve the catalog ID in reporting.

## 3. Result classes and normative-strength handling

The Testbed SHOULD distinguish at least:

```text
PASS
FAIL
PASS-WITH-DEVIATION
NOT-APPLICABLE
UNSUPPORTED-OPTIONAL
```

A failure to satisfy a normative **MUST** or **MUST NOT** is a conformance failure for the relevant target and MUST be reported as `FAIL`.

A **SHOULD** or **SHOULD NOT** deviation MUST be reported. It does not automatically constitute baseline conformance failure when the implementation documents a valid reason for the deviation. Such a result SHOULD be reported as `PASS-WITH-DEVIATION` unless another profile promotes that requirement to mandatory.

A **MAY** case tests permitted behavior or optional interoperability. Absence of an optional behavior MUST NOT be reported as a baseline failure. `UNSUPPORTED-OPTIONAL` is appropriate only for optional functionality that the relevant baseline target does not require, such as statement revocation (extension, §13.1, §21) or ES256 key agility (§10.2).

## 4. Conformance sets

The catalog defines the following reporting sets:

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

A report SHOULD state exactly which conformance set(s) were executed.

The L2 0.1 baseline is: ed25519 as the single baseline algorithm, the Record chain, transfer with trail, key revocation, the §15 state model, the `statement-only` verification mode, and the §7.4 `l1-bound` consistency checks (L2-VER-038–040, §15.1, §19). Statement revocation, ES256, and Manifest integrity pinning are outside the L2 0.1 baseline; Manifest integrity pinning is not merged into L2 conformance at all.

## 5. Verifier cases

### L2-VER-001 — Fresh snapshot yields VERIFIED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Trust Anchor Binding (anchor `550e8400-e29b-41d4-a716-446655440000`, entity `urn:relink:entity:thermal-sensor-42`, key1 `ECMERdvpV0k`, `epoch-0`, floor 0); Records 0, 1, 2, 3 accepted by chain walk; `T = 1769998400`, `W = 600`; statement A under key3 `G8VbXumewVs` (`epoch-1`, `generation = 1`, `issuedAt` 1769998300, `expiresAt` 1770001200) on canonical wire.  
**Action:** verifier evaluates statement A at `T` in `statement-only` mode.  
**Expected:** `VERIFIED`: snapshot Record 3 is fresh (`1769998200 <= T <= 1770001800`, `T - issuedAt = 200 <= W`), key3 `current` in snapshot with window `1769997600..1770001200` containing the statement `issuedAt`, signature verifies over canonical payload bytes, `epochId` equals the accepted epoch, `generation = 1` strictly exceeds the floor 0 (floor equality is not a valid statement position, §12.3), and `generation` exceeds the accepted statement maximum.  
**Reference:** L2 0.1 draft §15.2, §11.4, §9.6; Appendix A.5.

### L2-VER-002 — keyId recomputed from wire publicKey
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** any statement or Record entry on canonical wire.  
**Action:** verifier recomputes `keyId` from the wire `publicKey` per §10.1 before accepting the entry, and compares case-sensitively for exact equality.  
**Expected:** an entry whose recomputed `keyId` differs from its wire `keyId` MUST NOT be accepted as authority material; a statement under such a key fails with `UNKNOWN_AUTHORITY` at the chain phase (§9.4, phase 5).  
**Reference:** L2 0.1 draft §9.4, §10.1, §15.2.

### L2-VER-003 — Wire canonicality is checked first
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** raw wire text presented to the verifier.  
**Action:** verifier applies strict parse, duplicate-member detection in raw wire text, integer range, `l2Version`, payload/signature separation, and `keyId` recomputation before any trust evaluation.  
**Expected:** `INVALID` at phase 1, never `UNVERIFIED`; where the subcase precondition is the `keyId` recomputation mismatch, the dependent statement fails at phase 5 (`UNKNOWN_AUTHORITY`, §9.4); the other subcases are judged by their own fixtures.  
**Reference:** L2 0.1 draft §7.2, §7.3, §15.2 phase 1.

The Testbed SHOULD implement at least these subcases while retaining the parent catalog ID:

```text
L2-VER-003/duplicate-members
L2-VER-003/duplicate-members-in-transfer-payload
L2-VER-003/non-integer-timestamp
L2-VER-003/l2version-mismatch
L2-VER-003/signature-inside-payload
L2-VER-003/keyid-recompute-mismatch
```

### L2-VER-004 — ANCHOR_MISMATCH at phase 2
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** verifier configured with expected anchor `550e8400-e29b-41d4-a716-446655440000`; statement carrying a different `anchorId`.  
**Action:** verifier evaluates the statement.  
**Expected:** `ANCHOR_MISMATCH` at phase 2, before any trust-chain, freshness, or signature evaluation.  
**Reference:** L2 0.1 draft §15.1, §15.2 phase 2.

### L2-VER-005 — UNSUPPORTED_ALGORITHM at phase 3
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** statement declaring a proprietary, licensed, or unknown algorithm outside the §10.2 baseline.  
**Action:** verifier evaluates the statement.  
**Expected:** `UNSUPPORTED_ALGORITHM` at phase 3; fail-closed, not `VERIFIED` and not `UNVERIFIED`.  
**Reference:** L2 0.1 draft §10.2, §10.8, §15.2 phase 3.

### L2-VER-006 — No trusted clock yields UNVERIFIED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** verifier has no trusted clock source available.  
**Action:** verifier evaluates any statement.  
**Expected:** `UNVERIFIED` at phase 4; the verifier MUST NOT make freshness judgments and MUST NOT surface the result as `VERIFIED` or as an Entity failure.  
**Reference:** L2 0.1 draft §11.2, §15.2 phase 4.

### L2-VER-007 — Missing trust material yields UNVERIFIED at phase 5
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** verifier holds no authority material reaching the statement signer; chain walk has a gap.  
**Action:** verifier evaluates the statement.  
**Expected:** `UNVERIFIED` at phase 5 (chain walk gap, missing material, no trusted lower bound), not phase 6; `UNKNOWN_AUTHORITY` only for a signer resolvably outside the accepted chain.  
**Reference:** L2 0.1 draft §9.6, §9.8, §15.2 phase 5.

### L2-VER-008 — Signer outside accepted chain yields UNKNOWN_AUTHORITY
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** statement signed by a key that is not resolvable inside the accepted Record chain.  
**Action:** verifier evaluates the statement.  
**Expected:** `UNKNOWN_AUTHORITY` at phase 5; never `VERIFIED`, never `UNVERIFIED` presented as a trust-knowledge failure, never an Entity failure.  
**Reference:** L2 0.1 draft §9.4, §15.2 phase 5.

### L2-VER-009 — Record 0 self-sign through Trust Anchor Binding
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Trust Anchor Binding carrying `anchorId`, initial algorithm `ed25519`, full-length key1 `publicKey`, `keyId` `ECMERdvpV0k` recomputed per §10.1, initial `epoch-0`, initial floor 0.  
**Action:** verifier accepts Record 0 (self-signed, `recordGeneration = 0`, `prevRecordDigest` null, floor 0, `issuedAt` 1769996400, `expiresAt` 1770000000).  
**Expected:** Record 0 is accepted only through the policy channel that delivered the Trust Anchor Binding; first-contact material without chain provenance MUST NOT be trusted.  
**Reference:** L2 0.1 draft §9.3, §9.8; Appendix A.5 (Record 0).

### L2-VER-010 — Record 1 accepted with retirement truncation cap
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0 accepted; Record 1 (`recordGeneration = 1`, signer key1, `issuedAt` 1769997000, `expiresAt` 1770000600, `epoch-0`, `prevRecordDigest` `36ce3c7bf819434ebc79e534649873081ee0999af85c798c6586235680809afb`) introduces key2 `4KKXd7XSJW8` as `current` (`validFrom` 1769997000) and retires key1 with `validUntil` 1769997000.  
**Action:** verifier walks the chain from the Trust Anchor Binding.  
**Expected:** Record 1 accepted (signer key1 `current` in Record 0 snapshot, digest matches, retirement `validUntil` capped at `min(previous validUntil, issuedAt(1))`); chain accepted maximum becomes 1.  
**Reference:** L2 0.1 draft §9.6, §9.5; Appendix A.5 (Record 1).

### L2-VER-011 — Record 2 epoch switch accepted via trail
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0, 1 accepted; Record 2 (`recordGeneration = 2`, `signerKeyId` `4KKXd7XSJW8`, `issuedAt` 1769997600, `epoch-1`) embeds the trail `outgoingKeyId` `4KKXd7XSJW8` → `incomingKeyId` `G8VbXumewVs`, trail `epochId` `epoch-0`, `newEpochId` `epoch-1`, trail signature over the trail canonical payload.  
**Action:** verifier walks Record 2, verifying the Record signature under key2, `current` in Record 1 with window `1769997000..1770000600` containing `issuedAt(2)`, and the trail signature under the `publicKey` recorded for key2 in Record 1.  
**Expected:** Record 2 accepted; all §12.2 matching rules confirmed; atomic epoch switch to `epoch-1` with floor baseline 0; key3 `current` with window `1769997600..1770001200`.  
**Reference:** L2 0.1 draft §9.6, §12.2; Appendix A.5 (Record 2).

### L2-VER-012 — Record 3 accepted as first Record signed by incoming key
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0..2 accepted; Record 3 (`recordGeneration = 3`, signer key3 `G8VbXumewVs`, `issuedAt` 1769998200, `expiresAt` 1770001800, `epoch-1`, `prevRecordDigest` `39808d467859fcad7589d70d54e0666038ac039d4956964ddda751f10f3e3234`).  
**Action:** verifier walks Record 3; key3 window `1769997600..1770001200` contains `issuedAt(3)`; signer key3 present in Record 2 with `publicKey` bytes.  
**Expected:** Record 3 accepted; chain accepted maximum becomes 3; the revocation snapshot at `T = 1769998400` is Record 3, fresh (`200 <= W = 600`).  
**Reference:** L2 0.1 draft §9.6, §11.4; Appendix A.5 (Record 3).

### L2-VER-013 — Snapshot Record k is chosen before statement freshness
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** effective prefix `0..3` at `T = 1769998400`, `W = 600`; `k = 3` (highest accepted `recordGeneration` with `issuedAt(k) <= T` under the non-decreasing rule).  
**Action:** verifier selects the snapshot Record first, checks snapshot freshness, then evaluates statement freshness.  
**Expected:** snapshot Record 3 is selected and checked for freshness independently of the statement; the statement is judged fresh at `T = 1769998400` (`1769998300 <= T <= 1770001200`); snapshot freshness is evaluated on Record 3's `[issuedAt, expiresAt]` and `W`, not on statement fields.  
**Reference:** L2 0.1 draft §11.4, §15.2 phase 6, phase 10.

### L2-VER-014 — Chain failures split by input class: wire duplicates, trail failures, signer absent, missing material
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Record 2 fixtures: raw wire object with duplicate member names; structurally valid Record 2 with a missing, tampered, duplicated, or mismatched trail; statement A signed under key3 `G8VbXumewVs` evaluated against the chain `0..1`.  
**Action:** verifier evaluates each input class separately at the chain walk.  
**Expected:** the subcases yield split verdicts, never one blanket result:  
The Testbed SHOULD implement at least these subcases while retaining the parent catalog ID:

```text
L2-VER-014/record-wire-duplicate: raw wire text with duplicate member names -> INVALID at wire canonicality, the Record rejected, chain stops at Record 1
L2-VER-014/trail-verification-failure: structurally valid Record 2 whose trail fails field-set matching (missing, tampered, duplicated, mismatched) -> chain acceptance fails at phase 5, chain stops at Record 1
L2-VER-014/statement-a-signer-absent: statement A signed by key3, key3 absent from the accepted chain 0..1 -> UNKNOWN_AUTHORITY at phase 5, never reaches phases 8-9, never VERIFIED (signer resolvability is part of the chain walk)
L2-VER-014/statement-a-material-missing: chain gap, missing material, no trusted lower bound -> UNVERIFIED at phase 5, chain walk stops, statement not evaluated
L2-VER-014/chain-accepted-snapshot-gap: chain walk succeeds for 0..3, effective snapshot Record 3 is not fresh at T or fails coverage -> UNVERIFIED at phase 6, never VERIFIED
```
**Reference:** L2 0.1 draft §15.2 phase 1, phase 5, phase 6, §15.1; §9.4, §9.6, §9.8, §12.2; Appendix A.5.

### L2-VER-015 — Record 4 accepted; S6 is STALE at phase 10
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0..3 accepted; Record 4 (`recordGeneration = 4`, `epoch-1`, signer key3, `issuedAt` 1770000900, `expiresAt` 1770002400, key4 `ECYh7yJFAn8` introduced `current` `1770000900..1770002400`); statement S6 (key4, `epoch-2`, `generation = 1`, `issuedAt` 1770001100, `expiresAt` 1770002400); `T6 = 1770001200`, `W = 600`.  
**Action:** verifier evaluates S6 at `T6`.  
**Expected:** Records 0..4 accepted (key3 window `1769997600..1770001200` contains `issuedAt(4)`); snapshot Record 4 fresh at `T6` (`300 <= W`); `epoch-2` is not the accepted epoch and is introduced only by rejected Record 5, so phase 9 is skipped and phase 10 yields `STALE` — never `VERIFIED`, never `UNVERIFIED`, never `REVOKED`.  
**Reference:** L2 0.1 draft §15.2 phase 10, §13.4; Appendix A.5 (Record 4, S6).

### L2-VER-016 — Record 5 rejected by trail matching, not by signature
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0..4 accepted; Record 5 (`G = 5`, `epoch-2`, signer key4, `issuedAt` 1770001500) embeds trail `epoch-1` → `epoch-2`, key4 → key5 `cIbUqaV2qxI`; Record 5 keeps key3 `current` and carries key5 as one `current` key among two.  
**Action:** verifier walks Record 5.  
**Expected:** Record 5 rejected at trail matching, phase 5: §12.2 requires every key `current` in Record 4 (key3 and key4) to be retired with `validUntil` capped at `min(previous validUntil, issuedAt(5))` — key3 `min(1770002400, 1770001500) = 1770001500`, key4 `min(1770002400, 1770001500) = 1770001500` — and the trail-approved incoming key key5 to be the sole `current` key. The signature verifies cryptographically under key4 `publicKey`; the matching-rule failure, not the signature, is the rejection; chain stops at Record 4; Records introducing `epoch-2` signed under key4 MUST NOT be accepted.  
**Reference:** L2 0.1 draft §12.2, §9.6; Appendix A.5 (Record 5).

### L2-VER-017 — Record 6 rejected by contiguity; epochs 2 and 3 never accepted
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Record 5 rejected (max stays 4); Record 6 (`G = 6`, `epoch-3`, signer key4, `issuedAt` 1770001800) embeds trail `epoch-2` → `epoch-3`.  
**Action:** verifier walks Record 6.  
**Expected:** Record 6 rejected at chain walk: `prevRecordDigest` references a Record that was never accepted, contiguity fails; chain accepted maximum stays 4; `epoch-2` and `epoch-3` are never accepted epochs.  
**Reference:** L2 0.1 draft §9.6, §12.2, §9.6 epoch rule; Appendix A.5 (Record 6).

### L2-VER-018 — Record issuedAt beyond T is outside the effective prefix
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0..3 accepted plus Record 4F (`recordGeneration = 4`, `epoch-1`, signer key3, `issuedAt` 1769999000, `expiresAt` 1770002000, keys array as Record 3); `T = 1769998400`, `W = 600`; statement A.  
**Action:** verifier evaluates statement A at `T`.  
**Expected:** Record 4F is accepted by chain walk; at `T` the effective prefix is `0..3` — Record 4F has `issuedAt > T` and is outside the prefix; snapshot Record 3 is fresh and statement A is `VERIFIED` via snapshot Record 3; Record 4F's keys, floor, and epoch data MUST NOT contribute revocation, epoch, floor, or key evidence at `T`; the existence of a Record with `issuedAt > T` is not a phase-6 failure.  
**Reference:** L2 0.1 draft §11.4, §9.7, §15.2 phase 6; Appendix A.5 (Record 4F).

### L2-VER-019 — No fresh snapshot at T′ yields UNVERIFIED; back-off prohibited
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0..3, 4F accepted; `T′ = 1770000000`, `W = 600`.  
**Action:** verifier evaluates statement A at `T′`.  
**Expected:** effective prefix `0..4`; effective-latest Record 4F is not fresh (`T′ - 1769999000 > W`); Record 3 is also not fresh at `T′` (`T′ - 1769998200 > W`); backing off from Record 4F to Record 3 is in any case prohibited; no fresh snapshot, so `UNVERIFIED`. Shifting `T`, rewriting `issuedAt`, backing off, or selecting an out-of-window Record MUST NOT yield `VERIFIED`.  
**Reference:** L2 0.1 draft §11.4, §9.7, §15.2 phase 6; Appendix A.5 (Record 4F).

### L2-VER-020 — Effective-latest Record not fresh: statement UNVERIFIED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** scenario D, chain Records `0..3, 4E` accepted; Record 4E (`recordGeneration = 4`, signer key3, `issuedAt` 1769998250, `expiresAt` 1769998300); `T = 1769998700`, `W = 600`; statement S7, byte-identical to statement A, fresh at `T` (`T - issuedAt = 400 <= W`).  
**Action:** verifier evaluates S7 at `T`.  
**Expected:** effective prefix `0..4`, effective-latest Record 4E, whose `expiresAt` `1769998300 < T`: snapshot not fresh; S7 is `UNVERIFIED` at phase 6, never `VERIFIED`. Record 3 would be fresh at `T` (`500 <= W`); backing off to Record 3 is prohibited — an older in-window Record MUST NOT be selected when the effective-latest Record of the effective prefix is not fresh.  
**Reference:** L2 0.1 draft §11.4, §15.2 phase 6; Appendix A.5 (Record 4E, S7, scenario D).

### L2-VER-021 — VERIFIED only at the boundary T where the snapshot is fresh
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** scenario D chain `0..3, 4E`; `T = 1769998300`, `W = 600`; statement S7.  
**Action:** verifier evaluates S7 at `T = 1769998300`.  
**Expected:** Record 4E is fresh at `T` (`T <= expiresAt` `1769998300` with equality, `T - issuedAt = 50 <= W`) and S7 is effective at `T`; `VERIFIED` holds at this `T` only. At `T = 1769998700`, S7 is window-valid (`1769998300 <= T <= 1770001200`), but the effective latest Record 4E is not fresh (`expiresAt` 1769998300 `< T`), so the result is `UNVERIFIED` at phase 6, never `VERIFIED`. At `T < 1769998250`, the effective prefix is `0..3` with the fresh snapshot Record 3, S7 is not effective (`issuedAt` 1769998300 `> T`), it fails at phase 10, not phase 6, and is never `VERIFIED`; shifting `T`, rewriting `issuedAt`, backing off, and selecting out-of-window Records never yield `VERIFIED`.  
**Reference:** L2 0.1 draft §11.4, §15.2 phase 6, phase 10; Appendix A.5 (scenario D).

### L2-VER-022 — Effective prefix and k by non-decreasing rule
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** accepted chain `0..5` with non-decreasing `issuedAt`; `T` inside the chain; Record with `recordGeneration = 5` carries `issuedAt > T`.  
**Action:** verifier computes the effective prefix at `T`.  
**Expected:** the effective prefix is the longest contiguous accepted prefix with all `issuedAt <= T`; `k` is the highest accepted `recordGeneration` with `issuedAt(k) <= T` under the non-decreasing rule; Records with `issuedAt > T` are excluded from the prefix and their existence is not a phase-6 failure.  
**Reference:** L2 0.1 draft §11.4, §15.2 phase 6; Appendix A.5 (Records 4F, 4B, 5V).

### L2-VER-023 — Retransmission never refreshes the freshness point
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** Records 0..2 delivered repeatedly at `T = 1769998400`; Record 3 fresh.  
**Action:** verifier re-fetches, retransmits, re-presents, and revalidates Records 0..2.  
**Expected:** snapshot freshness never moves: the freshness point is the signed `issuedAt` of snapshot Record 3, the only freshness point; re-fetch, retransmission, re-presentation, and HTTP revalidation NEVER update it.  
**Reference:** L2 0.1 draft §11.4, §9.7; Appendix A.5.

### L2-VER-024 — Clock skew tolerance never manufactures freshness
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** documented skew allowance `S`; the freshness condition is `issuedAt <= T <= expiresAt` and `T - issuedAt <= W`; the snapshot fails that condition at `T`.  
**Action:** verifier evaluates any statement under skew conditions.  
**Expected:** the verifier MUST NOT shift `T`, rewrite `issuedAt`, or select an out-of-window Record to manufacture `VERIFIED`; `S` only absorbs clock tolerance within documented bounds, inside the freshness condition above.  
**Reference:** L2 0.1 draft §11.2, §11.6, §11.4.

### L2-VER-025 — Prior-epoch statement is STALE with phase 9 skipped
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** scenario B, chain `0..3` accepted, accepted epoch `epoch-1`; statement S5 (key2 `4KKXd7XSJW8`, `epoch-0`, `generation = 1`, `issuedAt` 1769997200, `expiresAt` 1770001000); `T = 1769998400`, `W = 600`; key2 is `retired` in snapshot Record 3.  
**Action:** verifier evaluates S5 at `T`.  
**Expected:** S5 is window-valid; `epoch-0` does not equal the accepted epoch, so the phase 9 key-status judgment is skipped — key status is not evaluated — and phase 10 yields `STALE` for the prior epoch. S5 MUST NOT be `VERIFIED`, `REVOKED`, `UNVERIFIED`, or `CONFLICT`.  
**Reference:** L2 0.1 draft §15.2 phase 9, phase 10, §13.4, §13.1; Appendix A.5 (S5).

### L2-VER-026 — REVOKED at the revocation boundary, never VERIFIED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** scenario E, common accepted prefix `0..3, 4A, 4R`; Record 4R (`recordGeneration = 5`, signer key3, `issuedAt` 1769998400) revokes key3 with `revokedFrom = 5`; statement S8 under key3; `T = 1769998400`, `W = 600`; effective prefix `0..5`, snapshot Record 4R fresh (`T - issuedAt = 0`).  
**Action:** verifier evaluates S8 at `T`.  
**Expected:** S8 is `REVOKED` at phase 9 at the revocation boundary, with fresh evidence; never `VERIFIED`; a revoked key MUST NOT be surfaced as `current`.  
**Reference:** L2 0.1 draft §13.4, §15.2 phase 9; Appendix A.5 (Record 4R, S8, scenario E).

### L2-VER-027 — Replay of an identical statement within the window is accepted
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** accepted chain, fresh snapshot; statement replayed byte-identically within `[issuedAt, expiresAt]`.  
**Action:** verifier evaluates the replayed presentation.  
**Expected:** replay of an identical statement within its window is accepted (`VERIFIED` if all phases pass); the result records which phase passed, with no replay penalty for byte-identical presentation.  
**Reference:** L2 0.1 draft §15.2 phase 7, phase 10; §17 replay row.

### L2-VER-028 — Same epoch and generation with different payload is CONFLICT
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** accepted chain; two statements in the same `epochId` and same `generation` with different payloads.  
**Action:** verifier evaluates the second statement.  
**Expected:** `CONFLICT` at phase 10 (equivocation); fail-closed with no heuristic choice between the two payloads.  
**Reference:** L2 0.1 draft §9.7, §15.2 phase 10, phase 7.

### L2-VER-029 — Record chain position: below accepted maximum is STALE, same generation with different payload is CONFLICT
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** accepted chain with accepted maximum `recordGeneration = G`; a byte-identical Record presented at a lower position, a byte-identical Record presented at position `G`, and a Record presented at position `G` with a different payload.  
**Action:** verifier evaluates Record positions at §15.2 phase 7.  
**Expected:** a Record presented at a position below the accepted maximum is `STALE`; a same-generation byte-identical presentation is a replay, not freshness progress (§11.3); a same-generation Record with a different payload is `CONFLICT` and MUST NOT be accepted, the chain never accepts it; statement `generation` positions are judged only at phase 10 (L2-VER-030), never at phase 7, and Record generations and statement generations are never compared to each other (§7.2).  
**Reference:** L2 0.1 draft §15.2 phase 7, phase 10; §9.7, §11.3, §12.3; §7.2.

### L2-VER-030 — Statement generation below floor is STALE
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** accepted chain with statement floor; statement `generation` below the floor.  
**Action:** verifier evaluates the statement.  
**Expected:** `STALE` at phase 10 for the below-floor generation, never `VERIFIED`.  
**Reference:** L2 0.1 draft §12.3, §15.2 phase 10; Appendix A.5 (floor baseline 0).

### L2-VER-031 — Statement outside its window is EXPIRED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** fresh snapshot; statement `expiresAt < T` in the accepted epoch.  
**Action:** verifier evaluates the statement at `T`.  
**Expected:** `EXPIRED` at phase 10; not `VERIFIED`, not `UNVERIFIED`.  
**Reference:** L2 0.1 draft §11.1, §15.2 phase 10.

### L2-VER-032 — VERIFIED asserts nothing beyond T
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** statement `VERIFIED` at `T`; evaluation at a later `T′ > T`.  
**Action:** verifier re-evaluates at `T′`.  
**Expected:** `VERIFIED` asserts nothing after `T`; the verifier re-evaluates at `T′` with the snapshot effective at `T′`; a cached `VERIFIED` at `T′` MUST NOT be asserted.  
**Reference:** L2 0.1 draft §13.5, §11.4, §15.2.

### L2-VER-033 — Snapshot coverage failure yields UNVERIFIED; the statement epoch is not checked at phase 6
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** chain `0..3` accepted; snapshot Record 3 fresh at `T = 1769998400`, covering the current accepted epoch `epoch-1`, the signer key material, the chain, and the statement floor as recorded in the effective prefix; statement S5 (`epoch-0`).  
**Action:** verifier evaluates snapshot coverage at phase 6, then the statement through the fixed phase order.  
**Expected:** the snapshot must cover the current accepted epoch, the signer key material, the chain, and the floor recorded in the effective prefix; a coverage failure is `UNVERIFIED` at phase 6; the phase-6 coverage check does NOT evaluate the statement's own `epochId`; a statement whose `epochId` differs from the current accepted epoch skips the phase-9 key-status judgment and is `STALE` at phase 10 (L2-VER-015, L2-VER-025), never `UNVERIFIED` at phase 6; `VERIFIED` MUST NOT rely on evidence from Records outside the effective prefix.  
**Reference:** L2 0.1 draft §11.4, §15.2 phase 6, phase 9, phase 10; Appendix A.5 (S5, S6).

### L2-VER-034 — Statement under a retired key is UNVERIFIED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** key retired in the fresh snapshot; statement under that key in the accepted epoch, window-valid.  
**Action:** verifier evaluates the statement.  
**Expected:** `UNVERIFIED` at phase 9 when the retirement is known; retirement is not compromise, and the result is never `REVOKED` and never `VERIFIED`.  
**Reference:** L2 0.1 draft §10.5, §13.4, §15.2 phase 9.

### L2-VER-035 — Statement under a revoked key is REVOKED with fresh evidence
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** fresh snapshot covering the revocation Record; statement `issuedAt >=` the revocation Record `issuedAt` under the revoked key.  
**Action:** verifier evaluates the statement.  
**Expected:** `REVOKED` at phase 9; statements below the revocation `issuedAt` are governed by §11.3 anti-rollback; revoked material MUST NOT serve as rotation authority.  
**Reference:** L2 0.1 draft §13.4, §11.3, §15.2 phase 9; Appendix A.5 (Record 4R).

### L2-VER-036 — Re-registration of a terminal key is rejected
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** key `revoked`, `compromised`, or `retired` in accepted chain history; a later Record attempts to re-register the same `keyId` against a different `publicKey` or to return it to `current`.  
**Action:** verifier walks the chain and evaluates a statement under the re-registered key.  
**Expected:** the Record is rejected at chain acceptance (terminal irreversibility); the statement fails at phase 5 with `UNKNOWN_AUTHORITY`, at phase 9 with `REVOKED` when history shows revocation, or with `UNVERIFIED` for retired keys; never `VERIFIED`.  
**Reference:** L2 0.1 draft §9.5, §13.4, §15.2 phase 5, phase 9.

### L2-VER-037 — revokedFrom mutation rejected; tip stays at the revocation Record
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** scenario E, common accepted prefix `0..3, 4A, 4R` at `T = 1769998400`, `W = 600`; negative branch adds Record 4C alone (byte-identical to Record 4B except `key3.revokedFrom = 1`).  
**Action:** verifier walks the chain and evaluates S8 at `T`.  
**Expected:** Record 4C is rejected at phase 5 as the sole post-establishment `revokedFrom` mutation; the chain tip stays at generation 5; Record 4C is never a snapshot, cannot restore key3, and MUST NOT change `S8` from `REVOKED`; Records 4B/4C with `issuedAt > T` are not part of the effective prefix.  
**Reference:** L2 0.1 draft §9.5, §13.5, §11.4; Appendix A.5 (scenario E).

### L2-VER-038 — l1-bound mode: matching Location verifies
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** `l1-bound` mode; L1 303 observation for the same expected Anchor recorded; statement `descriptionLocation` equals the observed Location.  
**Action:** verifier evaluates the statement in `l1-bound` mode.  
**Expected:** `VERIFIED` with §7.4 consistency confirmed; the result records `verificationMode`.  
**Reference:** L2 0.1 draft §15.1, §7.4, §15.2 phase 11.

### L2-VER-039 — l1-bound mode: descriptionLocation mismatch yields MISMATCH
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** `l1-bound` mode; observed L1 303 Location differs from statement `descriptionLocation`.  
**Action:** verifier evaluates the statement.  
**Expected:** `MISMATCH` at phase 11, after all phases 1..10 pass.  
**Reference:** L2 0.1 draft §7.4, §15.2 phase 11.

### L2-VER-040 — l1-bound mode: missing L1 observation yields UNVERIFIED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** `l1-bound` mode; no L1 observation recorded for the expected Anchor.  
**Action:** verifier evaluates the statement.  
**Expected:** `UNVERIFIED` at phase 11; `l1-bound` mode with no L1 observation for the expected Anchor performs no §7.4 consistency check, an implementation running `statement-only` semantics performs no L1 request at all, and absence of an L1 observation is not a `MISMATCH`.  
**Reference:** L2 0.1 draft §15.1, §15.2 phase 11.

### L2-VER-041 — States are exposed as states, never as booleans
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** verifier produces a result for any case above.  
**Action:** report the result.  
**Expected:** the result is one of the §15 states, not a boolean; `UNVERIFIED` is never surfaced as `VERIFIED` nor as an Entity failure; a consumer claiming L2 conformance MUST expose the states `VERIFIED`, `UNVERIFIED`, `INVALID`, `EXPIRED`, `REVOKED`, `STALE`, `UNKNOWN_AUTHORITY`, `UNSUPPORTED_ALGORITHM`, `CONFLICT`, `MISMATCH`, `ANCHOR_MISMATCH`.  
**Reference:** L2 0.1 draft §15, §15.1, §15.2.

### L2-VER-042 — Phase order is fixed; first non-null result wins
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** statement failing at multiple phases under the fixture set.  
**Action:** verifier evaluates with the §15.2 phase order 1..11.  
**Expected:** the verifier MUST NOT reorder phases or severity-pick results; the first non-null phase result is the answer, and the result records which phase failed (wire before anchor, anchor before algorithm, algorithm before clock, clock before chain, chain before snapshot, snapshot before position, position before signature, signature before key status, key status before freshness, freshness before L1 consistency).  
**Reference:** L2 0.1 draft §15.2.

### L2-VER-043 — Fail-closed on snapshot failure; HTTP cache is not L2 currency
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** snapshot unavailable, stale, incomplete, or empty; chain-walk failure; statement revocation cached.  
**Action:** verifier evaluates the statement.  
**Expected:** snapshot unavailable, stale, incomplete, or empty, and chain gaps from missing material, yield `UNVERIFIED`; a signer absent from the accepted chain yields `UNKNOWN_AUTHORITY` at phase 5 (L2-VER-008), not `UNVERIFIED`; revoked-but-cached evidence MUST NOT be surfaced as current; HTTP cache reusability (RFC 9111) is separate from L2 currency, and an HTTP-reusable cached statement MUST NOT be reported as L2-current.  
**Reference:** L2 0.1 draft §13.5, §14.2.

### L2-VER-044 — Offline never manufactures REVOKED
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** verifier offline with no fresh evidence reachable; last known key status.  
**Action:** verifier evaluates a statement offline.  
**Expected:** `REVOKED` is reported only with locally retained fresh evidence; without it the result is `UNVERIFIED`; offline operation is not revocation-safe.  
**Reference:** L2 0.1 draft §11.5, §13.6.

### L2-VER-045 — Statement revocation is an optional extension: absence is classified, never pinned
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** baseline conformance report; verifier without statement revocation support.  
**Action:** report the statement-revocation coverage classification.  
**Expected:** absence of statement revocation support is reported as `UNSUPPORTED-OPTIONAL`, never `FAIL`, never a baseline failure; the baseline catalog MUST NOT pin the wire form, the revocation trail, the result, or the phase of the not-yet-specified extension (§13.1, §21 OPEN); results and phases of a future statement-revocation extension belong to that extension's own specification and test profile.  
**Reference:** L2 0.1 draft §13.1, §19, §21; Catalog §3.


### L2-VER-046 — JCS required for the signing target payload, duplicate-member detection on raw wire text
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** canonical wire object with one unknown extension member inside `payload`; a canonical payload example from Appendix A whose outer envelope differs from the canonical wire example only in outer whitespace.  
**Action:** the verifier detects duplicate members in the raw wire text, computes the canonical payload bytes, and verifies the signature over them.  
**Expected:** JCS is required for the signing target, `payload`: nested objects inside `payload`, including unknown extension members, canonicalize under JCS with sorted keys and the extension bytes are reproducible; duplicate member names are detected in the raw wire text before parsing and rejected as INVALID for wire-text non-canonicality (outer-envelope wire-text non-canonicality, e.g. duplicate members, is INVALID at phase 1); outer whitespace around a canonical payload with no duplicate members does not make the statement INVALID, the canonical payload bytes stay identical to the pinned fixture; the verifier MUST NOT strip, rewrite, drop, re-sort, or renumber members of the signing target; `keyId`, `algorithm`, UUID, `l2Version`, `statementType`, and string values are exact strings, `keyId` case is preserved.  
The Testbed SHOULD implement at least these subcases while retaining the parent catalog ID:

```text
L2-VER-046/payload-jcs-canonical: canonical payload with JCS sorted keys, extension bytes reproducible -> signature verifies over canonical payload bytes
L2-VER-046/outer-whitespace-only: canonical payload, outer envelope differs only in outer whitespace, no duplicate members -> not INVALID; canonical payload bytes unchanged
L2-VER-046/raw-wire-duplicate: duplicate member names in raw wire text -> INVALID at phase 1, detected before parsing
L2-VER-046/payload-inner-noncanonical: payload interior non-canonical (unsorted keys, 1.0 form, unescaped control characters) -> signing-target bytes differ from the pinned canonical fixture, signature verification fails, INVALID at phase 8, distinct from the phase 1 raw wire duplicate case
```
**Reference:** L2 0.1 draft §7.3, §7.5, §15.2 phase 1, phase 8.

### L2-VER-047 — Statement replay, equivocation, accepted maximum: split verdicts from independent verifier states
**Target:** L2-VERIFIER  
**Strength:** MUST  
**Precondition:** accepted prefix `0..3, 4A` for the S9 fixtures — `epoch-1` is the current accepted epoch, key4 `ECYh7yJFAn8` is `current` in Record 4A, window `1769998300..1770001900` — `T = 1769998300`, `W = 600`; for the S8 subcase, accepted prefix `0..3, 4A, 4R`, `T = 1769998400`. Catalog fixtures S9, S9v1, S9v2 are pinned in Catalog Appendix 10: reproducible from seed string `relink-l2-0.1-draft-test-vector-seed-4` via spec Appendix A.1, signed under key4 with the spec Appendix A.6 procedure; all three share `keyId` `ECYh7yJFAn8`, `epochId` `epoch-1`, `issuedAt` 1769998300, `expiresAt` 1770001200; S9 has `generation = 2`, S9v1 and S9v2 have `generation = 1` with payloads differing from S9 and from each other, each signature valid under key4. New catalog fixtures only; the pinned spec Appendix A vectors MUST NOT be modified.  
**Action:** the verifier runs each subcase from an independent verifier state with the stated initial accepted statement state.  
**Expected:** the subcases yield split verdicts, never one blanket result:  
The Testbed SHOULD implement at least these subcases while retaining the parent catalog ID:

```text
L2-VER-047/s9-generation-2-base: fresh verifier state, no accepted statement maximum; S9 at generation 2 (Appendix 10.1), under key4 current in Record 4A, fresh at T, signature verifies under key4 -> VERIFIED
L2-VER-047/s9-generation-1-stale-variant: after S9 generation 2 is accepted, present S9v1 (Appendix 10.2, generation 1, valid signature under key4, fresh at T), below the accepted maximum -> STALE at phase 10, never VERIFIED
L2-VER-047/s9-generation-1-equivocation: fresh state with S9v1, generation 1, accepted first; present S9v2 (Appendix 10.3), same generation, payload differs from S9v1, re-signed under key4, fresh at T -> CONFLICT at phase 10 (same epoch, same generation, different payload), never VERIFIED
L2-VER-047/s9-generation-2-byte-identical-replay: after S9 generation 2 is accepted, replay S9 byte-identically; replay at the accepted maximum is not freshness progress, key4 current, within window -> VERIFIED, no replay penalty (L2-VER-027, §15.1)
L2-VER-047/s8-replay-byte-identical: byte-identical replay of S8 at generation 1 within its validity window is a replay, not freshness progress; signer key3 is revoked from Record 4R onward -> REVOKED at phase 9, never VERIFIED
```
**Reference:** L2 0.1 draft §15.2 phase 10, phase 9, phase 8; §9.7, §11.3, §13.2, §15.1; Appendix A.5 (S8), catalog Appendix 10.

## 6. Authority cases

### L2-AUT-001 — Bootstrap: first-contact material without chain provenance is not trusted
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** verifier contacts a new origin for authority material; no Trust Anchor Binding recorded.  
**Action:** verifier receives Records and a Trust Anchor Binding.  
**Expected:** material from the same origin as the statement is not independent; Records verifying under non-accepted keys MUST NOT bootstrap their own authority; the Trust Anchor Binding is accepted only through the policy channel; provenance handling is a deployment-profile concern.  
**Reference:** L2 0.1 draft §9.2, §9.3, §9.8, §21.

### L2-AUT-002 — prevRecordDigest binds payloads, not signatures
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Records 1..3 in the fixture set.  
**Action:** verifier recomputes each `prevRecordDigest` as the SHA-256, lowercase hex, of the JCS canonical `payload` member of Record `G-1`.  
**Expected:** digests match the fixture values (`36ce3c7bf819434ebc79e534649873081ee0999af85c798c6586235680809afb`, `870b931dd8f9ad01a6a037524842549ad60fcde1066dbc9caf4f3e6140102f61`, `39808d467859fcad7589d70d54e0666038ac039d4956964ddda751f10f3e3234`); the signature and envelope are NOT included in the digest; `prevRecordDigest` is null for Record 0; a Record whose digest binds the envelope MUST be rejected at chain walk.  
**Reference:** L2 0.1 draft §9.5, §9.6; Appendix A.5.

### L2-AUT-003 — Strictly increasing generations, non-decreasing issuedAt, contiguity
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** a Record chain with `recordGeneration` skipping, regressing, or `issuedAt` regressing.  
**Action:** verifier walks the chain.  
**Expected:** `recordGeneration` MUST be strictly increasing and contiguous from 0 to the accepted maximum; `issuedAt` MUST be non-decreasing; a gap, regression, or skipped generation breaks the chain at the first offending Record.  
**Reference:** L2 0.1 draft §9.5, §9.6, §11.4.

### L2-AUT-004 — Keys-array entry identity is preserved
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record chain; a Record mutates an existing entry's `keyId`, `publicKey`, `algorithm`, `validFrom`, or deletes an entry.  
**Action:** verifier walks the chain.  
**Expected:** entries never change identity, entries are never deleted, and a `keyId` is never re-registered against a different `publicKey`; the offending Record is rejected at chain acceptance.  
**Reference:** L2 0.1 draft §9.5, §9.4, §9.6.

### L2-AUT-005 — revokedFrom is set once and immutable
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record `G` first revokes key3 (Record 4R, `revokedFrom = 5`); Record 4C attempts to change `key3.revokedFrom` to 1 at generation 6.  
**Action:** verifier walks the chain.  
**Expected:** `revokedFrom` is absent on `current` entries, set to the `recordGeneration` of the first revoking Record, and is immutable, unchangeable, and non-removable thereafter; Record 4C is rejected at phase 5 as the sole post-establishment mutation.  
**Reference:** L2 0.1 draft §9.5; Appendix A.5 (Records 4R, 4C, scenario E).

### L2-AUT-006 — Terminal states never return to current
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** key in `revoked`, `compromised`, or `retired` state in accepted history.  
**Action:** a later Record presents the key as `current` or re-registers it.  
**Expected:** the Record is rejected by chain walk; a revoked, compromised, or retired key never returns to `current`; re-registration is rejected.  
**Reference:** L2 0.1 draft §9.5, §13.4.

### L2-AUT-007 — Retirement truncation cap on explicit current → retired transitions
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record `G` retires a `current` key, whose prior `validUntil` exceeds `issuedAt(G)`.  
**Action:** verifier walks Record `G`.  
**Expected:** the retired entry's `validUntil` MUST be capped at `min(previous validUntil, issuedAt(G))` (Record 1: key1 `min(1770000000, 1769997000) = 1769997000`; Record 2: key2 `min(1770000600, 1769997600) = 1769997600`); a retired entry with `validUntil` above the cap MUST NOT be accepted; rotation validity windows SHOULD overlap, a rotation Record MAY keep prior `current` keys with their windows, normal rotation with lawful overlap is accepted (L2-AUT-024), and retiring all prior `current` keys with the incoming key as sole `current` is a MUST only for transfer-introducing Records (§9.6, §12.2, L2-AUT-008).  
**Reference:** L2 0.1 draft §9.5, §10.4, §10.5, §12.2; Appendix A.5 (Records 1, 2).

### L2-AUT-008 — statementFloor required on every Record
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Records with missing `statementFloor`; Records establishing epochs; Records repeating a floor.  
**Action:** verifier walks the chain.  
**Expected:** `statementFloor` is required on every Record; an epoch-establishing Record sets the floor baseline 0 (Records 2 and 5 fixtures set 0); a non-transfer Record repeats `G-1`'s floor; a Record omitting `statementFloor` is rejected.  
**Reference:** L2 0.1 draft §9.5, §12.3; Appendix A.5.

### L2-AUT-009 — transfer member REQUIRED on epoch-introducing Records, FORBIDDEN otherwise
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record `G` with `epochId` differing from Record `G-1`; non-transfer Record carrying `transfer`.  
**Action:** verifier walks the chain.  
**Expected:** a Record whose `epochId` differs from `G-1` MUST carry `transfer` with the full trail; a Record whose `epochId` equals `G-1`'s MUST NOT carry `transfer`; both violations reject the Record at chain acceptance.  
**Reference:** L2 0.1 draft §9.5, §12.1, §12.2.

### L2-AUT-010 — Trail field set matches Record G exactly
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record 2 fixture trail: `outgoingKeyId` `4KKXd7XSJW8`, `incomingKeyId` `G8VbXumewVs`, trail `epochId` `epoch-0`, `newEpochId` `epoch-1`, `recordGeneration` 2, `statementFloor` 0, `algorithm` `ed25519`, `anchorId`, `entityId`, `l2Version` matching Record 2; key3 window introduced in Record 2, `validFrom` 1769997600, `validUntil` 1770001200, Record 2 `issuedAt` 1769997600 (fixture equality `validFrom` == `issuedAt`).  
**Action:** verifier checks every §12.2 matching rule against Record 1 and Record 2.  
**Expected:** `outgoingKeyId` equals Record 2 `signerKeyId`; `incomingKeyId` equals the keyId Record 2 introduces as `current` whose validity window MUST include Record 2 `issuedAt` (§12.2 inclusion condition; the fixture equality `validFrom` 1769997600 == `issuedAt` 1769997600 is fixture-specific, not the requirement); trail `epochId` equals Record 1 `epochId`; `newEpochId` equals Record 2 `epochId` and never appears in the chain history; trail `recordGeneration` and `statementFloor` equal Record 2's; any mismatch rejects Record 2.  
**Reference:** L2 0.1 draft §12.2; Appendix A.5 (Record 2 trail).

### L2-AUT-011 — Trail forbidden members
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** trail payload carrying any of `keyId`, `generation`, `issuedAt`, `expiresAt`, `descriptionLocation`, `lifecycleStatus`, `effectiveGeneration`.  
**Action:** verifier walks Record `G`.  
**Expected:** the Record is rejected: the trail MUST NOT carry any forbidden member.  
**Reference:** L2 0.1 draft §12.2.

### L2-AUT-012 — Trail signature target and window
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record 2 trail signed by the outgoing key over the JCS canonical trail payload.  
**Action:** verifier verifies the Record 2 signature covering the trail bytes, then the trail signature under the `publicKey` recorded for the outgoing key in Record `G-1` (Record 1).  
**Expected:** the trail signature verifies under key2 `publicKey` carried in Record 1, accepted only with `validFrom <= issuedAt(2) <= validUntil` (`1769997000 <= 1769997600 <= 1770000600`); the Record signature covers the trail canonical bytes.  
**Reference:** L2 0.1 draft §12.2, §7.3; Appendix A.5 (Record 2 trail signature).

### L2-AUT-013 — Signature bytes verifying outside the window MUST NOT be accepted
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record 5V (`G = 5`, `epoch-2`, signer key4, `issuedAt` 1770002700, `expiresAt` 1770003900, trail byte-identical to the Record 5 trail, `prevRecordDigest` `cf347228c0a32f5b47f10bff1ef86ca3e20b34172dceda39b706280db0a0665d`).  
**Action:** verifier walks Record 5V.  
**Expected:** key4 is `current` in Record 4 with window `1770000900..1770002400`, which excludes `issuedAt(5V) = 1770002700`; the trail signature bytes verify, and the Record MUST NOT be accepted; chain accepted maximum stays 4; records introducing `epoch-2` under key4 authority after key4's window ends MUST NOT be accepted.  
**Reference:** L2 0.1 draft §12.2, §9.6, §15.2 phase 5; Appendix A.5 (Record 5V).

### L2-AUT-014 — Missing, unverifiable, duplicated, or mismatched trail stops the chain
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record `G` with any trail defect.  
**Action:** verifier walks Record `G`.  
**Expected:** Record `G` is rejected, the chain stops at `G-1`, and dependent statements report `UNVERIFIED` (§9.6); the defect never yields `UNKNOWN_AUTHORITY` when the signer resolves inside the accepted prefix.  
**Reference:** L2 0.1 draft §9.6, §12.2, §15.2 phase 5, phase 6.

### L2-AUT-015 — Atomic epoch switch: incoming key is the sole current key
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record `G` establishing a new epoch.  
**Action:** verifier walks Record `G`.  
**Expected:** Record `G` carries `newEpochId` and floor baseline 0, marks every `current` key of Record `G-1` as `retired` with truncation cap `min(previous validUntil, issuedAt(G))`, and the trail-approved incoming key is the sole `current` key in Record `G`; a Record leaving a prior-epoch key `current` (Record 5 fixture, key3) MUST be rejected.  
**Reference:** L2 0.1 draft §12.2, §9.5, §12.3; Appendix A.5 (Record 5 rejection).

### L2-AUT-016 — Trail has no validity window; validity inherits from Record G
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** accepted historical trail.  
**Action:** verifier re-verifies the trail at a much later `T`, outside the outgoing key's recorded window.  
**Expected:** the trail payload carries no validity window; its validity is inherited from Record `G` through chain acceptance; historical trails are verifiable at any later `T`, and trail verification proves the trail only, never current key status.  
**Reference:** L2 0.1 draft §12.2, §13.4.

### L2-AUT-017 — Epochs are created only by chained transfer
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** standalone trail, policy-channel trail, supplementary trail, unsigned trail, self-attested trail, trail signed by the incoming key, administrative, origin, DNS, or CDN claim asserting a new epoch.  
**Action:** verifier evaluates the claim.  
**Expected:** none of these creates an epoch; only a Record `G` at the next contiguous generation above the last accepted `G-1`, with a matching embedded trail, establishes a new epoch.  
**Reference:** L2 0.1 draft §12.2, §9.6.

### L2-AUT-018 — Rotation is a signed act of the current authority
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** rotation Record introducing key2 via Record 1.  
**Action:** verifier walks Record 1.  
**Expected:** the new key's window is established by a Record signed by the key `current` at signing time (key1 in Record 0); a new key MUST NOT construct its own window alone; rotation MUST NOT change the epoch (`epoch-0` preserved in Record 1); generations increase monotonically; overlapping windows are a SHOULD (`PASS-WITH-DEVIATION` when documented).  
**Reference:** L2 0.1 draft §10.3, §10.4; Appendix A.5 (Record 1).

### L2-AUT-019 — Signer of Record G+1 present in Record G
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record 4 (signer key3) follows Record 3; any Record whose signer is absent from the previous Record's keys array.  
**Action:** verifier walks Record `G+1`.  
**Expected:** the signer of Record `G+1` MUST be present in Record `G` with `publicKey` bytes (key3 present in Record 3); a signer absent from `G` breaks chain acceptance at `G+1`, phase 5.  
**Reference:** L2 0.1 draft §9.6, §12.2; Appendix A.5 (Record 4, Record 3).

### L2-AUT-020 — State loss recovery and recovery after compromise
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** verifier loses all Record state; separately, the key that signed the chain history is compromised.  
**Action:** verifier re-establishes authority.  
**Expected:** recovery is via chain walk from the Trust Anchor Binding through the policy channel, with no trusted lower bound enabling a `VERIFIED` claim; recovery after compromise of a key that signed chain history MUST re-bootstrap with a NEW Trust Anchor Binding; a chain reconstructed under the compromised initial key MUST NOT be accepted as authority state.  
**Reference:** L2 0.1 draft §9.8, §13.4.

### L2-AUT-021 — Self-bootstrapping material is rejected
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record presented on the same origin as the statement, signed under a key that is not yet accepted.  
**Action:** verifier evaluates the Record as bootstrap material.  
**Expected:** material from the same origin as the statement is not independent; the Record MUST NOT bootstrap its own authority; the chain walk stops before accepting it.  
**Reference:** L2 0.1 draft §9.2, §9.3, §9.8.

### L2-AUT-022 — Chain-level keyId recomputation failure
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** Record entry whose wire `keyId` does not equal the recomputed `keyId` from the wire `publicKey`.  
**Action:** verifier walks the chain.  
**Expected:** the Record is not accepted as authority material, and a statement under that key fails at phase 5 with `UNKNOWN_AUTHORITY`; the failure is never reported as `INVALID` on the statement evaluation.  
**Reference:** L2 0.1 draft §9.4, §10.1, §15.2 phase 5.

### L2-AUT-023 — An epoch is never accepted twice
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** accepted epochs `epoch-0`, `epoch-1`; a trail proposing `newEpochId` equal to an epoch already in chain history.  
**Action:** verifier walks Record `G`.  
**Expected:** `newEpochId` MUST be an epoch never accepted in chain history; `epoch-2` and `epoch-3` from rejected Records 5 and 6 remain unaccepted, and re-entering `epoch-0` or `epoch-1` is rejected at chain acceptance.  
**Reference:** L2 0.1 draft §9.6, §12.2; Appendix A.5 (Records 5, 6).


### L2-AUT-024 — Rotation positive case: Record 4A introduces key4, key3 stays current, accepted
**Target:** L2-AUTHORITY  
**Strength:** MUST  
**Precondition:** chain `0..3` accepted; Record 4A (`recordGeneration = 4`, `epoch-1`, signer key3, `issuedAt` 1769998300, `expiresAt` 1770001900; key4 `ECYh7yJFAn8` introduced `current` `1769998300..1770001900`, key3 remains `current`); Record 4R (`recordGeneration = 5`, signer key3, `issuedAt` 1769998400, key3 revoked with `revokedFrom = 5`, keys as Record 4A); Record 4B (`recordGeneration = 6`, signer key4 `ECYh7yJFAn8`, `issuedAt` 1769998700, keys identical to Record 4R).  
**Action:** verifier walks Records 4A, 4R, 4B by chain walk from the Trust Anchor Binding.  
**Expected:** Record 4A accepted (signer key3 window `1769997600..1770001200` contains `issuedAt(4A) = 1769998300`; the overlapping `current` windows of key3 and key4 are the SHOULD-permitted rotation overlap, key3 is not retired by the introduction of key4, a rotation Record keeping prior `current` keys is never rejected for that overlap); Record 4R accepted (signer key3 `current` in Record 4A, window contains `issuedAt(4R) = 1769998400`); Record 4B accepted (signer key4 `current` in Record 4R with window `1769998300..1770001900` containing `issuedAt(4B) = 1769998700`); chain tip `recordGeneration = 6`.  
**Reference:** L2 0.1 draft §9.5, §9.6, §10.3, §10.4; Appendix A.5 (Records 4A, 4R, 4B).
## 7. Producer cases

### L2-PRO-001 — Single JCS canonical serialization as signing target
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** producer signs a statement payload.  
**Action:** producer serializes the payload with JCS (RFC 8785), UTF-16 code-unit member order, and signs the canonical payload octets.  
**Expected:** exactly one canonical serialization exists for a given payload; the signing target is the canonical payload bytes, not the wire envelope; the wire canonical string equals the payload verbatim (§7.3).  
**Reference:** L2 0.1 draft §7.3; Appendix A.2.

### L2-PRO-002 — Vector 1 reproduces byte-identically
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** Appendix A.2 Vector 1 statement payload with `epochId` on the signing target.  
**Action:** producer reproduces the canonical string and signature.  
**Expected:** the canonical string is byte-identical to the appendix fixture, the Ed25519 signature verifies under the fixture `publicKey`, and the canonical string with `epochId` is the signing target.  
**Reference:** L2 0.1 draft §7.3, §7.2; Appendix A.2, Appendix A.6 reproduction steps.

### L2-PRO-003 — Vector 2: extension members serialize in UTF-16 code-unit order
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** Appendix A.3 Vector 2 statement with extension members.  
**Action:** producer serializes with extension members.  
**Expected:** extension members sort in UTF-16 code-unit order alongside required members; the canonical string is byte-identical to the appendix fixture.  
**Reference:** L2 0.1 draft §7.5, §7.3; Appendix A.3, Appendix A.6.

### L2-PRO-004 — Vector 3: non-BMP UTF-16 ordering fixture
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** Appendix A.4 Vector 3 statement carrying non-BMP characters.  
**Action:** producer serializes.  
**Expected:** surrogate pairs order per UTF-16 code units as in the appendix fixture; canonical output is byte-identical; the producer MUST NOT reorder members per locale, code page, or insertion order.  
**Reference:** L2 0.1 draft §7.3, §17 canonicalization row; Appendix A.4, Appendix A.6.

### L2-PRO-005 — Trail canonical bytes are covered by the Record signature
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** Record 2 fixture.  
**Action:** producer emits Record 2 with `transfer.payload` equal to the trail canonical string verbatim and signs the Record payload.  
**Expected:** the trail canonical string appears verbatim as `transfer.payload`; the Record signature covers the trail bytes; the wire Record contains the trail canonical bytes byte-identically.  
**Reference:** L2 0.1 draft §12.2, §7.3; Appendix A.5 (Record 2).

### L2-PRO-006 — Producers never emit duplicate members
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** producer builds a payload containing a duplicate member name, including inside `transfer.payload`.  
**Action:** producer serializes.  
**Expected:** canonical output MUST NOT contain duplicate member names; duplicate-member wire text is a producer failure, and verifier-side detection is tested in L2-VER-003.  
**Reference:** L2 0.1 draft §7.2, §7.3, §17 canonicalization row.

### L2-PRO-007 — Signature lives only in the envelope
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** producer signs a payload.  
**Action:** producer emits the wire object.  
**Expected:** the signature bytes appear ONLY in the envelope, never inside the payload member; a payload carrying `signature` is a producer failure and a wire rejected at phase 1.  
**Reference:** L2 0.1 draft §7.2, §7.3, §17 signature-stripping row.

### L2-PRO-008 — keyId derivation and encodings
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** producer publishes a key.  
**Action:** producer computes the `keyId` from the canonical `publicKey` encoding.  
**Expected:** `keyId` is base64url (RFC 4648 §5) of the first 8 bytes of SHA-256 of the canonical `publicKey` encoding, `=` stripped, exactly 11 characters, case-sensitive exact comparison, opaque lookup key; ed25519 `publicKey` is raw 32 bytes, signature 64 bytes, per §10.8.  
**Reference:** L2 0.1 draft §10.1, §10.8; Appendix A.5 key derivation blocks.

### L2-PRO-009 — Key lifecycle: one permanent key alone never satisfies baseline
**Target:** L2-PRODUCER  
**Strength:** MUST baseline, SHOULD practice  
**Precondition:** producer key configuration and Record issuance across rotation, revocation, and retirement.  
**Action:** evaluate a single permanent key configuration; evaluate rotation, revocation, and retirement issuance.  
**Expected:** the baseline MUST NOT be satisfied by hard-coding one permanent key per deployment, a single permanent key alone never satisfies baseline conformance; short-lived signing keys with rotation, an offline root for bootstrap, and documented rotation practice are the recommended practice, a documented deviation is `PASS-WITH-DEVIATION`; revoked, compromised, and retired material never signs new authority acts, revoked material is never rotation authority, and rotation is issued only by the key `current` at signing time.  
**Reference:** L2 0.1 draft §10.6, §10.7, §13.4, §10.3.


### L2-PRO-010 — Vector 3b escape fixture serializes byte-identically
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** the canonical JSON objects pinned in Appendix A.4, Vector 3b (escape fixtures, including the non-BMP key-order vector).  
**Action:** producer serializes each object per JCS.  
**Expected:** each output is byte-identical to the pinned Vector 3b fixture, escaping forms, UTF-16 code-unit key order, and number forms included; a digest computed over these bytes is consistent with the digest over the pinned serialization.  
**Reference:** L2 0.1 draft §7.3; Appendix A.4.

### L2-PRO-011 — Pinned ed25519 signatures verify against canonical payload bytes
**Target:** L2-PRODUCER  
**Strength:** MUST  
**Precondition:** canonical payload bytes, pinned `publicKey` encodings, and pinned signature strings for statements A, S5, S6, S7, S8, Records 2, 3, 4, 4E, 4F, 4R, 4B, 4C, 5V, and the trail payloads, all in Appendix A.  
**Action:** run `ed25519` verification of each pinned signature over its canonical payload bytes under the pinned `publicKey`.  
**Expected:** every pinned signature verifies against the canonical payload bytes under the pinned `publicKey` (raw 32-byte public key encoding, 64-byte signature); a signature that does not verify against the canonical bytes is never authority evidence.  
**Reference:** L2 0.1 draft §10.2, §10.8; Appendix A.2–A.5.

### L2-PRO-012 — ES256 when offered: pinned reproduction contract, JWS fixed r||s encoding
**Target:** L2-PRODUCER  
**Strength:** MUST when offered, optional to baseline  
**Precondition:** ES256 offered as an algorithm; test ES256 keys and vectors are produced by the reproduction contract in Catalog Appendix 11, and the same payload is presented in non-pinned encodings (DER, reordered r/s).  
**Action:** verify ES256 signatures produced by the contract-derived test key over the pinned canonical payloads, and present the same payload with non-pinned encodings.  
**Expected:** ES256 MUST use the JOSE JWS "ES256" signature encoding, fixed 64-byte r||s, the signature bytes verify over the same canonical payload octets, digest interpretation over the same payload bytes is identical under the pinned encoding, non-pinned encodings (DER, reordered r/s, unpadded base64url, variable-length integers) MUST NOT be accepted, vectors are reproducible through the contract in Catalog Appendix 11 (seed-pinned keys, signatures generated deterministically, stored as canonical payload + signature pairs in the Testbed data set), never through unpinned ad-hoc signatures, absence of ES256 is `UNSUPPORTED-OPTIONAL`, not a baseline failure, and an unknown or unsupported `algorithm` yields `UNSUPPORTED_ALGORITHM` fail-closed, never `VERIFIED`, never `UNVERIFIED`.  
**Reference:** L2 0.1 draft §10.8, §19; Catalog Appendix 11.

## 8. Server cases

### L2-SRV-001 — Statement surface is additive to Core with the fixed L2 status contract
**Target:** L2-SERVER  
**Strength:** MUST  
**Precondition:** Resolver serving L2 statements for an Anchor registered in Core.  
**Action:** consumer requests the L2 statement via `GET /{resolver-service}/{uuid}?l=2`.  
**Expected:** the statement endpoint answers without altering the Core 303 resolution path; a Core-only consumer on the unchanged path remains fully conforming; L2 MUST NOT force Manifest, AR-XML, or trust into the Core path; the status table in §6.1 binds L2-supporting implementations and inherits the normative strength of the governing Resolver Core 0.1 / Lifecycle 0.1 rules; L2 0.1 adds no MUST-level status requirements for Core-only deployments. The subcases:  
The Testbed SHOULD implement at least these subcases while retaining the parent catalog ID:

```text
L2-SRV-001/active-200: ACTIVE record -> 200 + conforming L2 Trust Statement, Content-Type application/json, Cache-Control bounded by remaining validity (L2-SRV-005)
L2-SRV-001/suspended-404: SUSPENDED -> 404 Not Found, preserving the unknown/SUSPENDED non-distinction
L2-SRV-001/retired-410: RETIRED -> 410 Gone
L2-SRV-001/unknown-404: unknown UUID -> 404 Not Found
L2-SRV-001/malformed-400: malformed UUID -> 400 Bad Request
L2-SRV-001/method-405: unsupported method -> 405 Method Not Allowed before registration lookup
L2-SRV-001/no-suspended-retired-served: SUSPENDED and RETIRED L2 statements MUST NOT be served on the public path
```
**Reference:** L2 0.1 draft §6.1, §14.2, §16.1, §16.4.

### L2-SRV-002 — Authority material surface: contiguous ascending Records, unsigned transport
**Target:** L2-SERVER  
**Strength:** MUST  
**Precondition:** Record chain `0..G` for an Anchor.  
**Action:** consumer requests authority material via `GET /{resolver-service}/{uuid}/authority`.  
**Expected:** for an ACTIVE record, `200` + authority material with the same status mapping as §6.1; the baseline response body is the strict JSON object `{ "records": [ Record_0, Record_1, ..., Record_G ] }` in strictly ascending `recordGeneration`, each element a signed `{ payload, signature }` Record per §9.5; the array framing is unsigned transport, Records remain self-contained signed objects so any transport preserves the signing target; a verifier MUST have every Record `0..G` before accepting Record `G`; a missing Record in the chain is a chain gap, result `UNVERIFIED`; a response whose array is not contiguous and strictly ascending MUST NOT be accepted as authority material; transfer evidence travels inside the Records, the baseline response MUST NOT require any additional collection, a verifier MUST NOT request transfer evidence outside the Records array, and a transfer-introducing Record whose embedded trail is missing MUST NOT be accepted, the chain stops at Record `G-1` and results are `UNVERIFIED`.  
**Reference:** L2 0.1 draft §6.3, §9.5, §9.6, §12.2.

### L2-SRV-003 — Public L2 endpoints are GET-only, read-only, anonymous
**Target:** L2-SERVER  
**Strength:** MUST  
**Precondition:** any statement, material, or mutation attempt on the public surface.  
**Action:** request statements, material, and mutation attempts.  
**Expected:** public L2 endpoints are GET-only, read-only, anonymous; L2 MUST NOT introduce public `PUT`/`PATCH` mutation, authenticated public mutation, or Resolver-side trust enforcement; requests MUST NOT change bindings, Records, keys, epochs, floors, or revocation state; mutation is not reachable on the public surface, unsupported methods get `405` before registration lookup (§6.1); authenticated statement submission remains an administrative surface (§16).  
**Reference:** L2 0.1 draft §6.4, §6.1, §14.1, §16.

### L2-SRV-004 — `p` parameter and forward compatibility fail closed; no client-submitted signing targets
**Target:** L2-SERVER  
**Strength:** MUST  
**Precondition:** L2-supporting resolver; requests carrying `p` with `l=2` lacking L2-defined semantics, requests carrying `l` values other than `2`.  
**Action:** request `l=2` with `p` without L2-defined semantics, and requests with unsupported `l`.  
**Expected:** an `l=2` request containing `p` without L2-defined semantics MUST return `400 Bad Request` before record lookup, binding L2-supporting implementations only; a deployment MUST NOT accept an unsupported `l` as L2, L1, or L3; a consumer MUST NOT claim L2 achievement from a request merely containing `l=2`; the public surface has no contract by which a client submits JSON for the server to sign, the server MUST NOT define L2 case identifiers, and wire objects with unknown members are checked on the VERIFIER and PRODUCER sides (L2-VER-046, L2-PRO-004).  
**Reference:** L2 0.1 draft §6.2, §6.5, §16, §19.

### L2-SRV-005 — Cache policy: bounded explicit policy, error responses, immediate-freshness choice recorded
**Target:** L2-SERVER  
**Strength:** MUST  
**Precondition:** server serves L2 statements; baseline issuer profile, and an issuer profile requiring immediate L2 freshness.  
**Action:** server sets cache policy on `200` statements, on error responses, and on immediate-freshness responses.  
**Expected:** the subcases, judged without mixing MUST, SHOULD, and HTTP reusability:  
The Testbed SHOULD implement at least these subcases while retaining the parent catalog ID:

```text
L2-SRV-005/200-bounded-policy: a 200 L2 statement response MUST send an explicit cache policy that NEVER exceeds the statement's remaining validity: Cache-Control: public, max-age = min(deployment bound, expiresAt - now)
L2-SRV-005/error-no-store: 400/404/405/500/501/503 SHOULD use Cache-Control: no-store, a documented deviation is PASS-WITH-DEVIATION; 410 Gone MAY be cached, as in Core 0.1, because retirement is terminal
L2-SRV-005/immediate-freshness: issuers that require immediate L2 freshness MUST choose short max-age, no-store, or revalidation directives, and MUST record that choice; issuers not requiring immediate freshness are not uniformly required to make that choice
L2-SRV-005/http-separate-from-currency: cache rules govern HTTP reusability under RFC 9111, HTTP caches MUST NOT be required to detect revocation, revocation and epoch-transition events MUST NOT be modeled as HTTP cache behavior, invalidation on known revocation, transfer, or epoch transition is defined on the verifier side (§13.4, §13.5), an HTTP-reusable response is not L2-current by reuse
```
**Reference:** L2 0.1 draft §14.2, §13.4, §13.5, §11.4.

### L2-SRV-006 — Referrer-Policy: no-referrer is a SHOULD
**Target:** L2-SERVER  
**Strength:** SHOULD  
**Precondition:** statement and material endpoints.  
**Action:** server sets `Referrer-Policy`.  
**Expected:** `Referrer-Policy: no-referrer` SHOULD be set; a deviation MUST be reported as `PASS-WITH-DEVIATION` with a documented reason.  
**Reference:** L2 0.1 draft §14.4.

### L2-SRV-007 — Privacy: Anchor UUIDs are L1-level metadata
**Target:** L2-SERVER  
**Strength:** MUST  
**Precondition:** server logs statement requests.  
**Action:** server handles request metadata.  
**Expected:** Anchor UUIDs are treated as loggable metadata at the same level as L1, minimized; `l` and `p` parameters MUST NOT be expanded for L2 beyond Core definitions; privacy posture follows §14.3.  
**Reference:** L2 0.1 draft §14.3.

### L2-SRV-008 — Reference Resolver boundary
**Target:** L2-SERVER  
**Strength:** MUST  
**Precondition:** Reference Resolver issues L2 statements.  
**Action:** issue statements and material.  
**Expected:** L2 issuance MUST NOT fetch, parse, or validate AR-XML; MUST NOT invoke management UI, device IP, or capability endpoints; MUST NOT perform public mutations; the surface is additive and optional; Core conformance remains valid without L2; administrative authentication is not L2 authority (admin channels are policy connections, not protocol identity); L2 MUST NOT require, replace, or prohibit OAuth, OIDC, SSO, RBAC, WebAuthn, or any other admin mechanism; 501 guidance MUST never downgrade the L1 path.  
**Reference:** L2 0.1 draft §16.3, §16.4, §6.1, §17 downgrade row.

## 9. Testbed handoff notes

The §20 chain is:

```text
Resolver L2 0.1 spec (this repository, merged)
L2 conformance catalog (this document)
Reference Resolver implementation (this repository)
relink-testbed executable scenarios
Reference Lab integration
```

- This catalog defines the case identifiers owned by the catalog; the L2 draft MUST NOT invent `RES-*`, `LIFE-*`, `MAN-*`, `INT-*`, `NET-*`, `RT-*`, L2, or case IDs, and this document assigns `L2-VER-*`, `L2-AUT-*`, `L2-PRO-*`, `L2-SRV-*` for the 0.1 set, frozen with the L2 draft.
- Executable scenarios MUST live in `relink-testbed`; local PHPUnit results in `relink-resolver` MUST NOT be reported as `relink-testbed` results.
- Coverage mapping to the §20 targets: L2-VER-* exercises L2-VERIFIER (state model, §15 states, phase order, fail-closed); L2-AUT-* exercises L2-AUTHORITY (Record chain, trail, rotation, revocation, bootstrap, state loss); L2-PRO-* exercises L2-PRODUCER (canonical serialization, signing bytes, keyId derivation, wire discipline); L2-SRV-* exercises L2-SERVER (statement and material endpoints, cache, privacy, forward compatibility, Reference Resolver boundary).
- The Reference Resolver implementation MUST pass `composer validate`, `composer test`, `composer analyse`, `php -l`, and `git diff --check`; these apply to implementation work, not to this catalog.
- A report SHOULD state exactly which conformance set(s) were executed, per target, and MUST NOT collapse targets into one overall PASS line.
- Case subdivision, result classes, strength handling, and reporting follow §§2-4; the Testbed implements every §5-§8 case, subdividing as needed while retaining parent IDs.

## 10. Catalog fixtures (S9 family)

Status: catalog-owned, pinned, reproducible. The pinned vectors in the L2 0.1 spec Appendix A MUST NOT be modified; these fixtures live in this Catalog only.

Generation contract: the signing key is key4 `ECYh7yJFAn8` from spec Appendix A.5, derived from the seed string `relink-l2-0.1-draft-test-vector-seed-4` by the published derivation in spec Appendix A.1. Signatures are produced by the spec Appendix A.6 procedure: deterministic Ed25519 (RFC 8032) over the canonical payload's UTF-8 octets, base64url with '=' stripped, 86 characters. The private seed is published test-vector material, used only for test vectors. All S9 fixtures share `keyId` `ECYh7yJFAn8`, `epochId` `epoch-1`, `issuedAt` 1769998300, `expiresAt` 1770001200, `l2Version` `0.1`, `statementType` `resolution`, `lifecycleStatus` `active`, `anchorId` `550e8400-e29b-41d4-a716-446655440000`, `entityId` `urn:relink:entity:thermal-sensor-42`.

### 10.1 S9 (generation 2, base fixture)

Canonical payload (364 bytes):

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","descriptionLocation":"https://sensors.example/thermal/42/ar-xml","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-1","expiresAt":1770001200,"generation":2,"issuedAt":1769998300,"keyId":"ECYh7yJFAn8","l2Version":"0.1","lifecycleStatus":"active","statementType":"resolution"}
```

Signature (86 chars):

```
04Z-__f7h35mshYil-sF67cVE07SE4czanw-0nQ7oC76UiZ15IJmERM8DID5a5Mlc_6FeMO0hAHWfqCy-mD0DQ
```

### 10.2 S9v1 (generation 1, payload differs from S9 only in generation)

Canonical payload (364 bytes):

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","descriptionLocation":"https://sensors.example/thermal/42/ar-xml","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-1","expiresAt":1770001200,"generation":1,"issuedAt":1769998300,"keyId":"ECYh7yJFAn8","l2Version":"0.1","lifecycleStatus":"active","statementType":"resolution"}
```

Signature (86 chars):

```
tDFzpWAzdJKqiBdAGodXDXVi6gXHGODq3uBsoPz96wtFW8H-eiXNo7uZGQv8YhQS61uI_zPbcciCq9kbI0__Aw
```

### 10.3 S9v2 (generation 1, `descriptionLocation` differs from S9v1)

Canonical payload (366 bytes):

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","descriptionLocation":"https://sensors.example/thermal/42/ar-xml-2","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-1","expiresAt":1770001200,"generation":1,"issuedAt":1769998300,"keyId":"ECYh7yJFAn8","l2Version":"0.1","lifecycleStatus":"active","statementType":"resolution"}
```

Signature (86 chars):

```
EFXwBIToOlZVoWvyfkAuYhOgNaYrsIiH3V7d_1h_AbMUmZB8SuZEvRpbqVmlwEnt_iNF2_jqByHT1abwDhNfDA
```

Verifier states used by L2-VER-047, pinned and deterministic:

```text
state-base: accepted chain prefix 0..3, 4A; current accepted epoch epoch-1; key4 ECYh7yJFAn8 current in Record 4A, window 1769998300..1770001900; no accepted statement; T = 1769998300; W = 600
state-after-s9: as state-base, plus accepted statement S9, generation 2
state-after-s9v1: as state-base, plus accepted statement S9v1, generation 1
state-s8: accepted chain prefix 0..3, 4A, 4R; T = 1769998400; key3 revoked from Record 4R, revokedFrom 5
```

Storage: each fixture is stored as a canonical payload string + signature string pair, exactly as pinned; wire presentation wraps the canonical payload in the outer envelope form of spec Appendix A.4, outer whitespace tolerated per L2-VER-046; the signing target is the canonical payload bytes only.

## 11. ES256 test-vector reproduction contract

Status: catalog-owned. ES256 stays optional to baseline (§19); this contract pins how the Testbed generates, stores, and verifies ES256 vectors when offered.

- Curve P-256; JWS algorithm name `ES256` (RFC 7515 §6.2.1.1 interpretation).
- Test signing keys are derived from the catalog-owned seed strings `relink-l2-0.1-catalog-es256-seed-1` and `relink-l2-0.1-catalog-es256-seed-2`: the private scalar `d` is SHA-256 over the seed string's UTF-8 octets, interpreted as a big-endian integer, reduced modulo the P-256 group order, and MUST be in `1..n-1`.
- `publicKey`: uncompressed P-256 point, `x` and `y` fixed-width 32-byte big-endian, concatenated, base64url with '=' stripped (86 characters); `keyId` is recomputed from the `publicKey` bytes per §9.4.
- Signature: SHA-256 over the canonical payload's UTF-8 octets; JOSE JWS "ES256" signature encoding, fixed 64-byte big-endian `r || s`, base64url with '=' stripped (86 characters).
- Signing targets are the canonical payloads pinned in this Catalog and the spec Appendix, the same bytes verified in L2-PRO-* cases.
- Storage: vectors are generated once per this contract and stored as canonical payload string + signature string pairs in the Testbed data set; verification recomputes the signature from the contract-derived key and compares byte-for-byte.
- Non-pinned encodings MUST NOT be accepted: DER SEQUENCE, reordered `r`/`s`, unpadded base64url, variable-length integers.
