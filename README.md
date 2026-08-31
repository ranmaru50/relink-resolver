# RELink Resolver

RELink Resolver is the resolution layer for RELink (Real Entity Link), an experimental architecture for making physical and real-world entities addressable, discoverable, interactable, and operable using existing Web infrastructure.

This repository is the home of the RELink Resolver and Manifest specifications, the future reference resolver, and resolver interoperability test definitions.

English | [日本語](README.ja.md)

## Status

Specification-first design phase.

Resolver Core 0.1, Resolver Lifecycle 0.1, and the Resolver / Manifest Conformance Catalog 0.1 remain draft specifications. RELink Manifest 0.1, its Extension Policy, and its accompanying JSON Schema were **Frozen on 2026-08-31** and form the stable Manifest 0.1 baseline for implementation and conformance work.

For frozen Manifest 0.1, editorial/non-semantic errata may be corrected within 0.1; changes to standard members, wire semantics, integrity semantics, extension compatibility, or security/trust semantics require a later Manifest version or separately versioned profile.

## Specifications

- RELink Resolver Core 0.1 — Draft
  - [English](docs/specs/resolver-core-0.1.md)
  - [日本語](docs/specs/resolver-core-0.1.ja.md)
- RELink Resolver Lifecycle 0.1 — Draft
  - [English](docs/specs/resolver-lifecycle-0.1.md)
  - [日本語](docs/specs/resolver-lifecycle-0.1.ja.md)
- RELink Resolver / Manifest Conformance Catalog 0.1 — Draft
  - [English](docs/specs/resolver-manifest-conformance-0.1.md)
  - [日本語](docs/specs/resolver-manifest-conformance-0.1.ja.md)
- RELink Manifest 0.1 — **Frozen 2026-08-31**
  - [English](docs/specs/manifest-0.1.md)
  - [日本語](docs/specs/manifest-0.1.ja.md)
  - [JSON Schema](docs/specs/manifest-0.1.schema.json)
- RELink Manifest 0.1 Extension Policy — **Frozen 2026-08-31**
  - [English](docs/specs/manifest-0.1-extension-policy.md)
  - [日本語](docs/specs/manifest-0.1-extension-policy.ja.md)

The English Frozen Manifest documents are the normative source texts. The Japanese documents are official project translations; if an interpretation differs, the English Frozen text governs Manifest 0.1 conformance.

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
Entity     ≠ Location
Capability ≠ Interface
Resolution ≠ Authentication
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

L1 is intended to be public, GET-only, read-only, and HTTPS-based.

Resolver Core does not interpret AR-XML and does not execute Entity capabilities.

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

The Resolver / Manifest Conformance Catalog 0.1 defines implementation-independent protocol test cases for Resolver Core, Lifecycle, Frozen Manifest 0.1, optional integrity verification, extension compatibility, transport, cache/CORS, and resource-boundary behavior.

The catalog belongs in this repository because it defines protocol expectations. Executable tests, fixtures, runners, CI integration, and reports are delegated to `relink-testbed`.

```text
relink-resolver
= conformance definition

relink-testbed
= executable conformance implementation
```

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

This repository is expected to contain:

- Resolver Core 0.1 specification
- Resolver Lifecycle 0.1 specification
- Resolver / Manifest Conformance Catalog 0.1
- Frozen Manifest 0.1 specification set
- Reference Resolver design and implementation
- RELink Testbed integration definitions
- Web Runtime integration notes
- Native deployment profile
- Container deployment profile

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

The project should preserve this separation as the specification evolves.
