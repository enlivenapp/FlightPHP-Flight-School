<?php

/**
 * Enable a plugin by setting 'enabled' => true in config.php.
 *
 * @package   Enlivenapp\FlightSchool\Commands
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool\Commands;

use Enlivenapp\FlightSchool\PluginDiscoveryTrait;
use flight\commands\AbstractBaseCommand;

class PluginsEnableCommand extends AbstractBaseCommand
{
    use PluginDiscoveryTrait;

    /**
     * @param array $config Runway config.
     */
    public function __construct(array $config)
    {
        parent::__construct('plugins:enable', 'Enable a plugin in config.php', $config);
        $this->argument('[plugin]', 'Plugin name (e.g. enlivenapp/hello)');
    }

    /**
     * Enable the specified plugin.
     *
     * @param string|null $plugin Package name to enable.
     * @return void
     */
    public function execute(?string $plugin = null): void
    {
        $io = $this->app()->io();

        if (empty($plugin)) {
            $io->error('  No plugin specified. Run plugins:list to see available plugins:', true);
            $io->write('', true);
            $io->write('    php runway plugins:list', true);
            $io->write('    php runway plugins:enable vendor/package', true);
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
            $allDiscovered = $this->discoverAll();

            if (isset($allDiscovered[$plugin])) {
                $io->error("  Plugin '{$plugin}' is discovered but not in config.php. Run 'php runway plugins:sync' first.", true);
            } else {
                $io->error("  Plugin '{$plugin}' not found.", true);
            }
            return;
        }

        if (!empty($pluginsConfig[$plugin]['enabled'])) {
            $io->info("  Plugin '{$plugin}' is already enabled.", true);
            return;
        }

        if ($this->updatePluginConfig($plugin, 'enabled', true)) {
            $io->ok("  Plugin '{$plugin}' enabled.", true);
        } else {
            $io->error("  Failed to update config.php.", true);
        }
    }
}
