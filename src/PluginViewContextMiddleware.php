<?php

declare(strict_types=1);

namespace Enlivenapp\FlightSchool;

use flight\Engine;

class PluginViewContextMiddleware
{
    protected Engine $app;
    protected string $packageName;

    public function __construct(Engine $app, string $packageName)
    {
        $this->app = $app;
        $this->packageName = $packageName;
    }

    public function before(): void
    {
        $view = $this->app->view();
        if ($view instanceof PluginView) {
            $view->setCurrentPlugin($this->packageName);
        }
    }

    public function after(): void
    {
        $view = $this->app->view();
        if ($view instanceof PluginView) {
            $view->setCurrentPlugin(null);
        }
    }
}
