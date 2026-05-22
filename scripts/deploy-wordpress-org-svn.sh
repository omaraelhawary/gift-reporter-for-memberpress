#!/usr/bin/env bash
# Build and deploy to WordPress.org plugin SVN (trunk + version tag).
#
# Environment: SVN_USERNAME, SVN_PASSWORD (application password)
# Optional: SVN_WC_DIR (existing checkout; else temp checkout)
# Optional: EXPECTED_VERSION (fail if header Version mismatch)
#
# Usage:
#   export SVN_USERNAME=your-wp-org-username
#   export SVN_PASSWORD=your-application-password
#   bash scripts/deploy-wordpress-org-svn.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SLUG="memberpress-gift-reporter"
MAIN_PHP="${ROOT}/gift-reporter-for-memberpress.php"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"

if [[ -z "${SVN_USERNAME:-}" || -z "${SVN_PASSWORD:-}" ]]; then
  echo "error: set SVN_USERNAME and SVN_PASSWORD (WordPress.org application password)" >&2
  exit 1
fi

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

if [[ -n "${EXPECTED_VERSION:-}" && "${VERSION}" != "${EXPECTED_VERSION}" ]]; then
  echo "error: plugin Version is ${VERSION}, expected ${EXPECTED_VERSION}" >&2
  exit 1
fi

svn_non_interactive() {
  svn "$@" \
    --username "${SVN_USERNAME}" \
    --password "${SVN_PASSWORD}" \
    --non-interactive \
    --no-auth-cache
}

svn_tag_exists() {
  svn_non_interactive list "${SVN_URL}/tags/" 2>/dev/null | grep -qE "^${VERSION}/"
}

bash "${SCRIPT_DIR}/build-release.sh"

OWN_WC=false
if [[ -z "${SVN_WC_DIR:-}" ]]; then
  OWN_WC=true
  SVN_WC_DIR="$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}.svn-deploy.XXXXXX")"
  svn_non_interactive checkout "${SVN_URL}" "${SVN_WC_DIR}"
fi

cleanup() {
  if [[ "${OWN_WC}" == true ]]; then
    rm -rf "${SVN_WC_DIR}"
  fi
}
trap cleanup EXIT

cd "${SVN_WC_DIR}"
svn_non_interactive update

bash "${SCRIPT_DIR}/prepare-wordpress-org-svn-working-copy.sh" "${SVN_WC_DIR}"

svn_non_interactive add --force trunk assets

while IFS= read -r missing; do
  [[ -n "${missing}" ]] && svn_non_interactive rm --force "${missing}"
done < <(svn status | awk '/^!/ {print $2}')

if [[ -n "$(svn status --quiet)" ]]; then
  svn_non_interactive commit -m "Release ${VERSION}"
  echo "Committed trunk/assets changes for ${VERSION}"
else
  echo "No trunk/assets changes to commit (trunk may already match the release zip)"
fi

if svn_tag_exists; then
  echo "tags/${VERSION} already exists on WordPress.org SVN"
else
  echo "Creating tags/${VERSION} from trunk (remote copy)..."
  if ! svn_non_interactive copy "${SVN_URL}/trunk" "${SVN_URL}/tags/${VERSION}" -m "Tag ${VERSION}"; then
    echo "::error::Failed to create SVN tag ${VERSION}. Check SVN_USERNAME/SVN_PASSWORD and commit access." >&2
    exit 1
  fi
  echo "Created tags/${VERSION}"
fi

echo "Deploy finished for ${VERSION}"
bash "${SCRIPT_DIR}/sync-local-svn-working-copy.sh"
