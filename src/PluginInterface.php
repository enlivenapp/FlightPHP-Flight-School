<?php

/**
 * Contract for Flight School plugins.
 *
 * Any plugin that needs custom setup beyond Config/ files should
 * implement this interface in src/Plugin.php. The PluginLoader calls
 * register() after all Config/ files are loaded.
 *
 * @package   Enlivenapp\FlightSchool
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool;

use flight\Engine;
use flight\net\Router;

interface PluginInterface
{
    /**
     * Called by the PluginLoader after Config/ files are loaded.
     *
     * @param Engine $app    The FlightPHP app instance.
     * @param Router $router The FlightPHP router.
     * @param array  $config The config array returned by Config/Config.php.
     * @return void
     */
    public function register(Engine $app, Router $router, array $config = []): void;
}
