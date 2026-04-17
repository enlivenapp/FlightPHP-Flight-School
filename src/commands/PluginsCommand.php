<?php

/**
 * Entry point for the 'plugins' Runway command.
 * Delegates to PluginsHelpCommand to show usage and available commands.
 *
 * @package   Enlivenapp\FlightSchool\Commands
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool\Commands;

use flight\commands\AbstractBaseCommand;

class PluginsCommand extends AbstractBaseCommand
{
    /**
     * @param array $config Runway config.
     */
    public function __construct(array $config)
    {
        parent::__construct('plugins', 'Show Flight School plugin system usage and commands', $config);
    }

    /**
     * Delegate to the help command.
     *
     * @return void
     */
    public function execute(): void
    {
        (new PluginsHelpCommand($this->config))->bind($this->app())->execute();
    }
}
