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
 *   2. vendor/enlivenapp/flight-blog/src/views/post.php  (plugin default)
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
     * Gets the full path to a template file.
     * Checks app views first, then plugin views, then falls back to default.
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

                // 1. Check app/views override first
                $overridePath = $this->path . DIRECTORY_SEPARATOR . $file;
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
            }
        }

        // Default Flight behavior for non-plugin views
        return $this->path . DIRECTORY_SEPARATOR . $file;
    }
}
