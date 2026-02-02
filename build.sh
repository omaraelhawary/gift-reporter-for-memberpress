#!/bin/bash

# Gift Reporter for MemberPress - Build Script
# This script creates a consistent distribution zip file

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}🚀 Building Gift Reporter for MemberPress distribution package...${NC}"

# Get the plugin directory
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_NAME="gift-reporter-for-memberpress"
ZIP_NAME="${PLUGIN_NAME}.zip"
BUILD_DIR="${PLUGIN_DIR}/build"

# Clean up any existing build artifacts
echo -e "${YELLOW}🧹 Cleaning up previous build artifacts...${NC}"
rm -f "${PLUGIN_DIR}/${ZIP_NAME}"
rm -rf "${BUILD_DIR}"

# Create build directory
mkdir -p "${BUILD_DIR}"

# Copy plugin files to build directory, excluding development files
echo -e "${YELLOW}📁 Copying plugin files...${NC}"
rsync -av --progress "${PLUGIN_DIR}/" "${BUILD_DIR}/${PLUGIN_NAME}/" \
    --exclude='.git/' \
    --exclude='.gitignore' \
    --exclude='.DS_Store' \
    --exclude='.DS_Store?' \
    --exclude='._*' \
    --exclude='.Spotlight-V100' \
    --exclude='.Trashes' \
    --exclude='ehthumbs.db' \
    --exclude='Thumbs.db' \
    --exclude='*.swp' \
    --exclude='*.swo' \
    --exclude='*~' \
    --exclude='*.tmp' \
    --exclude='*.temp' \
    --exclude='*.bak' \
    --exclude='*.backup' \
    --exclude='*.log' \
    --exclude='error_log' \
    --exclude='access_log' \
    --exclude='node_modules/' \
    --exclude='npm-debug.log*' \
    --exclude='yarn-debug.log*' \
    --exclude='yarn-error.log*' \
    --exclude='.vscode/' \
    --exclude='.idea/' \
    --exclude='.phpcs.cache' \
    --exclude='composer.lock' \
    --exclude='package-lock.json' \
    --exclude='vendor/' \
    --exclude='build.sh' \
    --exclude='build/' \
    --exclude='BUILD.md' \
    --exclude='*.zip' \
    --exclude='CHANGELOG.md' \
    --exclude='CODE_OF_CONDUCT.md' \
    --exclude='CONTRIBUTING.md' \
    --exclude='GITHUB_SETUP.md' \
    --exclude='INSTALL.md' \
    --exclude='SECURITY.md' \
    --exclude='.github/' \
    --exclude='composer.json' \
    --exclude='package.json' \
    --exclude='phpcs.xml' \
    --exclude='screenshots/' \
    --exclude='deploy-svn.sh' \
    --exclude='SVN-DEPLOY.md' \
    --exclude='svn-export/'

# Create the zip file
echo -e "${YELLOW}📦 Creating zip file...${NC}"
cd "${BUILD_DIR}"
zip -r "${ZIP_NAME}" "${PLUGIN_NAME}/" -x "*.DS_Store*" "*/.*"

# Move zip file to plugin directory
mv "${ZIP_NAME}" "${PLUGIN_DIR}/"

# Clean up build directory
rm -rf "${BUILD_DIR}"

# Get file size
FILE_SIZE=$(du -h "${PLUGIN_DIR}/${ZIP_NAME}" | cut -f1)

echo -e "${GREEN}✅ Build completed successfully!${NC}"
echo -e "${GREEN}📦 Distribution package: ${PLUGIN_DIR}/${ZIP_NAME} (${FILE_SIZE})${NC}"
echo -e "${GREEN}🎯 Ready for distribution to customers!${NC}"

# Optional: Show zip contents
echo -e "${YELLOW}📋 Package contents:${NC}"
unzip -l "${PLUGIN_DIR}/${ZIP_NAME}" | head -20
