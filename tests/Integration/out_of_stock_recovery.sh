#!/bin/sh

set -eu
. "$(dirname "$0")/lib.sh"

trap restore_provider_defaults EXIT
configure_provider A out_of_stock
configure_provider B out_of_stock
suffix="$(unique_suffix)"
order_id="ord_stock$suffix"
event_id="evt_stock$suffix"

create_order "$order_id" 'KEY-CS2-PRIME'
send_webhook "$event_id" "$order_id" 1290
wait_for_status "$order_id" out_of_stock

assert_equals 0 "$(sql_scalar "SELECT COUNT(*) FROM provider_issues WHERE order_public_id = '$order_id'")" \
    'empty stock does not reserve a code'

configure_provider B success
curl --fail --silent --show-error \
    -X POST "$BASE_URL/api/v1/ops/recovery" \
    -H 'Content-Type: application/json' \
    --data "{\"order_id\":\"$order_id\"}" \
    >/dev/null
wait_for_status "$order_id" delivered

assert_equals 1 "$(sql_scalar "SELECT COUNT(*) FROM fulfillments f JOIN orders o ON o.id = f.order_id WHERE o.public_id = '$order_id'")" \
    'recovered order is fulfilled exactly once'

printf 'Out-of-stock test passed: state remained recoverable and later completed safely.\n'
