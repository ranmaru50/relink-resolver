# RELink Reference Resolver Deployment Profiles 0.1

Status: Draft deployment profile  
Version: 0.1  
Scope: Native and container deployment of the first Reference Resolver

## 1. Purpose

This document defines non-code deployment requirements for the first RELink Reference Resolver. It supports two official deployment profiles without making containers part of RELink protocol semantics.

```text
Native profile
Apache + PHP + SQLite

Container profile
Docker image + compose deployment + persistent SQLite storage
```

Both profiles MUST preserve the same externally observable Resolver/Manifest behavior. Deployment topology MUST NOT redefine Resolver Core, Lifecycle, Manifest, Conformance Catalog, Web Runtime integration, or Reference Resolver architecture semantics.

## 2. Governing documents

Deployments MUST conform to the applicable RELink specifications and handoff documents, including:

- Resolver Core 0.1;
- Resolver Lifecycle 0.1;
- Frozen Manifest 0.1 and Extension Policy;
- Frozen Resolver / Manifest Conformance Catalog 0.1;
- Frozen Web Runtime Integration Contract 0.1;
- Reference Resolver Architecture 0.1.

Where deployment convenience conflicts with protocol semantics, protocol semantics govern.

## 3. Deployment invariants

Both official profiles MUST preserve:

```text
Public protocol semantics ≠ deployment topology
SQLite persistence         ≠ container filesystem lifetime
TLS termination            ≠ Resolver trust semantics
Reverse proxy              ≠ Resolver protocol layer
Admin authentication       ≠ public L1 authentication
```

A deployment MAY place TLS termination, reverse proxying, logging, rate limiting, or authentication in surrounding infrastructure, provided externally observable RELink behavior remains conforming.

## 4. Native profile

The native profile uses an operating-system installation of:

```text
Apache HTTP Server
PHP runtime
SQLite support
persistent local filesystem
```

The deployment MUST provide a PHP version supported by the Reference Resolver implementation and required PHP extensions for SQLite/database access, JSON processing, URI/HTTP-safe output, and cryptographic hashing where optional Manifest integrity publishing is enabled.

Exact package names and version floors belong to implementation documentation and MUST NOT become protocol requirements.

Apache MUST route public Resolver resources and administrative resources to the appropriate application boundaries while preserving their distinct authorization requirements.

## 5. Container profile

The container profile is an optional packaging profile. A conforming distribution SHOULD provide:

```text
Dockerfile
compose.yaml
.env.example
```

The container image MAY include Apache and PHP in one application container or use an equivalent internal topology, provided the externally observable behavior and administrative/public separation remain equivalent to the native profile.

Docker, Compose, container networking, image registries, and volume mechanisms are not RELink protocol requirements.

## 6. Behavioral equivalence

Native and container deployments MUST be capable of producing the same protocol-visible results for the same logical Resolver state and configuration.

Equivalent behavior includes at least:

- Resolver status codes and redirects;
- `Location` validation/emission;
- lifecycle behavior;
- cache headers;
- CORS behavior;
- Manifest representation and lifecycle mapping where enabled;
- unsupported method / `l` / `p` behavior;
- public/admin separation.

Container-specific proxying or port mapping MUST NOT alter these semantics.

## 7. Configuration model

Both profiles SHOULD use a common logical configuration model even if their physical configuration mechanisms differ.

Configuration MAY include:

```text
public base path
admin base path
SQLite database path
Manifest enable/disable
history retention policy
logging settings
rate limits
trusted proxy configuration
external/public origin information where required
administrative authentication integration
```

Environment variables MAY be used, especially in the container profile. `.env.example` MUST contain examples/placeholders only and MUST NOT contain production secrets.

Configuration values that affect protocol-visible behavior MUST be documented and SHOULD have equivalent meaning across native and container profiles.

## 8. Persistent data

SQLite data is durable Resolver state and MUST NOT depend on ephemeral process/container filesystem lifetime.

Native deployments MUST place the SQLite database in a persistent, writable location with deployment-appropriate ownership and permissions.

Container deployments MUST use persistent storage for the SQLite database and any other state required to preserve mappings/history across container replacement or restart.

A container image layer MUST NOT be treated as the durable database store.

Temporary/cache files MAY remain ephemeral when loss does not alter authoritative Resolver state.

## 9. SQLite filesystem requirements

The selected persistent storage MUST support filesystem semantics suitable for the SQLite mode used by the implementation.

Deployments SHOULD avoid unsupported or operationally unsafe storage arrangements for SQLite locking/journaling. Network/distributed filesystems MUST NOT be assumed safe merely because they expose a filesystem path.

The implementation/deployment documentation SHOULD state any SQLite journal/WAL, backup, filesystem, and concurrency assumptions that operators must preserve.

These are operational requirements and do not alter public protocol semantics.

## 10. TLS and HTTPS

Public L1 deployment MUST be externally available over HTTPS as required by Resolver Core.

TLS MAY terminate:

- directly in Apache;
- at a deployment-controlled reverse proxy/load balancer;
- at another HTTPS ingress component.

If TLS terminates before the application process, the deployment MUST preserve enough trusted request context for the application to generate correct externally visible behavior where origin/scheme awareness is needed.

The application MUST NOT blindly trust client-supplied forwarding headers. Trusted proxy/forwarded-header processing MUST be explicitly configured when used.

## 11. HSTS and secure transport profile

Production public HTTPS deployments SHOULD support HSTS consistent with Resolver Core deployment guidance.

HSTS is a deployment/browser security mechanism and MUST NOT be represented as Resolver authentication, Manifest authenticity, Entity authentication, or L2 achievement.

Administrative access MUST use secure transport in production deployments.

## 12. Reverse proxy boundary

A reverse proxy MAY provide TLS termination, request limits, rate limiting, compression, access logging, or administrative access controls.

Proxy behavior MUST NOT:

- rewrite valid Resolver status semantics into different success semantics;
- transform SUSPENDED/unknown behavior in a way that defeats required non-distinction;
- rewrite `303` Description Location into a management/application URL;
- bypass public/admin route separation;
- introduce HTTPS-to-HTTP externally visible downgrade for L1;
- expose internal admin services publicly by default.

Proxy-generated error responses SHOULD preserve meaningful temporary/unavailable failure classification where practical.

## 13. Public/admin exposure

Both profiles MUST preserve distinct public and maintenance surfaces.

The public Resolver path MUST remain GET-only/read-only according to Resolver Core.

Administrative mutation routes MUST require deployment-appropriate authentication and authorization.

The container profile MUST NOT publish an administrative port or route without the same protection expected in the native profile merely for deployment convenience.

Operators MAY restrict `/admin/` by network location, separate virtual host, reverse proxy policy, VPN, or equivalent controls in addition to application authentication.

## 14. File and process permissions

Deployments SHOULD follow least privilege.

At minimum:

- the public web process SHOULD have only the filesystem/database permissions needed for its intended operation;
- secrets/configuration SHOULD not be publicly readable or served as static files;
- SQLite files, backups, and history data SHOULD not be directly downloadable through the public document root;
- administrative credentials/session material MUST NOT be stored in publicly served paths.

Where implementation architecture permits separate read/write privileges, deployments SHOULD use them.

## 15. Logging

Both profiles SHOULD expose operational logs sufficient for troubleshooting, security review, and abuse analysis.

Container logging MAY use stdout/stderr and/or mounted log storage. Native logging MAY use Apache/system/application logging facilities.

Log destination differences MUST NOT change protocol semantics.

Retention and access policy SHOULD be documented. Logs SHOULD minimize unnecessary sensitive data and MUST NOT become authoritative Resolver state.

## 16. Health and operational diagnostics

A deployment MAY provide health/readiness diagnostics outside the RELink public protocol surface.

Such diagnostics MUST NOT be confused with Resolver Core resources, Manifest resources, or Entity health semantics.

Health endpoints SHOULD avoid exposing sensitive configuration, database contents, administrative identity information, or secrets.

## 17. Backup and restore

Both profiles SHOULD document backup/restore procedures for persistent Resolver data.

Backups SHOULD preserve at least:

```text
Anchor UUID mappings
current Description Location
lifecycle state
Manifest metadata where enabled
retained history
```

Container replacement, image upgrade, or compose recreation MUST NOT destroy durable Resolver state when the documented persistence model is followed.

Restore MUST preserve semantic identity and lifecycle consistency rather than generating replacement UUIDs for existing records.

## 18. Upgrade and migration

Application/schema upgrades MUST be designed so that migration of internal storage does not change public Resolver semantics.

Before an upgrade that changes persistence representation, deployments SHOULD create a recoverable backup.

Migration SHOULD be explicit, bounded, and failure-aware. A partially completed migration MUST NOT silently serve fabricated or inconsistent successful mappings.

Native and container packages SHOULD use the same logical data/schema version model so that moving between profiles does not require protocol-level changes.

## 19. Native ↔ container portability

The project SHOULD make it possible to move an existing Reference Resolver dataset between supported native and container profiles through documented backup/restore or migration procedures.

Profile migration MUST preserve:

- UUID identity;
- lifecycle state;
- current mapping;
- Canonical Entity Identity where used;
- integrity metadata where used;
- retained administrative history to the extent supported by the backup policy.

A deployment profile change MUST NOT require new Anchor identifiers merely because packaging changed.

## 20. Secrets

Secrets MUST NOT be committed in repository defaults, `Dockerfile`, `compose.yaml`, or `.env.example`.

Deployment secrets MAY include administrative authentication secrets, session keys, proxy-auth integration secrets, or other implementation-specific credentials.

Secret storage mechanism is deployment-specific. Container secrets, environment injection, protected files, external secret stores, or native OS mechanisms MAY be used.

The public Anchor UUID is not a secret.

## 21. Network exposure

Container networking is an implementation detail. Internal service/container ports MAY differ from public ports.

Only intended public interfaces SHOULD be externally published.

SQLite itself MUST NOT require a network-exposed database service.

No deployment profile may introduce a requirement for Entities to periodically report current IP addresses or for the Resolver to map UUIDs to device control endpoints.

## 22. Resource limits

Both profiles SHOULD support operational controls for request size, concurrency, execution time, memory, search/list pagination, and rate limits.

Resource limits SHOULD be configured so ordinary conforming Resolver/Manifest requests remain usable while abusive/unbounded inputs are constrained.

Container resource limits MAY supplement application/proxy limits but MUST NOT become protocol semantics.

## 23. Time and clocks

Deployment clocks SHOULD be reasonably synchronized because administrative history timestamps, logs, cache behavior, and operational diagnostics depend on time consistency.

Clock synchronization does not provide Trust, freshness proof, anti-rollback protection, or L2 semantics.

## 24. Distribution artifacts handoff

The later implementation task SHOULD produce, for the container profile:

```text
Dockerfile
compose.yaml
.env.example
persistent-volume configuration/documentation
startup/upgrade documentation
```

and for the native profile:

```text
Apache configuration example
dependency/package requirements
filesystem layout guidance
permissions guidance
startup/upgrade documentation
```

These artifacts MUST implement this profile rather than redefine protocol behavior.

## 25. Deployment acceptance expectations

Implementation validation SHOULD include the Frozen Resolver / Manifest Conformance Catalog 0.1 against both profiles where technically applicable.

The same protocol-facing test cases SHOULD pass against native and container deployments.

Deployment-specific acceptance SHOULD additionally verify:

```text
persistent state survives restart/redeployment
admin surface remains protected
public/admin routes remain distinct
HTTPS/proxy configuration preserves L1 semantics
backup/restore preserves UUID/state/history
profile migration preserves logical Resolver state
```

## 26. Non-goals

Deployment Profiles 0.1 does not define:

- mandatory Docker usage;
- Kubernetes or orchestrator-specific architecture;
- a particular Linux distribution;
- exact package/version pinning;
- a specific TLS certificate authority;
- a specific reverse proxy product;
- L2/Trust authentication;
- device control or capability execution;
- database DDL;
- production implementation code;
- patent/FTO clearance.

## 27. Summary

```text
Native
Apache + PHP + SQLite

Container
Docker-packaged Reference Resolver + persistent SQLite data

Both
same protocol semantics
same logical configuration responsibilities
same durable Resolver state
same public/admin separation
```

Deployment changes packaging and operations, not RELink identity/resolution semantics.