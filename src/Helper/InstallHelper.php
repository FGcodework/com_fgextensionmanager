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
use Joomla\CMS\Log\Log;
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

		// Installer::getInstance() (the singleton), not a fresh instance.
		//
		// History: 1.23.0 switched to `new Installer()` based on
		// joomla-cms#41087 (the singleton can leak manifestClass state
		// between extensions when looping updates in the same request).
		// 1.25.3 then added an explicit setDatabase() call after hitting a
		// SEPARATE, confirmed Joomla 6.0 regression (joomla-cms#45653) where
		// a freshly-constructed Installer has no database connection.
		// Neither actually worked in practice: his own stack trace on
		// Joomla 6 showed getDatabase() still failing deep inside
		// getAdapter() even with setDatabase() called first - something
		// about a manually-constructed instance still doesn't get properly
		// wired up internally by the time it reaches that code path, and
		// two attempts on his live site is enough guessing in this
		// direction. getInstance() is the one actually confirmed reliable -
		// this error never happened before 1.23.0 - so reliability wins
		// over the narrower stale-state risk from #41087 (which only
		// matters when updateAll() mixes a plugin and a package update in
		// the same request - not impossible, but far less bad than
		// installer actions being broken outright).
		$installer = Installer::getInstance();

		// TEMPORARY DIAGNOSTIC (1.25.5) - static review of his own
		// Installer.php/DatabaseAwareTrait.php couldn't explain why
		// getDatabase() fails deep inside getAdapter() when
		// getInstance() itself calls setDatabase() right after
		// construction. Checking directly, via reflection, whether that
		// actually took effect on THIS instance before update()/install()
		// runs - removed once this is understood.
		try
		{
			$prop = new \ReflectionProperty($installer, 'databaseAwareTraitDatabase');
			$prop->setAccessible(true);
			$dbValue = $prop->getValue($installer);
			$diagMsg = 'FGEM DIAGNOSTIC: Installer::getInstance() db property = '
				. ($dbValue !== null ? get_class($dbValue) : 'NULL')
				. ' | Factory::getContainer()->has(DatabaseInterface) = '
				. (Factory::getContainer()->has(\Joomla\Database\DatabaseInterface::class) ? 'true' : 'false')
				. ' | spl_object_id(installer) = ' . spl_object_id($installer);
			$app->enqueueMessage($diagMsg, 'warning');
			Log::add($diagMsg, Log::WARNING, 'fgextensionmanager');
		}
		catch (\Throwable $diagException)
		{
			$diagMsg = 'FGEM DIAGNOSTIC: reflection failed: ' . $diagException->getMessage();
			$app->enqueueMessage($diagMsg, 'warning');
			Log::add($diagMsg, Log::WARNING, 'fgextensionmanager');
		}

		try
		{
			$success = $isUpdate
				? (bool) $installer->update($package['dir'])
				: (bool) $installer->install($package['dir']);
		}
		catch (\Throwable $installException)
		{
			$diagMsg = 'FGEM DIAGNOSTIC: update()/install() threw: '
				. get_class($installException) . ': ' . $installException->getMessage()
				. ' at ' . $installException->getFile() . ':' . $installException->getLine();
			Log::add($diagMsg, Log::ERROR, 'fgextensionmanager');
			Log::add($installException->getTraceAsString(), Log::ERROR, 'fgextensionmanager');

			return [false, $diagMsg];
		}

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

		// Same reasoning and history as installFromUrl() above - reverted
		// back to the proven-reliable getInstance().
		$installer = Installer::getInstance();
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
