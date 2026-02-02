#!/bin/bash
#
# Gift Reporter for MemberPress - WordPress.org SVN Deploy
# Prepares a clean copy for https://plugins.svn.wordpress.org/memberpress-gift-reporter/
#
# Usage:
#   ./deploy-svn.sh                    # Export to ./svn-export/ (manual copy to SVN)
#   ./deploy-svn.sh /path/to/svn-checkout   # Sync directly into SVN checkout
#

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SVN_CHECKOUT="${1:-}"
EXPORT_DIR="${PLUGIN_DIR}/svn-export"

# Get stable tag from readme.txt
get_version() {
	local readme="${PLUGIN_DIR}/readme.txt"
	if [[ -f "$readme" ]]; then
		grep -E '^Stable tag:' "$readme" | sed 's/Stable tag:[[:space:]]*//' | tr -d '\r'
	else
		echo ""
	fi
}

VERSION=$(get_version)
if [[ -z "$VERSION" ]]; then
	echo -e "${RED}Could not read Stable tag from readme.txt${NC}"
	exit 1
fi

# Files/dirs to exclude from WordPress.org trunk (same as zip, but we keep readme.txt, LICENSE, screenshots)
RSYNC_EXCLUDES=(
	--exclude='.git/'
	--exclude='.gitignore'
	--exclude='.DS_Store'
	--exclude='.DS_Store?'
	--exclude='._*'
	--exclude='.Spotlight-V100'
	--exclude='.Trashes'
	--exclude='ehthumbs.db'
	--exclude='Thumbs.db'
	--exclude='*.swp'
	--exclude='*.swo'
	--exclude='*~'
	--exclude='*.tmp'
	--exclude='*.temp'
	--exclude='*.bak'
	--exclude='*.backup'
	--exclude='*.log'
	--exclude='error_log'
	--exclude='access_log'
	--exclude='node_modules/'
	--exclude='npm-debug.log*'
	--exclude='yarn-debug.log*'
	--exclude='yarn-error.log*'
	--exclude='.vscode/'
	--exclude='.idea/'
	--exclude='.phpcs.cache'
	--exclude='composer.lock'
	--exclude='package-lock.json'
	--exclude='vendor/'
	--exclude='build.sh'
	--exclude='build/'
	--exclude='*.zip'
	--exclude='.github/'
	--exclude='composer.json'
	--exclude='package.json'
	--exclude='phpcs.xml'
	--exclude='CODE_OF_CONDUCT.md'
	--exclude='CONTRIBUTING.md'
	--exclude='SECURITY.md'
	--exclude='README.md'
	--exclude='svn-export/'
	--exclude='deploy-svn.sh'
)

echo -e "${GREEN}Preparing WordPress.org SVN package (Stable tag: ${VERSION})...${NC}"

# Always refresh export dir for manual use or for syncing into SVN
rm -rf "${EXPORT_DIR}"
mkdir -p "${EXPORT_DIR}"

rsync -a "${RSYNC_EXCLUDES[@]}" "${PLUGIN_DIR}/" "${EXPORT_DIR}/"

if [[ -n "$SVN_CHECKOUT" ]]; then
	# Sync into existing SVN checkout
	SVN_CHECKOUT="$(cd "$SVN_CHECKOUT" && pwd)"
	if [[ ! -d "${SVN_CHECKOUT}/.svn" ]]; then
		echo -e "${RED}Not an SVN checkout: ${SVN_CHECKOUT}${NC}"
		exit 1
	fi
	echo -e "${YELLOW}Syncing to SVN trunk...${NC}"
	rsync -a --delete "${EXPORT_DIR}/" "${SVN_CHECKOUT}/trunk/"
	if [[ -d "${SVN_CHECKOUT}/tags/${VERSION}" ]]; then
		echo -e "${YELLOW}Updating tag: ${VERSION}...${NC}"
		rsync -a --delete "${EXPORT_DIR}/" "${SVN_CHECKOUT}/tags/${VERSION}/"
	else
		echo -e "${YELLOW}Creating tag: ${VERSION}...${NC}"
		mkdir -p "${SVN_CHECKOUT}/tags/${VERSION}"
		rsync -a "${EXPORT_DIR}/" "${SVN_CHECKOUT}/tags/${VERSION}/"
	fi
	echo -e "${GREEN}Done. Next steps:${NC}"
	echo "  cd ${SVN_CHECKOUT}"
	echo "  svn status"
	echo "  svn add --force trunk tags/${VERSION}   # if new files"
	echo "  svn ci -m 'Update to ${VERSION}'"
else
	echo -e "${GREEN}Exported to: ${EXPORT_DIR}${NC}"
	echo ""
	echo -e "${YELLOW}To upload to WordPress.org:${NC}"
	echo "  1. Checkout: svn co https://plugins.svn.wordpress.org/memberpress-gift-reporter/ wp-svn"
	echo "  2. Copy:     rsync -av --delete ${EXPORT_DIR}/ wp-svn/trunk/"
	echo "  3. Tag:      cp -r wp-svn/trunk wp-svn/tags/${VERSION}"
	echo "  4. Commit:   cd wp-svn && svn add --force . && svn ci -m 'Update to ${VERSION}'"
fi
