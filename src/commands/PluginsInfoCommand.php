<?php

/**
 * Show detailed information about a plugin.
 *
 * Supports composer-info, routes, config, classes, and paths views.
 *
 * @package   Enlivenapp\FlightSchool\Commands
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool\Commands;

use Enlivenapp\FlightSchool\PluginDiscoveryTrait;
use flight\commands\AbstractBaseCommand;

class PluginsInfoCommand extends AbstractBaseCommand
{
    use PluginDiscoveryTrait;

    /**
     * @param array $config Runway config.
     */
    public function __construct(array $config)
    {
        parent::__construct('plugins:info', 'Show information about a plugin', $config);
        $this->argument('[plugin]', 'Plugin name (e.g. enlivenapp/hello-world-plugin)');
        $this->argument('[option]', 'Info to show: composer-info, routes, config, classes, paths');
        $this->argument('[modifier]', 'Use "all" for full details (routes and config only)');
    }

    /**
     * Display the requested info view for a plugin.
     *
     * @param string|null $plugin   Package name.
     * @param string|null $option   Info type to display.
     * @param string|null $modifier Optional 'all' for full details.
     * @return void
     */
    public function execute(?string $plugin = null, ?string $option = null, ?string $modifier = null): void
    {
        $io = $this->app()->io();

        if (empty($plugin) || $option === null || $option === 'help') {
            $this->showUsage($io);
            return;
        }

        $validationError = $this->validatePluginName($plugin);
        if ($validationError !== null) {
            $io->error("  {$validationError}", true);
            return;
        }

        $vendorPath = $this->projectRoot . '/vendor';
        $pluginRoot = $vendorPath . '/' . $plugin;

        if (!is_dir($pluginRoot)) {
            $io->error("  Plugin '{$plugin}' not found in vendor/.", true);
            return;
        }

        $composerFile = $pluginRoot . '/composer.json';
        $composerData = file_exists($composerFile)
            ? json_decode(file_get_contents($composerFile), true) ?? []
            : [];

        $autoload = $composerData['autoload']['psr-4'] ?? [];
        $baseNamespace = !empty($autoload) ? rtrim(array_key_first($autoload), '\\') : null;

        $showAll = $modifier === 'all';

        match ($option) {
            'composer-info' => $this->showComposerInfo($io, $composerData),
            'routes' => $this->showRoutes($io, $plugin, $pluginRoot, $showAll),
            'config' => $this->showConfig($io, $plugin, $pluginRoot, $showAll),
            'classes' => $this->showClasses($io, $pluginRoot, $baseNamespace),
            'paths' => $this->showPaths($io, $pluginRoot),
            default => $this->showUsage($io),
        };
    }

    protected function showUsage($io): void
    {
        $io->write('', true);
        $io->boldGreen('plugins:info', true);
        $io->write('', true);
        $io->bold('Usage:', true);
        $io->write('  plugins:info <vendor/package> <option> [all]', true);
        $io->write('', true);
        $io->bold('Options:', true);
        $io->write('  composer-info    Name, description, type, license, and authors', true);
        $io->write('  routes           Route prefix, or all routes with "all"', true);
        $io->write('  config           Config prefix, or all config items with "all"', true);
        $io->write('  classes          All usable classes', true);
        $io->write('  paths            All registered paths (getPaths)', true);
        $io->write('', true);
        $io->bold('Examples:', true);
        $io->write('  plugins:info enlivenapp/hello-world-plugin composer-info', true);
        $io->write('  plugins:info enlivenapp/hello-world-plugin routes', true);
        $io->write('  plugins:info enlivenapp/hello-world-plugin routes all', true);
        $io->write('  plugins:info enlivenapp/hello-world-plugin config', true);
        $io->write('  plugins:info enlivenapp/hello-world-plugin config all', true);
        $io->write('  plugins:info enlivenapp/hello-world-plugin classes', true);
        $io->write('  plugins:info enlivenapp/hello-world-plugin paths', true);
        $io->write('', true);
    }

    protected function showComposerInfo($io, array $data): void
    {
        $io->write('', true);
        $io->bold('Composer Info:', true);
        $io->write('', true);
        $io->write('  Name:         ' . ($data['name'] ?? 'n/a'), true);
        $io->write('  Description:  ' . ($data['description'] ?? 'n/a'), true);
        $io->write('  Type:         ' . ($data['type'] ?? 'n/a'), true);
        $io->write('  License:      ' . ($data['license'] ?? 'n/a'), true);

        $authors = $data['authors'] ?? [];
        if (!empty($authors)) {
            $io->write('  Authors:', true);
            foreach ($authors as $author) {
                $name = $author['name'] ?? 'unknown';
                $email = $author['email'] ?? '';
                $io->write('    ' . $name . ($email ? " <{$email}>" : ''), true);
            }
        } else {
            $io->write('  Authors:      n/a', true);
        }
        $io->write('', true);
    }

    protected function showRoutes($io, string $plugin, string $pluginRoot, bool $showAll): void
    {
        $result = $this->readPrepends($plugin, $pluginRoot);
        $PluginPrefix = $result['PluginPrefix'];

        $io->write('', true);

        if (!$showAll) {
            $io->bold('Route prefix:', true);
            $io->write("  /{$PluginPrefix['route']}", true);
            $io->write('', true);
            return;
        }

        $io->bold('Routes:', true);
        $io->write("  Prefix: /{$PluginPrefix['route']}", true);
        $io->write('', true);

        $routesFile = $pluginRoot . '/src/Config/Routes.php';
        if (!file_exists($routesFile)) {
            $io->info('  No Routes.php found.', true);
            $io->write('', true);
            return;
        }

        $contents = file_get_contents($routesFile);
        $methods = ['get', 'post', 'put', 'patch', 'delete'];
        $pattern = '/\$router\s*->\s*(' . implode('|', $methods) . ')\s*\(\s*[\'"]([^\'"]+)[\'"]/i';

        if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $method = strtoupper($match[1]);
                $path = $match[2];
                $fullPath = '/' . $PluginPrefix['route'] . ($path === '/' ? '' : $path);
                $io->write('  ' . str_pad($method, 8) . $fullPath, true);
            }
        } else {
            $io->info('  No route definitions found.', true);
        }

        $io->write('', true);
    }

    protected function showConfig($io, string $plugin, string $pluginRoot, bool $showAll): void
    {
        $result = $this->readPrepends($plugin, $pluginRoot);
        $PluginPrefix = $result['PluginPrefix'];

        $io->write('', true);

        if (!$showAll) {
            $io->bold('Config prefix:', true);
            $io->write("  {$PluginPrefix['config']}", true);
            $io->write('', true);
            return;
        }

        $io->bold('Config:', true);
        $io->write("  Prefix: {$PluginPrefix['config']}", true);
        $io->write('', true);

        $configData = $result['data'];
        if (empty($configData)) {
            $io->info('  No config values.', true);
            $io->write('', true);
            return;
        }

        $this->printConfigItems($io, $configData, '  ');
        $io->write('', true);
    }

    protected function printConfigItems($io, array $items, string $indent): void
    {
        foreach ($items as $key => $value) {
            if (is_array($value)) {
                $io->write("{$indent}{$key}:", true);
                $this->printConfigItems($io, $value, $indent . '  ');
            } else {
                $display = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                $io->write("{$indent}{$key}: {$display}", true);
            }
        }
    }

    protected function showClasses($io, string $pluginRoot, ?string $baseNamespace): void
    {
        $io->write('', true);
        $io->bold('Classes:', true);
        $io->write('', true);

        if ($baseNamespace === null) {
            $io->error('  No PSR-4 autoload found in composer.json.', true);
            $io->write('', true);
            return;
        }

        $srcDir = $pluginRoot . '/src';
        if (!is_dir($srcDir)) {
            $io->info('  No src/ directory found.', true);
            $io->write('', true);
            return;
        }

        $classes = [];
        $this->scanForClasses($srcDir, $srcDir, $baseNamespace, $classes);

        if (empty($classes)) {
            $io->info('  No classes found.', true);
        } else {
            sort($classes);
            foreach ($classes as $class) {
                $io->write("  {$class}", true);
            }
        }

        $io->write('', true);
    }

    protected function scanForClasses(string $dir, string $srcRoot, string $baseNamespace, array &$classes): void
    {
        foreach (glob($dir . '/*') as $path) {
            if (is_dir($path)) {
                $dirName = basename($path);

                // Skip Config/ — those are bootstrap files, not classes
                if ($path === $srcRoot . '/Config') {
                    continue;
                }

                // Skip Views/ — templates, not classes
                if ($path === $srcRoot . '/Views') {
                    continue;
                }

                $this->scanForClasses($path, $srcRoot, $baseNamespace, $classes);
                continue;
            }

            if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            $relativePath = str_replace($srcRoot . '/', '', $path);
            $className = str_replace('/', '\\', substr($relativePath, 0, -4));
            $fqcn = $baseNamespace . '\\' . $className;
            $classes[] = $fqcn;
        }
    }

    protected function showPaths($io, string $pluginRoot): void
    {
        $io->write('', true);
        $io->bold('Paths:', true);
        $io->write('', true);

        $srcDir = $pluginRoot . '/src';
        if (!is_dir($srcDir)) {
            $io->info('  No src/ directory found.', true);
            $io->write('', true);
            return;
        }

        $dirs = glob($srcDir . '/*', GLOB_ONLYDIR);
        if (empty($dirs)) {
            $io->info('  No directories found.', true);
        } else {
            foreach ($dirs as $dir) {
                $name = basename($dir);
                $io->write("  {$name}/", true);
            }
        }

        $io->write('', true);
    }

    /**
     * Read Config.php to get prepend overrides and config data.
     *
     * Config.php may set $configPrepend and $routePrepend as bare
     * variables. If not set, defaults are derived from the package name.
     *
     * @param string $plugin     Package name (e.g. 'enlivenapp/hello-world-plugin').
     * @param string $pluginRoot Absolute path to the plugin's root directory.
     * @return array{PluginPrefix: array{config: string, route: string}, data: array}
     */
    protected function readPrepends(string $plugin, string $pluginRoot): array
    {
        $configFile = $pluginRoot . '/src/Config/Config.php';
        $data = [];
        $configPrepend = null;
        $routePrepend = null;

        if (file_exists($configFile)) {
            $result = require $configFile;
            if (is_array($result)) {
                $data = $result;
            }
        }

        // Apply defaults if Config.php didn't set overrides
        $configPrepend = $configPrepend ?? str_replace('/', '.', $plugin);
        $routePrepend = $routePrepend ?? str_replace(['/', '-'], '_', $plugin);

        return [
            'PluginPrefix' => [
                'config' => $configPrepend,
                'route' => $routePrepend,
            ],
            'data' => $data,
        ];
    }
}
