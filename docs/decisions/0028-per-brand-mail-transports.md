# ADR-0028: Per-brand mail transports

**Status:** accepted

## Context

Messaging's SPEC left transport topology open: one shared mail transport
sending on behalf of every brand, or a transport per brand with its own
credentials, SPF and DKIM on its own domain? Deliverability and per-brand
sender identity pull in opposite directions on a shared transport: mail "from"
`sxlan.de` sent through the operator's shared relay either fails alignment or
requires every brand's DNS to delegate to it.

## Decision

**Each brand carries its own mail transport configuration.** The Messaging
mail driver resolves the transport from the brand context per message, the
same way Reverb resolves per-brand applications (ADR-0025) and broadcasting
resolves per-brand credentials.

- Transport settings are brand data (encrypted at rest), not deployment
  config: host/port/credentials or a provider API key, plus the sender
  identity (from-name, from-address, reply-to). DMARC alignment is per brand
  domain by construction.
- A brand without a configured transport falls back to the application's
  default mailer. In local dev that is Mailpit; in production the fallback is
  the operator's own transport, so a half-configured brand degrades to
  operator-sent mail instead of silence.
- Outbound jobs already restore brand context (ADR-0010); the driver reads
  the transport after restore, so queued mail sends through the brand that
  queued it.

## Consequences

- Brand provisioning grows a mail section (transport + sender identity +
  eventually a DNS checklist: SPF include, DKIM key, DMARC). A settings UI is
  release scope; until it exists, transports are seeded/managed by operators.
- Messaging's brand read model carries transport references; credential
  encryption and rotation become Messaging concerns from day one.
- Per-brand transports mean per-brand bounce/complaint webhooks eventually —
  the delivery-log backlog item inherits that shape.
- The suppression list is per brand, not global, since reputation is now per
  brand domain.
