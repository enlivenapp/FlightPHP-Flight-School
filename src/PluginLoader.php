<?php

/**
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

    /** @var ?string Package currently being loaded (set during register() calls) */
    protected ?string $currentPackage = null;

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

        foreach ($prioritized as $packageName => $info) {
            if ($this->isEnabled($packageName) && class_exists($info['class'])) {
                if (!is_a($info['class'], PluginInterface::class, true)) {
                    continue;
                }

                $plugin = new $info['class']();
                $this->resolvePluginRoot($packageName);
                $this->registerPluginViewPath($packageName);
                $this->scanPluginPaths($packageName);
                $config = $this->enabledPlugins[$packageName]['config'] ?? [];
                $this->currentPackage = $packageName;
                $plugin->register($this->app, $this->router, $config);
                $this->currentPackage = null;
                $this->loaded[$packageName] = $plugin;
            }
        }

        return $this->loaded;
    }

    /**
     * Replace Flight's default View with PluginView to support plugin view resolution.
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
     * Register a plugin's src/views/ directory for view resolution.
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
     * Scan a plugin's src/ directory, store paths, and register with the engine.
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
        }
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

    protected function isEnabled(string $packageName): bool
    {
        $settings = $this->enabledPlugins[$packageName] ?? [];
        return !empty($settings['enabled']);
    }

    /**
     * Resolve the Plugin class FQCN from a package's PSR-4 autoload config.
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
}
