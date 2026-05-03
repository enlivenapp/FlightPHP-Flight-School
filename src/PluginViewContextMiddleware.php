<?php

declare(strict_types=1);

namespace Enlivenapp\FlightSchool;

use flight\Engine;

/**
 * Sets and clears the active plugin context on the PluginView for each request.
 *
 * Wraps controller execution so that render() calls resolve templates
 * from the correct plugin's view directory.
 */
class PluginViewContextMiddleware
{
    protected Engine $app;
    protected string $packageName;

    public function __construct(Engine $app, string $packageName)
    {
        $this->app = $app;
        $this->packageName = $packageName;
    }

    /**
     * Set the active plugin context before the controller runs.
     *
     * @return void
     */
    public function before(): void
    {
        $view = $this->app->view();
        if ($view instanceof PluginView) {
            $view->setCurrentPlugin($this->packageName);
        }
    }

    /**
     * Clear the active plugin context after the controller runs.
     *
     * @return void
     */
    public function after(): void
    {
        $view = $this->app->view();
        if ($view instanceof PluginView) {
            $view->setCurrentPlugin(null);
        }
    }
}
