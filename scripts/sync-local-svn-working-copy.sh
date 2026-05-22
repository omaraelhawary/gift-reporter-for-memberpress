#!/usr/bin/env bash
# Refresh local WordPress.org SVN mirror (memberpress-gift-reporter-svn/).
#
# Usage:
#   bash scripts/sync-local-svn-working-copy.sh
#   bash scripts/sync-local-svn-working-copy.sh --fresh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SLUG="memberpress-gift-reporter"
MAIN_PHP="gift-reporter-for-memberpress.php"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
LOCAL_WC="${ROOT}/${SLUG}-svn"
FRESH=0

for arg in "$@"; do
  case "${arg}" in
    --fresh) FRESH=1 ;;
    -h|--help)
      echo "usage: bash scripts/sync-local-svn-working-copy.sh [--fresh]"
      exit 0
      ;;
    *)
      echo "error: unknown argument: ${arg}" >&2
      exit 1
      ;;
  esac
done

if [[ -n "${GITHUB_ACTIONS:-}" ]]; then
  echo "GitHub Actions cannot update SVN folders on your computer."
  echo "After a successful deploy, run locally:"
  echo "  bash scripts/sync-local-svn-working-copy.sh"
  exit 0
fi

if ! command -v svn >/dev/null 2>&1; then
  echo "error: svn is required (install with: brew install subversion)" >&2
  exit 1
fi

is_svn_wc() {
  [[ -d "${1}/.svn" ]] && svn info "${1}" >/dev/null 2>&1
}

if is_svn_wc "${LOCAL_WC}"; then
  echo "Updating local SVN mirror: ${LOCAL_WC}"
  svn update "${LOCAL_WC}"
elif [[ -e "${LOCAL_WC}" ]]; then
  if [[ "${FRESH}" -eq 1 ]]; then
    echo "Removing stale folder: ${LOCAL_WC}"
    rm -rf "${LOCAL_WC}"
  else
    echo "error: ${LOCAL_WC} exists but is not a Subversion working copy" >&2
    echo "Fix: bash scripts/sync-local-svn-working-copy.sh --fresh" >&2
    exit 1
  fi
  echo "Checking out WordPress.org SVN to: ${LOCAL_WC}"
  svn checkout "${SVN_URL}" "${LOCAL_WC}"
else
  echo "Checking out WordPress.org SVN to: ${LOCAL_WC}"
  svn checkout "${SVN_URL}" "${LOCAL_WC}"
fi

TRUNK_VERSION="$(
  sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "${LOCAL_WC}/trunk/${MAIN_PHP}" 2>/dev/null | head -n1 | tr -d '\r' || true
)"
echo "Local mirror ready. trunk Version: ${TRUNK_VERSION:-unknown}"
echo "Recent tags on WordPress.org:"
svn list "${SVN_URL}/tags/" 2>/dev/null | tail -5 || true
