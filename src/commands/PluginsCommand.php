<?php

/**
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool\Commands;

use flight\commands\AbstractBaseCommand;

class PluginsCommand extends AbstractBaseCommand
{
    public function __construct(array $config)
    {
        parent::__construct('plugins', 'Show Flight School plugin system usage and commands', $config);
    }

    public function execute(): void
    {
        (new PluginsHelpCommand($this->config))->bind($this->app())->execute();
    }
}
