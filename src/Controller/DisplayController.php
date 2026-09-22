<?php
/**
 * @package     FG.Administrator
 * @subpackage  com_fgextensionmanager
 *
 * @copyright   Copyright (C) 2026 Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace FG\Component\FgExtensionManager\Administrator\Controller;

use Joomla\CMS\MVC\Controller\BaseController;

defined('_JEXEC') or die;

/**
 * Display controller class.
 */
class DisplayController extends BaseController
{
	/**
	 * The default view for the display method.
	 *
	 * @var  string
	 */
	protected $default_view = 'extensions';
}
