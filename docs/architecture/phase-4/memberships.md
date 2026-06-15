<!-- SPDX-License-Identifier: Apache-2.0 -->
# Paid memberships / subscriptions (Phase 4 · M5)

> ADR-0063 (tiers), ADR-0064 (manual provider), ADR-0065 (Stripe), ADR-0066 (paid clubs).

## How it works (for operators)

Define **membership tiers** in **Admin → Members → Membership tiers** — each grants a set of **perks**
through the permission engine. **No money is charged by NovFora itself.** There are two ways a member
gets a tier:

- **Offline / manual (always available, the live path):** Admin → Members → Memberships — record that
  a member paid (cash, transfer, comp) and grant them a tier, optionally with an expiry. Revoke any
  time. This is the **only** path that grants a membership in this build.
- **Stripe (opt-in, CHARGING DISABLED by default):** when you enable Stripe and add keys, members get
  a **Subscribe** button that sends them to Stripe's **hosted** checkout (card data never touches the
  server). A signed webhook grants the tier after payment.

**Paid clubs (optional, money-fenced):** Admin → Settings → Clubs → "Require a membership to create
clubs" gates club creation on the `tier.create_clubs` perk. No new money path — the perk is acquired
via the membership system above.

## How it works (for developers)

- `App\Membership\TierProjector` mirrors `ClubRoleProjector`: active subscriptions project into per-user
  **global-scope** `acl_entries`, so a perk is a normal `$user->canDo('tier.ad_free', Scope::global())`.
  The clear step is **bounded to the fixed `TierPerks` universe** — a tier can never grant an arbitrary
  capability, and never disturbs other global user grants.
- `MembershipService::activate/cancel/expireDue` is the sole writer of subscription status; every
  transition re-projects + audits. Expiry is a baseline-safe hourly cron (`novfora:tiers:expire`).
- `PaymentProvider` is a semver'd contract; `PaymentProviders` reports enabled + self-checkout
  providers. `ManualPaymentProvider` (live) and `StripePaymentProvider` (disabled by default) implement it.
- **No card data is ever stored.** `member_subscriptions.provider_ref` is an opaque id at most.

## ⚠ SCAFFOLDED — NOT VALIDATED against live Stripe

`StripePaymentProvider::isEnabled()` is **false by default** (requires the enable flag AND a secret key),
so no charge can be initiated by this build. The request SHAPE + the webhook HMAC scheme are proven with
a mocked HTTP client / synthetic signed events. **To validate / enable Stripe:**

1. Create a Stripe account + products.
2. **Admin → Settings → Payments:** paste the secret + publishable keys, toggle on.
3. Add a Stripe webhook endpoint → `https://<site>/webhooks/stripe` for `checkout.session.completed`;
   paste its signing secret into the panel.
4. Run a **test-mode** checkout and confirm the membership is granted.
5. Before relying on auto-renewal, add handling for `invoice.payment_succeeded` /
   `customer.subscription.deleted` (the shape-only receiver handles `checkout.session.completed` only).

Until then, the **offline/manual** provider is the live-granting path.
