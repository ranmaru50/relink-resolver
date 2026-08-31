# RELink Manifest 0.1 — Extension Policy

Status: Frozen normative supplement to Manifest 0.1  
Version: 0.1  
Freeze date: 2026-08-31  
Applies to: `docs/specs/manifest-0.1.md` §12

## 1. Purpose

This document refines the extension and unknown-member rules of RELink Manifest 0.1 without changing the required minimal Manifest representation.

The design goal is to preserve forward compatibility for future RELink-defined members while preventing vendor-specific metadata from occupying or redefining the RELink standard member namespace.

The governing separation is:

```text
RELink standard namespace
    manifestVersion
    anchor
    entity
    description
    lifecycle
    extensions
    future RELink-defined members

Vendor / profile namespace
    extensions[<extension-name>]
```

This supplement does not change Manifest 0.1 wire format, required members, Resolver Core behavior, AR-XML semantics, or Trust/L2 semantics.

This Extension Policy is frozen together with Manifest 0.1. Editorial corrections and non-semantic errata MAY be applied within 0.1, but changes that alter interoperable extension behavior, namespace ownership, compatibility rules, or security semantics require a later Manifest version or separately versioned profile.

The English text is normative. If an official project translation conflicts with this document, this English frozen text governs Manifest 0.1 extension conformance.

## 2. Unknown members

A Manifest 0.1 consumer:

- MUST validate all members whose semantics are defined by Manifest 0.1;
- SHOULD ignore an unknown member that it does not understand unless a later specification explicitly defines different processing semantics;
- MUST NOT treat an unknown member as authentication, authorization, Trust evidence, or proof of a RELink security level;
- MUST NOT allow an unknown member to override, replace, negate, or reinterpret a member defined by Manifest 0.1;
- MUST NOT require an unknown member in order to perform baseline Manifest 0.1 processing.

Unknown top-level members are reserved primarily for forward compatibility with future RELink specifications. Their presence alone MUST NOT be interpreted as a vendor-extension mechanism.

A producer SHOULD NOT introduce a vendor-specific or deployment-specific member directly in the top-level Manifest namespace.

## 3. Vendor and profile extensions

Vendor-specific, product-specific, experimental, or deployment-profile metadata SHOULD be placed under the top-level `extensions` object.

Example:

```json
{
  "extensions": {
    "com.example.relink/device": {
      "model": "RX-100",
      "firmwareFamily": "3.x"
    }
  }
}
```

An extension name:

- MUST be a non-empty JSON object-member name;
- SHOULD be controlled by the extension producer or specification owner;
- SHOULD use a collision-resistant identifier such as a reverse-domain name or an absolute URI;
- SHOULD identify a coherent extension namespace rather than individual unrelated scalar fields.

Examples of suitable names include:

```text
com.example.relink/device
org.example/profile-v1
https://example.com/relink/extensions/device
```

Manifest 0.1 does not require an online registry, DNS lookup, URI dereference, licensing service, or external authority merely to process an extension name.

## 4. Extension isolation

A compatible extension MUST remain semantically subordinate to the Manifest 0.1 core model.

An extension MUST NOT:

- redefine the meaning of `manifestVersion`;
- replace or override `anchor.id`;
- replace or override `entity.id`;
- replace or override `description.location`;
- replace or override `lifecycle.status`;
- redefine the meaning or verification semantics of `description.integrity`;
- make Resolver Core resolution depend on extension processing;
- make baseline Manifest 0.1 interpretation depend on extension processing;
- duplicate AR-XML capability or invocation semantics in a way that changes the meaning of the AR-XML model;
- claim, solely by its presence, that authentication, authorization, Trust verification, or a higher RELink security level has succeeded.

For example, the following extension MUST NOT be used to supersede the standard Description Location:

```json
{
  "description": {
    "location": "https://standard.example/entity.arxml"
  },
  "extensions": {
    "com.example/override": {
      "actualLocation": "https://vendor.example/entity.arxml"
    }
  }
}
```

A vendor-aware consumer MUST NOT reinterpret `actualLocation` as the Manifest 0.1 `description.location` merely because that extension is present.

## 5. Optional processing

A consumer MAY implement one or more known extensions.

A consumer that does not implement an extension:

- SHOULD ignore that extension;
- MUST continue baseline Manifest 0.1 processing when all required standard members are valid;
- MUST NOT fail solely because an optional extension is unknown, unless another specification defining that extension explicitly establishes a different, separately negotiated profile.

A producer MUST NOT describe an extension as generally required for Manifest 0.1 conformance.

If an application requires an extension for application-specific operation, that requirement belongs to the application or profile and MUST NOT redefine baseline Manifest 0.1 conformance.

## 6. Security boundary

Manifest 0.1 assigns no intrinsic Trust semantics to vendor or profile extensions.

An extension MAY carry metadata intended for use by a future Trust, L2, application, or deployment-specific specification, but the verification and failure semantics MUST be defined by that separate specification.

An extension field named, for example, `trusted`, `verified`, `signature`, `publicKey`, or similar MUST NOT acquire RELink Trust semantics merely because of its name or presence.

This rule preserves the boundary:

```text
Manifest extension metadata
≠ authentication
≠ authorization
≠ Trust verification
≠ L2 achievement
```

## 7. Standardization path

A vendor or profile extension MAY later become a candidate for a RELink-defined standard member when interoperability across independent implementations requires common semantics.

Standardization SHOULD proceed by a later Manifest specification or profile that defines:

- the standard member name;
- exact semantics;
- required/optional status;
- compatibility behavior;
- migration from earlier vendor extensions when applicable.

Existing vendor extension names MUST NOT automatically become reserved RELink standard names merely through use.

## 8. Compatibility summary

```text
Unknown standard-looking member
    → SHOULD ignore for forward compatibility

Vendor-specific metadata
    → SHOULD use extensions[namespace]

Unknown extension
    → SHOULD ignore

Extension
    → MUST NOT override standard semantics
    → MUST NOT become a baseline dependency
    → MUST NOT imply Trust/L2
```

The minimal Manifest 0.1 representation remains unchanged.
