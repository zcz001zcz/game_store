#!/bin/sh

set -eu
. "$(dirname "$0")/lib.sh"

trap restore_provider_defaults EXIT
configure_provider A fail_before_issue
configure_provider B success
suffix="$(unique_suffix)"
order_id="ord_fallback$suffix"
event_id="evt_fallback$suffix"

create_order "$order_id" 'STEAM-TOPUP-1000'
send_webhook "$event_id" "$order_id" 1000
wait_for_status "$order_id" delivered

assert_equals 0 "$(sql_scalar "SELECT COUNT(*) FROM provider_issues WHERE order_public_id = '$order_id' AND provider = 'A'")" \
    'provider A fails before issuing'
assert_equals 1 "$(sql_scalar "SELECT COUNT(*) FROM provider_issues WHERE order_public_id = '$order_id' AND provider = 'B'")" \
    'provider B performs fallback delivery'
assert_equals 1 "$(sql_scalar "SELECT COUNT(*) FROM fulfillments f JOIN orders o ON o.id = f.order_id WHERE o.public_id = '$order_id'")" \
    'fallback creates one fulfillment'

printf 'Fallback test passed: definitive A failure safely fell back to B.\n'
