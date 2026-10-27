#!/usr/bin/env bash
# Builds the release files of Serfex VERSION into OUTDIR:
#
#   serfex-VERSION-linux-amd64.tar.gz   panel + agent for x86-64 servers
#   serfex-VERSION-linux-arm64.tar.gz   panel + agent for ARM servers
#   serfex-cli-{linux,darwin}-{amd64,arm64}  the serfex command line
#   install-cli.sh                      the command line's installer
#   install.sh                          the installer
#   SHA256SUMS                          sums of the files above
#
# An archive unpacks into /opt/serfex/releases/VERSION (see install.sh):
# VERSION, bin/serfex-agent, bin/serfex (the command line), panel/ with
# its production dependencies and built assets, and downloads/ with the
# command line for every system, which the panel serves at /cli/indir/
# for developers' computers. Needs Go, PHP with Composer, and Node.js.
#
#   scripts/build-release.sh 0.2.0 dist
#
# SERFEX_ARCHES limits the architectures ("amd64"). SERFEX_PANEL_BUILD
# names an already built panel directory to package instead of building
# one (CI packages test releases from one build).
set -euo pipefail

version=${1:?usage: build-release.sh VERSION OUTDIR}
out=${2:?usage: build-release.sh VERSION OUTDIR}
if ! [[ $version =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?$ ]]; then
	echo "version must look like 1.2.3 or 1.2.3-beta.1 (no leading v)" >&2
	exit 2
fi
root=$(cd "$(dirname "$0")/.." && pwd)
mkdir -p "$out"
out=$(cd "$out" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

panel=${SERFEX_PANEL_BUILD:-}
if [ -z "$panel" ]; then
	echo "▸ Building the panel"
	panel=$work/panel
	mkdir -p "$panel"
	# Tracked and new files, never local .env, vendor or node_modules.
	(cd "$root/panel" && git ls-files -z --cached --others --exclude-standard | tar --null -T - -cf -) | tar -xf - -C "$panel"
	(
		cd "$panel"
		COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
		npm ci --no-audit --no-fund
		npm run build
	)
fi

echo "▸ Building the command line"
cli=$work/downloads
mkdir -p "$cli"
for target in linux/amd64 linux/arm64 darwin/amd64 darwin/arm64; do
	(cd "$root/cli" && CGO_ENABLED=0 GOOS=${target%/*} GOARCH=${target#*/} go build -trimpath \
		-ldflags "-s -w -X github.com/calltech-app/serfex/cli/internal/version.Version=$version" \
		-o "$cli/serfex-cli-${target%/*}-${target#*/}" ./cmd/serfex)
done
cp "$root/scripts/install-cli.sh" "$cli/install-cli.sh"
(cd "$cli" && sha256sum serfex-cli-* install-cli.sh > SHA256SUMS)

strip_panel() {
	local p=$1
	rm -rf "$p/node_modules" "$p/tests" "$p/storage" "$p/.env" "$p/.env.example" "$p/phpunit.xml" "$p/.phpunit.result.cache"
	find "$p/bootstrap/cache" -mindepth 1 ! -name .gitignore -delete
	# Packages Composer had to clone instead of download.
	find "$p/vendor" -name .git -prune -exec rm -rf {} +
}

for arch in ${SERFEX_ARCHES:-amd64 arm64}; do
	echo "▸ Packaging serfex-$version-linux-$arch"
	stage=$work/stage-$arch
	rm -rf "$stage"
	mkdir -p "$stage/bin"
	cp -a "$panel" "$stage/panel"
	strip_panel "$stage/panel"
	echo "$version" > "$stage/VERSION"
	echo "$version" > "$stage/panel/VERSION"
	(cd "$root/agent" && CGO_ENABLED=0 GOOS=linux GOARCH=$arch go build -trimpath \
		-ldflags "-s -w -X github.com/calltech-app/serfex/agent/internal/version.Version=$version" \
		-o "$stage/bin/serfex-agent" ./cmd/serfex-agent)
	cp "$cli/serfex-cli-linux-$arch" "$stage/bin/serfex"
	cp -a "$cli" "$stage/downloads"
	cp "$root/scripts/install.sh" "$stage/install.sh"
	tar --sort=name --owner=0 --group=0 --numeric-owner -C "$stage" -czf "$out/serfex-$version-linux-$arch.tar.gz" .
done

cp "$root/scripts/install.sh" "$out/install.sh"
cp "$cli"/serfex-cli-* "$cli/install-cli.sh" "$out/"
(cd "$out" && sha256sum serfex-"$version"-linux-*.tar.gz serfex-cli-* install-cli.sh install.sh > SHA256SUMS)
echo "✓ $(ls "$out" | tr '\n' ' ')"
