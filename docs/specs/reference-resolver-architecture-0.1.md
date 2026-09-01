# RELink Reference Resolver Architecture 0.1

Status: Draft reference architecture  
Version: 0.1  
Scope: First Reference Resolver implementation

## 1. Purpose

This document defines the non-code architecture and maintenance requirements for the first RELink Reference Resolver implementation.

The implementation baseline is:

```text
Apache
PHP
SQLite
```

This document specifies component boundaries, data responsibilities, public/admin separation, persistence and history requirements, operational security expectations, and non-goals. It does not prescribe concrete PHP classes, database DDL, framework choice, or deployment packaging.

## 2. Governing specifications

The Reference Resolver MUST conform to the applicable RELink specifications and frozen handoff contracts, including:

- Resolver Core 0.1;
- Resolver Lifecycle 0.1;
- Frozen Manifest 0.1 and Extension Policy;
- Frozen Resolver / Manifest Conformance Catalog 0.1;
- Frozen Web Runtime Integration Contract 0.1 where Runtime interoperability is relevant.

Reference implementation convenience MUST NOT redefine protocol semantics.

## 3. Architectural separation

The Reference Resolver MUST preserve:

```text
Public resolution ≠ Maintenance administration
Resolver Core     ≠ Manifest
Manifest          ≠ Trust
Resolution        ≠ Capability execution
Persistence model ≠ Public protocol
```

Conceptually:

```text
Public surface
GET /relink/{uuid}
GET /relink/{uuid}/manifest   (optional Manifest surface)
        ↓
Public application boundary
        ↓
Repository / persistence port
        ↓
SQLite

Administrative surface
/admin/
        ↓
authentication + authorization
        ↓
Maintenance application boundary
        ↓
Repository / history port
        ↓
SQLite
```

The public and administrative surfaces MAY run in the same deployable application, but their routes, authorization requirements, and responsibilities MUST remain distinct.

## 4. Public Resolver surface

The public Resolver Core surface MUST remain GET-only and read-only.

For Core resolution, the public path MUST implement the externally observable behavior defined by Resolver Core and Lifecycle, including:

```text
ACTIVE    → 303 + current validated HTTPS Description Location
SUSPENDED → 404
RETIRED   → 410
unknown   → 404
```

Unsupported methods, malformed UUIDs, unsupported `l`, reserved `p`, cache headers, CORS, and error handling MUST follow the governing specifications and Frozen Conformance Catalog.

The public Core path MUST NOT perform administrative mutation.

The public Core path MUST NOT require administrator authentication or use the Anchor UUID as an authorization credential.

## 5. Manifest surface

A Reference Resolver MAY expose the deterministic Manifest resource:

```text
GET /relink/{uuid}/manifest
```

If implemented, it MUST emit strict JSON conforming to Frozen Manifest 0.1 and the Frozen Extension Policy.

Manifest generation MUST use stored Resolver/Entity metadata. Public Manifest generation MUST NOT fetch or parse AR-XML merely to construct, refresh, or verify the Manifest.

If `description.integrity` is stored, the public Manifest layer MAY emit it. Digest computation or refresh belongs to an explicit publishing/maintenance operation, not the public request path.

Manifest support MUST NOT become a dependency of normal ACTIVE Core L1 resolution.

## 6. Persistence responsibilities

The persistence layer MUST be capable of representing, at minimum, the following logical information for each Resolver record:

```text
Anchor UUID
current lifecycle state
current Description Location
Canonical Entity Identity where Manifest is used
optional Manifest media type metadata
optional Manifest integrity metadata
created/updated administrative metadata
bounded material history
```

The concrete SQLite schema is implementation-defined.

The database schema, primary-key layout, table names, row IDs, indexes, or migration mechanism MUST NOT leak into public protocol semantics.

The Anchor UUID is the public Resolver record identifier; it MUST NOT be treated as a secret or bearer credential.

## 7. Description Location validation

Administrative creation/update MUST validate Description Location before committing it as the current public mapping.

For the L1 profile, a stored public Description Location MUST be an absolute HTTPS URI suitable for safe HTTP `Location` emission.

At minimum, the implementation MUST reject values that would violate the public protocol, including:

```text
relative URI
non-HTTPS URI for L1
malformed absolute URI
CR/LF or header-injection material
```

Validation of the URI syntax and safe header emission does not establish trust, authorization, reachability, or AR-XML validity.

The maintenance surface MAY provide an explicit reachability/validation tool, but such a tool MUST remain separate from Resolver Core resolution semantics.

## 8. Lifecycle administration

Administrative lifecycle mutation MUST follow Resolver Lifecycle 0.1.

Allowed transitions are:

```text
ACTIVE    → SUSPENDED
SUSPENDED → ACTIVE
ACTIVE    → RETIRED
SUSPENDED → RETIRED
```

`RETIRED` is terminal in Lifecycle 0.1.

A lifecycle transition and its material history event SHOULD be committed atomically from the perspective of subsequent public reads.

The administration layer MUST NOT silently reactivate a RETIRED record.

## 9. Maintenance UI minimum capabilities

The first Reference Resolver maintenance surface SHOULD provide at least:

1. UUID registration;
2. Description Location update;
3. lifecycle/status update;
4. search;
5. list view;
6. record detail;
7. history view;
8. resolution test.

Where Manifest is enabled, record detail SHOULD also expose the current Canonical Entity Identity and optional Manifest metadata relevant to maintenance.

The UI MAY provide convenience validation, but convenience results MUST NOT be represented as Trust, authentication, ownership proof, or capability authorization.

## 10. UUID registration

Registration MUST create a new Resolver record with a syntactically valid UUID.

The Reference Resolver MAY generate UUIDs or accept caller-supplied UUIDs according to deployment policy, but it MUST preserve Resolver Core UUID semantics.

Registration SHOULD require the minimum data necessary to establish a conforming current mapping and lifecycle state.

A newly registered record SHOULD enter ACTIVE unless deployment policy explicitly creates it SUSPENDED before publication.

Duplicate registration of the same UUID value MUST NOT create ambiguous multiple current records.

## 11. Description Location update

The maintenance surface MUST allow the current Description Location to change independently of Canonical Entity Identity.

An update MUST NOT require changing `entity.id` merely because the Description Location changes.

After a committed update, fresh origin resolution MUST identify the new current Location. Existing intermediary caches remain governed by their previously advertised HTTP freshness.

The implementation SHOULD record the previous and new values in bounded history for material mapping changes.

## 12. History requirements

The Reference Resolver SHOULD retain bounded history for material administrative changes, including at least:

- lifecycle transitions;
- Description Location changes;
- Canonical Entity Identity changes, if allowed by the deployment's administrative policy;
- Manifest integrity metadata changes;
- other configuration changes that materially affect public Resolver/Manifest behavior.

A retained history event SHOULD include enough information to reconstruct the change conceptually:

```text
event time
record UUID
change type
previous value/state where applicable
new value/state where applicable
administrative actor identifier where available
optional reason/note
```

History is an administrative/operational record. It does not itself establish cryptographic authenticity, ownership, Trust, or L2 authority.

History retention MAY be bounded by count, age, storage policy, or archival policy, but the policy SHOULD be documented.

## 13. Search, list, and detail

Administrative search/list/detail functionality MUST operate on the maintenance surface and MUST NOT alter public Resolver semantics.

Search SHOULD support at least UUID and operationally useful current metadata such as lifecycle state and Description Location text.

Administrative list/detail views MAY expose internal maintenance metadata that is not available on public endpoints.

Public APIs MUST NOT expose administrative history or internal database metadata merely because the Maintenance UI can display it.

## 14. Resolution test

The Maintenance UI SHOULD provide a resolution test for an administrator to inspect the externally observable result that the current record would produce.

The test SHOULD distinguish at least:

```text
Core route result
HTTP status
Location where applicable
cache/CORS-relevant headers
Manifest result where enabled
```

A resolution test SHOULD exercise the public behavior or an equivalent application boundary rather than infer success solely from raw database state.

An optional AR-XML reachability check MUST be labeled separately from Resolver resolution success.

## 15. Administrative authentication and authorization

Administrative mutation surfaces MUST be protected by deployment-appropriate authentication and authorization.

The Anchor UUID MUST NOT authorize mutation.

Baseline L1 public resolution authentication rules do not define Maintenance UI authentication.

The first Reference Resolver does not mandate a specific identity provider, password scheme, WebAuthn mechanism, reverse-proxy authentication method, or external authorization service. The deployment choice MUST nevertheless provide meaningful protection against unauthenticated mutation.

Administrative sessions SHOULD use standard Web security controls appropriate to the deployment, including secure transport, CSRF protection where relevant, session expiry, and privilege checks.

## 16. Public/admin privilege separation

Deployments SHOULD minimize the privileges available to the public request path.

Where practical:

- public Resolver handlers SHOULD have no mutation capability;
- administrative mutation code SHOULD be reachable only through authenticated administrative paths;
- database/file permissions SHOULD follow least privilege;
- public and admin routing SHOULD be explicitly distinguishable;
- administrative errors SHOULD NOT expose sensitive implementation details publicly.

A deployment MAY use separate virtual hosts, processes, filesystem permissions, or reverse-proxy rules, but no specific mechanism is required for protocol conformance.

## 17. Input/output security

All public and administrative input MUST be treated as untrusted.

The Reference Resolver SHOULD apply bounded limits to request sizes, field lengths, list/search pagination, and generated Manifest size.

Public redirect emission MUST prevent response-splitting/header-injection.

Public Manifest JSON MUST be generated as strict JSON and MUST NOT allow duplicate object-member names.

Administrative output SHOULD be context-escaped to prevent UI injection.

SQLite access SHOULD use parameterized queries or equivalent safe data-binding mechanisms when implemented.

These are reference implementation security requirements; they do not alter Resolver protocol meaning.

## 18. Logging and privacy

The Reference Resolver SHOULD maintain operational logs sufficient for troubleshooting and abuse analysis without treating logs as protocol state.

Logs SHOULD minimize unnecessary sensitive data. Query strings, IP addresses, user-agent data, administrator identities, and submitted URLs may have privacy implications and SHOULD follow documented retention/access policy.

Public resolution logging MUST NOT become a requirement that an Entity periodically report its current network address.

## 19. Availability and abuse controls

The implementation SHOULD support deployment-appropriate abuse controls such as request-size limits, rate limiting, connection/time limits, and bounded administrative queries.

Availability controls MUST NOT silently change protocol semantics. For example, overload or temporary backend unavailability should be surfaced using the applicable HTTP failure behavior rather than a fabricated successful mapping.

## 20. Backup, restore, and migration

Because SQLite contains current mappings and administrative history, deployments SHOULD define backup and restore procedures.

Restore procedures SHOULD preserve UUID identity, lifecycle state, current Description Location, and retained history consistency.

Schema migration is an implementation concern. Migration MUST NOT change public Resolver semantics merely because internal storage representation changes.

## 21. Optional publishing/integrity tooling

A Maintenance UI MAY provide an explicit publishing operation that computes or updates Manifest `description.integrity` for a selected AR-XML representation.

Such tooling:

- MUST be separate from the public resolution request path;
- MUST use Frozen Manifest 0.1 byte semantics;
- SHOULD make it clear which representation/location was processed;
- MUST NOT label digest success as Entity authentication, ownership proof, freshness, anti-rollback protection, authorization, or L2.

The Reference Resolver MUST NOT automatically fetch every Description during public resolution to keep integrity metadata current.

## 22. No AR-XML interpretation in Resolver Core

The public Resolver Core path MUST NOT parse AR-XML in order to resolve a UUID.

The Reference Resolver MUST NOT:

- discover capabilities during resolution;
- construct capability endpoints from AR-XML;
- invoke capabilities;
- generate a device management UI;
- resolve a device's current IP address as the Resolver mapping mechanism;
- redirect to a management console as a substitute for Description resolution;
- require periodic device-IP reporting.

Optional administrative diagnostics that fetch AR-XML MUST remain explicitly separate from Core resolution behavior.

## 23. Manifest extensions and vendor metadata

Reference-implementation-specific public Manifest metadata SHOULD use the Frozen Manifest Extension Policy.

Vendor/deployment metadata SHOULD NOT be added as ad-hoc top-level Manifest members when it belongs under `extensions`.

An extension MUST NOT override standard `description.location`, lifecycle, integrity, Core resolution, or Trust semantics.

Administrative-only metadata need not be exposed in Manifest at all.

## 24. Configuration responsibilities

Deployment configuration MAY include:

```text
public base path
admin base path
SQLite database location
cache defaults within allowed profile
logging policy
rate limits
administrative authentication integration
Manifest enable/disable setting
history retention policy
```

Configuration MUST NOT permit a Core-only implementation to silently reinterpret unsupported security levels as L1.

Secrets MUST NOT be committed to repository defaults or exposed through public diagnostic output.

## 25. Implementation handoff

The subsequent implementation task may choose concrete PHP structure and SQLite schema, but SHOULD preserve at least these logical boundaries:

```text
HTTP/public adapter
HTTP/admin adapter
Core resolution service
Manifest representation service
Maintenance service
Persistence/repository boundary
History/audit boundary
Authentication/authorization adapter
```

Implementation acceptance SHOULD include the Frozen Resolver / Manifest Conformance Catalog 0.1 as the protocol baseline and separate tests for authenticated maintenance behavior.

## 26. Non-goals

Reference Resolver Architecture 0.1 does not define:

- L2 authentication protocol;
- ownership-transfer protocol;
- public-key binding or signatures;
- capability execution;
- device configuration protocol;
- dynamic device-IP lookup/reporting architecture;
- concrete database DDL;
- concrete PHP framework or class names;
- Docker/native packaging details;
- a legal patent/FTO conclusion.

## 27. Summary

```text
Public Resolver
    = minimal GET-only resolution

Optional Manifest Endpoint
    = stored Entity-level metadata representation

Maintenance UI
    = authenticated administrative mutation and inspection

SQLite
    = implementation persistence, not protocol semantics

History
    = bounded administrative trace, not Trust
```

The Reference Resolver implementation MUST preserve RELink's central separation:

```text
Resolver Core = minimal resolution
Manifest      = richer metadata
Trust         = downstream/later layer
Runtime       = description consumption and execution
AR-XML        = Entity Interface Description
```
