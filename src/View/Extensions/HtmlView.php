<?php
/**
 * @package     FG.Administrator
 * @subpackage  com_fgextensionmanager
 *
 * @copyright   Copyright (C) 2026 Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace FG\Component\FgExtensionManager\Administrator\View\Extensions;

use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * View class for the extensions overview.
 */
class HtmlView extends BaseHtmlView
{
	/**
	 * @var  object[]
	 */
	protected $items = [];

	/**
	 * @var  boolean  Whether any repo's data shown below came from a cache
	 *                fallback after a live fetch failed, rather than a fresh
	 *                or still-within-TTL cached fetch.
	 */
	protected $stale = false;

	/**
	 * @var  integer|null  Unix timestamp of the oldest stale source, if any.
	 */
	protected $staleSince;

	/**
	 * @var  integer|null  Unix timestamp of the oldest "as of" data source
	 *                     among everything shown below - unlike $staleSince,
	 *                     this is always set (when there's at least one
	 *                     tracked repo), not only on a genuine fetch failure.
	 */
	protected $lastChecked;

	/**
	 * @var  \Joomla\CMS\Object\CMSObject
	 */
	protected $canDoActions;

	/**
	 * @var  integer  How many tracked extensions (including a pending
	 *                self-update) currently show "update_available" -
	 *                exposed so the template can build the same confirm
	 *                message the toolbar's Update All button uses.
	 */
	protected $updateAvailableCount = 0;

	public function display($tpl = null): void
	{
		// The "extensions.refresh" task already force-refreshes and rewrites the
		// cache file before redirecting back here, so a plain (non-forced) read
		// on this page load already sees the fresh data.
		$result             = $this->getModel()->getItems(false);
		$this->items        = $result->items;
		$this->stale        = $result->stale;
		$this->staleSince   = $result->stale_since;
		$this->lastChecked  = $result->last_checked;
		$this->canDoActions = ContentHelper::getActions('com_fgextensionmanager');

		// Includes a pending self-update too (self isn't pulled out of
		// $this->items until the template renders it as its own card), so
		// this count matches what extensions.updateAll() actually updates.
		$this->updateAvailableCount = count(array_filter(
			$this->items,
			static fn ($item) => $item->state === 'update_available'
		));

		// External file + WebAssetManager instead of an inline <script>
		// block in the template - a Content-Security-Policy without
		// 'unsafe-inline' blocks inline scripts (and inline event-handler
		// attributes), but not an externally loaded, same-origin file like
		// this one. addScriptOptions() is the still-current (non-deprecated)
		// way to pass PHP data down to it, read there via Joomla.getOptions().
		$wa = $this->getDocument()->getWebAssetManager();
		$wa->registerAndUseScript('com_fgextensionmanager.extensions', 'com_fgextensionmanager/extensions.js', [], ['defer' => true]);
		$wa->registerAndUseStyle('com_fgextensionmanager.extensions', 'com_fgextensionmanager/extensions.css');
		$this->getDocument()->addScriptOptions('com_fgextensionmanager.extensions', [
			'updateAllConfirm' => Text::sprintf('COM_FGEXTENSIONMANAGER_UPDATE_ALL_CONFIRM', $this->updateAvailableCount),
		]);

		$this->addToolbar($this->updateAvailableCount);

		parent::display($tpl);
	}

	protected function addToolbar(int $updateAvailableCount = 0): void
	{
		$canDo = ContentHelper::getActions('com_fgextensionmanager');

		ToolbarHelper::title(Text::_('COM_FGEXTENSIONMANAGER_TITLE_EXTENSIONS'), 'download');

		if ($canDo->get('core.manage') || $canDo->get('core.admin'))
		{
			if ($updateAvailableCount > 0)
			{
				// Plain Standard button, same proven mechanism as Refresh below -
				// the appendButton('Confirm', ...) variant tried first turned out
				// not to work in practice. The confirm dialog is instead handled
				// by a scoped click listener on this specific button (see
				// media/js/extensions.js), not a global Joomla.submitbutton override.
				ToolbarHelper::custom(
					'extensions.updateAll',
					'loop',
					'',
					Text::sprintf('COM_FGEXTENSIONMANAGER_BUTTON_UPDATE_ALL', $updateAvailableCount),
					false
				);
			}

			ToolbarHelper::custom('extensions.refresh', 'refresh', '', Text::_('COM_FGEXTENSIONMANAGER_BUTTON_REFRESH'), false);
		}

		if ($canDo->get('core.admin'))
		{
			ToolbarHelper::preferences('com_fgextensionmanager');
		}
	}
}
