# RELink Resolver

RELink Resolver is the resolution layer for RELink (Real Entity Link), an experimental architecture for making physical and real-world entities addressable, discoverable, interactable, and operable using existing Web infrastructure.

This repository is the home of the RELink Resolver and Manifest specifications, the future reference resolver, and resolver interoperability test definitions.

English | [日本語](README.ja.md)

## Status

Specification-first design phase transitioning into reference implementation.

RELink Manifest 0.1, its Extension Policy, and its accompanying JSON Schema were **Frozen on 2026-08-31**. Resolver Core 0.1, Resolver Lifecycle 0.1, the Resolver / Manifest Conformance Catalog 0.1, Web Runtime Integration Contract 0.1, Reference Resolver Architecture 0.1, and Reference Resolver Deployment Profiles 0.1 were **Frozen on 2026-09-01** as stable implementation/test handoff baselines.

For frozen Resolver Core 0.1, editorial/non-semantic errata may be corrected within 0.1; changes to L1 request semantics, `l`/`p` downgrade behavior, HTTP status or processing-order semantics, Description Location validation, HTTPS/network-policy semantics, lifecycle mappings, Manifest independence, public/admin responsibility boundaries, Trust/L2 exclusions, or Core conformance expectations require a later Core version or separately versioned profile.

For frozen Resolver Lifecycle 0.1, editorial/non-semantic errata may be corrected within 0.1; changes to lifecycle states, permitted transitions, RETIRED terminal semantics, permitted-transition support requirements, administrative failure semantics, same-state no-op/history semantics, initial-registration semantics, public lifecycle mapping/non-distinction, cache semantics, Manifest lifecycle mapping, or conformance-derivation semantics require a later Lifecycle version or separately versioned profile.

For frozen Manifest 0.1, editorial/non-semantic errata may be corrected within 0.1; changes to standard members, wire semantics, integrity semantics, extension compatibility, or security/trust semantics require a later Manifest version or separately versioned profile.

For frozen Conformance Catalog 0.1, editorial/non-semantic errata may be corrected within 0.1; changes to conformance targets, result semantics, case identifiers, normative case expectations, baseline/optional classification, or security/network-policy semantics require a later catalog version or separately versioned profile.

For frozen Web Runtime Integration Contract 0.1, editorial/non-semantic errata may be corrected within 0.1; changes to final-URL semantics, retrieval ordering, Manifest association/integrity semantics, network-policy semantics, error boundaries, L0/L1 classification, or RT handoff expectations require a later contract version or separately versioned profile.

For frozen Reference Resolver Architecture 0.1, editorial/non-semantic errata may be corrected within 0.1; changes to public/admin responsibility boundaries, administrative transport/authentication/authorization semantics, CSRF requirements, outbound-fetch/SSRF policy semantics, integrity-publishing consistency rules, database/input/output security requirements, persistence/private-file boundaries, or implementation security acceptance expectations require a later architecture version or separately versioned profile.

For frozen Reference Resolver Deployment Profiles 0.1, editorial/non-semantic errata may be corrected within 0.1; changes to deployment invariants, native/container equivalence, persistence/private-file semantics, trusted-proxy handling, administrative outbound-network policy, backup/restore semantics, security boundaries, or deployment acceptance expectations require a later deployment-profile version or separately versioned profile.

## Specifications

- RELink Resolver Core 0.1 — **Frozen 2026-09-01**
  - [English](docs/specs/resolver-core-0.1.md)
  - [日本語](docs/specs/resolver-core-0.1.ja.md)
- RELink Resolver Lifecycle 0.1 — **Frozen 2026-09-01**
  - [English](docs/specs/resolver-lifecycle-0.1.md)
  - [日本語](docs/specs/resolver-lifecycle-0.1.ja.md)
- RELink Resolver / Manifest Conformance Catalog 0.1 — **Frozen 2026-09-01**
  - [English](docs/specs/resolver-manifest-conformance-0.1.md)
  - [日本語](docs/specs/resolver-manifest-conformance-0.1.ja.md)
- RELink Web Runtime Integration Contract 0.1 — **Frozen 2026-09-01**
  - [English](docs/specs/web-runtime-integration-0.1.md)
  - [日本語](docs/specs/web-runtime-integration-0.1.ja.md)
- RELink Reference Resolver Architecture 0.1 — **Frozen 2026-09-01**
  - [English](docs/specs/reference-resolver-architecture-0.1.md)
  - [日本語](docs/specs/reference-resolver-architecture-0.1.ja.md)
- RELink Reference Resolver Deployment Profiles 0.1 — **Frozen 2026-09-01**
  - [English](docs/specs/reference-resolver-deployment-profiles-0.1.md)
  - [日本語](docs/specs/reference-resolver-deployment-profiles-0.1.ja.md)
- RELink Manifest 0.1 — **Frozen 2026-08-31**
  - [English](docs/specs/manifest-0.1.md)
  - [日本語](docs/specs/manifest-0.1.ja.md)
  - [JSON Schema](docs/specs/manifest-0.1.schema.json)
- RELink Manifest 0.1 Extension Policy — **Frozen 2026-08-31**
  - [English](docs/specs/manifest-0.1-extension-policy.md)
  - [日本語](docs/specs/manifest-0.1-extension-policy.ja.md)

The English Frozen Resolver Core, Resolver Lifecycle, Manifest, Conformance Catalog, Web Runtime Integration Contract, Reference Resolver Architecture, and Reference Resolver Deployment Profiles documents are the normative source texts. The Japanese documents are official project translations; if an interpretation differs, the English Frozen text governs conformance.

## Architecture

```text
Physical Anchor
    ↓
Resolver
    ↓
Canonical Entity Identity
    ↓
Current Description Location
    ↓
AR-XML
    ↓
Runtime
    ↓
Capability
```

RELink keeps the following concerns separate:

```text
Entity      ≠ Location
Capability  ≠ Interface
Resolution  ≠ Authentication
Description ≠ Execution
```

## Resolver Core

Resolver Core is intentionally minimal.

For the L1 baseline, its primary responsibility is:

```text
Anchor UUID
    ↓
Current AR-XML description location
```

The expected baseline interaction is:

```text
GET /{resolver-service}/{uuid}
    ↓
UUID lookup
    ↓
303 See Other
Location: https://...
```

L1 is public, GET-only, read-only, and HTTPS-based. Unsupported requested `l` values and reserved `p` without defining level semantics fail closed rather than silently falling back to L1.

Resolver Core does not interpret or fetch AR-XML and does not execute Entity capabilities.

Frozen Manifest 0.1 does not enlarge Resolver Core responsibility: normal ACTIVE L1 resolution must remain possible without retrieving or parsing a Manifest.

## Lifecycle

Resolver Lifecycle 0.1 defines the implementation-independent state-transition model for Resolver records while preserving Resolver Core public behavior.

```text
ACTIVE    → SUSPENDED
SUSPENDED → ACTIVE
ACTIVE    → RETIRED
SUSPENDED → RETIRED
```

`RETIRED` is terminal in Lifecycle 0.1. Public L1 behavior remains:

```text
ACTIVE    → 303
SUSPENDED → 404
RETIRED   → 410
```

Lifecycle transition reasons and bounded history are administrative metadata. Lifecycle does not define authentication, authorization, ownership transfer, Trust, or capability execution.

## Conformance Catalog

The Frozen Resolver / Manifest Conformance Catalog 0.1 defines implementation-independent protocol test cases for Resolver Core, Lifecycle, Frozen Manifest 0.1, optional integrity verification, extension compatibility, transport, cache/CORS, network-policy boundaries, and resource-boundary behavior.

The catalog belongs in this repository because it defines protocol expectations. Executable tests, fixtures, runners, CI integration, and reports are delegated to `relink-testbed`.

```text
relink-resolver
= conformance definition

relink-testbed
= executable conformance implementation
```

## Web Runtime integration

The Frozen Web Runtime Integration Contract 0.1 defines the handoff from ordinary Resolver/Web dereferencing to AR-XML Runtime processing.

Its central rules include:

```text
Requested URL ≠ necessarily AR-XML Document URL
Final AR-XML response URL = AR-XML document base URL
Verified representation bytes = parsed representation bytes
HTTP terminal failure ≠ AR-XML parse failure
```

Direct AR-XML loading remains the L0/direct path; Resolver-mediated loading is the L1 path. Resolver-specific behavior must not leak into AR-XML parser or capability invocation semantics.

## Reference Resolver architecture

Frozen Reference Resolver Architecture 0.1 defines the non-code requirements for the first Apache/PHP/SQLite implementation.

```text
Public
GET /relink/{uuid}
GET /relink/{uuid}/manifest (optional)
        ↓
read-only Resolver/Manifest services
        ↓
SQLite

Admin
/admin/
        ↓
authentication + authorization
        ↓
maintenance services + history
        ↓
SQLite
```

The public and administrative surfaces remain separate in responsibility even when deployed in one application. The architecture defines UUID registration, Description Location/lifecycle maintenance, search/list/detail/history, resolution testing, persistence boundaries, bounded history, administrative Web security, outbound-fetch/SSRF controls, integrity-publishing consistency, and implementation security acceptance without fixing concrete PHP classes or SQLite DDL.

## Deployment profiles

Frozen Reference Resolver Deployment Profiles 0.1 defines equivalent Native and Docker packaging/operations profiles without changing RELink protocol semantics.

```text
Native
Apache + PHP + SQLite

Container
Docker-packaged Reference Resolver + persistent SQLite storage
```

Both profiles preserve the same protocol-visible behavior, public/admin separation, durable Resolver state, configuration responsibilities, TLS/proxy boundaries, private-file boundaries, outbound-network policy, SQLite-consistent backup/restore expectations, and migration semantics. Docker remains optional and is not a protocol requirement.

## Manifest

Manifest is a separate specification from Resolver Core.

A minimal L1 resolver may redirect directly to AR-XML without requiring a Manifest.

A richer deployment may expose a Manifest containing Entity-level resolution metadata such as:

```text
Anchor UUID
Canonical Entity Identity
Current AR-XML Location
Optional Description integrity metadata
Lifecycle metadata
Version information
Extensions
```

The default Manifest retrieval resource is separate from Resolver Core:

```text
GET /{resolver-service}/{uuid}/manifest
```

Manifest must not turn Resolver Core into an execution, management, or trust service.

Manifest 0.1 uses strict JSON for the wire representation, forbids duplicate object-member names, keeps vendor/profile metadata under the `extensions` namespace, and optionally supports AR-XML content pinning through `description.integrity`. Integrity verification is not authentication, freshness, anti-rollback, authorization, or L2.

## Trust and Security

Trust, authentication, signatures, authenticated mutation, ownership transfer, freshness/anti-rollback mechanisms, and related authority mechanisms are outside Resolver Core 0.1 and Manifest 0.1.

They are expected to be designed as later layers without redefining the L1 identity and resolution model.

Administrative authentication for the Reference Resolver protects the maintenance surface but does not redefine public L1 authentication semantics or establish RELink L2.

## Non-goals for Resolver Core

Resolver Core does not:

- resolve a device's current IP address
- construct an operational or management UI URL
- redirect directly to a management console
- configure a device
- invoke a capability
- require an Entity to periodically report its network address
- establish an ownership or trust chain
- interpret AR-XML capability semantics

## Planned deliverables

This repository contains the frozen 0.1 specification baseline and is expected to add:

- Reference Resolver implementation
- RELink Testbed integration definitions

## Related projects

- RELink project site: https://ranmaru50.github.io/relink-site/
- RELink Web Runtime: https://github.com/ranmaru50/relink-web-runtime
- RELink Testbed: https://github.com/ranmaru50/relink-testbed

## Design principle

The intended separation is:

```text
Resolver Core = minimal resolution
Manifest      = richer metadata
Trust         = later security / authority layer
Runtime       = consumer-facing interpretation and execution
```

The project should preserve this separation as implementation begins.

## Reference implementation

The first Apache/PHP/SQLite reference implementation is provided in `public/`, `src/`, `migrations/`, and `deploy/`. Setup, security boundaries, Native/Container profiles, and backup/restore guidance are documented in [docs/implementation.md](docs/implementation.md). HTTPS Native/Container acceptance deployment, acceptance-only HTTP security header fixtures, and Testbed handoff are documented in [docs/acceptance-deployments.md](docs/acceptance-deployments.md). PHPUnit unit and SQLite integration tests are described in [docs/testing.md](docs/testing.md). Framework and commercial-service integration is documented in [docs/integration.md](docs/integration.md); the Resolver Engine remains independent of Apache, Plain PHP UI, SQLite, and framework-specific authentication/session APIs.
