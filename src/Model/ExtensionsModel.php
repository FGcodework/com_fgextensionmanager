<?php
/**
 * @package     FG.Administrator
 * @subpackage  com_fgextensionmanager
 *
 * @copyright   Copyright (C) 2026 Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace FG\Component\FgExtensionManager\Administrator\Model;

use FG\Component\FgExtensionManager\Administrator\Helper\RepoHelper;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

defined('_JEXEC') or die;

/**
 * Model for the extensions overview.
 */
class ExtensionsModel extends BaseDatabaseModel
{
	/**
	 * @param   boolean  $refresh  Bypass the local updates.xml cache.
	 *
	 * @return  object  {items: object[], stale: bool, stale_since: int|null, last_checked: int|null}
	 */
	public function getItems(bool $refresh = false): object
	{
		return RepoHelper::getCatalog($refresh);
	}
}
