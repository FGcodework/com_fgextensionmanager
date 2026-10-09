<p align="center">
  <img src="assets/logo.png" width="120" alt="FG Extension Manager logo">
</p>

<h1 align="center">FG Extension Manager</h1>

<p align="center">
  <img src="https://img.shields.io/github/v/release/FGcodework/com_fgextensionmanager?color=FF6B4A&label=release" alt="Latest release">
  <img src="https://img.shields.io/badge/Joomla-5.x%20%7C%206.x-blue.svg?logo=joomla&logoColor=white" alt="Joomla">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-purple.svg?logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/license-GPL--2.0%2B-green.svg" alt="License">
  <img src="https://img.shields.io/github/downloads/FGcodework/com_fgextensionmanager/total?cacheSeconds=3600" alt="Downloads">
</p>

A native Joomla admin component that discovers, installs, updates and uninstalls the **FG
series** of GitHub extensions - no manual "Install from URL" per extension, no separate
catalog to maintain by hand.

## How it works

- **Discovery**: every GitHub repo under a configured owner/org that carries a specific
  GitHub topic (default `FGcodework` / `fg-joomla-extension`) is tracked automatically. Tag a
  new repo with that topic and it shows up here on the next refresh - no reinstall needed.
- **Compatibility**: reads each repo's own `updates.xml` (the same file Joomla's native
  "Find Updates" already uses), matching the running Joomla *and* PHP version. A repo with a
  subfolder build split (e.g. a separate Joomla 6-native build under `joomla6/`) is tried
  automatically if the root file doesn't cover the current Joomla version.
- **Install / Update / Uninstall**: uses Joomla's own core installer
  (`Installer::install()` / `::update()` / `::uninstall()`) - the same mechanism as
  Extensions › Manage.
- **Enable / Disable**: toggles `#__extensions.enabled` directly, mirroring the green
  check / red circle on Joomla's own Extensions: Manage screen.
- **Settings**: one click to a plugin's edit screen or a component's own admin screen, where
  Joomla has a well-defined target for one.
- **Changelog preview**: expands the latest `CHANGELOG.md` entry inline, with a link to the
  full file on GitHub.

## Requirements

- Joomla 5.0 or newer (including Joomla 6)
- PHP 8.0 or newer

Both are checked automatically at install time - installing on something older fails with a
clear message instead of a PHP fatal error later.

## Installation

1. Download the latest release ZIP from the [Releases](../../releases) page.
2. Extensions → Install → Upload Package File.
3. Open **Components → FG Extension Manager**, then **Options** to set the GitHub owner/org
   and topic (pre-filled with sensible defaults).

## Configuration

| Setting | Default | Description |
|---|---|---|
| GitHub Owner/Org | `FGcodework` | The GitHub account to search within |
| GitHub Topic | `fg-joomla-extension` | Only repos tagged with this exact topic are tracked |
| Cache Duration | 30 minutes | How long fetched `updates.xml`/`CHANGELOG.md` data is cached |
| HTTP Timeout | 10 seconds | Timeout per request when fetching from GitHub |

## Adding an extension to the list

1. Give the GitHub repo the topic `fg-joomla-extension` (repo page → the gear next to *About*).
2. Make sure the repo has an `updates.xml` in its root (or in a `joomla6/` / similar subfolder
   for a separate build) with a `<downloadurl>` and, ideally, a `<sha512>` of the release ZIP.
3. Optional: add a logo as `assets/logo.webp` (fallback `assets/logo.png`), 512×512 with
   transparent corners. It is loaded from the repo's default branch; without it the list shows a
   letter tile.
4. Press **Refresh** in the component - the extension appears in the list.

## Update All

**Update All** installs every pending update one after another; this component itself is always
updated last. It asks PHP for extra execution time and, if the host's limit is still too tight,
stops before starting an update that wouldn't fit and lists the ones left over - press Update All
again to finish.

## Permissions

| Action | Required permission |
|---|---|
| View the list, Refresh | `core.manage` |
| Install, Update, Update All, Enable/Disable | `core.manage` |
| Uninstall, Options | `core.admin` |

The component can't uninstall or disable itself from its own list.

## Security

- Every action is protected by Joomla's CSRF token and the permissions above.
- Download URLs must be HTTPS and on a GitHub host (allowlist), and the ZIP is verified against the
  `sha256` / `sha384` / `sha512` checksum from the repo's `updates.xml` when one is published.
- Nothing is installed without an explicit click (or Update All confirmation).

## Troubleshooting

- **The list is empty** - check Options: owner/org and topic must match the repos' GitHub topic
  exactly (this component itself is always shown).
- **Data looks stale** - Refresh bypasses the cache; the cache lives in Joomla's cache folder
  (`cache/com_fgextensionmanager`) and expires after the configured number of minutes.
- **A status says "Not compatible"** - the repo's `updates.xml` has no entry for your Joomla/PHP
  version; the version shown in muted text is the newest the repo publishes.
- **A logo is missing** - a repo without `assets/logo.*` (or a temporary GitHub throttle) shows a
  letter tile; the logo is retried once automatically.

## License

GNU General Public License v2.0 or later - see [LICENSE.txt](LICENSE.txt).
