<?php

/**
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool\Commands;

use flight\commands\AbstractBaseCommand;

class PluginsHelpCommand extends AbstractBaseCommand
{
    public function __construct(array $config)
    {
        parent::__construct('plugins:help', 'Show Flight School plugin system usage and commands', $config);
    }

    public function execute(): void
    {
        $io = $this->app()->io();

        $io->write('', true);
        $io->boldGreen('Flight School Plugin System', true);
        $io->write('', true);

        $io->bold('Available Commands:', true);
        $io->write('', true);
        $io->write('  plugins:list                List all discovered plugins with status, source, and priority', true);
        $io->write('  plugins:info <name> <opt>   Show plugin details (routes, config, classes, paths, composer-info)', true);
        $io->write('  plugins:sync                Scan for new plugins and add missing entries to config.php', true);
        $io->write('  plugins:enable <name>       Enable a plugin (e.g. plugins:enable enlivenapp/hello)', true);
        $io->write('  plugins:disable <name>      Disable a plugin (e.g. plugins:disable enlivenapp/hello)', true);
        $io->write('  plugins:help                Show this help message', true);
        $io->write('', true);

        $io->bold('Naming Rules:', true);
        $io->write('', true);
        $io->write('  Vendor and package names must be lowercase. Use letters, numbers, hyphens, and', true);
        $io->write('  underscores only. Names must start with a letter.', true);
        $io->write('', true);

        $io->bold('Plugin Sources:', true);
        $io->write('', true);
        $io->write('  Installed via Composer. Package must have "type": "flightphp-*" in its', true);
        $io->write('  composer.json. Config entry is added automatically on composer require.', true);
        $io->write('', true);

        $io->bold('Docs:', true);
        $io->write('', true);
        $io->write('  https://github.com/enlivenapp/FlightPHP-Flight-School', true);
        $io->write('', true);
    }
}
