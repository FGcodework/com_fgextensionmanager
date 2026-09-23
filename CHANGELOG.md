# Changelog

## 1.25.6
- Still the diagnostic build. Realized why the previous self-update test (downgrade to 1.25.0,
  then Update All) showed no diagnostic output at all: self-updating overwrites files on disk,
  but the request that TRIGGERS the update keeps running the code that was already loaded into
  memory at the START of that request - the OLD 1.25.0 InstallHelper.php (without any
  diagnostics), not the new one being installed. Two plugin-only updates DID show diagnostics
  and succeeded, both times with the database correctly set - the failure has only ever
  actually been observed updating com_fgextensionmanager itself (always type=component),
  never a plugin. Testing this needs self to already be ON a diagnostic build (1.25.5+) so an
  update FROM here (to this build) actually runs the new code during the update.
  Added the specific check for this theory: whether `Factory::getContainer()->has(...)` differs
  between `ComponentAdapter` and `PluginAdapter` - `getAdapter()`'s own code
  (`Installer.php:2492`) skips `getDatabase()` entirely when the adapter class is found in the
  container, and only reaches the failing `getDatabase()` call in the fallback path when it
  isn't - if that differs by type, it would fully explain every observation so far.

## 1.25.5
- TEMPORARY DIAGNOSTIC BUILD, for tracking down "Database not set in Joomla\CMS\Installer\
  Installer" on Joomla 6, which persisted through both 1.23.0's approach (`new Installer()`)
  and 1.25.4's revert to `Installer::getInstance()`. Static review of his own uploaded
  `Installer.php`/`DatabaseAwareTrait.php` couldn't explain the failure - `getInstance()`'s own
  code calls `setDatabase()` immediately after construction, and the whole call chain
  (`update()` -> `setupInstall()` -> `getAdapter()` -> `getDatabase()`) runs on that same
  object with no re-instantiation visible in the stack trace he provided. Added: a reflection
  check of the `databaseAwareTraitDatabase` property's actual value right before update()/
  install() runs (both as an on-page warning and logged via `Log::add()`, since an uncaught
  exception afterward might bypass normal message rendering), and a try/catch around update()/
  install() itself to surface the exact exception class, message, file and line as a clean
  error message plus a full stack trace in the log, instead of a raw fatal error page. No
  functional change beyond this - purely to get a definitive answer instead of guessing again.

## 1.25.4
- **Reverted 1.23.0's `new Installer()` change back to `Installer::getInstance()`.** 1.25.3's
  `setDatabase()` fix didn't actually work in practice - his own stack trace on Joomla 6 showed
  `getDatabase()` still failing, deep inside `getAdapter()` (called from `setupInstall()`,
  called from `update()`), even with `setDatabase()` called right after construction. Something
  about a manually-constructed `Installer` still isn't properly wired up internally by the time
  execution reaches that code path, and two failed attempts on his live site is enough guessing
  in this direction for now. `getInstance()` is the one actually confirmed reliable - this
  error never happened before 1.23.0 in the first place - so reliability wins here over
  `joomla-cms#41087`'s narrower stale-state risk (which only matters when `updateAll()` mixes a
  plugin and a package update in the same request).

## 1.25.3
- **Fixed "Database not set in Joomla\CMS\Installer\Installer" on Joomla 6** (broke Update
  All / Install / Uninstall entirely there since 1.23.0's `new Installer()` fix for the
  stale-state bug). Confirmed as a separate, genuine Joomla 6.0 core regression
  (joomla-cms#45653, fixed upstream by #45670): "in J5.3, setDatabase was performed in the
  Adapter construct function. That's missing in the new [6.0] Installer construct function."
  A manually-created `Installer` instance on Joomla 6 needs `setDatabase()` called explicitly -
  added right after `new Installer()` in both `installFromUrl()` and `uninstallExtension()`.
  Confirmed `setDatabase()` has existed on `Installer` since Joomla 4.2, so this is safe (just
  redundant, not harmful) on Joomla 5 too - no version-conditional logic needed.

## 1.25.2
- Replaced the native browser `confirm()` for "Update All" with `JoomlaDialog.confirm()` -
  Joomla's own dialog web component (confirmed in the official docs as a purpose-built
  replacement for alert()/confirm()), styled consistently with the admin UI instead of a
  native OS dialog box docked at the top of the viewport. `extensions.js` now loads as an ES
  module (`type: 'module'`, with `joomla.dialog` declared as a dependency so Joomla loads that
  asset first) to support `import JoomlaDialog from 'joomla.dialog'`. Verified the full
  Promise-based flow in a real DOM: confirming submits the task correctly, matching the
  previous synchronous confirm()'s behaviour.

## 1.25.1
- Fixed the extensions list visually "sinking" into the page background since the CSS Grid
  rewrite (1.22.0). Root cause: a real `<table>` gets a background/border for free from base
  template CSS that targets the `<table>` tag itself, but our `[role="table"]` div structure
  isn't an actual `<table>` element, so none of that applied automatically. Added explicit
  card-style framing (background, border, rounded corners) to `#fgem-table-wrapper`, matching
  the self card's own `.card` styling above it.

## 1.25.0
- Seven UX-detail fixes:
  1. Filter placeholder said "by name" but also matches technical id and repository - text
     now says so.
  2. Added state filter chips (All / Update available / Installed / Not installed /
     Incompatible, each with a live count, zero-count states hidden) above the text filter -
     combines with it (both apply together) rather than replacing it.
  3. The self card never showed its own error/incompatible message, unlike the table - added,
     matching the table's exact treatment (same colour logic, same condition).
  4. Removed `COM_FGEXTENSIONMANAGER_UP_TO_DATE` - confirmed genuinely unused since 1.13.1
     removed the "Up to date" text it was for (a *different* key,
     `..._ERROR_ALREADY_UP_TO_DATE`, is still in real use).
  5. `creationDate` was still 1.0.0's date after two dozen releases - updated.
  6. Moved the inline `<style>` block to `media/css/extensions.css`, registered via
     `WebAssetManager::registerAndUseStyle()` - same CSP reasoning as 1.24.0's JS move, plus an
     inline block can't be touched by a Joomla template override the way an external stylesheet
     can.
  7. On mobile, `.table-responsive`'s `overflow-x: auto` was still active alongside the
     card-stacking layout, even though stacked cards have nothing to scroll horizontally for -
     disabled specifically below the card-stacking breakpoint, left alone above it where it's
     still a reasonable safety net for the percentage-column grid.

## 1.24.0
- Four security-focused fixes from a short follow-up review:
  1. **Moved all JS out of inline `<script>`/`oninput=` into an external file** (CSP-hostile
     before - a Content-Security-Policy without 'unsafe-inline' blocks both). New
     `media/js/extensions.js`, registered via `WebAssetManager::registerAndUseScript()` and fed
     dynamic data via `Document::addScriptOptions()` (the current, non-deprecated way to pass
     PHP data to JS) instead of inline PHP-to-JS interpolation - the documented Joomla 5/6 way.
     Note while implementing: the official manual's own example URI
     (`'com_example/myjs1.js'`) deliberately omits the `js/` subfolder segment even though the
     file installs to `media/com_example/js/` - WebAssetManager looks there automatically for
     script assets. Caught this by checking the docs before finalizing, having initially
     "corrected" it to include `js/` based on our own `<files>` folder convention, which would
     have been wrong here.
  2. **Replaced the global `Joomla.submitbutton` override with scoped click listeners** on our
     two specific toolbar buttons. The override itself was fine in isolation, but as a global,
     shared function it's a last-write-wins hazard if any other script on the same page also
     overrides it. Attaching capturing-phase click listeners directly to our own buttons
     (found by icon class) intercepts the click before the component's own handler runs,
     without touching anything global. Verified directly in a real DOM: the internal
     (bubbling-phase) handler never fires, and both the confirmed and cancelled paths submit
     the right task or nothing at all, respectively.
  3. **Self-update ordering in `updateAll()`**: self-updating overwrites the very files the
     current request is executing from. Sorted self to always run last in the batch, and now
     stop the loop immediately after updating it rather than continuing - a small, deliberate
     safety margin against exactly this kind of self-modifying-code risk, even beyond what the
     ordering alone already guarantees.
  4. Added an explicit comment on `SELF_OWNER_REPO` for anyone forking this component - left as
     FGcodework/com_fgextensionmanager, a fork would keep tracking and self-updating from the
     original upstream instead of itself.

## 1.23.0
- Worked through a 12-point analysis (A-L), verifying each claim directly against the code or an
  authoritative source before fixing - two turned out to be independently confirmed via
  Joomla's own issue tracker and source code, not just plausible-sounding theory:
  - **A (critical)**: `updateAll()` looping `Installer::getInstance()` - confirmed via
    joomla-cms#41087 (a Joomla core maintainer's own diagnosis of this exact bug pattern: the
    singleton leaks `manifestClass` between extensions when looping updates). Switched to
    `new Installer()` in both `installFromUrl()` and `uninstallExtension()`, matching core's
    own recommended fix ("just like PackageAdapter does").
  - **B**: `setEnabled()` (toggle) never cleared the `_system`/`com_plugins`/etc. cache groups
    that install/update/uninstall already did - a just-disabled plugin stayed "on" from the
    cache's point of view. Fixed.
  - **C**: a missing CHANGELOG.md (404) was still triggering the "stale data" banner via
    `fallbackToStaleCache()` (which always calls `noteStale()`) whenever a leftover cache file
    existed from before the file was removed - despite a comment saying otherwise. Now reads
    any leftover cache quietly, without flagging staleness, matching the comment's actual intent.
  - **D**: our own `updates.xml` had no checksum, so the sha256/384/512-verification code we
    built never actually got exercised by our own release. Added (see below).
  - **E**: `InstalledHelper::find()` ran one query per catalog item - 13 queries for his current
    12 repos + self. Replaced with `findBatch()`: one query for everyone, matched back to each
    entry by (type, element, folder/client_id). Verified the batch-matching logic directly
    against mixed types (plugin/component/package, installed and not).
  - **F**: `targetplatform`'s regex, from a remote updates.xml, was interpolated into
    `preg_match()` with no validation - a genuine ReDoS surface. Added a length cap and
    temporarily lower `pcre.backtrack_limit`/`recursion_limit` around just this evaluation.
    Verified: a classic catastrophic-backtracking pattern now resolves in ~0.07ms instead of
    hanging, real patterns are unaffected.
  - **G**: confirmed directly in Joomla core's actual source for
    `InstallerHelper::downloadPackage()` - on a redirect it recursively calls itself with the
    raw `Location` header, no host re-validation. This is a genuine gap in Joomla core itself
    (shared by core's own "Install from URL"), not something safely fixable here without
    reimplementing package downloading independently - documented clearly in the code instead
    of left unexplained.
  - **H**: `config.xml`'s two `hint` attributes were literal Slovak text, not translatable -
    confirmed `FormField::$translateHint` defaults to true (hints ARE translated), so this was
    a real, fixable i18n bug, not just cosmetic. Now proper language keys.
  - **I**: dark mode - the worst offender (a fixed coral tint on the self table row) was already
    gone since 1.22.0's CSS Grid rewrite switched striping to a neutral `rgba(0,0,0,0.03)`,
    which works in both modes. Added an explicit dark-mode override for the one remaining
    `.bg-light` (the changelog preview panel) as defense-in-depth, since Atum's own Bootstrap
    5.3 dark-mode remapping couldn't be verified live from here.
  - **J**: a component's "Settings" linked to `index.php?option=com_x` (its main admin view),
    not its actual Options screen - confirmed the correct pattern
    (`index.php?option=com_config&view=component&component=com_x`) directly from this very
    component's own Options toolbar link. Fixed - and since this also removes the
    self-referential-link reasoning that had the self card's Settings button left out entirely
    (1.16.0/1.16.1), added it back now that it points somewhere genuinely useful.
  - **K**: `RepoHelper` is at 1425 lines now (up from 1330), still no tests. Real, accumulating
    debt - not attempted in this same session given the size of everything else already
    touched here; the incremental-extraction approach from 1.8.0 (pulling out
    `CompatibilityEvaluator` first) is still the right direction for a focused follow-up.
  - **L**: uninstalling a package silently didn't mention that every extension bundled inside
    it gets removed too, not just the package entry - added a package-specific confirm message.

## 1.22.1
- Three precision fixes to `extractChangelogSince()` (1.11.0/1.17.0/1.21.1's accumulated
  accuracy notes):
  1. Headings with no parseable version (e.g. "Keep a Changelog"-style `## [Unreleased]`) are
     now skipped entirely, so an up-to-date extension shows its actual latest release instead
     of an unreleased section that isn't even shipped yet. Verified with a synthetic changelog
     containing an Unreleased section above real releases.
  2. Version matching now accepts two-segment versions ("1.21") as well as three ("1.21.0"),
     and uses `version_compare()` instead of strict string equality, so a heading using a
     shorter form than the installed version string still matches correctly. Verified directly.
  3. **Fixed the actual 1.11.0 limitation for subfolder builds** (the one that specifically
     affects `plg_content_fgautolightbox`, confirmed against the real repo): when a match comes
     from a fallback path like `joomla6/updates.xml`, the changelog now comes from that same
     subfolder's own `CHANGELOG.md` instead of always the root one - confirmed the two files
     are genuinely different changelogs for different builds (root: classic build, 1.3.2;
     `joomla6/`: native build, 2.3.9), and that the subfolder one now gets used correctly.
     `fetchFallbackPaths()` now also returns which path matched; when the subfolder has no
     `CHANGELOG.md` of its own, falls through to the root one rather than showing nothing.
  Left as documented, not fixed: two builds in the SAME changelog file sharing a bare version
  number (e.g. `plg_system_fgemailremover`'s classic vs joomla4-6 numbering) could still match
  the wrong build's entry if their numbers ever coincide - doesn't currently occur, and fixing
  it would need tracking which heading style belongs to which build, more complexity than this
  best-effort preview warrants right now.

## 1.22.0
- **Architecture change**: replaced the HTML `<table>` with a CSS Grid (`display: grid` +
  `display: contents` row wrappers), eliminating the root cause behind three separate releases
  (1.18.1-1.18.3): a spanning changelog-detail cell needed `table-layout: fixed`, a
  `display: block` override on `<tr>`/`<td>` to escape the table's column-width algorithm, and
  a JS function measuring the table's real pixel width to work around that override not
  resolving `width: 100%` reliably. A grid's spanning item (`grid-column: 1 / -1`) does this
  natively - the column tracks are sized once, up front, from `grid-template-columns`, and no
  item's content (spanning or not) can affect that. None of table-layout:fixed, the
  display:block hack, or the width-measuring JS are needed anymore, all removed.
  This also fixes 1.21.3's filter bug at the root instead of patching around it - there's no
  `<tr>` losing an inline `display: block` override anymore, just a `display: contents` row
  wrapper that the filter now explicitly restores to `'contents'` (not `''`) for the same
  reason 1.21.3 already identified, verified directly this time against the actual grid
  markup rather than the old table structure.
  Striping is computed per-cell in PHP (same value applied to all cells in a row) rather than
  via CSS - a `display: contents` row generates no box of its own to stripe, and a CSS
  structural selector (`nth-child` or similar) would hit the exact same drift the hidden `<tr>`
  caused in 1.13.4, since the changelog detail row still only ever contributes one cell.
  The mobile card-stacking CSS (1.20.0) is rewritten for the grid structure but keeps the same
  approach: `data-label` + `::before` for stacked labels below the breakpoint.
  Could not verify this visually in a real browser from here - the DOM structure and the
  filter's `display: contents` toggling were verified directly (a real DOM confirms the parent/
  detail row pairing and display values are exactly as intended), but actual grid rendering
  and column alignment need a live check after upload.

## 1.21.4
- Cleaned up dead code left over from 1.16.0 pulling the self row out of the main table: three
  `$item->is_self` checks inside the table loop (the coral row tint, the "This extension"
  badge, and the `!$item->is_self` uninstall-button guard) could never be true anymore, since
  self is removed from `$this->items` before that loop ever runs - confirmed by grepping for
  every remaining reference. Removed all three; no behaviour change, since they were
  unreachable.
- Documenting something that was silently dropped rather than decided: the self card has never
  had a Settings button, despite 1.16.0's changelog listing "Update/Settings/Changelog" as
  carried over from the table row. Leaving it out is the right call, just never actually said -
  for this component specifically, `buildManageUrl()` for a component resolves to
  `index.php?option=com_fgextensionmanager`, which is this very page, so a "Settings" link on
  the self card would just point back at itself. Any real configuration this component has
  lives in Options, already reachable from the toolbar (1.19.0).

## 1.21.3
- Fixed the filter (1.18.0) silently undoing the column-shift fix (1.18.2/1.18.3) after
  filtering, even just typing then clearing the field: `fgemFilterRows()` reset a matched
  changelog detail row's `display` to `''` (empty string) to "show" it again, which clears the
  inline `display: block` entirely rather than restoring it - falling back to a `<tr>`'s CSS
  default of `table-row`, exactly the layout that was escaped in the first place to keep
  expanding a changelog from resizing the other columns. Verified directly in a real DOM: the
  detail row's `display` came back empty (not `block`) after a hide/show cycle with the old
  code. Fixed by using the explicit value `'block'` instead of `''` specifically for the detail
  row (the main row is unaffected - it has no inline `display` of its own to lose, so `''`
  correctly falls back to the table's normal row display there).

## 1.21.2
- Fixed self-uninstall/self-disable being UI-only, not enforced server-side (medium severity):
  `uninstall()` and `toggle()` never checked `is_self` - the Uninstall button and Enable/Disable
  toggle are hidden for FG Extension Manager's own row in the UI, but a direct POST with a
  valid token and `key=FGcodework/com_fgextensionmanager` would have gone through anyway.
  Added an explicit `is_self` refusal to both controller actions, matching the same
  defense-in-depth principle already applied to `protected` extensions (never trust
  client-side hiding alone for anything safety-relevant). `install()` doesn't need this - self
  is always installed, that state is unreachable for it - and `update()` intentionally still
  allows self-updating.

## 1.21.1
- **Critical regression from 1.20.3**: the hidden `task` field added to fix "Update All" broke
  every per-row action button (Install/Update/Toggle/Uninstall) instead. Those buttons are
  `<button name="task" value="...">` elements, and with the hidden field ALSO named "task" and
  positioned later in the DOM (near the end of the form), a real click sent BOTH values -
  verified directly with `FormData(form, submitter)` (the actual browser entry-list
  construction algorithm): `task=extensions.install` from the clicked button, immediately
  followed by an empty `task=` from the hidden field. PHP keeps the LAST value for a duplicate
  POST key, so `$_POST['task']` ended up empty - the button silently did nothing beyond
  reloading the page.
  Fixed by making the hidden field `disabled` by default (disabled fields are excluded from
  submission entirely, so it no longer competes with the row buttons at all), and having the
  toolbar's JS enable it, set the value, and submit only for that one programmatic submission.
  Verified both paths directly with `FormData`: a row-button click now sends only that button's
  task value, and the toolbar submit sends only the hidden field's value - no collision either
  way.

## 1.21.0
- Confirmed working: "Update All" from the native toolbar (1.20.1-1.20.3's fixes together).
  Removed the temporary coral debug color from the button (1.20.2) now that it's no longer
  needed - purely a cleanup, no functional change.

## 1.20.3
- Found the actual root cause of "Update All" doing nothing after confirming (1.20.1/1.20.2
  weren't wrong about the mechanism, just missing a piece): the form never had a dedicated
  hidden `task` field for `Joomla.submitform()` to write into. It also has several per-row
  `<button name="task" value="...">` elements (Install/Update/Uninstall - intentional, each
  contributes its own value only when directly clicked). With multiple elements sharing the
  name "task", `form.task` doesn't reliably resolve to a single settable element - confirmed
  empirically in a real DOM (jsdom): `form.task` came back `undefined`, and `form.elements.task`
  came back an ambiguous `RadioNodeList`, whose `.value` setter is only meaningful for radio
  inputs and is a silent no-op otherwise. That's exactly why confirming did nothing: the task
  value never actually reached the hidden field before `form.submit()`.
  Fixed by adding the missing hidden field with a unique `id` (`fgem-task-field`), and having
  the toolbar's `Joomla.submitbutton` override write into it directly via `getElementById`
  and call `form.submit()` itself, bypassing the ambiguous `form.task` reference entirely.
  Verified the fix directly in a real DOM: the hidden field receives the task value and
  `form.submit()` fires correctly.

## 1.20.2
- TEMPORARY, for testing 1.20.1's fix: colors the Update All toolbar button coral, found by
  its icon (`icon-loop`) rather than guessing `<joomla-toolbar-button>`'s exact internals - a
  quick way to visually confirm this version is actually the one running, no functional change.
  Safe to strip out again once 1.20.1's actual fix is confirmed working.

## 1.20.1
- Fixed "Update All" not working after 1.19.1's move to the native toolbar - the
  `appendButton('Confirm', ...)` mechanism (copied from documentation about
  `ToolbarHelper::deleteList()`'s internals, never actually tested live) turned out not to
  work in practice. Switched to the same proven `ToolbarHelper::custom()` (Standard button)
  mechanism Refresh already uses successfully, and handle the confirm dialog with a small JS
  wrapper around `Joomla.submitbutton()` instead - documented as the supported override point
  for exactly this (`<joomla-toolbar-button>` is "a thin wrapper around Joomla.submitbutton()
  made to be overridden by extension developers"). Captures the ORIGINAL function before
  overriding and calls that (not `Joomla.submitbutton()` again), avoiding the classic "too much
  recursion" bug several other Joomla extensions have hit doing this incorrectly. Verified the
  wrapper logic directly in Node: intercepts `extensions.updateAll` with the confirm dialog,
  lets every other task (e.g. `extensions.refresh`) through untouched.

## 1.20.0
- Added responsive card-stacking for the extensions table on narrow screens (max-width:
  767.98px), instead of relying only on horizontal scroll - the same general technique as his
  own `plg_system_fgresponsivetables`, built independently here (couldn't verify that plugin's
  admin-area scope or its selector convention via search, so this doesn't depend on it being
  installed or active). Below the breakpoint, each row becomes a bordered card with the
  Extension name/description as its natural title and every other value shown with a bold
  label (`data-label` attribute + CSS `::before`, the standard technique for this). Scoped
  entirely to `#fgem-table` via CSS, so nothing else on the page is affected.

## 1.19.1
- Moved "Update All" into the native Joomla toolbar too, next to Refresh - using the same
  underlying mechanism `ToolbarHelper::deleteList()` uses for its own confirm dialog
  (`Toolbar::appendButton('Confirm', ...)`, called directly with our own icon/message instead
  of deleteList()'s hardcoded delete semantics), so the confirm-before-updating behaviour is
  preserved. Only shown when there's actually something to update, same as before.
  Combined the "N extension(s) tracked · last checked ..." line and the filter field onto one
  row (filter left, text right) instead of two stacked rows.

## 1.19.0
- Moved Refresh into the native Joomla toolbar (top, next to Options), in the same style and
  via the same mechanism (`ToolbarHelper::custom()`) instead of a plain HTML button sitting
  below the page title. Submits the same `adminForm` with `task=extensions.refresh`, so the
  controller side is unchanged - verified the CSRF token and permission check
  (`checkToken()`/`hasAccess()`) both still apply the same way through the standard Joomla
  toolbar submit path. Removed the now-duplicate HTML button from the page body.

## 1.18.4
- Rebalanced column widths using the real rendered HTML as a guide: Extension (28% -> 40%) was
  wrapping its description to two lines while Status/Enabled/Installed/Available - each just a
  short badge or version number - sat mostly empty at 8-10% each. New split: Extension 40%,
  Status 9%, Enabled 9%, Installed 7%, Available 7%, Repository 16%, Actions 12% (still sums
  to 100%).

## 1.18.3
- Got the actual rendered HTML from his site this time, which showed the real issue: even with
  `display: block` (1.18.2), a `<tr>`/`<td>` overridden that way still doesn't reliably resolve
  `width: 100%` against the table's real width in every browser - percentage-width containing-
  block rules get ambiguous once an element that's normally table-row/table-cell has its
  display overridden, especially still carrying a `colspan` attribute from its original markup.
  Replaced the CSS-only approach with a small JS function that measures the table's actual
  rendered pixel width (`table.offsetWidth`) and applies it directly as an explicit pixel width
  to each detail row's cell - sidesteps the whole percentage-width ambiguity by using a hard
  measured value instead. Runs on page load, on window resize, and right before any collapse
  opens (in case a scrollbar appearing/disappearing changed the table's width). The self-card's
  own changelog is unaffected either way - it was never inside the table, just a plain `<div>`
  in a card, already full width by default.

## 1.18.2
- 1.18.1's fix (explicit `width: 100%` on the colspanned cell) still wasn't reliably reaching
  full width in practice. Took a more decisive approach: the changelog detail row's `<tr>` and
  `<td>` now use `display: block` instead of their default table-row/table-cell display,
  removing them from the table's column-width layout algorithm entirely - the same technique
  DataTables' own "responsive detail row" feature uses for exactly this case. It can no longer
  be constrained by (or itself influence) any column width, colspan-sum calculation included.
  Still could not verify visually in an actual browser from here - please check again after
  upload.

## 1.18.1
- Fixed the table columns shifting left when a changelog row expanded - added
  `table-layout: fixed` with explicit column width percentages on the `<th>` elements, so
  column widths come only from the header row and can never be affected by any row's content,
  expanded or not.
  That fix introduced a side effect: the expanded changelog box itself then rendered narrower
  than the full table width in some cases (table-layout:fixed's colspan-width calculation
  apparently isn't fully consistent across browsers). Fixed by setting an explicit
  `width: 100%` directly on the colspanned cell and its inner wrapper divs, instead of relying
  on the colspan-to-column-sum calculation. Also added `overflow-wrap: break-word` as a
  defensive measure against any single long unbroken token forcing overflow.
  Could not verify either fix visually in an actual browser from here - please confirm after
  upload.

## 1.18.0
- **Fixed a real regression from 1.17.0**: the changelog-since-installed-version feature
  (1.17.0) returned nothing at all for an installed extension that was already up to date,
  instead of falling back to showing the latest entry like every version before it did - so
  the Changelog button silently disappeared for most installed extensions (anything NOT
  showing "Update available"). `extractChangelogSince()` now always falls back to the single
  latest entry when there's nothing newer to show, matching the pre-1.17.0 behaviour for that
  case while keeping 1.17.0's actual improvement (showing everything since your version when
  you genuinely are behind). Verified against the real `plg_system_fgemailremover` changelog:
  the exact broken case (installed = latest version) now correctly returns the latest entry
  instead of null.
- Added a filter field above the extensions table (name/technical id/repository, plain
  client-side JS, no extra request) - useful once the tracked list grows past a quick scan.
  The hidden changelog-preview detail row now carries a marker class so it hides/shows
  together with its parent row instead of staying orphaned when filtered out.
- Added an always-visible "last checked" timestamp next to the extension count, distinct from
  the stale-data warning banner (which only appears on a genuine fetch failure). Tracks the
  OLDEST "as of" time among every source behind the current view - a still-fresh cache hit, a
  fetch that just happened, or a stale fallback - the same conservative-timestamp reasoning
  the stale banner already used, just always shown rather than only on failure.

## 1.17.0
- Changelog preview now shows everything that changed since your installed version, not just
  the single latest entry - if you've been away for a while and are several versions behind,
  you see the full picture instead of only the most recent line. `extractLatestChangelogEntry()`
  replaced with `extractChangelogSince()`: splits the file into all "## " sections, finds the
  one matching the installed version, and includes every section newer than it. Falls back to
  just the latest entry when not installed or the installed version isn't found in the
  changelog, and returns nothing at all when already on the newest version.
  Had to fix a real regex bug caught while testing this against the actual (60-section)
  `plg_system_fgemailremover` changelog: the heading-capture group needs `[^\n]*` explicitly -
  using `.` there under the `/s` (dotall) modifier let it swallow the entire rest of the file
  instead of just one line, collapsing 60 sections down to 1 in an early version of this code.
  Verified the corrected version against the real file: 60 sections parsed correctly, and all
  four scenarios (several versions behind, already up to date, not installed, installed
  version not found) produce the right output.
  Noted limitation: a changelog interleaving two builds with independent version numbers
  (exactly this repo's own format) could match the wrong build's entry if their version
  numbers ever coincide - not an issue with his current numbering, not fixed here.

## 1.16.2
- Moved the "N extension(s) tracked" count + Update All/Refresh toolbar below the self card
  instead of above it - the count only ever refers to the topic-discovered table, so it reads
  more sensibly sitting right above that table rather than above everything.

## 1.16.1
- Removed the Enable/Disable toggle from the self card too - same reasoning as Uninstall
  already being hidden: disabling the extension that's actively managing this very screen is
  a footgun, not a normal action. Update and Settings/Changelog remain.

## 1.16.0
- Pulled the self row out of the main table entirely, per feedback that a special row within
  the same table (border, then background tint) still felt like it was fighting the list
  rather than being genuinely separate. Now rendered as its own compact card above the
  topic-discovered extensions table, with the same Update/Enable-Disable/Settings/Changelog
  controls (still no Uninstall) but nothing shared with the table's row markup, sorting, or
  striping. The table below now only ever contains topic-discovered extensions.
  "Update All"'s count still includes a pending self-update even though it's no longer in the
  table (the server-side action already updated self regardless - this just makes the
  displayed count match what actually happens).

## 1.15.1
- Removed the border treatment on the self row (1.15.0) - replaced with just a subtle
  background tint (a light coral wash, matching the FG brand color, instead of Bootstrap's
  blue "primary"). The "This extension" badge stays as the main indicator.

## 1.15.0
- FG Extension Manager now tracks itself, always - `buildRepoRows()` injects its own repo
  (`FGcodework/com_fgextensionmanager`) into the tracked list unconditionally, so it shows up
  whether or not it's been tagged with the discovery topic on GitHub (if it IS tagged,
  discovery's own entry wins, since it can supply a more accurate `default_branch`).
  Pinned to the top of the list regardless of its state, with a distinct visual treatment
  (tinted background, coral-blue left border, thicker bottom border for separation, a "This
  extension" badge) instead of blending into the normal alternating rows.
  Uninstall is never shown for this row - self-uninstall is a real footgun (removing your own
  files/DB row while actively executing) that self-update doesn't share, so Update/Settings
  stay available but Uninstall doesn't. Verified the self-pinning sort logic directly: sorts
  first regardless of its actual state (not_installed/installed/update_available/etc.), ahead
  of the normal state-based grouping.

## 1.14.0
- Added a "Settings" button, inspired by KREM, shown for installed rows where Joomla has one
  well-defined target: a plugin (links directly to its `com_plugins` edit screen, keyed by
  `extension_id` - the actual field name `com_plugins` uses even though plugins live in
  `#__extensions`) or a component (links to its own default admin screen). Deliberately not
  shown for module/package - a module type has no single settings screen (could have zero,
  one, or several published instances), and a package bundles other extensions rather than
  having settings of its own. Verified the URL construction against real examples
  (`plg_x` -> `com_plugins` edit link, `com_fgreports` -> its own screen) and confirmed
  module/package correctly return no button at all instead of a broken link.
  Placed between Uninstall and Changelog in the existing stacked Actions layout - no layout
  change needed.

## 1.13.4
- Found the actual cause of the uneven row shading: `table-striped` uses CSS `nth-child` to
  alternate row colors, which counts EVERY `<tr>` in the table's DOM - including the hidden
  changelog-preview detail row added after any row that has one (1.11.0). A row with a preview
  shifts the parity for every row after it, so the banding drifted out of sync exactly where
  changelogs existed - not related to install state (1.13.2's theory) or anything about
  `table-striped` itself being wrong (1.13.3's revert). Replaced CSS-based striping with manual
  striping keyed to our own loop index (which only counts visible rows, since the hidden detail
  markup isn't a separate `$this->items` entry) - immune to how many hidden rows are
  interspersed.

## 1.13.3
- Reverted 1.13.2's removal of `table-striped`. It was assumed to be confusing (unrelated to
  install state), but it was actually just helping scan many rows - not the problem worth
  solving. Back to alternating row shading.

## 1.13.2
- Fixed two issues found on `pkg_fgbackendlangswitcher`:
  1. Removed `table-striped` - it colors rows by position in the table, completely unrelated
     to install state, so a "Not installed" row could end up shaded the same as an "Installed"
     one right above it, looking like it meant something when it didn't.
  2. The Changelog link for that package was pointing at a guessed `changelog_full_url`
     (`.../blob/master/CHANGELOG.md`) even though the earlier CHANGELOG.md fetch had already
     come back empty - confirmed the file genuinely doesn't exist there (404 on both `master`
     and `main`, checked directly). Dropped that guessing fallback entirely: the Changelog
     control now only appears when there's a real preview, or a validated `changelogurl` from
     updates.xml - nothing when neither exists, instead of a link that 404s.

## 1.13.1
- Actions column narrowed: dropped the "Up to date" text entirely (a fresh Update button
  already appears when there's actually something to do, so the text added nothing), and moved
  the Changelog link onto its own line below Install/Update/Uninstall instead of sitting beside
  them - trades a little vertical space for meaningfully less horizontal width.

## 1.13.0
- Styled the Extension column closer to KREM's layout: bold blue-tinted name, then the full
  technical identifier ("plg_system_fgemailremover", "com_fgreports", "pkg_fgbackendlangswitcher")
  in a monospace `<code>` line, then the description - matching KREM's 3-line pattern instead
  of 4 (dropped the separate "System plugin" type line, since the technical id's own prefix
  already conveys that). Added `buildTechnicalId()`: for plugins reconstructs the full
  "plg_<folder>_<element>" form (their #__extensions element is bare, per the established
  convention), for component/module/package uses the element as-is (already fully prefixed in
  his real feeds - confirmed against `com_fgreports` and `pkg_fgbackendlangswitcher`).

## 1.12.0
- Three display cleanups:
  1. Repository column now shows just the repo name ("plg_system_fgemailremover" instead of
     "FGcodework/plg_system_fgemailremover") - full owner/repo still shown on hover and still
     used for the link itself.
  2. Added `humanizeTargetPlatform()`: converts a raw targetplatform regex into a short,
     readable range ("3\.[0-9]+\.[0-9]+" -> "3.x", "[456]\.[0-9]+\.[0-9]+" -> "4.x, 5.x, 6.x")
     for the "Not compatible" message, instead of showing the raw regex. Covers every pattern
     style actually seen across his real feeds (verified by extracting and running the actual
     method from the real file, not a reimplementation); falls back to the raw regex only for
     something more complex (e.g. an explicit alternation), which doesn't currently occur in
     any of his feeds.
  3. Added a short description as a third line under the extension name, from updates.xml's
     `<description>` tag (confirmed present in his real feeds), truncated to 140 characters.

## 1.11.0
- Added an inline changelog preview: clicking "Changelog" now expands the latest entry
  directly in the row (Bootstrap collapse, no page navigation) instead of only linking out.
  Deliberately does NOT use updates.xml's `<changelogurl>` field - checked his real feeds and
  found it either missing entirely, or present under the wrong tag name
  (`<maintainerchangelogurl>`) pointing at a raw `.md` file; and per Joomla's own documentation,
  a correctly-named `<changelogurl>` is supposed to point to Joomla's dedicated changelog.xml
  schema anyway, not a plain CHANGELOG.md - so even a fixed tag name wouldn't have worked.
  Instead fetches `CHANGELOG.md` directly from each repo's root by convention (same parallel
  curl_multi batch approach as updates.xml), extracts the first "## " section as the latest
  entry, and always links to the real file on GitHub as a reliable fallback regardless of
  whatever updates.xml does or doesn't declare.
  Verified end-to-end against 5 real repos (0.45s for all 5, parallel): correctly extracts the
  latest entry across several different heading styles he's used
  ("## 1.13.1 (date)", "## joomla4-6/ vX.Y.Z - date", "## X.Y.Z" with no date, en-dash dates).
  Known limitation (documented, not fixed here): for a repo with a subfolder-build split like
  `plg_content_fgautolightbox`, the root CHANGELOG.md reflects the frozen classic build (1.3.2)
  rather than the actually-relevant joomla6/ build (2.3.9) - the same split already handled for
  updates.xml, just not yet for changelogs. The full-changelog link is unaffected either way.

## 1.10.0
- Added "Update All" - one button updates every extension currently showing "Update available"
  in a single click, with a confirm dialog naming the count. Shown next to Refresh, only when
  there's actually something to update.
  Deliberately does NOT enqueue a Joomla message after each individual update in the loop:
  `InstallHelper::installFromUrl()` clears the message queue at its own start (1.6.1), so
  enqueueing between calls would wipe out the previous extension's result before it's ever
  shown. Results are collected in plain local arrays instead and turned into at most two
  summary messages (succeeded / failed, each naming which extensions) once the whole batch is
  done. Verified the real `.ini` files parse correctly and `sprintf()` resolves the `%1$d`/
  `%2$s` placeholders as intended against the actual stored strings, not just a synthetic test.

## 1.9.1
- Made the Enabled/Disabled toggle more prominent: replaced the small icon-link with a full
  colored badge/button ("Enabled" green, "Disabled" red), matching the visual weight of the
  Status column instead of being easy to miss next to it.

## 1.9.0
- Added an Enabled/Disabled toggle, inspired by KREM (Kubik-Rubik Extension Manager) and
  matching Joomla's own Extensions: Manage screen - confirmed the underlying mechanism
  (`InstallerModelManage::publish()`, a direct update of `#__extensions.enabled`) via Joomla's
  own API docs before implementing it the same way.
  New "Enabled" column shows a clickable green check / red circle icon for installed rows
  (same icon classes Joomla's own admin templates use), backed by a new `extensions.toggle`
  task and `InstalledHelper::setEnabled()`. A protected extension (Joomla's own safeguard for
  critical extensions) shows a lock icon instead and can't be toggled, mirroring core's own
  refusal to let those be disabled.

## 1.8.5
- Three small template cosmetics: removed the unused `Session` import (confirmed - the CSRF
  token goes through `HTMLHelper::_('form.token')`, `Session::` is never actually called),
  added `scope="col"` to every table header cell (accessibility - screen readers can associate
  data cells with their header), and wrapped the table in `.table-responsive` so it scrolls
  horizontally instead of overflowing on narrow screens.

## 1.8.4
- Added the missing LICENSE.txt (standard GPL-2.0-or-later full text) that the manifest's
  `<license>` tag already referenced but the package never actually included. No functional
  effect on the running component - purely a packaging correctness fix for when this gets
  published to GitHub.

## 1.8.3
- Added an explicit minimum-version check (PHP 8.0.0, Joomla 5.0.0) so an install on too-old
  PHP/Joomla fails predictably with a clear message, instead of possibly failing later with a
  raw PHP fatal error on first page load.
  Important finding while implementing this: `Joomla\CMS\Installer\InstallerScriptTrait`
  (which provides this exact check for free via `$minimumPhp`/`$minimumJoomla` properties) only
  exists since Joomla 6 - confirmed absent from the official Joomla 5.4 manual's own version of
  this same example file. Using it would have fataled on install on Joomla 5, which this
  component explicitly supports (confirmed working on mechanizmysevcik.sk, J5.4.8). Wrote the
  version check manually instead (registered via `InstallerScriptInterface` in the existing
  `services/provider.php`, alongside the MVCFactory/Dispatcher registrations already there),
  using only APIs confirmed present in both the 5.4 and 6.1 versions of Joomla's own
  documented example.
  NEEDS CAREFUL TESTING: this changes the component's core bootstrap file for the first time
  since the dispatcher fix (1.0.5/1.0.3) that took several rounds to get right - please verify
  a normal install/update/uninstall still works before trusting this on a live site.

## 1.8.2
- Replaced all three `@file_put_contents()` cache writes with `atomicCacheWrite()`: write to a
  temp file in the same directory, then `rename()` into place. `rename()` is atomic on the same
  filesystem on every platform Joomla runs on, so a concurrent reader (another admin's page
  load, or the parallel curl_multi batch from 1.7.0 running in a different request at the same
  time) always sees either the complete old cache file or the complete new one, never a
  half-written one. Failures now log via Joomla's `Log` class (permissions, disk full,
  read-only filesystem, ...) instead of vanishing behind `@` with zero diagnostics - still
  non-fatal, a failed cache write just means the next request fetches live again. Verified the
  temp-file-then-rename mechanics directly: a normal write leaves no leftover `.tmp` files, and
  writing to a nonexistent directory fails cleanly (`false`, no fatal error) rather than
  crashing.
  Left cache *reads* as plain `@file_get_contents()` - the atomic write already fully closes
  the torn-read gap the review raised, and a read failure already falls through to a live
  re-fetch on its own.

## 1.8.1
- Added defense-in-depth hardening to `parseUpdatesXml()`: a 2 MB size cap rejected before any
  parsing is attempted (a real update feed is a few KB - verified against the real
  `plg_system_fgemailremover` feed at 2161 bytes, and a synthetic 5.1 MB feed correctly
  rejected outright), and `LIBXML_NONET` on the `simplexml_load_string()` call, which stops
  libxml from making its own network request while parsing a crafted external entity/XInclude
  in the XML - the same class of concern as the download_url SSRF allowlist (1.5.4), just at
  the parser level instead of the URL level.

## 1.8.0
- Extracted `CompatibilityEvaluator` out of `RepoHelper` (1026 -> 961 lines), per the review's
  suggestion to start with this piece specifically since PHP/Joomla/database compatibility
  rules are the part most likely to grow more cases over time, and it was already a fully pure
  function (no HTTP, filesystem, or database access) - just embedded in a class that does all
  of those things for other reasons. `evaluateEntries()`/`pickBestEntry()` became
  `CompatibilityEvaluator::evaluate()`/`::pickBest()`, with an optional explicit
  Joomla/PHP-version override (defaulting to the real `JVERSION`/`PHP_VERSION` as before) so it
  can be exercised without a live Joomla environment - verified with a standalone
  `_JEXEC`-defined script covering three real scenarios (matching build, Joomla-matches-but-
  PHP-too-old, no Joomla-version match at all) against no live site, only plain PHP.
  The rest of the suggested split (GitHubDiscoveryService, UpdateFeedClient, UpdateFeedParser,
  InstalledExtensionRepository, CatalogService) is left for later, as the review itself
  suggested doing incrementally rather than all at once.

## 1.7.3
- Replaced two confirmed-deprecated static `Factory` calls (verified against Joomla's own API
  docs, both explicitly "will be removed in 6.0" with an exact replacement given):
  `Factory::getDbo()` -> `Factory::getContainer()->get(DatabaseInterface::class)` in
  `InstalledHelper`, and the static `Factory::getLanguage()` -> `Factory::getApplication()->
  getLanguage()` in both `InstallHelper` methods (a GitHub issue titled exactly
  "Factory::getLanguage() is deprecated" confirmed this one specifically, distinct from the
  still-current, non-deprecated `$app->getLanguage()`).
  Correcting the analysis itself here: `Factory::getApplication()` is NOT deprecated - several
  of the official deprecation notices for OTHER methods recommend routing through
  `Factory::getApplication()->getX()` as the replacement, so it's the current blessed pattern,
  not something to also phase out. Also didn't pursue full constructor-injected DI throughout -
  a much larger architecture change the analysis itself flagged as "even better" rather than
  necessary, and out of proportion to what these static helper classes need.

## 1.7.2
- Fixed `installed_version` silently defaulting to `null` when `manifest_cache` is missing or
  corrupted, which made `state === 'installed'` (falsely "up to date") win over
  `'update_available'` in the comparison, since `null && ...` is always false. Added a
  file-based fallback (`InstalledHelper::versionFromManifestFile()`) that reads `<version>`
  directly from the installed extension's own manifest XML on disk when manifest_cache's
  version can't be read - implemented for `component` and `plugin` only, whose file-location
  convention is unambiguous (verified against this very component's own manifest path and
  Joomla core's `com_actionlogs`, and the standard `plugins/<folder>/<element>/<element>.xml`
  pattern); deliberately NOT implemented for `module`/`package`, since guessing their path
  convention risks silently reading the wrong file - those keep the existing
  manifest_cache-only behaviour. Verified the XML-reading logic handles a valid manifest,
  one with no `<version>` tag, a corrupted file, and a missing file, all without a fatal error.

## 1.7.1
- Added pagination to GitHub topic discovery (loops with `&page=N` while `total_count` exceeds
  what's been fetched so far, capped at 10 pages/1000 repos as a sanity limit). Low priority as
  noted - his account currently has 15 repos total, nowhere near the 100-per-page limit - but
  cheap to get right now rather than silently dropping anything past #100 later. Verified
  against the real account: correctly fetches all 15 in one page and stops (doesn't request a
  page 2 that would come back empty).

## 1.7.0
- Parallelized the primary updates.xml fetch across all repos using curl_multi (falls back to
  sequential if curl_multi_* somehow isn't available). Previously N repos on a cold cache meant
  1 + N fully sequential HTTP requests, so one slow/down host could delay every repo fetched
  after it by up to the configured timeout each - a real problem at `cache_minutes = 0` or on
  a network hiccup. `fetchEntriesWithFallback()` split into `fetchPrimaryBatch()` (parallel,
  all repos at once) and `fetchFallbackPaths()` (sequential, only for the typically-few repos
  whose primary path didn't already match - e.g. `plg_content_fgautolightbox`), since the
  fallback-path search is inherently adaptive per repo and not worth a second parallel batch
  for what's usually one or two repos.
  Verified end-to-end against 5 real repos (including `fgautolightbox`, which genuinely needs
  the fallback path): 0.27s total, correctly identified only that one repo as needing a second
  request, and every result matched the sequential version's output exactly.
- Deliberately not pursued now: a single central FG catalog JSON (1 request instead of 1+N).
  Good future direction if the repo count grows enough for even the parallel batch to matter,
  with topic discovery kept as the fallback - but adds a second thing to keep in sync with each
  release, and today's response time is already sub-second even for a cold cache.

## 1.6.1
- Fixed `getMessageQueue()` misreading unrelated messages as installer errors. Confirmed via
  Joomla's own API docs and a related core issue thread: `getMessageQueue($clear = false)`
  returns the WHOLE current queue and does NOT clear it unless explicitly asked - so (a) a
  pre-existing message from earlier in the request could get misattributed as an install/
  update/uninstall failure, and (b) our own read-without-clearing left those same messages
  sitting in the queue to be shown a second time by Joomla's own message renderer on the next
  page load (a duplicate-display bug the search turned up as a known, already-fixed pattern in
  Joomla core itself, fixed by clearing the queue at read time).
  Both `installFromUrl()` and `uninstallExtension()` now call `getMessageQueue(true)` once
  immediately before the operation (discarding whatever was already queued) and again after
  (reading AND clearing in one call), so only genuinely-new messages from that specific
  operation are ever considered, and none of them linger to reappear later.

## 1.6.0
- Fixed the silent stale-cache fallback: `RepoHelper::getCatalog()` now returns
  `{items, stale, stale_since}` instead of a bare array - `stale` is true if ANY repo's data
  came from an expired cache because a live GitHub fetch failed, `stale_since` is the OLDEST
  such cache's timestamp (the most conservative "as of" date when several sources are stale by
  different amounts). Verified the tracking logic directly with real cache files of different
  ages - correctly picks the oldest, and correctly reports nothing stale when every fetch
  actually succeeded.
  Refresh no longer claims "Refreshed the extension list from GitHub" when it silently fell
  back to cache - it now says which is true. The main list page also shows a warning banner
  with the cache date whenever any part of what's displayed is stale.
  This touched every `getCatalog()` caller: `ExtensionsModel::getItems()`,
  `ExtensionsController::refresh()/process()/uninstall()`, and `HtmlView::display()`.

## 1.5.5
- Added `sha384`/`sha512` support alongside `sha256`, parsed from updates.xml and verified with
  the strongest one present (sha512 > sha384 > sha256; the others are ignored if more than one
  is given). `InstallHelper::installFromUrl()` now takes a `$checksums` array instead of a
  single `$sha256` string. As the analysis itself noted, this doesn't add real protection
  against a compromised feed (the same feed that supplies a bad download URL could supply a
  matching bad checksum) - it's really just corruption-in-transit protection, extended to the
  stronger algorithms Joomla's own installer also supports. Verified the algorithm-preference
  logic directly (both present -> sha512 used and a deliberately-wrong sha256 alongside a
  correct sha512 correctly passes, since sha256 is never even checked).

## 1.5.4
- Added a GitHub-hosts allowlist for `download_url` (github.com, raw.githubusercontent.com,
  objects.githubusercontent.com, codeload.github.com). `InstallerHelper::downloadPackage()` is
  a server-side fetch - an SSRF boundary, not just an XSS one like changelog_url - so a
  compromised/malicious feed pointing it at an internal host is a real (if low-probability,
  given repos are scoped to his own GitHub account) risk that plain https:// alone doesn't
  close. An entry that fails the check is treated as if it had no download URL at all (skipped).
  Verified against the real `fgautolightbox` GitHub Releases URL (passes), plain `http://`
  (blocked), an SSRF-style internal IP (blocked), and a `github.com.evil.com` lookalike host
  (blocked - exact hostname match only, not a prefix/substring check).
  Didn't add the suggested "Download host: X" UI display - with the allowlist in place it can
  now only ever be a trusted GitHub host, so surfacing it would be redundant.

## 1.5.3
- Fixed a potential XSS via `changelog_url`: confirmed empirically that `htmlspecialchars()`
  passes a `javascript:` URI straight through unchanged (it has no HTML-special characters to
  escape) into `<a href="...">`, since it's an HTML-attribute escaper, not a URL validator.
  Added `isSafeExternalUrl()` (well-formed URL + https:// scheme specifically) and apply it at
  the trust boundary - when parsing `<changelogurl>` out of the remote updates.xml - rather
  than at render time, so the template's existing `!empty($item->changelog_url)` guard now
  also means "and it was safe", with no template change needed. Verified against a
  `javascript:`, a `data:` URI, plain `http://`, and two real `https://` GitHub URLs.

## 1.5.2
- Fixed the server-side Update check being looser than the UI: it only rejected
  `not_installed`, so a hand-crafted POST (bypassing the button, which only shows for
  `update_available`) could trigger Update on an already-up-to-date extension, redownloading
  and reinstalling the same version. Now requires `state === 'update_available'` exactly, with
  a distinct message when it's already current vs. not installed at all. The `error`/
  `incompatible` states were already blocked earlier (they never carry a `download_url`) - this
  closes the one real gap, `installed`.

## 1.5.1
- Fixed Update calling `Installer::install()` instead of a real update. Confirmed by fetching
  Joomla core's own current `com_installer/src/Model/UpdateModel.php` (the code behind
  Extensions > Manage > Update > Find Updates): it calls `$installer->update($package['dir'])`
  specifically, a genuine distinct public method - not just `install()` detecting an existing
  extension. Using `install()` for everything meant an install script reacting differently to
  update vs. fresh-install (e.g. running migration-only logic, or Joomla's
  onExtensionBefore/AfterUpdate plugin events) would never see the update path.
  `InstallHelper::installFromUrl()` now takes an `$isUpdate` flag and calls the matching
  method; `ExtensionsController::process()` passes `$mode === 'update'`. Install and Uninstall
  were already correct (`install()` and `uninstall()` are the right calls for those).

## 1.5.0
- Added `<php_minimum>` checking, confirmed against a real, currently-relevant case:
  `plg_content_fgautolightbox`'s `joomla6/updates.xml` declares `<php_minimum>8.3</php_minimum>`.
  A row whose Joomla version matches but whose PHP requirement isn't met now shows a precise
  "Requires PHP 8.3+, server has PHP 8.2.x" instead of the generic incompatible message.
  Verified end-to-end against the real feed for three combinations (J6.1.3/PHP8.3 -> OK,
  J6.1.3/PHP8.2 -> PHP_TOO_OLD, J5.4.8/any -> INCOMPATIBLE, no Joomla-version match at all).
- Also now checks `targetplatform`'s `name` attribute (must be "joomla"), matching Joomla
  core's own `Updater\Update` logic exactly.
- Deliberately NOT implemented: `min_dev_level`/`max_dev_level` - confirmed via Joomla core
  source that these were deprecated and are no longer evaluated since 4.0 - and
  `supported_databases` enforcement - real tag, but none of his current feeds use it, so this
  would be speculative complexity with no present payoff; parsing can be added if a real case
  comes up.

## 1.4.0
- Fixed `plg_content_fgautolightbox` showing "Not compatible" on both Joomla 5 and Joomla 6:
  its root `updates.xml` only covers the frozen Joomla 3.10 classic build; the Joomla 6 native
  build has its own separate `updates.xml` under `joomla6/`, which auto-discovery (1.2.0) had
  no way to find once the manual path-override list was removed.
  Added `fetchEntriesWithFallback()`: fetches the primary (root) `updates.xml` first, and only
  when its entries don't match the running Joomla version does it also try a short list of
  known subfolder-build paths (`joomla6/updates.xml`, `joomla4-6/updates.xml`,
  `joomla5-6/updates.xml`), stopping as soon as a match is found. The common single-file case
  still costs exactly one request; verified end-to-end against the real repo for Joomla 6.1.3 -
  root has 1 entry (J3.10 only, no match), `joomla6/updates.xml` has the native v2.3.9 build
  that does match.

## 1.3.6
- The small type line under each extension name now shows the stripped type word combined
  with the base type ("System plugin", "Editor plugin", "Content plugin") instead of just
  "plugin"/"component" - reusing the exact same word that was removed from the name, so the
  two stay consistent. Falls back to the bare type when no prefix was stripped ("FG - Remove
  Generator" still just shows "plugin"). Renamed `cleanExtensionName()` to `splitTypePrefix()`
  since it now returns the matched word too, not just the cleaned name.

## 1.3.5
- Corrected 1.3.4: it stripped *any* word before the first " - ", which also stripped "FG"
  itself from names like "FG - Remove Generator" - not what was wanted. Now only strips the
  prefix when it's a known Joomla/JED type word (System, Content, Editor, Captcha, ...) via an
  explicit list that deliberately excludes "fg", so the brand always stays: "System - FG
  Offline IP Whitelist" -> "FG Offline IP Whitelist", but "FG - Remove Generator" is left
  untouched since "FG" isn't a type word.

## 1.3.4
- The name-prefix stripping from 1.3.3 now also applies to the displayed name, not just the
  sort order - "System - FG Offline IP Whitelist" shows as "Offline IP Whitelist",
  "FG - Remove Generator" as "Remove Generator", etc. Renamed `nameSortKey()` to
  `cleanExtensionName()` since it now does both jobs; the final sort just compares the
  already-cleaned `name` directly instead of re-deriving it.

## 1.3.3
- Fixed inconsistent alphabetical sorting caused by mixed FG naming styles across extensions
  ("FG - Remove Generator" vs "System - FG Offline IP Whitelist" vs "FG Backend LangSwitcher").
  Added `nameSortKey()`, which strips a leading "<Type> - " segment and/or a bare "FG" prefix
  before comparing - verified against real examples of every style currently in use. No manual
  name list needed; the displayed name is untouched, only the sort order changes.

## 1.3.2
- Changed row grouping to match Regular Labs: installed extensions (update available, then
  up to date) now come first as one group, followed by not-installed, then
  incompatible/error. Within each group, rows stay alphabetical (PHP's `usort` is stable
  since 8.0, and Joomla 6 requires 8.1+, so the alphabetical pass in `RepoHelper::getCatalog()`
  survives this second sort).

## 1.3.1
- Fixed "Class Joomla\CMS\Filesystem\Folder not found" on Joomla 6 (confirmed reproducible on
  khanovaskola.sk, Joomla 6.1.3). `Joomla\CMS\Filesystem\Folder` was deprecated since 4.4 and
  removed outright in 6.0 (moved to a backward-compat plugin that isn't active by default) -
  switched to the still-current framework class `Joomla\Filesystem\Folder`. Its `create()`
  throws on failure instead of returning `false` like the old wrapper did, so both call sites
  (updates.xml cache, GitHub API discovery cache) are now wrapped in a non-fatal try/catch -
  a failed cache-directory create was never meant to be fatal here anyway.
- Audited every other `Joomla\CMS\*` import in the component against the same removed-in-6
  list; nothing else matched.

## 1.3.0
- Added Uninstall. Uses Joomla's core `Installer::uninstall()` (same mechanism as
  Extensions > Manage > Uninstall), shown for any row that's Installed or has an Update
  available. Gated behind `core.admin` specifically (not `core.manage`), separate from
  install/update, since it's destructive - and confirms with a JS dialog naming the extension
  before submitting.

## 1.2.1
- Fixed the Options page toolbar showing the raw `com_fgextensionmanager_configuration`
  language key instead of a real title - same root cause as the earlier submenu label fix
  (1.0.6): that screen only loads each component's `.sys.ini`, not the main `.ini`. Added
  `COM_FGEXTENSIONMANAGER_CONFIGURATION` to both.

## 1.2.0
- Removed the manual repository list entirely - GitHub-topic discovery (1.1.0) is now the only
  source, since maintaining a per-installation manual list was pointless duplication across
  multiple sites. Simplifies `RepoHelper` (no more merge-with-manual-overrides logic), drops
  the now-unused `forms/repo.xml` and the `repos` subform field from `config.xml`, and removes
  the "auto" badge (every row is discovered now, so it added nothing).
- If you'd used the manual list for a repo without the topic yet, tag it on GitHub instead -
  that's now the only way to add or remove a tracked repo.

## 1.1.1
- The GitHub Owner/Org and Topic discovery fields now default to `FGcodework` /
  `fg-joomla-extension` even if Options was already saved once with them blank - a
  `config.xml` `default` attribute only pre-fills a form that has never been saved, it doesn't
  affect `ComponentHelper::getParams()` once an (even empty) value exists in the database, so
  the fallback now lives in code instead. As a result these two fields can no longer be left
  blank to turn discovery off (updated the Options description accordingly) - say if you want
  an explicit on/off toggle instead.

## 1.1.0
- Added GitHub-topic-based auto-discovery. Set a GitHub owner/org + topic in Options and every
  repo under that account tagged with that exact topic is automatically included - no reinstall
  needed to add a new FG extension, and "helper"/private repos simply never get the topic.
  Discovery results are cached (same cache duration as updates.xml) with their own cache key.
  Each discovered repo's actual `default_branch` (from the GitHub API) is used automatically -
  confirmed this matters: not every FGcodework repo defaults to `master` (`fg-banner-generator`
  uses `main`), which a hand-typed branch would have silently gotten wrong.
  A manually-configured repo (existing list, now labeled "Manual Repositories / Overrides")
  always takes precedence over a discovered one for the same owner/repo, so it still works for
  one-off exceptions or overriding branch/path. Discovered rows show a small "auto" badge in
  the Repository column.
  `curlFetch()` now takes an optional headers array so the same direct-curl approach already
  proven for updates.xml fetches (see 1.0.11) covers the GitHub API call too.

## 1.0.15
- Added a separate "Not compatible" state (dark gray badge), distinct from a real "Error" (red
  badge). When a repo's updates.xml fetches and parses fine but simply has no `<update>` entry
  whose `<targetplatform>` matches the running Joomla version, that's an expected outcome, not
  a failure - the row now shows the extension's real name and its type, plus a message naming
  the running Joomla version and which targetplatform pattern(s) the repo *does* offer.

## 1.0.14
- Widened the owner/repo column at branch's expense (50/6/20) - branch is almost always
  "master" and rarely needs much room, while owner/repo names run long.

## 1.0.13
- Removed the "Display Label" column from the repo list in Options - rarely needed (the list
  already falls back to the extension's own `<name>` from updates.xml, or the repo path, when
  no label is set) and it was crowding the table. Redistributed the freed width to
  owner/repo (40), branch (10) and path (20).

## 1.0.12
- Fixed the repo list in Options needing horizontal scrolling: Joomla's subform
  `repeatable-table` layout uses each field's `size` attribute as a relative column-width
  weight, and the original values (45/20/30/30) were far larger than needed - shrunk to
  28/8/14/10 so all four columns plus the row actions fit without scrolling. Shortened the
  owner/repo placeholder text to match.

## 1.0.11
- Replaced Joomla's own HTTP abstraction (`HttpFactory`) with a direct `curl_exec()` call for
  fetching each repo's updates.xml. On this host, Joomla's HTTP client was consistently
  returning "200 OK" with an empty body and no headers at all for every GitHub raw URL tested
  (3 different repos, same result) - a direct curl call to the exact same URLs, tested for
  real from a separate environment against `FGcodework/plg_system_fgemailremover`, returned
  the correct 2161-byte body every time. `CURLOPT_ENCODING => ''` lets curl negotiate and
  transparently decode gzip/deflate/br itself, and `CURLOPT_FOLLOWLOCATION` follows redirects -
  both are things an older/misconfigured transport can silently get wrong.
- Defensively strips a leading UTF-8 BOM before parsing (confirmed libxml tolerates it fine on
  the real file, but stripping it costs nothing and guards other repos' files).
- Confirmed end-to-end against the real `FGcodework/plg_system_fgemailremover` updates.xml:
  correctly parses both `<update>` entries (Joomla 3.10 and Joomla 4-6 builds) and their
  mutually exclusive `<targetplatform>` regexes.

## 1.0.10
- On an "HTTP 200 but empty body" fetch failure, the error now also dumps the response's
  content-length/content-encoding/location/content-type headers, to distinguish a redirect
  that wasn't followed from a gzip-decoding issue from something else. Also sends an explicit
  User-Agent header on every request (some GitHub endpoints are picky about missing ones).

## 1.0.9
- The "Could not fetch updates.xml" error now shows the actual reason (HTTP status code, or
  the exception class/message on a network failure) and the exact URL that was requested,
  instead of a generic message - needed to diagnose the next issue without more guesswork.

## 1.0.8
- Fixed `preg_match(): Unknown modifier '?'` in `normalizeOwnerRepo()`: the regex used `#` as
  its delimiter but also contained a literal, unescaped `#` inside its own character classes
  (`[^/\s#?]`, `[#?]`), which PCRE read as the closing delimiter, turning everything after it
  into bogus modifier flags. Verified this time with a real `php -r` run against all the
  sample URL formats, not just a Python regex check. Switched the delimiter to `~`.

## 1.0.7
- Fixed the repo list showing nothing after saving Options: the subform stores each row's
  fields nested one level under the row-form's field group name ("repo" per
  `forms/repo.xml`), e.g. `{"repo": {"owner_repo": ...}}` - `RepoHelper::getCatalog()` was
  reading `owner_repo` directly on the row instead of unwrapping that nested group first, so
  every configured repo was silently skipped.
- `owner_repo` now also accepts a full GitHub URL (`https://github.com/owner/repo`, with or
  without `.git`/trailing slash, or the `git@github.com:owner/repo.git` SSH form), not just a
  bare `owner/repo` string.

## 1.0.6
- Fixed the submenu item showing the raw `COM_FGEXTENSIONMANAGER_MENU_EXTENSIONS` language key
  instead of its translation ("Extensions" / "Rozšírenia"). Joomla renders the admin sidebar
  menu using only each component's `.sys.ini` file (not the main `.ini`), and that key was only
  defined in the main one. Added it to both `en-GB` and `sk-SK` `.sys.ini` files.

## 1.0.5
- Fixed the actual structural cause (confirmed reproducible across two different sites with
  the exact same install method that works for his other FG components, ruling out hosting):
  `<files folder="admin">` was a sibling of `<administration>` instead of nested inside it.
  Cross-checked against Joomla core's own admin-only `com_actionlogs`, which nests `<files>`
  (and `<languages>`) directly inside `<administration>` alongside `<menu>`. With `<files>`
  outside that block, Joomla registered the extension (header fields always parse) and copied
  the bare manifest file (a separate, unconditional step), but never processed the folder/file
  copy list at all - confirmed by listing the installed directory, which contained only
  `fgextensionmanager.xml` no matter how the ZIP itself was packaged (wrapped or flat).

## 1.0.4
- Fixed the actual root cause behind every previous "Component not found" attempt: the ZIP
  had everything wrapped inside a top-level `com_fgextensionmanager/` folder. Joomla's
  installer on this host does not reliably auto-descend into that wrapper - it showed
  "Can't find XML setup file" on *every* install attempt (not a one-off cosmetic warning as
  first assumed), then recovered just enough to register the extension row and copy the bare
  manifest file, while the `<files>` folder-copy loop silently failed for everything else
  (`services/`, `src/`, `tmpl/`, ...) - confirmed by listing the actual installed folder,
  which contained only `fgextensionmanager.xml`. Fixed by flattening the ZIP so
  `fgextensionmanager.xml` and all component folders sit at the ZIP root, matching the
  structure of every real-world Joomla install package (including Regular Labs' own).
  The 1.0.2 (Dispatcher.php) and 1.0.3 (manifest namespace) fixes were both correct and are
  kept, they just never had a chance to matter since their files never reached disk.

## 1.0.3
- Fixed the real root cause of "Component not found" (Legacy dispatcher 404), confirmed by
  comparing against Joomla core's own `com_actionlogs`: the manifest's `<namespace path="src">`
  must be the namespace root WITHOUT the `\Administrator` suffix - Joomla appends
  `\Administrator` itself when registering the PSR-4 autoloader for the admin side. Having it
  in twice (`...\Administrator\Administrator`) broke autoloading of every class (Dispatcher,
  Controllers, ...), which Joomla's core silently swallows and falls back to
  `LegacyComponentDispatcher` for - masking the real error. Class files themselves keep their
  `\Administrator` namespace segment unchanged; only the manifest tag changed.
- Kept the 1.0.2 Dispatcher.php fix (still required, just wasn't reachable before due to the
  namespace bug above).

## 1.0.2
- Fixed "Component not found" (falling back to `LegacyComponentDispatcher`, 404) when opening
  the component. `services/provider.php` registers a `ComponentDispatcherFactory`, but unlike
  Controller/Model/View, the Dispatcher class has no generic fallback in Joomla core - it must
  physically exist at the namespaced path. Added `src/Dispatcher/Dispatcher.php` (a plain
  subclass of the core `ComponentDispatcher`, no overrides needed).

## 1.0.1
- Fixed install failure (`File::copy .../language/language` double-path) caused by a
  `<languages><folder>language</folder></languages>` manifest tag - the language folder is
  now listed inside `<files folder="admin">` instead, per Joomla convention, with no
  separate `<languages>` tag.

## 1.0.0
- Initial release.
- Admin-only overview of extensions listed under a configurable set of GitHub repos.
- Reads each repo's *existing* `updates.xml` (no separate catalog to maintain) - picks the
  entry whose `<targetplatform>` matches the running Joomla version, the same prefix-anchored
  matching Joomla core itself uses.
- Compares against `#__extensions` (via `manifest_cache`) to classify each extension as
  Not installed / Installed / Update available.
- Install and Update actions use Joomla's own core installer
  (`InstallerHelper::downloadPackage()` + `unpack()` + `Installer::install()`) - the same
  mechanism as Extensions > Install > Install from URL. Optional sha256 checksum
  verification when the repo's `updates.xml` provides one.
- Local file-based cache per repo (default 30 min, configurable) with a manual Refresh button.
