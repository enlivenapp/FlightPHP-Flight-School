<?php

/**
 * Discovers, configures, and loads Flight School plugins.
 *
 * Reads Composer's installed.json for packages with a type starting
 * with "flightphp-", loads their Config/ files, registers routes,
 * and calls Plugin::register() if present.
 *
 * @package   Enlivenapp\FlightSchool
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool;

use flight\Engine;
use flight\net\Router;

class PluginLoader
{
    protected Engine $app;
    protected Router $router;
    protected string $vendorPath;
    protected array $enabledPlugins;
    protected array $loaded = [];
    protected ?PluginView $pluginView = null;

    /** @var array<string, string> Resolved plugin root paths keyed by package name */
    protected array $pluginRoots = [];

    /** @var array<string, array<string, string>> Pre-scanned src/ paths: [type => [packageName => path]] */
    protected array $scannedPaths = [];

    /** @var array<string, string> Base PSR-4 namespace per package name */
    protected array $pluginNamespaces = [];

    /** @var array<string, array> Config arrays loaded from each plugin's Config/Config.php */
    protected array $pluginConfigs = [];

    /** @var ?string Package currently being loaded (set during register() calls) */
    protected ?string $currentPackage = null;

    /**
     * @param Engine $app            The FlightPHP app instance.
     * @param Router $router         The FlightPHP router.
     * @param string $vendorPath     Absolute path to the vendor/ directory.
     * @param array  $enabledPlugins Plugin settings from app/config/config.php.
     */
    public function __construct(Engine $app, Router $router, string $vendorPath, array $enabledPlugins = [])
    {
        $this->app = $app;
        $this->router = $router;
        $this->vendorPath = rtrim($vendorPath, DIRECTORY_SEPARATOR);
        $this->enabledPlugins = $enabledPlugins;
    }

    /**
     * Discover and load all enabled plugins.
     *
     * @return array<string, PluginInterface> Loaded plugin instances keyed by package name
     */
    public function loadPlugins(): array
    {
        $this->initPluginView();

        $discovered = $this->discover();

        // Sort by priority (lower = earlier), default to 50
        $prioritized = [];
        foreach ($discovered as $packageName => $pluginClass) {
            $settings = $this->enabledPlugins[$packageName] ?? [];
            $priority = $settings['priority'] ?? 50;
            $prioritized[$packageName] = [
                'class' => $pluginClass,
                'priority' => $priority,
            ];
        }
        uasort($prioritized, fn($a, $b) => $a['priority'] <=> $b['priority']);

        // Run all pending migrations and seeds before loading plugins
        $this->runMigrations();

        foreach ($prioritized as $packageName => $info) {
            if (!$this->isEnabled($packageName)) {
                continue;
            }

            $this->resolvePluginRoot($packageName);
            $this->registerPluginViewPath($packageName);
            $this->currentPackage = $packageName;
            $this->scanPluginPaths($packageName);

            // Plugin.php is optional. If it exists and implements
            // PluginInterface, call register() for any custom setup.
            $plugin = null;
            if (class_exists($info['class']) && is_a($info['class'], PluginInterface::class, true)) {
                $plugin = new $info['class']();
                $config = $this->pluginConfigs[$packageName] ?? [];
                $plugin->register($this->app, $this->router, $config);
            }

            $this->currentPackage = null;
            $this->loaded[$packageName] = $plugin;
        }

        return $this->loaded;
    }

    /**
     * Replace Flight's default View with PluginView to support
     * plugin view resolution with app-level overrides.
     *
     * @return void
     */
    protected function initPluginView(): void
    {
        $appViewPath = $this->app->get('flight.views.path') ?? '.';
        $appViewExt = $this->app->get('flight.views.extension') ?? '.php';

        $pluginView = &$this->pluginView;
        $this->app->register('view', PluginView::class, [$appViewPath], function (PluginView $view) use ($appViewExt, &$pluginView) {
            $view->extension = $appViewExt;
            $pluginView = $view;
        });

        // Force instantiation so pluginView is available for addPluginPath calls
        $this->app->view();
    }

    /**
     * Resolve and store the root path for a vendor plugin.
     * Rejects symlinks and paths that resolve outside the project root.
     *
     * @param string $packageName Composer package name (e.g. 'enlivenapp/hello-world-plugin').
     * @return void
     */
    protected function resolvePluginRoot(string $packageName): void
    {
        $vendorRoot = $this->vendorPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $packageName);

        if (is_dir($vendorRoot) && !is_link($vendorRoot) && $this->isWithinProjectRoot($vendorRoot)) {
            $this->pluginRoots[$packageName] = $vendorRoot;
        }
    }

    /**
     * Check that a resolved path stays within the project root.
     *
     * @param string $path Absolute filesystem path to validate.
     * @return bool
     */
    protected function isWithinProjectRoot(string $path): bool
    {
        $realPath = realpath($path);
        $realRoot = realpath(dirname($this->vendorPath));

        if ($realPath === false || $realRoot === false) {
            return false;
        }

        return str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR);
    }

    /**
     * Register a plugin's src/Views/ directory for view resolution.
     *
     * @param string $packageName Composer package name.
     * @return void
     */
    protected function registerPluginViewPath(string $packageName): void
    {
        if ($this->pluginView === null || !isset($this->pluginRoots[$packageName])) {
            return;
        }

        $viewPath = $this->pluginRoots[$packageName] . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Views';

        if (is_dir($viewPath)) {
            $this->pluginView->addPluginPath($packageName, $viewPath);
        }
    }

    /**
     * Scan a plugin's src/ directory, store paths, and register
     * with the engine. When Config/ is found, its files are loaded
     * via loadConfigDir().
     *
     * @param string $packageName Composer package name.
     * @return void
     */
    protected function scanPluginPaths(string $packageName): void
    {
        if (!isset($this->pluginRoots[$packageName])) {
            return;
        }

        $srcDir = $this->pluginRoots[$packageName] . DIRECTORY_SEPARATOR . 'src';

        if (!is_dir($srcDir)) {
            return;
        }

        foreach (glob($srcDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $dir) {
            $type = basename($dir);

            if ($type === 'Views') {
                continue;
            }

            $this->scannedPaths[$type][$packageName] = $dir;
            $this->app->path($dir);

            if ($type === 'Config') {
                $this->loadConfigDir($packageName, $dir);
            }
        }
    }

    /**
     * Load files from a plugin's Config/ directory in bootstrap order:
     * Config.php first, Routes.php second, then any remaining .php
     * files alphabetically. Services.php is intentionally skipped —
     * services use Composer autoloading and don't need registration.
     *
     * Config.php may set $configPrepend and $routePrepend to override
     * the default collision-avoidance prefixes. If not set, defaults
     * are derived from the package name:
     *   - Config: vendor.package-name (e.g. enlivenapp.hello-world-plugin)
     *   - Routes: vendor_package_name (e.g. enlivenapp_hello_world_plugin)
     *
     * Config.php should return an array. The PluginLoader stores it
     * on $app under the config prepend key automatically. Values set
     * via $app->set() directly in Config.php are NOT prefixed — they
     * go into $app exactly as written.
     *
     * Routes.php is automatically wrapped in a $router->group() using
     * the route prepend, so plugins don't need their own group wrapper.
     * $configPrepend is available inside Routes.php for config lookups.
     *
     * @param string $packageName Composer package name.
     * @param string $dir         Absolute path to the plugin's Config/ directory.
     * @return void
     */
    protected function loadConfigDir(string $packageName, string $dir): void
    {
        $app = $this->app;
        $router = $this->router;
        $config = [];
        $configPrepend = null;
        $routePrepend = null;

        // 1. Config — read config values and optional prepend overrides
        $configFile = $dir . DIRECTORY_SEPARATOR . 'Config.php';
        if (file_exists($configFile)) {
            $result = require $configFile;
            if (is_array($result)) {
                $config = $result;
            }
        }

        // Apply defaults if plugin didn't set overrides
        $configPrepend = $configPrepend ?? $this->deriveConfigPrepend($packageName);
        $routePrepend = $routePrepend ?? $this->deriveRoutePrepend($packageName);

        // Merge app-level config overrides from plugins array
        $appOverrides = $this->enabledPlugins[$packageName] ?? [];
        unset($appOverrides['enabled'], $appOverrides['priority']);
        if (!empty($appOverrides)) {
            $config = array_replace_recursive($config, $appOverrides);
        }

        // Store config on $app under the prefixed key
        if (!empty($config)) {
            $app->set($configPrepend, $config);
        }
        $this->pluginConfigs[$packageName] = $config;

        // 2. Routes — auto-wrapped in prefix group with plugin view context
        $routesFile = $dir . DIRECTORY_SEPARATOR . 'Routes.php';
        if (file_exists($routesFile)) {
            $viewMiddleware = new PluginViewContextMiddleware($app, $packageName);
            $router->group('/' . $routePrepend, function (Router $router) use ($app, $routesFile, $configPrepend) {
                require $routesFile;
            }, [$viewMiddleware]);
        }

        // 3. Everything else (Services.php intentionally skipped)
        $handled = ['Config.php', 'Services.php', 'Routes.php'];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') as $file) {
            if (!in_array(basename($file), $handled, true)) {
                require $file;
            }
        }
    }

    /**
     * Derive the default config prepend from a package name.
     * enlivenapp/hello-world-plugin → enlivenapp.hello-world-plugin
     *
     * @param string $packageName Composer package name.
     * @return string
     */
    protected function deriveConfigPrepend(string $packageName): string
    {
        return str_replace('/', '.', $packageName);
    }

    /**
     * Derive the default route prepend from a package name.
     * enlivenapp/hello-world-plugin → enlivenapp_hello_world_plugin
     *
     * @param string $packageName Composer package name.
     * @return string
     */
    protected function deriveRoutePrepend(string $packageName): string
    {
        return str_replace(['/', '-'], '_', $packageName);
    }

    /**
     * Get src/ paths and namespaces across all loaded plugins.
     * With no argument, returns all types. With a type, returns only that bucket.
     * Includes nested paths added via setPath().
     *
     * @param ?string $type Type bucket (e.g. 'Migrations', 'Seeds', 'Config') or null for all
     * @return array<string, array<string, array{path: string, namespace: ?string}>> Type => [package => {path, namespace}]
     */
    public function getPaths(?string $type = null): array
    {
        $types = $type !== null ? [$type] : array_keys($this->scannedPaths);
        $output = [];

        foreach ($types as $t) {
            $rawPaths = $this->scannedPaths[$t] ?? [];

            $entries = [];
            foreach ($rawPaths as $packageName => $path) {
                $baseNamespace = $this->pluginNamespaces[$packageName] ?? null;
                $srcDir = $this->pluginRoots[$packageName] . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
                $relativePath = str_replace($srcDir, '', $path);
                $namespaceSuffix = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

                $entries[$packageName] = [
                    'path' => $path,
                    'namespace' => $baseNamespace ? $baseNamespace . '\\' . $namespaceSuffix : null,
                ];
            }

            if (!empty($entries)) {
                $output[$t] = $entries;
            }
        }

        return $output;
    }

    /**
     * Register a nested src/ subdirectory under a type bucket.
     * Call from within a plugin's register() method — the package is resolved automatically.
     *
     * @param string $type Type bucket to group under (e.g. 'Migrations')
     * @param string $subdir Actual src/ subdirectory (e.g. 'Migrations/2026-04-17')
     */
    public function setPath(string $type, string $subdir): void
    {
        $packageName = $this->currentPackage;

        if ($packageName === null || !isset($this->pluginRoots[$packageName])) {
            return;
        }

        // Validate each segment is a valid PHP namespace identifier
        $segments = preg_split('#[/\\\\]#', $subdir);
        foreach ($segments as $segment) {
            if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $segment)) {
                error_log("Flight School: setPath() skipped '{$subdir}' in '{$packageName}' — "
                    . "'{$segment}' is not a valid PHP namespace segment (no dashes or special characters).");
                return;
            }
        }

        $dir = $this->pluginRoots[$packageName] . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);

        if (!is_dir($dir)) {
            return;
        }

        $this->scannedPaths[$type][$packageName] = $dir;
        $this->app->path($dir);
    }

    /**
     * Discover vendor plugins with a type starting with "flightphp-".
     *
     * @return array<string, string> Package/plugin name => Plugin class FQCN
     */
    public function discover(): array
    {
        return $this->discoverVendor();
    }

    /**
     * Discover vendor packages with a type starting with "flightphp-".
     *
     * @return array<string, string> Package name => Plugin class FQCN
     */
    protected function discoverVendor(): array
    {
        $installedFile = $this->vendorPath . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';

        if (!file_exists($installedFile)) {
            return [];
        }

        $installed = json_decode(file_get_contents($installedFile), true);

        // Composer 2 wraps packages in a "packages" key
        $packages = $installed['packages'] ?? $installed;

        $discovered = [];

        foreach ($packages as $package) {
            $type = $package['type'] ?? 'library';
            $name = $package['name'] ?? '';

            if (!str_starts_with($type, 'flightphp-')) {
                continue;
            }

            $pluginClass = $this->resolvePluginClass($package);

            if ($pluginClass !== null) {
                $discovered[$name] = $pluginClass;
                $autoload = $package['autoload']['psr-4'] ?? [];
                if (!empty($autoload)) {
                    $this->pluginNamespaces[$name] = rtrim(array_key_first($autoload), '\\');
                }
            }
        }

        return $discovered;
    }

    /**
     * Get all discovered plugins and their enabled status.
     *
     * @return array<string, array{class: string, enabled: bool}>
     */
    public function getDiscovered(): array
    {
        $discovered = $this->discover();
        $result = [];

        foreach ($discovered as $packageName => $pluginClass) {
            $result[$packageName] = [
                'class' => $pluginClass,
                'enabled' => $this->isEnabled($packageName),
            ];
        }

        return $result;
    }

    /**
     * Get loaded plugin instances.
     *
     * @return array<string, PluginInterface>
     */
    public function getLoaded(): array
    {
        return $this->loaded;
    }

    /**
     * Check if a plugin is enabled in the app config.
     *
     * @param string $packageName Composer package name.
     * @return bool
     */
    protected function isEnabled(string $packageName): bool
    {
        $settings = $this->enabledPlugins[$packageName] ?? [];
        return !empty($settings['enabled']);
    }

    /**
     * Resolve the Plugin class FQCN from a package's PSR-4 autoload config.
     *
     * @param array $package Composer package data from installed.json.
     * @return string|null Fully qualified class name, or null if no autoload.
     */
    protected function resolvePluginClass(array $package): ?string
    {
        $autoload = $package['autoload']['psr-4'] ?? [];

        if (empty($autoload)) {
            return null;
        }

        // Use the first PSR-4 namespace
        $namespace = array_key_first($autoload);

        return rtrim($namespace, '\\') . '\\Plugin';
    }

    /**
     * Run all pending migrations and seeds via enlivenapp/migrations.
     *
     * Called once before the plugin loading loop. On success, updates
     * the version in app/config/config.php for each package that ran.
     *
     * @return void
     */
    protected function runMigrations(): void
    {
        if (!class_exists(\Enlivenapp\Migrations\Services\MigrationSetup::class)) {
            return;
        }

        try {
            $migrate = new \Enlivenapp\Migrations\Services\MigrationSetup();
            $results = $migrate->runMigrate();
        } catch (\Throwable $e) {
            return;
        }

        foreach ($results as $packageName => $moduleResult) {
            if ($moduleResult->isSuccess() && $moduleResult->getVersion() !== null) {
                $this->writeNewVersionToConfig($packageName, $moduleResult->getVersion());
            }
        }
    }

    /**
     * Write the given version into this plugin's config.php entry.
     * Inserts the 'version' key if missing; replaces it if present.
     * Keeps the in-memory enabledPlugins state in sync.
     *
     * @param string $packageName Composer package name.
     * @param string $newVersion  Version string (already stripped of any 'v' prefix).
     * @return void
     */
    protected function writeNewVersionToConfig(string $packageName, string $newVersion): void
    {
        $configFile = dirname($this->vendorPath) . '/app/config/config.php';
        if (!file_exists($configFile)) {
            return;
        }

        $contents = file_get_contents($configFile);
        if ($contents === false) {
            return;
        }

        $replacePattern = '/(\'' . preg_quote($packageName, '/') . '\'\s*=>\s*\[[^\]]*?\'version\'\s*=>\s*\')([^\']*)(\')/s';
        $replaced = preg_replace(
            $replacePattern,
            '${1}' . addslashes($newVersion) . '${3}',
            $contents,
            1,
            $replaceCount
        );

        if ($replaceCount > 0 && $replaced !== null) {
            if ($replaced !== $contents) {
                file_put_contents($configFile, $replaced);
                $this->enabledPlugins[$packageName]['version'] = $newVersion;
            }
            return;
        }

        // No existing 'version' key in this entry — insert one right after the opening '[' line.
        $insertPattern = '/(\'' . preg_quote($packageName, '/') . '\'\s*=>\s*\[\s*\n)/';
        $insertLine    = "\t\t\t'version' => '" . addslashes($newVersion) . "',\n";
        $inserted      = preg_replace($insertPattern, '${1}' . $insertLine, $contents, 1, $insertCount);

        if ($insertCount > 0 && $inserted !== null && $inserted !== $contents) {
            file_put_contents($configFile, $inserted);
            $this->enabledPlugins[$packageName]['version'] = $newVersion;
        }
    }

}
