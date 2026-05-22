# Deploying to WordPress.org (SVN)

WordPress.org hosts this plugin in **SVN**: `https://plugins.svn.wordpress.org/memberpress-gift-reporter`

**Version source of truth:** `* Version:` in `gift-reporter-for-memberpress.php` (must match `Stable tag` in `readme.txt`).

## Prerequisites

- Plugin approved on WordPress.org with SVN access
- [SVN application password](https://make.wordpress.org/meta/handbook/tutorials-guides/svn-access/) for your WordPress.org username
- Local tools: `bash`, `rsync`, `zip`, `unzip`, `svn` (macOS: `brew install subversion`)
- For GitHub deploy: repository secrets `SVN_USERNAME` and `SVN_PASSWORD`

## Quick deploy (local)

```bash
# 1. Bump Version in gift-reporter-for-memberpress.php and Stable tag in readme.txt

# 2. Build release zip (runs npm build when package.json exists)
bash scripts/build-release.sh

# 3. Deploy to WordPress.org SVN
export SVN_USERNAME=your-wp-org-username
export SVN_PASSWORD=your-application-password
bash scripts/deploy-wordpress-org-svn.sh

# 4. Refresh local SVN mirror
bash scripts/sync-local-svn-working-copy.sh
```

Output zip: `dist/memberpress-gift-reporter-x.y.z.zip`

## GitHub release deploy

1. Bump `Version` and `Stable tag`, commit and push.
2. Create a GitHub Release with tag `x.y.z` or `vx.y.z` (must match plugin header `Version`).
3. The **Deploy to WordPress.org** workflow runs automatically.
4. Locally after success: `bash scripts/sync-local-svn-working-copy.sh`

## Manual SVN (prepare only)

```bash
bash scripts/build-release.sh
svn checkout https://plugins.svn.wordpress.org/memberpress-gift-reporter ~/svn-wc
bash scripts/prepare-wordpress-org-svn-working-copy.sh ~/svn-wc
cd ~/svn-wc
svn add --force trunk assets
svn commit -m "Release x.y.z"
svn copy https://plugins.svn.wordpress.org/memberpress-gift-reporter/trunk \
         https://plugins.svn.wordpress.org/memberpress-gift-reporter/tags/x.y.z \
         -m "Tag x.y.z"
```

## Assets

- Directory icons/banners: `wordpress-org-assets/` (see `wordpress-org-assets/README.md`)
- Screenshot: `screenshots/dashboard.png` → SVN `assets/screenshot-1.png`

## Scripts

| Script | Purpose |
|--------|---------|
| `scripts/build-release.sh` | Build `dist/memberpress-gift-reporter-x.y.z.zip` |
| `scripts/prepare-wordpress-org-svn-working-copy.sh` | Copy zip into SVN `trunk/` and assets |
| `scripts/deploy-wordpress-org-svn.sh` | Full deploy: build → commit trunk → create tag |
| `scripts/sync-local-svn-working-copy.sh` | Local mirror at `memberpress-gift-reporter-svn/` |

Legacy `deploy-svn.sh` and `build.sh` are superseded by `scripts/build-release.sh`.
