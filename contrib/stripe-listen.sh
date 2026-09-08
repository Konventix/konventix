#!/usr/bin/env bash
#
# Stripe webhook tunnel for local development (#69). app-modules/commerce/TODO.md's
# "Local-dev webhooks" line: Mollie/Unzer webhook URLs are built from `app.url`, which
# providers cannot reach from a WSL dev stack — that needs an inbound tunnel (neither ngrok
# nor cloudflared is installed here), so Mollie/Unzer stay out of scope for this script.
# Stripe does not have that problem: `stripe listen` opens an *outbound* connection to
# Stripe and replays events over it, so nothing needs to reach into the dev stack. ADR-0039
# makes webhooks the only source of settlement truth, so this is what end-to-end manual
# testing of the Stripe purchase path needs.
#
# A plain `--forward-to` is enough since ADR-0058: Konventix settles into this instance's own
# Stripe account and uses no connected accounts, so every event this deployment cares about —
# `checkout.session.*`, `charge.dispute.*`, `charge.refunded` — fires on the account the CLI
# is logged into. The `--forward-connect-to` this script used to pass alongside it forwarded
# connected-account events that no longer exist.
#
# Usage:
#   contrib/stripe-listen.sh            forward Stripe events to the local web container
#   contrib/stripe-listen.sh --secret   print only the webhook signing secret, then exit
#
# Requires the Stripe CLI (`stripe`) logged in — `stripe login` once per host.
set -eu
cd "$(dirname "$0")/.."

if ! command -v stripe >/dev/null 2>&1; then
    echo "stripe-listen: the Stripe CLI is not installed — see https://stripe.com/docs/stripe-cli." >&2
    exit 1
fi

# `stripe config --list` only reads ~/.config/stripe/config.toml; it never touches the
# network, so it is a cheap way to fail before `stripe listen` opens a session. Missing
# config and "logged out" both show up the same way: no account_id in the output.
if ! stripe config --list 2>/dev/null | grep -q 'account_id'; then
    echo "stripe-listen: not logged in — run 'stripe login' first." >&2
    exit 1
fi

# APP_PORT is the host-side publish of the web container (compose.yaml); FrankenPHP inside
# the container always listens on 80 regardless of it. Read .env, not .env.example — the
# value is per-developer (18080 on this machine) — and fall back to compose's own default.
app_port=80
if [ -f .env ] && line=$(grep -E '^APP_PORT=' .env | tail -n1); then
    app_port=${line#APP_PORT=}
    app_port=${app_port%$'\r'}
fi

forward_to="http://localhost:${app_port}/api/commerce/webhooks/stripe"

# The events StripeWebhookController handles today or is built to handle next — keep this
# list in step with the controller rather than defaulting to Stripe's "everything" (`*`).
events="checkout.session.completed,checkout.session.async_payment_succeeded,checkout.session.async_payment_failed,checkout.session.expired,charge.dispute.created,charge.dispute.closed,charge.refunded"

if [ "${1:-}" = "--secret" ]; then
    exec stripe listen --print-secret
fi

cat <<MSG
stripe-listen: forwarding to ${forward_to}
stripe-listen: events: ${events}

Before starting, .env needs:
  STRIPE_SECRET=sk_test_...
  STRIPE_WEBHOOK_SECRET=<the whsec_... this prints once connected>

BUT: a Stripe pair entered in the operator console (Settlement accounts -> Stripe account)
outranks .env, per value, since ADR-0058. If you have used that card, the whsec_ this prints
belongs in there too — otherwise every event this script forwards is refused with a 400
signature error while .env looks correct. 'Clear keys' on the same card removes the row and
hands the environment back.

Octane/Sail reads .env live in dev, but a cached config does not follow along — if
STRIPE_WEBHOOK_SECRET was already cached, run 'sail artisan config:clear' after updating it.

MSG

exec stripe listen --forward-to "$forward_to" --events "$events"
