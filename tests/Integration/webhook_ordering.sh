#!/bin/sh

set -eu
. "$(dirname "$0")/lib.sh"

trap restore_provider_defaults EXIT
configure_provider A success
configure_provider B success
suffix="$(unique_suffix)"
order_id="ord_early$suffix"

send_webhook "evt_early_failed$suffix" "$order_id" 399 failed '2025-01-01T11:59:00Z'
send_webhook "evt_early_paid$suffix" "$order_id" 399 paid '2025-01-01T12:00:00Z'

assert_equals 2 "$(sql_scalar "SELECT COUNT(*) FROM payment_events WHERE order_public_id = '$order_id' AND processing_state = 'pending'")" \
    'early webhooks wait for the order'

create_order "$order_id" 'SUB-DISCORD-1M'
wait_for_status "$order_id" delivered

assert_equals 0 "$(sql_scalar "SELECT COUNT(*) FROM payment_events WHERE order_public_id = '$order_id' AND processing_state = 'pending'")" \
    'pending events are replayed when order appears'
assert_equals 1 "$(sql_scalar "SELECT COUNT(*) FROM ledger_entries l JOIN orders o ON o.id = l.order_id WHERE o.public_id = '$order_id'")" \
    'out-of-order events create one capture'

printf 'Webhook ordering test passed: pre-order and out-of-order events were reconciled.\n'
