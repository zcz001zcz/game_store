#!/bin/sh

set -eu
. "$(dirname "$0")/lib.sh"

trap restore_provider_defaults EXIT
configure_provider A timeout_after_issue 1500
configure_provider B success
suffix="$(unique_suffix)"
order_id="ord_timeout$suffix"
event_id="evt_timeout$suffix"

create_order "$order_id" 'STEAM-TOPUP-2500'
send_webhook "$event_id" "$order_id" 2500
wait_for_status "$order_id" delivered 120

assert_equals 1 "$(sql_scalar "SELECT COUNT(*) FROM provider_issues WHERE order_public_id = '$order_id' AND provider = 'A'")" \
    'timed-out provider reserves exactly one code'
assert_equals 0 "$(sql_scalar "SELECT COUNT(*) FROM provider_issues WHERE order_public_id = '$order_id' AND provider = 'B'")" \
    'uncertain timeout does not trigger unsafe fallback'
assert_equals 1 "$(sql_scalar "SELECT COUNT(*) FROM fulfillments f JOIN orders o ON o.id = f.order_id WHERE o.public_id = '$order_id'")" \
    'timeout recovery creates one fulfillment'

attempts="$(sql_scalar "SELECT da.attempt_count FROM delivery_attempts da JOIN orders o ON o.id = da.order_id WHERE o.public_id = '$order_id' AND da.provider = 'A'")"

if [ "$attempts" -lt 2 ]; then
    printf 'Assertion failed: expected at least two calls with the stable request_id, got %s\n' "$attempts" >&2
    exit 1
fi

printf 'Timeout test passed: retry recovered the same issued code without fallback or duplication.\n'
