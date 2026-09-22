<?php
/**
 * @package     FG.Administrator
 * @subpackage  com_fgextensionmanager
 *
 * @copyright   Copyright (C) 2026 Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace FG\Component\FgExtensionManager\Administrator\Helper;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\Filesystem\Folder;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;

defined('_JEXEC') or die;

/**
 * Fetches and parses each configured repo's updates.xml and builds the extension catalog.
 */
class RepoHelper
{
	/**
	 * Set (with noteStale()) whenever any fetch this call falls back to an
	 * expired cache after a live request failed, so the catalog's own
	 * `stale` flag isn't silently wrong. Reset at the top of every
	 * getCatalog() call - these exist only to thread that fact up through
	 * several layers of private static methods without changing all of
	 * their signatures.
	 */
	private static bool $anyStale = false;
	private static ?int $oldestStaleTimestamp = null;

	/**
	 * The oldest "as of" timestamp among every primary updates.xml source
	 * used to build the current catalog (whether served from a still-fresh
	 * cache, just live-fetched, or a stale fallback) - an always-shown "last
	 * checked" indicator, distinct from $anyStale/$oldestStaleTimestamp
	 * above which only fire on genuine fetch failures. Reset alongside them.
	 */
	private static ?int $oldestDataTimestamp = null;

	/**
	 * Build the full catalog (one entry per configured repo).
	 *
	 * @param   boolean  $refresh  Bypass the local cache and re-fetch every repo.
	 *
	 * @return  object  {items: object[], stale: bool, stale_since: int|null, last_checked: int|null}
	 *                  stale is true if ANY repo's data shown came from an
	 *                  expired cache because a live fetch failed - the
	 *                  caller (e.g. the Refresh action) should not claim
	 *                  success without checking this.
	 */
	public static function getCatalog(bool $refresh = false): object
	{
		self::$anyStale             = false;
		self::$oldestStaleTimestamp = null;
		self::$oldestDataTimestamp  = null;

		$params       = ComponentHelper::getParams('com_fgextensionmanager');
		$cacheMinutes = (int) $params->get('cache_minutes', 30);
		$timeout      = (int) $params->get('http_timeout', 10);

		$rows = self::buildRepoRows($params, $timeout, $cacheMinutes, $refresh);

		// All repos' primary updates.xml are independent - fetch every one
		// that isn't already served by a fresh cache in a single parallel
		// batch (curl_multi), instead of N sequential requests where one
		// slow/down host delays every repo after it by up to $timeout
		// seconds each.
		$primary    = self::fetchPrimaryBatch($rows, $timeout, $cacheMinutes, $refresh);
		$changelogs = self::fetchChangelogBatch($rows, $timeout, $cacheMinutes, $refresh);

		$catalog = [];

		foreach ($rows as $ownerRepo => $repoData)
		{
			$branch = $repoData->branch;
			$path   = $repoData->path;
			$label  = $repoData->label;

			$url = 'https://raw.githubusercontent.com/' . $ownerRepo . '/' . $branch . '/' . ltrim($path, '/');

			$item = (object) [
				'key'                 => $ownerRepo,
				'owner_repo'          => $ownerRepo,
				'label'               => $label !== '' ? $label : $ownerRepo,
				'source_url'          => $url,
				'repo_url'            => 'https://github.com/' . $ownerRepo,
				'is_self'             => strcasecmp($ownerRepo, self::SELF_OWNER_REPO) === 0,
				'name'                => null,
				'description'         => null,
				'type'                => null,
				'type_label'          => null,
				'element'             => null,
				'technical_id'        => null,
				'folder'              => null,
				'client'              => null,
				'available_version'   => null,
				'installed_version'   => null,
				'enabled'             => null,
				'protected'           => false,
				'manage_url'          => null,
				'download_url'        => null,
				'changelog_url'       => null,
				'changelog_preview'   => null,
				'changelog_full_url'  => 'https://github.com/' . $ownerRepo . '/blob/' . $branch . '/CHANGELOG.md',
				'sha256'              => null,
				'sha384'              => null,
				'sha512'              => null,
				'state'               => 'error',
				'error'               => null,
			];

			$entries    = $primary[$ownerRepo]['entries'] ?? [];
			$fetchError = $primary[$ownerRepo]['error'] ?? null;

			// Only the (typically few) repos whose primary path doesn't cover
			// the running Joomla version pay for extra, sequential
			// fallback-path requests (e.g. joomla6/updates.xml) - rare
			// enough that a second parallel batch isn't worth the complexity.
			if (CompatibilityEvaluator::pickBest($entries) === null)
			{
				$entries = array_merge($entries, self::fetchFallbackPaths($ownerRepo, $branch, $timeout, $cacheMinutes, $refresh));
			}

			if (empty($entries))
			{
				$item->error = $fetchError !== null
					? Text::_('COM_FGEXTENSIONMANAGER_ERROR_FETCH_FAILED') . ' (' . $fetchError . ') [' . $url . ']'
					: Text::_('COM_FGEXTENSIONMANAGER_ERROR_NO_ENTRIES');
				$catalog[]   = $item;
				continue;
			}

			[$best, $phpMismatch] = CompatibilityEvaluator::evaluate($entries);

			if (!$best && $phpMismatch)
			{
				[$item->name, $typeWord] = self::splitTypePrefix($phpMismatch->name);
				$item->description       = $phpMismatch->description;
				$item->type              = $phpMismatch->type;
				$item->technical_id      = self::buildTechnicalId($phpMismatch);
				$item->type_label        = $typeWord !== null ? $typeWord . ' ' . $item->type : $item->type;
				$item->available_version = $phpMismatch->version;
				$item->state             = 'incompatible';
				$item->error             = Text::sprintf(
					'COM_FGEXTENSIONMANAGER_ERROR_PHP_TOO_OLD',
					$phpMismatch->php_minimum,
					PHP_VERSION
				);
				$item->changelog_preview = self::extractChangelogSince($changelogs[$ownerRepo] ?? null, null);

				if ($label === '')
				{
					$item->label = $item->name;
				}

				$catalog[] = $item;
				continue;
			}

			if (!$best)
			{
				$targets = array_values(array_unique(array_map(
					static fn ($entry) => $entry->targetplatform,
					$entries
				)));

				$humanTargets = array_values(array_unique(array_filter(array_map(
					[self::class, 'humanizeTargetPlatform'],
					$targets
				))));

				[$item->name, $typeWord] = self::splitTypePrefix($entries[0]->name ?: $ownerRepo);
				$item->description        = $entries[0]->description;
				$item->type              = $entries[0]->type;
				$item->technical_id      = self::buildTechnicalId($entries[0]);
				$item->type_label        = $typeWord !== null ? $typeWord . ' ' . $item->type : $item->type;
				$item->state             = 'incompatible';
				$item->error             = Text::sprintf(
					'COM_FGEXTENSIONMANAGER_ERROR_NOT_COMPATIBLE',
					defined('JVERSION') ? JVERSION : '?',
					$humanTargets ? implode(', ', $humanTargets) : implode(', ', $targets)
				);
				$item->changelog_preview = self::extractChangelogSince($changelogs[$ownerRepo] ?? null, null);

				if ($label === '')
				{
					$item->label = $item->name;
				}

				$catalog[] = $item;
				continue;
			}

			[$item->name, $typeWord]  = self::splitTypePrefix($best->name);
			$item->description        = $best->description;
			$item->type               = $best->type;
			$item->type_label         = $typeWord !== null ? $typeWord . ' ' . $item->type : $item->type;
			$item->element            = $best->element;
			$item->technical_id      = self::buildTechnicalId($best);
			$item->folder             = $best->folder;
			$item->client             = $best->client;
			$item->available_version  = $best->version;
			$item->download_url       = $best->download_url;
			$item->changelog_url      = $best->changelog_url;
			$item->sha256              = $best->sha256;
			$item->sha384              = $best->sha384;
			$item->sha512              = $best->sha512;

			if ($label === '')
			{
				$item->label = $item->name;
			}

			$installed = InstalledHelper::find($best);

			if ($installed === null)
			{
				$item->state = 'not_installed';
			}
			else
			{
				$item->installed_version = $installed->version;
				$item->extension_id      = $installed->extension_id;
				$item->enabled           = $installed->enabled;
				$item->protected         = $installed->protected;
				$item->manage_url        = self::buildManageUrl($best->type, $best->element, $installed->extension_id);

				if ($installed->version && version_compare($installed->version, $best->version, '<'))
				{
					$item->state = 'update_available';
				}
				else
				{
					$item->state = 'installed';
				}
			}

			$item->changelog_preview = self::extractChangelogSince($changelogs[$ownerRepo] ?? null, $item->installed_version);

			$catalog[] = $item;
		}

		usort($catalog, fn ($a, $b) => strcasecmp($a->name ?? $a->label, $b->name ?? $b->label));

		return (object) [
			'items'        => $catalog,
			'stale'        => self::$anyStale,
			'stale_since'  => self::$oldestStaleTimestamp,
			'last_checked' => self::$oldestDataTimestamp,
		];
	}

	/**
	 * Records that stale (expired-cache) data was used for some part of this
	 * getCatalog() call, keeping the OLDEST such timestamp - the most
	 * conservative "as of" date to show the user when several sources are
	 * stale by different amounts.
	 */
	private static function noteStale(int $timestamp): void
	{
		self::$anyStale = true;

		if (self::$oldestStaleTimestamp === null || $timestamp < self::$oldestStaleTimestamp)
		{
			self::$oldestStaleTimestamp = $timestamp;
		}

		self::noteDataTimestamp($timestamp);
	}

	/**
	 * Tracks the OLDEST "as of" timestamp seen across every primary
	 * updates.xml source this call, for the always-shown "last checked"
	 * indicator - the most conservative reading when sources have different
	 * ages (matches the same reasoning as noteStale() above).
	 */
	private static function noteDataTimestamp(int $timestamp): void
	{
		if (self::$oldestDataTimestamp === null || $timestamp < self::$oldestDataTimestamp)
		{
			self::$oldestDataTimestamp = $timestamp;
		}
	}

	/**
	 * Fetches every repo's PRIMARY updates.xml path in one parallel batch
	 * (curl_multi) instead of N sequential requests - each independent, so
	 * there's no reason a slow/down host for repo #3 should make repo #4
	 * wait its full timeout before even starting. Repos already served by a
	 * fresh, within-TTL cache are skipped from the network batch entirely.
	 *
	 * @return  array<string, array{entries: object[], error: string|null}>  keyed by owner/repo
	 */
	private static function fetchPrimaryBatch(array $rows, int $timeout, int $cacheMinutes, bool $refresh): array
	{
		$results  = [];
		$toFetch  = [];
		$cacheDir = JPATH_CACHE . '/com_fgextensionmanager';

		foreach ($rows as $ownerRepo => $repoData)
		{
			$url       = 'https://raw.githubusercontent.com/' . $ownerRepo . '/' . $repoData->branch . '/' . ltrim($repoData->path, '/');
			$cacheFile = $cacheDir . '/' . md5($url) . '.xml';

			if (!$refresh && $cacheMinutes > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMinutes * 60)
			{
				$content = @file_get_contents($cacheFile);

				if ($content !== false && $content !== '')
				{
					self::noteDataTimestamp(filemtime($cacheFile) ?: time());
					$results[$ownerRepo] = ['entries' => self::parseUpdatesXml($content), 'error' => null];

					continue;
				}
			}

			$toFetch[$ownerRepo] = ['url' => $url, 'cache_file' => $cacheFile];
		}

		if (empty($toFetch))
		{
			return $results;
		}

		$requests = array_map(static fn ($info) => ['url' => $info['url']], $toFetch);
		$responses = self::curlFetchMulti($requests, $timeout);

		foreach ($toFetch as $ownerRepo => $info)
		{
			$response = $responses[$ownerRepo] ?? ['body' => null, 'error' => 'no response'];

			if ($response['body'] !== null)
			{
				if (!is_dir($cacheDir))
				{
					try
					{
						Folder::create($cacheDir);
					}
					catch (\Throwable $e)
					{
						// Non-fatal.
					}
				}

				self::atomicCacheWrite($info['cache_file'], $response['body']);

				self::noteDataTimestamp(time());
				$results[$ownerRepo] = ['entries' => self::parseUpdatesXml($response['body']), 'error' => null];

				continue;
			}

			$stale = self::fallbackToStaleCache($info['cache_file']);

			$results[$ownerRepo] = $stale !== null
				? ['entries' => self::parseUpdatesXml($stale), 'error' => null]
				: ['entries' => [], 'error' => $response['error']];
		}

		return $results;
	}

	/**
	 * Fetches every repo's root CHANGELOG.md in one parallel batch, the same
	 * way as fetchPrimaryBatch(). Deliberately does NOT use updates.xml's
	 * <changelogurl> field: checked his real feeds and found it either
	 * missing entirely, or present under the wrong tag name
	 * (<maintainerchangelogurl>) pointing at a raw .md file - and even a
	 * correctly-named <changelogurl> is supposed to point to Joomla's own
	 * dedicated changelog.xml schema (Changelog::loadFromXml()), not a plain
	 * CHANGELOG.md, per Joomla's own documentation. Fetching CHANGELOG.md
	 * directly by convention is simpler and matches something he already
	 * does consistently across every FG repo.
	 *
	 * @return  array<string, string|null>  raw markdown per owner/repo, or null if unavailable
	 */
	private static function fetchChangelogBatch(array $rows, int $timeout, int $cacheMinutes, bool $refresh): array
	{
		$results  = [];
		$toFetch  = [];
		$cacheDir = JPATH_CACHE . '/com_fgextensionmanager';

		foreach ($rows as $ownerRepo => $repoData)
		{
			$url       = 'https://raw.githubusercontent.com/' . $ownerRepo . '/' . $repoData->branch . '/CHANGELOG.md';
			$cacheFile = $cacheDir . '/' . md5($url) . '.md';

			if (!$refresh && $cacheMinutes > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMinutes * 60)
			{
				$content = @file_get_contents($cacheFile);

				if ($content !== false && $content !== '')
				{
					$results[$ownerRepo] = $content;

					continue;
				}
			}

			$toFetch[$ownerRepo] = ['url' => $url, 'cache_file' => $cacheFile];
		}

		if (empty($toFetch))
		{
			return $results;
		}

		$requests  = array_map(static fn ($info) => ['url' => $info['url']], $toFetch);
		$responses = self::curlFetchMulti($requests, $timeout);

		foreach ($toFetch as $ownerRepo => $info)
		{
			$response = $responses[$ownerRepo] ?? ['body' => null, 'error' => null];

			if ($response['body'] !== null)
			{
				if (!is_dir($cacheDir))
				{
					try
					{
						Folder::create($cacheDir);
					}
					catch (\Throwable $e)
					{
						// Non-fatal.
					}
				}

				self::atomicCacheWrite($info['cache_file'], $response['body']);

				$results[$ownerRepo] = $response['body'];

				continue;
			}

			// A repo with no CHANGELOG.md at all (a 404) is a normal,
			// expected case - not treated as staleness, unlike updates.xml.
			$results[$ownerRepo] = self::fallbackToStaleCache($info['cache_file']);
		}

		return $results;
	}

	/**
	 * Extracts every changelog entry from the newest down to (but not
	 * including) the one matching $sinceVersion - "everything that changed
	 * since what you have installed" instead of just the latest entry, so a
	 * check after a while away shows the full picture, not just the most
	 * recent line. Falls back to just the single latest entry when
	 * $sinceVersion is null (not installed), already the newest version
	 * (up to date), or isn't found in the changelog at all (e.g. a very old
	 * version, or the changelog was reset) - always returns at least the
	 * latest entry when the changelog has one at all, never nothing.
	 *
	 * Known limitation: a repo whose CHANGELOG.md interleaves two builds
	 * with independent version numbers (e.g. plg_system_fgemailremover's
	 * classic vs joomla4-6/ entries) could, in principle, match the wrong
	 * build's entry if the two ever happen to share a version number - the
	 * version is matched as a bare number, not tied to which build it
	 * belongs to. Not an issue with any of his current version numbering
	 * (verified against that exact repo), and this is a best-effort
	 * preview, not authoritative, so left as-is rather than added
	 * complexity for a case that doesn't currently occur.
	 */
	private const CHANGELOG_PREVIEW_MAX_CHARS = 4000;

	private static function extractChangelogSince(?string $markdown, ?string $sinceVersion): ?string
	{
		if ($markdown === null || trim($markdown) === '')
		{
			return null;
		}

		if (preg_match_all('/^##[ \t]+([^\n]*)\n(.*?)(?=\n##[ \t]+|\z)/ms', $markdown, $matches, PREG_SET_ORDER) === false
			|| empty($matches))
		{
			return null;
		}

		$sections = [];

		foreach ($matches as $match)
		{
			$heading = trim($match[1]);
			$version = null;

			if (preg_match('/(\d+\.\d+\.\d+)/', $heading, $versionMatch))
			{
				$version = $versionMatch[1];
			}

			$sections[] = ['heading' => $heading, 'body' => trim($match[2]), 'version' => $version];
		}

		if (empty($sections))
		{
			return null;
		}

		$cutoffIndex = null;

		if ($sinceVersion !== null)
		{
			foreach ($sections as $index => $section)
			{
				if ($section['version'] !== null && $section['version'] === $sinceVersion)
				{
					$cutoffIndex = $index;
					break;
				}
			}
		}

		// $sinceVersion not given, already the newest, or not found in the
		// changelog at all: fall back to just the single latest entry rather
		// than showing nothing - always having at least the latest entry
		// available is the behaviour this replaced, and losing it entirely
		// for the (common) case of an up-to-date installed extension would
		// be a regression, not an improvement.
		$included = ($cutoffIndex !== null && $cutoffIndex > 0)
			? array_slice($sections, 0, $cutoffIndex)
			: [$sections[0]];

		$parts = [];

		foreach ($included as $section)
		{
			$parts[] = '## ' . $section['heading'] . "\n" . $section['body'];
		}

		$combined = trim(implode("\n\n", $parts));

		if ($combined === '')
		{
			return null;
		}

		if (strlen($combined) > self::CHANGELOG_PREVIEW_MAX_CHARS)
		{
			$combined = rtrim(substr($combined, 0, self::CHANGELOG_PREVIEW_MAX_CHARS)) . ' …';
		}

		return $combined;
	}

	/**
	 * Runs several curl requests concurrently via curl_multi. Falls back to
	 * plain sequential curlFetch() calls if the curl_multi_* functions
	 * aren't available (extremely rare - same curl extension, always
	 * compiled in together in practice).
	 *
	 * @param   array<string, array{url: string}>  $requests  keyed by an
	 *          arbitrary caller-chosen id (owner/repo here)
	 *
	 * @return  array<string, array{body: string|null, error: string|null}>
	 */
	private static function curlFetchMulti(array $requests, int $timeout): array
	{
		if (empty($requests))
		{
			return [];
		}

		if (!function_exists('curl_multi_init'))
		{
			$results = [];

			foreach ($requests as $key => $request)
			{
				$error          = null;
				$body           = self::curlFetch($request['url'], $timeout, $error);
				$results[$key]  = ['body' => $body, 'error' => $error];
			}

			return $results;
		}

		$defaultHeaders = [
			'User-Agent: FG-Extension-Manager/' . (defined('JVERSION') ? JVERSION : '1.0'),
			'Accept: application/xml, text/xml, */*',
		];

		$mh      = curl_multi_init();
		$handles = [];

		foreach ($requests as $key => $request)
		{
			$ch = curl_init($request['url']);

			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_CONNECTTIMEOUT => $timeout,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_ENCODING       => '',
				CURLOPT_HTTPHEADER     => $request['headers'] ?? $defaultHeaders,
			]);

			curl_multi_add_handle($mh, $ch);
			$handles[$key] = $ch;
		}

		$running = null;

		do
		{
			$status = curl_multi_exec($mh, $running);

			if ($running)
			{
				curl_multi_select($mh, 1.0);
			}
		}
		while ($running > 0 && $status === CURLM_OK);

		$results = [];

		foreach ($handles as $key => $ch)
		{
			$body      = curl_multi_getcontent($ch);
			$httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curlErrno = curl_errno($ch);
			$curlError = curl_error($ch);

			if ($curlErrno !== 0)
			{
				$results[$key] = ['body' => null, 'error' => 'curl error ' . $curlErrno . ': ' . $curlError];
			}
			elseif ($httpCode !== 200)
			{
				$results[$key] = ['body' => null, 'error' => 'HTTP ' . $httpCode];
			}
			elseif ($body === '' || $body === false)
			{
				$results[$key] = ['body' => null, 'error' => 'empty body (HTTP ' . $httpCode . ')'];
			}
			else
			{
				$results[$key] = ['body' => $body, 'error' => null];
			}

			curl_multi_remove_handle($mh, $ch);
			curl_close($ch);
		}

		curl_multi_close($mh);

		return $results;
	}

	/**
	 * Subfolder-build conventions seen across his FG repos (e.g.
	 * plg_content_fgautolightbox keeps its Joomla 6 native build's own
	 * updates.xml under joomla6/, separate from the root file, which only
	 * covers the frozen Joomla 3.10 classic build).
	 */
	private const FALLBACK_PATHS = ['joomla6/updates.xml', 'joomla4-6/updates.xml', 'joomla5-6/updates.xml'];

	/**
	 * Tries the known subfolder-build paths (sequentially - only called for
	 * the typically-few repos whose primary path didn't already match, so a
	 * second parallel batch isn't worth the complexity), stopping as soon as
	 * a match for the running Joomla version is found.
	 *
	 * @return  object[]  Every <update> entry found across the fallback paths tried.
	 */
	private static function fetchFallbackPaths(string $ownerRepo, string $branch, int $timeout, int $cacheMinutes, bool $refresh): array
	{
		$allEntries = [];

		foreach (self::FALLBACK_PATHS as $path)
		{
			$url   = 'https://raw.githubusercontent.com/' . $ownerRepo . '/' . $branch . '/' . ltrim($path, '/');
			$error = null;

			$xmlString = self::fetchRaw($url, $timeout, $cacheMinutes, $refresh, $error);

			if ($xmlString === null)
			{
				continue;
			}

			$allEntries = array_merge($allEntries, self::parseUpdatesXml($xmlString));

			if (CompatibilityEvaluator::pickBest($allEntries) !== null)
			{
				break;
			}
		}

		return $allEntries;
	}

	/**
	 * Known Joomla/JED type-prefix words his FG-series manifests use in
	 * "<Type> - <Name>" display names (per his own documented convention).
	 * Deliberately does NOT include "fg" - that's his brand and must stay.
	 */
	private const TYPE_PREFIXES = [
		'system', 'content', 'editor', 'editors-xtd', 'captcha', 'authentication',
		'user', 'search', 'smart search', 'fields', 'extension', 'filesystem',
		'quickicon', 'installer', 'task', 'behaviour', 'webservices',
		'api authentication', 'two factor authentication', 'finder', 'workflow',
		'component', 'module', 'plugin', 'template', 'package', 'library',
	];

	/**
	 * Strips a leading "<Type> - " segment from a display name ("System - FG
	 * Offline IP Whitelist" -> "FG Offline IP Whitelist") when the word
	 * before the dash is a known Joomla/JED type word, and returns the
	 * matched word alongside it so callers can build a combined type label
	 * ("System" + "plugin" -> "System plugin"). "FG - Remove Generator" is
	 * left untouched (word is null), since "FG" is never in that list - his
	 * brand always stays. Used for both what's shown in the list and what
	 * it's sorted by.
	 *
	 * @return  array{0: string, 1: string|null}  [cleaned name, matched type word or null]
	 */
	/**
	 * The full, conventional Joomla identifier for an entry ("plg_system_x",
	 * "com_x", "mod_x", "pkg_x"), for display. Confirmed against real feeds:
	 * component/package/module <element> is already fully prefixed, but a
	 * plugin's is bare (no "plg_<folder>_") per Joomla's own #__extensions
	 * convention - same fact InstalledHelper::find() already relies on.
	 */
	/**
	 * A link to the installed extension's own settings screen, where Joomla
	 * has one well-defined target: a plugin's edit screen (com_plugins,
	 * keyed by extension_id - yes, that's really the field name com_plugins
	 * uses, even though plugins live in #__extensions), or a component's own
	 * admin screen (its default view, which for most single-purpose admin
	 * tools like this one *is* effectively its "settings"). Returns null for
	 * module/package - a module type has no single settings screen (zero,
	 * one, or many published instances could exist), and a package bundles
	 * other extensions rather than having settings of its own.
	 */
	private static function buildManageUrl(string $type, string $element, int $extensionId): ?string
	{
		return match ($type)
		{
			'plugin'    => 'index.php?option=com_plugins&task=plugin.edit&extension_id=' . $extensionId,
			'component' => 'index.php?option=' . $element,
			default     => null,
		};
	}

	private static function buildTechnicalId(object $entry): string
	{
		if ($entry->type === 'plugin')
		{
			return 'plg_' . $entry->folder . '_' . $entry->element;
		}

		return $entry->element;
	}

	/**
	 * Converts a raw targetplatform regex into a short, readable version
	 * range for display ("3\.[0-9]+\.[0-9]+" -> "3.x",
	 * "[456]\.[0-9]+\.[0-9]+" -> "4.x, 5.x, 6.x"), covering every pattern
	 * style actually seen across his real feeds. Returns null for anything
	 * more complex than that (e.g. an explicit alternation) rather than
	 * guessing - the caller falls back to the raw regex only in that rare
	 * case.
	 */
	private static function humanizeTargetPlatform(string $regex): ?string
	{
		if (preg_match('/^(\d)\\\.\[0-9\]\+\\\.\[0-9\]\+$/', $regex, $matches))
		{
			return $matches[1] . '.x';
		}

		if (preg_match('/^\[(\d+)\]\\\.\[0-9\]\+\\\.\[0-9\]\+$/', $regex, $matches))
		{
			return implode(', ', array_map(static fn ($d) => $d . '.x', str_split($matches[1])));
		}

		return null;
	}

	private static function splitTypePrefix(string $name): array
	{
		if (preg_match('/^([^-]+?)\s-\s(.+)$/', $name, $matches))
		{
			$prefixWord = trim($matches[1]);

			if (in_array(strtolower($prefixWord), self::TYPE_PREFIXES, true))
			{
				return [trim($matches[2]), $prefixWord];
			}
		}

		return [trim($name), null];
	}

	/**
	 * The component's own repo - always tracked (see getCatalog()'s special
	 * handling of this row: pinned to the top, no Uninstall shown), so it
	 * shows up whether or not it's been tagged with the discovery topic on
	 * GitHub.
	 */
	private const SELF_OWNER_REPO = 'FGcodework/com_fgextensionmanager';

	/**
	 * Finds every repo tagged with the configured GitHub topic and normalizes
	 * it into a map keyed by "owner/repo". Always includes the component's
	 * own repo too (added last, so discovery's own entry - e.g. with a
	 * correctly-detected default_branch - wins if it's already tagged).
	 *
	 * @return  array<string, object>  owner/repo => {branch, path, label}
	 */
	private static function buildRepoRows(object $params, int $timeout, int $cacheMinutes, bool $refresh): array
	{
		$rows = [];

		$discoverOwner = trim((string) $params->get('discover_owner', 'FGcodework')) ?: 'FGcodework';
		$discoverTopic = trim((string) $params->get('discover_topic', 'fg-joomla-extension')) ?: 'fg-joomla-extension';

		$discoverError = null;
		$discovered    = self::discoverRepos($discoverOwner, $discoverTopic, $timeout, $cacheMinutes, $refresh, $discoverError);

		foreach ($discovered as $repo)
		{
			$ownerRepo = self::normalizeOwnerRepo((string) ($repo->full_name ?? ''));

			if ($ownerRepo === '')
			{
				continue;
			}

			$rows[$ownerRepo] = (object) [
				'branch' => trim((string) ($repo->default_branch ?? '')) ?: 'master',
				'path'   => 'updates.xml',
				'label'  => '',
			];
		}

		if (!isset($rows[self::SELF_OWNER_REPO]))
		{
			$rows[self::SELF_OWNER_REPO] = (object) [
				'branch' => 'master',
				'path'   => 'updates.xml',
				'label'  => '',
			];
		}

		return $rows;
	}

	/**
	 * Finds repos owned by $owner that carry the GitHub topic $topic, via the
	 * GitHub Search API (no auth token needed for the modest, cached call
	 * volume here). Pages through results if there are more than fit on one
	 * page (100 per page is the API's own max) - low priority today (his
	 * account currently has 15 repos total, nowhere near even one page) but
	 * cheap to get right rather than silently dropping anything past #100
	 * later. Capped at MAX_DISCOVERY_PAGES as a sanity limit either way.
	 * Returns a list of {full_name, default_branch} objects.
	 *
	 * @return  object[]
	 */
	private const MAX_DISCOVERY_PAGES = 10;

	private static function discoverRepos(string $owner, string $topic, int $timeout, int $cacheMinutes, bool $refresh, ?string &$error): array
	{
		$baseUrl   = 'https://api.github.com/search/repositories?per_page=100&q=' . rawurlencode('user:' . $owner . ' topic:' . $topic);
		$cacheDir  = JPATH_CACHE . '/com_fgextensionmanager';
		$cacheFile = $cacheDir . '/discover_' . md5($baseUrl) . '.json';

		if (!$refresh && $cacheMinutes > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMinutes * 60)
		{
			$cached = json_decode((string) @file_get_contents($cacheFile));

			if (is_array($cached))
			{
				return $cached;
			}
		}

		$headers = [
			'User-Agent: FG-Extension-Manager/' . (defined('JVERSION') ? JVERSION : '1.0'),
			'Accept: application/vnd.github+json',
			'X-GitHub-Api-Version: 2022-11-28',
		];

		$items      = [];
		$totalCount = null;

		for ($page = 1; $page <= self::MAX_DISCOVERY_PAGES; $page++)
		{
			$body = self::curlFetch($baseUrl . '&page=' . $page, $timeout, $error, $headers);

			if ($body === null)
			{
				// A later page failing mid-pagination still keeps whatever
				// earlier pages already returned, rather than discarding them.
				break;
			}

			$decoded = json_decode($body);

			if (!is_object($decoded) || !isset($decoded->items) || !is_array($decoded->items))
			{
				if ($page === 1)
				{
					$error = 'unexpected GitHub API response';
				}

				break;
			}

			$totalCount ??= (int) ($decoded->total_count ?? 0);

			foreach ($decoded->items as $item)
			{
				$items[] = (object) [
					'full_name'      => $item->full_name ?? '',
					'default_branch' => $item->default_branch ?? 'master',
				];
			}

			// Stop once we have everything GitHub reported, or this page
			// came back short (meaning there's nothing more to page through).
			if (count($decoded->items) < 100 || count($items) >= $totalCount)
			{
				break;
			}
		}

		if (empty($items))
		{
			// Nothing fetched live at all - fall back to a stale cache
			// rather than showing nothing.
			$staleContent = self::fallbackToStaleCache($cacheFile);

			if ($staleContent !== null)
			{
				$cached = json_decode($staleContent);

				if (is_array($cached))
				{
					return $cached;
				}
			}

			return [];
		}

		if (!is_dir($cacheDir))
		{
			try
			{
				Folder::create($cacheDir);
			}
			catch (\Throwable $e)
			{
				// Non-fatal: file_put_contents below will just fail silently too.
			}
		}

		self::atomicCacheWrite($cacheFile, json_encode($items));

		return $items;
	}

	/**
	 * Whether a string is safe to render in an href attribute: a well-formed
	 * URL using the https:// scheme specifically. htmlspecialchars() alone
	 * does not neutralize a "javascript:" (or other non-http) URI scheme in
	 * an href - it isn't a URL validator, just an HTML-attribute escaper -
	 * so anything from a remote feed needs this check before it's trusted.
	 */
	private static function isSafeExternalUrl(string $url): bool
	{
		if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL))
		{
			return false;
		}

		return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
	}

	/**
	 * The only hosts GitHub actually serves a repo's release assets, raw
	 * files, or auto-generated archives from. A downloadurl pointing
	 * anywhere else - even if it passes isSafeExternalUrl() - isn't
	 * something his own FG builds would ever legitimately use.
	 */
	private const ALLOWED_DOWNLOAD_HOSTS = [
		'github.com',
		'raw.githubusercontent.com',
		'objects.githubusercontent.com',
		'codeload.github.com',
	];

	/**
	 * Whether a URL is safe to hand to InstallerHelper::downloadPackage() -
	 * a server-side fetch, so this is an SSRF boundary, not just an XSS one.
	 * Requires https:// (via isSafeExternalUrl()) AND a host from the
	 * GitHub-only allowlist above.
	 */
	private static function isSafeDownloadUrl(string $url): bool
	{
		if (!self::isSafeExternalUrl($url))
		{
			return false;
		}

		$host = strtolower((string) parse_url($url, PHP_URL_HOST));

		return in_array($host, self::ALLOWED_DOWNLOAD_HOSTS, true);
	}

	/**
	 * Accepts either a plain "owner/repo" string or a full GitHub URL
	 * (https://github.com/owner/repo, github.com/owner/repo, with or without
	 * a trailing slash or .git suffix) and returns a normalized "owner/repo".
	 * Returns an empty string if nothing usable could be extracted.
	 */
	private static function normalizeOwnerRepo(string $input): string
	{
		$input = trim($input);

		if ($input === '')
		{
			return '';
		}

		if (preg_match('~github\.com[:/]+([^/\s]+)/([^/\s#?]+?)(?:\.git)?/?(?:[#?].*)?$~i', $input, $matches))
		{
			return $matches[1] . '/' . $matches[2];
		}

		$input = trim($input, '/');
		$input = preg_replace('/\.git$/i', '', $input);

		return substr_count($input, '/') === 1 ? $input : '';
	}

	/**
	 * Download (with a simple time-based file cache) the raw updates.xml content.
	 * On failure, $error is set to a short human-readable reason.
	 */
	private static function fetchRaw(string $url, int $timeout, int $cacheMinutes, bool $refresh, ?string &$error = null): ?string
	{
		$cacheDir  = JPATH_CACHE . '/com_fgextensionmanager';
		$cacheFile = $cacheDir . '/' . md5($url) . '.xml';

		if (!$refresh && $cacheMinutes > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMinutes * 60)
		{
			$content = @file_get_contents($cacheFile);

			if ($content !== false && $content !== '')
			{
				return $content;
			}
		}

		$content = self::curlFetch($url, $timeout, $error);

		if ($content === null)
		{
			return self::fallbackToStaleCache($cacheFile);
		}

		if (!is_dir($cacheDir))
		{
			try
			{
				Folder::create($cacheDir);
			}
			catch (\Throwable $e)
			{
				// Non-fatal: file_put_contents below will just fail silently too.
			}
		}

		self::atomicCacheWrite($cacheFile, $content);

		return $content;
	}

	/**
	 * Fetches a URL with a direct curl call rather than Joomla's own HTTP
	 * abstraction. Bypasses whatever transport Joomla would otherwise pick on
	 * this host (curl, stream, socket) - CURLOPT_ENCODING => '' asks curl to
	 * negotiate and transparently decode gzip/deflate/br itself, and
	 * CURLOPT_FOLLOWLOCATION follows redirects, both of which a
	 * misconfigured/older transport can silently get wrong and return a
	 * "200 OK" with an empty body, as seen here.
	 */
	private static function curlFetch(string $url, int $timeout, ?string &$error, ?array $headers = null): ?string
	{
		if (!function_exists('curl_init'))
		{
			$error = 'PHP curl extension is not available on this server';

			return null;
		}

		$headers ??= [
			'User-Agent: FG-Extension-Manager/' . (defined('JVERSION') ? JVERSION : '1.0'),
			'Accept: application/xml, text/xml, */*',
		];

		$ch = curl_init($url);

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => $timeout,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_ENCODING       => '',
			CURLOPT_HTTPHEADER     => $headers,
		]);

		$body      = curl_exec($ch);
		$httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErrno = curl_errno($ch);
		$curlError = curl_error($ch);
		$totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
		curl_close($ch);

		if ($curlErrno !== 0)
		{
			$error = 'curl error ' . $curlErrno . ': ' . $curlError;

			return null;
		}

		if ($httpCode !== 200)
		{
			$error = 'HTTP ' . $httpCode;

			return null;
		}

		if ($body === false || $body === '')
		{
			$error = sprintf('curl returned an empty body (HTTP %d, %.2fs)', $httpCode, (float) $totalTime);

			return null;
		}

		return $body;
	}

	/**
	 * Writes content to a cache file atomically: write to a temp file in the
	 * same directory, then rename() it into place. rename() is atomic on the
	 * same filesystem on every platform Joomla runs on, so a concurrent
	 * reader - another admin's page load, or a parallel curl_multi batch
	 * from a different request happening at the same time - always sees
	 * either the complete old file or the complete new one, never a
	 * half-written one. Failures (permissions, disk full, read-only
	 * filesystem, ...) are logged via Joomla's Log class instead of
	 * silently disappearing behind @ - still non-fatal either way, since a
	 * failed cache write just means the next request fetches live again.
	 */
	private static function atomicCacheWrite(string $path, string $content): void
	{
		$tmpPath = $path . '.' . getmypid() . '.' . uniqid('', true) . '.tmp';

		$bytesWritten = @file_put_contents($tmpPath, $content, LOCK_EX);

		if ($bytesWritten === false || $bytesWritten !== strlen($content))
		{
			@unlink($tmpPath);
			Log::add('FG Extension Manager: failed writing cache file ' . $path, Log::WARNING, 'com_fgextensionmanager');

			return;
		}

		if (!@rename($tmpPath, $path))
		{
			@unlink($tmpPath);
			Log::add('FG Extension Manager: failed renaming cache temp file into place: ' . $path, Log::WARNING, 'com_fgextensionmanager');
		}
	}

	private static function fallbackToStaleCache(string $cacheFile): ?string
	{
		if (!is_file($cacheFile))
		{
			return null;
		}

		$content = @file_get_contents($cacheFile);

		if ($content === false || $content === '')
		{
			return null;
		}

		self::noteStale(filemtime($cacheFile) ?: time());

		return $content;
	}

	/**
	 * Parse a standard Joomla updates.xml string into a flat list of <update> entries.
	 *
	 * @return  object[]
	 */
	private const MAX_UPDATES_XML_BYTES = 2 * 1024 * 1024; // 2 MB - an update feed is a few KB in practice.

	private static function parseUpdatesXml(string $xmlString): array
	{
		// Defense-in-depth against a malicious/broken remote feed: reject
		// anything absurdly large before even attempting to parse it, and
		// disable libxml's own network access (LIBXML_NONET) so a crafted
		// external entity/XInclude in the XML itself can't make the PHP
		// process issue its own outbound (or internal-network) request
		// while parsing - the same class of concern as the download_url
		// SSRF allowlist, just at the parser level instead of the URL level.
		if (strlen($xmlString) > self::MAX_UPDATES_XML_BYTES)
		{
			return [];
		}

		// Defensive: strip a leading UTF-8 BOM some editors/GitHub uploads leave in place.
		$xmlString = preg_replace('/^\xEF\xBB\xBF/', '', $xmlString);

		$previous = libxml_use_internal_errors(true);
		$xml      = simplexml_load_string($xmlString, \SimpleXMLElement::class, LIBXML_NONET);
		libxml_use_internal_errors($previous);

		if ($xml === false || !isset($xml->update))
		{
			return [];
		}

		$entries = [];

		foreach ($xml->update as $update)
		{
			$downloadUrl = '';

			if (isset($update->downloads->downloadurl))
			{
				foreach ($update->downloads->downloadurl as $downloadNode)
				{
					$value = trim((string) $downloadNode);
					$type  = (string) ($downloadNode['type'] ?? 'full');

					if ($value === '')
					{
						continue;
					}

					// Prefer a "full" package; otherwise take the first usable URL found.
					if ($type === 'full' || $downloadUrl === '')
					{
						$downloadUrl = $value;
					}
				}
			}

			// downloadurl is a server-side fetch target (InstallerHelper::downloadPackage()
			// runs on the Joomla server, not the admin's browser) - a compromised or
			// malicious feed pointing it at an internal host would be an SSRF vector.
			// Since every legitimate build genuinely comes from his own GitHub account,
			// restrict it to GitHub's actual asset-serving hosts rather than just requiring
			// https:// - an entry that fails this is treated the same as one with no
			// download URL at all (skipped below).
			if (!self::isSafeDownloadUrl($downloadUrl))
			{
				$downloadUrl = '';
			}

			$version = trim((string) ($update->version ?? ''));
			$element = trim((string) ($update->element ?? ''));
			$type    = trim((string) ($update->type ?? ''));

			if ($downloadUrl === '' || $version === '' || $element === '' || $type === '')
			{
				continue;
			}

			$targetPlatform     = '.*';
			$targetPlatformName = 'joomla';

			if (isset($update->targetplatform))
			{
				$targetPlatform     = (string) ($update->targetplatform['version'] ?? '.*');
				$targetPlatformName = (string) ($update->targetplatform['name'] ?? 'joomla');
			}

			// Only accept a well-formed https:// URL here - this is the trust
			// boundary for remote data, so a scheme like "javascript:" (which
			// htmlspecialchars() alone would NOT neutralize in an href
			// attribute) gets rejected once, here, rather than relying on
			// every future render site to remember to check it.
			$changelogUrl = trim((string) ($update->changelogurl ?? ''));
			$changelogUrl = self::isSafeExternalUrl($changelogUrl) ? $changelogUrl : '';

			$entries[] = (object) [
				'name'                => trim((string) ($update->name ?? $element)) ?: $element,
				'description'         => trim((string) ($update->description ?? '')),
				'element'             => $element,
				'type'                => strtolower($type),
				'folder'              => trim((string) ($update->folder ?? '')),
				'client'              => strtolower(trim((string) ($update->client ?? 'site'))),
				'version'             => $version,
				'download_url'        => $downloadUrl,
				'changelog_url'       => $changelogUrl,
				'sha256'              => strtolower(trim((string) ($update->sha256 ?? ''))),
				'sha384'              => strtolower(trim((string) ($update->sha384 ?? ''))),
				'sha512'              => strtolower(trim((string) ($update->sha512 ?? ''))),
				'targetplatform'      => $targetPlatform,
				'targetplatform_name' => $targetPlatformName !== '' ? $targetPlatformName : 'joomla',
				'php_minimum'         => trim((string) ($update->php_minimum ?? '')),
			];
		}

		return $entries;
	}
}
