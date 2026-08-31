# RELink Resolver

RELink Resolver is the resolution layer for RELink (Real Entity Link), an experimental architecture for making physical and real-world entities addressable, discoverable, interactable, and operable using existing Web infrastructure.

This repository is the home of the RELink Resolver and Manifest specifications, the future reference resolver, and resolver interoperability test definitions.

## Status

Early design phase.

The current work focuses on specification, responsibility boundaries, state transitions, and HTTP semantics before implementation.

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

## Manifest

Manifest is designed as a separate specification from Resolver Core.

A minimal L1 resolver may redirect directly to AR-XML without requiring a Manifest.

A richer deployment may expose a Manifest containing Entity-level resolution metadata such as:

```text
Canonical Entity Identity
Current AR-XML Location
Lifecycle metadata
Version information
Future extension metadata
```

Manifest must not turn Resolver Core into an execution, management, or trust service.

## Trust and Security

Trust, authentication, signatures, authenticated mutation, ownership transfer, and related mechanisms are outside Resolver Core 0.1.

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
- Manifest 0.1 specification
- Reference Resolver design and implementation
- Resolver interoperability test cases
- RELink Testbed integration definitions
- Web Runtime integration notes
- Native deployment profile
- Container deployment profile

Implementation work will follow only after the relevant protocol and responsibility boundaries are sufficiently defined.

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
