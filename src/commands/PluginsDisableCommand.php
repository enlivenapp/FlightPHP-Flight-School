<?php

/**
 * Disable a plugin by setting 'enabled' => false in config.php.
 *
 * @package   Enlivenapp\FlightSchool\Commands
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool\Commands;

use Enlivenapp\FlightSchool\PluginDiscoveryTrait;
use flight\commands\AbstractBaseCommand;

class PluginsDisableCommand extends AbstractBaseCommand
{
    use PluginDiscoveryTrait;

    /**
     * @param array $config Runway config.
     */
    public function __construct(array $config)
    {
        parent::__construct('plugins:disable', 'Disable a plugin in config.php', $config);
        $this->argument('[plugin]', 'Plugin name (e.g. enlivenapp/hello)');
    }

    /**
     * Disable the specified plugin.
     *
     * @param string|null $plugin Package name to disable.
     * @return void
     */
    public function execute(?string $plugin = null): void
    {
        $io = $this->app()->io();

        if (empty($plugin)) {
            $io->error('  No plugin specified. Run plugins:list to see available plugins:', true);
            $io->write('', true);
            $io->write('    php runway plugins:list', true);
            $io->write('    php runway plugins:disable vendor/package', true);
            $io->write('', true);
            return;
        }

        $validationError = $this->validatePluginName($plugin);
        if ($validationError !== null) {
            $io->error("  {$validationError}", true);
            return;
        }

        $pluginsConfig = $this->getPluginsConfig();

        if (!isset($pluginsConfig[$plugin])) {
            $io->error("  Plugin '{$plugin}' not found in config.php.", true);
            return;
        }

        if (empty($pluginsConfig[$plugin]['enabled'])) {
            $io->info("  Plugin '{$plugin}' is already disabled.", true);
            return;
        }

        if ($this->updatePluginConfig($plugin, 'enabled', false)) {
            $io->ok("  Plugin '{$plugin}' disabled.", true);
        } else {
            $io->error("  Failed to update config.php.", true);
        }
    }
}
