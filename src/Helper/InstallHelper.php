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
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Downloads a package from a URL and installs/updates it using Joomla's own
 * core installer (the same mechanism as Extensions > Install > Install from URL).
 */
class InstallHelper
{
	/** Largest package accepted (bytes). */
	private const MAX_PACKAGE_BYTES = 134217728;

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
	 * @param   array        $expected   Identity the package must have, from expectedFromItem()
	 *                                   ('type', 'element', 'folder', 'client'). Empty = no check.
	 *
	 * @return  array{0: bool, 1: string}  [success, message]
	 */
	public static function installFromUrl(string $url, array $checksums = [], bool $isUpdate = false, array $expected = []): array
	{
		// $ctx lets the catch block below clean up whatever the failed run had
		// already created (downloaded ZIP, extracted folder) - an exception
		// from the installer would otherwise leave both in the tmp folder, and
		// - worse, inside Update All - abort the loop for every later item.
		$ctx = ['file' => '', 'package' => []];

		try
		{
			return self::runInstall($url, $checksums, $isUpdate, $expected, $ctx);
		}
		catch (\Throwable $e)
		{
			Log::add('FG Package Manager: install/update failed with ' . get_class($e) . ': ' . $e->getMessage(), Log::ERROR, 'com_fgextensionmanager');

			try
			{
				$package = $ctx['package'];
				$file    = (string) ($package['packagefile'] ?? $ctx['file']);

				if ($file !== '' || !empty($package['extractdir']))
				{
					InstallerHelper::cleanupInstall($file, (string) ($package['extractdir'] ?? ''));
				}
			}
			catch (\Throwable $cleanupError)
			{
				// Best effort only.
			}

			return [false, Text::sprintf('COM_FGEXTENSIONMANAGER_ERROR_EXCEPTION', $e->getMessage())];
		}
	}

	/**
	 * @param   array  $ctx  Filled in as the run progresses (see installFromUrl()).
	 *
	 * @return  array{0: bool, 1: string}
	 */
	private static function runInstall(string $url, array $checksums, bool $isUpdate, array $expected, array &$ctx): array
	{
		$app = Factory::getApplication();

		$app->getLanguage()->load('com_installer', JPATH_ADMINISTRATOR);
		PluginHelper::importPlugin('installer');

		// Strict mode: refuse anything the repo does not publish a checksum for.
		if (empty($checksums) && (int) ComponentHelper::getParams('com_fgextensionmanager')->get('require_checksum', 0) === 1)
		{
			return [false, Text::_('COM_FGEXTENSIONMANAGER_ERROR_CHECKSUM_REQUIRED')];
		}

		// Own downloader instead of InstallerHelper::downloadPackage(): that one
		// follows redirects without re-checking where they lead.
		$downloadError   = null;
		$fullPackagePath = self::downloadPackage($url, (string) $app->get('tmp_path', JPATH_ROOT . '/tmp'), $downloadError);

		if ($fullPackagePath === '')
		{
			return [false, $downloadError ?: Text::_('COM_FGEXTENSIONMANAGER_ERROR_DOWNLOAD_FAILED')];
		}

		$ctx['file'] = $fullPackagePath;
		$verified = false;

		// Strongest available algorithm wins; the others (if also present) are ignored.
		foreach (['sha512', 'sha384', 'sha256'] as $algo)
		{
			$wanted = $checksums[$algo] ?? '';

			if ($wanted === '')
			{
				continue;
			}

			$actual = @hash_file($algo, $fullPackagePath);

			if (!$actual || strtolower($actual) !== strtolower($wanted))
			{
				@unlink($fullPackagePath);

				return [false, Text::_('COM_FGEXTENSIONMANAGER_ERROR_CHECKSUM')];
			}

			$verified = true;

			break;
		}

		$package = InstallerHelper::unpack($fullPackagePath, true);
		$ctx['package'] = is_array($package) ? $package : [];

		if (empty($package['type']))
		{
			InstallerHelper::cleanupInstall(
				$package['packagefile'] ?? $fullPackagePath,
				$package['extractdir'] ?? ''
			);

			return [false, Text::_('JLIB_INSTALLER_ABORT_DETECTMANIFEST')];
		}

		// The extracted manifest must be the extension we asked for - a wrong or
		// swapped ZIP is refused before Joomla's installer sees it.
		$mismatch = self::identityMismatch((string) $package['dir'], $expected);

		if ($mismatch !== null)
		{
			InstallerHelper::cleanupInstall(
				$package['packagefile'] ?? $fullPackagePath,
				$package['extractdir'] ?? ''
			);

			return [false, Text::sprintf('COM_FGEXTENSIONMANAGER_ERROR_PACKAGE_MISMATCH', $mismatch)];
		}

		// Clear any pre-existing, unrelated messages first - getMessageQueue()
		// returns the WHOLE queue by default (it doesn't clear unless asked),
		// so a leftover message from earlier in this request (or a stale one
		// still sitting in session from a previous request) would otherwise
		// get misread as coming from this install/update call.
		$app->getMessageQueue(true);

		$installer = Installer::getInstance();
		$success   = $isUpdate
			? (bool) $installer->update($package['dir'])
			: (bool) $installer->install($package['dir']);

		// Resetting OPcache right after a successful install/update/uninstall,
		// not just here for self-updates: PHP compiles and caches a file's
		// bytecode the first time it's read, and doesn't necessarily notice
		// (depending on the OPcache timestamp-validation settings) that
		// the file on disk changed underneath it later in the same request
		// or on a subsequent one. Confirmed as the actual explanation for
		// every confusing, seemingly-random observation across a long
		// debugging session on his Joomla 6 site: sometimes an OLD version
		// of this very file's compiled code kept running (e.g. still using
		// `new Installer()` after the source had already been reverted back
		// to `getInstance()`), sometimes diagnostic logging added in a newer
		// version silently never ran at all - both symptoms of stale
		// OPcache entries, not the Installer/database logic itself, which
		// static review of his own core files never actually found a
		// problem with. function_exists() guarded since OPcache isn't
		// guaranteed to be enabled everywhere.
		if (function_exists('opcache_reset'))
		{
			opcache_reset();
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

		if ($success)
		{
			self::forgetPendingUpdates($expected);
		}

		if (empty($messages))
		{
			$messages[] = $success
				? Text::_('COM_FGEXTENSIONMANAGER_INSTALL_SUCCESS')
				: Text::_('COM_FGEXTENSIONMANAGER_INSTALL_ERROR');
		}

		// No checksum in the repo's updates.xml: the install went ahead (the
		// ZIP still comes from an allow-listed GitHub host over HTTPS), but say
		// plainly that the package itself could not be verified.
		if ($success && !$verified)
		{
			$messages[] = Text::_('COM_FGEXTENSIONMANAGER_WARNING_UNVERIFIED');
		}

		return [$success, implode(' ', $messages)];
	}

	/**
	 * Identity a package must have, from a catalog item (type / element / folder / client).
	 *
	 * @return  array{type: string, element: string, folder: string, client: string}|array{}
	 */
	public static function expectedFromItem(object $item): array
	{
		$type    = strtolower((string) ($item->type ?? ''));
		$element = strtolower(trim((string) ($item->element ?? '')));
		$prefix  = ['component' => 'com_', 'module' => 'mod_', 'package' => 'pkg_'][$type] ?? null;

		if ($element === '' || !in_array($type, ['component', 'module', 'plugin', 'package'], true))
		{
			return [];
		}

		if ($prefix !== null && !str_starts_with($element, $prefix))
		{
			$element = $prefix . $element;
		}

		return [
			'type'    => $type,
			'element' => $element,
			'folder'  => strtolower(trim((string) ($item->folder ?? ''))),
			'client'  => strtolower(trim((string) ($item->client ?? 'site'))),
		];
	}

	/**
	 * Type and element declared by the manifest in an extracted folder.
	 *
	 * @return  array{type: string, element: string}|null
	 */
	private static function manifestIdentity(string $dir): ?array
	{
		foreach (glob(rtrim($dir, '/\\') . '/*.xml') ?: [] as $file)
		{
			$previous = libxml_use_internal_errors(true);
			$xml      = simplexml_load_file($file, \SimpleXMLElement::class, LIBXML_NONET);
			libxml_clear_errors();
			libxml_use_internal_errors($previous);

			if (!$xml instanceof \SimpleXMLElement || $xml->getName() !== 'extension')
			{
				continue;
			}

			$type    = strtolower((string) $xml['type']);
			$element = '';

			if ($type === 'package')
			{
				$name    = strtolower(trim((string) $xml->packagename));
				$element = $name !== '' ? 'pkg_' . $name : '';
			}
			elseif ($type === 'component')
			{
				$name    = strtolower(trim(trim((string) ($xml->element ?? '')) !== '' ? (string) $xml->element : (string) $xml->name));
				$element = '';

				if ($name !== '' && preg_match('/^[a-z0-9_-]+$/', $name))
				{
					$element = str_starts_with($name, 'com_') ? $name : 'com_' . $name;
				}
			}
			elseif ($type === 'plugin' || $type === 'module')
			{
				$attribute = $type;

				if ($xml->files)
				{
					foreach ($xml->files->children() as $child)
					{
						if ((string) $child[$attribute] !== '')
						{
							$element = strtolower((string) $child[$attribute]);
							break;
						}
					}
				}
			}

			return ['type' => $type, 'element' => $element];
		}

		return null;
	}

	/**
	 * Null when the extracted package is what was expected (or nothing is
	 * expected / the manifest names no element to compare); otherwise a short
	 * "expected X, got Y" text.
	 *
	 * @param   array  $expected  From expectedFromItem().
	 */
	private static function identityMismatch(string $dir, array $expected): ?string
	{
		if (empty($expected))
		{
			return null;
		}

		$identity = self::manifestIdentity($dir);

		if ($identity === null || $identity['element'] === '')
		{
			// Joomla found a manifest it can install, we just could not read an
			// element from it - do not block a legitimate package over that.
			Log::add('FG Extension Manager: could not read the package identity of ' . $expected['element'], Log::WARNING, 'com_fgextensionmanager');

			return null;
		}

		if ($identity['type'] === $expected['type'] && $identity['element'] === $expected['element'])
		{
			return null;
		}

		return sprintf('%s %s <> %s %s', $expected['type'], $expected['element'], $identity['type'], $identity['element']);
	}

	/**
	 * Joomla keeps rows in #__updates until its next check, so an extension we
	 * just updated would still be listed under System > Update. Remove them.
	 *
	 * @param   array  $expected  From expectedFromItem().
	 */
	private static function forgetPendingUpdates(array $expected): void
	{
		if (empty($expected))
		{
			return;
		}

		try
		{
			$db    = Factory::getContainer()->get(DatabaseInterface::class);
			$type  = $expected['type'];
			$el    = $expected['element'];
			$query = $db->getQuery(true)
				->select($db->quoteName('extension_id'))
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('type') . ' = :type')
				->where($db->quoteName('element') . ' = :element')
				->bind(':type', $type)
				->bind(':element', $el);

			if ($type === 'plugin' && $expected['folder'] !== '')
			{
				$folder = $expected['folder'];
				$query->where($db->quoteName('folder') . ' = :folder')->bind(':folder', $folder);
			}

			$id = (int) $db->setQuery($query)->loadResult();

			if ($id > 0)
			{
				$delete = $db->getQuery(true)
					->delete($db->quoteName('#__updates'))
					->where($db->quoteName('extension_id') . ' = :id')
					->bind(':id', $id, ParameterType::INTEGER);
				$db->setQuery($delete)->execute();
			}
		}
		catch (\Throwable $e)
		{
			// Cosmetic only: Joomla drops the stale row at its next update check.
		}
	}

	/**
	 * Downloads the package to the tmp folder, following redirects ITSELF: every
	 * hop must be https and an allowed GitHub host, at most 5 hops, a size cap,
	 * and the body must look like a ZIP. Joomla's own downloader follows a
	 * Location header without re-validating it.
	 *
	 * @param   callable|null  $allow  URL => bool; defaults to RepoHelper::isAllowedDownloadHop().
	 *
	 * @return  string  Full path of the downloaded file, '' on failure ($error says why).
	 */
	private static function downloadPackage(string $url, string $tmpDir, ?string &$error, ?callable $allow = null): string
	{
		$allow ??= [RepoHelper::class, 'isAllowedDownloadHop'];

		if (!function_exists('curl_init'))
		{
			$error = Text::_('COM_FGEXTENSIONMANAGER_ERROR_NO_CURL');

			return '';
		}

		$tmpDir = rtrim($tmpDir, '/\\');
		$file   = $tmpDir . '/fgem_' . bin2hex(random_bytes(6)) . '.zip';
		$caFile = '';

		for ($hop = 0; $hop <= 5; $hop++)
		{
			if (!$allow($url))
			{
				@unlink($file);
				$error = Text::sprintf('COM_FGEXTENSIONMANAGER_ERROR_DOWNLOAD_BLOCKED', (string) parse_url($url, PHP_URL_HOST));

				return '';
			}

			$fh = @fopen($file, 'wb');

			if ($fh === false)
			{
				$error = Text::sprintf('COM_FGEXTENSIONMANAGER_ERROR_TMP_NOT_WRITABLE', $tmpDir);

				return '';
			}

			$written  = 0;
			$tooBig   = false;
			$location = '';
			$ch       = curl_init($url);

			curl_setopt_array($ch, [
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT        => 120,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_HTTPHEADER     => [
					'User-Agent: FG-Extension-Manager/' . (defined('JVERSION') ? JVERSION : '1.0'),
					'Accept: application/octet-stream, application/zip, */*',
				],
				CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$location): int
				{
					if (stripos($line, 'HTTP/') === 0)
					{
						$location = '';
					}
					elseif (stripos($line, 'location:') === 0)
					{
						$location = trim(substr($line, 9));
					}

					return strlen($line);
				},
				// Only a 200 body is kept; redirect/error bodies are discarded.
				CURLOPT_WRITEFUNCTION  => static function ($c, string $data) use ($fh, &$written, &$tooBig): int
				{
					if ((int) curl_getinfo($c, CURLINFO_RESPONSE_CODE) !== 200)
					{
						return strlen($data);
					}

					$written += strlen($data);

					if ($written > self::MAX_PACKAGE_BYTES)
					{
						$tooBig = true;

						return 0;
					}

					return fwrite($fh, $data) === strlen($data) ? strlen($data) : 0;
				},
			] + RepoHelper::networkOptions() + ($caFile !== '' ? [CURLOPT_CAINFO => $caFile] : []));

			curl_exec($ch);

			$errno = curl_errno($ch);
			$msg   = curl_error($ch);
			$code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);
			fclose($fh);

			if ($tooBig)
			{
				@unlink($file);
				$error = Text::_('COM_FGEXTENSIONMANAGER_ERROR_DOWNLOAD_TOO_LARGE');

				return '';
			}

			if (in_array($errno, [60, 77], true) && $caFile === '' && ($caFile = RepoHelper::joomlaCaBundle()) !== '')
			{
				$hop--;

				continue;
			}

			if ($errno !== 0)
			{
				@unlink($file);
				$error = RepoHelper::curlFailure($errno, $msg);

				return '';
			}

			if (in_array($code, [301, 302, 303, 307, 308], true) && $location !== '')
			{
				$next = self::resolveRedirect($url, $location);

				if ($next === '')
				{
					@unlink($file);
					$error = Text::sprintf('COM_FGEXTENSIONMANAGER_ERROR_DOWNLOAD_BLOCKED', $location);

					return '';
				}

				$url = $next;

				continue;
			}

			if ($code !== 200)
			{
				@unlink($file);
				$error = 'HTTP ' . $code;

				return '';
			}

			$head = (string) @file_get_contents($file, false, null, 0, 4);

			if (substr($head, 0, 2) !== 'PK')
			{
				@unlink($file);
				$error = Text::_('COM_FGEXTENSIONMANAGER_ERROR_DOWNLOAD_NOT_ZIP');

				return '';
			}

			return $file;
		}

		@unlink($file);
		$error = Text::_('COM_FGEXTENSIONMANAGER_ERROR_DOWNLOAD_REDIRECTS');

		return '';
	}

	/**
	 * Absolute URL for a redirect's Location header; '' when it cannot be
	 * resolved safely (relative paths, whitespace, backslashes).
	 */
	private static function resolveRedirect(string $from, string $location): string
	{
		if ($location === '' || preg_match('/[\s\\\\]/', $location))
		{
			return '';
		}

		if (preg_match('#^https?://#i', $location))
		{
			return $location;
		}

		if (str_starts_with($location, '//'))
		{
			return 'https:' . $location;
		}

		if (str_starts_with($location, '/'))
		{
			$parts = parse_url($from);

			if (!is_array($parts) || empty($parts['host']))
			{
				return '';
			}

			return 'https://' . $parts['host'] . (!empty($parts['port']) ? ':' . (int) $parts['port'] : '') . $location;
		}

		return '';
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
		try
		{
			return self::runUninstall($type, $extensionId);
		}
		catch (\Throwable $e)
		{
			Log::add('FG Package Manager: uninstall failed with ' . get_class($e) . ': ' . $e->getMessage(), Log::ERROR, 'com_fgextensionmanager');

			return [false, Text::sprintf('COM_FGEXTENSIONMANAGER_ERROR_EXCEPTION', $e->getMessage())];
		}
	}

	/**
	 * @return  array{0: bool, 1: string}
	 */
	private static function runUninstall(string $type, int $extensionId): array
	{
		$app = Factory::getApplication();

		$app->getLanguage()->load('com_installer', JPATH_ADMINISTRATOR);

		$app->getMessageQueue(true);

		// Same reasoning and history as installFromUrl() above - reverted
		// back to the proven-reliable getInstance().
		$installer = Installer::getInstance();
		$success   = (bool) $installer->uninstall($type, $extensionId);

		// Same OPcache reasoning as installFromUrl() above.
		if (function_exists('opcache_reset'))
		{
			opcache_reset();
		}

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
