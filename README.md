# Pixelforge Studio

A take-home assessment app: users sign in, generate AI images through the fictional
[Pixelforge](https://pixelforge.promptedit.dev) provider, and pay Pixelforge's cost
plus a 25% markup in credits. Built with Laravel, Vue 3 and Inertia.

## Features

- **Generate** — prompt, size, quality and up to 4 reference-image URLs, with a live
  price estimate (min / expected / max) shown before you submit.
- **History** — live status (pending → queued → processing → succeeded | failed),
  estimated vs. actual cost, the image, and an itemised token receipt.
- **Balances** — each user's credit balance, a "Buy credits" test purchase, and
  submissions blocked when the worst-case price is unaffordable.
- **Admin report** (`/admin/report`) — credit spend, provider cost and profit per UTC
  day and per user, reconciled against Pixelforge's `GET /v1/usage`.
- **Credit activity** (`/credits`) — every balance change, auditable row by row.

## How the money works

- All amounts are integer **picodollars** (`1 USD = 10¹²`) — see `App\Support\Money`.
  Pixelforge reports costs to 9 decimals and the markup adds more, so floats would
  drift and cent integers would lose precision.
- On submit, the **worst-case price** (`max_cost_pico`) is held from the user's
  balance; when the generation finishes, the hold is released and the exact price is
  charged. Failed generations charge nothing. A charge is never more than the held max.
- Every balance change is a row in `credit_ledger` (`hold`, `release`, `charge`,
  `purchase`). A user's balance is the sum of their rows — fully auditable — and a
  `unique(generation_id, type)` index makes double-charging impossible even under races.

## How the lifecycle works

- `CreateGeneration` prices, locks the user row, checks the balance, writes the
  generation + hold in one transaction, then queues `SubmitGeneration`.
- The submit job sends our `uuid` as the `Idempotency-Key` (so retries never create a
  second billed generation) and in `metadata` (so a webhook that arrives before the
  POST response can still be matched). Rate limits, the concurrency cap and overloads
  retry with `Retry-After`-aware backoff; rejections fail the generation for free.
- Webhooks: signature verified on the raw body (HMAC-SHA256 + timestamp tolerance),
  each `event_id` recorded once (`insertOrIgnore` + unique index), and applying the
  event happens in the same transaction — a failure rolls both back so Pixelforge
  retries. Status only moves forward, so late or duplicated events can't regress state.
- `generations:sync` (scheduled every minute) polls Pixelforge for running generations
  that have gone quiet — webhook delivery isn't guaranteed.
- If we ever give up on a generation Pixelforge actually finished, a late success
  webhook still records the provider cost so the admin report reconciles — the user
  keeps their "not charged" promise and gets the image free.

## Setup

```bash
composer run setup          # deps, .env, key, migrate, npm build
```

Then set in `.env`:

```dotenv
PIXELFORGE_API_KEY=pf_...           # your Pixelforge key
PIXELFORGE_WEBHOOK_SECRET=whsec_... # that key's webhook signing secret
```

Use one API key per environment — each key has its own webhook URL, secret and
`/v1/usage` report, so a deployed app's numbers reconcile exactly.

```bash
composer run dev            # server + queue + vite
php artisan db:seed         # demo@pixelforge.test / pixelforge-demo (admin)
DEMO_LOGIN_HINT=true        # shows those credentials on the landing page
```

For local webhooks, point the key's webhook URL at a tunnel
(Expose, ngrok, Cloudflare Tunnel) → `https://<host>/webhooks/pixelforge`.

Production needs a **queue worker** and the **scheduler** running, or generations
will sit at `pending` forever.

## Testing

```bash
php artisan test            # 60 tests: pricing math, webhook dedup/ordering, billing
vendor/bin/pint --dirty     # code style
```

Pixelforge's prompt keywords (`[fail]`, `[duplicate]`, `[slow]`, `[overloaded]`,
`[blocked]`) are one-click chips on the Generate page, so the failure paths are easy
to exercise end-to-end.

Useful commands:

```bash
php artisan generations:sync  # poll stale running generations now
php artisan user:make-admin <email>
```

---

Pixelforge Studio™ — built by [Austin Opia](https://github.com/thomsontochi)
([austinopia.aidevelopia.com](https://austinopia.aidevelopia.com)) as a take-home
assessment for PromptEdit.
