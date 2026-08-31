# RELink Resolver / Manifest Conformance Catalog 0.1

Status: Frozen conformance specification  
Version: 0.1  
Freeze date: 2026-09-01  
Scope: Resolver Core 0.1, Resolver Lifecycle 0.1, Frozen Manifest 0.1

> **Freeze policy:** Conformance Catalog 0.1 is frozen as the stable protocol-side Testbed handoff baseline. Editorial or non-semantic errata MAY be corrected within 0.1. Changes to conformance targets, result semantics, case identifiers, normative case expectations, baseline/optional classification, or security/network-policy semantics require a later catalog version or separately versioned profile.

## 1. Purpose

This document defines deterministic, implementation-independent conformance cases for RELink Resolver Core 0.1, Resolver Lifecycle 0.1, and Frozen RELink Manifest 0.1.

The catalog is intended to be implemented by `relink-testbed`, but executable test code is outside the scope of this repository.

```text
relink-resolver
= protocol + conformance definition

relink-testbed
= executable test implementation
```

Tests MUST evaluate externally observable protocol behavior and representation semantics rather than internal PHP, SQLite, framework, process, or deployment details.

## 2. Conformance targets and case model

A catalog case MUST identify the role being tested. The defined conformance targets are:

```text
RESOLVER-SERVER
L1-CONSUMER
MANIFEST-ENDPOINT
MANIFEST-PRODUCER
MANIFEST-CONSUMER
INTEGRITY-CONSUMER
LIFECYCLE-ADMIN
REFERENCE-RESOLVER
```

A single implementation MAY claim more than one target, but results for different targets MUST NOT be collapsed into an ambiguous overall “Resolver / Manifest PASS”.

Each test case has:

- **ID**: stable catalog identifier;
- **Target**: conformance role under test;
- **Strength**: normative strength primarily exercised by the case (`MUST`, `SHOULD`, or `MAY`);
- **Precondition**: externally relevant state or fixture;
- **Action/Input**: request or raw representation presented to the system under test;
- **Expected**: required observable result;
- **Reference**: governing RELink specification area.

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

A **MAY** case tests permitted behavior or optional interoperability. Absence of an optional behavior MUST NOT be reported as a baseline failure.

`UNSUPPORTED-OPTIONAL` is appropriate only for optional functionality that the relevant baseline target does not require, such as Manifest integrity verification.

## 4. Conformance sets

The catalog defines the following reporting sets:

```text
Resolver Core Server Conformance
= RES-* + ID-* + HTTP-* + server-side CACHE-* + CORS-001

Resolver L1 Consumer Conformance
= REDIR-* + NET-* + CORS-002 where applicable

Resolver Lifecycle Administration Conformance
= LIFE-* administrative-transition cases

Reference Resolver Operational Conformance
= reference-profile SHOULD cases + LIFE-011 + documented resource/admin behavior

Manifest Producer / Endpoint Conformance
= MAN representation/endpoint subset + MNET server-side requirements

Manifest Consumer Conformance
= MAN parsing/semantic subset + MNET consumer subset + EXT-* + LIMIT-* + SCHEMA-*

Manifest Integrity Verification Conformance
= INT-* only
```

A report SHOULD state exactly which conformance set(s) were executed.

## 5. Resolver resolution cases

### RES-001 — ACTIVE UUID resolves
**Target:** RESOLVER-SERVER  
**Strength:** MUST  
**Precondition:** registered ACTIVE UUID with a valid current HTTPS Description Location.  
**Action:** `GET /{resolver-service}/{uuid}`.  
**Expected:** `303 See Other`; `Location` equals the current validated HTTPS Description Location.  
**Reference:** Resolver Core §§7-9, 11-12.

### RES-002 — changed Description Location is reflected
**Target:** RESOLVER-SERVER  
**Strength:** MUST  
**Precondition:** ACTIVE record whose current Description Location is changed administratively from A to B.  
**Action:** perform a fresh origin request after the update.  
**Expected:** new `303` identifies B, not A. Existing intermediary caches MAY continue serving A only within previously advertised freshness semantics.  
**Reference:** Resolver Core §§8, 14; Lifecycle §15.

### RES-003 — unknown UUID
**Target:** RESOLVER-SERVER  
**Strength:** MUST  
**Expected:** syntactically valid but unregistered UUID returns `404 Not Found`.  
**Reference:** Resolver Core §§6, 12.

### RES-004 — unsafe stored Location is not emitted
**Target:** RESOLVER-SERVER  
**Strength:** MUST for non-emission; SHOULD for `500`.  
**Expected:** Resolver MUST NOT emit an unsafe/non-conforming value and SHOULD return `500 Internal Server Error`.

The Testbed SHOULD implement at least these subcases while retaining the parent catalog ID:

```text
RES-004/relative-uri
RES-004/http-scheme
RES-004/malformed-absolute-uri
RES-004/header-injection
```

**Reference:** Resolver Core §8.

## 6. Identifier cases

**Target:** RESOLVER-SERVER for ID-001–005.

- **ID-001** — **MUST** accept valid lowercase RFC 9562 UUID text.
- **ID-002** — **MUST** accept equivalent uppercase UUID text as the same UUID value.
- **ID-003** — **MUST** accept valid mixed-case UUID text.
- **ID-004** — malformed UUID in the UUID path position **MUST** return `400 Bad Request`.
- **ID-005** — Resolver semantics **MUST NOT** change merely because of UUID version-specific bits when the UUID is otherwise supported and registered.

**Reference:** Resolver Core §6.

## 7. HTTP method and level-request cases

**Target:** RESOLVER-SERVER for HTTP-001–005.

### HTTP-001 — GET is read-only
**Strength:** MUST. Public Core GET MUST NOT mutate Resolver state.

### HTTP-002 — unsupported method returns 405
**Strength:** MUST. A syntactically valid Core route requested with an unsupported method MUST return `405 Method Not Allowed` and `Allow: GET`.

### HTTP-003 — unsupported-method result independent of registration state
**Strength:** MUST. For syntactically valid UUID values, the same unsupported method MUST produce `405` independently of whether the UUID is ACTIVE, SUSPENDED, RETIRED, or unknown.

The Testbed SHOULD exercise all four states:

```text
unsupported method + ACTIVE    → 405
unsupported method + SUSPENDED → 405
unsupported method + RETIRED   → 405
unsupported method + unknown   → 405
```

### HTTP-004 — unsupported `l` fails closed
**Strength:** MUST for fail-closed; SHOULD for status. An unsupported `l` MUST NOT be processed as L1. A Core 0.1-only Resolver SHOULD return `501 Not Implemented`.

### HTTP-005 — reserved `p` without defining level fails closed
**Strength:** MUST for fail-closed; SHOULD for status. `p` without supported defining `l` MUST NOT be processed as ordinary L1. A Core 0.1-only Resolver SHOULD return `400 Bad Request`.

**Reference:** Resolver Core §§5, 7, 12.

## 8. Redirect and transport cases

**Target:** L1-CONSUMER unless otherwise stated.

- **REDIR-001** — **MAY** traverse an ordinary HTTPS redirect before Resolver without changing Resolver semantics.
- **REDIR-002** — consumer follows Resolver `303` subject to network policy and obtains AR-XML from the resulting HTTPS path.
- **REDIR-003** — Description Location **MAY** redirect over HTTPS before final AR-XML retrieval.
- **REDIR-004** — HTTPS-to-HTTP downgrade before Resolver **MUST** fail L1 dereferencing.
- **REDIR-005** — HTTPS-to-HTTP downgrade after Resolver **MUST** fail L1 dereferencing.
- **REDIR-006** — final AR-XML URL used for L1 **MUST** use HTTPS.
- **REDIR-007** — consumer **SHOULD** enforce bounded redirect count and/or loop detection; dereferencing MUST NOT be unbounded.

**Reference:** Resolver Core §§10, 16-17.

## 9. Consumer network-policy cases

These cases test policy enforcement, not a universal prohibition on local/private destinations.

### NET-001 — Resolver Location is untrusted input
**Target:** L1-CONSUMER  
**Strength:** MUST  
A Resolver-supplied Description Location MUST pass through applicable consumer/platform network policy rather than being treated as intrinsically safe.

### NET-002 — Manifest Description Location is untrusted input
**Target:** MANIFEST-CONSUMER  
**Strength:** MUST  
A Manifest-supplied `description.location` MUST be subject to applicable network policy before or while dereferenced.

### NET-003 — configured denied destination is not fetched
**Target:** L1-CONSUMER / MANIFEST-CONSUMER  
**Strength:** MUST when the execution environment exposes an enforceable deny policy.  
**Precondition:** Testbed configures policy to deny destination X.  
**Expected:** consumer does not fetch X and reports a policy/network failure rather than treating successful Resolver/Manifest parsing as authorization.

### NET-004 — redirect destination is re-evaluated where controllable
**Target:** L1-CONSUMER / MANIFEST-CONSUMER  
**Strength:** SHOULD for native/server environments where the HTTP stack permits destination inspection/control.  
A redirect from an allowed destination to policy-denied X SHOULD be blocked according to the configured policy.

### NET-005 — successful Resolver/Manifest response does not bypass policy
**Target:** L1-CONSUMER / MANIFEST-CONSUMER  
**Strength:** MUST  
Successful `303` or Manifest `200` MUST NOT be treated as permission to bypass configured network restrictions.

No case in this group requires a protocol-wide rule such as “loopback MUST be rejected”. Local Entity access remains deployment/policy dependent.

**Reference:** Resolver Core §16; Frozen Manifest 0.1 §15.

## 10. Lifecycle cases

- **LIFE-001** — **Target:** RESOLVER-SERVER; **MUST** map ACTIVE to Core `303`.
- **LIFE-002** — **Target:** RESOLVER-SERVER; **MUST** map SUSPENDED to `404`.
- **LIFE-003** — **Target:** RESOLVER-SERVER; **MUST** map RETIRED to `410`.
- **LIFE-004** — **Target:** LIFECYCLE-ADMIN; **MUST** permit ACTIVE → SUSPENDED.
- **LIFE-005** — **Target:** LIFECYCLE-ADMIN; **MUST** permit SUSPENDED → ACTIVE.
- **LIFE-006** — **Target:** LIFECYCLE-ADMIN; **MUST** permit ACTIVE → RETIRED.
- **LIFE-007** — **Target:** LIFECYCLE-ADMIN; **MUST** permit SUSPENDED → RETIRED.
- **LIFE-008** — **Target:** LIFECYCLE-ADMIN; **MUST** reject RETIRED → ACTIVE.
- **LIFE-009** — **Target:** LIFECYCLE-ADMIN; **MUST** reject RETIRED → SUSPENDED.
- **LIFE-010** — **Target:** LIFECYCLE-ADMIN / RESOLVER-SERVER; lifecycle and Description Location remain semantically independent.
- **LIFE-011** — **Target:** REFERENCE-RESOLVER; **SHOULD** retain consistent previous state, new state, and transition time for retained material history events.
- **LIFE-012** — **Target:** RESOLVER-SERVER; origin **MUST** reflect committed new state; stale cached responses remain governed only by HTTP freshness.

**Reference:** Resolver Lifecycle 0.1 §§3-16.

## 11. Cache and CORS cases

- **CACHE-001** — **Target:** RESOLVER-SERVER; **MUST** send explicit cache policy on ACTIVE `303`.
- **CACHE-002** — **Target:** REFERENCE-RESOLVER; **SHOULD** use `Cache-Control: public, max-age=60` by default unless configured otherwise.
- **CACHE-003** — **Target:** REFERENCE-RESOLVER; `400/404/500/501/503` **SHOULD** use `no-store`.
- **CACHE-004** — **Target:** RESOLVER-SERVER; `410` **MAY** be finitely cached.
- **CORS-001** — **Target:** RESOLVER-SERVER; browser-oriented public Core **SHOULD** expose `Access-Control-Allow-Origin: *`.
- **CORS-002** — **Target:** L1-CONSUMER; Resolver CORS success **MUST NOT** be treated as permission to fetch final AR-XML origin.

**Reference:** Resolver Core §§14-15.

## 12. Manifest baseline cases

### MAN-001 — minimal valid Manifest
**Target:** MANIFEST-CONSUMER / MANIFEST-PRODUCER  
**Strength:** MUST  
A minimal representation containing the five required top-level members with valid values MUST be accepted/produced as conforming baseline Manifest 0.1.

### MAN-002 — unsupported Manifest version rejected
**Target:** MANIFEST-CONSUMER  
**Strength:** MUST  
A value other than `"0.1"` MUST NOT be interpreted as Manifest 0.1.

### MAN-003 — strict JSON required
**Target:** MANIFEST-CONSUMER / MANIFEST-PRODUCER  
**Strength:** MUST  
JSON5-only wire syntax MUST be rejected/not produced as Manifest 0.1.

### MAN-004 — duplicate member rejected
**Target:** MANIFEST-CONSUMER / MANIFEST-PRODUCER  
**Strength:** MUST  
Duplicate object-member names anywhere in the representation MUST cause consumer rejection and MUST NOT be produced by a conforming producer.

**Fixture requirement:** duplicate-member cases MUST be presented to the consumer as raw Manifest UTF-8 representation bytes/text before ordinary JSON object construction. The Testbed MUST NOT pre-parse the fixture into a data structure that discards duplicate members.

### MAN-005 — anchor/path UUID consistency
**Target:** MANIFEST-CONSUMER / MANIFEST-ENDPOINT  
**Strength:** MUST  
For deterministic `/uuid/manifest`, `anchor.id` MUST identify the same UUID value as the request path.

### MAN-006 — entity identity distinct from Location
**Target:** MANIFEST-PRODUCER / MANIFEST-CONSUMER  
**Strength:** MUST  
Changing `description.location` MUST NOT require changing `entity.id`.

### MAN-007 — `entity.id` is not a dereference instruction
**Target:** MANIFEST-CONSUMER  
**Strength:** MUST  
Consumer MUST NOT dereference `entity.id` merely because its scheme is dereferenceable.

### MAN-008 — HTTPS Description Location required for L1
**Target:** MANIFEST-CONSUMER / MANIFEST-PRODUCER  
**Strength:** MUST  
Non-HTTPS `description.location` fails L1 semantic conformance.

### MAN-009 — Manifest absence does not break Core L1
**Target:** RESOLVER-SERVER / L1-CONSUMER  
**Strength:** MUST  
Absence/failure of optional Manifest retrieval MUST NOT invalidate otherwise successful Core L1 resolution.

### MAN-010 — ACTIVE public Manifest
**Target:** MANIFEST-ENDPOINT  
**Strength:** SHOULD  
ACTIVE public Manifest endpoint SHOULD return `200 OK`, `Content-Type: application/json`, and conforming JSON.

### MAN-011 — SUSPENDED public Manifest
**Target:** MANIFEST-ENDPOINT  
**Strength:** SHOULD  
SUSPENDED public Manifest endpoint SHOULD return `404`.

### MAN-012 — RETIRED public Manifest
**Target:** MANIFEST-ENDPOINT  
**Strength:** SHOULD  
RETIRED public Manifest endpoint SHOULD return `410`.

### MAN-013 — unknown Manifest UUID
**Target:** MANIFEST-ENDPOINT  
**Strength:** SHOULD  
Unknown UUID SHOULD return `404`.

### MAN-014 — integrity absent remains valid baseline
**Target:** MANIFEST-CONSUMER / MANIFEST-PRODUCER  
**Strength:** MUST  
Absence of optional `description.integrity` MUST NOT invalidate an otherwise conforming Manifest.

### MAN-015 — integrity structure and sha-256 syntax
**Target:** MANIFEST-CONSUMER / MANIFEST-PRODUCER  
**Strength:** MUST when `description.integrity` is present  
`description.integrity` MUST contain both `algorithm` and `digest`; for `sha-256`, digest MUST be exactly 64 lowercase hexadecimal characters.

**Reference:** Frozen Manifest 0.1 §§5-13, 23.

## 13. Manifest transport cases

- **MNET-001** — **Target:** MANIFEST-ENDPOINT / MANIFEST-CONSUMER; Manifest 0.1 L1 retrieval **MUST** use HTTPS.
- **MNET-002** — **Target:** MANIFEST-CONSUMER; HTTPS-to-HTTP downgrade in Manifest redirect chain **MUST** be rejected/prevented.
- **MNET-003** — **Target:** MANIFEST-CONSUMER; final Manifest representation used for L1 **MUST** come from final HTTPS URL via HTTPS-only chain.

**Reference:** Frozen Manifest 0.1 §11.1.

## 14. Optional Manifest integrity-verification cases

These cases apply only to the **INTEGRITY-CONSUMER** target. A baseline Manifest Consumer that does not claim integrity-verification support reports these cases as `UNSUPPORTED-OPTIONAL`, not `FAIL`.

### INT-002 — valid sha-256 match
**Strength:** MUST for a consumer claiming verification support. A valid declared digest matching the defined final representation body octets is reported as integrity verification success.

### INT-003 — digest mismatch
**Strength:** MUST. Mismatch MUST be exposed as integrity verification failure. The mismatching representation MUST NOT be accepted as integrity-verified input for subsequent AR-XML processing when local policy requires verified integrity.

This case does not require automatic capability discovery or invocation and MUST NOT depend on a particular Runtime execution API.

### INT-004 — unsupported algorithm
**Strength:** MUST NOT report verification success. Consumer MAY classify the value as unverifiable according to local policy.

### INT-006 — redirect response body is not digest input
**Strength:** MUST. Digest applies to final successful Description representation, not an intermediate redirect body.

### INT-007 — content-coding semantics
**Strength:** MUST. Digest input is final representation body octets after HTTP content-coding processing and before character decoding/XML parsing.

### INT-008 — integrity success is not Trust
**Strength:** MUST NOT report digest match as Entity authentication, Manifest authenticity, authorization, freshness, rollback protection, or L2 achievement.

Catalog IDs `INT-001` and `INT-005` are intentionally retired from the optional-verification set; their baseline semantics are covered by `MAN-014` and `MAN-015` respectively. Testbed implementations SHOULD preserve aliases in historical reports if needed.

**Reference:** Frozen Manifest 0.1 §9.2.

## 15. Extension cases

**Target:** MANIFEST-CONSUMER unless noted.

- **EXT-001** — unknown non-critical top-level member **SHOULD** remain processable for forward compatibility and MUST NOT override standard semantics.
- **EXT-002** — unknown vendor extension **SHOULD** be ignored while baseline processing continues.
- **EXT-003** — extension **MUST NOT** override `description.location`.
- **EXT-004** — extension **MUST NOT** override/reinterpret `lifecycle.status`.
- **EXT-005** — extension **MUST NOT** redefine `description.integrity` semantics.
- **EXT-006** — names such as `trusted`, `verified`, `signature`, `publicKey` **MUST NOT** acquire RELink Trust semantics by presence alone.
- **EXT-007** — **Target:** MANIFEST-PRODUCER / MANIFEST-CONSUMER; vendor extension **MUST NOT** become baseline Manifest/Core dependency.

**Reference:** Frozen Manifest 0.1 §12; Manifest 0.1 Extension Policy §§2-6.

## 16. Resource-consumption cases

**Target:** MANIFEST-CONSUMER unless stated.

- **LIMIT-001** — consumer **MAY** reject Manifest exceeding documented finite body-size limit without treating Anchor UUID itself as invalid.
- **LIMIT-002** — consumer **SHOULD** enforce finite JSON nesting-depth limits.
- **LIMIT-003** — consumer **SHOULD** enforce finite member/element/time/memory limits appropriate to its execution environment.

These cases test existence and consistent enforcement of documented implementation limits, not universal numeric values.

**Reference:** Frozen Manifest 0.1 §17.

## 17. Schema validation cases

**Target:** MANIFEST-CONSUMER / validation tooling.

- **SCHEMA-001** — **MUST** reject semantic non-conformance even when JSON Schema validation succeeds.
- **SCHEMA-002** — UUID/absolute-URI semantics **MUST** be checked even when validator treats `format` as annotation.

**Reference:** Frozen Manifest 0.1 §21.

## 18. Testbed implementation boundary

The executable Testbed MAY choose any implementation language or framework.

It SHOULD provide controllable fixtures for:

- Resolver lifecycle state;
- Description Location mutation;
- HTTP redirects and downgrade paths;
- configured allow/deny network-policy decisions;
- cache headers;
- raw Manifest representations including malformed JSON and duplicate members;
- compressed/content-coded AR-XML responses;
- optional integrity success/failure;
- extension payloads;
- bounded-resource cases.

The Testbed MUST NOT infer protocol conformance from internal datastore state alone when externally observable behavior can be tested.

## 19. Reporting

A conformance report SHOULD identify:

```text
catalog version
conformance target / set
implementation under test
execution environment
case ID
normative strength
result
optional deviation reason
diagnostic detail
```

A report MUST NOT present `PASS-WITH-DEVIATION` as indistinguishable from strict satisfaction of the tested SHOULD/SHOULD NOT requirement.

Optional-feature cases SHOULD clearly distinguish unsupported optional functionality from normative baseline failure.

## 20. Scope boundary

This catalog does not define:

- production Resolver implementation code;
- database schema;
- Docker or native deployment mechanics;
- AR-XML capability conformance beyond the Resolver/Manifest integration boundary;
- L2/Trust authentication tests;
- vendor-specific application behavior.

Those concerns require separate specifications or Testbed suites.

## 21. Conformance set summary

```text
Resolver Core Server
    RES-* ID-* HTTP-* CACHE-* CORS-001

Resolver L1 Consumer
    REDIR-* NET-* CORS-002

Lifecycle Administration
    LIFE-004..LIFE-010

Reference Resolver
    reference-profile SHOULD cases + LIFE-011

Manifest Producer / Endpoint
    MAN-* producer/endpoint subset + MNET-001

Manifest Consumer
    MAN-* consumer subset + MNET-* + NET-* + EXT-* + LIMIT-* + SCHEMA-*

Optional Integrity Verification
    INT-002 INT-003 INT-004 INT-006 INT-007 INT-008
```

This catalog is the protocol-side handoff contract for subsequent executable implementation in `relink-testbed`.