<?php
/**
 * @package     FG.Administrator
 * @subpackage  com_fgextensionmanager
 *
 * @copyright   Copyright (C) 2026 Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * The component service provider.
 */
return new class () implements ServiceProviderInterface {
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 */
	public function register(Container $container): void
	{
		$container->registerServiceProvider(new MVCFactory('\\FG\\Component\\FgExtensionManager'));
		$container->registerServiceProvider(new ComponentDispatcherFactory('\\FG\\Component\\FgExtensionManager'));

		$container->set(
			ComponentInterface::class,
			function (Container $container) {
				$component = new MVCComponent($container->get(ComponentDispatcherFactoryInterface::class));
				$component->setMVCFactory($container->get(MVCFactoryInterface::class));

				return $component;
			}
		);

		$container->set(
			InstallerScriptInterface::class,
			new class ($container->get(AdministratorApplication::class)) implements InstallerScriptInterface {
				/**
				 * Minimum PHP version this component requires (uses str_starts_with()
				 * and other PHP 8.0+ syntax throughout).
				 */
				private const MINIMUM_PHP = '8.0.0';

				/**
				 * Minimum Joomla version this component requires - native PSR-4/
				 * service-provider architecture, not compatible with Joomla 4 and
				 * below.
				 *
				 * Checked manually here rather than via
				 * Joomla\CMS\Installer\InstallerScriptTrait's built-in
				 * $minimumPhp/$minimumJoomla properties: that trait was only added in
				 * Joomla 6 (confirmed absent from the Joomla 5.4 manual's own example
				 * of this same file) - using it would fatal on Joomla 5, which this
				 * component explicitly supports.
				 */
				private const MINIMUM_JOOMLA = '5.0.0';

				private AdministratorApplication $app;

				public function __construct(AdministratorApplication $app)
				{
					$this->app = $app;
				}

				public function preflight(string $type, InstallerAdapter $parent): bool
				{
					if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<'))
					{
						$this->app->enqueueMessage(
							Text::sprintf('COM_FGEXTENSIONMANAGER_ERROR_PHP_VERSION', self::MINIMUM_PHP, PHP_VERSION),
							'error'
						);

						return false;
					}

					if (defined('JVERSION') && version_compare(JVERSION, self::MINIMUM_JOOMLA, '<'))
					{
						$this->app->enqueueMessage(
							Text::sprintf('COM_FGEXTENSIONMANAGER_ERROR_JOOMLA_VERSION', self::MINIMUM_JOOMLA, JVERSION),
							'error'
						);

						return false;
					}

					return true;
				}

				public function install(InstallerAdapter $parent): bool
				{
					return true;
				}

				public function update(InstallerAdapter $parent): bool
				{
					return true;
				}

				public function uninstall(InstallerAdapter $parent): bool
				{
					return true;
				}

				public function postflight(string $type, InstallerAdapter $parent): bool
				{
					return true;
				}
			}
		);
	}
};
