<?php

/**
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool;

use flight\template\View;

/**
 * Extends Flight's View to support plugin view resolution with app-level overrides
 * and active theme view override tier.
 *
 * If enlivenapp/vision is installed, public views render through Vision (.tpl) —
 * no PHP execution. Admin views use Flight's native PHP renderer (.php).
 * Without Vision installed, all views use native PHP rendering.
 *
 * Resolution order for a template like 'enlivenapp/flight-shield/login':
 *   1. app/views/enlivenapp/flight-shield/login.tpl  (site owner override)
 *   2. themes/{active}/Views/enlivenapp/flight-shield/login.tpl  (theme override)
 *   3. vendor/enlivenapp/flight-shield/src/Views/login.tpl  (plugin default)
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
     * Route prefixes that use native PHP rendering when Vision is available.
     * Everything else goes through Vision.
     *
     * @var string[]
     */
    protected array $nativeRenderPrefixes = ['/admin'];

    /** Vision engine instance (lazy-loaded). */
    private ?object $visionEngine = null;

    /** Active theme Views/ directory path (null if no theme active). */
    protected ?string $themePath = null;

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
     * Get the registered view path for a plugin package.
     */
    public function getPluginPath(string $packageName): ?string
    {
        return $this->pluginPaths[$packageName] ?? null;
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
     * Set the active theme's Views/ directory for the theme override tier.
     */
    public function setThemePath(?string $path): void
    {
        $this->themePath = $path !== null ? rtrim($path, DIRECTORY_SEPARATOR) : null;
    }

    /**
     * Get the active theme's Views/ directory.
     */
    public function getThemePath(): ?string
    {
        return $this->themePath;
    }

    /**
     * Add a route prefix that should use native PHP rendering.
     */
    public function addNativeRenderPrefix(string $prefix): void
    {
        $this->nativeRenderPrefixes[] = $prefix;
    }

    /**
     * Whether Vision is available (enlivenapp/vision is installed).
     */
    protected function hasVision(): bool
    {
        return class_exists(\Enlivenapp\Vision\Engine::class);
    }

    /**
     * Get the Vision engine instance for filter/tag registration.
     * Returns null if Vision is not installed.
     */
    public function vision(): ?object
    {
        if ($this->visionEngine === null && $this->hasVision()) {
            $this->visionEngine = new \Enlivenapp\Vision\Engine();
            $this->registerDefaultTags();
        }
        return $this->visionEngine;
    }

    /**
     * Register built-in Vision tags (csrf_field, etc.).
     */
    protected function registerDefaultTags(): void
    {
        if ($this->visionEngine === null) {
            return;
        }

        // {% csrf_field %} outputs a hidden CSRF token input
        $this->visionEngine->tags()->register('csrf_field', function () {
            if (function_exists('csrf_field')) {
                return csrf_field();
            }
            return '';
        });
    }

    /**
     * Check if the current request should use native PHP rendering.
     */
    protected function isNativeRender(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';

        foreach ($this->nativeRenderPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render a template.
     *
     * Native PHP routes (admin) use Flight's include-based rendering.
     * All other routes use Vision — no PHP execution in templates.
     */
    public function render(string $file, ?array $templateData = null): void
    {
        if (!$this->hasVision() || $this->isNativeRender()) {
            $this->extension = '.php';
            parent::render($file, $templateData);
            return;
        }

        // Public view — Vision engine, .tpl only
        $this->extension = '.tpl';
        $template = $this->getTemplate($file);

        if (!file_exists($template)) {
            $normalized = self::normalizePath($template);
            throw new \Exception("Template file not found: {$normalized}.");
        }

        $data = $this->vars;
        if (is_array($templateData)) {
            $data = array_merge($data, $templateData);
            if ($this->preserveVars) {
                $this->vars = array_merge($this->vars, $templateData);
            }
        }

        // Use active theme's Views/ as basePath for includes/extends resolution
        $basePath = $this->themePath
            ? ($this->themePath . DIRECTORY_SEPARATOR)
            : (dirname($template) . '/');

        echo $this->vision()->render($template, $data, $basePath);
    }

    /**
     * Resolve a template file to its full path.
     *
     * Resolution order:
     *   1. Explicit plugin prefix (e.g. 'enlivenapp/flight-blog/post')
     *   2. Current plugin context (e.g. render('login') during a plugin route)
     *   3. Default app views
     *
     * For both 1 and 2, resolution is:
     *   app/views/{package}/{file} → theme Views/{package}/{file} → plugin src/Views/{file}
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
     *
     * Resolution order:
     *   1. app/views/{package}/{file}  (site owner override)
     *   2. themes/{active}/Views/{package}/{file}  (theme override)
     *   3. vendor/{package}/src/Views/{file}  (plugin default)
     *
     * @param string $packageName   Package name (e.g. 'enlivenapp/flight-shield')
     * @param string $pluginViewPath Absolute path to plugin's Views directory
     * @param string $relativeFile  File path relative to the views root (e.g. 'login.tpl')
     * @param string $prefixedFile  Full prefixed path for app override (e.g. 'enlivenapp/flight-shield/login.tpl')
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

        // 2. Check active theme override
        if ($this->themePath !== null) {
            $themOverride = $this->themePath . DIRECTORY_SEPARATOR . $prefixedFile;
            if (file_exists($themOverride)) {
                $realTheme = realpath($themOverride);
                $realThemeViews = realpath($this->themePath);
                if ($realTheme !== false && $realThemeViews !== false
                    && str_starts_with($realTheme, $realThemeViews . DIRECTORY_SEPARATOR)) {
                    return $themOverride;
                }
            }
        }

        // 3. Fall back to plugin's own views
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
