# Releasing a new version

The plugin self-updates from **GitHub Releases** using WordPress's native update
API (the `Update URI` header + the `update_plugins_github.com` filter, WP 5.8+).
No third-party library is bundled. Once a site runs a version that contains the
updater, every later GitHub release shows up on the **Plugins** screen with an
update notice, a working "View version details" popup, and a one-click update.

## How it works

- The plugin polls `https://api.github.com/repos/OWNER/REPO/releases/latest`
  (cached in a site transient: 6h on success, 1h on failure).
- The new version is the release **tag** with any leading `v` stripped.
- The update package is the **first release asset whose name ends in `.zip`**.
- On install, the extracted folder is renamed to the plugin's own directory, so
  updates always land in `vendor-analytics-pro-for-hivepress/`.

## One-time setup

- The repository must be **public** (the updater and the download link use
  unauthenticated GitHub access — never embed a token).
- The first public build that contains the updater is the baseline: users
  install it once, and automatic updates take over from the next release.

## Cutting a release

1. **Bump the version in all four places** (they must match, or `build.sh`
   refuses to build):
   - `hivepress-vendor-analytics.php` header — `Version:`
   - `hivepress-vendor-analytics.php` — `define( 'HPVA_VERSION', ... )`
   - `readme.txt` — `Stable tag:`
   - `readme.txt` — the top `== Changelog ==` entry
2. Commit and merge to the default branch (`main`).

   > ⚠️ **Push the source before you release — always.** The workflow also
   > triggers on `release: published`, and it **rebuilds the zip from the repo
   > source** and re-uploads it with `--clobber`. If you create a release while
   > `main` is still on the previous version, the workflow overwrites whatever
   > zip you attached with a freshly built one from the *stale* source — every
   > updating site then gets old code under the new version number, which the
   > updater treats as up to date. The plugin folder in a WordPress install is
   > not a git clone, so a local check for `.github/` will wrongly report "no
   > workflow". The order is non-negotiable: **source to `main` first, release
   > second.**
3. **Publish the release.** The tag must equal the header version (prefixed with
   `v`, e.g. `v1.5.1`). Two equivalent ways:

   **a) From GitHub** — create a Release (tag `v1.5.1`), publish it, and the
   `release.yml` workflow builds and attaches
   `vendor-analytics-pro-for-hivepress.zip` automatically.

   **b) From the GitHub Actions tab** — run the **Release** workflow via
   *Run workflow* with `tag = v1.5.1` (and optional notes). It creates the
   release, sets the notes, and attaches the asset.

## Publishing from a Claude session

`gh` and the raw releases REST API are not available inside sessions, so drive
the workflow through the GitHub MCP instead:

1. Bump the `Version:` header (and the three other spots), commit, merge to `main`.
2. Trigger the workflow:
   `actions_run_trigger` → method `run_workflow`, `workflow_id: release.yml`,
   `ref: main`, `inputs: { tag: "v1.5.1", notes: "<changelog>" }`.
3. Verify with `get_release_by_tag` (tag `v1.5.1`) that the tag, the notes and
   the `vendor-analytics-pro-for-hivepress.zip` asset all landed.

## The permanent download link

Every release attaches an asset with the same name, so GitHub's "latest release"
redirect always points at the newest build:

```
https://github.com/irapidchris-del/vendor-analytics-pro-for-hivepress/releases/latest/download/vendor-analytics-pro-for-hivepress.zip
```

This URL ends in `.zip`, downloads instantly, and never changes — post it once on
the HivePress community forum.

## Notes

- `build.sh` produces `dist/vendor-analytics-pro-for-hivepress.zip` (the release
  asset) plus a version-suffixed copy for local tracking. Both wrap a single
  version-less `vendor-analytics-pro-for-hivepress/` folder, so WordPress never
  shows a folder-mismatch warning. `build/` and `dist/` are git-ignored.
- The release asset must **always** be named exactly
  `vendor-analytics-pro-for-hivepress.zip` (no version in the file name), or the
  permanent link above breaks.
