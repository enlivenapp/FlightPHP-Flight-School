<?php

/**
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool;

use flight\Engine;
use flight\net\Router;

interface PluginInterface
{
    public function register(Engine $app, Router $router, array $config = []): void;
}
