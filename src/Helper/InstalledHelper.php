<?php
/**
 * @package     FG.Administrator
 * @subpackage  com_fgextensionmanager
 *
 * @copyright   Copyright (C) 2026 Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace FG\Component\FgExtensionManager\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * Looks up whether a given update-entry (component/module/plugin/package) is
 * already installed, by querying the core #__extensions table directly.
 */
class InstalledHelper
{
	/**
	 * @param   object  $entry  Normalized update entry (type, element, folder, client, version).
	 *
	 * @return  object|null  {extension_id, version, enabled, protected} or null if not installed.
	 */
	public static function find(object $entry): ?object
	{
		$db    = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName(['extension_id', 'manifest_cache', 'enabled', 'protected']))
			->from($db->quoteName('#__extensions'));

		$resolvedElement = $entry->element;

		switch ($entry->type)
		{
			case 'component':
				$resolvedElement = str_starts_with($entry->element, 'com_') ? $entry->element : 'com_' . $entry->element;
				$query->where($db->quoteName('type') . ' = ' . $db->quote('component'))
					->where($db->quoteName('element') . ' = ' . $db->quote($resolvedElement));
				break;

			case 'module':
				$resolvedElement = str_starts_with($entry->element, 'mod_') ? $entry->element : 'mod_' . $entry->element;
				$clientId        = $entry->client === 'administrator' ? 1 : 0;
				$query->where($db->quoteName('type') . ' = ' . $db->quote('module'))
					->where($db->quoteName('element') . ' = ' . $db->quote($resolvedElement))
					->where($db->quoteName('client_id') . ' = ' . (int) $clientId);
				break;

			case 'plugin':
				// #__extensions stores the BARE plugin element (no "plg_<folder>_" prefix).
				$query->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
					->where($db->quoteName('element') . ' = ' . $db->quote($entry->element))
					->where($db->quoteName('folder') . ' = ' . $db->quote($entry->folder));
				break;

			case 'package':
				$resolvedElement = str_starts_with($entry->element, 'pkg_') ? $entry->element : 'pkg_' . $entry->element;
				$query->where($db->quoteName('type') . ' = ' . $db->quote('package'))
					->where($db->quoteName('element') . ' = ' . $db->quote($resolvedElement));
				break;

			default:
				return null;
		}

		$db->setQuery($query);
		$row = $db->loadObject();

		if (!$row)
		{
			return null;
		}

		$version = null;

		if (!empty($row->manifest_cache))
		{
			$cache   = json_decode($row->manifest_cache, true);
			$version = $cache['version'] ?? null;
			$version = is_string($version) && trim($version) !== '' ? trim($version) : null;
		}

		// manifest_cache is the normal, fast path; only touch the filesystem
		// when it's missing or corrupted, so an installed extension is never
		// wrongly shown as "up to date" just because its cached version
		// couldn't be read.
		if ($version === null)
		{
			$version = self::versionFromManifestFile($entry->type, $resolvedElement, $entry->folder);
		}

		return (object) [
			'extension_id' => (int) $row->extension_id,
			'version'      => $version,
			'enabled'      => (bool) $row->enabled,
			'protected'    => (bool) $row->protected,
		];
	}

	/**
	 * Enables or disables an already-installed extension, mirroring what
	 * Joomla core's own Extensions: Manage screen does (confirmed via
	 * InstallerModelManage::publish() - a direct update of #__extensions.enabled,
	 * with protected extensions left untouchable exactly as Joomla core also
	 * refuses to toggle those). No core events fire for this particular
	 * action in Joomla's own implementation either, so none are added here.
	 *
	 * @return  array{0: bool, 1: string}  [success, message]
	 */
	public static function setEnabled(int $extensionId, bool $enabled): array
	{
		$db    = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName('protected'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('extension_id') . ' = ' . (int) $extensionId);
		$db->setQuery($query);
		$isProtected = (bool) $db->loadResult();

		if ($isProtected)
		{
			return [false, Text::_('COM_FGEXTENSIONMANAGER_ERROR_PROTECTED')];
		}

		$update = $db->getQuery(true)
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('enabled') . ' = ' . (int) $enabled)
			->where($db->quoteName('extension_id') . ' = ' . (int) $extensionId);
		$db->setQuery($update);

		try
		{
			$db->execute();
		}
		catch (\Throwable $e)
		{
			return [false, $e->getMessage()];
		}

		return [true, $enabled
			? Text::_('COM_FGEXTENSIONMANAGER_ENABLED_SUCCESS')
			: Text::_('COM_FGEXTENSIONMANAGER_DISABLED_SUCCESS')];
	}

	/**
	 * Reads <version> directly from the installed extension's own manifest
	 * XML on disk, for the two types whose file location convention is
	 * unambiguous. NOT implemented for module/package - their manifest path
	 * depends on details (site vs. administrator module storage; the
	 * package manifest filename convention) not worth guessing at and
	 * risking reading the wrong file; those simply keep the existing
	 * manifest_cache-only behaviour.
	 */
	private static function versionFromManifestFile(string $type, string $element, string $folder): ?string
	{
		switch ($type)
		{
			case 'component':
				// Convention confirmed against this very component's own
				// manifest (administrator/components/com_fgextensionmanager/
				// fgextensionmanager.xml - filename is the element with its
				// "com_" prefix stripped), and against Joomla core's
				// com_actionlogs the same way.
				$shortName = preg_replace('/^com_/', '', $element);
				$path      = JPATH_ADMINISTRATOR . '/components/' . $element . '/' . $shortName . '.xml';
				break;

			case 'plugin':
				// plugins/<folder>/<element>/<element>.xml - standard, well
				// established Joomla convention for every plugin group.
				$path = JPATH_PLUGINS . '/' . $folder . '/' . $element . '/' . $element . '.xml';
				break;

			default:
				return null;
		}

		if (!is_file($path))
		{
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		$xml      = @simplexml_load_file($path);
		libxml_use_internal_errors($previous);

		if ($xml === false || !isset($xml->version))
		{
			return null;
		}

		$version = trim((string) $xml->version);

		return $version !== '' ? $version : null;
	}
}
