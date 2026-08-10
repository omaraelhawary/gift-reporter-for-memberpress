#!/usr/bin/env bash
# Build a distributable WordPress plugin zip (no tests, CI, Composer, or dev docs).
#
# Usage (from plugin root):
#   bash scripts/build-release.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SLUG="memberpress-gift-reporter"
MAIN_PHP="${ROOT}/gift-reporter-for-memberpress.php"

if [[ ! -f "${MAIN_PHP}" ]]; then
  echo "error: expected bootstrap at ${MAIN_PHP}" >&2
  exit 1
fi

VERSION="$(
  sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "${MAIN_PHP}" | head -n1 | tr -d '\r'
)"
if [[ -z "${VERSION}" ]]; then
  echo "error: could not parse Version from ${MAIN_PHP}" >&2
  exit 1
fi

OUT_DIR="${ROOT}/dist"
ARCHIVE_NAME="${SLUG}-${VERSION}.zip"
ARCHIVE_PATH="${OUT_DIR}/${ARCHIVE_NAME}"

if [[ -f "${ROOT}/package.json" ]] && command -v npm >/dev/null 2>&1; then
  (cd "${ROOT}" && npm ci && npm run build)
fi

TMP="$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}.build.XXXXXX")"
cleanup() {
  rm -rf "${TMP}"
}
trap cleanup EXIT

STAGE="${TMP}/${SLUG}"
mkdir -p "${STAGE}" "${OUT_DIR}"

if ! command -v rsync >/dev/null 2>&1; then
  echo "error: rsync is required (install Xcode CLT on macOS)" >&2
  exit 1
fi

rsync -a \
  --delete \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.cursor/' \
  --exclude='.superpowers/' \
  --exclude='dist/' \
  --exclude="${SLUG}-svn/" \
  --exclude='wordpress-org-assets/' \
  --exclude='scripts/' \
  --exclude='tests/' \
  --exclude='vendor/' \
  --exclude='docs/' \
  --exclude='phpunit.xml.dist' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='composer.phar' \
  --exclude='.phpunit.result.cache' \
  --exclude='.DS_Store' \
  --exclude='.gitignore' \
  --exclude='CLAUDE.md' \
  --exclude='REVIEW.md' \
  --exclude='node_modules/' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  --exclude='build.sh' \
  --exclude='build/' \
  --exclude='deploy-svn.sh' \
  --exclude='SVN-DEPLOY.md' \
  --exclude='svn-export/' \
  --exclude='README.md' \
  --exclude='CODE_OF_CONDUCT.md' \
  --exclude='CONTRIBUTING.md' \
  --exclude='SECURITY.md' \
  --exclude='phpcs.xml' \
  --exclude='screenshots/' \
  --exclude='rounds/' \
  --exclude='bin/' \
  "${ROOT}/" "${STAGE}/"

(
  cd "${TMP}"
  rm -f "${ARCHIVE_PATH}"
  zip -rq "${ARCHIVE_PATH}" "${SLUG}"
)

echo "Built ${ARCHIVE_PATH} ($(du -h "${ARCHIVE_PATH}" | awk '{print $1}'))"
