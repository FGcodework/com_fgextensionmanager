<?php
/**
 * @package     FG.Administrator
 * @subpackage  com_fgextensionmanager
 *
 * @copyright   Copyright (C) 2026 Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace FG\Component\FgExtensionManager\Administrator\Dispatcher;

use Joomla\CMS\Dispatcher\ComponentDispatcher;

defined('_JEXEC') or die;

/**
 * Component dispatcher class. No overrides needed - the default behaviour
 * (resolve default_view, check core.manage, hand off to the controller) is
 * exactly what this component needs. This class still has to physically
 * exist though: ComponentDispatcherFactory builds its FQCN from the
 * manifest namespace and requires the class to be found, it does not fall
 * back to the generic ComponentDispatcher the way MVCFactory falls back to
 * generic Controller/Model/View base classes.
 */
class Dispatcher extends ComponentDispatcher
{
}
