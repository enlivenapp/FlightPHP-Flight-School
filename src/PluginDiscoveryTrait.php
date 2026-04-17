<?php

/**
 * Shared plugin discovery and config helpers for Runway CLI commands.
 *
 * Provides methods for discovering plugins, validating names,
 * reading/writing plugin config, and atomic file updates.
 *
 * @package   Enlivenapp\FlightSchool
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool;

trait PluginDiscoveryTrait
{
    /**
     * Discover vendor plugins with type starting with "flightphp-".
     *
     * @return array<string, string> Package name => 'vendor'
     */
    protected function discoverVendor(string $vendorPath): array
    {
        $installedFile = $vendorPath . '/composer/installed.json';

        if (!file_exists($installedFile)) {
            return [];
        }

        $installed = json_decode(file_get_contents($installedFile), true);
        $packages = $installed['packages'] ?? $installed;
        $discovered = [];

        foreach ($packages as $package) {
            $type = $package['type'] ?? 'library';
            $name = $package['name'] ?? '';

            if (str_starts_with($type, 'flightphp-')) {
                $discovered[$name] = 'vendor';
            }
        }

        return $discovered;
    }

    /**
     * Discover all plugins from vendor packages.
     *
     * @return array<string, string> Package name => source ('vendor')
     */
    protected function discoverAll(): array
    {
        return $this->discoverVendor($this->projectRoot . '/vendor');
    }

    /**
     * Validate a plugin name is vendor/package format with safe characters.
     *
     * @return string|null Error message, or null if valid
     */
    protected function validatePluginName(string $name): ?string
    {
        if (!str_contains($name, '/')) {
            return "Invalid plugin name for vendor '{$name}'. Use vendor/package format (e.g. enlivenapp/hello).";
        }

        $parts = explode('/', $name);

        if (count($parts) !== 2) {
            return "Invalid plugin name '{$name}'. Must be exactly vendor/package (e.g. enlivenapp/hello).";
        }

        [$vendor, $package] = $parts;

        if (empty($vendor) || empty($package)) {
            return "Invalid plugin name '{$name}'. Vendor and package can't be empty.";
        }

        $validPattern = '/^[a-z][a-z0-9_-]*$/';

        if (!preg_match($validPattern, $vendor)) {
            return "Invalid vendor name '{$vendor}'. Use lowercase letters, numbers, hyphens, and underscores only.";
        }

        if (!preg_match($validPattern, $package)) {
            return "Invalid package name '{$package}'. Use lowercase letters, numbers, hyphens, and underscores only.";
        }

        return null;
    }

    /**
     * Get the plugins config array from the loaded config.
     *
     * @return array<string, array>
     */
    protected function getPluginsConfig(): array
    {
        return $this->config['plugins'] ?? [];
    }

    /**
     * Update a plugin's config value in config.php.
     *
     * @param string $packageName Composer package name.
     * @param string $key         Config key to update (e.g. 'enabled').
     * @param mixed  $value       New value.
     * @return bool True if the update was applied.
     */
    protected function updatePluginConfig(string $packageName, string $key, mixed $value): bool
    {
        $file = $this->projectRoot . '/app/config/config.php';

        if (!file_exists($file)) {
            return false;
        }

        $valueStr = var_export($value, true);
        $safeValueStr = addcslashes($valueStr, '\\$');

        $escapedName = preg_quote($packageName, '/');
        $escapedKey = preg_quote($key, '/');
        $pattern = "/('{$escapedName}'\s*=>\s*\[.*?'{$escapedKey}'\s*=>\s*)([^,\]]+)/s";

        $updated = false;
        $this->lockedFileUpdate($file, function ($contents) use ($pattern, $safeValueStr, &$updated) {
            if (preg_match($pattern, $contents)) {
                $updated = true;
                return preg_replace($pattern, '${1}' . $safeValueStr, $contents);
            }
            return $contents;
        });

        return $updated;
    }

    /**
     * Atomically read, modify, and write a file under an exclusive lock.
     *
     * @param string   $file     Absolute path to the file.
     * @param callable $modifier Receives file contents, returns modified contents.
     * @return bool
     */
    protected function lockedFileUpdate(string $file, callable $modifier): bool
    {
        $fh = fopen($file, 'c+');
        if ($fh === false) {
            return false;
        }

        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return false;
        }

        $contents = stream_get_contents($fh);
        $modified = $modifier($contents);

        if ($modified !== $contents) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $modified);
            fflush($fh);
        }

        flock($fh, LOCK_UN);
        fclose($fh);
        return true;
    }
}
