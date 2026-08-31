# RELink Manifest 0.1

Status: Draft specification  
Version: 0.1  
Scope: Optional Entity-level resolution metadata

## 1. Purpose

RELink Manifest defines a compact, machine-readable metadata representation associated with a RELink Anchor and Physical Entity.

Manifest exists to carry metadata that is richer than Resolver Core but does not belong in AR-XML capability descriptions, Runtime execution, or Trust processing.

The architectural separation is:

```text
Resolver Core = minimal resolution
Manifest      = richer Entity-level resolution metadata
Trust         = authentication / authenticity / authority
Runtime       = description consumption and execution
AR-XML        = Entity Interface Description
```

Manifest 0.1 is OPTIONAL for L1 resolution.

A conforming Resolver Core deployment MUST be able to resolve an ACTIVE Anchor directly to the current AR-XML Description Location without retrieving or parsing a Manifest.

```text
Anchor
↓
Resolver Core
↓ 303
AR-XML
```

Manifest availability, retrieval, parsing, validation, or failure MUST NOT be a prerequisite for ordinary Resolver Core 0.1 L1 resolution.

## 2. Requirements language

The key words **MUST**, **MUST NOT**, **REQUIRED**, **SHALL**, **SHALL NOT**, **SHOULD**, **SHOULD NOT**, **RECOMMENDED**, **NOT RECOMMENDED**, **MAY**, and **OPTIONAL** in this document are to be interpreted as described in BCP 14 (RFC 2119 and RFC 8174) when, and only when, they appear in all capitals.

## 3. Terminology

### 3.1 Anchor UUID

The RFC 9562 UUID used as the Resolver lookup key.

The Anchor UUID identifies a RELink resolution record. It is not a credential and MUST NOT be treated as authentication or authorization material.

### 3.2 Canonical Entity Identity

A stable, location-independent URI identifying the Entity described by the Manifest.

Canonical Entity Identity answers:

```text
What Entity is this?
```

It does not answer:

```text
Where is its current AR-XML document?
```

### 3.3 Description Location

The current HTTPS URL from which the Entity's AR-XML description is expected to be retrieved.

Description Location answers:

```text
Where is the current description?
```

It is mutable without changing Canonical Entity Identity.

### 3.4 Manifest Resource

An HTTP resource whose representation conforms to this specification.

A Manifest Resource is distinct from:

- the Physical Entity;
- the Resolver Resource;
- the Canonical Entity Identity;
- the AR-XML document.

## 4. Core invariants

A conforming Manifest implementation MUST preserve:

```text
Entity Identity ≠ Resolver URL
Entity Identity ≠ Description Location
Resolver Core   ≠ Manifest
Manifest        ≠ Trust
Manifest        ≠ AR-XML
Description     ≠ Execution
```

Changing `description.location` MUST NOT require changing `entity.id`.

Changing the Resolver deployment URL MUST NOT require changing `entity.id`.

Manifest MUST NOT contain executable capability semantics that belong in AR-XML.

Optional integrity or operational-hardening features defined by this specification MUST NOT be interpreted as authentication, authority proof, or evidence that a higher RELink security level has been achieved.

## 5. JSON representation

Manifest 0.1 is a JSON object and its published/wire representation MUST conform to standard JSON syntax.

JSON5 and other JSON-compatible authoring syntaxes are not conforming Manifest 0.1 wire representations. In particular, a published Manifest MUST NOT require support for JSON5-only syntax such as comments, trailing commas, single-quoted strings, or unquoted object-member names.

Implementations MAY accept JSON5, YAML, or other implementation-defined local authoring formats before producing a conforming Manifest, provided that the publicly retrieved Manifest representation is serialized as conforming JSON.

```text
Authoring format
= implementation-defined

Published Manifest representation
= strict JSON
```

A consumer claiming baseline Manifest 0.1 conformance is required to parse conforming JSON only and MUST NOT be required to implement JSON5 or another authoring syntax.

### 5.1 Duplicate object-member names

A conforming Manifest 0.1 wire representation MUST NOT contain duplicate object-member names within the same JSON object.

This prohibition applies to every JSON object in the representation, including the top-level object, `anchor`, `entity`, `description`, `description.integrity`, `lifecycle`, `extensions`, and objects nested inside extensions.

A consumer MUST reject a Manifest that contains duplicate object-member names. A consumer MUST NOT resolve such ambiguity by applying first-member-wins, last-member-wins, merge, overwrite, or implementation-specific behavior.

This rule exists to prevent parser differential between validators, Runtimes, administrative tools, and future Trust processing.

The minimal conforming representation is:

```json
{
  "manifestVersion": "0.1",
  "anchor": {
    "id": "550e8400-e29b-41d4-a716-446655440000"
  },
  "entity": {
    "id": "https://identity.example/entities/12345"
  },
  "description": {
    "location": "https://entity.example/arxml/entity.xml"
  },
  "lifecycle": {
    "status": "active"
  }
}
```

The following top-level members are REQUIRED:

- `manifestVersion`
- `anchor`
- `entity`
- `description`
- `lifecycle`

An optional `extensions` object MAY be present.

The optional members defined in this specification, including `description.mediaType` and `description.integrity`, MUST NOT become prerequisites for baseline Manifest 0.1 or Resolver Core 0.1 interoperability.

## 6. `manifestVersion`

`manifestVersion` MUST be the JSON string `"0.1"`.

A consumer implementing Manifest 0.1 MUST NOT interpret a Manifest with another `manifestVersion` value as if it were Manifest 0.1.

Manifest 0.1 does not use Semantic Versioning semantics.

Future specifications may define compatibility relationships between later versions, but Manifest 0.1 consumers MUST fail validation for an unsupported `manifestVersion` rather than guessing compatibility.

Unknown non-critical members MAY be ignored as specified in §12, but an unsupported `manifestVersion` is not an ignorable extension.

## 7. `anchor`

`anchor` MUST be an object containing `id`.

`anchor.id`:

- MUST be an RFC 9562 UUID textual representation;
- MUST identify the Resolver record with which the Manifest is associated;
- SHOULD be serialized using lowercase hexadecimal text;
- MUST be treated as an opaque identifier;
- MUST NOT be treated as a password, bearer credential, access token, capability token, authentication proof, or authorization proof.

When a Manifest is retrieved through the deterministic Manifest endpoint for an Anchor UUID, the response representation's `anchor.id` MUST identify the same UUID value as the UUID in the request path.

A mismatch MUST be treated as an invalid Manifest.

Anchor-path consistency is a consistency check only. It does not authenticate the Manifest, the Resolver authority, the Physical Entity, or the party that supplied the representation.

## 8. `entity`

`entity` MUST be an object containing `id`.

`entity.id` is the Canonical Entity Identity.

`entity.id`:

- MUST be an absolute URI;
- MUST be stable across ordinary Description Location changes;
- MUST NOT be the current AR-XML Description Location merely by convention;
- MUST NOT be the Resolver Resource URL merely by convention;
- MUST NOT be interpreted as an operational endpoint unless another specification explicitly defines such semantics;
- MUST be treated as untrusted identifier data unless another specification defines verification semantics.

`entity.id` is an identifier, not a dereference instruction.

A consumer MUST NOT dereference `entity.id` merely because its URI scheme is dereferenceable. Any dereference behavior requires an independent specification or explicit consumer policy defining why dereference is appropriate and what security controls apply.

Manifest 0.1 does not require a particular URI scheme for Canonical Entity Identity.

In particular, Manifest 0.1 does not require `urn:uuid:` and does not require the Canonical Entity Identity to be derived from the Anchor UUID.

This allows deployments to support multiple Anchors referring to one Entity, migration between Resolver services, and future identity models without changing Manifest 0.1 field semantics.

## 9. `description`

`description` MUST be an object containing `location`.

`description.location`:

- MUST be a syntactically valid absolute URI;
- MUST use the `https` scheme for Manifest 0.1 L1 use;
- MUST identify the current AR-XML Description Location;
- MAY change while `entity.id` remains unchanged;
- MUST be treated as untrusted network input by consumers;
- MUST NOT imply that the target is safe, authenticated, authorized, public, or non-local.

If an ACTIVE Manifest is generated from the same Resolver record used by Resolver Core, `description.location` MUST represent the same current Description Location that Resolver Core would emit for that record at the same logical state.

This Resolver/Manifest consistency requirement does not authenticate the Manifest or establish that either representation was supplied by the intended authority.

Manifest generation MUST NOT require the Resolver to fetch or parse the AR-XML document.

### 9.1 `description.mediaType`

An implementation MAY include `description.mediaType` if it has a known media type for the description representation.

Manifest 0.1 does not define or register a dedicated AR-XML media type and therefore does not require a specific `description.mediaType` value.

Consumers MUST NOT treat `description.mediaType` as a substitute for validating the retrieved representation according to the applicable AR-XML specification.

### 9.2 `description.integrity`

A Manifest MAY include optional content-integrity metadata for a Description whose representation is suitable for content pinning.

Example:

```json
{
  "description": {
    "location": "https://entity.example/arxml/entity.xml",
    "integrity": {
      "algorithm": "sha-256",
      "digest": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
    }
  }
}
```

When present, `description.integrity` MUST be an object containing both `algorithm` and `digest`.

`description.integrity` is OPTIONAL. Producers SHOULD omit it when the AR-XML representation is intentionally dynamic, personalized, generated per request, or otherwise unsuitable for stable content pinning.

The field has the following limited semantics:

```text
description.location
= where the current description is retrieved

description.integrity
= which representation content the Manifest expects
```

It does not identify who authored, approved, owns, or is authorized to modify the AR-XML document.

#### 9.2.1 `algorithm`

`algorithm` MUST be a non-empty lowercase ASCII identifier naming the digest algorithm.

Manifest 0.1 defines `sha-256` as the initial interoperable algorithm identifier and RECOMMENDS it when a producer chooses to publish integrity metadata.

Manifest 0.1 does not prohibit later specifications or profiles from defining additional algorithms. A Manifest 0.1 consumer is not required to implement every future algorithm identifier.

No algorithm identifier, including `sha-256`, is an authentication mechanism or security-level indicator.

#### 9.2.2 `digest`

`digest` MUST contain the lowercase hexadecimal encoding of the digest bytes produced by `algorithm`.

For `sha-256`, `digest` MUST contain exactly 64 lowercase hexadecimal characters.

The digest applies to the octets of the final successfully retrieved Description representation after completion of the redirect chain and after HTTP content-coding processing performed by the consumer's HTTP/fetch stack, but before character decoding, XML parsing, or application-level normalization.

Conceptually:

```text
HTTP request
↓
redirect processing
↓
final successful response
↓
HTTP content-coding processing
↓
representation body octets  ← digest input
↓
character decoding
↓
AR-XML parsing
```

A redirect response body preceding the final Description response MUST NOT be used as the digest input.

The digest definition MUST NOT require XML canonicalization. Transfer framing and encoded wire bytes that are removed by the HTTP stack before exposing the final representation body are not the digest input.

#### 9.2.3 Consumer behavior

A baseline Manifest 0.1 consumer that does not implement `description.integrity` MAY ignore the field and remains conforming to Manifest 0.1.

A consumer that claims support for Manifest 0.1 integrity verification:

- MUST NOT report integrity as verified when it does not recognize or implement the declared `algorithm`;
- MUST compute the declared digest over the final representation body octets defined in §9.2.2 before character decoding or AR-XML parsing when verification is enabled;
- MUST treat a digest mismatch as an integrity verification failure;
- MUST NOT continue AR-XML capability discovery or invocation on the mismatching representation when its policy requires integrity verification;
- MAY treat an unsupported algorithm as unverifiable rather than invalidating the entire Manifest, according to local policy.

The presence or successful verification of `description.integrity` MUST NOT be treated as:

- Physical Entity authentication;
- owner authentication;
- Resolver authentication;
- Manifest authenticity proof;
- authorization;
- proof of a higher RELink security level;
- proof of freshness;
- replay protection;
- rollback protection;
- a substitute for future L2 or Trust semantics.

Integrity verification does not prevent replay or rollback to an older Manifest/Description pair whose bytes still match the digest declared by that older Manifest.

A producer or administrative system MAY calculate and register the digest when publishing the AR-XML representation. Resolver Core and public Manifest retrieval MUST NOT be required to fetch AR-XML in order to calculate, refresh, or verify the digest.

## 10. `lifecycle`

`lifecycle` MUST be an object containing `status`.

The allowed values are:

```text
active
suspended
retired
```

These values correspond to Resolver Core lifecycle states:

```text
ACTIVE    ↔ active
SUSPENDED ↔ suspended
RETIRED   ↔ retired
```

`lifecycle.status` is descriptive metadata. It does not authorize a transition and does not identify the actor or authority responsible for a transition.

Reasons, timestamps, actors, audit history, ownership state, and transition authorization are outside the required Manifest 0.1 model.

A later extension MAY add such metadata without changing the meaning of `lifecycle.status`.

The JSON model permits all three lifecycle values because Manifest representations may also be used in administrative export, archival storage, testing, or future distribution profiles. Under the public L1 retrieval profile in §11, a normally retrievable `200 OK` Manifest is expected to represent an ACTIVE record; SUSPENDED and RETIRED records use the public HTTP behavior defined below.

## 11. Manifest retrieval

### 11.1 Deterministic endpoint and transport security

A Resolver deployment exposing public Manifest 0.1 retrieval SHOULD expose:

```text
GET /{resolver-service}/{uuid}/manifest
```

This endpoint is separate from the Resolver Core resource:

```text
GET /{resolver-service}/{uuid}
```

The Resolver Core resource MUST retain its normal Resolver Core semantics and MUST NOT require content negotiation to select between AR-XML resolution and Manifest retrieval.

Manifest 0.1 L1 retrieval MUST use HTTPS.

A consumer performing L1 Manifest retrieval MUST prevent or reject HTTPS-to-HTTP downgrade throughout the Manifest retrieval redirect chain using controls available in the consumer and/or execution environment.

Any final Manifest representation used for L1 processing MUST have been obtained through an HTTPS-only redirect chain and from a final HTTPS URL.

As with Resolver Core, this requirement does not imply that every browser consumer can inspect every redirect target in application code before the browser follows it. Browser/platform redirect, mixed-content, origin, Fetch, and related network-security controls MAY satisfy the applicable transport requirement where direct application-level inspection is unavailable.

HTTPS transport does not by itself authenticate the Physical Entity, prove ownership, establish Manifest authority beyond ordinary Web origin authentication, or establish a higher RELink security level.

### 11.2 ACTIVE

For an ACTIVE record with a public Manifest, the Manifest endpoint SHOULD return:

```http
200 OK
Content-Type: application/json
```

with a conforming Manifest representation.

### 11.3 SUSPENDED

For a SUSPENDED record, a public Manifest endpoint SHOULD return `404 Not Found` to preserve Resolver Core's public non-distinction between unknown and SUSPENDED records.

A public Manifest endpoint MUST NOT reveal the existence of a SUSPENDED record solely through a distinguishable success response unless a later profile explicitly changes that privacy model.

### 11.4 RETIRED

For a RETIRED record, a public Manifest endpoint SHOULD return `410 Gone`.

A deployment MAY retain a retired Manifest internally for administration, audit, export, or archival purposes.

### 11.5 Unknown UUID

An unknown UUID SHOULD return `404 Not Found`.

### 11.6 Manifest absence

A deployment MAY support Resolver Core 0.1 without exposing any Manifest endpoint.

Failure to retrieve a Manifest MUST NOT invalidate an otherwise successful Resolver Core L1 resolution.

## 12. Extension and unknown-member rules

Manifest 0.1 is extensible JSON.

A Manifest 0.1 consumer:

- MUST validate all required 0.1 members and their required semantics;
- SHOULD ignore unknown members that it does not understand;
- MUST NOT treat an unknown member as security-critical evidence;
- MUST NOT allow an unknown member to override the semantics of a defined 0.1 member.

Unknown top-level members primarily exist to preserve forward compatibility with later RELink-defined Manifest versions or compatible standard additions.

Vendor-specific, product-specific, deployment-specific, or experimental metadata SHOULD be placed under the top-level `extensions` object rather than introduced as new top-level members.

An extension that changes the meaning of a required 0.1 member is not a compatible extension and requires a later Manifest specification.

Security-critical behavior MUST NOT depend on an extension that older conforming consumers are expected to ignore.

The detailed vendor-extension namespace and compatibility rules are defined by the Manifest 0.1 Extension Policy accompanying this specification.

### 12.1 `extensions`

An optional top-level `extensions` object MAY be used for vendor-specific, experimental, deployment-specific, or profile-specific metadata.

Extension names SHOULD be chosen to minimize collisions, for example by using URI-like or reverse-domain identifiers controlled by the extension producer.

Example:

```json
{
  "extensions": {
    "com.example.relink/device": {
      "model": "RX-100"
    }
  }
}
```

Manifest 0.1 assigns no Trust semantics to any extension field.

## 13. Content-Type

Manifest 0.1 representations SHOULD be served as:

```http
Content-Type: application/json
```

A representation served as `application/json` MUST contain conforming JSON and MUST NOT rely on JSON5 syntax.

Manifest 0.1 intentionally does not claim an unregistered RELink-specific media type.

A future specification MAY register or define a dedicated structured-syntax-suffix media type. Such future work MUST NOT change the JSON member semantics defined by Manifest 0.1 without a corresponding Manifest version change.

## 14. Cache, change detection, and CORS guidance

A public Manifest endpoint SHOULD send an explicit cache policy.

For ACTIVE records, the Reference Resolver profile SHOULD initially use:

```http
Cache-Control: public, max-age=60
```

For `404`, `400`, `500`, and `503` responses, the Reference Resolver profile SHOULD use:

```http
Cache-Control: no-store
```

For `410 Gone`, a finite cache lifetime MAY be used because retirement is terminal under Resolver Core 0.1.

A Manifest endpoint MAY expose ordinary HTTP validators such as `ETag` and/or `Last-Modified` to support efficient change detection and conditional retrieval.

Such validators:

- MUST retain their ordinary HTTP semantics;
- MUST NOT be interpreted as Entity authentication, Manifest signature, authority proof, freshness proof, or a RELink security-level indicator;
- MUST NOT be required for Manifest 0.1 conformance.

Browser-oriented public Manifest endpoints SHOULD return `Access-Control-Allow-Origin: *` where cross-origin browser retrieval is intended.

A public Manifest endpoint SHOULD also minimize referrer propagation according to the same deployment guidance used by Resolver Core.

## 15. Consumer network-security boundary

Manifest metadata is not a network authorization decision.

`description.location` is untrusted network input.

Before or while dereferencing a Manifest-supplied Description Location, a consumer MUST apply the network-security controls available in its execution environment.

For native/server-side consumers, this may include explicit Runtime policy for loopback, local-network, link-local, metadata endpoints, DNS rebinding, redirects, and allow/deny lists.

For browser consumers, applicable controls include browser Fetch, CORS, mixed-content restrictions, platform policy, and any additional policy that the Runtime can enforce.

Conceptually:

```text
Manifest
↓
Description Location
↓
Consumer / Platform Network Policy
↓
Fetch
```

Successful Manifest retrieval MUST NOT imply that the Description Location is safe or authorized to access.

## 16. Trust and security boundary

Manifest 0.1 is metadata, not a Trust protocol.

Manifest 0.1 does not provide:

- Physical Entity authentication;
- owner authentication;
- Runtime authentication;
- Resolver authority proof beyond ordinary HTTPS origin authentication;
- Manifest signature verification;
- AR-XML signature verification;
- key ownership proof;
- trust-chain validation;
- freshness or anti-rollback proof;
- ownership transfer;
- authorization to mutate Resolver state;
- capability authorization or execution.

A consumer MUST NOT treat the presence of `entity.id`, `anchor.id`, `description.location`, `description.integrity`, or `lifecycle.status` as proof of any of those properties.

Future Trust or L2 specifications MAY reference Manifest fields or add Trust metadata, but MUST define verification and failure semantics separately.

Manifest 0.1 MUST NOT define a field whose mere presence claims that a stronger RELink security level has been achieved.

The optional integrity mechanism in §9.2 is intentionally limited to content pinning and change detection. It MUST NOT constrain future L2 specifications from defining signatures, authenticated key binding, certificate-based mechanisms, Web-native authentication mechanisms, or other authentication and authorization models.

## 17. Resource-consumption guidance

Manifest representations are untrusted structured input and MAY contain unknown members and extension data.

Consumers SHOULD enforce implementation-appropriate finite limits on resource consumption before or during parsing, including limits on:

- Manifest response-body size;
- JSON nesting depth;
- object-member and array-element counts;
- individual string lengths where practical;
- parsing time and memory consumption.

Producers and Resolver deployments SHOULD avoid generating unnecessarily large or deeply nested public Manifest representations.

Manifest 0.1 does not prescribe universal numeric limits because suitable bounds differ between browsers, servers, native applications, and constrained devices. A consumer MAY reject a representation that exceeds its documented resource limits without treating the underlying Anchor as invalid.

## 18. Optional pre-L2 operational hardening

Deployments MAY selectively use ordinary operational controls before introducing a formal L2 or Trust protocol.

These controls are deployment guidance, not additional Manifest 0.1 requirements, and MUST NOT alter the minimal public resolution contract.

### 18.1 Administrative authentication

Administrative creation, update, suspension, retirement, and metadata-management surfaces SHOULD use deployment-appropriate authentication and authorization independent of public L1 resolution.

Manifest 0.1 does not prescribe a Web authentication technology, identity provider, credential type, session mechanism, MFA mechanism, certificate model, or authorization framework.

Knowledge of an Anchor UUID MUST NOT by itself authorize administrative mutation.

### 18.2 Audit history

Administrative systems SHOULD retain a bounded operational history of material record changes where practical, such as changes to Description Location, optional Description integrity metadata, lifecycle state, and Canonical Entity Identity administrative mapping.

Audit storage format, retention period, actor identity model, immutability mechanism, and external logging system are deployment choices and are not required by Manifest 0.1.

Manifest 0.1 does not require append-only ledgers, transparency logs, blockchains, third-party timestamping services, or other specialized audit infrastructure.

### 18.3 Origin and privilege separation

Deployments SHOULD consider separating public Resolver/Manifest serving privileges from administrative mutation privileges.

Where operationally reasonable, deployments MAY also separate AR-XML hosting from Resolver administration so that compromise of one component does not automatically imply write access to all components.

Manifest 0.1 does not require distinct DNS names, hosting providers, certificate authorities, networks, processes, or products. The exact topology remains a deployment choice.

### 18.4 Low-dependency design rule

A Manifest 0.1 deployment MUST NOT require proprietary cryptographic schemes, licensed authentication products, external trust services, specialized hardware, or patent-dependent mechanisms merely to claim baseline Manifest 0.1 conformance.

Optional deployment products or services MAY be used when independently selected by the operator, but their use MUST NOT redefine Manifest 0.1 wire semantics or become a prerequisite for other conforming implementations.

## 19. Resolver / Manifest responsibility boundary

Resolver Core may internally know only enough to perform resolution:

```text
Anchor UUID
Lifecycle status
Current Description Location
```

Manifest may expose a richer Entity-level view:

```text
Anchor UUID
Canonical Entity Identity
Current Description Location
Optional Description integrity metadata
Lifecycle metadata
Version information
Extensions
```

Manifest MUST NOT require Resolver Core to understand AR-XML capabilities, AR-XML inputs/results, device current IP address, management UI, command endpoints, capability invocation state, or Trust verification results.

Manifest generation SHOULD be possible from Resolver/administrative metadata without dereferencing the Description Location.

## 20. AR-XML boundary

AR-XML describes what an Entity can do and how a Runtime can interact with it.

Manifest describes identity/location/lifecycle metadata around resolution and MAY optionally provide a representation digest for content pinning.

Therefore Manifest 0.1 MUST NOT duplicate AR-XML capability definitions, interface endpoints, input schemas, result mappings, or invocation semantics.

`description.integrity` applies to the retrieved representation bytes and does not require any change to AR-XML syntax, semantics, canonicalization, generation method, or capability model.

The relationship is:

```text
Manifest
  entity.id
  description.location
  description.integrity (optional)
        ↓
      AR-XML
        ↓
  Capability / Interface description
```

## 21. JSON Schema role

The accompanying JSON Schema is a machine-readable validation aid. It is not the complete normative definition of Manifest 0.1 conformance.

JSON Schema implementations may differ in whether formats such as `uuid` and `uri` are enforced as assertions or treated as annotations. Therefore a schema validator reporting success does not, by itself, establish Manifest 0.1 conformance.

Consumers and validators MUST still apply the normative semantic checks in this specification, including RFC 9562 UUID handling, absolute-URI requirements, HTTPS requirements, duplicate-member rejection, lifecycle semantics, integrity semantics when supported, and the relevant security boundaries.

If the JSON Schema and the normative text appear to conflict, the normative text governs until the inconsistency is corrected.

## 22. Reference examples

### 22.1 Minimal Manifest

```json
{
  "manifestVersion": "0.1",
  "anchor": {
    "id": "550e8400-e29b-41d4-a716-446655440000"
  },
  "entity": {
    "id": "https://identity.example/entities/thermostat-42"
  },
  "description": {
    "location": "https://entity.example/descriptions/thermostat-42.arxml"
  },
  "lifecycle": {
    "status": "active"
  }
}
```

### 22.2 Manifest with optional content pinning

```json
{
  "manifestVersion": "0.1",
  "anchor": {
    "id": "550e8400-e29b-41d4-a716-446655440000"
  },
  "entity": {
    "id": "https://identity.example/entities/thermostat-42"
  },
  "description": {
    "location": "https://entity.example/descriptions/thermostat-42.arxml",
    "integrity": {
      "algorithm": "sha-256",
      "digest": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
    }
  },
  "lifecycle": {
    "status": "active"
  }
}
```

The examples do not imply that `entity.id` must be dereferenceable or operational.

The second example does not imply authentication or freshness. Its integrity object only pins the expected AR-XML representation content declared by that Manifest.

## 23. Conformance

A representation claiming **RELink Manifest 0.1 conformance** MUST:

1. be serialized using conforming JSON syntax as defined in §5;
2. contain no duplicate object-member names;
3. contain `manifestVersion` equal to `"0.1"`;
4. contain `anchor.id` as an RFC 9562 UUID;
5. contain `entity.id` as an absolute URI and preserve its identifier-only semantics;
6. keep `entity.id` semantically distinct from Resolver URL and current Description Location;
7. contain `description.location` as an absolute HTTPS URI for L1 use;
8. contain `lifecycle.status` with one of `active`, `suspended`, or `retired`;
9. not use Anchor UUID knowledge as authentication or authorization;
10. not define capability execution, device discovery, management UI, or Trust verification as Manifest 0.1 semantics;
11. remain optional to successful Resolver Core 0.1 L1 resolution.

JSON5 syntax is not a conforming Manifest 0.1 wire representation. Local authoring tools MAY accept JSON5 or another source format, but MUST emit conforming JSON when publishing a Manifest 0.1 representation.

If `description.integrity` is present, it MUST contain `algorithm` and `digest` as specified in §9.2. Its presence MUST NOT be required for Manifest 0.1 conformance.

A consumer claiming baseline Manifest 0.1 conformance MUST reject an unsupported `manifestVersion`, reject duplicate object-member names, validate required fields and normative semantic constraints, preserve HTTPS-only L1 Manifest retrieval, and treat `description.location` and `entity.id` according to their untrusted-data boundaries. It is not required to implement JSON5 or optional integrity verification.

A consumer claiming **Manifest 0.1 integrity-verification support** MUST satisfy the verification and reporting rules in §9.2.3.

## 24. Design summary

```text
Manifest 0.1
= optional Entity-level resolution metadata

Wire format:
    strict JSON
    duplicate member names forbidden
    JSON5 not conforming
    local authoring format implementation-defined

L1 Manifest retrieval:
    HTTPS-only redirect chain
    no HTTPS → HTTP downgrade

Required:
    manifestVersion = "0.1"
    anchor.id        = UUID
    entity.id        = absolute URI identifier
    description.location = HTTPS URI
    lifecycle.status = active | suspended | retired

Optional:
    description.mediaType
    description.integrity
        algorithm
        digest

Integrity meaning:
    final representation content pinning / change detection
    after redirect + HTTP content-coding processing
    before character decoding / XML parsing
    not authentication
    not freshness
    not anti-rollback
    not authorization
    not L2

Canonical Entity Identity:
    stable
    location-independent
    untrusted identifier data
    not a dereference instruction
    scheme not fixed by 0.1

Default retrieval:
    GET /{resolver-service}/{uuid}/manifest

Content-Type:
    application/json

ACTIVE public retrieval:
    200 JSON

SUSPENDED / unknown:
    404

RETIRED:
    410

Schema:
    validation aid only
    normative text governs semantic conformance

Core rule:
    Manifest failure MUST NOT break ordinary L1 resolution

Optional pre-L2 hardening:
    bounded resource consumption
    ordinary HTTP validators
    authenticated admin surface
    bounded audit history
    privilege/origin separation where useful

Not Manifest responsibility:
    Trust
    authentication protocol
    authorization protocol
    signatures
    freshness / anti-rollback
    ownership transfer
    device IP discovery
    management UI
    capability execution
    AR-XML capability semantics
```

Manifest 0.1 preserves the RELink principle that Entity identity is stable while description location may change, while allowing low-cost optional integrity and operational-hardening measures without enlarging Resolver Core or constraining future AR-XML, L2, Trust, or Web authentication designs.
