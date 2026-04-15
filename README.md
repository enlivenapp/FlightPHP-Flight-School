# Flight School

Plugin architecture for FlightPHP. Discovers, loads, and manages plugins from Composer packages. Plugins use FlightPHP's `Engine` and `Router` directly — no wrapper APIs.

## Requirements

- PHP 8.1+
- A [FlightPHP skeleton project](https://github.com/flightphp/skeleton)

```bash
composer create-project flightphp/skeleton my-project
cd my-project
```
or `composer create-project flightphp/skeleton .` to install in the same directory.


## Installation

```bash
composer require enlivenapp/flight-school
```

Composer will ask you to trust the plugin — type `yes`. This allows Flight School to set up your project:

> *We recommend always reviewing someone elses' code before installing it*


1. Adds the plugin loader service to `app/config/services.php`
2. Adds a `plugins` section to `app/config/config.php` and `config_sample.php`

Plugins are disabled by default. Enable them in `config.php` by setting `'enabled' => true`.

## Writing a Plugin

Every plugin implements `Enlivenapp\FlightSchool\PluginInterface`, which has one method:

```php
<?php

declare(strict_types=1);

namespace MyVendor\MyPlugin;

use Enlivenapp\FlightSchool\PluginInterface;
use flight\Engine;
use flight\net\Router;

class Plugin implements PluginInterface
{
    public function register(Engine $app, Router $router, array $config = []): void
    {
        // Config values from config.php, with defaults
        $prefix = $config['route_prefix'] ?? '/blog';

        // Routes
        $router->group($prefix, function (Router $r) {
            $r->get('/', [BlogController::class, 'index']);
            $r->get('/@slug', [BlogController::class, 'show']);
        });

        // Routes with middleware
        $router->group($prefix . '/admin', function (Router $r) {
            $r->get('/dashboard', [AdminController::class, 'dashboard']);
        }, [AuthMiddleware::class]);

        // Services (available app-wide as $app->blogService())
        $app->register('blogService', BlogService::class);

        // Events
        $app->onEvent('flight.request.received', function () {
            // Runs before routing
        });

        // Views (overridable — see Views section)
        $router->get($prefix . '/about', function () use ($app) {
            $app->render('myvendor/my-plugin/about', ['title' => 'About']);
        });
    }
}
```

The three arguments:

- **`$app`** — FlightPHP Engine instance. Register services, set values, listen to events, render views.
- **`$router`** — FlightPHP Router instance. Register routes and route groups.
- **`$config`** — The `'config'` array from this plugin's entry in `config.php`. Defaults to `[]`.

## Plugin Discovery

Any Composer package with a `type` starting with `flightphp-` is treated as a plugin. Flight School reads the PSR-4 namespace from `vendor/composer/installed.json` and looks for a `Plugin` class at its root (e.g. `YourVendor\YourPlugin\Plugin`).

When you `composer require` a `flightphp-*` package, its config entry is added automatically (disabled).

## Configuration

All plugin config lives in `app/config/config.php` under the `plugins` key:

```php
'plugins' => [
    'myvendor/my-plugin' => [
        'enabled'  => true,
        'priority' => 10,
        'config'   => [
            'route_prefix'   => '/blog',
            'posts_per_page' => 15,
        ],
    ],
],
```

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `false` | Set `true` to load the plugin |
| `priority` | `50` | Lower numbers load first. Use when one plugin depends on another. |
| `config` | `[]` | Passed to the plugin's `register()` method. Put whatever your plugin needs here. |

## Plugin Structure

Only include the directories your plugin uses:

```
my-plugin/
  src/
    Plugin.php              <- required (namespace root)
    Cache/
    Commands/             <- Runway CLI commands (auto-discovered)
    Config/
    Controllers/
    Middlewares/
    Migrations/
    Models/
    Seeds/
    Utils/
    Views/                <- overridable by the app
```

Directories follow PSR-4 convention, where folder names map directly to namespace segments. The bridge between the two is the `autoload` section in your plugin's `composer.json`:

```json
"autoload": {
    "psr-4": {
        "MyVendor\\MyPlugin\\": "src/"
    }
}
```

This tells Composer: "everything inside `src/` belongs to the `MyVendor\MyPlugin` namespace." From there, subdirectories become namespace segments and filenames become class names:

```
vendor/myvendor/my-plugin/
  composer.json          <- PSR-4 mapping above
  src/
    Plugin.php           <- MyVendor\MyPlugin\Plugin
    Controllers/
      BlogController.php <- MyVendor\MyPlugin\Controllers\BlogController
    Models/
      Post.php           <- MyVendor\MyPlugin\Models\Post
```

Every `src/` subdirectory (except `Views/`) is automatically registered with the Flight engine when the plugin loads. This means plugin classes are available anywhere — in the core app, in other plugins, or in CLI commands — just like any other autoloaded class.

Use plugin classes from routes, other plugins, or the core app:

```php
use MyVendor\MyPlugin\Controllers\BlogController;
```

Plugins can extend core app classes:

```php
use app\models\UserModel;

class ExtendedUser extends UserModel { ... }
```

Plugins can also extend classes from other plugins as long as the dependency loads first (use `priority` in config to control load order).

Special cases:

- **`Commands/`** — Runway CLI commands extending `AbstractBaseCommand` are discovered and available automatically (e.g. `php runway myplugin:do-something`)
- **`Views/`** — Handled by the view override system (see Views and Overrides)

## Views and Overrides

Render plugin views with the `{vendor}/{package}/{template}` convention:

```php
$app->render('myvendor/my-plugin/dashboard', ['data' => $data]);
```

Flight School checks two locations in order:

1. `app/views/myvendor/my-plugin/dashboard.php` — app override
2. `vendor/myvendor/my-plugin/src/Views/dashboard.php` — plugin default

To override a plugin's view, create the file at the app path. Delete it to revert to the plugin's default.

## Plugin Loader API

The loader is available as `$app->pluginLoader()`. It gives you access to what's loaded, what's available, and where plugin files live on disk.

### getLoaded()

Returns all enabled plugins that are currently running, keyed by package name. Each value is the plugin's `Plugin` instance.

```php
$loaded = $app->pluginLoader()->getLoaded();
// ['myvendor/my-plugin' => Plugin instance, ...]
```

### getDiscovered()

Returns every plugin the loader found in `installed.json`, whether enabled or not. Useful for admin panels or status pages.

```php
$discovered = $app->pluginLoader()->getDiscovered();
// ['myvendor/my-plugin' => ['class' => 'MyVendor\MyPlugin\Plugin', 'enabled' => true], ...]
```

### getPaths(string $type)

Returns absolute filesystem paths and namespaces for a specific `src/` subdirectory across all loaded plugins. This is designed for utility plugins that need to discover and process files from other plugins — a migration runner, a seed executor, a config merger, etc.

Built-in directories that support `getPaths()`:

| Directory | Purpose |
|-----------|---------|
| `Migrations` | Database migration files |
| `Seeds` | Database seed files |
| `Config` | Plugin configuration files |

```php
$app->pluginLoader()->getPaths('Migrations');
// [
//     'myvendor/my-plugin' => [
//         'path' => '/var/www/.../src/Migrations',
//         'namespace' => 'MyVendor\MyPlugin\Migrations'
//     ],
// ]
```

A migration plugin, for example, could load first (lower priority) and use this to find and run every other plugin's migrations:

```php
foreach ($app->pluginLoader()->getPaths('Migrations') as $package => $info) {
    foreach (glob($info['path'] . '/*.php') as $file) {
        $class = $info['namespace'] . '\\' . basename($file, '.php');
        $migration = new $class();
        $migration->up();
    }
}
```

Plugins can organize files into subdirectories and register them under a type bucket with `setPath()`. The current plugin is resolved automatically during `register()`:

```php
// In your plugin's register() method
$app->pluginLoader()->setPath('Migrations', 'Migrations/v1_0_2');
```

This registers `src/Migrations/v1_0_2/` under the `Migrations` bucket. A migration runner calling `getPaths('Migrations')` picks it up alongside every other plugin's migrations.

Subdirectory names must be valid PHP namespace segments — letters, numbers, and underscores only. Dashes and special characters are not allowed because directories map directly to namespaces. For example, `Migrations/v1_0_2` works but `Migrations/2026-04-17` does not. Invalid names are logged and skipped.

Call `getPaths()` with no argument to get everything, or pass a type to filter:

```php
$app->pluginLoader()->getPaths();            // all types
$app->pluginLoader()->getPaths('Migrations'); // only migrations
```

**Note:** `Commands/` and `Views/` are special cases — Commands are auto-discovered by Runway, and Views are handled by the view override system. Neither needs `getPaths()`.

## CLI Commands

Run `php runway plugins` for a full command summary.

| Command | Description |
|---------|-------------|
| `plugins:list` | Show all discovered plugins with status, source, and priority |
| `plugins:sync` | Add missing config entries for newly discovered plugins (disabled) |
| `plugins:enable vendor/package` | Enable a plugin |
| `plugins:disable vendor/package` | Disable a plugin |

For plugin removal, use `composer remove vendor/package`.

## Distributing a Plugin via Composer

Minimum `composer.json`:

```json
{
    "name": "yourvendor/your-plugin",
    "description": "What your plugin does",
    "type": "flightphp-plugin",
    "license": "MIT",
    "require": {
        "php": "^8.1"
    },
    "autoload": {
        "psr-4": {
            "YourVendor\\YourPlugin\\": "src/"
        }
    }
}
```

Key points:

- **`type`** must start with `flightphp-` (e.g. `flightphp-plugin`, `flightphp-widget`, `flightphp-theme`)
- **`autoload`** must use PSR-4 pointing to `src/`. Flight School appends `\Plugin` to the first namespace.
- **Require `enlivenapp/flight-school`** — plugins need the `PluginInterface`.
- **`Plugin.php` must live at `src/Plugin.php`** — not in a subfolder. The loader resolves it by appending `\Plugin` to your PSR-4 namespace, so `src/Plugin.php` is the only location it looks.

Publish your package to Packagist like any Composer package. Flight School handles discovery and config entry creation automatically.

## Security

- **Path containment.** All paths — loading and view resolution — are validated to stay within the project root.
- **Symlink rejection.** Symlinked directories are rejected during loading.
- **Interface verification.** Classes are checked against `PluginInterface` before instantiation. Non-conforming classes never have their constructor called.
- **Atomic file locking.** Config modifications hold an exclusive lock (`flock`) across the entire read-modify-write cycle.
- **No implicit trust.** All plugins are added as disabled. Nothing runs until explicitly enabled.

## License

MIT
