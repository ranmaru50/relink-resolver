# RELink Resolver / Manifest Conformance Catalog 0.1

Status: Draft conformance specification  
Version: 0.1  
Scope: Resolver Core 0.1, Resolver Lifecycle 0.1, Frozen Manifest 0.1

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

## 2. Case model

Each test case has:

- **ID**: stable catalog identifier;
- **Precondition**: externally relevant state or fixture;
- **Action/Input**: request or representation presented to the system under test;
- **Expected**: required observable result;
- **Reference**: governing RELink specification area.

A Testbed implementation MAY subdivide a catalog case into multiple executable tests, but SHOULD preserve the catalog ID in reporting.

## 3. Result classes

The Testbed SHOULD distinguish at least:

```text
PASS
FAIL
NOT-APPLICABLE
UNSUPPORTED-OPTIONAL
```

`UNSUPPORTED-OPTIONAL` is appropriate only for optional functionality that baseline conformance does not require, such as Manifest integrity verification.

## 4. Resolver resolution cases

### RES-001 — ACTIVE UUID resolves

**Precondition:** registered ACTIVE UUID with a valid current HTTPS Description Location.  
**Action:** `GET /{resolver-service}/{uuid}`.  
**Expected:** `303 See Other`; `Location` equals the current validated HTTPS Description Location.  
**Reference:** Resolver Core §§7-9, 11-12.

### RES-002 — changed Description Location is reflected

**Precondition:** ACTIVE record whose current Description Location is changed administratively from A to B.  
**Action:** perform a fresh origin request after the update.  
**Expected:** new `303` identifies B, not A. Existing intermediary caches MAY continue serving A only within previously advertised freshness semantics.  
**Reference:** Resolver Core §§8, 14; Lifecycle §15.

### RES-003 — unknown UUID

**Precondition:** syntactically valid but unregistered UUID.  
**Action:** Core GET.  
**Expected:** `404 Not Found`.  
**Reference:** Resolver Core §§6, 12.

### RES-004 — unsafe stored Location is not emitted

**Precondition:** ACTIVE record contains a stored Location that cannot be safely emitted as a conforming absolute HTTPS URI.  
**Action:** Core GET.  
**Expected:** Resolver MUST NOT emit the unsafe value; SHOULD return `500 Internal Server Error`.  
**Reference:** Resolver Core §8.

## 5. Identifier cases

### ID-001 — lowercase UUID accepted

Valid lowercase RFC 9562 textual UUID MUST be accepted for lookup.

### ID-002 — uppercase UUID accepted

Equivalent uppercase hexadecimal UUID text MUST be accepted and resolve as the same UUID value.

### ID-003 — mixed-case UUID accepted

Mixed-case hexadecimal UUID text permitted by RFC 9562 MUST be accepted and compared case-insensitively as a UUID value.

### ID-004 — malformed UUID rejected

Malformed UUID in the UUID path position MUST return `400 Bad Request`.

### ID-005 — UUID version treated opaquely

A syntactically valid supported registered UUID MUST NOT receive different Resolver semantics merely because of version-specific bits.

**Reference for ID-001–005:** Resolver Core §6.

## 6. HTTP method and level-request cases

### HTTP-001 — GET is read-only

A successful public Core GET MUST NOT mutate Resolver state.

### HTTP-002 — unsupported method returns 405

A syntactically valid Core route requested with an unsupported method MUST return `405 Method Not Allowed` and `Allow: GET`.

### HTTP-003 — unsupported-method result independent of registration state

For syntactically valid UUID values, unsupported methods MUST NOT reveal whether the UUID is ACTIVE, SUSPENDED, or unknown through different public status behavior.

### HTTP-004 — unsupported `l` fails closed

A Core 0.1-only Resolver receiving an unsupported requested `l` MUST NOT process the request as L1 and SHOULD return `501 Not Implemented`.

### HTTP-005 — reserved `p` without defining level fails closed

`p` without a supported defining `l` MUST NOT be processed as ordinary L1 and SHOULD return `400 Bad Request`.

**Reference:** Resolver Core §§5, 7, 12.

## 7. Redirect and transport cases

### REDIR-001 — ordinary HTTPS redirect before Resolver

An Anchor path MAY traverse an ordinary HTTPS redirect before reaching the Resolver without changing Resolver semantics.

### REDIR-002 — Resolver 303 followed by final AR-XML retrieval

Consumer follows the Resolver `303` subject to network policy and obtains AR-XML from the resulting HTTPS path.

### REDIR-003 — additional HTTPS redirect after Resolver

A Description Location MAY itself redirect over HTTPS before the final AR-XML representation.

### REDIR-004 — HTTPS-to-HTTP downgrade before Resolver rejected

Any HTTPS-to-HTTP downgrade in the Anchor-to-Resolver chain MUST cause L1 dereferencing to fail.

### REDIR-005 — HTTPS-to-HTTP downgrade after Resolver rejected

Any HTTPS-to-HTTP downgrade from Resolver response through final AR-XML retrieval MUST cause L1 dereferencing to fail.

### REDIR-006 — final AR-XML URL is HTTPS

The final AR-XML document URL used under L1 MUST use HTTPS.

### REDIR-007 — redirect loop bounded

Consumers SHOULD enforce a bounded redirect count and/or loop detection. A loop MUST NOT result in unbounded dereferencing.

**Reference:** Resolver Core §§10, 16-17.

## 8. Lifecycle cases

The following catalog cases correspond to Resolver Lifecycle 0.1:

### LIFE-001 — ACTIVE public behavior
ACTIVE → Core GET returns `303`.

### LIFE-002 — SUSPENDED public behavior
SUSPENDED → Core GET returns `404`.

### LIFE-003 — RETIRED public behavior
RETIRED → Core GET returns `410`.

### LIFE-004 — ACTIVE → SUSPENDED permitted
After successful transition, fresh origin Core requests use SUSPENDED behavior.

### LIFE-005 — SUSPENDED → ACTIVE permitted
After successful reactivation, fresh origin Core requests use ACTIVE behavior.

### LIFE-006 — ACTIVE → RETIRED permitted
After retirement, fresh origin requests use `410`.

### LIFE-007 — SUSPENDED → RETIRED permitted
After retirement, fresh origin requests use `410`.

### LIFE-008 — RETIRED → ACTIVE forbidden
Maintenance layer MUST reject the transition.

### LIFE-009 — RETIRED → SUSPENDED forbidden
Maintenance layer MUST reject the transition.

### LIFE-010 — lifecycle and Description Location are independent
Suspension/reactivation MAY preserve the stored Location; reactivation still requires response-time Location validation.

### LIFE-011 — transition history reflects actual change
Where history is supported by the Reference Resolver, retained material events SHOULD report previous state, new state, and transition time consistently with the applied state.

### LIFE-012 — stale cache does not redefine origin state
A previously cached response MAY remain usable only under normal cache freshness rules; the Resolver origin MUST use the new state after transition commit.

**Reference:** Resolver Lifecycle 0.1 §§3-16.

## 9. Cache and CORS cases

### CACHE-001 — ACTIVE 303 has explicit cache policy
A successful `303` MUST send an explicit cache policy.

### CACHE-002 — reference ACTIVE cache default
Reference profile SHOULD use `Cache-Control: public, max-age=60` unless configured otherwise.

### CACHE-003 — negative/temporary failures avoid cache persistence
`400`, `404`, `500`, `501`, and `503` SHOULD use `Cache-Control: no-store` in the Reference profile.

### CACHE-004 — RETIRED response may be finitely cached
`410` MAY be cached; Reference profile may use a finite lifetime.

### CORS-001 — browser-oriented Core supports public CORS
Public browser-oriented Resolver SHOULD expose `Access-Control-Allow-Origin: *` for Core GET.

### CORS-002 — Resolver CORS does not imply AR-XML CORS
Successful CORS access to Resolver MUST NOT be treated as permission to fetch the final AR-XML origin.

**Reference:** Resolver Core §§14-15.

## 10. Manifest baseline cases

### MAN-001 — minimal valid Manifest
A minimal representation containing `manifestVersion`, `anchor.id`, `entity.id`, `description.location`, and `lifecycle.status` with valid values MUST be accepted.

### MAN-002 — unsupported Manifest version rejected
A Manifest with `manifestVersion` other than `"0.1"` MUST NOT be interpreted as Manifest 0.1.

### MAN-003 — strict JSON required
A published representation using JSON5-only syntax (comments, trailing commas, single quotes, or unquoted member names) MUST be rejected as a Manifest 0.1 wire representation.

### MAN-004 — duplicate member rejected
A duplicate object-member name anywhere in the Manifest, including nested extension objects, MUST cause rejection.

### MAN-005 — anchor/path UUID consistency
For deterministic `/uuid/manifest` retrieval, `anchor.id` MUST identify the same UUID value as the path UUID; mismatch is invalid.

### MAN-006 — entity identity distinct from Location
Changing `description.location` MUST NOT require changing `entity.id`.

### MAN-007 — `entity.id` is not a dereference instruction
A consumer MUST NOT automatically dereference `entity.id` solely because its scheme is dereferenceable.

### MAN-008 — HTTPS Description Location required for L1
Non-HTTPS `description.location` MUST fail Manifest 0.1 L1 semantic validation.

### MAN-009 — Manifest absence does not break Core L1
A deployment without a Manifest endpoint can still conform to Resolver Core 0.1; successful ACTIVE Core resolution remains valid.

### MAN-010 — ACTIVE public Manifest
Public Manifest retrieval for ACTIVE should return `200 OK` with `Content-Type: application/json` and a conforming representation.

### MAN-011 — SUSPENDED public Manifest
SUSPENDED public Manifest retrieval should return `404`.

### MAN-012 — RETIRED public Manifest
RETIRED public Manifest retrieval should return `410`.

### MAN-013 — unknown Manifest UUID
Unknown UUID should return `404`.

**Reference:** Frozen Manifest 0.1 §§5-13, 23.

## 11. Manifest transport cases

### MNET-001 — Manifest retrieval is HTTPS-only
Manifest 0.1 L1 retrieval MUST use HTTPS.

### MNET-002 — Manifest HTTPS-to-HTTP downgrade rejected
A consumer MUST reject/prevent downgrade throughout the Manifest redirect chain.

### MNET-003 — final Manifest URL is HTTPS
A final Manifest representation used for L1 processing MUST come from a final HTTPS URL through an HTTPS-only chain.

**Reference:** Frozen Manifest 0.1 §11.1.

## 12. Manifest integrity cases

These cases apply only to consumers claiming Manifest 0.1 integrity-verification support.

### INT-001 — integrity absent remains valid baseline
Absence of `description.integrity` MUST NOT invalidate an otherwise valid Manifest 0.1 representation.

### INT-002 — valid sha-256 match
Given a supported `sha-256` declaration whose lowercase 64-hex digest matches the defined final representation body octets, verification succeeds.

### INT-003 — digest mismatch
Mismatch MUST be reported as integrity verification failure. When policy requires verification, AR-XML capability discovery/invocation MUST NOT continue on that representation.

### INT-004 — unsupported algorithm
Consumer MUST NOT report verification success for an unimplemented algorithm; it MAY classify the integrity value as unverifiable according to local policy.

### INT-005 — malformed sha-256 digest
A `sha-256` digest not consisting of exactly 64 lowercase hexadecimal characters is invalid integrity metadata.

### INT-006 — redirect response body is not digest input
Where Description retrieval redirects, the digest applies to the final successfully retrieved representation, not an intermediate redirect body.

### INT-007 — content-coding semantics
Digest is computed over final representation body octets after HTTP content-coding processing by the HTTP/fetch stack and before character decoding/XML parsing.

### INT-008 — integrity success is not Trust
A successful digest comparison MUST NOT be reported as Entity authentication, Manifest authenticity, authorization, freshness, rollback protection, or L2 achievement.

**Reference:** Frozen Manifest 0.1 §9.2.

## 13. Extension cases

### EXT-001 — unknown top-level member tolerated for forward compatibility
An otherwise valid Manifest containing an unknown non-critical top-level member SHOULD remain processable; the unknown member does not override standard semantics.

### EXT-002 — unknown vendor extension tolerated
An unknown `extensions[namespace]` value SHOULD be ignored while baseline processing continues.

### EXT-003 — extension cannot override Description Location
A vendor extension containing an alternate location MUST NOT replace `description.location` for Manifest 0.1 processing.

### EXT-004 — extension cannot override lifecycle
Vendor metadata MUST NOT replace or reinterpret `lifecycle.status`.

### EXT-005 — extension cannot redefine integrity
An extension MUST NOT alter the standard verification semantics of `description.integrity`.

### EXT-006 — extension name has no intrinsic Trust meaning
Names such as `trusted`, `verified`, `signature`, or `publicKey` MUST NOT acquire RELink Trust semantics merely by presence.

### EXT-007 — baseline does not depend on vendor extension
A producer MUST NOT require a vendor extension for baseline Manifest 0.1 conformance or Resolver Core L1 resolution.

**Reference:** Frozen Manifest 0.1 §12; Manifest 0.1 Extension Policy §§2-6.

## 14. Resource-consumption cases

### LIMIT-001 — bounded Manifest body
A consumer MAY reject a Manifest that exceeds its documented finite response-body limit without treating the Anchor UUID itself as invalid.

### LIMIT-002 — bounded JSON nesting
A consumer SHOULD enforce finite nesting-depth limits.

### LIMIT-003 — bounded members/elements
A consumer SHOULD enforce finite object-member/array-element/resource limits appropriate to its execution environment.

These cases test existence and consistent enforcement of documented implementation limits, not universal numeric values.

**Reference:** Frozen Manifest 0.1 §17.

## 15. Schema validation cases

### SCHEMA-001 — schema success alone is insufficient
A representation that passes a JSON Schema validator but violates normative semantic requirements MUST still be rejected as non-conforming.

### SCHEMA-002 — `format` implementation variance does not weaken semantics
UUID and absolute-URI semantics MUST be checked even when the chosen JSON Schema implementation treats `format` as annotation rather than assertion.

**Reference:** Frozen Manifest 0.1 §21.

## 16. Testbed implementation boundary

The executable Testbed MAY choose any implementation language or framework.

It SHOULD provide controllable fixtures for:

- Resolver lifecycle state;
- Description Location mutation;
- HTTP redirects and downgrade paths;
- cache headers;
- Manifest representations including malformed JSON/duplicates;
- compressed/content-coded AR-XML responses;
- optional integrity success/failure;
- extension payloads;
- bounded-resource cases.

The Testbed MUST NOT infer protocol conformance from internal datastore state alone when externally observable behavior can be tested.

## 17. Reporting

A conformance report SHOULD identify:

```text
catalog version
implementation under test
execution environment
case ID
result
optional diagnostic detail
```

Optional-feature cases SHOULD clearly distinguish unsupported optional functionality from normative baseline failure.

## 18. Scope boundary

This catalog does not define:

- production Resolver implementation code;
- database schema;
- Docker or native deployment mechanics;
- AR-XML capability conformance beyond the Resolver/Manifest integration boundary;
- L2/Trust authentication tests;
- vendor-specific application behavior.

Those concerns require separate specifications or Testbed suites.

## 19. Conformance set summary

```text
Resolver Core
    RES-* ID-* HTTP-* REDIR-* CACHE-* CORS-*

Lifecycle
    LIFE-*

Manifest
    MAN-* MNET-* SCHEMA-*

Optional Manifest integrity
    INT-*

Extension compatibility
    EXT-*

Resource hardening
    LIMIT-*
```

This catalog is the protocol-side handoff contract for subsequent executable implementation in `relink-testbed`.