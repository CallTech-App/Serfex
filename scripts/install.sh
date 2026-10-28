#!/usr/bin/env bash
# Serfex installer for a fresh Ubuntu Server 24.04 (or newer).
#
#   curl -fsSL https://github.com/CallTech-App/Serfex/releases/latest/download/install.sh | sudo bash
#
# At the end it prints a one-time setup link (24 hours): whoever opens it
# creates the first administrator and goes through the setup wizard. A
# lost link is made again on the server with `sudo serfex setup-url`.
#
# It installs the newest release (panel and agent) into
# /opt/serfex/releases/<version>, the panel's own PostgreSQL (a cluster
# named "serfex" on port 5433, apart from the customers'), the panel's
# PHP (8.5 from the Ondřej Surý archive), and starts the panel on
# https://<server>:8443. Later releases are installed from the panel
# (Araçlar ve Ayarlar → Güncellemeler), which keeps the previous release
# to go back to.
#
# Options:
#   --version V        install release V instead of the newest
#   --channel C        stable (default) or beta: beta also takes pre-releases
#   --hostname H       the panel's address (default: the server's FQDN)
#   --admin-email E    create the administrator now and print its password at the
#                      end, instead of the one-time setup link (for automation)
#   --admin-name N     the administrator's name (default: Yönetici / Administrator)
#   --lang L           tr or en: the panel's default language and this
#                      installer's (default: en; the setup page can still
#                      switch the panel to Turkish)
#   --source URL       where releases are listed (GitHub API URL of the repository)
#   --no-components    skip the customers' software (PHP 8.4, Composer, Node.js 24, Fail2ban)
#   -y, --yes          do not ask before starting
#
# Everything it prints also goes to /var/log/serfex/install.log.
set -euo pipefail

SOURCE=https://api.github.com/repos/CallTech-App/Serfex
PANEL_PHP=8.5
DB_PORT=5433
WEB_PORT=8443
VERSION=
CHANNEL=stable
HOSTNAME_ARG=
ADMIN_EMAIL=
ADMIN_NAME=
LANG_CODE=
COMPONENTS=1
YES=0

while [ $# -gt 0 ]; do
	case $1 in
	--version) VERSION=${2#v}; shift 2 ;;
	--channel) CHANNEL=$2; shift 2 ;;
	--hostname) HOSTNAME_ARG=$2; shift 2 ;;
	--admin-email) ADMIN_EMAIL=$2; shift 2 ;;
	--admin-name) ADMIN_NAME=$2; shift 2 ;;
	--lang) LANG_CODE=$2; shift 2 ;;
	--source) SOURCE=${2%/}; shift 2 ;;
	--no-components) COMPONENTS=0; shift ;;
	-y | --yes) YES=1; shift ;;
	-h | --help) sed -n '2,/^set -euo/p' "$0" | sed -e '$d' -e 's/^# \{0,1\}//'; exit 0 ;;
	*) echo "Unknown option: $1 (see --help)" >&2; exit 2 ;;
	esac
done

[ -n "$LANG_CODE" ] || LANG_CODE=en
case $LANG_CODE in tr | en) ;; *) echo "--lang must be tr or en." >&2; exit 2 ;; esac
[ -n "$ADMIN_NAME" ] || ADMIN_NAME=$([ "$LANG_CODE" = tr ] && echo Yönetici || echo Administrator)
# t "Türkçe" "English": the message in the installer's language.
t() { if [ "$LANG_CODE" = tr ]; then printf '%s' "$1"; else printf '%s' "$2"; fi; }
step() { printf '\n▸ %s\n' "$*"; }
die() { printf '\n✗ %s\n' "$*" >&2; exit 1; }
agent() { /opt/serfex/current/bin/serfex-agent call "$@"; }
artisan() { runuser -u serfex -- env -C /opt/serfex/current/panel "php$PANEL_PHP" artisan "$@"; }
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
APT=(apt-get -y -q -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)

[ "$(id -u)" -eq 0 ] || die "$(t "Kurulumu root olarak çalıştırın (sudo bash install.sh)." "Run the installer as root (sudo bash install.sh).")"
[ -r /etc/os-release ] || die "$(t "Serfex Ubuntu Server 24.04 ya da daha yenisinde çalışır." "Serfex runs on Ubuntu Server 24.04 or newer.")"
# In a subshell: os-release sets VERSION, which is ours.
read -r OS_ID OS_VERSION < <(. /etc/os-release && echo "${ID:-unknown} ${VERSION_ID:-0}")
PRETTY_NAME=$(. /etc/os-release && echo "${PRETTY_NAME:-unknown}")
if [ "$OS_ID" != ubuntu ] || ! dpkg --compare-versions "$OS_VERSION" ge 24.04; then
	die "$(t "Serfex Ubuntu Server 24.04 ya da daha yenisinde çalışır; bu sunucu $PRETTY_NAME." "Serfex runs on Ubuntu Server 24.04 or newer; this is $PRETTY_NAME.")"
fi
command -v systemctl > /dev/null && [ -d /run/systemd/system ] || die "$(t "Serfex systemd gerektirir." "Serfex needs systemd.")"
ARCH=$(dpkg --print-architecture)
case $ARCH in amd64 | arm64) ;; *) die "$(t "Serfex'in amd64 ve arm64 sürümleri var, $ARCH yok." "Serfex has releases for amd64 and arm64, not $ARCH.")" ;; esac
case $CHANNEL in stable | beta) ;; *) die "$(t "--channel stable ya da beta olmalı." "--channel must be stable or beta.")" ;; esac
if [ -e /opt/serfex/current ]; then
	die "$(t "Serfex zaten kurulu ($(cat /opt/serfex/current/VERSION 2>/dev/null)). Panelden güncelleyin: Araçlar ve Ayarlar → Güncellemeler." "Serfex is already installed ($(cat /opt/serfex/current/VERSION 2>/dev/null)). Update it from the panel: Settings → Updates.")"
fi
PANEL_HOST=${HOSTNAME_ARG:-$(hostname -f 2>/dev/null || hostname)}

mkdir -p /var/log/serfex
exec > >(tee -a /var/log/serfex/install.log) 2>&1
echo "$(t "Serfex kurulumu" "Serfex installer") · $(date -u '+%Y-%m-%d %H:%M UTC') · $PRETTY_NAME · $ARCH"

if [ "$YES" -eq 0 ] && [ -r /dev/tty ]; then
	if [ "$LANG_CODE" = tr ]; then
		cat <<-INFO

		Serfex $PANEL_HOST sunucusuna kurulacak:
		  panel https://$PANEL_HOST:$WEB_PORT adresinde, PostgreSQL'i $DB_PORT portunda,
		  panel için PHP $PANEL_PHP, Nginx$([ "$COMPONENTS" -eq 1 ] && echo "; siteler için PHP 8.4, Composer, Node.js 24 ve Fail2ban").
		INFO
	else
		cat <<-INFO

		This installs Serfex on $PANEL_HOST:
		  the panel on https://$PANEL_HOST:$WEB_PORT, its PostgreSQL on port $DB_PORT,
		  PHP $PANEL_PHP for the panel, Nginx$([ "$COMPONENTS" -eq 1 ] && echo ", PHP 8.4, Composer, Node.js 24 and Fail2ban for sites").
		INFO
	fi
	read -r -p "$(t "Devam edilsin mi? [e/H] " "Continue? [y/N] ")" answer < /dev/tty
	case $answer in y | Y | e | E | yes | evet) ;; *) die "$(t "Hiçbir şey değiştirilmedi." "Nothing was changed.")" ;; esac
fi

step "$(t "Temel paketler" "Base packages")"
"${APT[@]}" update
"${APT[@]}" install --no-install-recommends ca-certificates curl jq tar gzip software-properties-common

step "$(t "Sürüm bulunuyor" "Finding the release")"
releases=$(curl -fsSL -H 'Accept: application/vnd.github+json' "$SOURCE/releases?per_page=50") ||
	die "$(t "$SOURCE adresindeki sürümler listelenemedi." "Could not list releases at $SOURCE.")"
# GitHub lists the newest first.
pick='[.[] | select(.draft | not) | select($ch == "beta" or (.prerelease | not))
	| select($v == "" or .tag_name == ("v" + $v) or .tag_name == $v)
	| (.tag_name | ltrimstr("v")) as $tv
	| select(any(.assets[]; .name == ("serfex-" + $tv + "-linux-" + $arch + ".tar.gz")))][0]'
release=$(jq -c --arg ch "$CHANNEL" --arg v "$VERSION" --arg arch "$ARCH" "$pick" <<< "$releases")
[ "$release" != null ] || die "$(t "$SOURCE adresinde $CHANNEL kanalında linux-$ARCH için ${VERSION:+$VERSION }sürüm yok." "No ${VERSION:+$VERSION }release for linux-$ARCH on the $CHANNEL channel at $SOURCE.")"
VERSION=$(jq -r '.tag_name | ltrimstr("v")' <<< "$release")
archive=serfex-$VERSION-linux-$ARCH.tar.gz
asset() { jq -r --arg n "$1" ".assets[] | select(.name == \$n) | .browser_download_url" <<< "$release"; }
echo "Serfex $VERSION"

step "$(t "$archive indiriliyor ve doğrulanıyor" "Downloading and verifying $archive")"
dl=$(mktemp -d)
trap 'rm -rf "$dl"' EXIT
curl -fL --retry 3 -o "$dl/$archive" "$(asset "$archive")"
curl -fsSL --retry 3 -o "$dl/SHA256SUMS" "$(asset SHA256SUMS)"
(cd "$dl" && grep -E "  \*?$archive\$" SHA256SUMS | sha256sum -c -) || die "$(t "$archive, SHA256SUMS ile eşleşmiyor." "$archive does not match SHA256SUMS.")"

step "$(t "Panel için PHP $PANEL_PHP" "PHP $PANEL_PHP for the panel")"
if ! apt-cache policy "php$PANEL_PHP-fpm" | grep -q 'Candidate: [0-9]'; then
	add-apt-repository -y ppa:ondrej/php
	"${APT[@]}" update
fi
"${APT[@]}" install --no-install-recommends nginx \
	php$PANEL_PHP-cli php$PANEL_PHP-fpm php$PANEL_PHP-pgsql php$PANEL_PHP-mbstring php$PANEL_PHP-xml \
	php$PANEL_PHP-curl php$PANEL_PHP-intl php$PANEL_PHP-zip php$PANEL_PHP-bcmath

step "$(t "Panelin PostgreSQL'i (serfex kümesi, port $DB_PORT)" "The panel's PostgreSQL (cluster serfex, port $DB_PORT)")"
# The customers' PostgreSQL, when installed from the panel, is the "main"
# cluster; the panel keeps its own.
mkdir -p /etc/postgresql-common/createcluster.d
echo "create_main_cluster = false" > /etc/postgresql-common/createcluster.d/serfex.conf
# The newest stable PostgreSQL comes from the PostgreSQL project's own
# archive (Ubuntu keeps one version per release); postgresql-common
# carries its key and the script that lists it. Without the archive,
# Ubuntu's version serves.
"${APT[@]}" install --no-install-recommends postgresql-common
PG=
# apt-get update only warns about a list it could not fetch; --error-on=any
# makes that a failure.
if /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y > /dev/null 2>&1 && apt-get -q update --error-on=any > /dev/null 2>&1; then
	PG=$(apt-cache pkgnames postgresql- | grep -E '^postgresql-[0-9]+$' | sed 's/^postgresql-//' | sort -n | tail -n 1 || true)
else
	echo "$(t "PostgreSQL paket deposuna (apt.postgresql.org) ulaşılamadı; Ubuntu'nun sürümü kuruluyor." "The PostgreSQL archive (apt.postgresql.org) could not be read; installing Ubuntu's version.")"
	rm -f /etc/apt/sources.list.d/pgdg.sources
	"${APT[@]}" update
fi
if [ -n "$PG" ]; then
	"${APT[@]}" install --no-install-recommends "postgresql-$PG" "postgresql-client-$PG"
else
	"${APT[@]}" install --no-install-recommends postgresql postgresql-client
fi
PG=$(ls /usr/lib/postgresql | sort -V | tail -n 1)
if ! pg_lsclusters -h | awk '{print $2}' | grep -qx serfex; then
	pg_createcluster "$PG" serfex --port "$DB_PORT" --start-conf auto -- --auth-local=peer --auth-host=scram-sha-256
fi
systemctl enable --now "postgresql@$PG-serfex"
DB_PASSWORD=$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32)
psql_admin() { runuser -u postgres -- psql -X -q -p "$DB_PORT" -v ON_ERROR_STOP=1 "$@"; }
for i in $(seq 30); do psql_admin -c 'SELECT 1' > /dev/null 2>&1 && break; sleep 1; done
if [ "$(psql_admin -At -c "SELECT 1 FROM pg_roles WHERE rolname = 'serfex'")" = 1 ]; then
	psql_admin -c "ALTER ROLE serfex WITH LOGIN PASSWORD '$DB_PASSWORD'"
else
	psql_admin -c "CREATE ROLE serfex LOGIN PASSWORD '$DB_PASSWORD'"
fi
if [ "$(psql_admin -At -c "SELECT 1 FROM pg_database WHERE datname = 'serfex'")" != 1 ]; then
	psql_admin -c "CREATE DATABASE serfex OWNER serfex"
fi

step "$(t "Serfex $VERSION, /opt/serfex/releases/$VERSION dizinine" "Serfex $VERSION into /opt/serfex/releases/$VERSION")"
id serfex > /dev/null 2>&1 || useradd --system --user-group --home-dir /var/lib/serfex/panel --shell /usr/sbin/nologin --comment "Serfex panel" serfex
install -d -m 0755 /etc/serfex /var/lib/serfex /opt/serfex /opt/serfex/releases
install -d -m 0750 -o serfex -g serfex /var/lib/serfex/panel /var/lib/serfex/panel/storage
for d in app app/private app/public framework framework/cache framework/cache/data framework/sessions framework/views logs; do
	install -d -m 0750 -o serfex -g serfex "/var/lib/serfex/panel/storage/$d"
done
rel=/opt/serfex/releases/$VERSION
rm -rf "$rel" "$rel.tmp"
mkdir -p "$rel.tmp"
tar -xzf "$dl/$archive" -C "$rel.tmp" --no-same-owner --no-same-permissions
[ "$(cat "$rel.tmp/VERSION")" = "$VERSION" ] || die "$(t "$archive, $VERSION sürümü değil." "$archive is not release $VERSION.")"
[ "$("$rel.tmp/bin/serfex-agent" -version)" = "$VERSION" ] || die "$(t "$archive içindeki ajan bu sunucuda çalışmıyor." "The agent in $archive does not run here.")"
mv "$rel.tmp" "$rel"
ln -sfn ../../../../../etc/serfex/panel.env "$rel/panel/.env"
rm -rf "$rel/panel/storage"
ln -sfn ../../../../../var/lib/serfex/panel/storage "$rel/panel/storage"
chown serfex:serfex "$rel/panel/bootstrap/cache"
ln -sfn "releases/$VERSION" /opt/serfex/current.new
mv -T /opt/serfex/current.new /opt/serfex/current
# The serfex command line, with Tab completion for bash, zsh and fish.
if [ -x /opt/serfex/current/bin/serfex ]; then
	ln -sfn /opt/serfex/current/bin/serfex /usr/local/bin/serfex
	mkdir -p /usr/share/bash-completion/completions /usr/share/zsh/vendor-completions /usr/share/fish/vendor_completions.d
	/usr/local/bin/serfex completion bash > /usr/share/bash-completion/completions/serfex
	/usr/local/bin/serfex completion zsh > /usr/share/zsh/vendor-completions/_serfex
	/usr/local/bin/serfex completion fish > /usr/share/fish/vendor_completions.d/serfex.fish
fi

step "$(t "Ayarlar" "Settings")"
if [ ! -f /etc/serfex/panel.env ]; then
	umask 027
	cat > /etc/serfex/panel.env <<-ENV
	APP_NAME=Serfex
	APP_ENV=production
	APP_KEY=base64:$(head -c 32 /dev/urandom | base64)
	APP_DEBUG=false
	APP_URL=https://$PANEL_HOST:$WEB_PORT
	APP_LOCALE=$LANG_CODE
	SERFEX_LOCALE=$LANG_CODE
		APP_MAINTENANCE_DRIVER=file

	LOG_CHANNEL=daily
	LOG_LEVEL=info

	DB_CONNECTION=pgsql
	DB_HOST=127.0.0.1
	DB_PORT=$DB_PORT
	DB_DATABASE=serfex
	DB_USERNAME=serfex
	DB_PASSWORD=$DB_PASSWORD

	SERFEX_AGENT_SOCKET=/run/serfex-agent/agent.sock

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
	sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$DB_PASSWORD/" /etc/serfex/panel.env
fi
chown root:serfex /etc/serfex/panel.env
chmod 0640 /etc/serfex/panel.env
jq -n --arg php "$PANEL_PHP" --arg source "$SOURCE" --arg host "$PANEL_HOST" \
	'{php: $php, source: $source, hostname: $host}' > /etc/serfex/panel.json

step "$(t "Serfex ajanı" "Serfex agent")"
cat > /etc/systemd/system/serfex-agent.service <<-UNIT
# Serfex: written by install.sh.
[Unit]
Description=Serfex agent
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/opt/serfex/current/bin/serfex-agent -socket /run/serfex-agent/agent.sock -socket-group serfex
Restart=always
RestartSec=2
RuntimeDirectory=serfex-agent
RuntimeDirectoryMode=0755

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable serfex-agent
systemctl restart serfex-agent
for i in $(seq 50); do agent system.ping > /dev/null 2>&1 && break; sleep 0.2; done
agent system.ping > /dev/null || die "$(t "Ajan başlamadı: journalctl -u serfex-agent" "The agent did not start: journalctl -u serfex-agent")"

step "$(t "Panel, $WEB_PORT portunda" "The panel on port $WEB_PORT")"
agent panel.setup > /dev/null
artisan migrate --force --no-interaction
artisan optimize
systemctl start serfex-panel-queue serfex-panel-backups serfex-panel-scheduler

# Customers' disk limits are enforced with user quotas on the file system
# holding /home. Where that cannot work (btrfs, a kernel without quota
# support) the limits stay warnings and the panel says why.
step "$(t "Disk kotaları" "Disk quotas")"
QUOTA=$(agent quota.enable 2> /dev/null | jq -r '.state // empty' 2> /dev/null || true)
case $QUOTA in
on) echo "  $(t "Açık: müşteriler paketlerindeki disk alanını aşamaz." "On: customers cannot use more disk space than their plan.")" ;;
*) echo "  $(t "Bu sunucuda açılamadı; disk sınırları uyarı olarak kalır (Araçlar ve Ayarlar → Sunucu)." "Could not be turned on here; disk limits stay warnings (Tools & Settings → Server).")" ;;
esac
artisan serfex:disk-quota > /dev/null 2>&1 || true

if [ "$COMPONENTS" -eq 1 ]; then
	# The defaults of Yazılım Yönetimi, installed as from the panel.
	for c in nginx php8.4 composer node24 fail2ban; do
		step "$(t "$c kuruluyor" "Installing $c")"
		agent component.install "{\"id\":\"$c\",\"run\":\"install-${c//./}-$(date +%s)\"}" > /dev/null ||
			echo "  $(t "$c şimdi kurulamadı; daha sonra Yazılım Yönetimi'nden kurun." "$c could not be installed now; install it later from Software.")"
	done
fi

ADMIN_PASSWORD=
if [ -n "$ADMIN_EMAIL" ]; then
	step "$(t "Yönetici $ADMIN_EMAIL" "Administrator $ADMIN_EMAIL")"
	ADMIN_PASSWORD=$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)
	artisan serfex:admin --email="$ADMIN_EMAIL" --name="$ADMIN_NAME" --password="$ADMIN_PASSWORD"
fi

step "$(t "Panel denetleniyor" "Checking the panel")"
ok=0
for i in $(seq 30); do
	if curl -fsk -o /dev/null "https://127.0.0.1:$WEB_PORT/up"; then ok=1; break; fi
	sleep 2
done
[ "$ok" -eq 1 ] || die "$(t "Panel https://127.0.0.1:$WEB_PORT/up adresinde yanıt vermiyor; /var/log/serfex/web/ ve journalctl -u serfex-php çıktısına bakın." "The panel does not answer on https://127.0.0.1:$WEB_PORT/up; see /var/log/serfex/web/ and journalctl -u serfex-php.")"

# The way in: the administrator made above, or a one-time setup link.
SETUP_URLS=
if [ -z "$ADMIN_PASSWORD" ]; then
	SETUP_URLS=$(artisan serfex:setup-url --json 2> /dev/null | tail -n 1 | jq -r '.urls[]?' 2> /dev/null || true)
fi

printf '\n✓ %s\n\n  Panel:  https://%s:%s\n' "$(t "Serfex $VERSION kuruldu." "Serfex $VERSION is installed.")" "$PANEL_HOST" "$WEB_PORT"
if [ -n "$ADMIN_PASSWORD" ]; then
	printf "  $(t 'Giriş: %s / %s  (girişten sonra parolayı değiştirin)' 'Sign in: %s / %s  (change the password after signing in)')\n" "$ADMIN_EMAIL" "$ADMIN_PASSWORD"
elif [ -n "$SETUP_URLS" ]; then
	printf '\n  %s\n' "$(t "Kurulumu tamamlamak için bu bağlantıyı tarayıcıda açın; yönetici hesabını oluşturup kurulum sihirbazına geçersiniz:" "To finish, open this link in a browser; you create the administrator account and go on to the setup wizard:")"
	while IFS= read -r u; do printf '\n    %s\n' "$u"; done <<< "$SETUP_URLS"
	printf '\n  %s\n' "$(t "Bağlantı bir kez kullanılır ve 24 saat geçerlidir. Kaybederseniz: sudo serfex setup-url" "The link works once, for 24 hours. If you lose it: sudo serfex setup-url")"
else
	printf "  $(t 'Giriş: mevcut yönetici hesabınızla.' 'Sign in with your existing administrator account.')\n"
fi
[ -x /usr/local/bin/serfex ] && [ -z "$SETUP_URLS" ] && printf "  $(t 'Komut satırı: %s' 'Command line: %s')\n" "sudo serfex login --local"
printf '\n  %s\n  %s\n' \
	"$(t "Panel kendi sertifikasını alana kadar kendinden imzalı bir sertifika kullanır: SSL → Ayarlar." "The panel uses a self-signed certificate until it gets its own: SSL → Settings.")" \
	"$(t "Güvenlik duvarında 80, 443 ve $WEB_PORT portlarını (DNS ve posta için 53, 25, 587, 993) açın." "Open ports 80, 443 and $WEB_PORT (and 53, 25, 587, 993 for DNS and mail) in your firewall.")"
