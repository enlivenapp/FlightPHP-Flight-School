# Flight School

Plugin architecture for FlightPHP. Composer gets plugin code on disk. Flight School wires it into the running app.

- **Automatic boot order** for plugin files (Config, Routes) with `$app` and `$router` available
- **Auto-prefixed config and routes** so plugins don't step on each other
- **Enable/disable** plugins in `app/config/config.php`
- **Priority-based load ordering** between plugins
- **View overrides** so the host app can replace any plugin view
- **Cross-plugin discovery** via `getPaths()` (migrations, seeds, etc.)
- **CLI commands** to list, info, sync, enable, and disable plugins
- **Security** checks (path containment, symlink rejection)

Plugins use FlightPHP's `Engine` and `Router` directly, no wrapper APIs.



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

Composer will ask you to trust the plugin. Type `yes`. This allows Flight School to set up your project:

> *We recommend always reviewing someone elses' code before installing it*


1. Adds the plugin loader service to `app/config/services.php`
2. Adds a `plugins` section to `app/config/config.php` and `config_sample.php`

Plugins are disabled by default. Enable them in `config.php` by setting `'enabled' => true` or use runway: `php runway plugins:enable [vendor/package]`.

## Writing a Plugin

A plugin is a Composer package with files in `src/Config/`. The PluginLoader loads them automatically.

**src/Config/Config.php** returns config values. The PluginLoader stores the returned array on `$app` with a prefix based on the package name, so two plugins can't overwrite each other's config:

```php
<?php
return [
    'posts_per_page' => 15,
];
```

For `myvendor/my-plugin`, the config is stored as `myvendor.my-plugin`. Read it with `$app->get('myvendor.my-plugin')`.

You can set a shorter prefix by adding `$configPrepend` (and `$routePrepend` for routes) in Config.php:

```php
<?php
$configPrepend = 'blog';
$routePrepend = 'blog';

return [
    'posts_per_page' => 15,
];
```

Now it's `$app->get('blog')` instead. If you don't set these, the defaults are:

- **Config:** `myvendor.my-plugin` (dot-separated package name)
- **Routes:** `myvendor_my_plugin` (underscored package name)

**src/Config/Routes.php** defines routes. The PluginLoader wraps this file in a `$router->group()` using the route prepend, so you don't need your own group wrapper. `$configPrepend` is available for reading your plugin's config:

```php
<?php
$config = $app->get($configPrepend);

$router->get('/', [BlogController::class, 'index']);
$router->get('/@slug', [BlogController::class, 'show']);
```

With `$routePrepend = 'blog'` these become `/blog/` and `/blog/@slug`. Without the override they'd be `/myvendor_my_plugin/` and `/myvendor_my_plugin/@slug`.

Only include the files your plugin needs. `$app` and `$router` are available in all Config/ files.

**Services don't need registration.** Composer autoloading makes all plugin classes available by their full name. Just use them directly:

```php
$mailer = new \MyVendor\MyPlugin\Services\Mailer();
```

**Plugin.php is optional.** If your plugin needs custom setup beyond what Config/ files provide (events, middleware, etc.), create `src/Plugin.php` implementing `PluginInterface`. The loader calls `register()` after the Config/ files are loaded:

```php
<?php
namespace MyVendor\MyPlugin;

use Enlivenapp\FlightSchool\PluginInterface;
use flight\Engine;
use flight\net\Router;

class Plugin implements PluginInterface
{
    public function register(Engine $app, Router $router, array $config = []): void
    {
        $app->onEvent('flight.request.received', function () {
            // Runs before routing
        });
    }
}
```

## Plugin Discovery

Any Composer package with a `type` starting with `flightphp-` is treated as a plugin. Flight School reads the PSR-4 namespace from `vendor/composer/installed.json` and loads the plugin's `src/Config/` files automatically. If a `Plugin` class exists (e.g. `YourVendor\YourPlugin\Plugin`), its `register()` method is called after.

When you `composer require` a `flightphp-*` package, its config entry is added automatically (disabled).

## Configuration

Plugin config has two parts:

**1. App config** in `app/config/config.php` only controls which plugins are enabled and their load order:

```php
'plugins' => [
    'myvendor/my-plugin' => [
        'enabled'  => true,
        'priority' => 10,
    ],
],
```

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `false` | Set `true` to load the plugin |
| `priority` | `50` | Lower numbers load first. Use when one plugin depends on another. |

**2. Plugin config** lives in the plugin's own `src/Config/` directory. The PluginLoader loads files in this order:

1. `Config.php` — return config values, optionally set prepend overrides
2. `Routes.php` — define routes (auto-wrapped in prefix group)
3. Any other `.php` files (Services.php is skipped)

`$app` and `$router` are available in all Config/ files. `$configPrepend` is available in Routes.php.

## Plugin Structure

Only include the directories your plugin uses:

```
my-plugin/
  src/
    Plugin.php              <- optional (for custom setup beyond Config/ files)
    Cache/
    commands/             <- Runway CLI commands (auto-discovered, must be lowercase)
    Config/               <- loaded automatically (Config.php, Routes.php)
    Controllers/
    Middlewares/
    Migrations/
    Models/
    Seeds/
    Services/             <- available via Composer autoloading, no registration needed
    Utils/
    Views/                <- overridable by the app
```

Directories follow PSR-4 convention, where folder names map directly to namespace segments. The one exception is `commands/` — it must be lowercase because Runway discovers command files by scanning the filesystem directly, not through Composer's autoloader. The bridge between the two is the `autoload` section in your plugin's `composer.json`:

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
    Services/
      Mailer.php         <- MyVendor\MyPlugin\Services\Mailer
```

Every `src/` subdirectory (except `Views/`) is automatically registered with the Flight engine when the plugin loads. This means plugin classes are available anywhere in the core app, in other plugins, or in CLI commands, just like any other autoloaded class.

Use plugin classes from routes, other plugins, or the core app:

```php
use MyVendor\MyPlugin\Controllers\BlogController;
use MyVendor\MyPlugin\Services\Mailer;
```

Plugins can extend core app classes:

```php
use app\models\UserModel;

class ExtendedUser extends UserModel { ... }
```

Plugins can also extend classes from other plugins as long as the dependency loads first (use `priority` in config to control load order).

Special cases:

- **`commands/`** (lowercase) Runway CLI commands extending `AbstractBaseCommand` are discovered and available automatically (e.g. `php runway myplugin:do-something`). Must be lowercase — Runway scans the filesystem directly, not through Composer's autoloader.
- **`Views/`** Handled by the view override system (see Views and Overrides)

## Views and Overrides

Render plugin views with the `{vendor}/{package}/{template}` convention:

```php
$app->render('myvendor/my-plugin/dashboard', ['data' => $data]);
```

Flight School checks two locations in order:

1. `app/views/myvendor/my-plugin/dashboard.php` (app override)
2. `vendor/myvendor/my-plugin/src/Views/dashboard.php` (plugin default)

To override a plugin's view, create the file at the app path. Delete it to revert to the plugin's default.

## Plugin Loader API

The loader is available as `$app->pluginLoader()`. It gives you access to what's loaded, what's available, and where plugin files live on disk.

### getLoaded()

Returns all enabled plugins that are currently running, keyed by package name. Each value is the plugin's `Plugin` instance, or `null` if the plugin has no Plugin.php.

```php
$loaded = $app->pluginLoader()->getLoaded();
// ['myvendor/my-plugin' => Plugin instance or null, ...]
```

### getDiscovered()

Returns every plugin the loader found in `installed.json`, whether enabled or not. Useful for admin panels or status pages.

```php
$discovered = $app->pluginLoader()->getDiscovered();
// ['myvendor/my-plugin' => ['class' => 'MyVendor\MyPlugin\Plugin', 'enabled' => true], ...]
```

### getPaths(string $type)

Returns absolute filesystem paths and namespaces for a specific `src/` subdirectory across all loaded plugins. This is designed for utility plugins that need to discover and process files from other plugins (a migration runner, a seed executor, a config merger, etc.).

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

Subdirectory names must be valid PHP namespace segments: letters, numbers, and underscores only. Dashes and special characters are not allowed because directories map directly to namespaces. For example, `Migrations/v1_0_2` works but `Migrations/2026-04-17` does not. Invalid names are logged and skipped.

Call `getPaths()` with no argument to get everything, or pass a type to filter:

```php
$app->pluginLoader()->getPaths();            // all types
$app->pluginLoader()->getPaths('Migrations'); // only migrations
```

**Note:** `commands/` (lowercase) and `Views/` are special cases. Commands are auto-discovered by Runway, and Views are handled by the view override system. Neither needs `getPaths()`.

## CLI Commands

Run `php runway plugins` for a full command summary.

| Command | Description |
|---------|-------------|
| `plugins:list` | Show all discovered plugins with status, source, and priority |
| `plugins:info vendor/package <option> [all]` | Show plugin details) |
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
- **`autoload`** must use PSR-4 pointing to `src/`.
- **`Plugin.php` is optional.** If included, it must live at `src/Plugin.php` and implement `PluginInterface`. The loader calls `register()` after Config/ files are loaded.

### Config/ directory

Put your plugin's config and routes in `src/Config/`:

```
src/Config/
  Config.php      <- returns config array, optionally sets prepend overrides
  Routes.php      <- defines routes (auto-wrapped in prefix group)
```

Only include the files your plugin needs. `$app` and `$router` are available in all of them. `$configPrepend` is available in Routes.php.

Publish your package to Packagist like any Composer package. Flight School handles discovery and config entry creation automatically.

## Security

- **Path containment.** All paths (loading and view resolution) are validated to stay within the project root.
- **Symlink rejection.** Symlinked directories are rejected during loading.
- **Interface verification.** Classes are checked against `PluginInterface` before instantiation. Non-conforming classes never have their constructor called.
- **Atomic file locking.** Config modifications hold an exclusive lock (`flock`) across the entire read-modify-write cycle.
- **No implicit trust.** All plugins are added as disabled. Nothing runs until explicitly enabled.

## License

MIT
