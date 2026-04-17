<?php

/**
 * List all discovered plugins with their status, source, and priority.
 *
 * @package   Enlivenapp\FlightSchool\Commands
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool\Commands;

use Enlivenapp\FlightSchool\PluginDiscoveryTrait;
use flight\commands\AbstractBaseCommand;

class PluginsListCommand extends AbstractBaseCommand
{
    use PluginDiscoveryTrait;

    /**
     * @param array $config Runway config.
     */
    public function __construct(array $config)
    {
        parent::__construct('plugins:list', 'List all discovered plugins with their status', $config);
    }

    /**
     * Discover all plugins and print a formatted table.
     *
     * @return void
     */
    public function execute(): void
    {
        $io = $this->app()->io();

        $allDiscovered = $this->discoverAll();
        $pluginsConfig = $this->getPluginsConfig();

        if (empty($allDiscovered)) {
            $io->info('No plugins found.', true);
            return;
        }

        $io->info('Discovered ' . count($allDiscovered) . ' plugin(s):', true);
        $io->write('', true);

        // Header
        $io->write(str_pad('  Plugin', 45) . str_pad('Source', 10) . str_pad('Status', 12) . 'Priority', true);
        $io->write(str_repeat('-', 85), true);

        foreach ($allDiscovered as $name => $source) {
            $settings = $pluginsConfig[$name] ?? [];
            $enabled = !empty($settings['enabled']);
            $priority = $settings['priority'] ?? 50;
            $inConfig = isset($pluginsConfig[$name]);

            $status = 'not synced';
            if ($inConfig) {
                $status = $enabled ? 'enabled' : 'disabled';
            }

            $statusColor = match ($status) {
                'enabled' => "\033[32m" . $status . "\033[0m",
                'disabled' => "\033[33m" . $status . "\033[0m",
                'not synced' => "\033[31m" . $status . "\033[0m",
            };

            $io->write(
                str_pad('  ' . $name, 45)
                . str_pad($source, 10)
                . str_pad($statusColor, 23) // extra chars for ANSI codes
                . $priority,
                true
            );
        }

        $io->write('', true);
    }
}
