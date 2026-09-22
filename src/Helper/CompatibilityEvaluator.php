<?php
/**
 * @package     FG.Administrator
 * @subpackage  com_fgextensionmanager
 *
 * @copyright   Copyright (C) 2026 Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace FG\Component\FgExtensionManager\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Decides, given a list of parsed updates.xml <update> entries, which one
 * (if any) is actually usable on the running Joomla + PHP versions.
 *
 * Deliberately pure: no HTTP, no filesystem, no database. Every entry it's
 * given is a plain object with the fields RepoHelper::parseUpdatesXml()
 * produces (targetplatform, targetplatform_name, php_minimum, version, ...).
 * That's what makes this the first piece worth pulling out of RepoHelper on
 * its own - the PHP/Joomla/database compatibility rules are exactly the
 * part most likely to grow more cases over time, and the easiest to
 * unit-test in isolation from everything else RepoHelper does (GitHub
 * discovery, HTTP fetching, caching, XML parsing, #__extensions lookups,
 * display-name normalization, sorting).
 */
class CompatibilityEvaluator
{
	/**
	 * Evaluates every entry against the running Joomla version and PHP version
	 * (mirrors Joomla core's own prefix-anchored targetplatform matching, plus
	 * its <php_minimum> check - see Joomla\CMS\Updater\Update::loadFromXml()).
	 * Returns two entries: the best one that satisfies BOTH checks (what
	 * install/update actually uses), and separately the best one that matches
	 * the Joomla version but fails only the PHP check, so callers can show a
	 * precise "requires PHP X, server has Y" message instead of a generic
	 * "not compatible" when that's the real reason.
	 *
	 * @param   object[]     $entries     Parsed update entries.
	 * @param   string|null  $jVersion    Defaults to the running JVERSION.
	 * @param   string|null  $phpVersion  Defaults to the running PHP_VERSION.
	 *
	 * @return  array{0: object|null, 1: object|null}  [fully matching entry, platform-only-matching entry]
	 */
	public static function evaluate(array $entries, ?string $jVersion = null, ?string $phpVersion = null): array
	{
		$jVersion   ??= defined('JVERSION') ? JVERSION : '0.0.0';
		$phpVersion ??= PHP_VERSION;

		$bestFull         = null;
		$bestPlatformOnly = null;

		foreach ($entries as $entry)
		{
			if (strcasecmp($entry->targetplatform_name, 'joomla') !== 0)
			{
				continue;
			}

			$regex = str_replace('/', '\/', $entry->targetplatform);

			if (@preg_match('/^' . $regex . '/', $jVersion) !== 1)
			{
				continue;
			}

			$phpOk = $entry->php_minimum === '' || version_compare($phpVersion, $entry->php_minimum, '>=');

			if ($phpOk)
			{
				if ($bestFull === null || version_compare($entry->version, $bestFull->version, '>'))
				{
					$bestFull = $entry;
				}
			}
			elseif ($bestPlatformOnly === null || version_compare($entry->version, $bestPlatformOnly->version, '>'))
			{
				$bestPlatformOnly = $entry;
			}
		}

		return [$bestFull, $bestPlatformOnly];
	}

	/**
	 * Of all entries whose targetplatform (name+version) and php_minimum are
	 * satisfied, the one with the highest version. Thin wrapper around
	 * evaluate() for callers that only need the go/no-go answer (e.g.
	 * "should fetchFallbackPaths() stop trying more paths").
	 */
	public static function pickBest(array $entries): ?object
	{
		[$best] = self::evaluate($entries);

		return $best;
	}
}
