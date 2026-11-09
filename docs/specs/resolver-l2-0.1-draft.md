# RELink Resolver L2 Trust and Authority Model 0.1 — Draft

Status: Draft (not frozen) — Issue #28 work product
Version: 0.1-draft
Scope: L2 trust / authority layer above frozen Resolver Core 0.1

The English text is the proposed normative source. A Japanese translation will be produced
when this draft stabilizes. This document MUST NOT be treated as a Frozen specification.
Implementation conformance claims MUST NOT cite this draft.

Freeze policy (prospective): when this layer is frozen, semantic changes will require a later
L2 version or a separately versioned profile, following the same pattern as Resolver Core 0.1.

## 1. Purpose

This draft defines RELink Resolver L2 as a separately versioned Trust / Authority layer built
above the frozen Resolver Core 0.1 L1 model, per Issue #28.

```text
L1
= resolution

L2
= trust / authenticity / authority / freshness over resolution information
```

L2 answers, for a given Anchor UUID:

```text
Who asserted this resolution information?
Was the signed statement modified?
Is the signer authorized for this Entity?
Is the statement sufficiently current?
Has authority been transferred or revoked?
```

L2 MUST NOT redefine, weaken, resequence, or replace frozen Resolver Core 0.1 L1 semantics.

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

## 2. Requirements language

The key words **MUST**, **MUST NOT**, **REQUIRED**, **SHALL**, **SHALL NOT**, **SHOULD**,
**SHOULD NOT**, **RECOMMENDED**, **NOT RECOMMENDED**, **MAY**, and **OPTIONAL** in this
document are to be interpreted as described in BCP 14 (RFC 2119 and RFC 8174) when, and only
when, they appear in all capitals.

## 3. Terminology

### 3.1 L1 / Resolver Core

Frozen Resolver Core 0.1 public resolution: `GET /{resolver-service}/{uuid}` with no `l` and
no `p`, returning `303` to a validated HTTPS Description Location. L1 is public, anonymous,
read-only, and untrusted-network-input by definition.

### 3.2 L2 Request

A Resolver request containing `l=2` as defined by this specification. `l` is the requested
RELink security level, not an achieved level (unchanged from Resolver Core §5).

### 3.3 L2 Trust Statement

A signed, canonical, machine-readable JSON object asserting resolution information for one
Anchor UUID. See §7.

### 3.4 Entity Authority

The cryptographic authority binding for an Anchor UUID: the set of signing keys that are
authorized to issue L2 statements for that Anchor, at a given authority epoch.

### 3.5 Authority Epoch

A contiguous interval of generations governed by one authority binding. A transfer creates a
new epoch. Statements issued before the current epoch MUST NOT be accepted as current.

### 3.6 Trust Anchor Binding

The deployment-policy record binding an Anchor UUID (and optionally a Canonical Entity
Identity) to its initial authority. It is established by the registration/bootstrap channel,
not by L2 itself. See §9.3.

### 3.7 Verifier

A consumer that obtains L2 trust material and applies the L2 verification procedure in §15
to produce a deterministic verification state.

### 3.8 Generation

A monotonically increasing integer associated with each L2 statement within an authority
epoch, used for anti-rollback ordering. See §11.

## 4. Conceptual distinctions

L2 MUST keep the following concepts distinct. A mechanism satisfying one MUST NOT be
surfaced as satisfying another.

```text
Integrity          ≠ Authenticity
Authenticity       ≠ Authority
Authority          ≠ Freshness
Freshness          ≠ Ownership
Ownership          ≠ Authorization
Authorization      ≠ Resolution
```

In particular:

- Manifest 0.1 `description.integrity` is content pinning. It is not L2 authenticity,
  authority, freshness, revocation, anti-rollback, ownership, or authorization.
- `entity.id` is identifier data. It is not authority material.
- An Anchor UUID is not a credential. Knowledge of an Anchor UUID grants no L2 authority.
- Administrative authentication ("who may mutate resolver records") is not L2 authority
  ("who is authoritative for an Entity"). See §16.
- Control of an HTTPS origin alone is not Entity authority. Web PKI origin authentication
  authenticates a transport, not an Entity's signing authority.

Baseline L2 guarantees:

- G1 Authenticity of a resolution statement (signed by the current authority);
- G2 Integrity of signed resolution metadata (within the signed payload);
- G3 Authority of the signer for the Anchor;
- G4 Freshness / expiry;
- G5 Anti-rollback / anti-stale behavior;
- G6 Authority transfer semantics;
- G7 Revocation / retirement of signing authority;
- G8 Deterministic verification state.

Optional extensions (non-baseline): delegation chains, cross-anchor delegation, profile
attestation, timestamps from external services, transparency logs. Extensions MUST NOT be
required for baseline L2 conformance.

## 5. L1 boundary and design principles

L2 MUST NOT:

1. change L1 UUID lookup semantics;
2. change L1 lifecycle mappings (`ACTIVE`→303, `SUSPENDED`→404, `RETIRED`→410);
3. cause an unsupported requested `l` to be silently processed as L1 (unchanged Core rule);
4. cause an L2 request to silently downgrade to L1;
5. force all L1 consumers to implement trust verification;
6. cause Resolver to fetch, parse, or validate AR-XML as part of L2 response construction;
7. move Capability discovery, authorization, or execution into Resolver;
8. redefine Description Location semantics, cache, CORS, privacy, or error boundaries;
9. make Manifest retrieval a prerequisite for L2 statement issuance, or L2 a prerequisite
   for L1 resolution;
10. convert frozen Core SHOULD-level rules (unsupported `l` response codes, `p` response
    codes, `no-store` cache guidance) into MUST rules for Core-only deployments.

L2 MUST be additive and independently observable:

```text
L1 absent of L2     → unchanged Core 0.1 behavior
L2 present          → additional signed trust material
L2 verification     → consumer-side decision, never a Resolver transport side effect
```

A statement that fails L2 verification MUST NOT alter the public L1 response. L2 verification
success MUST NOT alter the public L1 response. Consumers MUST treat L2 results as metadata
about the resolution, not as a substitute for L1 HTTP status.

A Core 0.1-only Resolver receiving `l=2` MUST NOT process the request as L1, MUST NOT
accept it as L2, and MUST NOT accept it as L3. The request is handled as an unsupported
level exactly as Frozen Resolver Core 0.1 §§5 and 14.2 prescribe, including the SHOULD-level
`501 Not Implemented` and `Cache-Control: no-store` guidance. L2 requirements bind
L2-supporting implementations; L2 MUST NOT convert frozen Core SHOULD rules into MUST rules
for Core-only deployments.

## 6. L2 request and resource shape

### 6.1 L2 statement request

```text
GET /{resolver-service}/{uuid}?l=2
```

For an L2-supporting Resolver and an ACTIVE record:

```http
200 OK
Content-Type: application/json
Cache-Control: <bounded by remaining validity, §14.2>
```

with a conforming L2 Trust Statement.

Public status behavior mirrors Resolver Core and Lifecycle 0.1:

| State | L2 endpoint behavior |
| --- | --- |
| ACTIVE | `200` + L2 Trust Statement |
| SUSPENDED | `404 Not Found` (preserves unknown/SUSPENDED non-distinction) |
| RETIRED | `410 Gone` |
| unknown | `404 Not Found` |
| malformed UUID | `400 Bad Request` |
| unsupported method | `405 Method Not Allowed` before registration lookup |
| internal failure | `500` / `503` |

SUSPENDED and RETIRED L2 statements MUST NOT be served on the public path, for the same
privacy reasons as the public Manifest surface.

Each row inherits the normative strength of the governing Resolver Core 0.1 / Lifecycle 0.1
rule. L2 0.1 adds no MUST-level status requirements for Core-only deployments; the fixed
codes above bind L2-supporting implementations.

### 6.2 `p` parameter

Baseline L2 defines no required `p` semantics. A Core 0.1-only Resolver receiving `p`
without a defining `l` MUST NOT process the request as L1, MUST NOT accept it as L2, and
MUST NOT accept it as L3; the request is handled exactly as Frozen Resolver Core 0.1 §5
prescribes, including the SHOULD-level `400 Bad Request` guidance. For L2-supporting
implementations, an `l=2` request containing `p` without L2-defined semantics MUST return
`400 Bad Request` before record lookup; this fixed response code binds L2-supporting
implementations only. `p` MAY later carry L2 profile identifiers, nonce, or challenge
binding (OPEN, §21).

### 6.3 Authority material request

```text
GET /{resolver-service}/{uuid}/authority
```

For an ACTIVE record, `200` + an Authority Record. Same status mapping as §6.1.
The Authority Record is a signed L2 object (`statementType = "authority"`) whose required
members, wire format, acceptance order, chain rules, and anti-rollback rules are defined in
§§9.5–9.8. The initial Authority Record MAY self-sign; it is accepted through the Trust
Anchor Binding (§9.3).

Baseline 0.1 response form: the response body is a strict JSON object

```text
{ "records": [ Record_0, Record_1, ..., Record_G ] }
```

in strictly ascending `recordGeneration`, each element a signed `{ payload, signature }`
Record per §9.5. The array framing is unsigned transport: Records remain self-contained
signed objects, so any transport (array, paging, policy channel) preserves the signing
target. A verifier MUST have every Record `0..G` before accepting Record `G`; a missing
Record in the chain is a chain gap, and the result is `UNVERIFIED` (§9.6). A response
whose array is not contiguous and strictly ascending MUST NOT be accepted as authority
material.

Transfer evidence travels inside the Records: a transfer-introducing Record `G` carries the
signed transfer trail as its embedded `transfer` payload member (§9.5, §12.2). The baseline
response MUST NOT require any additional collection to obtain transfer evidence, and a
verifier MUST NOT request transfer evidence outside the Records array. A transfer-introducing
Record whose embedded trail is missing MUST NOT be accepted as authority material; the chain
stops at Record `G-1` and resolution results are `UNVERIFIED` (§9.6). A trail embedded in
Record `G` MUST be verified under the outgoing key's `publicKey` and its `current` validity
window recorded in Record `G-1` (§9.6, §12.2).

### 6.4 Non-mutation rule

Public L2 endpoints are GET-only, read-only, anonymous. L2 MUST NOT introduce public
`PUT`/`PATCH` mutation, authenticated public mutation, or Resolver-side trust enforcement.
Authenticated statement submission remains an administrative surface (§16).

### 6.5 Forward compatibility

`l` values other than `2` remain undefined here. A deployment MUST NOT accept an unsupported
`l` as L2, L1, or L3. A consumer MUST NOT claim L2 achievement from a request merely
containing `l=2`; achievement is the verifier's result, not the request.

## 7. L2 Trust Statement

### 7.1 Structure

The L2 Trust Statement is a strict JSON object with two top-level members:

```text
payload    – signed content, canonical JSON object
signature  – base64url encoding of the signature over the canonical payload octets
```

The signing target is defined in §7.3. The `signature` member is NOT part of the signing
target. A statement with a `signature` inside `payload` MUST be rejected (`INVALID`).

Duplicate object-member names anywhere in a statement MUST be rejected as `INVALID`, per the
Frozen Manifest 0.1 duplicate-member rule.

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

`l2Version` MUST be the string `"0.1"`. A verifier MUST NOT interpret other `l2Version`
values as L2 0.1. An unsupported `l2Version` MUST fail closed (not an ignorable extension).

Optional payload members (baseline): none are REQUIRED; any additional member is treated
under §7.5.

The `transfer` trail (§12.2) is carried as the embedded `transfer` payload member of the
transfer-introducing Authority Record (§9.5); the Authority Record payload (§9.5) defines
`recordGeneration`, `epochId`, and `statementFloor`. The trail adds `outgoingKeyId`,
`incomingKeyId`, `newEpochId`, `epochId`, `recordGeneration`, and `statementFloor` as
defined in §12.2, and forbids `keyId`, `generation`, `issuedAt`, `expiresAt`,
`descriptionLocation`, `lifecycleStatus`, and `effectiveGeneration`. Statement `generation`
sequences and Record `recordGeneration` sequences are separate sequences and MUST NOT be
compared with each other. All canonicalization and signing rules of §7.3 apply to every
payload, including Authority Records, embedded transfer trails, and key entries.

### 7.3 Canonical serialization and signing bytes

The signing target MUST be uniquely determined so that independent implementations compute
identical bytes.

The canonical serialization is RFC 8785, the JSON Canonicalization Scheme (JCS), applied to
the parsed `payload` object. The signing target is the UTF-8 encoding of that JCS
serialization. JCS fixes object member order (member names in UTF-16 code-unit order per
RFC 8785 §3.2.3: a name is its UTF-16 code-unit sequence, so a non-BMP name sorts as its
surrogate pair, not by scalar code-point value), string escaping, and number serialization;
the §7.2 enumeration is a display order, not a serialization order. Appendix A.4 pins this
rule with a vector whose non-BMP keys order differently under scalar code-point sorting.

Wire canonicality:

1. the `payload` as presented on the wire MUST itself already be a JCS serialization;
2. the verifier MUST detect duplicate object-member names in the raw wire text, before
   parsing, and MUST reject the statement as `INVALID` for any wire-text non-canonicality
   (member order, whitespace, number form, escaping);
3. because the wire is canonical, `1` and `1.0` cannot both appear: JCS emits the canonical
   number form, and integer-valued fields MUST appear as wire integers.

Integer fields (`issuedAt`, `expiresAt`, `generation`, `recordGeneration`,
`revokedFrom`, `validFrom`, `validUntil`) MUST be non-negative integers in JCS canonical
form, within the IEEE-754 double safe-integer range, `0` to `2^53 - 1`. A fractional,
exponent-form, non-integer, negative, or out-of-range value MUST produce `INVALID`.

Nested objects inside `payload`, including unknown extension members, canonicalize under
JCS with sorted keys, so extension bytes are reproducible. `keyId`, `algorithm`, UUID,
`l2Version`, `statementType`, and string values are exact strings; `keyId` case is
preserved (§10.1).

The signing target MUST NOT depend on XML canonicalization, transport framing, content
coding, or the consumer's JSON parser quirks. Test vectors are in Appendix A.

### 7.4 Statement-to-L1 consistency

The statement's `descriptionLocation` is the authority's assertion of the current
Description Location. A verifier comparing L2 against an observed L1 `303` response MUST
require exact, case-sensitive, byte-level equality of the observed `Location` field value
and the statement's `descriptionLocation` after standard HTTP field-value extraction.
A mismatch MUST produce `MISMATCH` (§15). Baseline MUST NOT apply URI-normalization
(§21 OPEN for normalization rules beyond this exact rule).

Successful equality is a consistency check between signed assertion and observed L1
response. It is not, by itself, authority, authenticity, or freshness.

### 7.5 Extensions

- Unknown members inside `payload` are canonicalized under JCS via §7.3 and are covered by
  the signature; they MUST be accepted, MUST NOT override defined member semantics, and
  MUST NOT be treated as security-critical evidence by verifiers.
- Extensions outside `payload` (e.g., in the statement object) are NOT signed. They MUST be
  ignored by verifiers for trust decisions.
- An L2 extension that changes the meaning of a required 0.1 member is not compatible and
  requires a later L2 version.

### 7.6 Manifest independence

An L2 Trust Statement MUST be issuable and verifiable in deployments with no Manifest
endpoint and no Manifest retrieval. The Manifest-association semantics
(`statementType` extension referencing a Manifest, content pinning reference) are OPEN
(§21). Manifest 0.1 remains optional for ordinary L1 operation, and MUST NOT be required
by the L2 baseline. An L2 profile MAY explicitly require Manifest support; that profile is
separately versioned.

## 8. Identity and authority model

### 8.1 Roles

```text
Anchor UUID              → resolution record key (not identity, not credential)
Canonical Entity Identity→ what Entity is this (stable, location-independent)
Entity Authority         → who may sign for this Anchor UUID
Signer                   → the key that signed a particular statement
```

The relationship is anchored at the Anchor UUID: L2 0.1 authority is bound per Anchor UUID.
The Canonical Entity Identity carried in the statement MUST match the deployment's
registration record for that Anchor. A verifier MUST treat a statement whose `entityId`
does not match the trusted registration/authority material as `UNKNOWN_AUTHORITY`
(§15, §9.4).

### 8.2 Authority binding rules

1. At most one authority binding is current for an Anchor UUID at a given time.
2. An authority binding consists of key identifiers, their validity windows, and the
   current epoch's minimum accepted generation.
3. Authority is established only by:
   - a Trust Anchor Binding (bootstrap, §9.3);
   - a key rotation signed by the current authority (§10.3);
   - an authority transfer signed by the outgoing authority (§12).
4. Authority MUST NOT be implied by:
   - possession of the Anchor UUID;
   - control of the Resolver HTTPS origin;
   - Web PKI origin authentication;
   - administrative login at the Resolver;
   - Manifest presence, `entity.id` content, or `description.integrity`;
   - DNS, hosting, CDN, or redirect infrastructure.
5. Authority is direct in L2 baseline. Delegated, chained, and profile-defined authority are
   explicitly out of baseline (§4, §18, §21).

### 8.3 Conflicting authority

If trust material asserts two current authorities for one Anchor UUID that overlap in time
and are not the same binding, the verifier MUST produce `CONFLICT` and MUST NOT arbitrarily
select one. Fail-closed. Conflicting bindings MUST NOT be reconciled by first-seen,
last-seen, shortest-key, strongest-algorithm, recency, or origin preference.

## 9. Trust anchors and trust material distribution

### 9.1 Verifier trust inputs

A verifier MUST have, at minimum:

```text
trust anchor bindings      (per Anchor UUID, from a policy channel)
current authority material (Authority Record, rotations, transfers)
statement-under-verification
observed L1 resolution result (for §7.4 consistency, when L2 is verified against L1)
```

### 9.2 Trust material sources

Authority material MAY be obtained from:

1. the L2 endpoint (§6.3);
2. the policy channel that established the Trust Anchor Binding;
3. deployment-specific out-of-band distribution (provisioning, device pairing, factory
   issuance, operator-published trust list).

Trust material obtained from the same origin as the statement under test is NOT an
independent authority source: the Authority Record is accepted only when it verifies under
a key that is current in the verifier's accepted authority binding, whose provenance
traces to a Trust Anchor Binding. An Authority Record that verifies under a key not
already accepted MUST NOT bootstrap its own authority.

### 9.3 Bootstrap

First authority for an Anchor is established at registration by the deployment's
registration/administrative channel. The Trust Anchor Binding records, for the initial
epoch: `anchorId`, the initial algorithm identifier, the initial public key bytes in the
algorithm-specific encoding of §10.8, the `keyId` computed per §10.1, the initial `epochId`,
and the initial `statementFloor`. The binding MUST carry full-length public key material;
`keyId` alone is not key material. The initial Authority Record MAY self-sign; the verifier
accepts bootstrap only through its policy channel. Bootstrap establishes the authority
binding, not Entity authentication, ownership of the physical device, or AR-XML validity.

The provenance, format, and provisioning mechanism of Trust Anchor Bindings is a
deployment profile concern, not L2 wire semantics (OPEN, §21).

### 9.4 Trust material consistency

A verifier MUST reject authority material that contradicts its accepted binding, and MUST
produce `UNKNOWN_AUTHORITY` for statements signed by keys absent from the accepted binding,
`CONFLICT` per §8.3 for contradictory bindings, and `UNVERIFIED` when authority material is
unavailable (missing, unretrievable, not fresh per §11.4).

The verifier MUST recompute each `keyId` from the `publicKey` bytes of the Record entry
(§10.1) before accepting that entry as key material. A Record containing any entry whose
recomputed keyId does not equal its stated keyId MUST NOT be accepted as authority
material; a statement signed under that material resolves to `UNKNOWN_AUTHORITY` at the
chain phase (§15.2).

### 9.5 Authority Record format

The Authority Record is a signed L2 object, `{ payload, signature }` per §7.1, with
required payload members:

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
prevRecordDigest   = lowercase hex SHA-256 of the UTF-8 octets of the JCS canonical
                     serialization of the `payload` member of Record G-1 (§7.3 target);
                     the Record `signature` and the outer `{ payload, signature }` envelope
                     are NOT included; null for Record 0
statementFloor     = statement generation floor in force at this Record; required on every
                     Record; epoch-establishing Records set the floor (baseline 0),
                     non-transfer Records repeat Record G-1's floor
keys               = array of key entries
transfer           = embedded signed transfer trail, { payload, signature } per §7.1 with
                     the trail payload defined in §12.2; REQUIRED on transfer-introducing
                     Records (epochId differs from Record G-1), FORBIDDEN on all other
                     Records
```

Each key entry:

```text
keyId
publicKey          = base64url (RFC 4648 §5, '=' stripped) of the algorithm-specific
                     public key encoding (§10.8); full-length key material on the wire
algorithm
validFrom          (Unix seconds)
validUntil         (Unix seconds; retired keys use an explicit past validUntil)
status             = "current" | "retired" | "revoked" | "compromised"
revokedFrom        = recordGeneration (the chain position: the recordGeneration of the
                     revoking Record, not a statement generation; required for status
                     "revoked" and "compromised")
```

Record payloads canonicalize and sign per §7.3, including wire canonicality. Records are
served on the authority endpoint (§6.3) and MAY be distributed via the policy channel
(§9.2). The key set in Record G is the complete, current snapshot of accepted keys at
`recordGeneration = G`; the signer of Record `G+1` MUST be present in Record `G` with its
`publicKey` bytes, so a verifier holding only Record `G` can verify Record `G+1`'s signature
from wire material alone. `prevRecordDigest` binds Record `G` to Record `G-1` byte-for-byte;
the chain walk verifies this binding at every step. Revocation,
retirement, compromise, and rotation status changes are expressed by successive Records.
Keys-array mutation from Record `G-1` to Record `G` is governed by three rules, applied
at chain acceptance (§9.6):

- entry identity preservation: Record `G` MUST carry every key entry accepted in Record
  `G-1`, with `keyId`, `publicKey`, `algorithm`, `validFrom`, and `revokedFrom`
  unchanged; a key entry MUST NOT be deleted from the keys array of a later accepted
  Record, and a `keyId` MUST NOT be re-registered against a different `publicKey`
  (§9.4);
- terminal-state irreversibility: an entry accepted as `revoked`, `compromised`, or
  `retired` in Record `G-1` is terminal, and its `status`, `validUntil`, and
  `revokedFrom` are established evidence: they MUST NOT be re-registered as `current`,
  overwritten, widened, shortened, removed, or extended by a later Record; in
  particular, the transfer retirement truncation `validUntil = issuedAt(G)` (§12.2)
  applies only to keys `current` in Record `G-1` and MUST NOT extend the `validUntil`
  of a key already `retired`, `revoked`, or `compromised` in Record `G-1`;
- allowed transitions: the only permitted mutations of an entry are
  `current -> retired` (rotation out, retirement, or transfer, with
  `validUntil = issuedAt(G)` of the Record performing the transition),
  `current -> revoked` (with `revokedFrom = recordGeneration` of the revoking Record,
  §13.4), and `current -> compromised` (with `revokedFrom = recordGeneration`);
  `validFrom` MUST NOT change, and `validUntil` of a `current` entry MAY change only
  via one of these transitions, a rotation Record introducing a new key, or transfer
  truncation under §12.2.

A Record that revokes the revocation, deletes a revoked entry, returns a revoked,
compromised, or retired key to `current`, extends an established terminal `validUntil`,
or re-registers a `keyId` against a new key MUST NOT be accepted by chain walk (§9.6);
recovery from a revoked or compromised key uses a new key introduced by rotation,
transfer, or re-bootstrap (§9.8), never the revoked key.

### 9.6 Chain acceptance

Verifiers MUST accept Records only by chain walk from the Trust Anchor Binding (§9.3):

1. Record `recordGeneration = 0` MUST match the Trust Anchor Binding (`anchorId`, initial
   `keyId`, initial public key bytes, initial `epochId`, initial `statementFloor`, initial
   epoch start) and MUST verify under the binding's initial public key material;
2. Record `recordGeneration = G > 0` MUST be accepted only when: Records `0..G-1` are
   accepted and Record G is the next contiguous generation; Record G's `prevRecordDigest`
   equals the SHA-256 of the JCS canonical serialization of Record `G-1`'s `payload`
   (§9.5, §7.3); Record G's `issuedAt` MUST be greater than or equal to Record
   `G-1`'s `issuedAt` (non-decreasing issuance along the chain, so a child of a
   not-yet-effective Record is never effective before its parent); Record G verifies
   under its `signerKeyId`, using that key's `publicKey`
   bytes present in Record `G-1`; `signerKeyId` has `status = "current"` in Record `G-1`
   with `validFrom <= issuedAt(G) <= validUntil`; Record G's `epochId` MUST equal Record
   `G-1`'s `epochId` when Record G carries no `transfer` member and MUST differ when it
   does, the new `epochId` MUST be an identifier never accepted in this Anchor's chain
   history, and Record G's `statementFloor` MUST equal Record `G-1`'s `statementFloor` on
   a non-transfer Record and MUST be `0` on a transfer-introducing Record; every key
   introduced by Record G is authorized by rotation (signed by a key `current` in Record
   `G-1`, §10.3) or by Record G's embedded trail, which MUST verify under the
   `publicKey` recorded for its `outgoingKeyId` in Record `G-1`, with that entry
   satisfying `validFrom <= issuedAt(G) <= validUntil` in Record `G-1` (§12.2), MUST
   satisfy every matching rule of §12.2, and, on a transfer-introducing Record, the
   Record G keys array MUST mark every key `current` in Record `G-1` as `retired` with
   `validUntil = issuedAt(G)`, leaving the trail-authorized incoming key as the sole
   `current` key in Record G (§12.2);
3. a Record that self-signs the introduction of a new key (signer not `current` in
   Record `G-1`) MUST NOT be accepted: this is the key-substitution defense, applied at
   the Record boundary, not against any historical key set;
4. Records not verifiable by chain walk MUST NOT be accepted as authority material; the
   result is `UNVERIFIED` (material not presented) or `UNKNOWN_AUTHORITY` (signer outside
   the accepted chain), per §15.2 ordering;
5. L2 0.1 acceptance: a verifier holding only the initial Trust Anchor Binding MUST be
   able to reach the current signing key after rotation, transfer, and revocation, using
   the signed Record chain alone. A verifier that cannot do this fails L2 0.1. This
   acceptance criterion is catalog-owned follow-up work (§20, §21).

The chain walk is historical evidence verification: each Record signature verifies under
the key that was `current` in Record `G-1` at that step, and that key's validity window
bounds the signing time of Record `G` (`validFrom <= issuedAt(G) <= validUntil`). The
trail signature verifies under the same window rule (§12.2): under the `publicKey`
recorded in Record `G-1` for `outgoingKeyId` with `validFrom <= issuedAt(G) <=
validUntil` in Record `G-1`; a trail whose outgoing key window in Record `G-1` excludes
`issuedAt(G)` MUST NOT be accepted, even when the signature bytes verify. A key that was
`current` in Record `G-1` and is left `current` in Record G — any key of Record `G-1`'s
`current` set surviving in Record G, beyond and in addition to the trail-authorized
incoming key, which must be the sole `current` key in Record G — is a trail matching
failure (§12.2): Record G is rejected, the chain stops at `G-1`, and a new epoch is established
only by an accepted transfer Record — Records signed in a new epoch by a key not accepted
in that epoch are rejected by chain acceptance, and resolution statements signed in a new
epoch under a key not accepted in that epoch yield `STALE` (§15.2 phase 10) or
`UNKNOWN_AUTHORITY` (phase 5), never `VERIFIED`. A
Record's `expiresAt` bounds its use as a revocation snapshot at evaluation time `T`
(§11.4); expiry of historical Records MUST NOT invalidate the accepted chain at `T`.
Verification of a historical signature under a key later marked `compromised` proves the
record trail only, and MUST NOT be treated as current trust in that key (§13.4).

### 9.7 Authority-material anti-rollback and equivocation

- the verifier MUST track the highest accepted `recordGeneration` per Anchor;
- Records below the accepted maximum MUST NOT be accepted (authority rollback attempt;
  `STALE` per §15.2);
- a Record re-presented at the accepted maximum MUST be byte-identical, compared after
  §7.3 canonicalization, to the accepted Record; a different payload at the same
  `recordGeneration` MUST produce `CONFLICT` (equivocation, §17);
- the verifier MUST NOT accept two Records with the same `recordGeneration` and different
  payloads, and MUST NOT pick one;
- a Record re-fetched, retransmitted, re-presented, or HTTP-revalidated at the accepted
  maximum MUST NOT advance the snapshot freshness point (§11.4): freshness is bound to the
  Record's signed `issuedAt`, never to retrieval time. Only acceptance of a higher
  `recordGeneration` moves the chain tip; the revocation snapshot at `T` is chosen from
  the effective prefix, not from the chain tip (§11.4);
- the effective prefix at `T` is the longest contiguous accepted prefix `0..k` in which
  every Record has `issuedAt <= T`; by the non-decreasing `issuedAt` rule (§9.6), `k` is
  the highest accepted `recordGeneration` with `issuedAt(k) <= T`; the revocation
  snapshot at `T` is Record `k` (§11.4);
- a Record accepted by chain walk with `issuedAt > T` MUST NOT serve as the revocation
  snapshot at `T`, MUST NOT update the epoch, statement floor, key status, or revocation
  state used for judgments at `T` (§11.4, §15.2 phase 6), and takes effect only at
  evaluation times `T' >= issuedAt`; a `VERIFIED` judgment at `T` MUST NOT rely on a
  Record not yet effective at `T`, and MUST NOT be obtained by backing off to an older
  Record of the effective prefix when Record `k` is not fresh at `T` (§11.4).

### 9.8 State loss

After verifier state loss (accepted Records, chain position, generation maxima), the
verifier MUST re-establish authority material by walking the chain from its Trust Anchor
Binding via the policy channel (§9.2). Authority material received on first contact
without chain provenance MUST NOT be trusted. A verifier without a trusted lower bound
MUST NOT claim `VERIFIED` from first-contact material, because old data inside a validity
window cannot be identified as old.

Recovery after compromise of a key that signed Records in the accepted chain history MUST
NOT be performed with material signed by that key: the chain walk through a compromised key
verifies the trail, not current trust. A verifier recovering from compromise MUST
re-bootstrap — obtain a new Trust Anchor Binding through the policy channel with new initial
public key material (§9.3). A chain reconstructed under a compromised initial key MUST NOT
be treated as authority state.

## 10. Key lifecycle

### 10.1 Key identifiers

`keyId` MUST be the standard base64url alphabet (RFC 4648 §5) of the first 8 bytes of the
SHA-256 digest of the public key's canonical encoding, with `=` padding stripped. Base64url
is case-sensitive: `A`-`Z` and `a`-`z` are distinct characters, and 8 bytes produce exactly
11 characters (6 bytes encode to 8 characters, the remaining 2 bytes encode to 3 characters
with one `=` pad, which is stripped). Verifiers MUST compare `keyId` by exact, case-sensitive
string equality. The public key encoding per algorithm is defined in §10.8, and every key
entry presented on the wire carries that encoding as `publicKey` (§9.5). The verifier MUST
recompute `keyId` from the presented `publicKey` bytes before accepting the entry
(fail-closed rule in §9.4). Verifiers MUST
treat `keyId` as an opaque lookup key, not as an authorization claim.

### 10.2 Baseline algorithms

Baseline L2 verifiers MUST support EdDSA Ed25519 (algorithm identifier `ed25519`).
ES256 (`ES256`, NIST P-256) is RECOMMENDED as the second supported algorithm.

A baseline L2 deployment MUST NOT require proprietary cryptographic schemes, licensed
products, external trust services, specialized hardware, or patent-dependent mechanisms.

### 10.3 Rotation

Key rotation is a signed act of the current authority. The baseline model represents
rotation via the Authority Record: a new key's validity window is established by a Record
signed by a key that is current at signing time. The Authority Record MUST NOT be
constructible by the new key alone. Record chain acceptance (§9.6) governs which Records
establish windows.

Rotation validity windows SHOULD overlap (§10.4). Rotation MUST NOT change the authority
binding's epoch; generations continue to increase monotonically across rotation.

### 10.4 Overlapping rotation windows

An Authority Record SHOULD define, per key:

```text
validFrom      (Unix seconds)
validUntil     (Unix seconds; retired keys use an explicit past validUntil)
```

Statements are accepted only when signed by a key whose validity window contains the
statement's `issuedAt` (§15).

### 10.5 Retired keys

A retired key's `validUntil` is explicit and in the past. Statements signed by a retired key
MUST NOT be accepted as current; if the statement is otherwise structurally valid, the
result is `UNVERIFIED` when the retirement is known, not `VERIFIED`. Retirement is not
compromise.

### 10.6 Revoked keys

A key revoked before its natural `validUntil` MUST be treated as non-current from the
revocation's effective generation onward. A statement signed by a revoked key MUST produce
`REVOKED` when fresh revocation evidence is available (§13). Revoked-key material MUST NOT
be accepted as rotation authority.

### 10.7 No single permanent key

The baseline MUST NOT be satisfied by hard-coding one permanent key per deployment. Deployments
SHOULD use short-lived signing keys with rotation, an offline root/attestation key for
bootstrap, and documented rotation practice. Key storage practice is a deployment concern.

### 10.8 Algorithm agility

`algorithm` is a registered identifier string. Known baseline identifiers:

```text
ed25519  – EdDSA over Ed25519; public key encoding: raw 32-byte public key,
           signature 64 bytes
ES256    – ECDSA over NIST P-256 with SHA-256; JOSE JWS "ES256" signature encoding
```

A verifier receiving an unknown or unsupported `algorithm` MUST NOT attempt verification
and MUST produce `UNSUPPORTED_ALGORITHM` (fail-closed, not `VERIFIED`, not `UNVERIFIED`).
Adding algorithms requires a later L2 version or a separately versioned profile.

## 11. Freshness and anti-rollback

### 11.1 Statement validity window

A statement is within its validity window when the verifier's accepted current time `T`
satisfies `issuedAt <= T <= expiresAt`.

### 11.2 Verifier clock

A verifier MUST maintain a documented maximum accepted clock skew tolerance. A verifier
without a trusted clock MUST NOT claim `VERIFIED`; freshness components cannot be evaluated,
and the result MUST be `UNVERIFIED`.

Statements SHOULD have bounded `expiresAt - issuedAt` appropriate to deployment, cache,
revocation, and incident-response requirements. Shorter validity is RECOMMENDED when
revocation must be effective quickly.

### 11.3 Anti-rollback

The verifier MUST track, per Anchor UUID and per `epochId`, the highest statement
generation accepted as current. The statement floor of an epoch is the `statementFloor`
established by the Record that establishes that epoch (§9.5, §12.2); the baseline floor is
`0`. Statement generations are a per-epoch sequence; they are not comparable to Record
`recordGeneration` (§7.2).

A statement whose `epochId` is not the verifier's current accepted epoch MUST NOT be
accepted as current: `STALE` (cross-epoch replay). The prior-epoch result is produced by
the statement freshness phase (§15.2 phase 10); the snapshot coverage check (§11.4)
evaluates the signing key, the current accepted epoch, and the chain, and does not
evaluate the statement's own `epochId`. Within the current epoch, a statement
with `generation` below the accepted maximum MUST produce `STALE`. A statement at the
accepted maximum MUST NOT be re-accepted as freshness progress; replay of the identical
accepted statement is permitted only within its validity window, and MUST NOT be accepted
after a revocation covering it.

Generation numbers within an epoch MUST be strictly increasing for successive accepted
statements. A statement with the same `epochId` and `generation` as an accepted statement
and a different payload MUST produce `CONFLICT` (statement equivocation, mirroring §9.7).

### 11.4 Authority material freshness and revocation coverage

Authority material has its own validity window. Expired authority material MUST NOT be
accepted as current authority. Retrieval failure, expired authority material, a chain-walk
gap, or a coverage gap MUST produce `UNVERIFIED`, not `VERIFIED`.

`VERIFIED` at time `T` is a point-in-time judgment over the verifier's revocation
snapshot. Two chain positions are distinct: the chain tip — the highest accepted
`recordGeneration` verified by chain walk (§9.6), used for anti-rollback (§9.7) — and
the effective prefix at `T` — the longest contiguous accepted prefix `0..k` in which
every Record has `issuedAt <= T`; by the non-decreasing `issuedAt` rule (§9.6), `k` is
the highest accepted `recordGeneration` with `issuedAt(k) <= T`. The revocation snapshot
at `T` is Record `k`, chosen first, before any freshness check, from the effective
prefix; its key entries carry `status` and `revokedFrom`. The snapshot's freshness is
evaluated against its signed time, not its retrieval time: Record `k` is fresh at `T` if
and only if `T <= expiresAt(k)` and `T - issuedAt(k) <= W`, where `W` is the
deployment's documented, bounded freshness upper bound; `issuedAt(k) <= T` holds by
construction of the prefix. The verifier MUST NOT back off to an older Record of the
prefix when Record `k` is not fresh: if the effective-latest Record `k` fails the
freshness check, the result is `UNVERIFIED` at phase 6, never `VERIFIED`, even when an
older Record in the prefix would pass the check. A Record with `issuedAt > T` is not
effective at `T` and MUST NOT serve as the snapshot at `T`; a Record not yet effective
at `T` MUST NOT update the revocation snapshot, the current accepted epoch, the accepted
statement floor, or any judgment at `T`, and future revocation, epoch, and floor
evidence MUST NOT preempt `T`. Re-fetching, retransmission, re-presentation, and
HTTP revalidation of a Record at the chain tip MUST NOT update the freshness point
(§9.7): the freshness point is the Record's signed `issuedAt`. Only acceptance of a
higher `recordGeneration` moves the chain tip; when that Record is effective at `T` and
fresh by this rule, the snapshot moves with it.
At evaluation time `T`, the snapshot MUST be Record `k` of the effective prefix, MUST be
fresh at `T` by this rule, and MUST cover the statement's signing key, the current
accepted epoch, and the accepted chain; the snapshot coverage check does not evaluate the
statement's own `epochId` (§11.3). A snapshot not fresh at `T` — `T - issuedAt(k) > W`
or `T > expiresAt(k)` —, a missing snapshot, an empty effective prefix, and a coverage
gap MUST produce `UNVERIFIED`, never `VERIFIED`; backing off from a non-fresh
effective-latest Record `k` to an older Record MUST NOT yield `VERIFIED`. Incomplete
chains, missing Records, and unverifiable Records are chain acceptance failures recorded
at phase 5 (§9.6 item 4), not phase 6 failures. A Record accepted by chain walk whose
`issuedAt` is later than `T` never becomes the snapshot at `T`, and a future Record
MUST NOT preempt `T` as a revocation, epoch, or floor event: a statement judged at `T`
over such material is `UNVERIFIED` at phase 6 (§15.2).

`VERIFIED` at `T` asserts nothing about revocation after `T`: revocation that occurs later
is not continuously guaranteed, `VERIFIED` MUST NOT be presented as a guarantee of future
currency, and a consumer MUST re-evaluate for currency at any `T' > T` (§13.5). Baseline
coverage is key-status based: `revoked` and `compromised` key entries with `revokedFrom`
(§13.4); per-statement revocation is not a baseline guarantee (§13.1, §21 OPEN). Statement
validity windows bound statements; the snapshot freshness upper bound `W` bounds
revocation knowledge, not statement expiry. Revocation updates withheld by the distributor
within `W` are not detectable by the verifier: `W` bounds the knowledge gap, and a
deployment that needs a bounded revocation publication latency MUST document `W` against
its distributor's publication SLA (§11.6, §21).

Clock skew is a separate time-window rule, not a snapshot override: the verifier MUST
document its trusted time range, with skew allowance `S` around its trusted clock. When
the trusted time `T`, within `S`, places a Record outside the freshness predicate above,
the snapshot fails and the result is `UNVERIFIED` at phase 6 (§15.2). The verifier MUST
NOT shift `T`, rewrite a Record's signed `issuedAt`, select a Record whose `issuedAt` is
later than `T`, or select an out-of-window Record to obtain `VERIFIED`, `REVOKED`,
`STALE`, or `EXPIRED`. A `VERIFIED` judgment at `T` asserts nothing about revocation,
epoch, floor, or key state at any `T'` outside the fresh snapshot window covering `T`.

### 11.5 Offline verification

An offline verifier (no network, no trusted-clock service) MAY verify: signature, key
binding, structure, §7.4 consistency, and recorded authority material. Without a trusted
clock, freshness MUST be evaluated against local trusted time only when the verifier
documents a trusted time source; otherwise the result is `UNVERIFIED` and MUST NOT be
surfaced as a freshness verdict. Without a fresh revocation snapshot (§11.4), `REVOKED`
cannot be determined and `UNVERIFIED` MUST NOT be upgraded. A nonce/challenge mechanism for
online verification is a follow-up (§21).

### 11.6 Clock skew and time integrity

Deployments SHOULD limit validity windows so that skew tolerance, cache lifetime, and
revocation latency are comparable. `issuedAt` is the signer's assertion, not a
third-party timestamp. Third-party timestamping is a non-baseline extension.

## 12. Ownership / authority transfer

### 12.1 Transfer is an explicit authority transition

Transfer MUST be represented by a signed transfer trail embedded in an Authority Record,
not by an implicit administrative update of Description Location, not by a key appearing
in an Authority Record, and not by lifecycle changes.

### 12.2 Transfer trail (embedded in Record G; model fixed here)

```text
statementType       = "transfer"
l2Version           = "0.1"
anchorId
entityId            (MUST match the record's Canonical Entity Identity)
outgoingKeyId       (the signing key of Record G; MUST equal Record G's signerKeyId)
incomingKeyId       (MUST equal the keyId Record G introduces as current)
algorithm           (the outgoing key's algorithm for the trail signature; MUST match
                     Record G's algorithm)
epochId             (MUST equal Record G-1's epochId: the epoch being left)
newEpochId          (MUST equal Record G's epochId; MUST be an identifier never accepted
                     in this Anchor's chain history, §9.6)
recordGeneration    (MUST equal Record G's recordGeneration)
statementFloor      (MUST equal Record G's statementFloor, baseline 0: floor reset)

forbidden payload members: keyId, generation, issuedAt, expiresAt,
descriptionLocation, lifecycleStatus, effectiveGeneration
```

The trail is the embedded `transfer` payload member of Record `G`, a signed object
`{ payload, signature }` per §7.1, signed by the outgoing key over the JCS canonical
trail payload (§7.3), so the Record `G` signature covers the trail bytes. The trail
carries no validity window: its freshness and chain position are inherited from Record
`G` through chain acceptance (§9.6), so a valid historical trail remains verifiable at
any later `T`.

The trail MUST be signed by the outgoing authority key. Bootstrap-to-first-transfer
is not applicable; the initial binding comes from the Trust Anchor Binding.

A trail whose `outgoingKeyId` is not Record `G`'s signer MUST NOT be accepted (§9.6).
A trail presented outside an accepted Record — standalone, on the policy channel, or in
a supplementary collection — MUST NOT create an epoch: only the embedded trail inside an
accepted transfer-introducing Record is transfer evidence. A verifier MUST NOT accept
unsigned, self-attested, `incomingKeyId`-signed, administratively signed, origin-signed,
DNS-signed, or CDN-signed transfer claims.

A transfer MUST bind to the authority Record chain in a fixed order:

1. Record `G` is presented in the baseline Records array (§6.3), at the next
   contiguous generation above the last accepted Record `G-1`, and carries the
   embedded `transfer` trail; Record `G`'s `epochId` differs from Record `G-1`'s;
2. chain acceptance verifies Record `G`'s Record signature under the outgoing key,
   which is `current` in Record `G-1` with a window containing `issuedAt(G)`
   (§9.5, §9.6);
3. the embedded trail MUST verify as a signed object under the `publicKey` recorded
   for `outgoingKeyId` in Record `G-1`, accepted only when that key entry in Record
   `G-1` satisfies `validFrom <= issuedAt(G) <= validUntil`; a trail signature whose
   bytes verify under the recorded key while that window excludes `issuedAt(G)` MUST
   NOT be accepted; a missing, unverifiable, duplicated, or mismatched trail MUST NOT
   be accepted: Record `G` is rejected, the chain stops at `G-1`, and statements
   resolve to `UNVERIFIED` (§9.6);
4. the verifier MUST accept Record `G` atomically as the epoch switch: Record `G`
   carries `newEpochId` and the new epoch's `statementFloor` (baseline `0`); Record
   `G`'s keys array MUST mark every key `current` in Record `G-1` as `retired` with
   `validUntil = issuedAt(G)`, so that the trail-authorized incoming key is the sole
   `current` key in Record `G`; the outgoing key is non-current from the acceptance
   of Record `G` onward, and the incoming key, introduced by Record `G` as `current`
   with a window containing `issuedAt(G)`, is the only key that signs Records from
   `G + 1` onward. A key that remains `current` in Record `G` after the retirement
   truncation of item 4 — any key of Record `G-1`'s `current` set left `current` in
   Record `G`, in addition to the trail-authorized incoming key, which must be the
   sole `current` key in Record `G` — is a trail matching failure rejected under item
   3, and keeps no signing authority in the new epoch: Records in the new epoch
   signed by such a key are rejected by chain acceptance, and resolution statements
   signed in the new epoch under a key not accepted in that epoch yield `STALE`
   (§15.2 phase 10) or `UNKNOWN_AUTHORITY` (phase 5), never `VERIFIED`;
5. the first Record signed by the incoming key MUST be at `recordGeneration = G + 1`
   and MUST be accepted under §9.6;
6. trails and Records below the accepted maximum are authority rollback (§9.7).

Signer state is judged against the immediately preceding accepted Record (`G-1` for
Record `G`), never against any earlier Record. Appendix A.5 pins a complete chain vector:
Records `0..3` with the embedded trail, the payload-only `prevRecordDigest` values, the
post-transfer statement, and the negative vectors for the trail key window, the
retire-all rule, the not-yet-effective snapshot, the expired effective-latest Record
with the back-off prohibition, and epoch acceptance (Records 4, 4E, 5, 5V, 6, 4F,
statements S5 and S6), reproducible from fixed seed strings.

### 12.3 Epoch semantics

After an epoch switch at Record `G`, every statement MUST carry the `epochId` established
by Record `G` (§7.2, §9.5). A statement carrying a prior epoch's `epochId` MUST NOT be
accepted as current (`STALE`, §11.3). Within the new epoch, statement generations start at
the `statementFloor` established by Record `G` (§11.3) and MUST be strictly increasing. The
outgoing authority MUST NOT sign in the new epoch; the incoming authority MUST NOT sign
before Record `G + 1`.

### 12.4 Transfer is not reversible in 0.1

A transfer MUST NOT be silently revoked. A new transfer away from an authority is permitted
under §12.2 by the current key. There is no `RETIRED`-record revival and no re-binding of a
RETIRED Anchor (§ Lifecycle 0.1 terminality).

### 12.5 Transfer and lifecycle

Transfer MUST NOT change lifecycle state. Lifecycle transitions remain governed by Lifecycle
0.1; an L2 transfer MUST NOT be interpreted as suspension, retirement, reactivation,
ownership of the physical Entity, capability authorization, or administrative rights.

```text
Lifecycle state  ≠ Authority binding
Ownership        ≠ Capability authorization
```

### 12.6 Transfer race conditions

Concurrent competing transfers from the same outgoing key MUST be resolved fail-closed:
verifiers MUST NOT accept multiple current epochs for one Anchor (§8.3, `CONFLICT`).
Submission-time concurrency control on the administrative surface is a deployment concern.

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

Revocation MUST be signed by the current authority (the key that owns the revocable object)
or by an accepted successor epoch after transfer. A revoked key MUST NOT revoke. A key not
in the accepted binding MUST NOT revoke. An administrative actor's login is not L2
revocation authority (§16).

### 13.3 Revocation discovery

Revocation evidence is discovered from authority material (§9.2) and, when available,
revocation material resources (follow-up, §21). Revocation evidence MUST itself be fresh
and verifiable under an accepted authority (§11.4).

### 13.4 Revocation effects

A fresh, verified revocation of a key: the key's window ends at the `issuedAt` of the
Record carrying the revocation; statements under that key with `issuedAt` at or after that
Record's `issuedAt` are `REVOKED`; statements below remain governed by §11.3. Per-statement
revocation is not a baseline
guarantee (§13.1): baseline coverage is key-status based (§11.4). A statement revocation
extension, when defined, MUST invalidate verifier caches of revoked statements at the
verifier's next accepted evaluation (§21 OPEN).

Revocation, retirement, and compromise are expressed as Record chain operations (§9.5): the
signing key is `current` under Record `G-1`, and Record `G` marks the target key
`status = "revoked"` or `"compromised"` with `revokedFrom = recordGeneration` of Record
`G` (the Record position in the chain, not a statement generation). `revokedFrom = G`
means the revocation is expressed by Record `G` and takes effect at Record `G`'s
`issuedAt`; the entry is then terminal and irreversible under the §9.5 rules.

A key marked `compromised` in the accepted chain MUST NOT be accepted as current by the
verifier, for any signature, at any generation, at any time. A compromised key that
re-signs old `generation`/`issuedAt` values after compromise produces a signature the
verifier MUST NOT accept as current: without independently trusted evidence, the verifier
MUST NOT recognize a signature from a compromised key as current (§17, leak-replay). A
signature by a compromised key, evaluated with fresh coverage, MUST be surfaced as `REVOKED`,
never as `VERIFIED`. Verification of a historical Record signature by a key later marked
`compromised` proves the record trail only (§9.6); it MUST NOT make signatures by that key
current, and recovery from compromise of the initial binding key requires re-bootstrap with
new Trust Anchor Binding key material (§9.8).

A revoked, compromised, or retired key MUST NOT return to `current` in any later accepted
Record, in any resolver evaluation, at any time. Re-registration of such a key MUST be
rejected by chain walk (§9.5, §9.6), and the revocation history — `status`,
`revokedFrom`, and the key window — is retained by the accepted chain and applied at
every evaluation, in chain acceptance and in resolution judgment alike. A resolution
statement presented under a re-registered key MUST NOT yield `VERIFIED`: chain acceptance
rejects the re-registration Record, and the statement fails at §15.2 phase 5
(`UNKNOWN_AUTHORITY`, signer outside the accepted chain) or phase 9 (`REVOKED` for a
compromised key, `UNVERIFIED` for a retired key). Legitimate recovery uses a new key
introduced under §9.5, §12.2, or §9.8.

### 13.5 Fail-closed revocation availability

Baseline requires a fresh revocation snapshot for `VERIFIED` (§11.4). When revocation chain
state is unavailable, when the snapshot is stale, incomplete, or not the highest accepted
Record, or when the chain walk cannot be completed, the result is `UNVERIFIED` (fail-closed
for L2-VERIFIED claims). Revoked-but-cached statements MUST NOT be surfaced as current.
Revoked material present in an HTTP cache MAY remain HTTP-reusable per RFC 9111 cache rules
(§14.2); HTTP reusability is a separate judgment, and the L2 verifier's currency decision
(§§11, 13) MUST NOT be derived from cache freshness, MUST NOT accept revoked material as
current.

### 13.6 Offline revocation

An offline verifier MUST NOT mark statements `REVOKED` without locally retained fresh
revocation evidence; `UNVERIFIED` is the required result when revocation coverage cannot be
evaluated. Offline verification MUST NOT be represented as revocation-safe.

## 14. HTTP, cache, and privacy behavior for L2 material

### 14.1 Public surface

L2 public material is anonymous, read-only, GET-only, HTTPS-only, with the same redirect
downgrade rule as L1: HTTPS-to-HTTP downgrade in the L2 material dereference chain MUST fail
the dereference and MUST NOT be downgraded. CORS guidance follows Resolver Core.

### 14.2 Cache policy for L2 statements

A `200` L2 statement response MUST send an explicit cache policy that NEVER exceeds the
statement's remaining validity:

```text
Cache-Control: public, max-age = min(deployment bound, expiresAt - now)
```

`400`/`404`/`405`/`500`/`501`/`503` SHOULD use `Cache-Control: no-store`. `410 Gone` MAY be
cached, as in Core 0.1, because retirement is terminal.

These cache rules govern HTTP reusability of stored L2 responses (RFC 9111). A normal
cache applies RFC 9111 rules plus the explicit TTL above, and has no automatic knowledge
of revocation, rotation, transfer, or epoch transition during its freshness window; HTTP
caches MUST NOT be required to detect revocation, and revocation and epoch-transition
events MUST NOT be modeled as HTTP cache behavior. Invalidation of L2 trust results on
known revocation, transfer, or epoch transition is defined on the L2 verifier side
(§13.4, §13.5): the verifier MUST NOT surface known-revoked, known-superseded, or
prior-epoch material as current, and MUST re-evaluate at its next accepted evaluation.
Issuers that require immediate L2 freshness MUST choose short `max-age`, `no-store`, or
revalidation directives, and MUST record that choice. The L2 verifier's currency decision
(§§11, 13) is a separate judgment: an HTTP-reusable response is not L2-current by virtue
of reuse.

### 14.3 Privacy

Anchor UUIDs in L2 requests MUST be treated as loggable operational metadata with the same
care as L1, including minimized handling of `l`/`p` query parameters. L2 trust material
MUST NOT be cached in admin surfaces beyond policy. Revocation and transfer material MAY be
privacy-sensitive; deployments SHOULD minimize log retention.

### 14.4 Referrer policy

Same as Resolver Core: `Referrer-Policy: no-referrer` SHOULD be sent on L2 responses for
redirect responses; L2 responses SHOULD follow the same referrer-minimization guidance as
Core.

## 15. Verification result model

A verifier MUST produce exactly one deterministic primary state for a statement evaluation,
recorded with its verification mode (§15.1) and evaluated in the normative ordering of
§15.2. The verifier MUST be able to distinguish structural/signature failure from missing
trust information and from freshness failure.

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
    fresh revocation snapshot marks the signing key compromised, or revoked with the
    statement `issuedAt` at or after the `issuedAt` of Record `revokedFrom` (§11.4, §13.4,
    §15.2 phase 9)
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

`UNVERIFIED` MUST NOT be surfaced as `VERIFIED`, and MUST NOT be surfaced as a failure of
the Entity. `UNVERIFIED` is an absence of trust knowledge, not a trust verdict. A consumer
claiming L2 verification conformance MUST expose these states, not a boolean, and MUST NOT
silently collapse `UNVERIFIED` into "allowed".

```text
L1 status            ≠ L2 verification state
L2 state VERIFIED    ≠ Entity is safe / authorized / reachable / non-local
L2 state VERIFIED    ≠ capability authorization
L2 state UNVERIFIED  ≠ AR-XML parse failure
L2 failure           ≠ network failure, integrity failure, or L1 failure
```

The verifier MUST NOT fetch AR-XML as part of L2 verification, MUST NOT execute capabilities,
and MUST NOT treat L2 verification as a dereference permission (§16, network policy stays
where L1 places it).

### 15.1 Verification modes

The verifier MUST evaluate in one of two modes, recorded in the result as
`verificationMode`:

```text
statement-only   the statement is verified against the verifier's accepted authority
                 material; §7.4 consistency is not evaluated
l1-bound         the statement is verified against the verifier's accepted authority
                 material AND §7.4 consistency is evaluated against the observed L1 303
                 response for the same expected Anchor
```

The verifier MUST obtain its expected `anchorId` out of band, from the Anchor UUID of the
original request, not from the statement, the response, or the wire. A statement whose
`anchorId` does not equal the expected Anchor MUST be rejected as `ANCHOR_MISMATCH`: a valid
signature for a different Anchor pointing at the same Description Location is not a valid
assertion for the expected Anchor, and Location equality alone MUST NOT override
`anchorId` equality.

`statement-only` results MUST NOT be presented, stated, or displayed as "current L1
resolution confirmed by signature". A `VERIFIED` result MUST expose its mode; a modeless
boolean is not permitted. In `statement-only` mode, `VERIFIED` means signature, authority,
freshness, and revocation-snapshot checks passed with no L1 observation evaluated; in
`l1-bound` mode, `VERIFIED` additionally means §7.4 passed.

When `l1-bound` evaluation is required and the L1 observation cannot be obtained, the
result is `UNVERIFIED`, not `MISMATCH`, not `VERIFIED`.

### 15.2 Precedence order

When multiple failure conditions apply, the result is the state of the first failing phase,
in the normative order below. All phases passing yields `VERIFIED`.

```text
1.  wire canonicality, strict parse, duplicate members, integer range, l2Version,
    payload/signature separation, keyId/publicKey recomputation match (§9.4, §10.1)
    INVALID
2.  expected anchorId match (§15.1)                                    ANCHOR_MISMATCH
3.  algorithm identifier known and supported (§10.8)                   UNSUPPORTED_ALGORITHM
4.  trusted clock available (§11.2)                                    UNVERIFIED
5.  authority material present, chain walk 0..G complete, Record contiguity,
    prevRecordDigest binding, signerKeyId resolvable with publicKey in the accepted
    chain, embedded transfer trails verified with all §12.2 matching rules
    (§9.6, §12.2)                                                       UNVERIFIED (chain gap,
    missing material, no trusted lower bound §9.8); UNKNOWN_AUTHORITY (signer outside the
    accepted chain; keyId/publicKey recomputation failure §9.4)
6.  revocation snapshot: the last Record k of the effective prefix at T — the longest
    contiguous accepted prefix 0..k in which every Record has `issuedAt <= T`, with
    `issuedAt` non-decreasing (§9.6); freshness by the §11.4 signed-time rule:
    `T <= expiresAt(k)` and `T - issuedAt(k) <= W`, covering the signing key, the
    current accepted epoch, and the chain; no back-off to an older Record of the
    prefix when Record k is not fresh; snapshot coverage does not evaluate the
    statement's own `epochId` (§11.3, §11.4)                          UNVERIFIED on gap,
    signed-time staleness, expired effective-latest snapshot, coverage gap
7.  Record chain position: Record below accepted maximum, same-generation byte-identity,
    epoch establishment (§9.7, §12.2, §12.3)                          STALE / CONFLICT
8.  signature verification under the signerKeyId publicKey bytes (§9.6)
    INVALID
9.  signer key status in the fresh snapshot, at issuedAt, for statements whose
    `epochId` equals the current accepted epoch; a statement whose `epochId` does
    not equal the current accepted epoch skips this key-status judgment and is
    judged at phase 10: compromised, revoked (statement
    `issuedAt` at or after the `issuedAt` of Record `revokedFrom`), retired, outside
    validity window, entityId
    mismatch                                                          REVOKED (compromised,
    revoked); UNVERIFIED (retired, §10.5; outside window, §10.4);
    UNKNOWN_AUTHORITY (entityId mismatch, §15)
10. statement freshness [issuedAt, expiresAt] vs T; statement `epochId` vs current
    accepted epoch — an `epochId` of a prior epoch, of an epoch never accepted in this
    Anchor's chain, or of an epoch introduced only by a rejected Record yields `STALE`
    here and nowhere earlier; statement generation vs accepted maximum and statement
    floor; statement equivocation
    (§11.3, §12.3)                                                    EXPIRED; STALE (epoch
    mismatch — prior epoch, unaccepted epoch, epoch introduced only by a rejected
    Record; below floor, below accepted maximum); CONFLICT (equivocation)
11. l1-bound mode, §7.4 (§15.1)                                        UNVERIFIED on missing
    observation; MISMATCH on equality failure
```

Within a phase, conditions apply in listed order, and each branch yields exactly one
result. A signer absent from the accepted chain fails at phase 5 as
`UNKNOWN_AUTHORITY` and never reaches phases 8–9; `keyId`/`publicKey` recomputation
mismatch fails at phase 1 as `INVALID` for the Record, phase 5 for the dependent
statement, consistent with §9.4. A retired key yields `UNVERIFIED` (§10.5), never
`REVOKED`; `REVOKED` requires a fresh snapshot, which phase 6 guarantees before phase 9.
A statement replayed within its validity window at the accepted maximum is not freshness
progress (§11.3), and re-fetching, retransmission, re-presentation, and HTTP revalidation
of an accepted Record at the accepted maximum do not refresh the revocation snapshot
(§9.7, §11.4). A statement whose `epochId` does not equal the current accepted epoch does
not reach the phase 9 key-status judgment; its epoch mismatch yields `STALE` at phase 10,
including a prior epoch, an epoch never accepted in this Anchor's chain, and an epoch
introduced only by a rejected Record. A Record accepted by chain walk that is not yet
effective at `T` (`issuedAt > T`) fails the snapshot freshness predicate at phase 6 as
`UNVERIFIED` before phases 9–10 run (§11.4). When the effective-latest Record `k` of the
effective prefix at `T` is not fresh at `T` — expired (`T > expiresAt(k)`) or stale
(`T - issuedAt(k) > W`) — the result is `UNVERIFIED` at phase 6, even when an older
Record of the prefix would pass the freshness check (Appendix A.5 Record 4E). Fixed
expected results: a signed statement
carrying an `epochId` from a prior epoch, an epoch never accepted in this Anchor's chain,
or an epoch introduced only by a rejected Record is `STALE` at phase 10, and its key
status is not judged at phase 9; a revocation boundary — a statement `issuedAt` at or
after the `issuedAt` of Record `revokedFrom` — is `REVOKED` at phase 9, within the
accepted-epoch scope; a missing snapshot, a snapshot stale by its signed time, an expired
effective-latest Record `k` of the effective prefix at `T` (`T > expiresAt(k)`), and a
snapshot not yet effective at `T` (`issuedAt > T`, excluded from the effective prefix)
are `UNVERIFIED` at phase 6, never `VERIFIED`; backing off from a non-fresh
effective-latest Record to an older Record of the prefix that would pass the freshness
check MUST NOT yield `VERIFIED`; `VERIFIED` MUST NOT rely on revocation, epoch, floor,
or key evidence from a Record not effective at `T`. Authority rollback (Record below the accepted
maximum) is `STALE`; a same-generation Record with a different payload is `CONFLICT`; a
signer outside the accepted chain is `UNKNOWN_AUTHORITY`. A verifier MUST NOT reorder
phases, MUST NOT pick by severity, recency, or origin preference. The result MUST record
which phase failed.

## 16. Relationship to L1, Manifest 0.1, and administration

### 16.1 L1

L1 remains valid, unchanged, and sufficient on its own. L2 verification is additive. An L2
consumer MAY verify L2 without re-implementing trust for L1 clients, and L1-only consumers
MUST remain fully conforming. L2 MUST NOT force Manifest, AR-XML parsing, or trust checks
into the Core path. A statement's description assertion is verified only against what L1
observed; L2 MUST NOT replace L1 redirect processing.

### 16.2 Manifest 0.1

Manifest remains optional for ordinary L1 and for L2 baseline. L2 MAY sign selected Manifest
fields, reference a Manifest, extend Manifest via an L2 extension, use a separate resource,
and support deployments with no Manifest at all. The L2 statement's `entityId` and
`descriptionLocation` reuse Frozen Manifest 0.1 vocabulary without redefining it. An L2
profile MAY require Manifest retrieval; that profile is separately versioned.
Manifest 0.1 integrity pinning MUST NOT be merged into L2 verification semantics.

### 16.3 Administrative authentication

```text
Admin authentication
= who may mutate resolver records

L2 authority
= who is cryptographically/semantically authoritative for an Entity
```

The two are distinct. Administrative login, session, CSRF, and operator identity are not
proofs of L2 authority, and L2 authority is not an administrative credential. Registration
bootstrap MAY create the initial Trust Anchor Binding through the administrative channel;
this is a policy connection, not a protocol identity. L2 MUST NOT require, replace, define,
or prohibit OAuth, OIDC, SSO, RBAC, WebAuthn, passwords, sessions, CSRF, device pairing, or
other administrative authentication mechanisms.

### 16.4 Reference Resolver boundary

L2 statement issuance MUST NOT require fetching, parsing, or validating AR-XML. L2 MUST NOT
construct management UI, resolve device IP, invoke capabilities, or expose mutation on the
public surface. Reference Resolver L2 support is an additive optional surface; Core conformance
claims remain valid without L2.

## 17. Threat model and security analysis

The L2 baseline MUST address, at minimum, the following. Each threat maps to normative rules
in this draft.

| Threat | Baseline mechanism |
| --- | --- |
| Replay | validity window + per-epoch generation + accepted-maximum (§11.3) |
| Rollback | monotonic Record generations, per-epoch statement floors, `STALE` (§9.7, §11.3, §12.3) |
| Stale signed data | `expiresAt`, epoch floor, `EXPIRED`/`STALE` (§11) |
| Key compromise | revocation, short windows, fail-closed revocation, compromised flag in Records, never-current signatures, re-bootstrap after initial-key compromise (§9.8, §10.6, §13.4) |
| Key substitution | Authority Record chain: signed by prior-current key, never by new key alone; key material carried on the wire as `publicKey`, `keyId` recomputed (§9.4, §9.5, §9.6, §10.3) |
| Conflicting authorities | `CONFLICT`, fail-closed, no reconciliation heuristics (§8.3) |
| Downgrade to L1 | frozen Core `l` rules preserved (SHOULD-level `501` guidance), never L1 (§6.1, §5) |
| Signature stripping | signing target = canonical payload octets; signature in payload rejected; `VERIFIED` requires signature (§7.1, §15 `INVALID`) |
| Canonicalization ambiguity | single JCS scheme (RFC 8785), wire canonicality, duplicate-member detection in raw wire text, UTF-16 code-unit member order, non-BMP test vector (§7.3, Appendix A) |
| Clock skew | documented skew tolerance, bounded validity, `UNVERIFIED` without trusted clock (§11.2) |
| Trust-anchor compromise | bootstrap provenance is a policy channel; authority material self-bootstrapping forbidden (§9.2–9.3) |
| Transfer race | outgoing-key signature required, single current epoch, `CONFLICT` (§12.2, §12.6) |
| Revoked-but-cached statements | verifier-side invalidation, cache bounded by remaining validity, L2 currency separate from HTTP reuse (§13.4, §13.5, §14.2) |
| Offline verification limits | `UNVERIFIED` for clock/revocation gaps, never `VERIFIED` from offline gaps (§11.5, §13.6) |
| Compromised key re-signing old generation/issuedAt | compromised flag propagates through the Record chain; signatures from a compromised key are never current; revocation snapshot required for `VERIFIED` (§13.4, §11.4) |
| Authority Record rollback / equivocation | `recordGeneration` contiguity, accepted maximum, byte-identity at the same generation, `STALE`/`CONFLICT` (§9.7, §15.2) |
| Cross-anchor statement substitution | expected `anchorId` obtained out of band, `ANCHOR_MISMATCH`; Location equality alone never overrides (§15.1) |
| First-contact authority bootstrap | chain walk from the Trust Anchor Binding, re-establishment after state loss, no first-contact `VERIFIED` (§9.6, §9.8) |
| keyId ↔ publicKey substitution | `keyId` recomputed from wire `publicKey` bytes; Records carry full key material; chain acceptance from binding material (§9.3, §9.4, §9.5, §10.1) |
| cross-epoch replay / generation mixing | statement `epochId` on the signing target, per-epoch statement floors, `recordGeneration` and statement `generation` as separate sequences (`STALE`, §11.3, §12.2, §12.3) |
| freshness refresh by re-fetching accepted old Records | freshness is evaluated against the Record's signed `issuedAt` and `expiresAt`; re-fetch, retransmission, re-presentation, and HTTP revalidation at the accepted maximum never advance the freshness point; revocation updates withheld by the distributor within `W` are undetectable, and `W` bounds the knowledge gap (§9.7, §11.4) |
| compromised initial-key chain recovery limits | chain walk through a compromised key verifies the historical trail, never current trust; recovery requires re-bootstrap with new Trust Anchor Binding key material via the policy channel (§9.6, §9.8, §13.4) |
| transfer trail absence, tampering, duplication | embedded trail inside Record `G` is the only transfer evidence; all §12.2 matching rules; chain stops at `G-1` with `UNVERIFIED` for statements, `CONFLICT` on same-generation Record duplication (§12.2, §9.6, §9.7, §15.2) |
| trail signed outside the outgoing key's window | Record and trail signatures are verified under the signer key's `current` window in Record `G-1`: `validFrom <= issuedAt(G) <= validUntil`; a cryptographically valid signature by a key whose `G-1` window excludes `issuedAt(G)` is rejected; all `G-1` current keys MUST be retired in `G` with the trail-approved incoming key as sole current (§9.6, §12.2) |
| epoch introduced only by rejected Records | unaccepted epochs are never current; Records signed under such authority are rejected, chain stops; statements carrying the epoch are `STALE` at phase 10, never `VERIFIED` (§9.6, §12.2, §15.2 phase 10) |
| revoked-key revival | revocation history is persisted in every Record; re-registration, `revokedFrom` overwriting, widening, shortening, deletion, and revokes-the-revocation Records are rejected by chain walk; a revoked key is never `current`; statements from a revived key yield `UNKNOWN_AUTHORITY`, `REVOKED` (compromised), or `UNVERIFIED` (retired), never `VERIFIED`; recovery is a new key, rotation, transfer, or re-bootstrap (§9.5, §9.6, §10.6, §13.4, §15.2 phases 5, 9) |
| key-entry mutation beyond allowed transitions | keys-array mutation is limited to entry identity preservation, terminal-state irreversibility, and the allowed transitions `current -> retired`, `current -> revoked`, `current -> compromised`; deletion of entries, re-registration of a `keyId` against a different `publicKey`, widening, shortening, extension of a terminal `validUntil`, revokes-the-revocation, and transfer truncation extending an already-retired key's window are rejected by chain walk (§9.5, §9.6, §12.2, §13.4) |
| future-issuedAt Record as revocation evidence | the snapshot MUST be the last Record `k` of the effective prefix at `T` (`issuedAt(k) <= T`), fresh by `T <= expiresAt(k)` and `T - issuedAt(k) <= W`; a Record with `issuedAt > T` is never the snapshot at `T`, never refreshes epochs, floors, or revocation, and future evidence is never accepted pre-emptively (§9.7, §11.4, §15.2 phase 6) |
| back-off past an expired effective-latest Record | the snapshot is the effective prefix tip at `T`, chosen before the freshness check; an expired (`T > expiresAt(k)`) or stale (`T - issuedAt(k) > W`) effective-latest Record yields `UNVERIFIED` at phase 6, never `VERIFIED` via an older in-window Record; non-decreasing `issuedAt` keeps children of not-yet-effective Records not effective at `T` (§9.6, §9.7, §11.4, §15.2 phase 6, Appendix A.5 Record 4E) |

Explicit non-goal boundary: L2 does not provide physical Entity authentication, device
authorization, capability authorization, AR-XML semantic validation, or general PKI
replacement, and `VERIFIED` MUST NOT be surfaced as any of them (§15, §21).

## 18. Non-goals

L2 0.1 MUST NOT define:

1. Capability authorization, OAuth itself, user authentication for Entity operations;
2. AR-XML semantic validation, Profile or Capability Contract resolution;
3. device IP / network address resolution, automatic Capability execution;
4. generic PKI replacement;
5. full decentralized identity architecture beyond the minimal model;
6. new public mutation semantics on the Core GET path;
7. mandatory Manifest;
8. append-only ledgers, blockchains, transparency logs, or third-party timestamping as
   baseline requirements;
9. proprietary crypto, licensed authentication products, specialized hardware, or
   patent-dependent mechanisms as baseline;
10. changes to Frozen Resolver Core 0.1, Lifecycle 0.1, Manifest 0.1, Extension Policy,
    Runtime Integration Contract 0.1, Conformance Catalog 0.1, Reference Resolver
    Architecture 0.1, or Deployment Profiles 0.1.

## 19. Baseline vs extensions

Baseline mandatory guarantees: G1–G8 (§4). A verifier claiming L2 0.1 baseline conformance
MUST implement: JCS canonical serialization with UTF-16 code-unit member order (§7.3),
`ed25519` verification, statement parsing, Record chain acceptance from the Trust Anchor
Binding including its public key material and the embedded transfer trails (§9.6, §12.2),
keyId recomputation from wire `publicKey`
bytes (§9.4, §10.1), authority-material anti-rollback and equivocation handling (§9.7),
freshness, per-epoch statement anti-rollback, the revocation snapshot evaluated against its
signed time (§11.4), the full
state model in §15 with verification modes and the §15.2 precedence order, §7.4 consistency
in `l1-bound` mode, and fail-closed behavior everywhere required.

Optional extensions: `ES256` and later algorithms (§10.8), delegation/chained authority,
nonce/challenge online checks, per-statement revocation (§13.1), Manifest association,
third-party timestamps, transparency logs, profile identifiers via `p`. Extensions MUST NOT
be required for baseline conformance, MUST NOT redefine baseline semantics, and MUST NOT be
treated as security-critical by baseline verifiers.

## 20. Conformance and testbed handoff

The L2 conformance catalog is follow-up work (Issue #28 deliverables sequence):

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

L2 targets anticipated for the follow-up catalog (identifiers owned by the catalog, not by
this draft):

```text
L2-SERVER        (statement/authority material endpoint behavior)
L2-PRODUCER      (canonical serialization, signing, key lifecycle practices)
L2-VERIFIER      (state model, all §15 states, fail-closed rules)
L2-AUTHORITY     (rotation/transfer/revocation signature semantics)
```

L2-VERIFIER / L2-AUTHORITY acceptance includes: chain acceptance from an initial Trust
Anchor Binding carrying public key material, reaching the current key after rotation,
transfer, and revocation over the wire (§9.3, §9.6 item 5); a Record chain vector where a
verifier holding only the initial binding accepts Records `0..G` through embedded transfer
trails, reaches the post-transfer key, and verifies the post-transfer resolution statement
in the new epoch (Appendix A.5); the Record 0→1 `prevRecordDigest` vector with the
payload-only target (§9.5); §9.7 equivocation handling; revocation snapshot freshness
evaluated against the Record's signed time for `VERIFIED` (§11.4), where re-fetching,
re-presentation, and revalidation of an accepted Record do not refresh the snapshot;
snapshot selection from the effective prefix `0..k` at `T` — the longest accepted prefix
in which every Record has `issuedAt <= T`, `issuedAt` non-decreasing (§9.6) —, with the
negative vector Record 4E, where the effective-latest Record `k` is expired at `T`, the
verifier MUST NOT back off to an older in-window Record, and the result is `UNVERIFIED`
at phase 6 (Appendix A.5); trail signature verification under the outgoing key's
`current` window in Record `G-1`
(`validFrom <= issuedAt(G) <= validUntil`), retirement of every `G-1` current key in
Record `G` with the trail-approved incoming key as sole current, and rejection of a
Record whose signature verifies cryptographically while the `G-1` window excludes
`issuedAt(G)` (§9.6, §12.2); chain vectors where a rejected Record stops the chain at
the accepted maximum and an epoch introduced only by a rejected Record yields `STALE` at
phase 10 for statements (§15.2); revocation snapshots that never use a Record not yet
effective at `T` (`issuedAt > T`) as evidence (§11.4), the non-decreasing `issuedAt`
chain constraint (§9.6), revocation history continuity, revoked-key revival rejection,
entry identity preservation, terminal-state irreversibility, and allowed status
transitions only in the keys array (§9.5, §9.6, §12.2, §13.4); §15.2 precedence cases, with epoch mismatch
`STALE` at phase 10, revocation boundary `REVOKED` at phase 9 within the accepted-epoch
scope, and snapshot staleness `UNVERIFIED` at phase 6; signature
verification compared under Ed25519 and ES256 with known vectors; identical payload with
differing signature encodings yielding the same digest interpretation; transfer trail
absence, tampering, and duplication; compromised-key recovery requiring re-bootstrap;
Appendix A canonical and signature vectors, including the non-BMP UTF-16 code-unit vector
and the Vector 3b U+2028, U+2029, and U+007F escaping fixture.
Identifiers remain catalog-owned; this draft MUST NOT invent them.

This draft MUST NOT invent `RES-*`, `LIFE-*`, `MAN-*`, `INT-*`, `NET-*`, `RT-*`, or L2
catalog case identifiers. The Frozen Catalog owns 0.1 identifiers; a future L2 catalog owns
L2 identifiers. Local PHPUnit tests map to the L2 catalog only after it exists; the
repository confirmation flow (`composer validate`, `composer test`, `composer analyse`,
`php -l`, `git diff --check`) applies to implementation work, and local results MUST NOT be
reported as relink-testbed results.

## 21. Open issues requiring follow-up decisions

1. `p` semantics for L2 profiles, nonce/challenge binding for online freshness;
2. revocation as a public resource (paths, status codes, discovery, submission
   channel: administrative vs. L2-signed material); transfer trails are embedded in
   Records and baseline-defined (§6.3, §12.2), not an open resource question;
   per-statement revocation as a
   non-baseline extension (§13.1, §13.4); revocation status itself is a Record chain
   operation (§9.5, §13.4);
3. Trust Anchor Binding provisioning profile (factory pairing, operator trust lists,
   device-bundled anchors, binding public key material §9.3, epochId and statementFloor
   establishment) and its conformance;
4. URI comparison normalization for §7.4 beyond exact byte equality;
5. `statementType` registry governance and version negotiation;
6. delegation / chained authority design;
7. authority material for SUSPENDED/RETIRED lifecycle edges in administrative, export, and
   archival contexts;
8. revocation propagation latency targets, numeric snapshot freshness upper bounds beyond
   the §11.4 baseline requirement (profile parameters, not fixed in 0.1), cache
   invalidation interfaces, and coverage horizon profiles beyond the §11.4 baseline;
9. ES256 adoption as MUST, and a profile mechanism for adding algorithms;
10. L2 conformance catalog case set, including §9.6 chain-acceptance, §12.2 transfer
    trail matching rules, §9.5 `prevRecordDigest`, §9.7 equivocation,
    §11.4 revocation snapshot, §15.2 precedence, signature test vectors beyond
    Appendix A, Ed25519/ES256 known-vector comparison, and compromised-key recovery;
11. Japanese normative translation after stabilization.

## 22. Acceptance-criteria mapping (Issue #28)

| Issue #28 acceptance criterion | Draft coverage |
| --- | --- |
| L2 is a separate versioned layer, frozen Core 0.1 semantics unchanged | §1, §5, freeze policy |
| Integrity / authenticity / authority / freshness / ownership / authorization distinguished | §4, §8.2, §15, §16.3 |
| Signed / verifiable object defined | §7 (payload, JCS canonical serialization, signing bytes), Appendix A vectors |
| Signer identity and Entity authority rules defined | §8, §§9.1–9.8 (Authority Record format with wire `publicKey` material, chain acceptance, anti-rollback, state loss) |
| Key discovery and rotation semantics defined | §§9.5–9.7, §10 (validity windows, rotation Records, keyId recomputed from wire publicKey, epochId; keys-array mutation rules: entry identity preservation, terminal-state irreversibility, allowed status transitions only); OPEN items in §21 |
| Freshness and anti-rollback defined | §11 (statement anti-rollback, authority-material anti-rollback §9.7, revocation snapshot §11.4 chosen as the last Record k of the effective prefix at `T` (`issuedAt <= T`, non-decreasing §9.6), fresh by `T <= expiresAt(k)` and `T - issuedAt(k) <= W`, with signed-time freshness, not-yet-effective Records excluded, no back-off past a non-fresh effective-latest Record, clock skew as a separate rule, and re-fetch non-refresh) |
| Ownership / authority transfer semantics defined | §12 (embedded transfer trail in Record G, §12.2 matching rules including trail key verification under the outgoing key's window in Record G-1, retire-all-current-keys with the incoming key as sole current, epoch switch, trail carried in the baseline Records array §6.3) |
| Revocation semantics defined | §13, §9.5, §13.4 (revocation, retirement, compromise as Record chain operations; `revokedFrom = recordGeneration` of the revoking Record, effective at Record `issuedAt`; revocation history persisted in every Record, terminal-state irreversibility, re-registration and revokes-the-revocation rejected by chain walk); revocation material resource OPEN, §21 |
| Verification result states deterministic and documented | §15 (modes, precedence order, `ANCHOR_MISMATCH`) |
| Relationship to Manifest 0.1 explicit | §16.2, §7.6 |
| Relationship to administrative authentication explicit | §16.3 |
| L1 downgrade / fallback explicit and fail-closed | §5, §6.1, §6.5, §17 |
| Security analysis covers replay, rollback, key compromise, conflicting authority, signature stripping, Record rollback/equivocation, cross-anchor substitution, compromised-key re-signing, first-contact bootstrap, keyId/publicKey substitution, cross-epoch replay, re-fetch freshness refresh, transfer trail integrity, trail key window verification in Record G-1, epochs introduced only by rejected Records, revoked-key revival, future-issuedAt snapshot evidence, key-entry mutation beyond allowed transitions, back-off past an expired effective-latest Record, compromised-key chain recovery | §17 |
| Capability execution and authorization out of scope | §18, §16.4, §15 |

All criteria are draft-covered at the specification level; implementation, L2 conformance
catalog, and testbed scenarios remain follow-up per §20.

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
    transfer  = embedded signed trail in Record G (outgoingKeyId, incomingKeyId,
                epochId, newEpochId, recordGeneration, statementFloor), signed by the
                outgoing key; all §12.2 matching rules; trail in the baseline Records
                array; incoming key signs from Record G+1, statement floor reset
    revocation= signed by current authority, fail-closed
    one current binding per anchor; conflicts → CONFLICT
    Authority Record = signed snapshot, recordGeneration strictly increasing,
    publicKey on the wire, prevRecordDigest = lowercase hex SHA-256 of the UTF-8 octets
    of JCS(previous Record payload), epochId, statementFloor
    chain walk 0..G from the Trust Anchor Binding, embedded transfer trails verified
    with §12.2 matching rules; key never signs its own introduction
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
    VERIFIED at T requires the revocation snapshot fresh at T by its signed time:
    the snapshot is the last Record k of the effective prefix at T — the longest
    accepted prefix 0..k in which every Record has issuedAt <= T, issuedAt
    non-decreasing (§9.6); freshness is T <= expiresAt(k) and T - issuedAt(k) <= W
    (§11.4); no back-off to an older Record of the prefix when Record k is not
    fresh; a Record not yet effective at T is never the snapshot at T;
    re-fetching, retransmission, re-presentation, and revalidation of an accepted
    Record never refresh the snapshot; VERIFIED at T makes no claim about T' > T

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

---

## Appendix A. Deterministic Ed25519 + JCS test vectors (informative)

This appendix pins the exact signing target, signature encoding, and canonical
serialization rules of §7.3, including object member order in UTF-16 code-unit
order (RFC 8785 §3.2.3, §7.3), and pins the full Record chain vector: Records `0..3`,
the embedded transfer trail (§12.2), the payload-only `prevRecordDigest` target (§9.5),
and the post-transfer statement. The vectors are reproducible from the fixed seed
strings below (A.1, A.5) using only RFC 8032 Ed25519, RFC 8785 JCS, SHA-256, SHA-512,
and base64url (RFC 4648 §5). Conformance test case identifiers are catalog-owned
follow-up work (§20); this appendix provides reference values, not case identifiers.

### A.1 Fixed key material

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

The verifier MUST recompute `keyId` from `A` (§10.1) and MUST match
`ECMERdvpV0k`; the wire `publicKey` of every Record that introduces this key MUST
equal `A` (§9.4, §10.1).

### A.2 Vector 1: resolution statement, `epochId` on the signing target

Payload #1 (display order; the wire payload MUST be this object serialized in
canonical form, §7.3):

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

JCS canonical string (signing target, one line):

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","descriptionLocation":"https://sensors.example/thermal/42/ar-xml","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-0","expiresAt":1770000000,"generation":7,"issuedAt":1769996400,"keyId":"ECMERdvpV0k","l2Version":"0.1","lifecycleStatus":"active","statementType":"resolution"}
```

Signing target as UTF-8 octets, hex (364 bytes; line breaks display-only):

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

Signature (Ed25519(private seed, signing target), base64url with '=' stripped, 86 chars):

```
z2u8xgPs0p-OJcBwycTyYmTTc23uwniF0JgzK02jEvqaoYWkNdpIfB6yQ5-LbZbjRHxfaZHpTlUFavYoO8nQBw
```

The wire statement object is `{ "payload": <canonical object above>, "signature":
<signature above> }` (§7.1). Verification against public key `A` MUST succeed;
verification against a signing target with any modified octet MUST fail. The statement
`epochId` is on the signing target (§7.2): an implementation that omits `epochId`
from the canonical string produces a different signing target and fails this vector.

### A.3 Vector 2: extension members

Payload #2 = payload #1 plus one extension member:

```json
"extExample": { "z": 1, "a": [2, 3] }
```

JCS canonical string (signing target, one line; `extExample` serializes after
`expiresAt` and before `generation`; inside the extension object `a` serializes
before `z`):

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","descriptionLocation":"https://sensors.example/thermal/42/ar-xml","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-0","expiresAt":1770000000,"extExample":{"a":[2,3],"z":1},"generation":7,"issuedAt":1769996400,"keyId":"ECMERdvpV0k","l2Version":"0.1","lifecycleStatus":"active","statementType":"resolution"}
```

Signing target as UTF-8 octets, hex (395 bytes; line breaks display-only):

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

Signature (86 chars):

```
HChT88MiaoViTfGVUMcfrPTikpbeMs8PYlBozWkBkTlJQWzhPWNTZ1B5MB7Q_pf2k7DqmGxiu-REmKhdKMgkBA
```

### A.4 Vector 3: non-BMP extension keys, UTF-16 code-unit order check

Payload #3 = payload #2 plus one extension member `extUnicode` with the member names
`a` (U+0061), `z` (U+007A), U+00E9, U+1F600 (UTF-16: D83D DE00), and U+E000. In the
display below the non-ASCII keys are written as JSON escapes; in the payload they are
literal characters:

```json
"extUnicode": { "a": 1, "z": 1, "\u00e9": 2, "\ud83d\ude00": 3, "\ue000": 4 }
```

UTF-16 code-unit order (RFC 8785 §3.2.3, the §7.3 rule) is `a` (0061) <
`z` (007A) < U+00E9 (00E9) < U+1F600 (D83D DE00) < U+E000 (E000). Scalar code-point
order would place U+E000 (57344) before U+1F600 (128512); any implementation that
sorts object members by scalar code point produces a different canonical string and
fails this vector.

JCS canonical string (signing target, one line; non-ASCII printable members are
emitted as-is, so the canonical string contains literal U+00E9, U+1F600, and U+E000.
U+E000 is a PUA code point: it may render as a box, a space, or nothing, depending on
the viewer):

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","descriptionLocation":"https://sensors.example/thermal/42/ar-xml","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-0","expiresAt":1770000000,"extExample":{"a":[2,3],"z":1},"extUnicode":{"a":1,"z":1,"é":2,"😀":3,"":4},"generation":7,"issuedAt":1769996400,"keyId":"ECMERdvpV0k","l2Version":"0.1","lifecycleStatus":"active","statementType":"resolution"}
```

Signing target as UTF-8 octets, hex (446 bytes; line breaks display-only; U+00E9 =
C3 A9, U+1F600 = F0 9F 98 80, U+E000 = EE 80 80):

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

Signature (86 chars):

```
nNEFPVF4vUZWiUU9eadPkEqQEb_pNBXR3yksE5IHTNrYASA8Fg4hubCdDr9PR4vPLxzaBRzeeYdYH-O4XArjCQ
```

Vector 3b (string escaping fixture, RFC 8785 §3.2.2.2). Payload #3b is a resolution
statement with `keyId = "ECMERdvpV0k"`, `epochId = "epoch-0"`, `generation = 2`,
`issuedAt = 1769997300`, `expiresAt = 1770000900`, plus one `note` member whose payload
value is the segments `lineA`, U+2028, `lineB`, U+2029, `del`, U+007F, `tab`, U+0009,
`end` in that order. Payload display:

```json
"note": "lineA<U+2028>lineB<U+2029>del<U+007F>tab<U+0009>end"
```

Per RFC 8785 §3.2.2.2, U+2028, U+2029, and U+007F are emitted as-is as raw code points
(MUST NOT be escaped as `\u2028`, `\u2029`, `\u007f`), and U+0009 is escaped as `\t`
(two octets `5C 74`). The canonical string below marks raw code-point octets with
`<U+XXXX>` placeholders:

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","descriptionLocation":"https://sensors.example/thermal/42/ar-xml","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-0","expiresAt":1770000900,"generation":2,"issuedAt":1769997300,"keyId":"ECMERdvpV0k","l2Version":"0.1","lifecycleStatus":"active","note":"lineA<U+2028>lineB<U+2029>del<U+007F>tab\tend","statementType":"resolution"}
```

Signing target as UTF-8 octets, hex (402 bytes; line breaks display-only; U+2028 =
E2 80 A8, U+2029 = E2 80 A9, U+007F = 7F; the `\t` escape is `5C 74`):

```
7b22616c676f726974686d223a2265643235353139222c22616e63686f724964
223a2235353065383430302d653239622d343164342d613731362d3434363635
35343430303030222c226465736372697074696f6e4c6f636174696f6e223a22
68747470733a2f2f73656e736f72732e6578616d706c652f746865726d616c2f
34322f61722d786d6c222c22656e746974794964223a2275726e3a72656c696e
6b3a656e746974793a746865726d616c2d73656e736f722d3432222c2265706f
63684964223a2265706f63682d30222c22657870697265734174223a31373730
3030303930302c2267656e65726174696f6e223a322c22697373756564417422
3a313736393939373330302c226b65794964223a2245434d455264767056306b
222c226c3256657273696f6e223a22302e31222c226c6966656379636c655374
61747573223a22616374697665222c226e6f7465223a226c696e6541e280a86c
696e6542e280a964656c7f7461625c74656e64222c2273746174656d656e7454
797065223a227265736f6c7574696f6e227d
```

Signature (86 chars):

```
o-as8zOSOzLKI-mgL6sKIKjW4Wy6PTbMZYGOBILgmUMGTcAYPPIJb95iUX8jy4z-B4zEM42VGp97ElTVgVQqAQ
```

An implementation that escapes U+2028, U+2029, or U+007F produces a different canonical
string, fails this vector, and MUST NOT be used as the JCS serializer (§7.3).

### A.5 Vector 4: Record chain, embedded transfer trail, payload-only prevRecordDigest

Seed strings, UTF-8: `relink-l2-0.1-draft-test-vector-seed-2` and
`relink-l2-0.1-draft-test-vector-seed-3`. Derive key2 and key3 with the A.1 derivation,
seed 2 for key2 and seed 3 for key3.

```
key2 private seed (hex) = 11a02c8e86da4620c1b75851b54cf11ed0bf920ed6a2f8bfdab8528b6661c3ea
key2 public key A (hex) = 92446f197e98d48765c07683e51c7261bdf01ed5d8b0d271a3ad40c184572563
key2 SHA-256(A) (hex)   = e0a29777b5d2256ffa591e993e6daa9f941fb1a45d32587e44603d5d0c7692a4
key2 keyId              = 4KKXd7XSJW8
key2 publicKey          = kkRvGX6Y1IdlwHaD5RxyYb3wHtXYsNJxo61AwYRXJWM

key3 private seed (hex) = d0510d88aca40d3264a3e849dd5a12e6fdf4ffe96c52c1d7c7c469f33b97b146
key3 public key A (hex) = 1b7ecbe59de3d86053280be9b424c1e674d48dba257febd06ac89207bca9bdcb
key3 SHA-256(A) (hex)   = 1bc55b5ee99ec15bd6ab107a8eea608e67ef8bba02e8b9537f20555b81fd1752
key3 keyId              = G8VbXumewVs
key3 publicKey          = G37L5Z3j2GBTKAvptCTB5nTUjbolf-vQasiSB7ypvcs
```

The Trust Anchor Binding (policy channel, §9.3) carries `keyId = "ECMERdvpV0k"`,
`publicKey = A` (A.1), `epochId = "epoch-0"`, `statementFloor = 0`. Records `0..3` form
the chain; each Record is an envelope `{ payload, signature }` (§7.1, §9.5). Payloads
below are display order; the wire payload MUST be the canonical serialization (§7.3).

Record 0 (`recordGeneration = 0`, self-signed, `prevRecordDigest` null):

```json
{
  "l2Version": "0.1",
  "statementType": "authority",
  "anchorId": "550e8400-e29b-41d4-a716-446655440000",
  "entityId": "urn:relink:entity:thermal-sensor-42",
  "recordGeneration": 0,
  "signerKeyId": "ECMERdvpV0k",
  "algorithm": "ed25519",
  "issuedAt": 1769996400,
  "expiresAt": 1770000000,
  "epochId": "epoch-0",
  "prevRecordDigest": null,
  "statementFloor": 0,
  "keys": [
    {
      "keyId": "ECMERdvpV0k",
      "publicKey": "s-3uYHWJqOApUHGAeMxzdQfh9Htni5M2NfZz7UWRn-I",
      "algorithm": "ed25519",
      "validFrom": 1769996400,
      "validUntil": 1770000000,
      "status": "current"
    }
  ]
}
```

JCS canonical string (signing target, one line):

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-0","expiresAt":1770000000,"issuedAt":1769996400,"keys":[{"algorithm":"ed25519","keyId":"ECMERdvpV0k","publicKey":"s-3uYHWJqOApUHGAeMxzdQfh9Htni5M2NfZz7UWRn-I","status":"current","validFrom":1769996400,"validUntil":1770000000}],"l2Version":"0.1","prevRecordDigest":null,"recordGeneration":0,"signerKeyId":"ECMERdvpV0k","statementFloor":0,"statementType":"authority"}
```

Signature (86 chars):

```
DObC78YFVR6d-TIuJKO_WbeXw8bmlE9uhd0e8UaQQWLEoLZedRgca8CJcVD3v1K2vbJ1JESUQzV6Uo1YvSnXAQ
```

Record 1 (rotation: signer key1, key1 retired, key2 introduced as current):

```json
{
  "l2Version": "0.1",
  "statementType": "authority",
  "anchorId": "550e8400-e29b-41d4-a716-446655440000",
  "entityId": "urn:relink:entity:thermal-sensor-42",
  "recordGeneration": 1,
  "signerKeyId": "ECMERdvpV0k",
  "algorithm": "ed25519",
  "issuedAt": 1769997000,
  "expiresAt": 1770000600,
  "epochId": "epoch-0",
  "prevRecordDigest": "36ce3c7bf819434ebc79e534649873081ee0999af85c798c6586235680809afb",
  "statementFloor": 0,
  "keys": [
    {
      "keyId": "ECMERdvpV0k",
      "publicKey": "s-3uYHWJqOApUHGAeMxzdQfh9Htni5M2NfZz7UWRn-I",
      "algorithm": "ed25519",
      "validFrom": 1769996400,
      "validUntil": 1769997000,
      "status": "retired"
    },
    {
      "keyId": "4KKXd7XSJW8",
      "publicKey": "kkRvGX6Y1IdlwHaD5RxyYb3wHtXYsNJxo61AwYRXJWM",
      "algorithm": "ed25519",
      "validFrom": 1769997000,
      "validUntil": 1770000600,
      "status": "current"
    }
  ]
}
```

`prevRecordDigest` is the lowercase hex SHA-256 of the UTF-8 octets of Record 0's
canonical payload string — payload only, signature and envelope excluded (§9.5).

JCS canonical string (signing target, one line):

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-0","expiresAt":1770000600,"issuedAt":1769997000,"keys":[{"algorithm":"ed25519","keyId":"ECMERdvpV0k","publicKey":"s-3uYHWJqOApUHGAeMxzdQfh9Htni5M2NfZz7UWRn-I","status":"retired","validFrom":1769996400,"validUntil":1769997000},{"algorithm":"ed25519","keyId":"4KKXd7XSJW8","publicKey":"kkRvGX6Y1IdlwHaD5RxyYb3wHtXYsNJxo61AwYRXJWM","status":"current","validFrom":1769997000,"validUntil":1770000600}],"l2Version":"0.1","prevRecordDigest":"36ce3c7bf819434ebc79e534649873081ee0999af85c798c6586235680809afb","recordGeneration":1,"signerKeyId":"ECMERdvpV0k","statementFloor":0,"statementType":"authority"}
```

Signature (86 chars):

```
uSDzPwzTq4xubwXA4NbkhTRiwaT1GfMM3bzdZ5Axoz0Rw3xcwTIFpnVB3Yv8y6yDYXixDC-1HMw1fTFM05HICw
```

Record 2 (`G = 2`, transfer-introducing; signer key2, introduced by Record 1; embedded
trail):

```json
{
  "l2Version": "0.1",
  "statementType": "authority",
  "anchorId": "550e8400-e29b-41d4-a716-446655440000",
  "entityId": "urn:relink:entity:thermal-sensor-42",
  "recordGeneration": 2,
  "signerKeyId": "4KKXd7XSJW8",
  "algorithm": "ed25519",
  "issuedAt": 1769997600,
  "expiresAt": 1770001200,
  "epochId": "epoch-1",
  "prevRecordDigest": "870b931dd8f9ad01a6a037524842549ad60fcde1066dbc9caf4f3e6140102f61",
  "statementFloor": 0,
  "keys": [
    {
      "keyId": "ECMERdvpV0k",
      "publicKey": "s-3uYHWJqOApUHGAeMxzdQfh9Htni5M2NfZz7UWRn-I",
      "algorithm": "ed25519",
      "validFrom": 1769996400,
      "validUntil": 1769997000,
      "status": "retired"
    },
    {
      "keyId": "4KKXd7XSJW8",
      "publicKey": "kkRvGX6Y1IdlwHaD5RxyYb3wHtXYsNJxo61AwYRXJWM",
      "algorithm": "ed25519",
      "validFrom": 1769997000,
      "validUntil": 1769997600,
      "status": "retired"
    },
    {
      "keyId": "G8VbXumewVs",
      "publicKey": "G37L5Z3j2GBTKAvptCTB5nTUjbolf-vQasiSB7ypvcs",
      "algorithm": "ed25519",
      "validFrom": 1769997600,
      "validUntil": 1770001200,
      "status": "current"
    }
  ],
  "transfer": {
    "payload": {
      "l2Version": "0.1",
      "statementType": "transfer",
      "anchorId": "550e8400-e29b-41d4-a716-446655440000",
      "entityId": "urn:relink:entity:thermal-sensor-42",
      "outgoingKeyId": "4KKXd7XSJW8",
      "incomingKeyId": "G8VbXumewVs",
      "algorithm": "ed25519",
      "epochId": "epoch-0",
      "newEpochId": "epoch-1",
      "recordGeneration": 2,
      "statementFloor": 0
    },
    "signature": "Ijetk5nXS-lFfjypHyQyi3QYKCXxBPSew2H6LB_qr0BYD7tw21X7MmZknNGl1K4jXpO_an7qQUoEqERJPf15BQ"
  }
}
```

Trail payload JCS canonical string (trail signing target, one line):

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-0","incomingKeyId":"G8VbXumewVs","l2Version":"0.1","newEpochId":"epoch-1","outgoingKeyId":"4KKXd7XSJW8","recordGeneration":2,"statementFloor":0,"statementType":"transfer"}
```

Trail signature, Ed25519 under key2 (86 chars):

```
Ijetk5nXS-lFfjypHyQyi3QYKCXxBPSew2H6LB_qr0BYD7tw21X7MmZknNGl1K4jXpO_an7qQUoEqERJPf15BQ
```

Record 2 canonical string — the trail canonical string appears verbatim as the
`transfer.payload` member, so Record 2's signature covers the trail bytes (one line):

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-1","expiresAt":1770001200,"issuedAt":1769997600,"keys":[{"algorithm":"ed25519","keyId":"ECMERdvpV0k","publicKey":"s-3uYHWJqOApUHGAeMxzdQfh9Htni5M2NfZz7UWRn-I","status":"retired","validFrom":1769996400,"validUntil":1769997000},{"algorithm":"ed25519","keyId":"4KKXd7XSJW8","publicKey":"kkRvGX6Y1IdlwHaD5RxyYb3wHtXYsNJxo61AwYRXJWM","status":"retired","validFrom":1769997000,"validUntil":1769997600},{"algorithm":"ed25519","keyId":"G8VbXumewVs","publicKey":"G37L5Z3j2GBTKAvptCTB5nTUjbolf-vQasiSB7ypvcs","status":"current","validFrom":1769997600,"validUntil":1770001200}],"l2Version":"0.1","prevRecordDigest":"870b931dd8f9ad01a6a037524842549ad60fcde1066dbc9caf4f3e6140102f61","recordGeneration":2,"signerKeyId":"4KKXd7XSJW8","statementFloor":0,"statementType":"authority","transfer":{"payload":{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-0","incomingKeyId":"G8VbXumewVs","l2Version":"0.1","newEpochId":"epoch-1","outgoingKeyId":"4KKXd7XSJW8","recordGeneration":2,"statementFloor":0,"statementType":"transfer"},"signature":"Ijetk5nXS-lFfjypHyQyi3QYKCXxBPSew2H6LB_qr0BYD7tw21X7MmZknNGl1K4jXpO_an7qQUoEqERJPf15BQ"}}
```

Record 2 signature, Ed25519 under key2 (86 chars):

```
198jI84AoMGGcA6AQvoqgF_OVrw5SU3i88-RKX1dZ8kYcCICdmf-UKst3vShf6hkkxd6d4Jv-_CEpWoN1tsFAw
```

`§12.2` matching rules for this trail, all confirmed: `outgoingKeyId` `4KKXd7XSJW8`
equals Record 2 `signerKeyId`; key2 is `current` in Record 1 with window
`1769997000..1770000600` containing Record 2 `issuedAt` `1769997600`; `incomingKeyId`
`G8VbXumewVs` equals the key Record 2 introduces as `current` with `validFrom` equal to
Record 2 `issuedAt`; trail `epochId` `epoch-0` equals Record 1 `epochId`; `newEpochId`
`epoch-1` equals Record 2 `epochId` and never appears in the chain history; trail
`recordGeneration` `2` and `statementFloor` `0` equal Record 2's; `l2Version`,
`anchorId`, `entityId`, `algorithm` equal Record 2's; the trail signature verifies under
key2 `publicKey`, carried in Record 1.

Record 3 (signer key3, the first Record signed by the incoming key, `G + 1 = 3`):

```json
{
  "l2Version": "0.1",
  "statementType": "authority",
  "anchorId": "550e8400-e29b-41d4-a716-446655440000",
  "entityId": "urn:relink:entity:thermal-sensor-42",
  "recordGeneration": 3,
  "signerKeyId": "G8VbXumewVs",
  "algorithm": "ed25519",
  "issuedAt": 1769998200,
  "expiresAt": 1770001800,
  "epochId": "epoch-1",
  "prevRecordDigest": "39808d467859fcad7589d70d54e0666038ac039d4956964ddda751f10f3e3234",
  "statementFloor": 0,
  "keys": [
    {
      "keyId": "ECMERdvpV0k",
      "publicKey": "s-3uYHWJqOApUHGAeMxzdQfh9Htni5M2NfZz7UWRn-I",
      "algorithm": "ed25519",
      "validFrom": 1769996400,
      "validUntil": 1769997000,
      "status": "retired"
    },
    {
      "keyId": "4KKXd7XSJW8",
      "publicKey": "kkRvGX6Y1IdlwHaD5RxyYb3wHtXYsNJxo61AwYRXJWM",
      "algorithm": "ed25519",
      "validFrom": 1769997000,
      "validUntil": 1769997600,
      "status": "retired"
    },
    {
      "keyId": "G8VbXumewVs",
      "publicKey": "G37L5Z3j2GBTKAvptCTB5nTUjbolf-vQasiSB7ypvcs",
      "algorithm": "ed25519",
      "validFrom": 1769997600,
      "validUntil": 1770001200,
      "status": "current"
    }
  ]
}
```

JCS canonical string (signing target, one line):

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-1","expiresAt":1770001800,"issuedAt":1769998200,"keys":[{"algorithm":"ed25519","keyId":"ECMERdvpV0k","publicKey":"s-3uYHWJqOApUHGAeMxzdQfh9Htni5M2NfZz7UWRn-I","status":"retired","validFrom":1769996400,"validUntil":1769997000},{"algorithm":"ed25519","keyId":"4KKXd7XSJW8","publicKey":"kkRvGX6Y1IdlwHaD5RxyYb3wHtXYsNJxo61AwYRXJWM","status":"retired","validFrom":1769997000,"validUntil":1769997600},{"algorithm":"ed25519","keyId":"G8VbXumewVs","publicKey":"G37L5Z3j2GBTKAvptCTB5nTUjbolf-vQasiSB7ypvcs","status":"current","validFrom":1769997600,"validUntil":1770001200}],"l2Version":"0.1","prevRecordDigest":"39808d467859fcad7589d70d54e0666038ac039d4956964ddda751f10f3e3234","recordGeneration":3,"signerKeyId":"G8VbXumewVs","statementFloor":0,"statementType":"authority"}
```

Record 3 signature, Ed25519 under key3 (86 chars):

```
zvbOz9_FWQoqCq9yQ46VGFGF7JsxZr2SOf7Y9nYJbdkXhtQ90WWHBgycvnbN7VqjA7cqxNT8164yyMKk5lYvAw
```

Post-transfer resolution statement (signer key3, `epoch-1`, `generation = 1`):

```json
{
  "l2Version": "0.1",
  "statementType": "resolution",
  "anchorId": "550e8400-e29b-41d4-a716-446655440000",
  "entityId": "urn:relink:entity:thermal-sensor-42",
  "descriptionLocation": "https://sensors.example/thermal/42/ar-xml",
  "lifecycleStatus": "active",
  "keyId": "G8VbXumewVs",
  "algorithm": "ed25519",
  "issuedAt": 1769998300,
  "expiresAt": 1770001200,
  "generation": 1,
  "epochId": "epoch-1"
}
```

JCS canonical string (signing target, one line):

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","descriptionLocation":"https://sensors.example/thermal/42/ar-xml","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-1","expiresAt":1770001200,"generation":1,"issuedAt":1769998300,"keyId":"G8VbXumewVs","l2Version":"0.1","lifecycleStatus":"active","statementType":"resolution"}
```

Signature, Ed25519 under key3 (86 chars):

```
ahcug-ygTSwa7kit4p4ZtQ-8NInObst_l_FTzZKaL4bd2dV0lCShbVrKDbg-HRgQuKf485lOVSDNrFrrv0CGDQ
```

Expected verifier result at `T = 1769998400` with `W = 600` (§11.4): a verifier holding
only the Trust Anchor Binding accepts Record 0 (self-signed, generation 0, floor 0,
`prevRecordDigest` null), Record 1 (signer key1, `current` in the Record 0 snapshot,
digest matches), Record 2 (signer key2, introduced by Record 1; embedded trail verified;
atomic epoch switch to `epoch-1`), Record 3 (signer key3, introduced by Record 2, window
`1769997600..1770001200` containing `issuedAt` `1769998200`). The revocation snapshot is
Record 3: `issuedAt 1769998200 <= 1769998400 <= expiresAt 1770001800` and
`1769998400 - 1769998200 = 200 <= W = 600` (§11.4), and the snapshot covers key3, the
accepted epoch `epoch-1`, and the chain. The statement
verifies under key3 (`keyId` recomputed from wire `publicKey`), `epochId` matches the
accepted epoch, `generation 1` is at the floor, `issuedAt <= T <= expiresAt`:
`VERIFIED`.

Expected negative results, split by failing phase (§15.2): a missing, tampered,
duplicated, or mismatched trail in Record 2 is rejected by chain walk at trail
verification, part of key-authority acceptance, phase 5 (§9.6, §12.2); the chain stops
at Record 1, and the statement is `UNVERIFIED` at phase 6 — no fresh snapshot covers
key3, `epoch-1`, and the chain. A Record whose wire JSON contains duplicate member
names, including inside the `transfer` payload, is `INVALID` at phase 1 (wire parse,
§7.2), never `UNVERIFIED`; a statement dependent on that rejected Record fails at
phase 5 (signer absent from the accepted chain, `UNKNOWN_AUTHORITY`). A statement
signed by key3 carrying `epoch-0` is `STALE` at phase 10, never at phase 6.
Re-fetching, retransmitting, re-presenting, and revalidating Records 0..2 at `T` never
refreshes the snapshot: Record 3 is the only freshness point (§9.7, §11.4).

Round-4 chain extensions (Records 4, 5, 6, 4F, 5V; statements S5, S6). Seed strings,
UTF-8: `relink-l2-0.1-draft-test-vector-seed-4` and
`relink-l2-0.1-draft-test-vector-seed-5`. Derive key4 (successor authority key) and key5
(transfer target key) with the A.1 derivation, seed 4 for key4 and seed 5 for key5.

```
key4 private seed (hex) = c7d71bdecffdd92a2bb9ec96f98a18b13c97baa94b922f2686e87d3461a94a59
key4 public key A (hex) = 5c96c39ec91e5f00b5ca83df0675a641979f29129092b4772adc1892923a79d0
key4 SHA-256(A) (hex)   = 102621ef2245027f4eca01ef407507e2f22677a02f5419ee11f612f3e163b0b6
key4 keyId              = ECYh7yJFAn8
key4 publicKey          = XJbDnskeXwC1yoPfBnWmQZefKRKQkrR3KtwYkpI6edA

key5 private seed (hex) = 02ce7c04513bc5674e89ccbe8199c561014cec3192421b9efb4abaf3e91697de
key5 public key A (hex) = 8d1086ebb8e175ca50ffac7157e371530eee3c200637bf97bb04d846d80c7b61
key5 SHA-256(A) (hex)   = 7086d4a9a576ab126718797fc1dc378dc2a25590abdf5c6d392ad46cf05620fc
key5 keyId              = cIbUqaV2qxI
key5 publicKey          = jRCG67jhdcpQ_6xxV-NxUw7uPCAGN7-XuwTYRtgMe2E
```

For Records 4..6, 4F, 5V and statements S5, S6, the canonical string is the payload
that MUST appear verbatim on the wire (§7.3), so only canonical strings and signatures
are listed.

Record 4 (`recordGeneration = 4`, `epoch-1`, signer key3; key4 introduced as `current`
with `validFrom` = Record 4 `issuedAt` 1770000900; `prevRecordDigest` is the SHA-256 of
Record 3's canonical payload string):

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-1","expiresAt":1770002400,"issuedAt":1770000900,"keys":[{"algorithm":"ed25519","keyId":"ECMERdvpV0k","publicKey":"s-3uYHWJqOApUHGAeMxzdQfh9Htni5M2NfZz7UWRn-I","status":"retired","validFrom":1769996400,"validUntil":1769997000},{"algorithm":"ed25519","keyId":"4KKXd7XSJW8","publicKey":"kkRvGX6Y1IdlwHaD5RxyYb3wHtXYsNJxo61AwYRXJWM","status":"retired","validFrom":1769997000,"validUntil":1769997600},{"algorithm":"ed25519","keyId":"G8VbXumewVs","publicKey":"G37L5Z3j2GBTKAvptCTB5nTUjbolf-vQasiSB7ypvcs","status":"current","validFrom":1769997600,"validUntil":1770002400},{"algorithm":"ed25519","keyId":"ECYh7yJFAn8","publicKey":"XJbDnskeXwC1yoPfBnWmQZefKRKQkrR3KtwYkpI6edA","status":"current","validFrom":1770000900,"validUntil":1770002400}],"l2Version":"0.1","prevRecordDigest":"72d46a3ca11dfdd8c275c4702f23c1415ff5b24ed6cabe88eea28b40ee895d03","recordGeneration":4,"signerKeyId":"G8VbXumewVs","statementFloor":0,"statementType":"authority"}
```

Signature (86 chars):

```
W5El3bBE8R0AdqGAKN3Owy5StGHyAFV0AAuOKBORsI5hf5fO-s0Da-FhyCKn0C4_qD0Nsyn43uXEM6SCWxNkCg
```

Expected: Record 4 accepted. Signer key3 is `current` in Record 3, the G-1 snapshot,
with window `1769997600..1770001200` containing Record 4 `issuedAt` `1770000900`
(§9.6, §12.2). Chain accepted maximum becomes 4; accepted epoch `epoch-1`; floor 0.

Record 5 (`G = 5`, `epoch-2`, signer key4, `issuedAt` 1770001500; embedded trail
key4 → key5). Trail canonical string and trail signature:

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-1","incomingKeyId":"cIbUqaV2qxI","l2Version":"0.1","newEpochId":"epoch-2","outgoingKeyId":"ECYh7yJFAn8","recordGeneration":5,"statementFloor":0,"statementType":"transfer"}
```

```
BLyaj6zMmZLoO4C0MXTjj1Hc42GGrlys9UbNotBAvLDRVwpBBODBffweqLzfx8OxLr6pquhZAippy1lcfY48Cw
```

Record 5 canonical string (the trail canonical string appears verbatim as
`transfer.payload`):

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-2","expiresAt":1770002400,"issuedAt":1770001500,"keys":[{"algorithm":"ed25519","keyId":"ECMERdvpV0k","publicKey":"s-3uYHWJqOApUHGAeMxzdQfh9Htni5M2NfZz7UWRn-I","status":"retired","validFrom":1769996400,"validUntil":1769997000},{"algorithm":"ed25519","keyId":"4KKXd7XSJW8","publicKey":"kkRvGX6Y1IdlwHaD5RxyYb3wHtXYsNJxo61AwYRXJWM","status":"retired","validFrom":1769997000,"validUntil":1769997600},{"algorithm":"ed25519","keyId":"G8VbXumewVs","publicKey":"G37L5Z3j2GBTKAvptCTB5nTUjbolf-vQasiSB7ypvcs","status":"current","validFrom":1769997600,"validUntil":1770002400},{"algorithm":"ed25519","keyId":"ECYh7yJFAn8","publicKey":"XJbDnskeXwC1yoPfBnWmQZefKRKQkrR3KtwYkpI6edA","status":"retired","validFrom":1770000900,"validUntil":1770001500},{"algorithm":"ed25519","keyId":"cIbUqaV2qxI","publicKey":"jRCG67jhdcpQ_6xxV-NxUw7uPCAGN7-XuwTYRtgMe2E","status":"current","validFrom":1770001500,"validUntil":1770002400}],"l2Version":"0.1","prevRecordDigest":"cf347228c0a32f5b47f10bff1ef86ca3e20b34172dceda39b706280db0a0665d","recordGeneration":5,"signerKeyId":"ECYh7yJFAn8","statementFloor":0,"statementType":"authority","transfer":{"payload":{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-1","incomingKeyId":"cIbUqaV2qxI","l2Version":"0.1","newEpochId":"epoch-2","outgoingKeyId":"ECYh7yJFAn8","recordGeneration":5,"statementFloor":0,"statementType":"transfer"},"signature":"BLyaj6zMmZLoO4C0MXTjj1Hc42GGrlys9UbNotBAvLDRVwpBBODBffweqLzfx8OxLr6pquhZAippy1lcfY48Cw"}}
```

Signature (86 chars):

```
ev9uPwTgn68dXyYmSfUOjY--ogMPMnz2wkrCMN2V6mfdhvDpJY3CI4WSIcjpN9-TCzfmNGQtRjYiZoZixCzEAQ
```

Expected: Record 5 rejected at chain walk, trail verification, phase 5 (§9.6, §12.2).
Under §12.2, Record 5 MUST retire every key that is `current` in Record 4, the G-1
snapshot — key3 and key4 — with `validUntil` = Record 5 `issuedAt` `1770001500`, and the
trail-approved incoming key key5 MUST be the sole `current` key in Record 5. Record 5
carries key3, an epoch-1 key that was `current` in Record 4, with `status` `current`:
key3 remains `current` in the old epoch in Record 5, and the trail-approved incoming key
key5 is not the sole `current` key in Record 5 — trail matching failure, Record rejected,
chain stops at Record 4. The signature verifies cryptographically under key4 `publicKey`;
the matching-rule failure, not the signature, is the rejection. Records introducing
`epoch-2` signed under key4's authority MUST NOT be accepted.

Record 6 (`G = 6`, `epoch-3`, signer key4, `issuedAt` 1770001800; embedded trail
`epoch-2` → `epoch-3`). Trail canonical string and trail signature:

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-2","incomingKeyId":"cIbUqaV2qxI","l2Version":"0.1","newEpochId":"epoch-3","outgoingKeyId":"ECYh7yJFAn8","recordGeneration":6,"statementFloor":0,"statementType":"transfer"}
```

```
sJId5k5StJcUDoT0A4It8BTYEwjhkOhtHAsQxU65TBGXEIQkaftXPgyx4v1j0YNEoQEGAqxqNP2HKrZys1DRBg
```

Record 6 canonical string:

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-3","expiresAt":1770002400,"issuedAt":1770001800,"keys":[{"algorithm":"ed25519","keyId":"ECMERdvpV0k","publicKey":"s-3uYHWJqOApUHGAeMxzdQfh9Htni5M2NfZz7UWRn-I","status":"retired","validFrom":1769996400,"validUntil":1769997000},{"algorithm":"ed25519","keyId":"4KKXd7XSJW8","publicKey":"kkRvGX6Y1IdlwHaD5RxyYb3wHtXYsNJxo61AwYRXJWM","status":"retired","validFrom":1769997000,"validUntil":1769997600},{"algorithm":"ed25519","keyId":"G8VbXumewVs","publicKey":"G37L5Z3j2GBTKAvptCTB5nTUjbolf-vQasiSB7ypvcs","status":"retired","validFrom":1769997600,"validUntil":1770001500},{"algorithm":"ed25519","keyId":"ECYh7yJFAn8","publicKey":"XJbDnskeXwC1yoPfBnWmQZefKRKQkrR3KtwYkpI6edA","status":"retired","validFrom":1770000900,"validUntil":1770001800},{"algorithm":"ed25519","keyId":"cIbUqaV2qxI","publicKey":"jRCG67jhdcpQ_6xxV-NxUw7uPCAGN7-XuwTYRtgMe2E","status":"current","validFrom":1770001800,"validUntil":1770002400}],"l2Version":"0.1","prevRecordDigest":"66ea457884fe3bc5f9555523b4728d270524eda80b8ccda330a596a61916a750","recordGeneration":6,"signerKeyId":"ECYh7yJFAn8","statementFloor":0,"statementType":"authority","transfer":{"payload":{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-2","incomingKeyId":"cIbUqaV2qxI","l2Version":"0.1","newEpochId":"epoch-3","outgoingKeyId":"ECYh7yJFAn8","recordGeneration":6,"statementFloor":0,"statementType":"transfer"},"signature":"sJId5k5StJcUDoT0A4It8BTYEwjhkOhtHAsQxU65TBGXEIQkaftXPgyx4v1j0YNEoQEGAqxqNP2HKrZys1DRBg"}}
```

Signature (86 chars):

```
V62IKH72c7W924Z3gBE3bxwf6lWIJvsoKxFZilZyD4k1iVvpI33xUOy3ddIl54wK545YRpVOZv9f4zoyfSfCCw
```

Expected: Record 6 rejected at chain walk. Record 5 was rejected, so Record 6's
`prevRecordDigest` references a Record that was never accepted, contiguity fails, and
`epoch-3` is introduced only by a rejected Record. Chain accepted maximum stays 4;
`epoch-2` and `epoch-3` are never accepted epochs.

Statement S6 (key4, `epoch-2`, `issuedAt` 1770001100, `expiresAt` 1770002400), verified
in scenario A (chain 0..4) at `T6 = 1770001200` with `W = 600`:

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","descriptionLocation":"https://sensors.example/thermal/42/ar-xml","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-2","expiresAt":1770002400,"generation":1,"issuedAt":1770001100,"keyId":"ECYh7yJFAn8","l2Version":"0.1","lifecycleStatus":"active","statementType":"resolution"}
```

Signature (86 chars):

```
DQLGea9EXGOAXoXK4Un1a0F1sZXxsUGF6NDD965OSm0rxSsCleLR5PUSJkh5FtTck3U1LWbyT01s5XXgW4Z5DQ
```

Expected at `T6`: chain walk accepts Records 0..4. The revocation snapshot is Record 4,
fresh at `T6`: `issuedAt 1770000900 <= 1770001200 <= expiresAt 1770002400` and
`1770001200 - 1770000900 = 300 <= 600` (§11.4), covering key4, `epoch-1`, and the chain.
The signature verifies under key4 `publicKey`, `current` in the snapshot. `epochId`
`epoch-2` does not equal the current accepted epoch `epoch-1`, and `epoch-2` is
introduced only by rejected Record 5: phase 9 is skipped, phase 10 yields `STALE` —
never `VERIFIED`, never `UNVERIFIED`, never `REVOKED` (§15.2, §13.4).

Statement S5 (key2, `epoch-0`, `issuedAt` 1769997200, `expiresAt` 1770001000), verified
in scenario B (chain 0..3, 4F) at `T = 1769998400` with `W = 600`:

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","descriptionLocation":"https://sensors.example/thermal/42/ar-xml","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-0","expiresAt":1770001000,"generation":1,"issuedAt":1769997200,"keyId":"4KKXd7XSJW8","l2Version":"0.1","lifecycleStatus":"active","statementType":"resolution"}
```

Signature (86 chars):

```
Dr5OL9j6Er18zcYAAghaygB1EQQAPEgEQXeck_wGAIItUzTDgtgORJNjzf8usYS0ibaEjmhmJUapRPP9blJUCA
```

Expected at `T = 1769998400`: chain walk accepts Records 0..3. The revocation snapshot
is Record 3, fresh at `T`; S5 `issuedAt` `1769997200 <= T <= expiresAt` `1770001000` is
valid. S5 `epochId` `epoch-0` does not equal the current accepted epoch `epoch-1`, so
the phase 9 key-status judgment is skipped — key2 is `retired` in the snapshot and key
status is not evaluated — and phase 10 yields `STALE` for the prior epoch (§15.2, §13.4,
§13.1). S5 MUST NOT be `VERIFIED`, `REVOKED`, `UNVERIFIED`, or `CONFLICT`.

Record 4F (`recordGeneration = 4`, `epoch-1`, signer key3, `issuedAt` 1769999000,
`expiresAt` 1770002000; keys array as Record 3), scenario B chain at `T = 1769998400`,
`W = 600`:

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-1","expiresAt":1770002000,"issuedAt":1769999000,"keys":[{"algorithm":"ed25519","keyId":"ECMERdvpV0k","publicKey":"s-3uYHWJqOApUHGAeMxzdQfh9Htni5M2NfZz7UWRn-I","status":"retired","validFrom":1769996400,"validUntil":1769997000},{"algorithm":"ed25519","keyId":"4KKXd7XSJW8","publicKey":"kkRvGX6Y1IdlwHaD5RxyYb3wHtXYsNJxo61AwYRXJWM","status":"retired","validFrom":1769997000,"validUntil":1769997600},{"algorithm":"ed25519","keyId":"G8VbXumewVs","publicKey":"G37L5Z3j2GBTKAvptCTB5nTUjbolf-vQasiSB7ypvcs","status":"current","validFrom":1769997600,"validUntil":1770001200}],"l2Version":"0.1","prevRecordDigest":"72d46a3ca11dfdd8c275c4702f23c1415ff5b24ed6cabe88eea28b40ee895d03","recordGeneration":4,"signerKeyId":"G8VbXumewVs","statementFloor":0,"statementType":"authority"}
```

Signature (86 chars):

```
ZN-7Z-5SPb8La6n6P6n-KjHUsQpEp-q5YXBzbb-K0Iu1LhsUxEBE_CwUTLv0y1Qme2ESD4O-UnKf8PaIcW2KBQ
```

Expected: Record 4F is accepted by chain walk (signer key3 `current` in Record 3,
window `1769997600..1770001200` contains `issuedAt` `1769999000`, digest matches Record
3, `issuedAt` non-decreasing from Record 3). At `T = 1769998400` the effective prefix is
`0..3` — Record 4F has `issuedAt > T` and is outside the prefix: the snapshot is Record
3, fresh at `T`, and the post-transfer statement under key3 is `VERIFIED` via snapshot
Record 3. Record 4F's keys array, floor, and epoch data MUST NOT contribute revocation,
epoch, floor, or key evidence at `T` (§11.4, §9.7, §15.2 phase 6). At `T' = 1770000000`
the effective prefix is `0..4` and the effective-latest Record 4F fails the freshness
check: `T' - 1769999000` exceeds `W = 600`; Record 3 is also not fresh at `T'`
(`T' - 1769998200` exceeds `W`), and back-off from Record 4F is in any case prohibited
(§11.4): no fresh snapshot, `UNVERIFIED`. Shifting `T`, rewriting `issuedAt`, backing
off to an older Record of the prefix, or selecting an out-of-window Record to
manufacture freshness MUST NOT yield `VERIFIED` (§11.4).

Record 4E (`recordGeneration = 4`, `epoch-1`, signer key3, `issuedAt` 1769998250,
`expiresAt` 1769998300; keys array as Record 3, `issuedAt` non-decreasing from Record
3), scenario D, chain Records `0..3, 4E`, `T = 1769998700`, `W = 600`:

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-1","expiresAt":1769998300,"issuedAt":1769998250,"keys":[{"algorithm":"ed25519","keyId":"ECMERdvpV0k","publicKey":"s-3uYHWJqOApUHGAeMxzdQfh9Htni5M2NfZz7UWRn-I","status":"retired","validFrom":1769996400,"validUntil":1769997000},{"algorithm":"ed25519","keyId":"4KKXd7XSJW8","publicKey":"kkRvGX6Y1IdlwHaD5RxyYb3wHtXYsNJxo61AwYRXJWM","status":"retired","validFrom":1769997000,"validUntil":1769997600},{"algorithm":"ed25519","keyId":"G8VbXumewVs","publicKey":"G37L5Z3j2GBTKAvptCTB5nTUjbolf-vQasiSB7ypvcs","status":"current","validFrom":1769997600,"validUntil":1770001200}],"l2Version":"0.1","prevRecordDigest":"72d46a3ca11dfdd8c275c4702f23c1415ff5b24ed6cabe88eea28b40ee895d03","recordGeneration":4,"signerKeyId":"G8VbXumewVs","statementFloor":0,"statementType":"authority"}
```

Signature (86 chars):

```
iHQ8u7fdzazGOmgd7_k8C5NzOknLDjGu0sQxKggCxV3ldOYEGTnve0NjB4G8LHSNEvfx4XtDOeDk0kkGCZQgCA
```

Expected: Record 4E is accepted by chain walk (signer key3 `current` in Record 3,
window `1769997600..1770001200` contains `issuedAt` `1769998250`, digest matches Record
3, `issuedAt` non-decreasing from Record 3). At `T = 1769998700` the effective prefix is
`0..4` and the effective-latest Record is Record 4E, whose `expiresAt` `1769998300` is
below `T`: Record 4E is not fresh at `T` and the snapshot is not fresh. The
post-transfer statement S7 — the scenario A statement, byte-identical, `issuedAt`
`1769998300`, `expiresAt` `1770001200`, fresh at `T` (`T - issuedAt = 400 <= W`) — is
`UNVERIFIED` at phase 6, never `VERIFIED` (§11.4, §15.2 phase 6). Record 3 would be
fresh at `T` (`T - 1769998200 = 500 <= W`, `T <= expiresAt` `1770001800`): backing off
to Record 3 is prohibited, an older in-window Record MUST NOT be selected when the
effective-latest Record `k` of the effective prefix is not fresh at `T` (§11.4).
Shifting `T` back below `1769998250` yields the effective prefix `0..3` with snapshot
Record 3, fresh at that `T`: `VERIFIED` holds only under that shifted evaluation time,
never at `T = 1769998700`.

Record 5V (`G = 5`, `epoch-2`, signer key4, `issuedAt` 1770002700, `expiresAt`
1770003900; embedded trail key4 → key5, byte-identical to the Record 5 trail), scenario
C chain (0..3, 4) at any `T` inside `epoch-1` freshness:

```
{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-2","expiresAt":1770003900,"issuedAt":1770002700,"keys":[{"algorithm":"ed25519","keyId":"ECMERdvpV0k","publicKey":"s-3uYHWJqOApUHGAeMxzdQfh9Htni5M2NfZz7UWRn-I","status":"retired","validFrom":1769996400,"validUntil":1769997000},{"algorithm":"ed25519","keyId":"4KKXd7XSJW8","publicKey":"kkRvGX6Y1IdlwHaD5RxyYb3wHtXYsNJxo61AwYRXJWM","status":"retired","validFrom":1769997000,"validUntil":1769997600},{"algorithm":"ed25519","keyId":"G8VbXumewVs","publicKey":"G37L5Z3j2GBTKAvptCTB5nTUjbolf-vQasiSB7ypvcs","status":"retired","validFrom":1769997600,"validUntil":1770002400},{"algorithm":"ed25519","keyId":"ECYh7yJFAn8","publicKey":"XJbDnskeXwC1yoPfBnWmQZefKRKQkrR3KtwYkpI6edA","status":"retired","validFrom":1770000900,"validUntil":1770002700},{"algorithm":"ed25519","keyId":"cIbUqaV2qxI","publicKey":"jRCG67jhdcpQ_6xxV-NxUw7uPCAGN7-XuwTYRtgMe2E","status":"current","validFrom":1770002700,"validUntil":1770003900}],"l2Version":"0.1","prevRecordDigest":"cf347228c0a32f5b47f10bff1ef86ca3e20b34172dceda39b706280db0a0665d","recordGeneration":5,"signerKeyId":"ECYh7yJFAn8","statementFloor":0,"statementType":"authority","transfer":{"payload":{"algorithm":"ed25519","anchorId":"550e8400-e29b-41d4-a716-446655440000","entityId":"urn:relink:entity:thermal-sensor-42","epochId":"epoch-1","incomingKeyId":"cIbUqaV2qxI","l2Version":"0.1","newEpochId":"epoch-2","outgoingKeyId":"ECYh7yJFAn8","recordGeneration":5,"statementFloor":0,"statementType":"transfer"},"signature":"BLyaj6zMmZLoO4C0MXTjj1Hc42GGrlys9UbNotBAvLDRVwpBBODBffweqLzfx8OxLr6pquhZAippy1lcfY48Cw"}}
```

Signature (86 chars):

```
I924C6doUis7-AlOd1gpHJ-bV9tc-XA2cS1INMOcqH5Jw_Sj0uzdG_RFxf__YkwySCz-VdrX_aWEOdJ2vLqPAg
```

Expected: Record 5V rejected at chain walk, phase 5 (§9.6, §12.2). The signature octets
verify cryptographically under key4 `publicKey`; the window rule, not the signature,
rejects it. Signer key4 is `current` in Record 4, the G-1 snapshot, with window
`1770000900..1770002400`, which excludes Record 5V `issuedAt` `1770002700`: the Record
signature window check fails, and the trail window check
(`validFrom <= issuedAt(G) <= validUntil` against the outgoing key's window in Record 4,
G-1) fails at the same boundary. Chain accepted maximum stays 4. This vector shows
cryptographic validity is not key validity (§9.6, §12.2).

### A.6 Reproduction steps

1. Compute the private seed per A.1, derive the public key `A`, recompute `keyId` per
   §10.1, and check the A.1 values.
2. Serialize each payload with JCS: object member names sorted in UTF-16 code-unit
   order (RFC 8785 §3.2.3), minimal escaping per RFC 8785 §3.2.2.2 — `"` escaped as
   `\"` and `\` as `\\`; U+0008, U+0009, U+000A, U+000C, U+000D as the short escapes
   `\b`, `\t`, `\n`, `\f`, `\r`; remaining C0 control characters as lowercase
   `\uXXXX`; U+2028, U+2029, and DEL (U+007F) emitted as-is, never escaped; printable
   non-ASCII emitted as-is — integers as plain decimal, no whitespace. The result
   MUST equal the canonical strings above byte-for-byte, including the literal
   U+2028, U+2029, and U+007F octets of Vector 3b.
3. Sign the canonical string's UTF-8 octets with deterministic Ed25519 (RFC 8032
   §5.2); the base64url signature ('=' stripped) MUST equal the signature above.
4. Verify against `A`; a tampered signing target MUST fail. Vector 3 MUST verify with
   the U+1F600 member serialized before the U+E000 member; scalar code-point sorting
   MUST NOT reproduce the canonical bytes. Vector 3b MUST verify with the U+2028,
   U+2029, and U+007F octets emitted as-is inside the `note` string; an serializer
   that escapes U+2028, U+2029, or U+007F MUST NOT reproduce the canonical bytes.
5. Derive key2, key3, key4, and key5 from seed strings 2, 3, 4, and 5 per A.1,
   recompute their `keyId`s from the `publicKey` bytes, and check the A.5 key values.
6. For the chain vector, compute each `prevRecordDigest` as the lowercase hex SHA-256
   of the UTF-8 octets of the previous Record's canonical payload string — payload
   only, signature and envelope excluded — and check Record 0's null, the six digest
   values, and Record 1..4, 4E, 4F, and 5V signatures under the signer keys carried by
   the preceding accepted Records. Record 5V's signature octets verify
   cryptographically under key4 `publicKey` while its `issuedAt` lies outside key4's
   validity window in Record 4, which MUST reject the Record (§9.6, §12.2).
7. Verify the trail signature under key2 `publicKey`, confirm every §12.2 matching
   rule against Record 2, and confirm the trail canonical string appears verbatim as
   `transfer.payload` inside Record 2's canonical string. Verify the post-transfer
   statement under key3 at `T = 1769998400` with `W = 600`, and confirm the expected
   positive and negative results in A.5, including the Record 4 acceptance, the
   Record 5 trail-matching rejection, the Record 6 epoch-contiguity rejection, the
   Record 4F snapshot outcome at `T = 1769998400`, statement S5 at `T = 1769998400`,
   statement S6 at `T6 = 1770001200`, the Record 4E acceptance by chain walk, and the
   statement S7 snapshot outcome at `T = 1769998700` with `W = 600`: `UNVERIFIED` at
   phase 6, with Record 3 fresh at `T` (`T - issuedAt = 500 <= W`) and back-off from
   the expired effective-latest Record 4E to Record 3 prohibited (§11.4).
