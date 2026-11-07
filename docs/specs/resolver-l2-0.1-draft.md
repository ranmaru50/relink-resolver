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
   for L1 resolution.

L2 MUST be additive and independently observable:

```text
L1 absent of L2     → unchanged Core 0.1 behavior
L2 present          → additional signed trust material
L2 verification     → consumer-side decision, never a Resolver transport side effect
```

A statement that fails L2 verification MUST NOT alter the public L1 response. L2 verification
success MUST NOT alter the public L1 response. Consumers MUST treat L2 results as metadata
about the resolution, not as a substitute for L1 HTTP status.

A Core 0.1-only Resolver receiving `l=2` MUST fail closed exactly as Core 0.1 requires:
`501 Not Implemented` with `Cache-Control: no-store`.

## 6. L2 request and resource shape

### 6.1 L2 statement request

```text
GET /{resolver-service}/{uuid}?l=2
```

For an L2-supporting Resolver and an ACTIVE record:

```http
200 OK
Content-Type: application/json
Cache-Control: <bounded by remaining validity, §14.4>
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

### 6.2 `p` parameter

Baseline L2 defines no required `p` semantics. A request containing `p` without L2-defined
semantics MUST be processed as Core 0.1 requires: `400 Bad Request`, no L1 downgrade.
`p` MAY later carry L2 profile identifiers, nonce, or challenge binding (OPEN, §21).

### 6.3 Authority material request

```text
GET /{resolver-service}/{uuid}/authority
```

For an ACTIVE record, `200` + an Authority Record (§9). Same status mapping as §6.1.
The Authority Record is signed metadata (self-signed by current authority; bootstrap by
trust anchor binding, §9.3).

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
```

`l2Version` MUST be the string `"0.1"`. A verifier MUST NOT interpret other `l2Version`
values as L2 0.1. An unsupported `l2Version` MUST fail closed (not an ignorable extension).

Optional payload members (baseline): none are REQUIRED; any additional member is treated
under §7.5.

### 7.3 Canonical serialization and signing bytes

The signing target MUST be deterministic and interoperable.

The signing target is the UTF-8 encoding of the canonical serialization of `payload`, with:

1. members serialized in the fixed order defined in §7.2, then any additional members in
   lexicographic ascending order of member name;
2. strict JSON with minimal whitespace (no insignificant whitespace);
3. lowercase hexadecimal in UUID values;
4. Unicode text serialized as defined by standard JSON escaping rules with non-ASCII
   characters emitted as UTF-8 (not escaped);
5. no duplicate members, no numeric-format ambiguity: integer-valued members MUST be
   serialized as decimal integers without exponent, fraction, `+`, leading zeros, or trailing
   `.0`;
6. base64url for `keyId` digest material as defined in §10.1.

The verifier MUST canonicalize the parsed `payload` and verify the signature against the
canonical octets. A payload that fails §7.3 canonicalization rules, contains duplicate
members, contains a non-canonical number format, or fails to parse as strict JSON MUST
produce `INVALID`.

The signing target MUST NOT depend on XML canonicalization, transport framing, content
coding, or the consumer's JSON parser quirks.

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

- Unknown members inside `payload` are covered by the signature via §7.3; they MUST be
  accepted, MUST NOT override defined member semantics, and MUST NOT be treated as
  security-critical evidence by verifiers.
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
registration/administrative channel: the Trust Anchor Binding records `anchorId → initial
keyId` and initial epoch start. The initial Authority Record MAY self-sign. The verifier
accepts bootstrap only through its policy channel. Bootstrap establishes the authority
binding, not Entity authentication, ownership of the physical device, or AR-XML validity.

The provenance, format, and provisioning mechanism of Trust Anchor Bindings is a
deployment profile concern, not L2 wire semantics (OPEN, §21).

### 9.4 Trust material consistency

A verifier MUST reject authority material that contradicts its accepted binding, and MUST
produce `UNKNOWN_AUTHORITY` for statements signed by keys absent from the accepted binding,
`CONFLICT` per §8.3 for contradictory bindings, and `UNVERIFIED` when authority material is
unavailable (missing, unretrievable, not fresh per §11.4).

## 10. Key lifecycle

### 10.1 Key identifiers

`keyId` MUST be a lowercase base64url string of the first 16 bytes of the SHA-256 digest of
the public key's canonical encoding. The public key encoding per algorithm is defined in
§10.8. Verifiers MUST treat `keyId` as an opaque lookup key, not as an authorization claim.

### 10.2 Baseline algorithms

Baseline L2 verifiers MUST support EdDSA Ed25519 (algorithm identifier `ed25519`).
ES256 (`ES256`, NIST P-256) is RECOMMENDED as the second supported algorithm.

A baseline L2 deployment MUST NOT require proprietary cryptographic schemes, licensed
products, external trust services, specialized hardware, or patent-dependent mechanisms.

### 10.3 Rotation

Key rotation is a signed act of the current authority. The baseline model represents
rotation via the Authority Record: a new key's validity window is established by a record
signed by a key that is current at signing time. The Authority Record MUST NOT be
constructible by the new key alone.

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

The verifier MUST track, per Anchor UUID and epoch, the highest generation accepted as
current. A statement with `generation` less than that accepted maximum MUST produce `STALE`.
A statement at the accepted maximum MUST NOT be re-accepted as freshness progress; replay of
the identical accepted statement is permitted only within its validity window, and MUST NOT
be accepted after a revocation covering it.

Generation numbers within an epoch MUST be strictly increasing for successive accepted
statements. A statement with generation below the epoch floor (set by a transfer, §12, or
revocation, §13) MUST produce `STALE`.

### 11.4 Authority Record freshness

Authority material has its own validity window. Expired authority material MUST NOT be
accepted as current authority. Retrieval failure, expired authority material, or missing
revocation coverage when policy requires it MUST produce `UNVERIFIED`, not `VERIFIED`.
Revocation evidence is required for `REVOKED` (§13), not for `VERIFIED` when revocation
state is available and the signer is not revoked.

### 11.5 Offline verification

An offline verifier (no network, no trusted-clock service) MAY verify: signature, key
binding, structure, §7.4 consistency, and recorded authority material. Without a trusted
clock, freshness MUST be evaluated against local trusted time only when the verifier
documents a trusted time source; otherwise the result is `UNVERIFIED` and MUST NOT be
surfaced as a freshness verdict. Without fresh revocation coverage, `REVOKED` cannot be
determined and `UNVERIFIED` MUST NOT be upgraded. A nonce/challenge mechanism for online
verification is a follow-up (§21).

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
effectiveGeneration (the first generation of the new epoch)
issuedAt
expiresAt
```

A transfer statement MUST be signed by the outgoing authority key. Bootstrap-to-first-transfer
is not applicable; the initial binding comes from the Trust Anchor Binding.

A transfer statement whose `outgoingKeyId` is not the verifier's accepted current authority
MUST produce `UNKNOWN_AUTHORITY`. A transfer statement that is not fresh MUST NOT create an
epoch. A verifier MUST NOT accept unsigned, self-attested, `incomingKeyId`-signed,
administratively signed, origin-signed, DNS-signed, or CDN-signed transfer claims.

### 12.3 Epoch semantics

After an epoch transition at generation `G`, statements from the prior epoch MUST NOT be
accepted as current (generation < epoch floor ⇒ `STALE`). The outgoing authority MUST NOT
sign in the new epoch. The incoming authority MUST NOT sign before the epoch floor.

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
key            (a key's validity window before natural expiry)
statement      (a specific unexpired statement before natural expiry)
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
statements below remain governed by §11.3. A fresh, verified revocation of a statement makes
that statement `REVOKED` regardless of cache freshness; verifier caches of revoked statements
MUST be invalidated at the verifier's next accepted evaluation.

### 13.5 Fail-closed revocation availability

When policy requires revocation currency and revocation state is unavailable, the result is
`UNVERIFIED` (fail-closed for L2-VERIFIED claims). A verifier MUST NOT treat revocation
unavailability as `VERIFIED` when its policy requires revocation coverage. Revoked-but-cached
statements MUST NOT be surfaced as current.

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

Caches MUST NOT serve an L2 statement past its `expiresAt`, past a revocation covering it,
or past an epoch transition affecting it.

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

A verifier MUST produce exactly one deterministic primary state for a statement evaluation.
The verifier MUST be able to distinguish structural/signature failure from missing trust
information and from freshness failure.

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
| Replay | validity window + generation + accepted-maximum (§11.3) |
| Rollback | monotonic generations, epoch floors, `STALE` (§11.3, §12.3) |
| Stale signed data | `expiresAt`, epoch floor, `EXPIRED`/`STALE` (§11) |
| Key compromise | revocation, short windows, fail-closed revocation (§10.6, §13) |
| Key substitution | Authority Record signed by prior-current key, not by new key alone (§10.3) |
| Conflicting authorities | `CONFLICT`, fail-closed, no reconciliation heuristics (§8.3) |
| Downgrade to L1 | Core `l` rules preserved; `l=2` to Core-only → `501`, never L1 (§6.1, §5) |
| Signature stripping | signing target = canonical payload octets; signature in payload rejected; `VERIFIED` requires signature (§7.1, §15 `INVALID`) |
| Canonicalization ambiguity | fixed serialization order, number rules, no XML canonicalization (§7.3) |
| Clock skew | documented skew tolerance, bounded validity, `UNVERIFIED` without trusted clock (§11.2) |
| Trust-anchor compromise | bootstrap provenance is a policy channel; authority material self-bootstrapping forbidden (§9.2–9.3) |
| Transfer race | outgoing-key signature required, single current epoch, `CONFLICT` (§12.2, §12.6) |
| Revoked-but-cached statements | revocation overrides cache, cache bounded by remaining validity (§13.4, §14.2) |
| Offline verification limits | `UNVERIFIED` for clock/revocation gaps, never `VERIFIED` from offline gaps (§11.5, §13.6) |

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
MUST implement: canonical serialization, `ed25519` verification, statement parsing,
authority binding checks, freshness, anti-rollback, revocation state handling, the full
state model in §15, §7.4 consistency, and fail-closed behavior everywhere required.

Optional extensions: `ES256` and later algorithms (§10.8), delegation/chained authority,
nonce/challenge online checks, Manifest association, third-party timestamps, transparency
logs, profile identifiers via `p`. Extensions MUST NOT be required for baseline conformance,
MUST NOT redefine baseline semantics, and MUST NOT be treated as security-critical by
baseline verifiers.

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

This draft MUST NOT invent `RES-*`, `LIFE-*`, `MAN-*`, `INT-*`, `NET-*`, `RT-*`, or L2
catalog case identifiers. The Frozen Catalog owns 0.1 identifiers; a future L2 catalog owns
L2 identifiers. Local PHPUnit tests map to the L2 catalog only after it exists; the
repository confirmation flow (`composer validate`, `composer test`, `composer analyse`,
`php -l`, `git diff --check`) applies to implementation work, and local results MUST NOT be
reported as relink-testbed results.

## 21. Open issues requiring follow-up decisions

1. `p` semantics for L2 profiles, nonce/challenge binding for online freshness;
2. canonicalization of signed extension members inside `payload` (member-order rules for
   vendor extensions);
3. transfer/revocation as public resources (paths, status codes, discovery, submission
   channel: administrative vs. L2-signed material);
4. Trust Anchor Binding provisioning profile (factory pairing, operator trust lists,
   device-bundled anchors) and its conformance;
5. URI comparison normalization for §7.4 beyond exact byte equality;
6. `statementType` registry governance and version negotiation;
7. delegation / chained authority design;
8. authority material for SUSPENDED/RETIRED lifecycle edges in administrative, export, and
   archival contexts;
9. revocation propagation latency targets and cache invalidation interfaces;
10. ES256 adoption as MUST, and a profile mechanism for adding algorithms;
11. L2 conformance catalog case set;
12. Japanese normative translation after stabilization.

## 22. Acceptance-criteria mapping (Issue #28)

| Issue #28 acceptance criterion | Draft coverage |
| --- | --- |
| L2 is a separate versioned layer, frozen Core 0.1 semantics unchanged | §1, §5, freeze policy |
| Integrity / authenticity / authority / freshness / ownership / authorization distinguished | §4, §8.2, §15, §16.3 |
| Signed / verifiable object defined | §7 (payload, canonical serialization, signing bytes) |
| Signer identity and Entity authority rules defined | §8, §9 |
| Key discovery and rotation semantics defined | §10 (discovery windows; OPEN items in §21) |
| Freshness and anti-rollback defined | §11 |
| Ownership / authority transfer semantics defined | §12 |
| Revocation semantics defined | §13 (revocation material resource OPEN, §21) |
| Verification result states deterministic and documented | §15 |
| Relationship to Manifest 0.1 explicit | §16.2, §7.6 |
| Relationship to administrative authentication explicit | §16.3 |
| L1 downgrade / fallback explicit and fail-closed | §5, §6.1, §6.5, §17 |
| Security analysis covers replay, rollback, key compromise, conflicting authority, signature stripping | §17 |
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
