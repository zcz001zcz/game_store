#!/bin/sh

set -eu

BASE_URL="${BASE_URL:-http://localhost:8080}"
DB_USER="${POSTGRES_USER:-game_store}"
DB_NAME="${POSTGRES_DB:-game_store}"

unique_suffix() {
    printf '%s_%s' "$(date '+%s')" "$$"
}

configure_provider() {
    provider="$1"
    mode="$2"
    timeout_ms="${3:-1500}"

    curl --fail --silent --show-error \
        -X PUT "$BASE_URL/api/v1/dev/providers/$provider" \
        -H 'Content-Type: application/json' \
        --data "{\"mode\":\"$mode\",\"failure_rate\":0,\"timeout_rate\":0,\"timeout_ms\":$timeout_ms}" \
        >/dev/null
}

restore_provider_defaults() {
    curl --fail --silent --show-error -X PUT "$BASE_URL/api/v1/dev/providers/A" \
        -H 'Content-Type: application/json' \
        --data '{"mode":"random","failure_rate":0.1,"timeout_rate":0.1,"timeout_ms":1500}' \
        >/dev/null || true
    curl --fail --silent --show-error -X PUT "$BASE_URL/api/v1/dev/providers/B" \
        -H 'Content-Type: application/json' \
        --data '{"mode":"random","failure_rate":0.05,"timeout_rate":0.05,"timeout_ms":1500}' \
        >/dev/null || true
}

create_order() {
    order_id="$1"
    sku="$2"

    curl --fail --silent --show-error \
        -X POST "$BASE_URL/api/v1/orders" \
        -H 'Content-Type: application/json' \
        --data "{\"order_id\":\"$order_id\",\"sku\":\"$sku\"}" \
        >/dev/null
}

send_webhook() {
    event_id="$1"
    order_id="$2"
    amount="$3"
    status="${4:-paid}"
    occurred_at="${5:-2025-01-01T12:00:00Z}"

    curl --fail --silent --show-error \
        -X POST "$BASE_URL/api/v1/webhooks/payment" \
        -H 'Content-Type: application/json' \
        --data "{\"event_id\":\"$event_id\",\"order_id\":\"$order_id\",\"status\":\"$status\",\"amount\":$amount,\"currency\":\"RUB\",\"created_at\":\"$occurred_at\"}" \
        >/dev/null
}

wait_for_status() {
    order_id="$1"
    expected="$2"
    max_checks="${3:-80}"
    check=0

    while [ "$check" -lt "$max_checks" ]; do
        if curl --fail --silent "$BASE_URL/api/v1/orders/$order_id" | grep -q "\"status\":\"$expected\""; then
            return 0
        fi

        check=$((check + 1))
        sleep 0.25
    done

    printf 'Timed out waiting for order %s to reach %s\n' "$order_id" "$expected" >&2
    return 1
}

sql_scalar() {
    docker compose exec -T db psql -U "$DB_USER" -d "$DB_NAME" -Atc "$1"
}

assert_equals() {
    expected="$1"
    actual="$2"
    message="$3"

    if [ "$expected" != "$actual" ]; then
        printf 'Assertion failed: %s. Expected %s, got %s\n' "$message" "$expected" "$actual" >&2
        exit 1
    fi
}
