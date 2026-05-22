#!/usr/bin/env bash
# Populate a WordPress.org plugin SVN working copy:
#   trunk/  ← contents of dist zip
#   assets/ ← icons, banners, screenshot-1
#
# Usage:
#   bash scripts/build-release.sh
#   svn checkout https://plugins.svn.wordpress.org/memberpress-gift-reporter ~/path/to/svn-wc
#   bash scripts/prepare-wordpress-org-svn-working-copy.sh ~/path/to/svn-wc
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SLUG="memberpress-gift-reporter"
MAIN_PHP="${ROOT}/gift-reporter-for-memberpress.php"

if [[ "${#}" -lt 1 ]]; then
  echo "usage: bash scripts/prepare-wordpress-org-svn-working-copy.sh /path/to/svn/working-copy" >&2
  exit 1
fi

SVN_WC="$(cd "${1}" && pwd)"

if [[ ! -d "${SVN_WC}/trunk" ]]; then
  echo "error: ${SVN_WC}/trunk not found — pass the top-level SVN checkout directory" >&2
  exit 1
fi

VERSION="$(
  sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "${MAIN_PHP}" | head -n1 | tr -d '\r'
)"
ZIP="${ROOT}/dist/${SLUG}-${VERSION}.zip"

if [[ ! -f "${ZIP}" ]]; then
  echo "error: missing ${ZIP} — run: bash scripts/build-release.sh" >&2
  exit 1
fi

TMP="$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}.svn-stage.XXXXXX")"
cleanup() {
  rm -rf "${TMP}"
}
trap cleanup EXIT

unzip -q "${ZIP}" -d "${TMP}"
STAGE="${TMP}/${SLUG}"
if [[ ! -d "${STAGE}" ]]; then
  echo "error: expected ${STAGE} inside zip" >&2
  exit 1
fi

if ! command -v rsync >/dev/null 2>&1; then
  echo "error: rsync is required" >&2
  exit 1
fi

rsync -a --delete "${STAGE}/" "${SVN_WC}/trunk/"

ASSETS_SRC="${ROOT}/wordpress-org-assets"
SHOT_SRC="${ROOT}/screenshots/dashboard.png"
mkdir -p "${SVN_WC}/assets"

if [[ -d "${ASSETS_SRC}" ]]; then
  for f in icon-128x128.png icon-256x256.png banner-772x250.png banner-1544x500.png; do
    if [[ -f "${ASSETS_SRC}/${f}" ]]; then
      cp -f "${ASSETS_SRC}/${f}" "${SVN_WC}/assets/${f}"
    fi
  done
  for f in screenshot-1.png screenshot-2.png; do
    if [[ -f "${ASSETS_SRC}/${f}" ]]; then
      cp -f "${ASSETS_SRC}/${f}" "${SVN_WC}/assets/${f}"
    fi
  done
fi

if [[ -f "${SHOT_SRC}" && ! -f "${SVN_WC}/assets/screenshot-1.png" ]]; then
  cp -f "${SHOT_SRC}" "${SVN_WC}/assets/screenshot-1.png"
elif [[ ! -f "${SVN_WC}/assets/screenshot-1.png" ]]; then
  echo "warning: ${SHOT_SRC} missing — add screenshot-1.png to wordpress-org-assets/ or screenshots/" >&2
fi

echo "Prepared trunk + assets under ${SVN_WC}"
echo "Version: ${VERSION} (from ${ZIP})"
echo "Next: set SVN_USERNAME and SVN_PASSWORD in your environment, then bash scripts/deploy-wordpress-org-svn.sh"
