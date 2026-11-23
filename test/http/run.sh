#!/usr/bin/env bash
# HTTP-level checks of the Mpago module against a running development instance and the fake
# Mercado Pago API (test/fake/router.php). Separate from PHPUnit: it goes through the web server,
# commits to the instance database and leaves behind the documents it creates (a bank account
# MPAGO-TEST, two third parties, two invoices, one order, two payments). Enabling the module
# runs its init() even when it is already enabled, which creates the MPAGO payment mode if missing.
#
# Usage: test/http/run.sh            (instance of dev/env.sh, by default http://127.0.0.1:8080)
# Must run where the web server of the instance is reachable. Exits non-zero on the first failed check.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
MODULE="$(cd "$HERE/../.." && pwd -P)"
DEV="${DEV:-$(dirname "$MODULE")/dev}"
# shellcheck disable=SC1091
. "$DEV/env.sh"
export DOLI_SRC

WORK="$(mktemp -d)"
JAR="$WORK/cookies"
FAKE_PID=""
TOKEN="APP_USR-0000000000-http-check-token"
SECRET="http-check-webhook-secret"
CHECKS=0

helper() { "${PHP_CLI[@]}" "$HERE/helper.php" "$@"; }

cleanup() {
	# Back to an instance that talks to nobody: no fake API address, no fake credentials.
	helper const MPAGO_API_BASE_URL || true
	helper const MPAGO_ACCESS_TOKEN || true
	helper const MPAGO_WEBHOOK_SECRET || true
	[ -z "$FAKE_PID" ] || kill "$FAKE_PID" 2>/dev/null || true
	rm -rf "$WORK"
}
trap cleanup EXIT

# shot <name> <url>: phone-size screenshot into $SHOTS, when that directory is given
shot() {
	[ -n "${SHOTS:-}" ] || return 0
	mkdir -p "$SHOTS"
	chromium --headless=new --no-sandbox --disable-gpu --hide-scrollbars --window-size=390,844 --screenshot="$SHOTS/$1.png" "$2" >/dev/null 2>&1 || true
}

ok() { CHECKS=$((CHECKS + 1)); printf 'ok   %s\n' "$1"; }
fail() { printf 'FAIL %s\n' "$1" >&2; exit 1; }
# check <description> <actual> <expected>
check() { if [ "$2" = "$3" ]; then ok "$1: $2"; else fail "$1: got '$2', expected '$3'"; fi; }
# contains <description> <file> <text>
contains() { if grep -qF -- "$3" "$2"; then ok "$1"; else fail "$1: '$3' not found"; fi; }
lacks() { if grep -qF -- "$3" "$2"; then fail "$1: '$3' found"; else ok "$1"; fi; }
value() { sed -n "s/^$1=//p" | head -1; }
jsonget() { php -r '$d = json_decode((string) stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) { $d = is_array($d) && isset($d[$k]) ? $d[$k] : null; } print is_scalar($d) ? $d : "";' "$1"; }

# --- Fake Mercado Pago API ---------------------------------------------------
PORT="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); print (int) substr(strrchr((string) stream_socket_get_name($s, false), ":"), 1);')"
FAKE="http://127.0.0.1:$PORT"
MPAGO_FAKE_STATE="$WORK/fake.json" php -S "127.0.0.1:$PORT" "$MODULE/test/fake/router.php" >"$WORK/fake.log" 2>&1 &
FAKE_PID=$!
for _ in $(seq 50); do curl -s -o /dev/null "$FAKE/__test/state" && break; sleep 0.1; done
curl -s -o /dev/null -X POST -d "{\"token\":\"$TOKEN\"}" "$FAKE/__test/config"
ok "fake Mercado Pago API listening on $FAKE"

# --- Module enabled and configured through its setup page ---------------------
ACCOUNT="$(helper enable)"
helper const MPAGO_API_BASE_URL "$FAKE"
helper const MPAGO_ACCESS_TOKEN
helper const MPAGO_WEBHOOK_SECRET
ok "module enabled, bank account id $ACCOUNT, MPAGO_API_BASE_URL=$FAKE"
read -r PAYMENT_MODE PAYMENT_MODE_ACTIVE < <(helper paymentmode)
[ "$PAYMENT_MODE" -gt 0 ] || fail "no MPAGO payment mode after enabling the module"
check "MPAGO payment mode (id $PAYMENT_MODE) active" "$PAYMENT_MODE_ACTIVE" 1

formtoken() { grep -o 'name="token" value="[^"]*"' "$1" | head -1 | sed 's/.*value="//; s/"$//'; }

curl -s -c "$JAR" -o "$WORK/login.html" "$DOLI_URL/index.php"
curl -s -b "$JAR" -c "$JAR" -o /dev/null --data-urlencode "token=$(formtoken "$WORK/login.html")" \
	-d 'actionlogin=login&loginfunction=loginfunction' --data-urlencode "username=$DOLI_ADMIN" --data-urlencode "password=$DOLI_ADMIN_PASS" "$DOLI_URL/index.php?mainmenu=home"

SETUP="$DOLI_URL/custom/mpago/admin/setup.php"
check "setup page answers for the administrator" "$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/setup.html" -w '%{http_code}' "$SETUP")" 200
contains "setup page reports the incomplete setup" "$WORK/setup.html" 'id="mpago_config_errors"'
contains "setup page shows the webhook address" "$WORK/setup.html" "$DOLI_URL/custom/mpago/public/webhook.php?entity=1"

check "setup form saved (redirect)" "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "token=$(formtoken "$WORK/setup.html")" \
	-d "action=update&MPAGO_ACCESS_TOKEN=$TOKEN&MPAGO_WEBHOOK_SECRET=$SECRET&MPAGO_BANK_ACCOUNT=$ACCOUNT&MPAGO_USER_ID=1&MPAGO_EXPIRATION_HOURS=24" "$SETUP")" 302
curl -s -b "$JAR" -c "$JAR" -o "$WORK/setup.html" "$SETUP"
lacks "setup page reports a complete setup" "$WORK/setup.html" 'id="mpago_config_errors"'
lacks "setup page does not print the access token" "$WORK/setup.html" "$TOKEN"
lacks "setup page does not print the webhook secret" "$WORK/setup.html" "$SECRET"
contains "setup page preselects the MPAGO payment mode" "$WORK/setup.html" "<option value=\"$PAYMENT_MODE\" data-code=\"MPAGO\" selected"
check "access token stored" "$(helper secret MPAGO_ACCESS_TOKEN)" encrypted
check "webhook secret stored" "$(helper secret MPAGO_WEBHOOK_SECRET)" encrypted

curl -s -b "$JAR" -c "$JAR" -o "$WORK/test.html" --data-urlencode "token=$(formtoken "$WORK/setup.html")" -d 'action=testconnection' "$SETUP"
contains "test connection: token accepted by the fake API" "$WORK/test.html" 'acept&oacute; el access token'

# --- One complete payment -----------------------------------------------------
# pay <invoice|order> <amount without tax>
pay() {
	local source="$1" amount="$2" id ref url code location order returnurl ts signature
	read -r id ref url < <(helper create "$source" "$amount")
	ok "[$source] created $ref (id $id), payment link $url"

	check "[$source] payment page answers" "$(curl -s -c "$WORK/buyer" -o "$WORK/pay.html" -w '%{http_code}' "$url")" 200
	contains "[$source] payment page shows the Mercado Pago button" "$WORK/pay.html" 'name="dopayment_mpago"'
	shot "pay-$source" "$url"

	# Post the form back as a browser would: every hidden field plus the button.
	php -r '$h = (string) file_get_contents($argv[1]); preg_match_all("/<input[^>]*type=\"hidden\"[^>]*>/", $h, $m); $f = array();
		foreach ($m[0] as $i) { if (preg_match("/name=\"([^\"]*)\"/", $i, $n)) { preg_match("/value=\"([^\"]*)\"/", $i, $v); $f[$n[1]] = html_entity_decode($v[1] ?? "", ENT_QUOTES); } }
		$f["dopayment_mpago"] = "Pagar"; print http_build_query($f);' "$WORK/pay.html" > "$WORK/post"
	code="$(curl -s -b "$WORK/buyer" -o /dev/null -D "$WORK/headers" -w '%{http_code}' -X POST --data @"$WORK/post" "${url%%\?*}")"
	check "[$source] clicking the button redirects" "$code" 302
	location="$(tr -d '\r' < "$WORK/headers" | sed -n 's/^[Ll]ocation: //p')"
	case "$location" in
		"$FAKE/checkout/v1/redirect?order_id=ORDTST"*) ok "[$source] redirect goes to the checkout of the fake: $location" ;;
		*) fail "[$source] redirect goes to '$location'" ;;
	esac
	order="${location##*order_id=}"

	curl -s "$FAKE/__test/state" > "$WORK/state.json"
	check "[$source] order created at the fake with our reference" "$(jsonget "orders.$order.external_reference" < "$WORK/state.json")" "1-${source:0:1}$id-1"
	check "[$source] amount sent with VAT" "$(jsonget "orders.$order.total_amount" < "$WORK/state.json")" "$(php -r 'print number_format($argv[1] * 1.21, 2, ".", "");' "$amount")"
	returnurl="$(jsonget "orders.$order.config.online.success_url" < "$WORK/state.json")"

	# Back before paying: nothing is recorded and the buyer can try again.
	curl -s -o "$WORK/return.html" "$returnurl"
	contains "[$source] return page before payment: not completed" "$WORK/return.html" 'class="mpago_failed'
	contains "[$source] return page before payment: try again link" "$WORK/return.html" 'id="mpago_retry"'
	check "[$source] no payment before paying" "$(helper status "$source" "$id" | value payments)" 0

	curl -s -o /dev/null -X POST -d '{"pay":true}' "$FAKE/__test/orders/$order"
	# Return page, webhook processing and polling can meet: eight simultaneous returns must record once.
	pids=""
	for _ in 1 2 3 4 5 6 7 8; do curl -s -o /dev/null "$returnurl" & pids="$pids $!"; done
	# shellcheck disable=SC2086
	wait $pids
	# The query string Mercado Pago appends is ignored: it says "rejected" here.
	check "[$source] return page answers" "$(curl -s -o "$WORK/return.html" -w '%{http_code}' "$returnurl&status=rejected&collection_status=rejected&order_id=OTHER")" 200
	contains "[$source] return page says paid" "$WORK/return.html" 'class="mpago_paid'
	shot "return-$source" "$returnurl"

	helper status "$source" "$id" > "$WORK/status"
	[ "$source" != order ] || check "[order] order classified billed" "$(value billed < "$WORK/status")" 1
	[ "$source" != order ] || check "[order] one invoice created from the order" "$(value invoices < "$WORK/status")" 1
	check "[$source] invoice $(value invoice < "$WORK/status") paid" "$(value paye < "$WORK/status")" 1
	check "[$source] exactly one payment" "$(value payments < "$WORK/status")" 1
	check "[$source] exactly one bank line" "$(value banklines < "$WORK/status")" 1
	check "[$source] payment amount is the invoice total" "$(value payment_amount < "$WORK/status")" "$(value total < "$WORK/status")"
	check "[$source] payment carries the Mercado Pago order id" "$(value payment_ext_id < "$WORK/status")" "$order"
	check "[$source] payment site" "$(value payment_site < "$WORK/status")" mercadopago
	check "[$source] bank line on the configured account" "$(value payment_account < "$WORK/status")" "$ACCOUNT"
	check "[$source] payment type" "$(value payment_type < "$WORK/status")" MPAGO

	curl -s -o "$WORK/return.html" "$returnurl"
	contains "[$source] return page reloaded still says paid" "$WORK/return.html" 'class="mpago_paid'
	check "[$source] still one payment after reloading the return page" "$(helper status "$source" "$id" | value payments)" 1

	curl -s -o "$WORK/pay.html" "$url"
	lacks "[$source] payment page no longer offers the button" "$WORK/pay.html" 'name="dopayment_mpago"'
	[ "$source" != invoice ] || contains "[invoice] payment page says it was paid with Mercado Pago" "$WORK/pay.html" 'id="mpago_status"'

	# Webhook: the id is signed in lower case, as the documentation says.
	ts="$(date +%s)000"
	signature="$(php -r 'print hash_hmac("sha256", "id:".strtolower($argv[1]).";request-id:".$argv[2].";ts:".$argv[3].";", $argv[4]);' "$order" "req-$order" "$ts" "$SECRET")"
	webhook() { curl -s -o "$WORK/webhook.txt" -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "X-Request-Id: req-$order" -H "X-Signature: ts=$ts,v1=$1" \
		-d "{\"action\":\"order.processed\",\"type\":\"order\",\"live_mode\":false,\"data\":{\"id\":\"$order\"}}" "$DOLI_URL/custom/mpago/public/webhook.php?data.id=$order&type=order&entity=1"; }
	check "[$source] correctly signed webhook" "$(webhook "$signature")" 200
	check "[$source] same webhook delivered again" "$(webhook "$signature")" 200
	check "[$source] badly signed webhook" "$(webhook "$(printf '%064d' 0)")" 401
	check "[$source] webhook stored once, pending" "$(helper events "$order")" "1 1"

	helper cron > "$WORK/cron"
	contains "[$source] job processEvents succeeded" "$WORK/cron" 'processEvents=0 '
	contains "[$source] job pollOpenOrders succeeded" "$WORK/cron" 'pollOpenOrders=0 '
	check "[$source] webhook processed" "$(helper events "$order")" "1 0"
	helper status "$source" "$id" > "$WORK/status"
	check "[$source] still exactly one payment after webhook and jobs" "$(value payments < "$WORK/status")" 1
	check "[$source] still exactly one bank line after webhook and jobs" "$(value banklines < "$WORK/status")" 1
	[ "$source" != order ] || check "[order] still one invoice for the order" "$(value invoices < "$WORK/status")" 1
}

pay invoice 1000
pay order 2500.50

check "webhook without signature" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$DOLI_URL/custom/mpago/public/webhook.php?data.id=ORDTSTX&type=order")" 401
check "return page with an unknown token" "$(curl -s -o /dev/null -w '%{http_code}' "$DOLI_URL/custom/mpago/public/return.php?t=unknown")" 404
check "setup page refused to anonymous visitors" "$(curl -s -o "$WORK/anon.html" -w '%{http_code}' "$SETUP")" 200
lacks "setup page shows nothing to anonymous visitors" "$WORK/anon.html" 'MPAGO_ACCESS_TOKEN'

for log in "$DOLI_DOCS"/dolibarr*.log; do
	[ -f "$log" ] || continue
	lacks "no access token in $(basename "$log")" "$log" "$TOKEN"
	lacks "no webhook secret in $(basename "$log")" "$log" "$SECRET"
done

printf '\n%d checks passed against %s (Dolibarr %s)\n' "$CHECKS" "$DOLI_URL" "$DOLI_VERSION"
