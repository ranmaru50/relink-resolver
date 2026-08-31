# RELink Resolver Core 0.1

Status: Draft specification  
Version: 0.1  
Scope: L1 public resolution

## 1. Purpose

RELink Resolver Core defines the minimal Web-facing resolution function that maps a persistent RELink Anchor identifier to the current location of an Entity's AR-XML description.

```text
Anchor UUID
    ↓
Resolver Core
    ↓
Current AR-XML Description Location
```

Resolver Core is intentionally smaller than the complete RELink architecture. It does not describe Entity capabilities, establish trust, authenticate ownership, or execute operations.

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

Possession or knowledge of an Anchor UUID **MUST NOT** be treated as authentication or authorization.

An Anchor UUID is not a password, bearer credential, access token, or capability token.

### 3.4 Resolver Resource

The HTTP resource identified by the Resolver request target for a given Anchor UUID.

The Resolver Resource is not the AR-XML document and is not the Physical Entity itself.

### 3.5 Description Location

The current HTTPS URL from which the Entity's AR-XML description is expected to be retrieved.

The Description Location is mutable without changing the Anchor UUID.

A Description Location returned by a Resolver is **untrusted network input** from the consumer's perspective. Successful resolution does not imply that the target is safe or authorized to access.

### 3.6 Canonical Entity Identity

A location-independent identity for the Entity. Its concrete representation is outside the normative scope of Resolver Core 0.1 and is expected to be specified with the Manifest specification.

Resolver Core 0.1 **MUST NOT** equate the Resolver URL or Description Location with Canonical Entity Identity.

### 3.7 Runtime / Consumer

A consumer that dereferences RELink resources, retrieves and interprets AR-XML, discovers capabilities, and may later execute capabilities under separate Runtime semantics.

## 4. Core design invariants

Implementations conforming to Resolver Core 0.1 **MUST** preserve the following separation:

```text
Entity     ≠ Location
Capability ≠ Interface
Resolution ≠ Authentication
Description ≠ Execution
```

In particular:

- an Anchor UUID **MAY** remain stable while its Description Location changes;
- a Resolver URL **MUST NOT** be treated as the Entity's operational endpoint;
- successful resolution **MUST NOT** imply that the Physical Entity, AR-XML document, owner, or Runtime has been authenticated;
- successful resolution **MUST NOT** invoke any Entity capability.

## 5. L1 Resolution Security Profile and security-level requests

Resolver Core 0.1 specifies the **L1 Resolution Security Profile**.

L1 is a resolution transport/profile designation. It is not an Entity trust rating, safety rating, ownership assurance, or authenticity rating.

When no `l` security-level query parameter is present, the request **MUST** be interpreted as an L1 request.

```text
no l parameter
= L1
```

Future RELink levels may use a request form such as:

```text
https://{domain}/{resolver-service}/{uuid}?l={level}&p={public-parameter}
```

The following rules are fixed for forward compatibility:

- `l` represents the **requested RELink security level**, not an achieved or verified level;
- Resolver Core 0.1 defines only the no-`l` L1 request form;
- a Core 0.1-only Resolver receiving an `l` value it does not support **MUST NOT** silently process that request as L1;
- an unsupported `l` value **MUST** fail closed;
- a Core 0.1-only Resolver receiving an unsupported `l` **SHOULD** return `501 Not Implemented`;
- `p` has no semantics in Resolver Core 0.1;
- `p` **MUST NOT** cause an unsupported `l` request to be accepted as L1;
- Resolver Core 0.1 clients **SHOULD** omit `l` and `p`;
- the presence of query parameters **MUST NOT** be interpreted as evidence that a stronger security level was achieved.

A deployment implementing later RELink levels **MAY** define additional query semantics without changing the meaning of an L1 request with no `l` parameter.

The required downgrade rule is:

```text
no l              → L1
supported l       → process under that supported level
unsupported l     → fail closed
unsupported l     ↛ L1
```

## 6. Request target and UUID handling

The canonical Resolver Core request form is:

```text
GET /{resolver-service}/{uuid}
```

`{resolver-service}` is deployment-defined.

`{uuid}` **MUST** be a UUID textual representation conforming to RFC 9562.

A conforming server:

- **MUST** accept uppercase, lowercase, and mixed-case hexadecimal UUID text permitted by RFC 9562;
- **MUST** compare UUID values without assigning meaning to hexadecimal letter case;
- **SHOULD** use lowercase UUID text for administrative display and generated URLs;
- **MUST NOT** require one specific UUID version for lookup if the UUID is otherwise valid under the implementation's registration policy;
- **MUST NOT** derive Resolver behavior from UUID version-specific fields.

A malformed UUID in the UUID path position **MUST** produce `400 Bad Request`.

A syntactically valid but unregistered UUID **MUST** produce `404 Not Found`.

Implementations that generate externally visible Anchor UUIDs **SHOULD** consider metadata-leakage characteristics of the selected UUID version. The Reference Resolver profile **SHOULD** use UUIDv4 by default unless another version is selected for an explicit reason.

## 7. HTTP method semantics and processing order

### 7.1 GET

`GET` is the only Resolver Core 0.1 resolution method.

A successful GET performs a read-only lookup and returns an HTTP redirect to the current Description Location.

A GET request **MUST NOT** mutate Resolver state.

### 7.2 Other methods

Resolver Core 0.1 does not define `POST`, `PUT`, `PATCH`, or `DELETE` on the public resolution resource.

For a syntactically valid Resolver Core route, unsupported-method handling **MUST** occur before UUID registration-state lookup.

Therefore, an unsupported method **MUST NOT** produce different public results merely because the supplied syntactically valid UUID is registered, unknown, ACTIVE, or SUSPENDED.

A Resolver receiving an unsupported method on a syntactically valid Resolver Core route **MUST** return:

```http
405 Method Not Allowed
Allow: GET
```

Authenticated maintenance operations, if provided, **MUST** be exposed through a separate administrative surface and are not part of Resolver Core 0.1.

## 8. Successful resolution and Location validation

For an ACTIVE resolution record, the Resolver **MUST** return:

```http
303 See Other
Location: https://...
```

The `Location` field **MUST** identify the current AR-XML Description Location.

Before emitting `Location`, the Resolver **MUST** validate the stored value at response time. The emitted value:

- **MUST** be a syntactically valid absolute URI;
- **MUST** use the `https` scheme for conforming L1 production resolution;
- **MUST** be safe to serialize as an HTTP field value and **MUST NOT** permit header injection;
- **MUST NOT** be a generated management-console URL;
- **MUST NOT** be an operational UI constructed by the Resolver;
- **MUST NOT** be a capability invocation URL selected by the Resolver;
- **MUST NOT** be derived by resolving a device's dynamically reported current IP address as part of ordinary Core resolution.

If internal or administrative data for an ACTIVE record cannot be safely emitted as a conforming Description Location, the Resolver **MUST NOT** emit that value and **SHOULD** return `500 Internal Server Error`.

The Resolver **MUST NOT** inspect or fetch AR-XML contents in order to determine the success of ordinary resolution.

## 9. Why 303 See Other is used

Resolver Core 0.1 uses `303 See Other` rather than a permanent redirect because the Description Location is a distinct resource and may change over the lifetime of the Entity.

A Resolver Resource, Physical Entity, and AR-XML document are not equivalent resources.

For a GET request, HTTP semantics for `303 See Other` allow the `Location` target to identify another resource descriptive of the original target without asserting URI equivalence. This matches:

```text
Entity ≠ Location
```

Clients **MAY** follow the `303` automatically subject to the consumer security requirements in this specification.

## 10. Redirect chains and HTTPS downgrade resistance

RELink does not require the Anchor URL to directly identify the Resolver endpoint.

The following is valid:

```text
Physical Anchor
↓
HTTPS short URL
↓ HTTPS redirect(s)
HTTPS Resolver URL
↓ 303
HTTPS AR-XML Description Location
↓ optional HTTPS redirect(s)
HTTPS final AR-XML URL
```

A consumer claiming L1 processing **MUST NOT** follow an HTTPS-to-HTTP redirect while dereferencing:

- an Anchor URL on the path to a Resolver;
- a Resolver response;
- a Description Location;
- any subsequent redirect on the path to the final AR-XML document.

The final URL from which AR-XML is retrieved under L1 processing **MUST** use HTTPS.

An HTTPS-to-HTTP redirect encountered during L1 processing **MUST** cause dereferencing to fail rather than downgrade transport security.

Resolver Core places no protocol-specific requirement on the number of ordinary redirects a consumer must follow. Consumers **SHOULD** apply bounded redirect counts, loop detection, and normal HTTP safety limits.

The final AR-XML document URL is significant to Runtime processing. Relative AR-XML Interface endpoints are expected to be resolved against the final AR-XML document URL, not against the original Anchor URL or Resolver URL.

## 11. Lifecycle states

Resolver Core 0.1 defines:

```text
ACTIVE
SUSPENDED
RETIRED
```

### 11.1 ACTIVE

An ACTIVE record is currently resolvable.

```text
303 See Other
```

### 11.2 SUSPENDED

A SUSPENDED record is known to the Resolver but temporarily unavailable for public resolution.

```text
404 Not Found
```

The public L1 interface intentionally does not distinguish an unknown UUID from a SUSPENDED UUID.

### 11.3 RETIRED

A RETIRED record is permanently withdrawn from normal RELink resolution.

```text
410 Gone
```

RETIRED is terminal in Resolver Core 0.1.

### 11.4 State transitions

Permitted transitions are:

```text
ACTIVE    → SUSPENDED
SUSPENDED → ACTIVE
ACTIVE    → RETIRED
SUSPENDED → RETIRED
```

A transition out of RETIRED **MUST NOT** occur within Resolver Core 0.1 semantics.

Lifecycle reasons, actors, timestamps, audit history, and richer lifecycle metadata are outside the public Core response and may be represented by administration and/or Manifest specifications.

## 12. HTTP status-code model

| Status | Resolver Core meaning |
| --- | --- |
| `303 See Other` | ACTIVE UUID successfully resolved |
| `400 Bad Request` | Invalid UUID syntax or malformed Core request |
| `404 Not Found` | UUID is unknown, or known record is SUSPENDED |
| `405 Method Not Allowed` | Method unsupported by public Resolver Core; determined independently of registration state |
| `410 Gone` | UUID is known and RETIRED |
| `500 Internal Server Error` | Unexpected internal failure or unsafe/invalid stored Location |
| `501 Not Implemented` | Requested `l` is not supported by this Resolver implementation |
| `503 Service Unavailable` | Resolver or required backing service is temporarily unavailable |

A server **SHOULD** prefer `503 Service Unavailable` when the failure is known to be temporary.

Resolver Core 0.1 does not define `401 Unauthorized` or `403 Forbidden` for ordinary L1 resolution because L1 is public and anonymous.

## 13. Error representation

Consumers **MUST** be able to determine Resolver success or failure from HTTP semantics without parsing a response body.

An error body is OPTIONAL.

A Resolver providing structured error details **SHOULD** use RFC 9457 Problem Details (`application/problem+json`).

Error representations **MUST NOT** expose secrets, credentials, private administrative metadata, private ownership information, or internal datastore details.

Structured error bodies are diagnostic metadata and **MUST NOT** become a dependency of successful L1 resolution.

## 14. Cache policy

Resolver mappings are mutable. Cache policy must bound the lifetime of obsolete Description Locations.

### 14.1 Successful resolution

A Resolver **MUST** send an explicit cache policy on a `303` response.

Reference default:

```http
Cache-Control: public, max-age=60
```

The exact freshness lifetime is deployment-configurable and is not a protocol constant.

Deployments with rapid revocation or incident-response requirements **SHOULD** use a shorter `max-age` or `no-store` when appropriate.

### 14.2 Malformed, unknown, unsupported-level, and temporary failures

The following responses **SHOULD** use:

```http
Cache-Control: no-store
```

- `400 Bad Request`
- `404 Not Found`
- `501 Not Implemented`
- `500 Internal Server Error`
- `503 Service Unavailable`

Avoiding negative caching of `404` supports registration and temporary suspension workflows.

### 14.3 Retired records

A `410 Gone` response **MAY** be cached because retirement is terminal.

Reference default:

```http
Cache-Control: public, max-age=300
```

## 15. CORS policy

A public browser-oriented L1 Resolver **SHOULD** return:

```http
Access-Control-Allow-Origin: *
```

L1 resolution does not require credentials and a public Resolver **SHOULD NOT** require credentialed CORS for the Core GET path.

A Core 0.1 client **SHOULD NOT** require custom request headers for ordinary L1 resolution.

CORS permission on the Resolver does not grant access to the final AR-XML resource. The AR-XML origin and relevant redirect path must independently satisfy browser Fetch/CORS requirements.

## 16. Consumer network-security policy

Resolver output is untrusted network input.

A consumer **MUST** apply its own network and security policy before dereferencing a Description Location or any redirect target derived from it.

Successful resolution **MUST NOT** be interpreted to mean that the target is safe, public, non-local, trusted, authenticated, or authorized to access.

A conforming consumer's network policy may consider, according to its execution environment:

- destination scheme;
- hostname and address ranges;
- loopback and local-network targets;
- link-local destinations;
- cloud or platform metadata endpoints;
- DNS rebinding risk;
- redirect destinations;
- deployment-specific allowlists or denylists.

Resolver Core 0.1 intentionally does **not** prohibit private or local-network Description Locations at the Resolver level, because RELink deployments may legitimately describe local Entities. The access decision belongs to the consumer/runtime policy.

Conceptually:

```text
Resolver
↓
Description Location
↓
Consumer Network Policy
↓
Fetch
```

This boundary is particularly important for server-side, native, agent, Python, Kotlin, .NET, and other non-browser consumers that do not inherit browser navigation/network protections.

## 17. Transport security and referrer minimization

Conforming L1 production Resolver URLs **MUST** use HTTPS.

Successful L1 Description Locations **MUST** use HTTPS.

L1 consumers **MUST** preserve HTTPS throughout the dereference chain as specified in §10.

A public L1 Resolver **SHOULD** send:

```http
Referrer-Policy: no-referrer
```

on redirect responses to reduce unnecessary propagation of Anchor URLs and future query parameters to the Description origin.

The Reference Resolver profile **SHOULD** enable `Referrer-Policy: no-referrer` by default.

HTTPS provides transport security to the contacted Web origin. Resolver Core 0.1 does not claim that HTTPS authenticates the Physical Entity, ownership, AR-XML semantics, physical Anchor attachment, or administrative authority.

HSTS and related origin-hardening controls belong to deployment profiles rather than Core protocol semantics.

## 18. Resolver and AR-XML responsibility boundary

Resolver Core knows only enough information to perform resolution.

A Core implementation may internally store:

```text
Anchor UUID
Lifecycle status
Current AR-XML Description Location
```

and administration metadata.

Resolver Core **MUST NOT** require knowledge of AR-XML category, profiles, capabilities, inputs, results, interfaces, Entity current IP address, device control protocol, management UI, or capability invocation state.

The Resolver **MUST NOT** fetch or parse AR-XML as a prerequisite for returning the registered current Description Location.

This restriction also prevents ordinary resolution from becoming an external-request amplification path or generic SSRF proxy.

## 19. Resolver and Manifest boundary

Manifest is a separate specification.

A conforming Resolver Core 0.1 deployment **MUST** be able to resolve an ACTIVE UUID directly to its AR-XML Description Location without requiring a Manifest.

```text
Resolver Core
↓ 303
AR-XML
```

A richer deployment **MAY** additionally expose a Manifest through a separately specified mechanism.

Manifest availability, retrieval, parsing, validation, or failure **MUST NOT** be a prerequisite for normal Core 0.1 L1 resolution.

Canonical Entity Identity representation, richer lifecycle metadata, version information, and future security/trust references belong to the Manifest layer.

## 20. L1 and future L2 boundary

L1 is:

```text
public
anonymous
read-only
GET
UUID lookup
HTTPS-only dereference chain
303 to current AR-XML Description Location
consumer-controlled network policy
```

Resolver Core 0.1 leaves the following for later levels/specifications:

- Runtime-to-Resolver authentication;
- stronger Resolver authentication semantics beyond ordinary HTTPS origin authentication;
- public-key verification;
- signatures;
- authenticated update;
- `PUT` / `PATCH` mutation;
- ownership and authority transfer;
- higher-level security negotiation;
- trust-chain validation.

Core 0.1 nevertheless defines one mandatory forward-security rule: an unsupported requested `l` **MUST NOT** be silently downgraded to L1.

A later level **MUST NOT** redefine an existing Anchor UUID merely because a stronger security level is requested.

## 21. Security non-goals

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

A consumer **MUST NOT** treat `303 See Other`, an Anchor UUID, or an L1 result as proof of any of those properties.

```text
L1
≠ Entity trust rating
≠ Entity safety rating
≠ authenticity proof
≠ authorization
```

## 22. Prohibited responsibility expansion

A Resolver Core 0.1 implementation **MUST NOT** make ordinary resolution depend on patterns such as:

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

A deployment **MAY** operate unrelated device-management services, but those services **MUST** remain outside Resolver Core semantics.

## 23. Privacy, logging, and information disclosure

L1 is a public lookup protocol. Deployments **MUST** assume that anyone obtaining a valid Anchor URL can attempt resolution.

Therefore:

- Description Locations returned by public L1 resolution **SHOULD** be suitable for public disclosure;
- private administrative data **MUST NOT** be embedded in public error responses;
- unknown and SUSPENDED records deliberately share `404` behavior;
- implementations **SHOULD** avoid response differences that unnecessarily expose internal state;
- Anchor UUIDs **SHOULD** be treated as potentially linkable operational metadata in access logs, CDN logs, WAF logs, analytics, and proxy logs;
- deployments **SHOULD** minimize unnecessary log retention, access, and secondary use of Anchor identifiers;
- deployments **SHOULD** take particular care before logging URL query parameters introduced by later security levels.

Resolver Core 0.1 does not provide confidentiality for the existence of ACTIVE or RETIRED identifiers.

## 24. Operational resilience guidance

Resolver Core intentionally performs only bounded local resolution work and does not fetch AR-XML or contact devices during ordinary resolution.

Reference implementations **SHOULD** use ordinary operational controls such as:

- indexed UUID lookup;
- bounded request and database timeouts;
- connection limits;
- deployment-appropriate rate limiting;
- bounded diagnostic/error bodies;
- safe handling of malformed requests.

These controls are implementation/deployment guidance and do not expand Core protocol responsibility.

## 25. Reference interaction

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
Referrer-Policy: no-referrer
```

The consumer then applies its own network policy before dereferencing the Description Location.

An unsupported higher-level request against a Core 0.1-only Resolver is expected to fail closed, for example:

```http
GET /relink/550e8400-e29b-41d4-a716-446655440000?l=2&p=example HTTP/1.1
Host: resolver.example
```

```http
HTTP/1.1 501 Not Implemented
Cache-Control: no-store
```

## 26. Conformance

A deployment claiming **RELink Resolver Core 0.1 L1 conformance** **MUST**, at minimum:

1. expose an HTTPS GET resolution endpoint using an RFC 9562 UUID lookup key;
2. treat the UUID as opaque and never as a credential;
3. return `303 See Other` with a validated absolute HTTPS current AR-XML Description Location for ACTIVE records;
4. fail closed for unsupported requested `l` values rather than silently processing them as L1;
5. apply the status semantics defined for malformed, unknown/SUSPENDED, unsupported-method, RETIRED, unsupported-level, and server-failure cases;
6. process unsupported methods independently of UUID registration state;
7. keep public resolution read-only;
8. preserve Entity/Location, Resolution/Authentication, and Description/Execution separation;
9. avoid requiring Manifest, Trust, capability execution, device-network discovery, or AR-XML fetch/parse for ordinary resolution;
10. send explicit cache behavior as required by this specification.

A consumer claiming **RELink Resolver Core 0.1 L1 processing conformance** **MUST**, at minimum:

1. reject HTTPS-to-HTTP downgrade throughout Anchor, Resolver, Description, and AR-XML redirect dereferencing;
2. require the final AR-XML document URL to use HTTPS;
3. apply its own network/security policy before dereferencing Resolver-supplied locations and redirect targets;
4. not interpret successful resolution as authentication, authorization, trust, or safety proof;
5. use the final AR-XML document URL as the base for subsequent AR-XML relative-URL processing where applicable.

CORS support is RECOMMENDED for browser-oriented public deployments but is not required for non-browser consumers unless a deployment profile requires it.

## 27. Related specifications and standards

Resolver Core 0.1 relies on existing Web standards rather than redefining them.

Relevant standards include:

- RFC 9110 — HTTP Semantics
- RFC 9111 — HTTP Caching
- RFC 9562 — Universally Unique IDentifiers (UUIDs)
- RFC 9457 — Problem Details for HTTP APIs
- Referrer Policy
- Fetch Standard — redirects and browser CORS processing

Related RELink work:

- AR-XML Core 0.1
- RELink Manifest 0.1
- RELink Web Runtime integration
- RELink Resolver Testbed cases
- future RELink Trust / higher security-level specifications

## 28. Design summary

```text
Input:
    public HTTPS GET
    /{resolver-service}/{uuid}

Default profile:
    no l = L1

Unsupported requested level:
    fail closed
    SHOULD return 501

Identifier:
    RFC 9562 UUID
    opaque
    not a credential

ACTIVE:
    303 See Other
    Location = validated current HTTPS AR-XML Description Location

SUSPENDED / unknown:
    404 Not Found

RETIRED:
    410 Gone

Unsupported method:
    405 Method Not Allowed
    before registration-state lookup

Resolver failure:
    500 / 503

Redirect security:
    HTTPS-only for L1
    no HTTPS→HTTP downgrade

Consumer boundary:
    Resolver Location = untrusted network input
    consumer Network Policy decides whether to fetch

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
