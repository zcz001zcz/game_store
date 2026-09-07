#!/bin/sh

set -eu

BASE_URL="${BASE_URL:-http://localhost:8080}"
event_id="$1"
order_id="$2"
amount="$3"
occurred_at="$4"

curl --fail --silent --show-error \
    -X POST "$BASE_URL/api/v1/webhooks/payment" \
    -H 'Content-Type: application/json' \
    --data "{\"event_id\":\"$event_id\",\"order_id\":\"$order_id\",\"status\":\"paid\",\"amount\":$amount,\"currency\":\"RUB\",\"created_at\":\"$occurred_at\"}" \
    >/dev/null
