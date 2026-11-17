# Pixelforge Studio

A take-home assessment app: users sign in, generate AI images through the fictional
[Pixelforge](https://pixelforge.promptedit.dev) provider, and pay Pixelforge's cost
plus a 25% markup in credits. Built with Laravel, Vue 3 and Inertia.

## Try it

Log in with the demo account (an admin, with $10 of credits):

```
demo@pixelforge.test
pixelforge-demo
```

The Generate page has one-click chips for Pixelforge's test keywords (`[fail]`,
`[duplicate]`, `[slow]`, `[overloaded]`, `[blocked]`), so every failure path can be
tried in a few clicks. The admin report is at `/admin/report`.

## Features

- **Generate:** prompt, size, quality and up to 4 reference-image URLs, with a live
  price estimate (min, expected, max) shown before you submit.
- **History:** live status (pending → queued → processing → succeeded or failed),
  estimated vs. actual cost, the image, and an itemised token receipt.
- **Balances:** each user's credit balance, a "Buy credits" test purchase, and
  submissions blocked when the worst-case price is unaffordable.
- **Admin report** (`/admin/report`): credit spend, provider cost and profit per UTC
  day and per user, reconciled against Pixelforge's `GET /v1/usage`.
- **Credit activity** (`/credits`): every balance change, auditable row by row.

## How the money works

- All amounts are integer **picodollars** (`1 USD = 10¹²`), see `App\Support\Money`.
  Pixelforge reports costs to 9 decimals and the markup adds more, so floats would
  drift and cent integers would lose precision.
- On submit, the **worst-case price** (`max_cost_pico`) is held from the user's
  balance. When the generation finishes, the hold is released and the exact price is
  charged. Failed generations charge nothing. A charge is never more than the held max.
- Every balance change is a row in `credit_ledger` (`hold`, `release`, `charge`,
  `purchase`). A user's balance is the sum of their rows, so it is fully auditable, and
  a `unique(generation_id, type)` index makes double-charging impossible even under races.

## How the lifecycle works

- `CreateGeneration` prices the request, locks the user row, checks the balance, writes
  the generation and the hold in one transaction, then queues `SubmitGeneration`.
- The submit job sends our `uuid` as the `Idempotency-Key` (so retries never create a
  second billed generation) and in `metadata` (so a webhook that arrives before the
  POST response can still be matched). Rate limits, the concurrency cap and overloads
  retry with `Retry-After`-aware backoff; rejections fail the generation for free.
- Webhooks: the signature is verified on the raw body (HMAC-SHA256 plus a timestamp
  tolerance), each `event_id` is recorded once (`insertOrIgnore` and a unique index),
  and applying the event happens in the same transaction. If applying fails, both roll
  back and Pixelforge retries. Status only moves forward, so late or duplicated events
  can't take a generation back to an earlier state.
- `generations:sync` (scheduled every minute) polls Pixelforge for running generations
  that have gone quiet, because webhook delivery isn't guaranteed.
- If we ever give up on a generation that Pixelforge actually finished, a late success
  webhook still records the provider cost so the admin report reconciles. The user
  keeps their "not charged" promise and gets the image for free.

## Decisions and trade-offs

- **Fractions of a cent.** The brief leaves this open. Credits are kept exact to the
  picodollar and never rounded, so a balance can read `$45.773859375`. Every charge
  then matches Pixelforge's cost plus 25% exactly, and the books always add up. The
  markup never needs rounding either: every published rate works out to a whole number
  of picodollars per token, and so does 1.25 times it.
- **The estimate is a range.** Output and reference-image tokens are known up front.
  Text tokens are only estimated (`ceil(characters / 4)`, real count within ±15%) and
  reasoning tokens fall in a range set by quality. We show min, expected and max, hold
  the max, and charge the exact amount once usage comes back.
- **Billing day.** Pixelforge bills a generation on the UTC day it completed, not the day
  it was created, so the admin report groups the same way and reconciles day by day.
- **Expired images.** Pixelforge image links expire 24 hours after completion, which the
  brief allows. Expired images show a clear "Image link expired" tile instead of a
  broken image.
- **What I'd do next:**
    - Our own "3 at a time" submit queue, so we never hit Pixelforge's concurrency limit
      and requests go out in order.
    - Store finished images ourselves, so history keeps its images after 24 hours.
    - Real payments with Stripe Checkout, adding credits only once Stripe's webhook
      confirms the payment.

## Setup

```bash
composer run setup          # deps, .env, key, migrate, npm build
```

Then set in `.env`:

```dotenv
PIXELFORGE_API_KEY=pf_...           # your Pixelforge key
PIXELFORGE_WEBHOOK_SECRET=whsec_... # that key's webhook signing secret
```

Use one API key per environment. Each key has its own webhook URL, secret and
`/v1/usage` report, so a deployed app's numbers reconcile exactly.

```bash
composer run dev            # server + queue + vite
php artisan schedule:work   # runs generations:sync every minute
php artisan db:seed         # demo@pixelforge.test / pixelforge-demo (admin)
DEMO_LOGIN_HINT=true        # shows those credentials on the landing page
```

For local webhooks, point the key's webhook URL at a tunnel
(Expose, ngrok, Cloudflare Tunnel): `https://<host>/webhooks/pixelforge`.

Production needs a **queue worker** and the **scheduler** running, or generations
will sit at `pending` forever.

## Testing

```bash
php artisan test            # 61 tests: pricing math, webhook dedup/ordering, billing
vendor/bin/pint --dirty     # code style
```

Useful commands:

```bash
php artisan generations:sync  # poll stale running generations now
php artisan user:make-admin <email>
```

---

Pixelforge Studio™, built by [Austin Opia](https://github.com/thomsontochi)
([austinopia.aidevelopia.com](https://austinopia.aidevelopia.com)) as a take-home
assessment for PromptEdit.
