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
		$result = self::findBatch(['_single' => $entry]);

		return $result['_single'] ?? null;
	}

	/**
	 * Batched version of find(): looks up installed status for MANY entries
	 * in a SINGLE query instead of one query per entry - the previous
	 * per-item find() call in RepoHelper::getCatalog()'s loop meant one
	 * query per tracked repo (13 for his current 12 repos + self), an N+1
	 * pattern that scales with the catalog size for no real reason, since
	 * every lookup happens in the same request anyway.
	 *
	 * @param   array<string, object>  $entries  Normalized update entries, keyed however the caller likes - the same keys come back in the result.
	 *
	 * @return  array<string, object>  Only keys that ARE installed are present, each the same shape find() returns.
	 */
	public static function findBatch(array $entries): array
	{
		if (empty($entries))
		{
			return [];
		}

		$db    = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName(['type', 'element', 'folder', 'client_id', 'extension_id', 'manifest_cache', 'enabled', 'protected']))
			->from($db->quoteName('#__extensions'));

		$resolved   = [];
		$conditions = [];

		foreach ($entries as $key => $entry)
		{
			$info = self::resolveElement($entry);

			if ($info === null)
			{
				continue;
			}

			$resolved[$key] = $info;

			$group = [
				$db->quoteName('type') . ' = ' . $db->quote($info['type']),
				$db->quoteName('element') . ' = ' . $db->quote($info['element']),
			];

			if ($info['type'] === 'plugin')
			{
				$group[] = $db->quoteName('folder') . ' = ' . $db->quote($entry->folder);
			}
			elseif ($info['type'] === 'module')
			{
				$group[] = $db->quoteName('client_id') . ' = ' . (int) $info['client_id'];
			}

			$conditions[] = '(' . implode(' AND ', $group) . ')';
		}

		if (empty($conditions))
		{
			return [];
		}

		// The same (type, element[, folder/client_id]) combination can
		// legitimately repeat across keys (e.g. if two catalog entries ever
		// resolved to the same installed extension) - deduplicating keeps
		// the WHERE clause from growing pointlessly.
		$query->where('(' . implode(' OR ', array_unique($conditions)) . ')');
		$db->setQuery($query);

		$rows = $db->loadObjectList();

		if (empty($rows))
		{
			return [];
		}

		$results = [];

		foreach ($entries as $key => $entry)
		{
			if (!isset($resolved[$key]))
			{
				continue;
			}

			foreach ($rows as $row)
			{
				if ($row->type !== $resolved[$key]['type'] || $row->element !== $resolved[$key]['element'])
				{
					continue;
				}

				if ($resolved[$key]['type'] === 'plugin' && $row->folder !== $entry->folder)
				{
					continue;
				}

				if ($resolved[$key]['type'] === 'module' && (int) $row->client_id !== $resolved[$key]['client_id'])
				{
					continue;
				}

				$results[$key] = self::buildResult($row, $entry, $resolved[$key]['element']);
				break;
			}
		}

		return $results;
	}

	/**
	 * Normalizes an update entry's element to the fully-prefixed form
	 * #__extensions actually stores, per type - shared by find() and
	 * findBatch() so the two can't drift apart on this logic.
	 *
	 * @return  array{type: string, element: string, client_id?: int}|null
	 */
	private static function resolveElement(object $entry): ?array
	{
		switch ($entry->type)
		{
			case 'component':
				return [
					'type'    => 'component',
					'element' => str_starts_with($entry->element, 'com_') ? $entry->element : 'com_' . $entry->element,
				];

			case 'module':
				return [
					'type'      => 'module',
					'element'   => str_starts_with($entry->element, 'mod_') ? $entry->element : 'mod_' . $entry->element,
					'client_id' => $entry->client === 'administrator' ? 1 : 0,
				];

			case 'plugin':
				// #__extensions stores the BARE plugin element (no "plg_<folder>_" prefix).
				return ['type' => 'plugin', 'element' => $entry->element];

			case 'package':
				return [
					'type'    => 'package',
					'element' => str_starts_with($entry->element, 'pkg_') ? $entry->element : 'pkg_' . $entry->element,
				];

			default:
				return null;
		}
	}

	/**
	 * Builds the {extension_id, version, enabled, protected} result object
	 * from a matched #__extensions row, including the manifest-file
	 * fallback when manifest_cache's version is missing/corrupted.
	 */
	private static function buildResult(object $row, object $entry, string $resolvedElement): object
	{
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

		// Same caches install()/update()/uninstall() already clear (1.6.1
		// et al.) - plugin/module enabled state is exactly what these hold,
		// so skipping this left a just-disabled plugin still "on" from the
		// cache's point of view until it expired or someone cleared it
		// manually.
		InstallHelper::clearExtensionCaches();

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
