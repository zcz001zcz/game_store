#!/bin/sh

set -eu
. "$(dirname "$0")/lib.sh"

trap restore_provider_defaults EXIT
configure_provider A success
configure_provider B success
suffix="$(unique_suffix)"
occurred_at='2025-01-01T12:00:00Z'

same_order="ord_race_same$suffix"
same_event="evt_race_same$suffix"
create_order "$same_order" 'STEAM-TOPUP-500'

seq 1 50 | xargs -P 50 -I '{}' sh tests/Integration/send_webhook.sh \
    "$same_event" "$same_order" 500 "$occurred_at"
wait_for_status "$same_order" delivered

assert_equals 1 "$(sql_scalar "SELECT COUNT(*) FROM payment_events WHERE event_id = '$same_event'")" \
    'same event_id is stored once'
assert_equals 1 "$(sql_scalar "SELECT COUNT(*) FROM ledger_entries l JOIN orders o ON o.id = l.order_id WHERE o.public_id = '$same_order'")" \
    'one ledger capture exists'
assert_equals 1 "$(sql_scalar "SELECT COUNT(*) FROM fulfillments f JOIN orders o ON o.id = f.order_id WHERE o.public_id = '$same_order'")" \
    'one fulfillment exists'
assert_equals 1 "$(sql_scalar "SELECT COUNT(*) FROM provider_issues WHERE order_public_id = '$same_order'")" \
    'provider issued once'

unique_order="ord_race_unique$suffix"
create_order "$unique_order" 'STEAM-TOPUP-500'
seq 1 50 | xargs -P 50 -I '{}' sh tests/Integration/send_webhook.sh \
    "evt_race_${suffix}_{}" "$unique_order" 500 "$occurred_at"
wait_for_status "$unique_order" delivered

assert_equals 50 "$(sql_scalar "SELECT COUNT(*) FROM payment_events WHERE order_public_id = '$unique_order'")" \
    'all distinct events are recorded'
assert_equals 1 "$(sql_scalar "SELECT COUNT(*) FROM ledger_entries l JOIN orders o ON o.id = l.order_id WHERE o.public_id = '$unique_order'")" \
    'distinct concurrent events still create one capture'
assert_equals 1 "$(sql_scalar "SELECT COUNT(*) FROM jobs WHERE aggregate_id = '$unique_order'")" \
    'distinct concurrent events still create one delivery job'
assert_equals 1 "$(sql_scalar "SELECT COUNT(*) FROM fulfillments f JOIN orders o ON o.id = f.order_id WHERE o.public_id = '$unique_order'")" \
    'distinct concurrent events still fulfill once'

printf 'Race test passed: duplicate and 50 distinct concurrent webhooks produced one delivery.\n'
