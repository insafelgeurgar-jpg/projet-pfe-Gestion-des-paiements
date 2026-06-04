# Marketplace escrow architecture

## Flow

1. **Client** creates contest with dynamic budget (min enforced server-side).
2. **Stripe Checkout** charges the **platform** account; `transfer_group` + metadata link payment to contest.
3. Contest → `active`; funds marked `escrow_held` in `contest_payments`.
4. **Freelancers** submit; **voting** opens; users vote.
5. **Client/admin** selects winner → `stripe.transfers.create` with `source_transaction` when available.
6. Contest → `payout_sent`; `escrow_held` cleared.

## Stripe Connect setup

1. Enable Connect in Stripe Dashboard (Express accounts).
2. Set redirect URLs to `{CLIENT_URL}/onboard/return` and `/onboard/refresh`.
3. Configure webhook endpoint `POST {API_URL}/webhook` with events:
   - `checkout.session.completed`
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `transfer.created`
   - `transfer.failed`
   - `payout.paid`
   - `payout.failed`
   - `account.updated`

## Environment variables

See `backend/.env.example` and `frontend/.env.example`.

## Contest statuses

`draft` → `payment_pending` → `active` → `voting` → `completed` → `payout_sent` | `cancelled`

- **completed**: voting closed; ready for winner selection / escrow release
- **payout_sent**: Stripe transfer to freelancer Connect account succeeded
