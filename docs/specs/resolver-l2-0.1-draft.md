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

The `transfer` payload (§12.2) and the Authority Record payload (§9.5) add
`recordGeneration`, `epochId` / `newEpochId`, and `statementFloor` as defined there.
Statement `generation` sequences and Record `recordGeneration` sequences are separate
sequences and MUST NOT be compared with each other. All canonicalization and signing rules
of §7.3 apply to every statement type, including Authority Records and transfer statements.

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
`effectiveGeneration`, `revokedFrom`) MUST be non-negative integers in JCS canonical form,
within the IEEE-754 double safe-integer range, `0` to `2^53 - 1`. A fractional,
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
prevRecordDigest   = lowercase hex SHA-256 of the canonical wire bytes of Record G-1
                     (§7.3 target); null for Record 0
statementFloor     = statement generation floor established by this epoch; required on
                     epoch-establishing Records (baseline 0)
keys               = array of key entries
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
revokedFrom        = generation (required for status "revoked" and "compromised")
```

Record payloads canonicalize and sign per §7.3, including wire canonicality. Records are
served on the authority endpoint (§6.3) and MAY be distributed via the policy channel
(§9.2). The key set in Record G is the complete, current snapshot of accepted keys at
`recordGeneration = G`; every key that may sign any Record `G+1..` MUST be present in
Record `G` with its `publicKey` bytes, so a verifier holding only Record `G` can verify
Record `G+1`'s signature from wire material alone. `prevRecordDigest` binds Record `G` to
Record `G-1` byte-for-byte; the chain walk verifies this binding at every step. Revocation,
retirement, compromise, and rotation status changes are expressed by successive Records.

### 9.6 Chain acceptance

Verifiers MUST accept Records only by chain walk from the Trust Anchor Binding (§9.3):

1. Record `recordGeneration = 0` MUST match the Trust Anchor Binding (`anchorId`, initial
   `keyId`, initial public key bytes, initial `epochId`, initial `statementFloor`, initial
   epoch start) and MUST verify under the binding's initial public key material;
2. Record `recordGeneration = G > 0` MUST be accepted only when: Records `0..G-1` are
   accepted and Record G is the next contiguous generation; Record G's `prevRecordDigest`
   equals the SHA-256 of Record `G-1`'s canonical wire bytes (§9.5); Record G verifies
   under its `signerKeyId`, using that key's `publicKey` bytes present in Record `G-1`;
   `signerKeyId` has `status = "current"` in Record `G-1` with
   `validFrom <= issuedAt(G) <= validUntil`; and every key introduced by Record G is
   authorized by rotation (signed by a key `current` in Record `G-1`, §10.3) or by a
   verified transfer (§12) in force at `recordGeneration >= effectiveGeneration`;
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

### 9.7 Authority-material anti-rollback and equivocation

- the verifier MUST track the highest accepted `recordGeneration` per Anchor;
- Records below the accepted maximum MUST NOT be accepted (authority rollback attempt;
  `STALE` per §15.2);
- a Record re-presented at the accepted maximum MUST be byte-identical, compared after
  §7.3 canonicalization, to the accepted Record; a different payload at the same
  `recordGeneration` MUST produce `CONFLICT` (equivocation, §17);
- the verifier MUST NOT accept two Records with the same `recordGeneration` and different
  payloads, and MUST NOT pick one.

### 9.8 State loss

After verifier state loss (accepted Records, chain position, generation maxima), the
verifier MUST re-establish authority material by walking the chain from its Trust Anchor
Binding via the policy channel (§9.2). Authority material received on first contact
without chain provenance MUST NOT be trusted. A verifier without a trusted lower bound
MUST NOT claim `VERIFIED` from first-contact material, because old data inside a validity
window cannot be identified as old.

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
accepted as current: `STALE` (cross-epoch replay). Within the current epoch, a statement
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

`VERIFIED` at time `T` is a point-in-time judgment over the verifier's revocation snapshot:
the highest accepted Record, verified by chain walk (§9.6), whose key entries carry
`status` and `revokedFrom`. At evaluation time `T`, the snapshot MUST be the highest
accepted Record, MUST have been accepted within the deployment's documented, bounded
freshness upper bound, MUST cover the statement's signing key, the statement's `epochId`,
and the accepted chain, and MUST be fresh at `T`. A snapshot older than the freshness
upper bound, a missing snapshot, an incomplete chain, and a coverage gap MUST produce
`UNVERIFIED`, never `VERIFIED`.

`VERIFIED` at `T` asserts nothing about revocation after `T`: revocation that occurs later
is not continuously guaranteed, `VERIFIED` MUST NOT be presented as a guarantee of future
currency, and a consumer MUST re-evaluate for currency at any `T' > T` (§13.5). Baseline
coverage is key-status based: `revoked` and `compromised` key entries with `revokedFrom`
(§13.4); per-statement revocation is not a baseline guarantee (§13.1, §21 OPEN). Statement
validity windows bound statements; snapshot freshness upper bounds bound revocation
knowledge, not statement expiry. Revocation publication latency SHOULD be comparable to
statement validity windows and snapshot freshness upper bounds (§11.6, §21).

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

Transfer MUST be represented by a signed transfer statement, not by an implicit administrative
update of Description Location, not by a key appearing in an Authority Record, and not by
lifecycle changes.

### 12.2 Transfer statement (follow-up resource; model fixed here)

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

A transfer statement MUST be signed by the outgoing authority key. Bootstrap-to-first-transfer
is not applicable; the initial binding comes from the Trust Anchor Binding.

A transfer statement whose `outgoingKeyId` is not the verifier's accepted current authority
MUST produce `UNKNOWN_AUTHORITY`. A transfer statement that is not fresh MUST NOT create an
epoch. A verifier MUST NOT accept unsigned, self-attested, `incomingKeyId`-signed,
administratively signed, origin-signed, DNS-signed, or CDN-signed transfer claims.

A transfer MUST bind to the authority Record chain in a fixed order:

1. the transfer statement is presented at `recordGeneration = G`, strictly above the last
   accepted Record `G-1`, signed by the outgoing key (`current` in Record `G-1`), with
   `newEpochId` set;
2. the verifier verifies the transfer statement under the outgoing key;
3. Record `G` MUST be signed by the outgoing key, introduced by the verified transfer,
   and MUST carry `newEpochId` and the new epoch's `statementFloor` (baseline `0`); it
   introduces the incoming key as `current` with a window containing `issuedAt(G)`;
4. the verifier MUST accept Record `G` atomically as the epoch switch: the outgoing key is
   non-current from the acceptance of Record `G` onward, and the incoming key is current
   for signatures from Record `G + 1` onward;
5. the first Record signed by the incoming key MUST be at `recordGeneration = G + 1` and
   MUST be accepted under §9.6;
6. transfers and Records below the accepted maximum are authority rollback (§9.7).

Signer state is judged against the immediately preceding accepted Record (`G-1` for
Record `G`), never against any earlier Record.

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

A fresh, verified revocation of a key: the key's window ends at the revocation's effective
generation; statements under that key with generation ≥ effective generation are `REVOKED`;
statements below remain governed by §11.3. Per-statement revocation is not a baseline
guarantee (§13.1): baseline coverage is key-status based (§11.4). A statement revocation
extension, when defined, MUST invalidate verifier caches of revoked statements at the
verifier's next accepted evaluation (§21 OPEN).

Revocation, retirement, and compromise are expressed as Record chain operations (§9.5): the
signing key is `current` under Record `G-1`, and Record `G` marks the target key
`status = "revoked"` or `"compromised"` with `revokedFrom`.

A key marked `compromised` in the accepted chain MUST NOT be accepted as current by the
verifier, for any signature, at any generation, at any time. A compromised key that
re-signs old `generation`/`issuedAt` values after compromise produces a signature the
verifier MUST NOT accept as current: without independently trusted evidence, the verifier
MUST NOT recognize a signature from a compromised key as current (§17, leak-replay). A
signature by a compromised key, evaluated with fresh coverage, MUST be surfaced as `REVOKED`,
never as `VERIFIED`.

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

Within a phase, conditions apply in listed order, and each branch yields exactly one
result. A signer absent from the accepted chain fails at phase 5 as
`UNKNOWN_AUTHORITY` and never reaches phases 8–9; `keyId`/`publicKey` recomputation
mismatch fails at phase 1 as `INVALID` for the Record, phase 5 for the dependent
statement, consistent with §9.4. A retired key yields `UNVERIFIED` (§10.5), never
`REVOKED`; `REVOKED` requires a fresh snapshot, which phase 6 guarantees before phase 9.
A statement replayed within its validity window at the accepted maximum is not freshness
progress (§11.3). Authority rollback (Record below the accepted maximum) is `STALE`; a
same-generation Record with a different payload is `CONFLICT`; a signer outside the
accepted chain is `UNKNOWN_AUTHORITY`. A verifier MUST NOT reorder phases, MUST NOT pick
by severity, recency, or origin preference. The result MUST record which phase failed.

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
| Key compromise | revocation, short windows, fail-closed revocation, compromised flag in Records, never-current signatures (§10.6, §13.4) |
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
Binding including its public key material (§9.6), keyId recomputation from wire `publicKey`
bytes (§9.4, §10.1), authority-material anti-rollback and equivocation handling (§9.7),
freshness, per-epoch statement anti-rollback, the revocation snapshot (§11.4), the full
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
transfer, and revocation over the wire (§9.3, §9.6 item 5); §9.7 equivocation handling;
revocation snapshot freshness for `VERIFIED` (§11.4); §15.2 precedence cases; Appendix A
canonical and signature vectors, including the non-BMP UTF-16 code-unit vector.
Identifiers remain catalog-owned; this draft MUST NOT invent them.

This draft MUST NOT invent `RES-*`, `LIFE-*`, `MAN-*`, `INT-*`, `NET-*`, `RT-*`, or L2
catalog case identifiers. The Frozen Catalog owns 0.1 identifiers; a future L2 catalog owns
L2 identifiers. Local PHPUnit tests map to the L2 catalog only after it exists; the
repository confirmation flow (`composer validate`, `composer test`, `composer analyse`,
`php -l`, `git diff --check`) applies to implementation work, and local results MUST NOT be
reported as relink-testbed results.

## 21. Open issues requiring follow-up decisions

1. `p` semantics for L2 profiles, nonce/challenge binding for online freshness;
2. transfer/revocation as public resources (paths, status codes, discovery, submission
   channel: administrative vs. L2-signed material); per-statement revocation as a
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
10. L2 conformance catalog case set, including §9.6 chain-acceptance, §9.7 equivocation,
    §11.4 revocation snapshot, §15.2 precedence, and signature test vectors beyond
    Appendix A;
11. Japanese normative translation after stabilization.

## 22. Acceptance-criteria mapping (Issue #28)

| Issue #28 acceptance criterion | Draft coverage |
| --- | --- |
| L2 is a separate versioned layer, frozen Core 0.1 semantics unchanged | §1, §5, freeze policy |
| Integrity / authenticity / authority / freshness / ownership / authorization distinguished | §4, §8.2, §15, §16.3 |
| Signed / verifiable object defined | §7 (payload, JCS canonical serialization, signing bytes), Appendix A vectors |
| Signer identity and Entity authority rules defined | §8, §§9.1–9.8 (Authority Record format with wire `publicKey` material, chain acceptance, anti-rollback, state loss) |
| Key discovery and rotation semantics defined | §§9.5–9.7, §10 (validity windows, rotation Records, keyId recomputed from wire publicKey, epochId); OPEN items in §21 |
| Freshness and anti-rollback defined | §11 (statement anti-rollback, authority-material anti-rollback §9.7, revocation snapshot §11.4) |
| Ownership / authority transfer semantics defined | §12 |
| Revocation semantics defined | §13, §9.5, §13.4 (revocation, retirement, compromise as Record chain operations); revocation material resource OPEN, §21 |
| Verification result states deterministic and documented | §15 (modes, precedence order, `ANCHOR_MISMATCH`) |
| Relationship to Manifest 0.1 explicit | §16.2, §7.6 |
| Relationship to administrative authentication explicit | §16.3 |
| L1 downgrade / fallback explicit and fail-closed | §5, §6.1, §6.5, §17 |
| Security analysis covers replay, rollback, key compromise, conflicting authority, signature stripping, Record rollback/equivocation, cross-anchor substitution, compromised-key re-signing, first-contact bootstrap, keyId/publicKey substitution, cross-epoch replay | §17 |
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

---

## Appendix A. Deterministic Ed25519 + JCS test vectors (informative)

This appendix pins the exact signing target, signature encoding, and canonical
serialization rules of §7.3, including object member order in UTF-16 code-unit
order (RFC 8785 §3.2.3, §7.3). The vectors are reproducible from the fixed
seed below using only RFC 8032 Ed25519, RFC 8785 JCS, SHA-256, SHA-512, and base64url
(RFC 4648 §5). Conformance test case identifiers are catalog-owned follow-up work
(§20); this appendix provides reference values, not case identifiers.

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

### A.5 Reproduction steps

1. Compute the private seed per A.1, derive the public key `A`, recompute `keyId` per
§10.1, and check the A.1 values.
2. Serialize each payload with JCS: object member names sorted in UTF-16 code-unit
   order (RFC 8785 §3.2.3), minimal escaping (C0 control characters, `"`, `\`, and
   DEL escaped as `\uXXXX`; printable non-ASCII emitted as-is), integers as plain
   decimal, no whitespace. The result MUST equal the canonical string above
   byte-for-byte.
3. Sign the canonical string's UTF-8 octets with deterministic Ed25519 (RFC 8032
   §5.2); the base64url signature ('=' stripped) MUST equal the signature above.
4. Verify against `A`; a tampered signing target MUST fail. Vector 3 MUST verify with
   the U+1F600 member serialized before the U+E000 member; scalar code-point sorting
   MUST NOT reproduce the canonical bytes.
