# Deploying to WordPress.org Plugin Directory (SVN)

This project uses Git; WordPress.org hosts plugins in **SVN**. Use the steps below to publish updates to [wordpress.org/plugins/memberpress-gift-reporter](https://wordpress.org/plugins/memberpress-gift-reporter/).

## Prerequisites

- **SVN** installed (`svn --version`)
- **WordPress.org plugin SVN** access (same account as the one used to submit the plugin)
- **Stable tag** in `readme.txt` must match the version you are releasing (e.g. `Stable tag: 1.6.2`)

## Quick deploy (recommended)

### 1. Prepare the SVN export

From the plugin root:

```bash
chmod +x deploy-svn.sh
./deploy-svn.sh
```

This creates `svn-export/` with only the files that should go in WordPress.org (no `.git`, `node_modules`, dev docs, etc.).

### 2. Checkout WordPress.org SVN (first time only)

```bash
svn co https://plugins.svn.wordpress.org/memberpress-gift-reporter/ wp-svn
cd wp-svn
```

### 3. Update trunk and tag

From the plugin root (not inside `wp-svn`):

```bash
# Copy export into trunk
rsync -av --delete svn-export/ wp-svn/trunk/

# Create or update the tag for current version (e.g. 1.6.2)
# Replace 1.6.2 with the "Stable tag" from readme.txt
rm -rf wp-svn/tags/1.6.2
cp -r svn-export wp-svn/tags/1.6.2
```

### 4. Commit to WordPress.org

```bash
cd wp-svn
svn status
svn add --force trunk tags
svn ci -m "Update to 1.6.2"
```

When prompted, use your **WordPress.org** username and password (or app password).

---

## Deploy directly into an SVN checkout

If you already have the SVN repo checked out (e.g. `../memberpress-gift-reporter-svn`):

```bash
./deploy-svn.sh /path/to/memberpress-gift-reporter-svn
```

The script will:

- Sync the plugin files into `trunk/`
- Create or update `tags/<version>/` from the "Stable tag" in `readme.txt`

Then:

```bash
cd /path/to/memberpress-gift-reporter-svn
svn status
svn add --force trunk tags
svn ci -m "Update to 1.6.2"
```

---

## What gets included

- Plugin PHP, JS, CSS, views, languages, `readme.txt`, `LICENSE`, `screenshots/`, `uninstall.php`
- **Excluded:** `.git/`, `.github/`, `node_modules/`, `package.json`, `composer.json`, `phpcs.xml`, `build.sh`, `README.md`, and other dev-only files

---

## WordPress.org SVN layout

| Path        | Purpose |
|------------|---------|
| `trunk/`   | What users get when they install "latest" from WordPress.org |
| `tags/1.6.2/` | Specific release; `readme.txt` "Stable tag" must match the tag folder name |
| `assets/`  | Plugin page assets (banner, icon) — optional; add here if you use them |

---

## Troubleshooting

- **"Stable tag" in readme.txt** must exactly match the tag folder name (e.g. `1.6.2`).
- **New files:** Run `svn add --force trunk tags` before `svn ci`.
- **Deleted files:** `svn status` will show them; remove with `svn delete <file>` then commit.
- **Credentials:** Use your WordPress.org login; for 2FA, create an [Application Password](https://wordpress.org/support/article/application-passwords/) and use it as the password.
