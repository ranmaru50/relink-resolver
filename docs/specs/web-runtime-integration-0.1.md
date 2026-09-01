# RELink Web Runtime Integration Contract 0.1

Status: Draft integration specification  
Version: 0.1  
Scope: Resolver Core 0.1 ↔ RELink Web Runtime integration

## 1. Purpose

This document defines how a RELink Web Runtime consumes an Anchor/Resolver URL using ordinary Web dereferencing while preserving the separation between Resolver Core, Manifest, AR-XML, Runtime, and Trust.

```text
Anchor / Resolver URL
    ↓
ordinary HTTPS redirect(s)
    ↓
Resolver Core
    ↓ 303
Description Location
    ↓
optional HTTPS redirect(s)
    ↓
final AR-XML response
    ↓
Runtime parse / validate / expose capabilities
```

The integration contract is protocol-facing. It does not prescribe a particular TypeScript implementation, browser framework, fetch library, or internal class layout.

## 2. Architectural boundary

A conforming integration MUST preserve:

```text
Resolution ≠ AR-XML parsing
Resolution ≠ Capability invocation
Manifest ≠ mandatory L1 load dependency
Integrity ≠ Authentication
Entity Identity ≠ Description Location
```

Resolver-specific knowledge MUST NOT be introduced into AR-XML syntax, parser semantics, capability definitions, or invocation semantics merely to support Resolver redirects.

The Runtime MAY start from a direct AR-XML URL, an Anchor URL, or a Resolver URL, provided the resulting dereference process satisfies the applicable L1 transport and network-policy requirements.

## 3. Ordinary Web dereference model

Resolver integration MUST use normal HTTP/Web redirect semantics rather than a Resolver-specific out-of-band lookup protocol.

A Runtime consumer MAY encounter:

```text
Anchor URL
→ ordinary 301/302/303/307/308 redirect(s)
→ Resolver URL
→ 303 See Other
→ Description Location
→ ordinary HTTPS redirect(s)
→ final AR-XML representation
```

The Runtime MUST NOT assume that the originally supplied URL is the AR-XML document URL.

The Runtime MUST distinguish at least conceptually:

```text
requested URL
final response URL
representation body
```

## 4. Final AR-XML response URL

The URL of the final successful AR-XML representation after redirect processing is the **AR-XML Document URL**.

That final URL MUST be used as the document base for URL resolution performed by AR-XML processing.

In particular, relative Interface endpoints MUST be resolved against the final AR-XML Document URL, not against:

- the original Anchor URL;
- an intermediate short URL;
- the Resolver URL;
- the Resolver `303` response URL;
- an intermediate Description redirect URL.

Example:

```text
Input:
https://resolver.example/relink/550e8400-e29b-41d4-a716-446655440000

Resolver:
303 Location: https://entity.example/descriptions/current.xml

Description redirect:
302 Location: https://cdn.example/entity/a/entity.xml

Final AR-XML Document URL:
https://cdn.example/entity/a/entity.xml

AR-XML relative Interface:
./actions/start

Resolved Interface URL:
https://cdn.example/entity/a/actions/start
```

## 5. ResourceFetcher boundary

The integration SHOULD fit the Runtime's existing Ports-and-Adapters architecture at the resource-fetch boundary.

The resource-fetch abstraction used for document loading MUST make enough retrieval metadata available to the Runtime load pipeline to distinguish the originally requested URL from the final response URL.

A fetch result suitable for this contract conceptually contains:

```text
final response URL
representation body
```

When optional integrity verification is supported, the boundary MUST additionally make the representation octets required by Frozen Manifest 0.1 integrity semantics available before character decoding/XML parsing.

This contract does not prescribe a concrete interface signature or data structure.

## 6. Text decoding and AR-XML parsing

Without optional Manifest integrity verification, the Runtime MAY process the final AR-XML representation through the implementation's ordinary HTTP/text decoding path before XML parsing, subject to AR-XML requirements.

When integrity verification is enabled, the processing order MUST preserve Frozen Manifest 0.1 byte semantics:

```text
HTTP dereference / redirects complete
↓
HTTP content-coding processing
↓
final representation body octets
↓  optional digest verification
character decoding
↓
XML parsing
↓
AR-XML validation
```

The digest MUST NOT be computed over an intermediate redirect response body, decoded character string, parsed XML tree, normalized XML, or reserialized XML.

## 7. Manifest independence

Normal Resolver Core 0.1 L1 loading MUST NOT require Manifest retrieval.

The baseline path remains:

```text
Anchor / Resolver URL
↓
303 / redirects
↓
AR-XML
↓
Runtime
```

A Runtime MAY implement optional Manifest retrieval for richer metadata or integrity verification, but Manifest absence, unsupported Manifest functionality, or Manifest endpoint failure MUST NOT invalidate an otherwise successful baseline Core L1 dereference unless the caller explicitly selected a policy/profile that requires Manifest processing.

The Runtime MUST NOT fetch a Manifest merely to discover the Description Location when ordinary Resolver Core resolution already supplies it.

## 8. Optional integrity verification

If the Runtime claims Frozen Manifest 0.1 integrity-verification support, Manifest retrieval and AR-XML retrieval remain distinct operations.

Conceptually:

```text
Manifest
    ↓
expected description.integrity

Description Location
    ↓
final AR-XML representation octets
    ↓
verification
```

A successful digest comparison establishes content-integrity/pinning only. It MUST NOT be surfaced as Entity authentication, Manifest authenticity, Resolver authority, ownership proof, freshness, anti-rollback protection, authorization, or L2 achievement.

A mismatch MUST be exposed as an integrity verification failure. If local policy requires verified integrity, the mismatching representation MUST NOT be accepted as integrity-verified input for subsequent AR-XML processing.

This contract does not require automatic capability invocation, nor does it prescribe a specific Runtime API for representing integrity state.

## 9. Network-security policy

Resolver and Manifest supplied destinations are untrusted network input.

Before or while dereferencing those destinations, the Runtime and/or execution environment MUST apply the network-security controls available in that environment.

The integration MUST NOT treat successful Resolver `303`, successful Manifest parsing, valid `anchor.id`, valid `entity.id`, or successful integrity verification as permission to bypass network policy.

For native/server adapters, implementations SHOULD evaluate redirect destinations and policy-denied targets before network access where the HTTP stack permits such control.

For browser adapters, conformance MAY rely on Fetch redirect handling, mixed-content restrictions, CORS/origin policy, browser network protections, and additional Runtime policy that is technically available.

This specification does not globally forbid loopback/private/local Description Locations. Local Entity access remains deployment/policy dependent.

## 10. HTTPS and downgrade handling

A Runtime claiming Resolver Core 0.1 L1 processing MUST preserve HTTPS throughout the Anchor/Resolver/Description/final-AR-XML chain.

An HTTPS-to-HTTP downgrade anywhere in that chain MUST cause L1 dereferencing to fail.

The final AR-XML Document URL used by L1 MUST use HTTPS.

If optional Manifest retrieval is performed for L1, its complete redirect chain and final Manifest URL MUST independently satisfy Frozen Manifest 0.1 HTTPS requirements.

## 11. Browser CORS behavior

Resolver integration MUST remain compatible with browser Fetch/CORS semantics.

A browser-oriented Resolver SHOULD expose public CORS as defined by Resolver Core, but successful CORS access to the Resolver does not imply that the final AR-XML origin allows browser access.

Each relevant origin/redirect path remains subject to browser platform enforcement.

The Runtime MUST NOT introduce a custom proxy or Resolver-side AR-XML fetch merely to bypass normal browser CORS behavior as part of this integration contract.

## 12. Error boundary

The Runtime SHOULD preserve meaningful separation among failure classes without depending on Resolver implementation internals.

At minimum, an integration implementation SHOULD be able to distinguish conceptually:

```text
network / transport failure
HTTPS downgrade failure
network-policy rejection
HTTP terminal failure
representation retrieval failure
integrity verification failure
XML parse failure
AR-XML validation failure
capability invocation failure
```

Resolver-specific HTTP errors MUST NOT be transformed into AR-XML parse errors.

AR-XML validation errors MUST NOT be represented as Resolver failures.

Integrity failure MUST remain separate from authentication/trust failure semantics.

This contract does not mandate concrete exception names or public API shapes.

## 13. Runtime document identity and observable URL

After successful loading, the Runtime's document-facing URL SHOULD identify the final AR-XML Document URL.

Any public/document property used as the base for relative Interface resolution MUST expose or internally use the final AR-XML response URL rather than the caller-supplied Anchor/Resolver URL.

An implementation MAY separately retain the original requested URL and redirect diagnostics for observability, but they MUST NOT replace the final AR-XML Document URL in AR-XML semantics.

## 14. Capability invocation boundary

Capability discovery and invocation MUST remain AR-XML Runtime concerns.

Resolver Core MUST NOT:

- select a capability;
- construct a capability endpoint;
- invoke a capability;
- provide device current IP resolution for invocation;
- provide a management-console URL as a substitute for AR-XML processing.

The Runtime MUST resolve relative capability Interface endpoints using the final AR-XML Document URL and then apply its ordinary invocation/network-policy logic.

## 15. Compatibility with direct AR-XML loading

Resolver integration MUST NOT remove support for direct AR-XML URLs.

Both paths SHOULD converge before parsing:

```text
Direct AR-XML URL ───────────────┐
                                 ↓
Anchor/Resolver URL → redirects → final representation + final URL
                                 ↓
                           AR-XML parse/validate
```

The parser SHOULD receive the same representation semantics regardless of whether the original input was direct or Resolver-mediated.

## 16. Testbed expectations

Executable integration tests belong in `relink-testbed` and/or `relink-web-runtime`, not in this specification repository.

The implementation handoff SHOULD cover at least:

```text
RT-001 direct AR-XML load preserves behavior
RT-002 Resolver 303 load succeeds
RT-003 pre-Resolver HTTPS redirect succeeds
RT-004 post-Resolver HTTPS redirect succeeds
RT-005 final response URL becomes Runtime document URL/base
RT-006 relative Interface resolves against final URL
RT-007 HTTPS→HTTP downgrade fails
RT-008 configured network-policy denial prevents fetch
RT-009 Resolver CORS does not bypass final-origin CORS
RT-010 Manifest absence does not break baseline L1 load
RT-011 integrity match uses defined final body octets
RT-012 integrity mismatch is distinct from parse/validation failure
RT-013 intermediate redirect body is excluded from digest
RT-014 content-coding semantics match Frozen Manifest 0.1
```

These identifiers are integration handoff identifiers, not additions to Frozen Resolver / Manifest Conformance Catalog 0.1.

## 17. Implementation handoff requirements

The subsequent `relink-web-runtime` implementation task SHOULD examine the current document-loading resource port and adapt it so the load pipeline can obtain the final response URL.

The current implementation model in which a fetch abstraction returns only decoded text is insufficient to implement final-response-URL semantics without additional retrieval metadata.

For optional integrity support, an implementation will also need access to the defined final representation body octets before character decoding/XML parsing.

Implementation work MUST remain localized to appropriate Runtime/application/adapter boundaries and MUST NOT add Resolver-specific parsing behavior to the AR-XML parser or capability invoker.

## 18. Non-goals

This integration contract does not define:

- production code changes in `relink-web-runtime`;
- a new Resolver protocol;
- Resolver internals or database behavior;
- Manifest as a mandatory L1 dependency;
- automatic capability execution;
- L2 authentication, signatures, ownership, key binding, or Trust;
- AR-XML syntax changes;
- a browser CORS bypass mechanism.

## 19. Conformance summary

A Runtime integration conforming to this contract preserves the following pipeline:

```text
input URL
↓
ordinary HTTPS dereference + redirects
↓
network/platform policy
↓
final AR-XML response URL + representation
↓
optional integrity verification
↓
character decoding / XML parse
↓
AR-XML validation
↓
Runtime document
↓
relative Interface resolution against final AR-XML URL
↓
separate capability invocation
```

The central integration rule is:

```text
Requested URL ≠ necessarily AR-XML Document URL
Final AR-XML response URL = AR-XML document base URL
```

This keeps Resolver integration aligned with ordinary Web semantics and preserves RELink's architectural responsibility boundaries.