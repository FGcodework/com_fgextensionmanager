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

			if (!self::targetplatformMatches($entry->targetplatform, $jVersion))
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
	 * A repo's `<targetplatform version="...">` is a regex, but it comes
	 * from a fetched updates.xml - a remote source, less trusted than our
	 * own code - and gets interpolated directly into a PCRE pattern with no
	 * validation. A crafted pathological pattern (catastrophic
	 * backtracking, e.g. nested quantifiers like `(x+)+`) could make a
	 * single preg_match() burn real CPU time. PHP's own
	 * pcre.backtrack_limit/pcre.recursion_limit already bound this
	 * (matches don't hang forever), but their defaults are sized for
	 * legitimate, complex patterns - far more headroom than a
	 * three-numbers-and-some-brackets version pattern ever needs. Reject
	 * absurdly long patterns outright, and temporarily lower both limits
	 * for this one evaluation only, restoring them straight after - bounds
	 * the worst case tightly without affecting anything else in the request.
	 */
	private const TARGETPLATFORM_MAX_LENGTH = 200;

	private static function targetplatformMatches(string $targetplatform, string $jVersion): bool
	{
		if ($targetplatform === '' || strlen($targetplatform) > self::TARGETPLATFORM_MAX_LENGTH)
		{
			return false;
		}

		$regex = str_replace('/', '\/', $targetplatform);

		$previousBacktrack  = ini_get('pcre.backtrack_limit');
		$previousRecursion  = ini_get('pcre.recursion_limit');

		ini_set('pcre.backtrack_limit', '20000');
		ini_set('pcre.recursion_limit', '2000');

		$result = @preg_match('/^' . $regex . '/', $jVersion);

		ini_set('pcre.backtrack_limit', $previousBacktrack);
		ini_set('pcre.recursion_limit', $previousRecursion);

		return $result === 1;
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
