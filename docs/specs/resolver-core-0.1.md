# RELink Resolver Core 0.1

Status: Draft specification  
Version: 0.1  
Scope: L1 public resolution  

## 1. Purpose

RELink Resolver Core defines the minimal Web-facing resolution function that maps a persistent RELink Anchor identifier to the current location of an Entity's AR-XML description.

The core relationship is:

```text
Anchor UUID
    ↓
Resolver Core
    ↓
Current AR-XML Description Location
```

Resolver Core is intentionally smaller than the complete RELink architecture. It does not describe Entity capabilities, establish trust, authenticate ownership, or execute operations.

The architectural separation is:

```text
Resolver Core = minimal resolution
Manifest      = richer metadata
Trust         = later security / authority layer
Runtime       = consumer-facing interpretation and execution
```

## 2. Requirements language

The key words **MUST**, **MUST NOT**, **REQUIRED**, **SHALL**, **SHALL NOT**, **SHOULD**, **SHOULD NOT**, **RECOMMENDED**, **NOT RECOMMENDED**, **MAY**, and **OPTIONAL** in this document are to be interpreted as described in BCP 14 (RFC 2119 and RFC 8174) when, and only when, they appear in all capitals.

## 3. Terminology

### 3.1 Physical Anchor

A machine-readable or otherwise dereferenceable physical reference associated with a Physical Entity. QR and NFC are expected Anchor carriers, but Resolver Core does not depend on a particular carrier technology.

### 3.2 Anchor URL

The URL encoded in or obtained from a Physical Anchor.

A typical direct Resolver URL is:

```text
https://{domain}/{resolver-service}/{uuid}
```

An Anchor URL MAY first pass through ordinary Web redirection infrastructure, such as a URL-shortening service, before reaching the Resolver.

### 3.3 Anchor UUID

The UUID used as the Resolver lookup key.

The Anchor UUID identifies the RELink resolution record. Resolver Core treats it as an opaque identifier and does not derive semantics, timestamps, security properties, ownership, device type, or network location from its UUID version or bit layout.

### 3.4 Resolver Resource

The HTTP resource identified by the Resolver request target for a given Anchor UUID.

The Resolver Resource is not the AR-XML document and is not the Physical Entity itself.

### 3.5 Description Location

The current HTTPS URL from which the Entity's AR-XML description is expected to be retrieved.

The Description Location is mutable without changing the Anchor UUID.

### 3.6 Canonical Entity Identity

A location-independent identity for the Entity. Its concrete representation is outside the normative scope of Resolver Core 0.1 and is expected to be specified with the Manifest specification.

Resolver Core 0.1 MUST NOT equate the Resolver URL or Description Location with Canonical Entity Identity.

### 3.7 Runtime

A consumer that dereferences RELink resources, retrieves and interprets AR-XML, discovers capabilities, and may later execute capabilities under separate Runtime semantics.

## 4. Core design invariants

Implementations conforming to Resolver Core 0.1 MUST preserve the following separation:

```text
Entity     ≠ Location
Capability ≠ Interface
Resolution ≠ Authentication
Description ≠ Execution
```

In particular:

- an Anchor UUID MAY remain stable while its Description Location changes;
- a Resolver URL MUST NOT be treated as the Entity's operational endpoint;
- successful resolution MUST NOT imply that the Physical Entity, AR-XML document, owner, or Runtime has been authenticated;
- successful resolution MUST NOT invoke any Entity capability.

## 5. L1 security-level baseline

Resolver Core 0.1 specifies the L1 public resolution baseline.

When no security-level query parameter is present, the request MUST be interpreted as an L1 request.

```text
no security-level query
= L1
```

Future RELink levels may use a request form such as:

```text
https://{domain}/{resolver-service}/{uuid}?l={level}&p={public-parameter}
```

The following rules are fixed for forward compatibility:

- `l`, when defined by a future specification, represents the **requested security level**, not an achieved or verified security level.
- `p` has no semantics in Resolver Core 0.1.
- Resolver Core 0.1 clients SHOULD omit `l` and `p`.
- Resolver Core 0.1 does not define authentication, negotiation, downgrade, failure, or mutation semantics for L2 or later levels.
- The presence of an unrecognized query parameter MUST NOT be interpreted by a Core 0.1 consumer as evidence of increased security.

A deployment implementing later RELink levels MAY define additional query semantics without changing the meaning of an L1 request with no security-level query parameter.

## 6. Request target

The canonical Resolver Core request form is:

```text
GET /{resolver-service}/{uuid}
```

`{resolver-service}` is deployment-defined.

`{uuid}` MUST be a UUID textual representation conforming to RFC 9562.

A conforming server:

- MUST accept uppercase, lowercase, and mixed-case hexadecimal UUID text permitted by RFC 9562;
- MUST compare UUID values without assigning meaning to hexadecimal letter case;
- SHOULD use lowercase UUID text for administrative display, logs, generated URLs, and other canonicalized output;
- MUST NOT require one specific UUID version for lookup if the supplied UUID is otherwise valid under the implementation's registration policy;
- MUST NOT derive Resolver behavior from UUID version-specific fields.

A malformed UUID in the UUID path position MUST produce `400 Bad Request`.

A syntactically valid but unregistered UUID MUST produce `404 Not Found`.

## 7. HTTP method semantics

### 7.1 GET

`GET` is the only Resolver Core 0.1 resolution method.

A successful GET performs a read-only lookup and returns an HTTP redirect to the current Description Location.

A GET request MUST NOT mutate Resolver state.

### 7.2 Other methods

Resolver Core 0.1 does not define `POST`, `PUT`, `PATCH`, or `DELETE` on the public resolution resource.

A Resolver receiving an unsupported method on a valid Resolver Core resource MUST return:

```http
405 Method Not Allowed
Allow: GET
```

Authenticated maintenance operations, if provided by a Reference Resolver or deployment, MUST be exposed through a separate administrative surface and are not part of Resolver Core 0.1.

## 8. Successful resolution

For an ACTIVE resolution record, the Resolver MUST return:

```http
303 See Other
Location: https://...
```

The `Location` field MUST identify the current AR-XML Description Location.

The `Location` value:

- MUST be an absolute URI;
- MUST use the `https` scheme for conforming L1 production resolution;
- MUST NOT be a device's current IP-address lookup result merely because that address is currently reachable;
- MUST NOT be a generated management-console URL;
- MUST NOT be an operational UI constructed by the Resolver;
- MUST NOT be a capability invocation URL selected by the Resolver.

The Resolver MUST NOT inspect AR-XML contents in order to determine the success of ordinary resolution.

## 9. Why 303 See Other is used

Resolver Core 0.1 uses `303 See Other` rather than a permanent redirect because the Description Location is a distinct resource and may change over the lifetime of the Entity.

A Resolver Resource, Physical Entity, and AR-XML document are not equivalent resources.

For a GET request, HTTP semantics for `303 See Other` allow the `Location` target to identify another resource descriptive of the original target without asserting URI equivalence. This matches the RELink rule:

```text
Entity ≠ Location
```

Clients MAY follow the `303` automatically using normal HTTP behavior.

## 10. Redirect chains

RELink does not require the Anchor URL to directly identify the Resolver endpoint.

The following is valid:

```text
Physical Anchor
↓
ordinary short URL
↓ 301 / 302 / 303 / 307 / 308 as provided by Web infrastructure
Resolver URL
↓ 303
AR-XML Description Location
```

Likewise, the Description Location MAY itself redirect through ordinary Web infrastructure before the AR-XML representation is obtained.

Resolver Core places no protocol-specific requirement on the number of ordinary redirects a consumer must follow. Consumers SHOULD apply their normal HTTP redirect-loop detection and safety limits.

The final URL from which AR-XML is actually retrieved is significant to Runtime processing. Relative AR-XML Interface endpoints are expected to be resolved against the final AR-XML document URL, not against the original Anchor URL or Resolver URL.

The detailed Runtime integration contract is specified separately.

## 11. Lifecycle states

Resolver Core 0.1 defines three resolution lifecycle states:

```text
ACTIVE
SUSPENDED
RETIRED
```

### 11.1 ACTIVE

An ACTIVE record is currently resolvable.

Public response:

```text
303 See Other
```

### 11.2 SUSPENDED

A SUSPENDED record is known to the Resolver but is temporarily not available for public resolution.

Public response:

```text
404 Not Found
```

The public L1 interface intentionally does not distinguish an unknown UUID from a temporarily suspended UUID.

A maintenance interface MAY retain and expose the distinction internally.

### 11.3 RETIRED

A RETIRED record is known to have been permanently withdrawn from normal RELink resolution.

Public response:

```text
410 Gone
```

RETIRED is a terminal state in Resolver Core 0.1.

### 11.4 State transitions

The permitted Core 0.1 state transitions are:

```text
ACTIVE    → SUSPENDED
SUSPENDED → ACTIVE
ACTIVE    → RETIRED
SUSPENDED → RETIRED
```

A transition out of RETIRED MUST NOT occur within Resolver Core 0.1 semantics.

Lifecycle reasons, administrative actors, timestamps, audit history, and richer lifecycle metadata are not carried in the public Core resolution response. They may be represented by administrative storage and/or Manifest metadata under their separate specifications.

## 12. HTTP status-code model

Resolver Core 0.1 defines the following externally observable status meanings.

| Status | Resolver Core meaning |
| --- | --- |
| `303 See Other` | ACTIVE UUID successfully resolved to current Description Location |
| `400 Bad Request` | Resolver request contains an invalid Anchor UUID syntax or is otherwise malformed at the Core request level |
| `404 Not Found` | UUID is unknown, or the known record is SUSPENDED |
| `405 Method Not Allowed` | Method is not supported by public Resolver Core 0.1 |
| `410 Gone` | UUID is known and permanently RETIRED |
| `500 Internal Server Error` | Unexpected internal Resolver failure |
| `503 Service Unavailable` | Resolver or required backing service is temporarily unavailable |

A server SHOULD prefer `503 Service Unavailable` when the failure is known to be temporary, such as an unavailable backing datastore.

Resolver Core 0.1 does not define `401 Unauthorized` or `403 Forbidden` for ordinary L1 resolution because L1 is public and anonymous.

## 13. Error representation

Consumers MUST be able to determine Resolver Core success or failure from HTTP semantics without parsing a response body.

An error response body is OPTIONAL.

A Resolver that provides structured error details SHOULD use RFC 9457 Problem Details (`application/problem+json`).

Error representations MUST NOT expose secrets, credentials, private administrative metadata, private ownership information, or internal datastore details.

The structured error format is diagnostic metadata and MUST NOT become a dependency of successful L1 resolution.

## 14. Cache policy

Resolver mappings are mutable. A cache policy must therefore permit normal Web caching while bounding how long an obsolete Description Location may remain fresh.

### 14.1 Successful resolution

A Resolver MUST send an explicit cache policy on a `303` success response.

The Reference Resolver profile SHOULD default to:

```http
Cache-Control: public, max-age=60
```

The exact freshness lifetime is deployment-configurable and is not a protocol constant.

Deployments SHOULD choose a freshness lifetime consistent with their expected mapping-update frequency.

### 14.2 Malformed, unknown, and temporary failures

The following responses SHOULD use:

```http
Cache-Control: no-store
```

for Core 0.1 deployments unless a later profile explicitly defines another policy:

- `400 Bad Request`
- `404 Not Found`
- `500 Internal Server Error`
- `503 Service Unavailable`

Avoiding negative caching of `404` supports registration and temporary suspension workflows where the public result may change soon after an earlier lookup.

### 14.3 Retired records

A `410 Gone` response MAY be cached because retirement is terminal under Resolver Core 0.1.

The Reference Resolver profile SHOULD use a finite explicit freshness lifetime rather than relying on heuristic caching.

A recommended initial value is:

```http
Cache-Control: public, max-age=300
```

This value is a Reference Resolver default, not a protocol invariant.

## 15. CORS policy

Resolver Core 0.1 is designed to be usable from Web Runtime implementations running in browsers.

A public L1 Resolver SHOULD return:

```http
Access-Control-Allow-Origin: *
```

for public Core responses where browser Fetch access is intended.

L1 resolution does not require credentials and a public Resolver SHOULD NOT require credentialed CORS for the Core GET path.

A Core 0.1 client SHOULD NOT require custom request headers for ordinary L1 resolution.

CORS permission on the Resolver does not grant access to the final AR-XML resource. The AR-XML origin and any relevant redirect path must independently satisfy browser Fetch/CORS requirements.

Browser navigation and browser Fetch have different CORS implications; Resolver Core does not redefine browser security behavior.

## 16. Transport security

Conforming L1 production Resolver URLs MUST use HTTPS.

Successful L1 Description Locations MUST use HTTPS.

HTTPS provides transport security for the contacted Web origin. Resolver Core 0.1 does not claim that HTTPS alone authenticates:

- the Physical Entity;
- ownership of the Entity;
- the semantic correctness of AR-XML;
- the physical attachment of an Anchor;
- the authority of a party to update a resolution record beyond whatever administrative controls a deployment applies.

Those concerns belong to later Trust and authenticated-update specifications.

## 17. Resolver and AR-XML responsibility boundary

Resolver Core knows only enough information to perform resolution.

A Core implementation may internally store:

```text
Anchor UUID
Lifecycle status
Current AR-XML Description Location
```

and implementation metadata required for administration.

Resolver Core MUST NOT require knowledge of:

- AR-XML category;
- AR-XML profiles;
- AR-XML capabilities;
- AR-XML inputs or results;
- AR-XML interfaces;
- Entity current IP address;
- device control protocol;
- device management UI;
- capability invocation state.

The Resolver MUST NOT parse AR-XML as a prerequisite for returning the registered current Description Location.

## 18. Resolver and Manifest boundary

Manifest is a separate specification.

A conforming Resolver Core 0.1 deployment MUST be able to resolve an ACTIVE UUID directly to its AR-XML Description Location without requiring a Manifest.

```text
Resolver Core
↓ 303
AR-XML
```

A richer deployment MAY additionally expose a Manifest through a separately specified discovery mechanism.

Manifest availability, retrieval, parsing, validation, or failure MUST NOT be a prerequisite for normal Core 0.1 L1 resolution.

Canonical Entity Identity representation, richer lifecycle metadata, version information, and future security/trust references belong to the Manifest layer rather than the minimal Core redirect response.

## 19. L1 and future L2 boundary

L1 is:

```text
public
anonymous
read-only
GET
UUID lookup
HTTPS
303 to current AR-XML Description Location
```

Resolver Core 0.1 explicitly leaves the following for later levels or specifications:

- Runtime-to-Resolver authentication;
- Resolver authentication beyond ordinary HTTPS origin authentication;
- public-key-based verification semantics;
- signatures;
- authenticated update;
- `PUT` / `PATCH` mutation protocols;
- ownership and authority transfer;
- security-level negotiation;
- downgrade handling;
- trust-chain validation.

A later level MUST NOT redefine an existing Anchor UUID merely because a stronger security level is requested.

A later level SHOULD extend the L1 identity/resolution model rather than merge identity, description, trust, and execution into one operation.

## 20. Security non-goals

Resolver Core 0.1 does **not** provide:

- Physical Entity authentication;
- Runtime authentication;
- owner authentication;
- AR-XML authenticity verification;
- AR-XML integrity verification beyond transport mechanisms supplied by HTTP/TLS;
- Resolver trust-chain establishment;
- device authorization;
- capability authorization;
- capability execution;
- ownership transfer;
- local-network discovery;
- current device-IP discovery;
- device configuration;
- management-console construction.

A consumer MUST NOT treat `303 See Other` as proof of any of those properties.

## 21. Prohibited responsibility expansion

To preserve RELink's architectural boundary, a Resolver Core 0.1 implementation MUST NOT make ordinary resolution depend on any of the following patterns:

```text
UUID → dynamically reported device IP → management URL
```

```text
UUID → generated control UI
```

```text
UUID → Resolver-selected command endpoint
```

```text
Resolver → direct capability invocation
```

```text
periodic device IP registration → required for ordinary identity resolution
```

A deployment MAY operate unrelated device-management services, but those services MUST remain outside Resolver Core semantics.

## 22. Privacy and information disclosure

L1 is a public lookup protocol. Deployments MUST assume that anyone who obtains a valid Anchor URL can attempt resolution.

Therefore:

- Description Locations returned by public L1 resolution SHOULD be suitable for public disclosure;
- private administrative data MUST NOT be embedded in public error responses;
- unknown and SUSPENDED records deliberately share `404` behavior;
- implementations SHOULD avoid response differences that unnecessarily expose internal datastore or administrative state.

Resolver Core 0.1 does not provide confidentiality for the existence of ACTIVE or RETIRED identifiers.

## 23. Reference interaction

A successful L1 interaction is:

```http
GET /relink/550e8400-e29b-41d4-a716-446655440000 HTTP/1.1
Host: resolver.example
```

```http
HTTP/1.1 303 See Other
Location: https://entity.example/arxml/550e8400-e29b-41d4-a716-446655440000.xml
Cache-Control: public, max-age=60
Access-Control-Allow-Origin: *
```

The client may then retrieve the Description Location using ordinary HTTP semantics.

## 24. Conformance

A deployment claiming **RELink Resolver Core 0.1 L1 conformance** MUST, at minimum:

1. expose an HTTPS GET resolution endpoint using an RFC 9562 UUID lookup key;
2. treat the UUID as opaque for Resolver semantics;
3. return `303 See Other` with an absolute HTTPS current AR-XML Description Location for ACTIVE records;
4. return the status semantics specified in this document for malformed, unknown/SUSPENDED, unsupported-method, RETIRED, and server-failure cases;
5. keep public resolution read-only;
6. preserve Entity/Location, Resolution/Authentication, and Description/Execution separation;
7. avoid requiring Manifest, Trust, capability execution, or device-network discovery for ordinary L1 resolution;
8. send explicit cache behavior for Core responses as required by this specification.

CORS support is RECOMMENDED for browser-oriented public deployments but is not required for non-browser Resolver consumers unless the applicable deployment profile requires it.

## 25. Related specifications and standards

Resolver Core 0.1 relies on existing Web standards rather than redefining them.

Relevant external standards include:

- RFC 9110 — HTTP Semantics
- RFC 9111 — HTTP Caching
- RFC 9562 — Universally Unique IDentifiers (UUIDs)
- RFC 9457 — Problem Details for HTTP APIs
- Fetch Standard — redirects and browser CORS processing

Related RELink work:

- AR-XML Core 0.1
- RELink Manifest 0.1
- RELink Web Runtime integration
- RELink Resolver Testbed cases
- future RELink Trust / higher security-level specifications

## 26. Design summary

Resolver Core 0.1 can be summarized as:

```text
Input:
    public HTTPS GET
    /{resolver-service}/{uuid}

Default level:
    no security-level query = L1

Identifier:
    RFC 9562 UUID
    treated as opaque

ACTIVE:
    303 See Other
    Location = current HTTPS AR-XML Description Location

SUSPENDED / unknown:
    404 Not Found

RETIRED:
    410 Gone

Unsupported method:
    405 Method Not Allowed

Resolver failure:
    500 / 503

Core responsibility:
    UUID → current description location

Not Core responsibility:
    Trust
    authentication
    mutation
    device current IP
    management UI
    capability execution
    AR-XML interpretation
```

This minimal boundary is intentional and is the baseline on which Manifest, Trust, Runtime integration, Reference Resolver implementation, and conformance testing are expected to build.