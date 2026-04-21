<?php

/**
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool;

use flight\template\View;

/**
 * Extends Flight's View to support plugin view resolution with app-level overrides.
 *
 * Resolution order for a template like 'enlivenapp/flight-blog/post':
 *   1. app/views/enlivenapp/flight-blog/post.php  (user override)
 *   2. vendor/enlivenapp/flight-blog/src/Views/post.php  (plugin default)
 */
class PluginView extends View
{
    /**
     * Registered plugin view paths keyed by package name.
     *
     * @var array<string, string>
     */
    protected array $pluginPaths = [];

    /**
     * The currently active plugin for automatic view resolution.
     */
    protected ?string $currentPlugin = null;

    /**
     * Register a plugin's view directory.
     *
     * @param string $packageName e.g. 'enlivenapp/flight-blog' or 'local/hello'
     * @param string $viewPath    Absolute path to the plugin's views directory
     */
    public function addPluginPath(string $packageName, string $viewPath): void
    {
        $this->pluginPaths[$packageName] = rtrim($viewPath, DIRECTORY_SEPARATOR);
    }

    /**
     * Set the active plugin context for view resolution.
     */
    public function setCurrentPlugin(?string $packageName): void
    {
        $this->currentPlugin = $packageName;
    }

    /**
     * Get the active plugin context.
     */
    public function getCurrentPlugin(): ?string
    {
        return $this->currentPlugin;
    }

    /**
     * Resolve a template file to its full path.
     *
     * Resolution order:
     *   1. Explicit plugin prefix (e.g. 'enlivenapp/flight-blog/post')
     *   2. Current plugin context (e.g. render('login') during a plugin route)
     *   3. Default app views
     *
     * For both 1 and 2, app overrides are checked first:
     *   app/views/{package}/{file} → plugin src/Views/{file}
     */
    public function getTemplate(string $file): string
    {
        $ext = $this->extension;

        if (!empty($ext) && (\substr($file, -1 * \strlen($ext)) != $ext)) {
            $file .= $ext;
        }

        // If it's an absolute path, return as-is
        $is_windows = \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN';
        if ((\substr($file, 0, 1) === '/') || ($is_windows && \substr($file, 1, 1) === ':')) {
            return $file;
        }

        // Check if this matches a registered plugin path (e.g. 'enlivenapp/flight-blog/post')
        foreach ($this->pluginPaths as $packageName => $pluginViewPath) {
            $prefix = $packageName . '/';

            if (str_starts_with($file, $prefix)) {
                $relativeFile = substr($file, strlen($prefix));
                return $this->resolvePluginView($packageName, $pluginViewPath, $relativeFile, $file);
            }
        }

        // If a current plugin is set, resolve relative to it
        if ($this->currentPlugin !== null && isset($this->pluginPaths[$this->currentPlugin])) {
            $pluginViewPath = $this->pluginPaths[$this->currentPlugin];
            $prefixedFile = $this->currentPlugin . '/' . $file;
            $resolved = $this->resolvePluginView($this->currentPlugin, $pluginViewPath, $file, $prefixedFile);
            if (file_exists($resolved)) {
                return $resolved;
            }
        }

        // Default Flight behavior for non-plugin views
        return $this->path . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * Resolve a view file within a plugin's context.
     * Checks app override first, then plugin's own views.
     *
     * @param string $packageName   Package name (e.g. 'enlivenapp/flight-shield')
     * @param string $pluginViewPath Absolute path to plugin's Views directory
     * @param string $relativeFile  File path relative to the views root (e.g. 'login.php')
     * @param string $prefixedFile  Full prefixed path for app override (e.g. 'enlivenapp/flight-shield/login.php')
     * @return string Resolved absolute path
     */
    protected function resolvePluginView(string $packageName, string $pluginViewPath, string $relativeFile, string $prefixedFile): string
    {
        // 1. Check app/views override first
        $overridePath = $this->path . DIRECTORY_SEPARATOR . $prefixedFile;
        if (file_exists($overridePath)) {
            $realOverride = realpath($overridePath);
            $realAppViews = realpath($this->path);
            if ($realOverride !== false && $realAppViews !== false
                && str_starts_with($realOverride, $realAppViews . DIRECTORY_SEPARATOR)) {
                return $overridePath;
            }
        }

        // 2. Fall back to plugin's own views
        $pluginFile = $pluginViewPath . DIRECTORY_SEPARATOR . $relativeFile;
        if (file_exists($pluginFile)) {
            $realPlugin = realpath($pluginFile);
            $realPluginViews = realpath($pluginViewPath);
            if ($realPlugin !== false && $realPluginViews !== false
                && str_starts_with($realPlugin, $realPluginViews . DIRECTORY_SEPARATOR)) {
                return $pluginFile;
            }
        }

        // Return the plugin path even if it doesn't exist (Flight will show its own error)
        return $pluginFile;
    }
}
