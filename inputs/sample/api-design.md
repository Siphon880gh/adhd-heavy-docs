# Payments API — Internal Design (excerpt)

## Goals
- Idempotent charge creation for web + mobile
- Webhook delivery with at-least-once semantics
- PCI scope limited to tokenized PANs via Stripe

## Auth
All server-to-server calls use HMAC-SHA256 over `timestamp.method.path.body`.
Keys rotate every 90 days. Clock skew tolerance: 5 minutes.

## Charge flow
1. Client collects card → Stripe.js → `payment_method` id
2. Backend `POST /v1/charges` with `Idempotency-Key`
3. On success, enqueue `charge.succeeded` outbox row
4. Worker delivers webhooks; receiver must be idempotent

## Open questions
- Do we support partial captures in v1?
- Retry budget for webhook worker (current draft: 8 attempts, expo backoff)
