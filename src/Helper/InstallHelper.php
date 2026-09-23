<?php
/**
 * @package     FG.Administrator
 * @subpackage  com_fgextensionmanager
 *
 * @copyright   Copyright (C) 2026 Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace FG\Component\FgExtensionManager\Administrator\Helper;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;

defined('_JEXEC') or die;

/**
 * Downloads a package from a URL and installs/updates it using Joomla's own
 * core installer (the same mechanism as Extensions > Install > Install from URL).
 */
class InstallHelper
{
	/**
	 * @param   string       $url        Direct download URL of the install package (zip).
	 * @param   array        $checksums  Optional expected checksums, keyed 'sha256'|'sha384'|
	 *                                   'sha512' (any subset). The strongest one present is
	 *                                   verified; the others are ignored.
	 * @param   boolean      $isUpdate   True to call Installer::update() instead of
	 *                                   install() - matches Joomla core's own
	 *                                   com_installer UpdateModel::update() exactly,
	 *                                   which fires onExtensionBefore/AfterUpdate
	 *                                   (and the install script's update() method)
	 *                                   instead of the Before/AfterInstall equivalents.
	 *
	 * @return  array{0: bool, 1: string}  [success, message]
	 */
	public static function installFromUrl(string $url, array $checksums = [], bool $isUpdate = false): array
	{
		$app = Factory::getApplication();

		$app->getLanguage()->load('com_installer', JPATH_ADMINISTRATOR);
		PluginHelper::importPlugin('installer');

		$packageFile = InstallerHelper::downloadPackage($url);

		if (!$packageFile)
		{
			return [false, Text::_('COM_FGEXTENSIONMANAGER_ERROR_DOWNLOAD_FAILED')];
		}

		$tmpPath          = $app->get('tmp_path');
		$fullPackagePath  = rtrim($tmpPath, '/\\') . '/' . $packageFile;

		// Strongest available algorithm wins; the others (if also present) are ignored.
		foreach (['sha512', 'sha384', 'sha256'] as $algo)
		{
			$expected = $checksums[$algo] ?? '';

			if ($expected === '')
			{
				continue;
			}

			$actual = @hash_file($algo, $fullPackagePath);

			if (!$actual || strtolower($actual) !== strtolower($expected))
			{
				@unlink($fullPackagePath);

				return [false, Text::_('COM_FGEXTENSIONMANAGER_ERROR_CHECKSUM')];
			}

			break;
		}

		$package = InstallerHelper::unpack($fullPackagePath, true);

		if (empty($package['type']))
		{
			InstallerHelper::cleanupInstall(
				$package['packagefile'] ?? $fullPackagePath,
				$package['extractdir'] ?? ''
			);

			return [false, Text::_('JLIB_INSTALLER_ABORT_DETECTMANIFEST')];
		}

		// Clear any pre-existing, unrelated messages first - getMessageQueue()
		// returns the WHOLE queue by default (it doesn't clear unless asked),
		// so a leftover message from earlier in this request (or a stale one
		// still sitting in session from a previous request) would otherwise
		// get misread as coming from this install/update call.
		$app->getMessageQueue(true);

		// A fresh instance, not Installer::getInstance() - confirmed via
		// Joomla's own issue tracker (joomla-cms#41087) that the singleton
		// leaks state (manifestClass in particular) between extensions when
		// looping updates/installs in the same request - exactly what
		// extensions.updateAll() does. Core's own recommended fix is to stop
		// using getInstance() and create a fresh one each time, "just like
		// PackageAdapter does".
		$installer = new Installer();
		$success   = $isUpdate
			? (bool) $installer->update($package['dir'])
			: (bool) $installer->install($package['dir']);

		$messages = [];

		// clear = true: also empties the queue as we read it, so these
		// messages don't get shown a second time by Joomla's own message
		// renderer on the next page load.
		foreach ($app->getMessageQueue(true) as $queued)
		{
			if (($queued['type'] ?? '') === 'error')
			{
				$success = false;
			}

			if (!empty($queued['message']))
			{
				$messages[] = $queued['message'];
			}
		}

		InstallerHelper::cleanupInstall($package['packagefile'], $package['extractdir']);

		self::clearExtensionCaches();

		if (empty($messages))
		{
			$messages[] = $success
				? Text::_('COM_FGEXTENSIONMANAGER_INSTALL_SUCCESS')
				: Text::_('COM_FGEXTENSIONMANAGER_INSTALL_ERROR');
		}

		return [$success, implode(' ', $messages)];
	}

	/**
	 * @param   string   $type         Extension type as stored in #__extensions.type
	 *                                 (component, module, plugin, package).
	 * @param   integer  $extensionId  #__extensions.extension_id of the row to remove.
	 *
	 * @return  array{0: bool, 1: string}  [success, message]
	 */
	public static function uninstallExtension(string $type, int $extensionId): array
	{
		$app = Factory::getApplication();

		$app->getLanguage()->load('com_installer', JPATH_ADMINISTRATOR);

		$app->getMessageQueue(true);

		// Same fresh-instance reasoning as installFromUrl() above.
		$installer = new Installer();
		$success   = (bool) $installer->uninstall($type, $extensionId);

		$messages = [];

		foreach ($app->getMessageQueue(true) as $queued)
		{
			if (($queued['type'] ?? '') === 'error')
			{
				$success = false;
			}

			if (!empty($queued['message']))
			{
				$messages[] = $queued['message'];
			}
		}

		self::clearExtensionCaches();

		if (empty($messages))
		{
			$messages[] = $success
				? Text::_('COM_FGEXTENSIONMANAGER_UNINSTALL_SUCCESS')
				: Text::_('COM_FGEXTENSIONMANAGER_UNINSTALL_ERROR');
		}

		return [$success, implode(' ', $messages)];
	}

	/**
	 * Clears the cache groups Joomla itself reads plugin/module enabled
	 * state from, so a change takes effect immediately instead of waiting
	 * for the cache to expire or a manual "Clear Cache".
	 */
	public static function clearExtensionCaches(): void
	{
		try
		{
			$factory = Factory::getContainer()->get(CacheControllerFactoryInterface::class);

			foreach (['_system', 'com_modules', 'com_plugins', 'mod_menu'] as $group)
			{
				$factory->createCacheController('callback', ['defaultgroup' => $group])->clean();
			}
		}
		catch (\Throwable $e)
		{
			// Non-fatal: a manual "Clear Cache" in the admin will still fix this.
		}
	}
}
