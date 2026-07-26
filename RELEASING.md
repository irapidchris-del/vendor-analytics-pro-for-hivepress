# Releasing a new version

The plugin self-updates from **GitHub Releases** using the
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
library bundled in `includes/plugin-update-checker/`. Once a site is running a
version that contains the updater, every future GitHub release shows up on the
site's **Plugins** screen with an update notice and a one-click update.

## One-time setup

- The repository must be **public** (or releases must be publicly downloadable).
  The updater and the download link below rely on unauthenticated access to the
  release asset. Do not embed a personal access token in the plugin.
- The first public build that contains the updater is the baseline: users must
  install it once (manually) before automatic updates can take over.

## Cutting a release

1. **Bump the version in all four places** (they must match):
   - `hivepress-vendor-analytics.php` header — `Version:`
   - `hivepress-vendor-analytics.php` — `define( 'HPVA_VERSION', ... )`
   - `readme.txt` — `Stable tag:`
   - `readme.txt` — the top `== Changelog ==` entry
2. **Build the zips:**
   ```bash
   ./build.sh
   ```
   This writes two files to `dist/`:
   - `vendor-analytics-pro-for-hivepress.zip` — the **release asset** (clean name)
   - `vendor-analytics-pro-for-hivepress-<version>.zip` — an identical copy with
     the version in the file name, for your own tracking
   Both contain a single top-level folder `vendor-analytics-pro-for-hivepress/`,
   so WordPress installs/updates them into the correct folder with no warnings.
3. **Create a GitHub release:**
   - Tag it `v<version>` or `<version>` (e.g. `v1.5.0`). The tag drives the
     version the updater compares against, so it must equal the header version.
   - Paste the changelog for this version into the release notes.
   - **Attach `dist/vendor-analytics-pro-for-hivepress.zip` as a release asset.**
     The file name of the attached asset must stay exactly
     `vendor-analytics-pro-for-hivepress.zip` on every release.
4. **Publish.** Within a few hours WordPress sites will see the update (users can
   force an immediate check with the "Check for updates" link on the Plugins
   screen).

## The permanent download link

Because every release attaches an asset with the same name, GitHub's
"latest release" redirect always points at the newest build:

```
https://github.com/irapidchris-del/vendor-analytics-pro-for-hivepress/releases/latest/download/vendor-analytics-pro-for-hivepress.zip
```

This URL ends in `.zip`, downloads instantly, and never needs updating — post it
once on the HivePress community forum and it will always serve the latest version.

## Notes

- `build/` and `dist/` are git-ignored; they are local artifacts only.
- The updater only loads in the admin and during cron, so it adds nothing to
  front-end page loads.
- If the bundled library is ever removed, the plugin keeps working — it simply
  stops self-updating.
