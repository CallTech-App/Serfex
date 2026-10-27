#!/bin/sh
# Installs the servik command line on Linux or macOS (amd64 or arm64).
#
#   curl -fsSL https://github.com/CallTech-App/Serfex/releases/latest/download/install-cli.sh | sh
#
# A Servik panel serves its own copy, which installs the panel's version:
#
#   curl -fsSL https://panel.example.com:8443/cli/install.sh | sh
#
# Environment:
#   SERVIK_INSTALL_DIR  where to put servik (default: /usr/local/bin when
#                       it is writable, else ~/.local/bin)
#   SERVIK_INSECURE=1   accept the panel's own certificate (before Let's
#                       Encrypt); the download is still checked against
#                       the panel's SHA256SUMS
set -eu

# The panel replaces this line with its own address.
SOURCE='https://github.com/CallTech-App/Serfex/releases/latest/download'

case ${LANG:-}${LC_ALL:-} in tr* | *tr_*) tr=1 ;; *) tr=0 ;; esac
say() { if [ "$tr" = 1 ]; then printf '%s\n' "$1"; else printf '%s\n' "$2"; fi; }
die() {
	say "servik: $1" "servik: $2" >&2
	exit 1
}

case $(uname -s) in
Linux) os=linux ;;
Darwin) os=darwin ;;
*) die "Bu sistem desteklenmiyor: $(uname -s) (Linux ya da macOS gerekir)." "This system is not supported: $(uname -s) (Linux or macOS is needed)." ;;
esac
case $(uname -m) in
x86_64 | amd64) arch=amd64 ;;
aarch64 | arm64) arch=arm64 ;;
*) die "Bu işlemci desteklenmiyor: $(uname -m)." "This processor is not supported: $(uname -m)." ;;
esac
file=servik-cli-$os-$arch

insecure=
[ "${SERVIK_INSECURE:-}" = 1 ] && insecure=-k
fetch() {
	if command -v curl > /dev/null 2>&1; then
		curl -fsSL $insecure -o "$2" "$1"
	elif command -v wget > /dev/null 2>&1; then
		wget -q ${insecure:+--no-check-certificate} -O "$2" "$1"
	else
		die "curl ya da wget gerekli." "curl or wget is needed."
	fi
}

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
say "▸ $file indiriliyor ($SOURCE)" "▸ Downloading $file ($SOURCE)"
fetch "$SOURCE/$file" "$tmp/servik" || die "$file indirilemedi." "$file could not be downloaded."
fetch "$SOURCE/SHA256SUMS" "$tmp/SHA256SUMS" || die "SHA256SUMS indirilemedi." "SHA256SUMS could not be downloaded."

want=$(awk -v f="$file" '$2 == f || $2 == "*" f { print $1 }' "$tmp/SHA256SUMS")
[ -n "$want" ] || die "SHA256SUMS içinde $file yok." "SHA256SUMS does not list $file."
if command -v sha256sum > /dev/null 2>&1; then
	got=$(sha256sum "$tmp/servik" | awk '{ print $1 }')
else
	got=$(shasum -a 256 "$tmp/servik" | awk '{ print $1 }')
fi
[ "$got" = "$want" ] || die "$file doğrulanamadı (SHA-256 tutmuyor)." "$file failed verification (SHA-256 mismatch)."
chmod 0755 "$tmp/servik"

dir=${SERVIK_INSTALL_DIR:-}
if [ -z "$dir" ]; then
	if [ -w /usr/local/bin ]; then
		dir=/usr/local/bin
	else
		dir=$HOME/.local/bin
	fi
fi
mkdir -p "$dir"
mv "$tmp/servik" "$dir/servik"
[ "$os" = darwin ] && xattr -d com.apple.quarantine "$dir/servik" 2> /dev/null || true

say "✓ $("$dir/servik" version) kuruldu: $dir/servik" "✓ $("$dir/servik" version) installed: $dir/servik"
case ":$PATH:" in
*":$dir:"*) ;;
*) say "  $dir PATH içinde değil; kabuğunuzun ayarına ekleyin: export PATH=\"$dir:\$PATH\"" "  $dir is not on your PATH; add it in your shell's settings: export PATH=\"$dir:\$PATH\"" ;;
esac
say "  Giriş yapmak için: servik login <panel-adresi>" "  To sign in: servik login <panel-address>"
say "  Kabuk tamamlama:   servik completion --help" "  Shell completion:  servik completion --help"
