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

		$this->addToolbar();

		parent::display($tpl);
	}

	protected function addToolbar(): void
	{
		$canDo = ContentHelper::getActions('com_fgextensionmanager');

		ToolbarHelper::title(Text::_('COM_FGEXTENSIONMANAGER_TITLE_EXTENSIONS'), 'download');

		if ($canDo->get('core.manage') || $canDo->get('core.admin'))
		{
			ToolbarHelper::custom('extensions.refresh', 'refresh', '', Text::_('COM_FGEXTENSIONMANAGER_BUTTON_REFRESH'), false);
		}

		if ($canDo->get('core.admin'))
		{
			ToolbarHelper::preferences('com_fgextensionmanager');
		}
	}
}
