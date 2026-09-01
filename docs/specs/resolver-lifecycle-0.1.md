# RELink Resolver Lifecycle 0.1

Status: Frozen 2026-09-01  
Version: 0.1  
Scope: Resolver record lifecycle and state-transition model

Freeze policy: Resolver Lifecycle 0.1 is frozen as of 2026-09-01. Editorial and non-semantic errata MAY be corrected within 0.1. Changes to lifecycle states, permitted transitions, RETIRED terminal semantics, permitted-transition support requirements, administrative failure semantics, same-state no-op/history semantics, initial-registration semantics, public lifecycle mapping/non-distinction, cache semantics, Manifest lifecycle mapping, or conformance-derivation semantics require a later Lifecycle version or separately versioned profile.

## 1. Purpose

This specification defines the lifecycle state machine for RELink Resolver records independently of implementation details.

It reuses the lifecycle states and public HTTP semantics already defined by Resolver Core 0.1 and aligns them with the Frozen RELink Manifest 0.1 lifecycle vocabulary.

```text
Resolver Core = public lifecycle effect
Lifecycle 0.1 = state-transition model + maintenance/history rules
Manifest 0.1 = descriptive lifecycle metadata
Trust/L2 = future authorization/authenticity rules
```

This specification does not add authentication, ownership-transfer, Trust, capability execution, device management, or AR-XML semantics to Resolver Core.

## 2. Requirements language

The key words **MUST**, **MUST NOT**, **REQUIRED**, **SHOULD**, **SHOULD NOT**, **MAY**, and **OPTIONAL** are to be interpreted as described in BCP 14 (RFC 2119 and RFC 8174) when they appear in all capitals.

## 3. Lifecycle states

Resolver Lifecycle 0.1 defines exactly three record states:

```text
ACTIVE
SUSPENDED
RETIRED
```

Their meanings are:

- **ACTIVE**: the record is currently available for normal public L1 resolution.
- **SUSPENDED**: the record remains known to the Resolver but is temporarily unavailable for normal public L1 resolution.
- **RETIRED**: the record has been permanently withdrawn from normal RELink resolution.

These state names and meanings MUST remain consistent with Resolver Core 0.1.

The corresponding Frozen Manifest 0.1 values are:

```text
ACTIVE    ↔ active
SUSPENDED ↔ suspended
RETIRED   ↔ retired
```

## 4. Public HTTP mapping

Lifecycle 0.1 does not redefine Resolver Core public response semantics. A conforming implementation MUST preserve:

| State | Resolver Core public L1 behavior |
| --- | --- |
| ACTIVE | `303 See Other` to the current validated HTTPS Description Location |
| SUSPENDED | `404 Not Found` |
| RETIRED | `410 Gone` |

The public interface intentionally does not distinguish an unknown UUID from a SUSPENDED UUID.

Public error representations SHOULD avoid deliberately revealing whether a `404 Not Found` corresponds to an unknown UUID or a SUSPENDED record. Lifecycle 0.1 does not require complete timing or side-channel indistinguishability.

Lifecycle reason, transition history, actor metadata, timestamps, or administrative annotations MUST NOT alter the public status-code mapping above unless a later versioned RELink specification explicitly defines another profile.

## 5. State-transition model

The permitted state transitions are:

```text
ACTIVE    → SUSPENDED
SUSPENDED → ACTIVE
ACTIVE    → RETIRED
SUSPENDED → RETIRED
```

The state machine is therefore:

```text
          suspend
ACTIVE ─────────────→ SUSPENDED
  │                     │
  │ retire              │ reactivate
  │                     ↓
  │                   ACTIVE
  │                     │
  └──────────────┐      │ retire
                 ↓      ↓
               RETIRED
```

An implementation claiming Lifecycle 0.1 administrative-transition conformance MUST support each permitted transition applicable to an existing record. This requirement means that the transition edge is implemented and available to the administrative transition function; it does not require every individual request to succeed when authorization, concurrency, validation, persistence, or other applicable preconditions fail.

A request to perform any transition not listed above MUST be rejected by the maintenance layer.

A transition whose source state is not the record's actual current state MUST NOT be silently coerced into another transition.

Administrative transition processing MUST preserve the semantic distinction between at least the following failure classes, even when an implementation maps them to implementation-specific errors or transport responses:

```text
INVALID_TRANSITION
    requested edge is not permitted by the Lifecycle 0.1 state machine

STATE_CONFLICT
    the expected/source state is stale or no longer matches the current record state

AUTHORIZATION_FAILURE
    the caller is not authorized to perform the requested administrative operation

PERSISTENCE_FAILURE
    an otherwise permitted operation could not be committed safely
```

Lifecycle 0.1 does not standardize exception names, response bodies, or HTTP status codes for these administrative failure classes. An implementation MUST NOT treat a stale-state conflict as if the requested edge were permanently forbidden by the lifecycle state machine.

Repeatedly setting a record to its already-current state MAY be treated as an idempotent no-op by an administrative implementation, but such a no-op MUST NOT be represented as a different lifecycle transition. An idempotent same-state no-op MUST NOT be recorded as a lifecycle transition event. It MAY be recorded separately as an administrative request, audit, or diagnostic event.

## 6. RETIRED is terminal

RETIRED is terminal in Resolver Lifecycle 0.1.

A record in RETIRED state MUST NOT transition back to ACTIVE or SUSPENDED under Resolver Lifecycle 0.1.

```text
RETIRED → ACTIVE      forbidden
RETIRED → SUSPENDED   forbidden
```

If a deployment must create a new resolvable record after retirement, it MUST use a new Resolver record/Anchor identity rather than reviving the retired record under 0.1 semantics.

A future versioned RELink specification MAY define migration or recovery semantics, but such semantics MUST NOT be inferred from Lifecycle 0.1.

## 7. Initial registration state

A newly registered Resolver record MUST have an explicit lifecycle state before it becomes externally observable.

Initial registration establishes that initial lifecycle state. It is not a Lifecycle 0.1 transition from an implicit `NONEXISTENT` state, and implementations MUST NOT extend the Lifecycle 0.1 state machine by treating `NONEXISTENT` as a fourth lifecycle state.

A Reference Resolver SHOULD support initial registration as either:

- ACTIVE, when the record is ready for normal public resolution; or
- SUSPENDED, when the record is intentionally staged but not yet publicly resolvable.

Ordinary new-record registration SHOULD NOT create a RETIRED record. Import, archival restoration, or migration tooling MAY reconstruct a historical RETIRED record when preserving existing lifecycle history.

The choice of initial state does not authenticate the registrant or establish ownership.

## 8. Suspension semantics

SUSPENDED represents temporary public unavailability, not deletion.

A SUSPENDED record:

- remains known to the Resolver internally;
- MAY retain its current Description Location and Manifest-related metadata internally;
- MUST return the public behavior defined for SUSPENDED by Resolver Core;
- MAY later transition back to ACTIVE;
- MAY transition permanently to RETIRED.

Suspension MUST NOT imply that the underlying Physical Entity, AR-XML document, owner, or authority has been authenticated, revoked, compromised, or otherwise assigned a Trust status.

## 9. Retirement semantics

RETIRED represents permanent withdrawal of the Resolver record from normal RELink resolution under Lifecycle 0.1.

A RETIRED record:

- remains identifiable internally for lifecycle/history purposes;
- MUST return `410 Gone` on the public Resolver Core path;
- MUST NOT return to ACTIVE or SUSPENDED;
- MAY retain administrative metadata, Description Location history, Manifest snapshots, and audit/history records internally;
- MUST NOT be interpreted as permission to reuse the same Anchor UUID for a different Entity or different Resolver record.

Retirement is a lifecycle statement, not an ownership-transfer or Trust statement.

## 10. Transition reasons and administrative metadata

A lifecycle transition MAY carry an administrative reason or note.

Reason metadata:

- MUST NOT be required for the public Resolver Core response;
- MUST NOT alter the lifecycle state's normative meaning;
- MUST NOT authorize the transition merely by being present;
- SHOULD be treated as administrative metadata;
- MAY be exposed in authenticated administration, export, audit, or diagnostic contexts;
- SHOULD NOT be exposed through the public Core response unless a later specification explicitly defines such disclosure.

Reason taxonomies, free-text formats, localization, and organization-specific codes are implementation/profile concerns and are not standardized by Lifecycle 0.1.

Frozen Manifest 0.1 does not require lifecycle reason fields. Lifecycle 0.1 MUST NOT require modification of Frozen Manifest 0.1 to represent reasons.

## 11. Transition history

A Reference Resolver SHOULD retain a bounded history of material lifecycle transitions.

For each retained transition event, the implementation SHOULD be able to represent at least:

```text
record identifier
previous state
new state
transition time
```

It MAY additionally retain:

```text
administrative reason
implementation-defined actor/reference metadata
request/correlation identifier
other diagnostic metadata
```

Actor/reference metadata is operational metadata only. Lifecycle 0.1 does not define actor authentication, authorization, identity proof, ownership, or Trust semantics.

While a history event is retained, implementations SHOULD NOT rewrite its previous/new state or timestamp in a way that misrepresents the actual transition.

Bounded retention, archival, compaction, privacy minimization, and log rotation are permitted. Lifecycle 0.1 does not require an append-only ledger, blockchain, external transparency service, third-party timestamping, or specialized storage technology.

## 12. State-change atomicity

A lifecycle transition SHOULD be committed atomically with the Resolver record's current lifecycle state where the implementation/storage model permits it.

The Reference Resolver SHOULD avoid externally observable intermediate states in which:

- the public Resolver reports a new state while administrative state still reports the old state; or
- transition history reports completion while the effective Resolver record state was not changed.

If an implementation cannot complete the requested transition safely, it SHOULD leave the previous lifecycle state effective and report an administrative failure rather than partially applying the transition.

This requirement concerns consistency only and does not define a database transaction mechanism.

## 13. Description Location across transitions

A lifecycle state transition does not inherently change the current Description Location.

Therefore:

```text
ACTIVE → SUSPENDED
```

MAY preserve the stored Description Location unchanged.

```text
SUSPENDED → ACTIVE
```

MAY reactivate using the previously stored Description Location, provided the location still satisfies Resolver Core validation requirements at response time.

A deployment MAY update the Description Location before, after, or as part of an administrative lifecycle workflow, but Location mutation and lifecycle mutation remain distinct semantic operations.

```text
Lifecycle state ≠ Description Location
```

RETIRED records MAY retain the last known Description Location internally for history/audit purposes, but the public Resolver MUST return `410 Gone` rather than redirecting to it.

## 14. Manifest relationship

Frozen Manifest 0.1 represents lifecycle state descriptively as:

```json
{
  "lifecycle": {
    "status": "active"
  }
}
```

Lifecycle 0.1 MUST preserve the fixed mapping between Resolver state and Manifest lifecycle value.

For a Manifest representation generated from a Resolver record at a given logical state, the serialized `lifecycle.status` MUST correspond to that record's lifecycle state.

Public Manifest retrieval behavior remains governed by Frozen Manifest 0.1:

```text
ACTIVE    → 200 Manifest
SUSPENDED → 404
RETIRED   → 410
```

Because public SUSPENDED/RETIRED Manifest retrieval normally does not return a Manifest representation, the `suspended` and `retired` values remain useful for administration, export, archival, testing, and future profiles.

Lifecycle 0.1 MUST NOT redefine Frozen Manifest 0.1 or require new Manifest fields.

## 15. Cache behavior across transitions

A lifecycle transition changes the origin's effective response for subsequent requests after the transition is committed.

Examples:

```text
ACTIVE → SUSPENDED
origin response changes from 303 to 404
```

```text
ACTIVE → RETIRED
origin response changes from 303 to 410
```

```text
SUSPENDED → ACTIVE
origin response changes from 404 to 303
```

Existing HTTP caches MAY continue serving a previously stored response while that response remains fresh according to ordinary HTTP caching semantics and the cache policy emitted before the transition.

Lifecycle 0.1 therefore does not promise instantaneous global invalidation of previously cached responses.

Implementations SHOULD use the Resolver Core cache policy to bound stale lifecycle visibility.

In particular:

- successful `303` responses MUST use the explicit Core cache policy;
- SUSPENDED/unknown `404` responses SHOULD use `Cache-Control: no-store`;
- RETIRED `410` responses MAY use a finite cache lifetime because RETIRED is terminal;
- administrative tooling MAY provide cache-purge integration as an implementation feature, but cache purging is not required for Lifecycle 0.1 conformance.

A state transition MUST NOT depend on a cache-purge service in order to be considered committed at the Resolver origin.

## 16. Concurrent administrative transitions

Administrative implementations SHOULD use concurrency control sufficient to prevent two conflicting lifecycle transitions from both being accepted against the same prior state.

Conceptually, a transition is evaluated as:

```text
expected current state
        ↓
validate permitted transition
        ↓
commit new state
```

If the actual current state changed before commit, the administrative operation SHOULD fail or be retried against the new state rather than silently applying a transition based on stale assumptions. Such a failure is a state conflict, not evidence that the requested transition edge is invalid under the Lifecycle 0.1 state machine.

Lifecycle 0.1 does not mandate optimistic locking, database row locking, ETags, compare-and-swap, or any specific concurrency mechanism.

## 17. Public/admin boundary

Lifecycle mutation is not part of the public Resolver Core GET interface.

A conforming implementation:

- MUST keep public L1 resolution read-only;
- MUST NOT permit lifecycle mutation merely through knowledge of an Anchor UUID;
- SHOULD expose mutation only through a separate administrative surface if mutation is implemented;
- MUST NOT interpret Lifecycle 0.1 as defining an authentication or authorization protocol.

Administrative authentication and authorization are deployment concerns until a later RELink Trust/L2 specification defines protocol-level semantics.

## 18. Trust and security boundary

Lifecycle 0.1 does not define or prove:

- who owns an Entity;
- who is authorized to transition a record;
- whether an Entity or Resolver is authentic;
- whether a suspension represents compromise;
- whether retirement represents ownership transfer;
- whether a lifecycle transition is cryptographically signed;
- whether a higher RELink security level has been achieved.

A lifecycle state is operational Resolver metadata, not a Trust verdict.

```text
Lifecycle ≠ Authentication
Lifecycle ≠ Authorization
Lifecycle ≠ Ownership
Lifecycle ≠ Trust
```

Future L2/Trust specifications MAY define who may request or authorize lifecycle changes, but MUST preserve the 0.1 state meanings unless a later version explicitly replaces this lifecycle model.

## 19. Reference Resolver requirements

A Reference Resolver implementing Lifecycle 0.1 SHOULD provide administrative capability to:

- view the current lifecycle state;
- transition ACTIVE to SUSPENDED;
- reactivate SUSPENDED to ACTIVE;
- retire ACTIVE or SUSPENDED records;
- reject transitions out of RETIRED;
- record transition time;
- retain bounded transition history;
- optionally record transition reasons;
- distinguish lifecycle mutation from Description Location mutation;
- test the public response corresponding to the current state.

These are administrative requirements only. They do not define a public mutation API. A Reference Resolver claiming the Frozen Catalog's lifecycle-administration conformance target remains subject to the MUST-support rule in Section 5.

## 20. Conformance derivation and catalog ownership

Lifecycle 0.1 defines lifecycle semantics and the behavior from which conformance tests are derived. It does **not** assign or own conformance case identifiers.

The Frozen Resolver / Manifest Conformance Catalog 0.1 is the authoritative registry for lifecycle case identifiers, targets, strengths, and expected results. Implementations, Testbed fixtures, reports, and future references MUST use the Frozen Catalog's `LIFE-*` identifiers rather than inventing or reusing a conflicting Lifecycle-local numbering scheme.

Lifecycle-derived conformance coverage SHOULD include, as applicable:

- ACTIVE, SUSPENDED, and RETIRED public mappings;
- permitted and forbidden lifecycle transitions;
- RETIRED terminal behavior;
- unknown/SUSPENDED public non-distinction;
- independence of lifecycle state and Description Location;
- origin behavior after committed state changes and ordinary cache freshness;
- administrative concurrency/history behavior where covered by the relevant conformance target;
- consistency between Resolver state and Frozen Manifest lifecycle semantics.

Internal database layout, framework behavior, or storage technology MUST NOT be part of protocol conformance testing.

## 21. Design summary

```text
States:
    ACTIVE
    SUSPENDED
    RETIRED

Transitions:
    ACTIVE    → SUSPENDED
    SUSPENDED → ACTIVE
    ACTIVE    → RETIRED
    SUSPENDED → RETIRED

Terminal:
    RETIRED

Public Core mapping:
    ACTIVE    → 303
    SUSPENDED → 404
    RETIRED   → 410

Manifest mapping:
    ACTIVE    ↔ active
    SUSPENDED ↔ suspended
    RETIRED   ↔ retired

History:
    bounded transition history recommended
    time + previous/new state
    reason/actor metadata optional
    same-state no-op is not a lifecycle transition event

Administrative failure semantics:
    invalid transition ≠ stale-state conflict

Cache:
    origin state changes immediately after commit
    existing caches follow ordinary freshness rules

Not Lifecycle responsibility:
    authentication
    authorization
    ownership transfer
    Trust
    capability execution
    AR-XML semantics
    implementation-specific storage
```

Lifecycle 0.1 provides a small, deterministic state machine that can be reused by Resolver Core, Frozen Manifest 0.1, the Reference Resolver, and Resolver Testbed definitions without expanding the Resolver into a Trust or device-management system.
