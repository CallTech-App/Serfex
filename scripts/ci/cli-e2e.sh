#!/usr/bin/env bash
# The serfex command line against a real panel and a real agent (CI job
# "Command line drives a real panel"). Needs: the agent running as root
# with its socket open to this user's group, Nginx and PHP-FPM 8.3,
# PostgreSQL with an empty "serfex" database for user serfex/serfex, and
# the panel's dependencies installed (composer install, npm run build).
#
#   scripts/ci/cli-e2e.sh
#
# It starts the panel (php artisan serve and a queue worker), makes a
# command line key with serfex:cli-key, and then works only through the
# serfex binary: sign in, create a site and wait for it, check Nginx
# serves it, add a subdomain, list services and databases, read the
# result as JSON in Turkish and English, refuse a change without --yes,
# delete the site and sign out (which closes the key on the panel).
set -euo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d)
domain=cli-e2e.test
cli=$work/serfex
export SERFEX_CONFIG=$work/config.json NO_COLOR=1 LANG=tr_TR.UTF-8

step() { printf '\n▸ %s\n' "$*"; }
# serves waits for Nginx to answer for a name with the page the agent
# writes for it (Nginx swaps its workers a moment after a reload).
serves() {
	for i in $(seq 50); do
		curl -s -H "Host: $1" http://127.0.0.1/ | grep -q "<h1>$1</h1>" && return 0
		sleep 0.1
	done
	return 1
}
fail() {
	printf '✗ %s\n' "$*" >&2
	exit 1
}
cleanup() {
	[ -n "${serve:-}" ] && kill "$serve" 2> /dev/null || true
	[ -n "${worker:-}" ] && kill "$worker" 2> /dev/null || true
	if [ "${1:-}" != ok ]; then
		echo "--- panel log"
		tail -n 80 "$root/panel/storage/logs/laravel.log" 2> /dev/null || true
		echo "--- serve"
		tail -n 40 "$work/serve.log" 2> /dev/null || true
		echo "--- worker"
		tail -n 40 "$work/worker.log" 2> /dev/null || true
	fi
}
trap cleanup EXIT

step "Building the command line"
(cd "$root/cli" && CGO_ENABLED=0 go build -trimpath -ldflags "-X github.com/calltech-app/serfex/cli/internal/version.Version=0.0.0-ci" -o "$cli" ./cmd/serfex)
"$cli" version

step "Starting the panel"
cd "$root/panel"
cp .env.example .env
sed -i -e 's/^DB_DATABASE=.*/DB_DATABASE=serfex/' -e 's/^DB_PASSWORD=.*/DB_PASSWORD=serfex/' -e 's#^APP_URL=.*#APP_URL=http://127.0.0.1:8000#' .env
php artisan key:generate --no-interaction
php artisan migrate --force --no-interaction
php artisan serfex:admin --email=ci@serfex.test --name=CI --password=ci-password-123
# No Let's Encrypt in CI.
php artisan tinker --execute "App\Models\Setting::put('ssl.auto', false);"
php artisan serve --host=127.0.0.1 --port=8000 > "$work/serve.log" 2>&1 &
serve=$!
php artisan queue:work --queue=default --sleep=1 --tries=1 > "$work/worker.log" 2>&1 &
worker=$!
for i in $(seq 50); do curl -fs -o /dev/null http://127.0.0.1:8000/up && break; sleep 0.2; done
key=$(php artisan serfex:cli-key --client "CI" | tail -n 1 | jq -r .key)
[[ $key == sfx_panel_* ]] || fail "serfex:cli-key gave no key"

step "serfex login --token"
"$cli" login http://127.0.0.1:8000 --token "$key" --profile ci
"$cli" whoami
[ "$("$cli" whoami --json | jq -r .account)" = admin ] || fail "whoami is not the administrator"

step "serfex sites create --wait"
"$cli" sites create "$domain" --php 8.3 --no-www --wait
[ "$("$cli" sites show "$domain" --json | jq -r .status)" = active ] || fail "$domain is not active"
serves "$domain" || fail "Nginx does not serve $domain"
"$cli" sites list

step "serfex domains add"
"$cli" domains add "$domain" blog
"$cli" domains list "$domain" --json | jq -e '.subdomains[] | select(.name == "blog.'"$domain"'")' > /dev/null || fail "no blog subdomain"
serves "blog.$domain" || fail "Nginx does not serve blog.$domain"
"$cli" domains remove "$domain" blog --yes

step "serfex services, db, ssl"
"$cli" services list
[ "$("$cli" services status nginx --json | jq -r .state)" = active ] || fail "nginx is not active"
"$cli" db list
"$cli" ssl show "$domain"

step "Languages and refusals"
# Captured first: with pipefail, grep -q exiting early would SIGPIPE the CLI.
shown=$("$cli" --lang en sites show "$domain")
grep -q '^State:' <<< "$shown" || fail "no English output: $shown"
"$cli" sites show nowhere.test 2> "$work/err" && fail "a missing site was found"
grep -q 'nowhere.test adında bir site yok.' "$work/err" || fail "the panel's refusal is not shown in Turkish: $(cat "$work/err")"
"$cli" --lang en sites show nowhere.test 2> "$work/err" || true
grep -q 'There is no site nowhere.test.' "$work/err" || fail "the panel's refusal is not shown in English: $(cat "$work/err")"
if "$cli" sites delete "$domain" < /dev/null 2> "$work/err"; then fail "deleted without --yes"; fi
grep -q -- '--yes' "$work/err" || fail "no --yes hint"

step "serfex sites delete --yes"
"$cli" sites delete "$domain" --yes
for i in $(seq 100); do
	"$cli" sites show "$domain" > /dev/null 2>&1 || break
	sleep 0.2
done
"$cli" sites show "$domain" > /dev/null 2>&1 && fail "$domain was not deleted"

step "serfex logout"
"$cli" logout
"$cli" --url http://127.0.0.1:8000 --token "$key" whoami 2> "$work/err" && fail "the key still works after logout"
grep -q 'iptal edildi' "$work/err" || fail "unexpected refusal: $(cat "$work/err")"

cleanup ok
trap - EXIT
printf '\n✓ The command line drove a real panel.\n'
