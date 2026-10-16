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
 * Small string helpers that use mbstring when it is available and fall back to
 * plain PHP when it is not. Joomla 5.4+/6.x ship a polyfill for mb_*, but the
 * component officially supports Joomla 5.0+, so the template must not depend
 * on it - without mbstring the worst case here is a less exact upper/lower
 * casing of accented letters, never a fatal error.
 */
final class TextHelper
{
	public static function lower(string $text): string
	{
		return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
	}

	public static function upper(string $text): string
	{
		return function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
	}

	/**
	 * First character of $text (a whole UTF-8 character, not a single byte).
	 */
	public static function firstChar(string $text): string
	{
		if (function_exists('mb_substr'))
		{
			return mb_substr($text, 0, 1, 'UTF-8');
		}

		return preg_match('/^./su', $text, $m) === 1 ? $m[0] : substr($text, 0, 1);
	}

	/**
	 * Upper-case first letter used for the logo placeholder tile; a leading
	 * "FG" brand prefix is skipped so "FG Backend LangSwitcher" gives "B".
	 */
	public static function initial(string $name): string
	{
		$stripped = trim((string) preg_replace('/^FG[\s_-]*/i', '', trim($name)));

		return self::upper(self::firstChar($stripped !== '' ? $stripped : '?'));
	}

	/**
	 * Shortens $text to at most $max characters, adding an ellipsis when cut.
	 */
	public static function truncate(string $text, int $max): string
	{
		if (function_exists('mb_strlen'))
		{
			return mb_strlen($text, 'UTF-8') > $max ? rtrim(mb_substr($text, 0, $max, 'UTF-8')) . '…' : $text;
		}

		if (preg_match('/^.{0,' . $max . '}/su', $text, $m) === 1 && strlen($m[0]) < strlen($text))
		{
			return rtrim($m[0]) . '…';
		}

		return $text;
	}
}
