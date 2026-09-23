# Changelog

## 1.25.8
- Clarified the GitHub Owner/Org and Topic field descriptions in Options: they're still
  editable (this is the intended way to reuse this component for a different GitHub account's
  extensions if you ever fork it), but now explicitly warn that changing them can make the
  list come back empty - safe to try and revert, since self always shows regardless.

## 1.25.7
- Fixed "Database not set in Joomla\CMS\Installer\Installer" on Joomla 6, which had persisted
  through several attempted fixes since 1.23.0 (a `new Installer()` change, then a
  `setDatabase()` call, then a revert back to `Installer::getInstance()`). Root cause was PHP
  OPcache serving stale, previously-compiled bytecode after this component overwrote its own
  files during a self-update - not the Installer/database logic itself, which was never
  actually at fault. Fixed by calling `opcache_reset()` after every install/update/uninstall.

## 1.25.4
- Reverted 1.23.0's `new Installer()` change back to `Installer::getInstance()` after 1.25.3's
  attempted fix for it didn't resolve the underlying issue (see 1.25.7 for the real cause).

## 1.25.3
- Added an explicit `setDatabase()` call after `new Installer()`, working around a separate,
  confirmed Joomla 6.0 core regression (joomla-cms#45653) - didn't end up being the actual fix
  needed (see 1.25.4/1.25.7).

## 1.25.2
- "Update All"'s confirm dialog now uses `JoomlaDialog.confirm()` (Joomla's own dialog web
  component) instead of the browser's native `confirm()`, matching the admin UI's styling.

## 1.25.1
- Fixed the extensions list visually blending into the page background after the 1.22.0 CSS
  Grid rewrite (a real `<table>` gets a background/border for free from base template CSS that
  a `[role="table"]` div doesn't) - added explicit card-style framing.

## 1.25.0
- Filter placeholder now mentions it also matches technical ID and repository, not just name.
- Added state filter chips (All / Update available / Installed / Not installed / Incompatible)
  with live counts, above the text filter.
- The self card now shows its own error/incompatible message, matching the table.
- Removed an unused language key and updated `creationDate`.
- Moved the inline `<style>` block to `media/css/extensions.css` via WebAssetManager.
- Disabled `.table-responsive`'s horizontal scroll specifically below the mobile card-stacking
  breakpoint, where it no longer serves a purpose.

## 1.24.0
- Moved all inline `<script>`/`oninput=` JS to an external `media/js/extensions.js`, registered
  via WebAssetManager - CSP-friendly, and (for the CSS moved the same way in 1.25.0)
  override-able by a Joomla template the way inline blocks aren't.
- Replaced a global `Joomla.submitbutton` override with scoped click listeners on this
  component's own two toolbar buttons, avoiding any conflict with another script doing the same
  thing on the same page.
- `updateAll()` now always processes a pending self-update last, and stops immediately after -
  self-updating overwrites the very files the current request is executing.
- Documented in code that forking this component requires changing `SELF_OWNER_REPO`.

## 1.23.0
- Worked through a 12-point review. Notable fixes: `setEnabled()` (toggle) now clears the same
  extension caches install/update/uninstall already did; a missing changelog file no longer
  triggers the "stale data" banner; `InstalledHelper` now does one batched database query for
  the whole catalog instead of one per row; added length/backtrack limits around evaluating a
  remote-supplied `targetplatform` regex (a ReDoS surface); a component's "Settings" link now
  points at its actual Options screen instead of its main admin view (and the self card's
  Settings button, dropped without explanation back in 1.16.1, came back now that the link is
  actually useful); uninstalling a package now warns that bundled child extensions are removed
  too. Documented rather than fixed: a redirect-following gap in Joomla core's own
  `InstallerHelper::downloadPackage()`, and `RepoHelper`'s continued growth with no test suite.

## 1.22.1
- Fixed three precision issues in the changelog-since-installed-version feature: a "Keep a
  Changelog"-style `[Unreleased]` heading no longer gets mistaken for the latest release,
  version matching now tolerates a two-segment version string, and a match against a
  subfolder-specific `updates.xml` (e.g. `joomla6/updates.xml`) now pulls the changelog from
  that same subfolder instead of always the repo root.

## 1.22.0
- Replaced the HTML `<table>` powering the extensions list with a CSS Grid
  (`display: grid` + `display: contents` rows), eliminating the accumulated `table-layout`/
  `display:block`/JS-width-measuring workarounds from 1.18.1-1.18.3 - a spanning changelog
  detail cell now uses `grid-column: 1 / -1` natively. Also fixed 1.21.3's filter bug at the
  root instead of patching around it.

## 1.21.4
- Removed dead code left over from 1.16.0 pulling the self row out of the main table, and
  documented why the self card has never had a Settings button (fixed for real in 1.23.0).

## 1.21.3
- Fixed the name/repo/ID filter silently undoing the 1.18.x column-shift fix after a search -
  clearing a changelog row back to visible lost its `display: block` override.

## 1.21.2
- Self-uninstall and self-disable were UI-only, not enforced server-side - a direct POST could
  still trigger either. Added an explicit server-side refusal.

## 1.21.1
- Fixed a regression from 1.20.3: the hidden `task` field it added collided with the per-row
  action buttons (also named `task`), silently breaking Install/Update/Toggle/Uninstall.

## 1.21.0
- Confirmed "Update All" working from the native toolbar; removed 1.20.2's temporary debug
  color from the button.

## 1.20.3
- Found the actual reason "Update All" did nothing after confirming: the form had no dedicated
  hidden field for the toolbar's JS to write the task into. Fixed (see 1.21.1 for a regression
  this introduced, fixed there).

## 1.20.1
- Attempted fix for "Update All" not working after 1.19.1 moved it to the native toolbar;
  didn't fully work in practice (see 1.20.3).

## 1.20.0
- Added responsive card-stacking for the extensions table on narrow screens, replacing
  horizontal scroll as the only option there.

## 1.19.1
- Moved "Update All" into the native Joomla toolbar next to Refresh, with its confirm dialog
  preserved. Combined the extension-count line and the filter field onto one row.

## 1.19.0
- Moved Refresh into the native Joomla toolbar, next to Options.

## 1.18.4
- Rebalanced the table's column widths - Extension needed more room, several others needed
  much less.

## 1.18.1 - 1.18.3
- Fixed the table columns shifting when a changelog row expanded, through three iterations
  (table-layout:fixed, then a display:block override, then a JS width-measurement workaround).
  Superseded entirely by 1.22.0's CSS Grid rewrite, which didn't need any of these workarounds.

## 1.18.0
- Fixed a regression from 1.17.0 where an up-to-date extension's Changelog button silently
  disappeared instead of falling back to showing the latest entry.
- Added a name/technical-ID/repository filter field, and an always-visible "last checked"
  timestamp.

## 1.17.0
- Changelog preview now shows everything that changed since your installed version, not just
  the single latest entry.

## 1.16.2
- Moved the extension-count/toolbar row below the self card instead of above it.

## 1.16.1
- Removed the Enable/Disable toggle from the self card - the same footgun reasoning as
  Uninstall already being hidden there.

## 1.16.0
- Pulled the self row out of the main table entirely into its own card above it.

## 1.15.0 - 1.15.1
- FG Extension Manager now tracks and displays itself, pinned to the top of the list with a
  distinct visual treatment; Uninstall is never shown for it.

## 1.14.0
- Added a "Settings" button (linking to the plugin edit screen or the component's own admin
  screen) for installed rows where Joomla has one well-defined target.

## 1.13.0 - 1.13.4
- Several display refinements: dropped the redundant "Up to date" text, restyled the Extension
  column closer to a KREM-like layout, and fixed uneven row shading caused by CSS `nth-child`
  striping miscounting hidden changelog-detail rows - replaced with striping keyed to the
  actual visible row index.

## 1.12.0
- Repository column now shows just the repo name; incompatible-extension messages show a
  readable platform range instead of a raw regex; added a short description line under each
  extension's name.

## 1.11.0
- Added an inline changelog preview (expands in place, no page navigation), fetched from each
  repo's `CHANGELOG.md` directly rather than relying on updates.xml's often-missing or
  wrongly-tagged changelog field.

## 1.10.0
- Added "Update All" - updates every extension currently showing "Update available" in one
  click, with a confirm dialog and a results summary.

## 1.9.0 - 1.9.1
- Added an Enabled/Disabled toggle for installed rows, matching Joomla's own Extensions:
  Manage screen, later restyled as a more visible colored button.

## 1.8.0 - 1.8.5
- Extracted `CompatibilityEvaluator` out of `RepoHelper` as a first step toward a smaller,
  more testable codebase. Added an explicit minimum PHP/Joomla version check on install,
  atomic (temp-file-then-rename) cache writes, a size cap and `LIBXML_NONET` on XML parsing,
  the missing LICENSE.txt, and some template accessibility/responsiveness cosmetics.

## 1.7.0 - 1.7.3
- Parallelized the updates.xml fetch across all tracked repos (curl_multi) instead of one
  request at a time, added pagination to GitHub topic discovery, fixed a silent
  "installed but not up to date" misclassification when `manifest_cache` is missing/corrupted,
  and replaced two deprecated `Factory` static calls.

## 1.6.0 - 1.6.1
- Fixed a silent stale-cache fallback not being reflected in the UI at all (added a proper
  "stale" banner with a timestamp), and fixed unrelated messages from elsewhere in the request
  occasionally getting misread as install/update errors.

## 1.5.0 - 1.5.5
- Added PHP-minimum-version checking (beyond just the Joomla-version match), a GitHub-only
  download-URL allowlist (an SSRF boundary), URL validation for the changelog link (an XSS
  fix), a stricter server-side check before allowing Update, fixed Update calling `install()`
  instead of the real `update()` method, and added sha384/sha512 checksum support alongside
  sha256.

## 1.4.0
- Fixed a repo whose Joomla-6-native build lives in a separate `updates.xml` under a subfolder
  (e.g. `joomla6/`) always showing "Not compatible", by trying known subfolder paths when the
  primary one doesn't match.

## 1.3.0 - 1.3.6
- Added Uninstall. Fixed inconsistent alphabetical sorting across mixed FG naming styles, fixed
  a Joomla-6 fatal error from a removed core class
  (`Joomla\CMS\Filesystem\Folder` -> `Joomla\Filesystem\Folder`), and changed row grouping to
  put all installed extensions first.

## 1.2.0 - 1.2.1
- Removed the manual repository list entirely - GitHub-topic discovery is now the only source.
  Fixed the Options page toolbar showing a raw language key instead of a title.

## 1.1.0 - 1.1.1
- Added GitHub-topic-based auto-discovery: tag a repo on GitHub and it's tracked automatically,
  no reinstall needed.

## 1.0.9 - 1.0.15
- A long stretch of install/fetch reliability fixes: a broken regex in URL normalization, a
  subform field-nesting bug that made the configured repo list silently disappear, better error
  messages for failed fetches, replaced Joomla's own HTTP client with direct curl (it was
  returning empty bodies on this host), and separated "Not compatible" (a normal, expected
  state) from a real fetch/parse "Error".

## 1.0.5 - 1.0.8
- Fixed the actual structural causes behind "Component not found" and a silently-broken
  install: a wrapped top-level folder in the ZIP that Joomla didn't reliably auto-descend into,
  and `<files>` needing to be nested inside `<administration>` rather than a sibling of it.

## 1.0.1 - 1.0.4
- Fixed several install-time failures in turn: a namespace tag duplicating `\Administrator`
  (breaking autoloading and silently falling back to a 404), a missing `Dispatcher.php`, and a
  `<languages>` tag that should have been folded into `<files>` instead.

## 1.0.0
- Initial release. Admin-only overview of extensions under a configurable set of GitHub repos,
  reading each repo's existing `updates.xml` to classify each as Not installed / Installed /
  Update available, with Install and Update actions via Joomla's own core installer.
