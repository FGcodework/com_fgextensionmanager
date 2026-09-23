<?php
/**
 * @package     FG.Administrator
 * @subpackage  com_fgextensionmanager
 *
 * @copyright   Copyright (C) 2026 Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace FG\Component\FgExtensionManager\Administrator\Controller;

use FG\Component\FgExtensionManager\Administrator\Helper\InstallHelper;
use FG\Component\FgExtensionManager\Administrator\Helper\InstalledHelper;
use FG\Component\FgExtensionManager\Administrator\Helper\RepoHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/**
 * Handles the Install / Update / Refresh actions for the extension catalog.
 */
class ExtensionsController extends BaseController
{
	private const REDIRECT = 'index.php?option=com_fgextensionmanager&view=extensions';

	/**
	 * Installs a not-yet-installed extension from the catalog.
	 */
	public function install(): void
	{
		$this->process('install');
	}

	/**
	 * Updates an already-installed extension to the available version.
	 * Uses the exact same core installer call as install() - Joomla's own
	 * installer detects the existing extension and updates it in place.
	 */
	public function update(): void
	{
		$this->process('update');
	}

	/**
	 * Forces a fresh fetch of every repo's updates.xml (bypasses the cache).
	 */
	public function refresh(): void
	{
		$this->checkToken();

		if (!$this->hasAccess())
		{
			$this->app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
			$this->setRedirect(Route::_(self::REDIRECT, false));

			return;
		}

		$catalog = RepoHelper::getCatalog(true);

		if ($catalog->stale)
		{
			$when = $catalog->stale_since
				? Factory::getDate($catalog->stale_since)->format('d.m.Y H:i')
				: '?';

			$this->app->enqueueMessage(Text::sprintf('COM_FGEXTENSIONMANAGER_REFRESH_STALE', $when), 'warning');
		}
		else
		{
			$this->app->enqueueMessage(Text::_('COM_FGEXTENSIONMANAGER_REFRESHED'), 'message');
		}

		$this->setRedirect(Route::_(self::REDIRECT, false));
	}

	/**
	 * Uninstalls an already-installed extension. Gated behind core.admin
	 * specifically (not core.manage) since this is destructive.
	 */
	public function uninstall(): void
	{
		$this->checkToken();

		$user = $this->app->getIdentity();

		if (!$user || !$user->authorise('core.admin', 'com_fgextensionmanager'))
		{
			$this->app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
			$this->setRedirect(Route::_(self::REDIRECT, false));

			return;
		}

		$key     = $this->input->getString('key', '');
		$catalog = RepoHelper::getCatalog(false)->items;
		$item    = null;

		foreach ($catalog as $candidate)
		{
			if ($candidate->key === $key)
			{
				$item = $candidate;
				break;
			}
		}

		if (!$item || empty($item->extension_id) || empty($item->type))
		{
			$this->app->enqueueMessage(Text::_('COM_FGEXTENSIONMANAGER_ERROR_NOT_FOUND'), 'error');
			$this->setRedirect(Route::_(self::REDIRECT, false));

			return;
		}

		if (!in_array($item->state, ['installed', 'update_available'], true))
		{
			$this->app->enqueueMessage(Text::_('COM_FGEXTENSIONMANAGER_ERROR_NOT_INSTALLED'), 'warning');
			$this->setRedirect(Route::_(self::REDIRECT, false));

			return;
		}

		if (!empty($item->is_self))
		{
			$this->app->enqueueMessage(Text::_('COM_FGEXTENSIONMANAGER_ERROR_SELF_ACTION'), 'error');
			$this->setRedirect(Route::_(self::REDIRECT, false));

			return;
		}

		[$success, $message] = InstallHelper::uninstallExtension($item->type, (int) $item->extension_id);

		$this->app->enqueueMessage(
			$item->label . ': ' . $message,
			$success ? 'message' : 'error'
		);

		$this->setRedirect(Route::_(self::REDIRECT, false));
	}

	/**
	 * Enables or disables an already-installed extension (mirroring the
	 * green check/red circle toggle on Joomla's own Extensions: Manage
	 * screen). Gated behind the same core.manage/core.admin as install/
	 * update - it's reversible with one more click, not destructive like
	 * uninstall.
	 */
	public function toggle(): void
	{
		$this->checkToken();

		if (!$this->hasAccess())
		{
			$this->app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
			$this->setRedirect(Route::_(self::REDIRECT, false));

			return;
		}

		$key     = $this->input->getString('key', '');
		$catalog = RepoHelper::getCatalog(false)->items;
		$item    = null;

		foreach ($catalog as $candidate)
		{
			if ($candidate->key === $key)
			{
				$item = $candidate;
				break;
			}
		}

		if (!$item || empty($item->extension_id) || !in_array($item->state, ['installed', 'update_available'], true))
		{
			$this->app->enqueueMessage(Text::_('COM_FGEXTENSIONMANAGER_ERROR_NOT_FOUND'), 'error');
			$this->setRedirect(Route::_(self::REDIRECT, false));

			return;
		}

		if (!empty($item->is_self))
		{
			$this->app->enqueueMessage(Text::_('COM_FGEXTENSIONMANAGER_ERROR_SELF_ACTION'), 'error');
			$this->setRedirect(Route::_(self::REDIRECT, false));

			return;
		}

		[$success, $message] = InstalledHelper::setEnabled((int) $item->extension_id, !$item->enabled);

		$this->app->enqueueMessage(
			$item->label . ': ' . $message,
			$success ? 'message' : 'error'
		);

		$this->setRedirect(Route::_(self::REDIRECT, false));
	}

	/**
	 * Updates every extension currently showing "update_available" in one
	 * go. Deliberately does NOT call $this->app->enqueueMessage() between
	 * InstallHelper::installFromUrl() calls - each of those calls clears the
	 * message queue at its own start (see 1.6.1) precisely so a pre-existing
	 * unrelated message can't be misread as its result, which would also
	 * wipe out a message enqueued for an EARLIER extension in this same
	 * loop. Results are collected in plain local arrays instead, and only
	 * turned into (at most two) summary messages once the whole loop is done.
	 */
	public function updateAll(): void
	{
		$this->checkToken();

		if (!$this->hasAccess())
		{
			$this->app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
			$this->setRedirect(Route::_(self::REDIRECT, false));

			return;
		}

		// Defensive: makes sure this run starts from a clean OPcache state
		// too, not just the reset after each install/update in
		// InstallHelper - in case OPcache was left stale by something
		// before this request even started (a plain PHP function; nothing
		// component-specific to import for it).
		if (function_exists('opcache_reset'))
		{
			opcache_reset();
		}

		$catalog  = RepoHelper::getCatalog(false)->items;
		$toUpdate = array_values(array_filter($catalog, static fn ($item) => $item->state === 'update_available'));

		if (empty($toUpdate))
		{
			$this->app->enqueueMessage(Text::_('COM_FGEXTENSIONMANAGER_UPDATE_ALL_NONE'), 'warning');
			$this->setRedirect(Route::_(self::REDIRECT, false));

			return;
		}

		// Self LAST, always - self-updating overwrites the very files this
		// request is currently executing. PHP keeps running classes it's
		// already loaded from memory for the rest of this request either
		// way, but anything not yet loaded (lazily-included files, a
		// not-yet-touched class) would load the NEW version mid-request if
		// self-update happened before the loop finished, mixing old and new
		// code in the same request in a way that's hard to reason about.
		// Sorting self last, then stopping immediately after it (below)
		// rather than relying only on this ordering, keeps that window as
		// small as possible.
		usort($toUpdate, static fn ($a, $b) => (int) ($a->is_self ?? false) <=> (int) ($b->is_self ?? false));

		$succeeded = [];
		$failed    = [];

		foreach ($toUpdate as $item)
		{
			$checksums = array_filter([
				'sha256' => $item->sha256 ?? '',
				'sha384' => $item->sha384 ?? '',
				'sha512' => $item->sha512 ?? '',
			]);

			[$success, $message] = InstallHelper::installFromUrl($item->download_url, $checksums, true);

			if ($success)
			{
				$succeeded[] = $item->label;
			}
			else
			{
				$failed[] = $item->label . ' (' . $message . ')';
			}

			// Stop right here if that was self - own files are now
			// overwritten, so nothing after this point should keep running
			// in the same request, even if (due to some future change) self
			// wasn't actually the last item in the list.
			if (!empty($item->is_self))
			{
				break;
			}
		}

		if ($succeeded)
		{
			$this->app->enqueueMessage(
				Text::sprintf('COM_FGEXTENSIONMANAGER_UPDATE_ALL_SUCCESS', count($succeeded), implode(', ', $succeeded)),
				'message'
			);
		}

		if ($failed)
		{
			$this->app->enqueueMessage(
				Text::sprintf('COM_FGEXTENSIONMANAGER_UPDATE_ALL_FAILED', count($failed), implode(', ', $failed)),
				'error'
			);
		}

		$this->setRedirect(Route::_(self::REDIRECT, false));
	}

	private function process(string $mode): void
	{
		$this->checkToken();

		if (!$this->hasAccess())
		{
			$this->app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
			$this->setRedirect(Route::_(self::REDIRECT, false));

			return;
		}

		$key     = $this->input->getString('key', '');
		$catalog = RepoHelper::getCatalog(false)->items;
		$item    = null;

		foreach ($catalog as $candidate)
		{
			if ($candidate->key === $key)
			{
				$item = $candidate;
				break;
			}
		}

		if (!$item || empty($item->download_url))
		{
			$this->app->enqueueMessage(Text::_('COM_FGEXTENSIONMANAGER_ERROR_NOT_FOUND'), 'error');
			$this->setRedirect(Route::_(self::REDIRECT, false));

			return;
		}

		if ($mode === 'install' && $item->state !== 'not_installed')
		{
			$this->app->enqueueMessage(Text::_('COM_FGEXTENSIONMANAGER_ERROR_ALREADY_INSTALLED'), 'warning');
			$this->setRedirect(Route::_(self::REDIRECT, false));

			return;
		}

		if ($mode === 'update' && $item->state !== 'update_available')
		{
			$key = $item->state === 'not_installed'
				? 'COM_FGEXTENSIONMANAGER_ERROR_NOT_INSTALLED'
				: 'COM_FGEXTENSIONMANAGER_ERROR_ALREADY_UP_TO_DATE';

			$this->app->enqueueMessage(Text::_($key), 'warning');
			$this->setRedirect(Route::_(self::REDIRECT, false));

			return;
		}

		$checksums = array_filter([
			'sha256' => $item->sha256 ?? '',
			'sha384' => $item->sha384 ?? '',
			'sha512' => $item->sha512 ?? '',
		]);

		[$success, $message] = InstallHelper::installFromUrl($item->download_url, $checksums, $mode === 'update');

		$this->app->enqueueMessage(
			$item->label . ': ' . $message,
			$success ? 'message' : 'error'
		);

		$this->setRedirect(Route::_(self::REDIRECT, false));
	}

	private function hasAccess(): bool
	{
		$user = $this->app->getIdentity();

		return $user && ($user->authorise('core.admin', 'com_fgextensionmanager')
			|| $user->authorise('core.manage', 'com_fgextensionmanager'));
	}
}
