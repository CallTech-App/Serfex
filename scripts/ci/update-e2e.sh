#!/usr/bin/env bash
# CI: Serfex installs, updates itself and comes back on a real Ubuntu.
#
#   scripts/ci/update-e2e.sh build DIR        (as the CI user)
#   sudo scripts/ci/update-e2e.sh run DIR     (as root)
#
# build packages three test releases from this checkout into DIR:
#   0.1.0-ci.1  the checkout as it is
#   0.1.0-ci.2  plus a migration creating the table ci_update_probe
#   0.1.0-ci.3  plus a migration creating ci_broken_probe, and a public/
#               index.php answering 500, so its health check fails
# and a releases listing in GitHub's shape (DIR/api/releases).
#
# run serves DIR/api on 127.0.0.1:8999 as the release source, installs
# 0.1.0-ci.1 with install.sh, updates to 0.1.0-ci.2 (serfex:update), tries
# 0.1.0-ci.3 and checks Serfex returned to 0.1.0-ci.2 by itself (its
# database too), then returns to 0.1.0-ci.1 by hand (serfex:update
# --rollback) and checks ci.2's migration was rolled back.
set -euo pipefail

cmd=${1:?usage: update-e2e.sh build|run DIR}
dir=${2:?usage: update-e2e.sh build|run DIR}
root=$(cd "$(dirname "$0")/../.." && pwd)
PORT=8999
V1=0.1.0-ci.1 V2=0.1.0-ci.2 V3=0.1.0-ci.3

build() {
	mkdir -p "$dir"
	dir=$(cd "$dir" && pwd)
	local base=$dir/work/panel
	rm -rf "$dir/work" && mkdir -p "$base"
	(cd "$root/panel" && git ls-files -z --cached --others --exclude-standard | tar --null -T - -cf -) | tar -xf - -C "$base"
	(cd "$base" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress && npm ci --no-audit --no-fund && npm run build)

	SERFEX_ARCHES=amd64 SERFEX_PANEL_BUILD=$base "$root/scripts/build-release.sh" "$V1" "$dir/dl/$V1"

	cp -a "$base" "$dir/work/panel-2"
	cat > "$dir/work/panel-2/database/migrations/2099_01_01_000001_ci_update_probe.php" <<-'PHP'
	<?php

	use Illuminate\Database\Migrations\Migration;
	use Illuminate\Database\Schema\Blueprint;
	use Illuminate\Support\Facades\Schema;

	return new class extends Migration
	{
	    public function up(): void
	    {
	        Schema::create('ci_update_probe', fn (Blueprint $t) => $t->id());
	    }

	    public function down(): void
	    {
	        Schema::dropIfExists('ci_update_probe');
	    }
	};
	PHP
	SERFEX_ARCHES=amd64 SERFEX_PANEL_BUILD=$dir/work/panel-2 "$root/scripts/build-release.sh" "$V2" "$dir/dl/$V2"

	cp -a "$dir/work/panel-2" "$dir/work/panel-3"
	sed -e 's/ci_update_probe/ci_broken_probe/g' "$dir/work/panel-2/database/migrations/2099_01_01_000001_ci_update_probe.php" \
		> "$dir/work/panel-3/database/migrations/2099_01_01_000002_ci_broken_probe.php"
	printf '<?php\nhttp_response_code(500);\necho "broken release";\n' > "$dir/work/panel-3/public/index.php"
	SERFEX_ARCHES=amd64 SERFEX_PANEL_BUILD=$dir/work/panel-3 "$root/scripts/build-release.sh" "$V3" "$dir/dl/$V3"
	rm -rf "$dir/work"

	mkdir -p "$dir/api"
	local releases=()
	for v in "$V3" "$V2" "$V1"; do
		local a=serfex-$v-linux-amd64.tar.gz
		releases+=("$(jq -n --arg v "$v" --arg a "$a" --arg url "http://127.0.0.1:$PORT/dl/$v" --argjson size "$(stat -c %s "$dir/dl/$v/$a")" '{
			tag_name: ("v" + $v), name: ("Serfex " + $v), body: ("## Serfex " + $v + "\n\n- CI test release"),
			draft: false, prerelease: false, published_at: "2026-10-19T10:00:00Z", html_url: ("https://example.invalid/" + $v),
			assets: [
				{name: $a, browser_download_url: ($url + "/" + $a), size: $size},
				{name: "SHA256SUMS", browser_download_url: ($url + "/SHA256SUMS"), size: 1},
				{name: "install.sh", browser_download_url: ($url + "/install.sh"), size: 1}
			]}')")
	done
	printf '%s\n' "${releases[@]}" | jq -s . > "$dir/api/releases"
	ln -s ../dl "$dir/api/dl"
	echo "✓ test releases in $dir"
}

# --- run -----------------------------------------------------------------

fail() { printf '\n✗ %s\n' "$*" >&2; exit 1; }
ok() { printf '✓ %s\n' "$*"; }
current() { basename "$(readlink /opt/serfex/current)"; }
artisan() { runuser -u serfex -- env -C /opt/serfex/current/panel php8.5 artisan "$@"; }
agent() { /opt/serfex/current/bin/serfex-agent call "$@"; }
sql() { runuser -u postgres -- psql -X -At -p 5433 -d serfex -c "$1"; }
has_table() { [ "$(sql "SELECT to_regclass('public.$1') IS NOT NULL")" = t ]; }
code() { curl -sk -o /dev/null -w '%{http_code}' "https://127.0.0.1:8443$1"; }
last_update() { sql "SELECT kind || ' ' || to_version || ' ' || status FROM panel_updates ORDER BY id DESC LIMIT 1"; }

expect_running() {
	local v=$1
	[ "$(current)" = "$v" ] || fail "current is $(current), not $v"
	[ "$(cat /opt/serfex/current/VERSION)" = "$v" ] || fail "the release in current is not $v"
	local st
	st=$(agent update.status)
	[ "$(jq -r .version <<< "$st")" = "$v" ] || fail "the agent reports $(jq -r .version <<< "$st"), not $v"
	[ "$(jq -r .agent <<< "$st")" = "$v" ] || fail "the agent running is $(jq -r .agent <<< "$st"), not $v"
	[ "$(code /up)" = 200 ] || fail "/up answers $(code /up) on $v"
	[ "$(code /login)" = 200 ] || fail "/login answers $(code /login) on $v (maintenance left on?)"
	for u in serfex-panel-queue serfex-panel-backups serfex-panel-scheduler serfex-agent; do
		systemctl is-active --quiet "$u" || fail "$u is not running on $v"
	done
	ok "Serfex $v runs (panel, agent, workers)"
}

run() {
	dir=$(cd "$dir" && pwd)
	[ "$(id -u)" -eq 0 ] || fail "run as root"
	(cd "$dir/api" && exec python3 -m http.server "$PORT" --bind 127.0.0.1 > /tmp/release-source.log 2>&1) &
	for i in $(seq 30); do curl -fs -o /dev/null "http://127.0.0.1:$PORT/releases" && break; sleep 0.5; done

	echo "▸ install.sh --version $V1"
	bash "$root/scripts/install.sh" --version "$V1" --source "http://127.0.0.1:$PORT" --hostname panel.ci.test \
		--no-components --yes --lang tr | tee /tmp/install.out
	expect_running "$V1"

	# Without --admin-email the installer prints the one-time setup link.
	link=$(grep -oE 'https://[^ ]+/install/[A-Za-z0-9]{48}' /tmp/install.out | head -n 1)
	[ -n "$link" ] || fail "install.sh printed no setup link"
	setup_path=${link#https://*/}
	[ "$(code "/$setup_path")" = 200 ] || fail "the setup link answers $(code "/$setup_path")"
	[ "$(sql "SELECT count(*) FROM users")" = 0 ] || fail "an account exists before the setup link was used"
	serfex setup-url > /tmp/setup-url.out || fail "serfex setup-url failed"
	grep -q '/install/' /tmp/setup-url.out || fail "serfex setup-url printed no link"
	[ "$(code "/$setup_path")" = 410 ] || fail "the first link still works after a new one was made"
	ok "install.sh printed a setup link; serfex setup-url replaced it"

	artisan serfex:admin --email=ci@serfex.test --name=CI --password=ci-password-123
	[ "$(sql "SELECT count(*) FROM users WHERE email = 'ci@serfex.test' AND is_admin")" = 1 ] || fail "no administrator"
	! serfex setup-url > /dev/null 2>&1 || fail "serfex setup-url made a link although an administrator exists"

	echo "▸ serfex:update --check"
	artisan serfex:update --check | tee /tmp/check.out
	grep -q "Serfex $V3 hazır" /tmp/check.out || fail "the check did not find $V3"

	echo "▸ serfex:update --target=$V2"
	artisan serfex:update --target="$V2" --yes || fail "the update to $V2 failed (exit $?)"
	expect_running "$V2"
	has_table ci_update_probe || fail "$V2's migration did not run"
	[ "$(agent update.status | jq -r .previous)" = "$V1" ] || fail "$V1 is not kept as the previous release"
	[ "$(last_update)" = "upgrade $V2 succeeded" ] || fail "recorded: $(last_update)"
	[ "$(sql "SELECT count(*) FROM backups WHERE scope = 'panel' AND trigger = 'update' AND status = 'succeeded'")" = 1 ] ||
		fail "no panel backup before the update"
	ok "updated to $V2 after a panel backup"

	echo "▸ serfex:update --target=$V3 (a release that fails its health check)"
	set +e
	artisan serfex:update --target="$V3" --yes
	rc=$?
	set -e
	[ "$rc" = 2 ] || fail "serfex:update exited $rc, not 2 (returned to the previous release)"
	expect_running "$V2"
	has_table ci_update_probe || fail "$V2's table is gone after returning from $V3"
	! has_table ci_broken_probe || fail "$V3's migration was not undone"
	[ ! -e "/opt/serfex/releases/$V3" ] || fail "the failed release $V3 was kept"
	[ "$(agent update.status | jq -r .previous)" = "$V1" ] || fail "$V1 is no longer the previous release"
	[ "$(last_update)" = "upgrade $V3 rolled_back" ] || fail "recorded: $(last_update)"
	ok "$V3 failed its health check; Serfex returned to $V2 by itself, database included"

	echo "▸ serfex:update --rollback"
	artisan serfex:update --rollback --yes || fail "the rollback to $V1 failed"
	expect_running "$V1"
	! has_table ci_update_probe || fail "$V2's migration was not rolled back"
	[ -e "/opt/serfex/releases/$V2/VERSION" ] || fail "$V2 was removed while serfex:update still ran its code"
	[ "$(agent update.status | jq -r '.previous // ""')" = "" ] || fail "a previous release is still offered"
	[ "$(last_update)" = "rollback $V1 succeeded" ] || fail "recorded: $(last_update)"
	ok "returned to $V1 by hand, its migrations rolled back"
}

case $cmd in
build) build ;;
run) run ;;
*) fail "usage: update-e2e.sh build|run DIR" ;;
esac
