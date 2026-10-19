#!/usr/bin/env bash
# Servik installer for a fresh Ubuntu Server 24.04 (or newer).
#
#   curl -fsSL https://github.com/Winchester37/Servik/releases/latest/download/install.sh | sudo bash
#
# It installs the newest release (panel and agent) into
# /opt/servik/releases/<version>, the panel's own PostgreSQL (a cluster
# named "servik" on port 5433, apart from the customers'), the panel's
# PHP (8.5 from the Ondřej Surý archive), and starts the panel on
# https://<server>:8443. Later releases are installed from the panel
# (Araçlar ve Ayarlar → Güncellemeler), which keeps the previous release
# to go back to.
#
# Options:
#   --version V        install release V instead of the newest
#   --channel C        stable (default) or beta: beta also takes pre-releases
#   --hostname H       the panel's address (default: the server's FQDN)
#   --admin-email E    create the administrator; the password is printed at the end
#   --admin-name N     the administrator's name (default: Yönetici)
#   --source URL       where releases are listed (GitHub API URL of the repository)
#   --no-components    skip the customers' software (PHP 8.4, Composer, Node.js 24, Fail2ban)
#   -y, --yes          do not ask before starting
#
# Everything it prints also goes to /var/log/servik/install.log.
set -euo pipefail

SOURCE=https://api.github.com/repos/Winchester37/Servik
PANEL_PHP=8.5
DB_PORT=5433
WEB_PORT=8443
VERSION=
CHANNEL=stable
HOSTNAME_ARG=
ADMIN_EMAIL=
ADMIN_NAME=Yönetici
COMPONENTS=1
YES=0

while [ $# -gt 0 ]; do
	case $1 in
	--version) VERSION=${2#v}; shift 2 ;;
	--channel) CHANNEL=$2; shift 2 ;;
	--hostname) HOSTNAME_ARG=$2; shift 2 ;;
	--admin-email) ADMIN_EMAIL=$2; shift 2 ;;
	--admin-name) ADMIN_NAME=$2; shift 2 ;;
	--source) SOURCE=${2%/}; shift 2 ;;
	--no-components) COMPONENTS=0; shift ;;
	-y | --yes) YES=1; shift ;;
	-h | --help) sed -n '2,/^set -euo/p' "$0" | sed -e '$d' -e 's/^# \{0,1\}//'; exit 0 ;;
	*) echo "Unknown option: $1 (see --help)" >&2; exit 2 ;;
	esac
done

step() { printf '\n▸ %s\n' "$*"; }
die() { printf '\n✗ %s\n' "$*" >&2; exit 1; }
agent() { /opt/servik/current/bin/servik-agent call "$@"; }
artisan() { runuser -u servik -- env -C /opt/servik/current/panel "php$PANEL_PHP" artisan "$@"; }
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
APT=(apt-get -y -q -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)

[ "$(id -u)" -eq 0 ] || die "Run the installer as root (sudo bash install.sh)."
[ -r /etc/os-release ] || die "Servik runs on Ubuntu Server 24.04 or newer."
. /etc/os-release
if [ "${ID:-}" != ubuntu ] || ! dpkg --compare-versions "${VERSION_ID:-0}" ge 24.04; then
	die "Servik runs on Ubuntu Server 24.04 or newer; this is ${PRETTY_NAME:-unknown}."
fi
command -v systemctl > /dev/null && [ -d /run/systemd/system ] || die "Servik needs systemd."
ARCH=$(dpkg --print-architecture)
case $ARCH in amd64 | arm64) ;; *) die "Servik has releases for amd64 and arm64, not $ARCH." ;; esac
case $CHANNEL in stable | beta) ;; *) die "--channel must be stable or beta." ;; esac
if [ -e /opt/servik/current ]; then
	die "Servik is already installed ($(cat /opt/servik/current/VERSION 2>/dev/null)). Update it from the panel: Araçlar ve Ayarlar → Güncellemeler."
fi
PANEL_HOST=${HOSTNAME_ARG:-$(hostname -f 2>/dev/null || hostname)}

mkdir -p /var/log/servik
exec > >(tee -a /var/log/servik/install.log) 2>&1
echo "Servik installer · $(date -u '+%Y-%m-%d %H:%M UTC') · $PRETTY_NAME · $ARCH"

if [ "$YES" -eq 0 ] && [ -r /dev/tty ]; then
	cat <<-INFO

	This installs Servik on $PANEL_HOST:
	  the panel on https://$PANEL_HOST:$WEB_PORT, its PostgreSQL on port $DB_PORT,
	  PHP $PANEL_PHP for the panel, Nginx$([ "$COMPONENTS" -eq 1 ] && echo ", PHP 8.4, Composer, Node.js 24 and Fail2ban for sites").
	INFO
	read -r -p "Continue? [y/N] " answer < /dev/tty
	case $answer in y | Y | e | E | yes | evet) ;; *) die "Nothing was changed." ;; esac
fi

step "Base packages"
"${APT[@]}" update
"${APT[@]}" install --no-install-recommends ca-certificates curl jq tar gzip software-properties-common

step "Finding the release"
releases=$(curl -fsSL -H 'Accept: application/vnd.github+json' "$SOURCE/releases?per_page=50") ||
	die "Could not list releases at $SOURCE."
# GitHub lists the newest first.
pick='[.[] | select(.draft | not) | select($ch == "beta" or (.prerelease | not))
	| select($v == "" or .tag_name == ("v" + $v) or .tag_name == $v)
	| select(any(.assets[]; .name == ("servik-" + (.tag_name | ltrimstr("v")) + "-linux-" + $arch + ".tar.gz")))][0]'
release=$(jq -c --arg ch "$CHANNEL" --arg v "$VERSION" --arg arch "$ARCH" "$pick" <<< "$releases")
[ "$release" != null ] || die "No ${VERSION:+$VERSION }release for linux-$ARCH on the $CHANNEL channel at $SOURCE."
VERSION=$(jq -r '.tag_name | ltrimstr("v")' <<< "$release")
archive=servik-$VERSION-linux-$ARCH.tar.gz
asset() { jq -r --arg n "$1" '.assets[] | select(.name == $n) | .browser_download_url' <<< "$release"; }
echo "Servik $VERSION"

step "Downloading and verifying $archive"
dl=$(mktemp -d)
trap 'rm -rf "$dl"' EXIT
curl -fL --retry 3 -o "$dl/$archive" "$(asset "$archive")"
curl -fsSL --retry 3 -o "$dl/SHA256SUMS" "$(asset SHA256SUMS)"
(cd "$dl" && grep -E "  \*?$archive\$" SHA256SUMS | sha256sum -c -) || die "$archive does not match SHA256SUMS."

step "PHP $PANEL_PHP for the panel"
if ! apt-cache policy "php$PANEL_PHP-fpm" | grep -q 'Candidate: [0-9]'; then
	add-apt-repository -y ppa:ondrej/php
	"${APT[@]}" update
fi
"${APT[@]}" install --no-install-recommends nginx \
	php$PANEL_PHP-cli php$PANEL_PHP-fpm php$PANEL_PHP-pgsql php$PANEL_PHP-mbstring php$PANEL_PHP-xml \
	php$PANEL_PHP-curl php$PANEL_PHP-intl php$PANEL_PHP-zip php$PANEL_PHP-bcmath

step "The panel's PostgreSQL (cluster servik, port $DB_PORT)"
# The customers' PostgreSQL, when installed from the panel, is the "main"
# cluster; the panel keeps its own.
mkdir -p /etc/postgresql-common/createcluster.d
echo "create_main_cluster = false" > /etc/postgresql-common/createcluster.d/servik.conf
"${APT[@]}" install --no-install-recommends postgresql postgresql-client
PG=$(ls /usr/lib/postgresql | sort -V | tail -n 1)
if ! pg_lsclusters -h | awk '{print $2}' | grep -qx servik; then
	pg_createcluster "$PG" servik --port "$DB_PORT" --start-conf auto -- --auth-local=peer --auth-host=scram-sha-256
fi
systemctl enable --now "postgresql@$PG-servik"
DB_PASSWORD=$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32)
psql_admin() { runuser -u postgres -- psql -X -q -p "$DB_PORT" -v ON_ERROR_STOP=1 "$@"; }
for i in $(seq 30); do psql_admin -c 'SELECT 1' > /dev/null 2>&1 && break; sleep 1; done
if [ "$(psql_admin -At -c "SELECT 1 FROM pg_roles WHERE rolname = 'servik'")" = 1 ]; then
	psql_admin -c "ALTER ROLE servik WITH LOGIN PASSWORD '$DB_PASSWORD'"
else
	psql_admin -c "CREATE ROLE servik LOGIN PASSWORD '$DB_PASSWORD'"
fi
if [ "$(psql_admin -At -c "SELECT 1 FROM pg_database WHERE datname = 'servik'")" != 1 ]; then
	psql_admin -c "CREATE DATABASE servik OWNER servik"
fi

step "Servik $VERSION into /opt/servik/releases/$VERSION"
id servik > /dev/null 2>&1 || useradd --system --user-group --home-dir /var/lib/servik/panel --shell /usr/sbin/nologin --comment "Servik panel" servik
install -d -m 0755 /etc/servik /var/lib/servik /opt/servik /opt/servik/releases
install -d -m 0750 -o servik -g servik /var/lib/servik/panel /var/lib/servik/panel/storage
for d in app app/private app/public framework framework/cache framework/cache/data framework/sessions framework/views logs; do
	install -d -m 0750 -o servik -g servik "/var/lib/servik/panel/storage/$d"
done
rel=/opt/servik/releases/$VERSION
rm -rf "$rel" "$rel.tmp"
mkdir -p "$rel.tmp"
tar -xzf "$dl/$archive" -C "$rel.tmp" --no-same-owner --no-same-permissions
[ "$(cat "$rel.tmp/VERSION")" = "$VERSION" ] || die "$archive is not release $VERSION."
[ "$("$rel.tmp/bin/servik-agent" -version)" = "$VERSION" ] || die "The agent in $archive does not run here."
mv "$rel.tmp" "$rel"
ln -sfn ../../../../../etc/servik/panel.env "$rel/panel/.env"
rm -rf "$rel/panel/storage"
ln -sfn ../../../../../var/lib/servik/panel/storage "$rel/panel/storage"
chown servik:servik "$rel/panel/bootstrap/cache"
ln -sfn "releases/$VERSION" /opt/servik/current.new
mv -T /opt/servik/current.new /opt/servik/current

step "Settings"
if [ ! -f /etc/servik/panel.env ]; then
	umask 027
	cat > /etc/servik/panel.env <<-ENV
	APP_NAME=Servik
	APP_ENV=production
	APP_KEY=base64:$(head -c 32 /dev/urandom | base64)
	APP_DEBUG=false
	APP_URL=https://$PANEL_HOST:$WEB_PORT
	APP_LOCALE=tr
	APP_FALLBACK_LOCALE=en
	APP_FAKER_LOCALE=tr_TR
	APP_MAINTENANCE_DRIVER=file

	LOG_CHANNEL=daily
	LOG_LEVEL=info

	DB_CONNECTION=pgsql
	DB_HOST=127.0.0.1
	DB_PORT=$DB_PORT
	DB_DATABASE=servik
	DB_USERNAME=servik
	DB_PASSWORD=$DB_PASSWORD

	SERVIK_AGENT_SOCKET=/run/servik-agent/agent.sock

	SESSION_DRIVER=database
	SESSION_LIFETIME=120
	SESSION_SECURE_COOKIE=true
	QUEUE_CONNECTION=database
	CACHE_STORE=database
	BROADCAST_CONNECTION=log
	FILESYSTEM_DISK=local
	MAIL_MAILER=log
	ENV
	umask 022
else
	# A reinstall keeps the key and settings; the password is new.
	sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$DB_PASSWORD/" /etc/servik/panel.env
fi
chown root:servik /etc/servik/panel.env
chmod 0640 /etc/servik/panel.env
jq -n --arg php "$PANEL_PHP" --arg source "$SOURCE" --arg host "$PANEL_HOST" \
	'{php: $php, source: $source, hostname: $host}' > /etc/servik/panel.json

step "Servik agent"
cat > /etc/systemd/system/servik-agent.service <<-UNIT
# Servik: written by install.sh.
[Unit]
Description=Servik agent
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/opt/servik/current/bin/servik-agent -socket /run/servik-agent/agent.sock -socket-group servik
Restart=always
RestartSec=2
RuntimeDirectory=servik-agent
RuntimeDirectoryMode=0755

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable servik-agent
systemctl restart servik-agent
for i in $(seq 50); do agent system.ping > /dev/null 2>&1 && break; sleep 0.2; done
agent system.ping > /dev/null || die "The agent did not start: journalctl -u servik-agent"

step "The panel on port $WEB_PORT"
agent panel.setup > /dev/null
artisan migrate --force --no-interaction
artisan optimize
systemctl start servik-panel-queue servik-panel-backups servik-panel-scheduler

if [ "$COMPONENTS" -eq 1 ]; then
	# The defaults of Yazılım Yönetimi, installed as from the panel.
	for c in nginx php8.4 composer node24 fail2ban; do
		step "Installing $c"
		agent component.install "{\"id\":\"$c\",\"run\":\"install-${c//./}-$(date +%s)\"}" > /dev/null ||
			echo "  $c could not be installed now; install it later from Yazılım Yönetimi."
	done
fi

ADMIN_PASSWORD=
if [ -n "$ADMIN_EMAIL" ]; then
	step "Administrator $ADMIN_EMAIL"
	ADMIN_PASSWORD=$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)
	artisan servik:admin --email="$ADMIN_EMAIL" --name="$ADMIN_NAME" --password="$ADMIN_PASSWORD"
fi

step "Checking the panel"
ok=0
for i in $(seq 30); do
	if curl -fsk -o /dev/null "https://127.0.0.1:$WEB_PORT/up"; then ok=1; break; fi
	sleep 2
done
[ "$ok" -eq 1 ] || die "The panel does not answer on https://127.0.0.1:$WEB_PORT/up; see /var/log/servik/web/ and journalctl -u servik-php."

cat <<-DONE

✓ Servik $VERSION is installed.

  Panel:  https://$PANEL_HOST:$WEB_PORT
DONE
if [ -n "$ADMIN_PASSWORD" ]; then
	printf '  Sign in: %s / %s  (change the password after signing in)\n' "$ADMIN_EMAIL" "$ADMIN_PASSWORD"
else
	printf '  Create the administrator:\n    sudo -u servik php%s /opt/servik/current/panel/artisan servik:admin\n' "$PANEL_PHP"
fi
cat <<-DONE

  The panel uses a self-signed certificate until it gets its own: SSL → Ayarlar.
  Open ports 80, 443 and $WEB_PORT (and 53, 25, 587, 993 for DNS and mail) in your firewall.
DONE
