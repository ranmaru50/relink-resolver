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

## 5. JSON representation

Manifest 0.1 is a JSON object.

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

## 6. `manifestVersion`

`manifestVersion` MUST be the JSON string:

```text
0.1
```

A consumer implementing Manifest 0.1 MUST NOT interpret a Manifest with another `manifestVersion` value as if it were Manifest 0.1.

Manifest 0.1 does not use Semantic Versioning semantics.

Future specifications may define compatibility relationships between later versions, but Core 0.1 consumers MUST fail validation for an unsupported `manifestVersion` rather than guessing compatibility.

Unknown non-critical members MAY be ignored as specified in §12, but an unsupported `manifestVersion` is not an ignorable extension.

## 7. `anchor`

`anchor` MUST be an object containing:

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000"
}
```

`anchor.id`:

- MUST be an RFC 9562 UUID textual representation;
- MUST identify the Resolver record with which the Manifest is associated;
- SHOULD be serialized using lowercase hexadecimal text;
- MUST be treated as an opaque identifier;
- MUST NOT be treated as a password, bearer credential, access token, capability token, authentication proof, or authorization proof.

When a Manifest is retrieved through the deterministic Manifest endpoint for an Anchor UUID, the response representation's `anchor.id` MUST identify the same UUID value as the UUID in the request path.

A mismatch MUST be treated as an invalid Manifest.

## 8. `entity`

`entity` MUST be an object containing:

```json
{
  "id": "https://identity.example/entities/12345"
}
```

`entity.id` is the Canonical Entity Identity.

`entity.id`:

- MUST be an absolute URI;
- MUST be stable across ordinary Description Location changes;
- MUST NOT be the current AR-XML Description Location merely by convention;
- MUST NOT be the Resolver Resource URL merely by convention;
- MUST NOT be interpreted as an operational endpoint unless another specification explicitly defines such semantics.

Manifest 0.1 does not require a particular URI scheme for Canonical Entity Identity.

In particular, Manifest 0.1 does not require `urn:uuid:` and does not require the Canonical Entity Identity to be derived from the Anchor UUID.

This allows deployments to support multiple Anchors referring to one Entity, migration between Resolver services, and future identity models without changing Manifest 0.1 field semantics.

## 9. `description`

`description` MUST be an object containing:

```json
{
  "location": "https://entity.example/arxml/entity.xml"
}
```

`description.location`:

- MUST be a syntactically valid absolute URI;
- MUST use the `https` scheme for Manifest 0.1 L1 use;
- MUST identify the current AR-XML Description Location;
- MAY change while `entity.id` remains unchanged;
- MUST be treated as untrusted network input by consumers;
- MUST NOT imply that the target is safe, authenticated, authorized, public, or non-local.

If an ACTIVE Manifest is generated from the same Resolver record used by Resolver Core, `description.location` MUST represent the same current Description Location that Resolver Core would emit for that record at the same logical state.

Manifest generation MUST NOT require the Resolver to fetch or parse the AR-XML document.

### 9.1 `description.mediaType`

An implementation MAY include:

```json
{
  "mediaType": "application/xml"
}
```

if it has a known media type for the description representation.

Manifest 0.1 does not define or register a dedicated AR-XML media type and therefore does not require a specific `description.mediaType` value.

Consumers MUST NOT treat `description.mediaType` as a substitute for validating the retrieved representation according to the applicable AR-XML specification.

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

## 11. Manifest retrieval

### 11.1 Deterministic endpoint

A Resolver deployment exposing public Manifest 0.1 retrieval SHOULD expose:

```text
GET /{resolver-service}/{uuid}/manifest
```

This endpoint is separate from the Resolver Core resource:

```text
GET /{resolver-service}/{uuid}
```

The Resolver Core resource MUST retain its normal Resolver Core semantics and MUST NOT require content negotiation to select between AR-XML resolution and Manifest retrieval.

This separation avoids making Manifest representation selection part of the minimal Resolver Core cache and redirect contract.

### 11.2 ACTIVE

For an ACTIVE record with a public Manifest, the Manifest endpoint SHOULD return:

```http
200 OK
Content-Type: application/json
```

with a conforming Manifest representation.

### 11.3 SUSPENDED

For a SUSPENDED record, a public Manifest endpoint SHOULD return:

```http
404 Not Found
```

to preserve Resolver Core's public non-distinction between unknown and SUSPENDED records.

A public Manifest endpoint MUST NOT reveal the existence of a SUSPENDED record solely through a distinguishable success response unless a later profile explicitly changes that privacy model.

### 11.4 RETIRED

For a RETIRED record, a public Manifest endpoint SHOULD return:

```http
410 Gone
```

A deployment MAY retain a retired Manifest internally for administration, audit, export, or archival purposes.

### 11.5 Unknown UUID

An unknown UUID SHOULD return:

```http
404 Not Found
```

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

An extension that changes the meaning of a required 0.1 member is not a compatible extension and requires a later Manifest specification.

Security-critical behavior MUST NOT depend on an extension that older conforming consumers are expected to ignore.

### 12.1 `extensions`

An optional top-level `extensions` object MAY be used for experimental or profile-specific metadata.

Extension names SHOULD be chosen to minimize collisions, for example by using URI-like or reverse-domain identifiers.

Example:

```json
{
  "extensions": {
    "example.org/demo": {
      "value": "example"
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

Manifest 0.1 intentionally does not claim an unregistered RELink-specific media type.

A future specification MAY register or define a dedicated structured-syntax-suffix media type. Such future work MUST NOT change the JSON member semantics defined by Manifest 0.1 without a corresponding Manifest version change.

## 14. Cache and CORS guidance

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

Browser-oriented public Manifest endpoints SHOULD return:

```http
Access-Control-Allow-Origin: *
```

where cross-origin browser retrieval is intended.

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
- ownership transfer;
- authorization to mutate Resolver state;
- capability authorization or execution.

A consumer MUST NOT treat the presence of `entity.id`, `anchor.id`, `description.location`, or `lifecycle.status` as proof of any of those properties.

Future Trust specifications MAY reference Manifest fields or add Trust metadata, but MUST define verification and failure semantics separately.

Manifest 0.1 MUST NOT define a field whose mere presence claims that a stronger RELink security level has been achieved.

## 17. Resolver / Manifest responsibility boundary

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
Lifecycle metadata
Version information
Extensions
```

Manifest MUST NOT require Resolver Core to understand:

- AR-XML capabilities;
- AR-XML inputs/results;
- device current IP address;
- management UI;
- command endpoints;
- capability invocation state;
- Trust verification results.

Manifest generation SHOULD be possible from Resolver/administrative metadata without dereferencing the Description Location.

## 18. AR-XML boundary

AR-XML describes what an Entity can do and how a Runtime can interact with it.

Manifest describes identity/location/lifecycle metadata around resolution.

Therefore Manifest 0.1 MUST NOT duplicate AR-XML capability definitions, interface endpoints, input schemas, result mappings, or invocation semantics.

The relationship is:

```text
Manifest
  entity.id
  description.location
        ↓
      AR-XML
        ↓
  Capability / Interface description
```

## 19. Reference example

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

The example does not imply that `https://identity.example/entities/thermostat-42` must be dereferenceable or operational. It is the Canonical Entity Identity URI.

## 20. Conformance

A representation claiming **RELink Manifest 0.1 conformance** MUST:

1. be a JSON object;
2. contain `manifestVersion` equal to `"0.1"`;
3. contain `anchor.id` as an RFC 9562 UUID;
4. contain `entity.id` as an absolute URI;
5. keep `entity.id` semantically distinct from Resolver URL and current Description Location;
6. contain `description.location` as an absolute HTTPS URI for L1 use;
7. contain `lifecycle.status` with one of `active`, `suspended`, or `retired`;
8. not use Anchor UUID knowledge as authentication or authorization;
9. not define capability execution, device discovery, management UI, or Trust verification as Manifest 0.1 semantics;
10. remain optional to successful Resolver Core 0.1 L1 resolution.

A consumer claiming Manifest 0.1 conformance MUST reject an unsupported `manifestVersion`, validate required fields, and treat `description.location` as untrusted network input.

## 21. Design summary

```text
Manifest 0.1
= optional Entity-level resolution metadata

Required:
    manifestVersion = "0.1"
    anchor.id        = UUID
    entity.id        = absolute URI
    description.location = HTTPS URI
    lifecycle.status = active | suspended | retired

Canonical Entity Identity:
    stable
    location-independent
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

Core rule:
    Manifest failure MUST NOT break ordinary L1 resolution

Not Manifest responsibility:
    Trust
    authentication
    authorization
    signatures
    ownership transfer
    device IP discovery
    management UI
    capability execution
    AR-XML capability semantics
```

Manifest 0.1 preserves the RELink principle that Entity identity is stable while description location may change, without enlarging Resolver Core into a metadata, Trust, or execution service.
