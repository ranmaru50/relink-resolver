# RELink Web Runtime Integration Contract 0.1

Status: Draft integration specification  
Version: 0.1  
Scope: Resolver Core 0.1 ↔ RELink Web Runtime integration

## 1. Purpose

This document defines how a RELink Web Runtime consumes an Anchor/Resolver URL using ordinary Web dereferencing while preserving the separation between Resolver Core, Manifest, AR-XML, Runtime, and Trust.

```text
Anchor / Resolver URL
    ↓
policy-controlled HTTPS redirect(s)
    ↓
Resolver Core
    ↓ 303
Description Location
    ↓
policy-controlled HTTPS redirect(s)
    ↓
final successful AR-XML response
    ↓
Runtime parse / validate / expose capabilities
```

The integration contract is protocol-facing. It does not prescribe a particular TypeScript implementation, browser framework, fetch library, or internal class layout.

## 2. Architectural boundary and L0/L1 paths

A conforming integration MUST preserve:

```text
Resolution ≠ AR-XML parsing
Resolution ≠ Capability invocation
Manifest ≠ mandatory L1 load dependency
Integrity ≠ Authentication
Entity Identity ≠ Description Location
```

Resolver-specific knowledge MUST NOT be introduced into AR-XML syntax, parser semantics, capability definitions, or invocation semantics merely to support Resolver redirects.

Direct AR-XML loading remains the direct/L0 path. Resolver-mediated loading is the L1 integration path.

```text
L0 / direct
Direct AR-XML URL
    ↓
final AR-XML representation

L1 / Resolver-mediated
Anchor / Resolver URL
    ↓
Resolver / redirects
    ↓
final AR-XML representation
```

Both paths converge at the final representation boundary. This distinction does not require a particular Runtime API or internal mode flag.

## 3. Ordinary Web dereference model

Resolver integration MUST use normal HTTP/Web redirect semantics rather than a Resolver-specific out-of-band lookup protocol.

A Runtime consumer MAY encounter:

```text
Anchor URL
→ ordinary 301/302/303/307/308 HTTPS redirect(s)
→ Resolver URL
→ 303 See Other
→ Description Location
→ ordinary HTTPS redirect(s)
→ final successful AR-XML representation
```

The Runtime MUST NOT assume that the originally supplied URL is the AR-XML document URL.

The Runtime MUST distinguish at least conceptually:

```text
requested URL
final response URL
terminal HTTP status
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

The resource-fetch abstraction used for document loading MUST make enough retrieval metadata available to the Runtime load pipeline to distinguish the originally requested URL from the final response URL and terminal HTTP status.

A fetch result suitable for this contract conceptually contains:

```text
requested URL or equivalent request context
final response URL
terminal HTTP status
representation body
```

When optional integrity verification is supported, the boundary MUST additionally make the representation octets required by Frozen Manifest 0.1 integrity semantics available before character decoding/XML parsing.

This contract does not prescribe a concrete interface signature or data structure.

## 6. Retrieval success, representation bytes, and parsing order

A terminal non-success HTTP response MUST be handled as an HTTP/retrieval failure and MUST NOT be passed to the AR-XML parser as if it were a successful Description representation.

Where AR-XML or deployment rules require representation/media compatibility checks, those checks MUST occur before the representation is accepted as AR-XML input.

Without optional Manifest integrity verification, the Runtime MAY process the successful final AR-XML representation through the implementation's ordinary HTTP/text decoding path before XML parsing, subject to AR-XML requirements.

When integrity verification is enabled, the processing order MUST preserve Frozen Manifest 0.1 byte semantics:

```text
policy-controlled HTTP dereference / redirects
↓
terminal successful HTTP response
↓
representation/media compatibility checks where applicable
↓
HTTP content-coding processing
↓
final representation body octets
↓
digest verification
↓
character decoding
↓
XML parsing
↓
AR-XML validation
```

The digest MUST NOT be computed over an intermediate redirect response body, decoded character string, parsed XML tree, normalized XML, or reserialized XML.

The exact representation octets whose digest is verified MUST be the same representation octets subsequently decoded and parsed as the AR-XML document. An implementation MUST NOT verify one fetched representation and then silently substitute or re-fetch a different representation for parsing under the same verification result.

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

This contract does not define implicit Manifest discovery from an arbitrary Anchor redirect chain, nor does it define discovering a Manifest by reverse inference from the final AR-XML URL.

If Manifest processing is selected, the Manifest source/association MUST be explicit or otherwise defined by a separate applicable profile, such as an explicit Manifest URL, a known Resolver resource, or caller-provided association metadata.

## 8. Optional Manifest integrity verification and binding

If the Runtime claims Frozen Manifest 0.1 integrity-verification support, Manifest retrieval and AR-XML retrieval remain distinct operations.

Conceptually:

```text
Manifest retrieval
↓
Manifest 0.1 baseline validation
↓
applicable association/binding checks
↓
expected description.integrity

Description retrieval
↓
final AR-XML representation octets
↓
verification
```

Integrity metadata MUST be consumed only from a Manifest that has successfully passed applicable Manifest 0.1 baseline validation and association checks.

For deterministic Resolver Manifest retrieval, the path UUID / `anchor.id` consistency requirement defined by Frozen Manifest 0.1 MUST be satisfied before integrity metadata from that Manifest is relied upon.

Where the selected integration profile or caller supplies an association between a Manifest and a Description load, that association MUST be validated according to that profile/policy before applying the Manifest's integrity metadata. This contract does not invent an implicit association rule for arbitrary redirect chains.

A successful digest comparison establishes content-integrity/pinning only. It MUST NOT be surfaced as Entity authentication, Manifest authenticity, Resolver authority, ownership proof, freshness, anti-rollback protection, authorization, or L2 achievement.

A mismatch MUST be exposed as an integrity verification failure. If local policy requires verified integrity, the mismatching representation MUST NOT be accepted as integrity-verified input for subsequent AR-XML processing.

This contract does not require automatic capability invocation, nor does it prescribe a specific Runtime API for representing integrity state.

## 9. Network-security policy and dereference ordering

Resolver- and Manifest-supplied destinations are untrusted network input.

Network/platform policy MUST be applied before or while each dereference occurs, to the extent that the execution environment exposes such control.

Conceptually:

```text
input URL
↓
network/platform policy
↓
HTTPS dereference
↓
redirect target
↓
network/platform policy
↓
next HTTPS dereference
...
↓
final response
```

The integration MUST NOT treat successful Resolver `303`, successful Manifest parsing, valid `anchor.id`, valid `entity.id`, or successful integrity verification as permission to bypass network policy.

For native/server adapters, implementations SHOULD evaluate redirect destinations and policy-denied targets before network access where the HTTP stack permits such control.

For browser adapters, conformance MAY rely on Fetch redirect handling, mixed-content restrictions, CORS/origin policy, browser network protections, and additional Runtime policy that is technically available. Browser application code is not required to inspect redirect targets that the browser platform does not expose.

This specification does not globally forbid loopback/private/local Description Locations. Local Entity access remains deployment/policy dependent.

## 10. HTTPS and downgrade handling

A Runtime claiming Resolver Core 0.1 L1 processing MUST preserve HTTPS throughout the Anchor/Resolver/Description/final-AR-XML chain.

An HTTPS-to-HTTP downgrade anywhere in that chain MUST cause L1 dereferencing to fail.

The final AR-XML Document URL used by L1 MUST use HTTPS.

If optional Manifest retrieval is performed for L1, its complete redirect chain and final Manifest URL MUST independently satisfy Frozen Manifest 0.1 HTTPS requirements.

## 11. Public L1 credentials and browser CORS

Baseline public Resolver Core L1 resolution MUST NOT require credentials. Baseline public Manifest retrieval likewise MUST NOT require credentials merely to satisfy Manifest 0.1 interoperability.

Implementations SHOULD avoid attaching ambient credentials such as cookies to public Resolver/Manifest requests unless explicitly selected by caller or deployment policy.

This guidance does not impose a universal credential rule on Description retrieval or future authenticated profiles.

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
representation/media compatibility failure
representation retrieval failure
integrity verification failure
XML parse failure
AR-XML validation failure
capability invocation failure
```

A terminal non-success HTTP response MUST remain an HTTP/retrieval failure and MUST NOT be transformed into an XML/AR-XML parse failure by parsing its error body as a Description.

Resolver-specific HTTP errors MUST NOT be transformed into AR-XML parse errors.

AR-XML validation errors MUST NOT be represented as Resolver failures.

Integrity failure MUST remain separate from authentication/trust failure semantics.

This contract does not mandate concrete exception names or public API shapes.

## 13. Runtime document identity and observable URL

After successful loading, the Runtime's document-facing URL SHOULD identify the final AR-XML Document URL.

Any public/document property used as the base for relative Interface resolution MUST expose or internally use the final AR-XML response URL rather than the caller-supplied Anchor/Resolver URL.

An implementation MAY separately retain the original requested URL, Resolver/Anchor input, and redirect diagnostics for observability, but they MUST NOT replace the final AR-XML Document URL in AR-XML semantics.

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

Direct AR-XML loading is the direct/L0 path; Resolver-mediated loading is the L1 path. Both SHOULD converge before parsing:

```text
Direct AR-XML URL (L0) ──────────┐
                                  ↓
Anchor/Resolver URL (L1) → redirects → final successful representation + final URL
                                  ↓
                            AR-XML parse/validate
```

The parser SHOULD receive the same representation semantics regardless of whether the original input was direct or Resolver-mediated.

Direct/L0 loading MUST NOT require Resolver or Manifest behavior merely because the Runtime also supports L1 integration.

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
RT-015 terminal non-success HTTP response is not passed to XML parser
RT-016 verified representation octets are the same octets decoded/parsed
RT-017 Manifest integrity metadata is used only after applicable Manifest validation/binding succeeds
RT-018 direct AR-XML load remains L0/direct-compatible and Resolver/Manifest-independent
```

These identifiers are integration handoff identifiers, not additions to Frozen Resolver / Manifest Conformance Catalog 0.1.

## 17. Implementation handoff requirements

The subsequent `relink-web-runtime` implementation task SHOULD examine the current document-loading resource port and adapt it so the load pipeline can obtain the final response URL and terminal response status.

The current implementation model in which a fetch abstraction returns only decoded text is insufficient to implement final-response-URL semantics, HTTP-terminal-status separation, or byte-identity integrity semantics without additional retrieval metadata.

For optional integrity support, an implementation will also need access to the defined final representation body octets before character decoding/XML parsing and MUST ensure those verified octets are exactly those subsequently consumed by the decoder/parser.

Implementation work MUST remain localized to appropriate Runtime/application/adapter boundaries and MUST NOT add Resolver-specific parsing behavior to the AR-XML parser or capability invoker.

## 18. Non-goals

This integration contract does not define:

- production code changes in `relink-web-runtime`;
- a new Resolver protocol;
- Resolver internals or database behavior;
- Manifest as a mandatory L1 dependency;
- implicit Manifest discovery from arbitrary redirect chains;
- automatic capability execution;
- L2 authentication, signatures, ownership, key binding, or Trust;
- AR-XML syntax changes;
- a browser CORS bypass mechanism.

## 19. Conformance summary

A Runtime integration conforming to this contract preserves the following pipeline:

```text
input URL
↓
policy-controlled HTTPS dereference
↓
redirect target → policy-controlled HTTPS dereference (repeat as needed)
↓
terminal successful AR-XML response + final response URL
↓
representation/media compatibility checks where applicable
↓
optional Manifest validation/association + integrity verification
↓
character decoding / XML parse of the same verified representation bytes
↓
AR-XML validation
↓
Runtime document
↓
relative Interface resolution against final AR-XML URL
↓
separate capability invocation
```

The central integration rules are:

```text
Requested URL ≠ necessarily AR-XML Document URL
Final AR-XML response URL = AR-XML document base URL
Verified representation bytes = parsed representation bytes
HTTP terminal failure ≠ AR-XML parse failure
```

This keeps Resolver integration aligned with ordinary Web semantics and preserves RELink's architectural responsibility boundaries.