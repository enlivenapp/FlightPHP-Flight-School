<?php

/**
 * Composer plugin that wires Flight School into a FlightPHP project.
 *
 * On install of flight-school: adds the plugin loader service and a
 * plugins section to the app config. On install of any flightphp-*
 * package: adds a disabled config entry for the new plugin.
 *
 * @package   Enlivenapp\FlightSchool
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;

class ComposerPlugin implements PluginInterface, EventSubscriberInterface
{
    protected Composer $composer;
    protected IOInterface $io;

    /**
     * @param Composer    $composer The Composer instance.
     * @param IOInterface $io       Composer's I/O interface.
     * @return void
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /** @return void */
    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    /** @return void */
    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * Subscribe to post-package-install events.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
        ];
    }

    /**
     * Handle post-install: set up flight-school or add config for new plugins.
     *
     * @param PackageEvent $event The Composer package event.
     * @return void
     */
    public function onPostPackageInstall(PackageEvent $event): void
    {
        $package = $event->getOperation()->getPackage();
        $projectRoot = dirname($this->composer->getConfig()->get('vendor-dir'));

        if (!$this->isFlightSkeleton($projectRoot)) {
            $this->io->write('<warning>Flight School: FlightPHP skeleton not detected.</warning>');
            $this->io->write('<warning>  Run "composer create-project flightphp/skeleton" first, then require enlivenapp/flight-school.</warning>');
            $this->io->write('<warning>  Expected: app/config/services.php, app/config/config.php</warning>');
            return;
        }

        // When flight-school itself is installed, set up the project
        if ($package->getName() === 'enlivenapp/flight-school') {
            $this->installServices($projectRoot);
            $this->installPluginsSection($projectRoot, 'config.php');
            $this->installPluginsSection($projectRoot, 'config_sample.php');
            $this->io->write('<info>Flight School installed successfully.</info>');
            return;
        }

        // When any flightphp-* plugin is installed, add a disabled config entry
        $type = $package->getType();
        if (str_starts_with($type, 'flightphp-')) {
            $this->addPluginConfigEntry($projectRoot, $package->getName());
        }
    }

    /**
     * Check that the FlightPHP skeleton structure exists.
     *
     * @param string $projectRoot Absolute path to the project root.
     * @return bool
     */
    protected function isFlightSkeleton(string $projectRoot): bool
    {
        return is_dir($projectRoot . '/app/config')
            && file_exists($projectRoot . '/app/config/services.php')
            && (file_exists($projectRoot . '/app/config/config.php')
                || file_exists($projectRoot . '/app/config/config_sample.php'));
    }

    /**
     * Add a disabled config entry for a newly installed plugin.
     *
     * Only writes 'enabled' and 'priority'. Config values live in
     * the plugin's own src/Config/Config.php — the PluginLoader reads
     * those at runtime.
     *
     * @param string $projectRoot Absolute path to the project root.
     * @param string $packageName Composer package name.
     * @return void
     */
    protected function addPluginConfigEntry(string $projectRoot, string $packageName): void
    {
        foreach (['config.php', 'config_sample.php'] as $filename) {
            $file = $projectRoot . '/app/config/' . $filename;

            if (!file_exists($file)) {
                continue;
            }

            $io = $this->io;
            $this->lockedFileUpdate($file, function ($contents) use ($packageName, $filename, $io) {
                if (str_contains($contents, "'" . $packageName . "'")) {
                    return $contents;
                }

                $entry = "\t\t'" . $packageName . "' => [\n"
                       . "\t\t\t'enabled' => false,\n"
                       . "\t\t\t'priority' => 50,\n"
                       . "\t\t],";

                // Find the 'plugins' => [ block
                $pluginsPos = strpos($contents, "'plugins'");
                if ($pluginsPos === false) {
                    $io->write("<warning>  Could not find 'plugins' array in app/config/{$filename}.</warning>");
                    $io->write("<warning>  Please add the following to your 'plugins' array:</warning>");
                    $io->write($entry);
                    return $contents;
                }

                // Find the opening [ after 'plugins'
                $openBracket = strpos($contents, '[', $pluginsPos);
                if ($openBracket === false) {
                    $io->write("<warning>  Could not find 'plugins' array in app/config/{$filename}.</warning>");
                    $io->write("<warning>  Please add the following to your 'plugins' array:</warning>");
                    $io->write($entry);
                    return $contents;
                }

                // Count brackets to find the matching ]
                $depth = 0;
                $closePos = false;
                for ($i = $openBracket; $i < strlen($contents); $i++) {
                    if ($contents[$i] === '[') {
                        $depth++;
                    } elseif ($contents[$i] === ']') {
                        $depth--;
                        if ($depth === 0) {
                            $closePos = $i;
                            break;
                        }
                    }
                }

                if ($closePos === false) {
                    $io->write("<warning>  Could not find closing bracket for 'plugins' array in app/config/{$filename}.</warning>");
                    $io->write("<warning>  Please add the following to your 'plugins' array:</warning>");
                    $io->write($entry);
                    return $contents;
                }

                // Walk backward inside the plugins array, skip blanks and comments, add comma if needed
                $inside = substr($contents, $openBracket + 1, $closePos - $openBracket - 1);
                $lines = explode("\n", $inside);

                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    $trimmed = trim($lines[$i]);
                    if ($trimmed === '' || str_starts_with($trimmed, '//')) {
                        continue;
                    }
                    if (!str_ends_with($trimmed, ',')) {
                        $lines[$i] .= ',';
                    }
                    break;
                }

                $io->write("<info>  Plugin '{$packageName}' added to {$filename} (disabled). Enable it in config.php.</info>");

                return substr($contents, 0, $openBracket + 1)
                    . implode("\n", $lines) . "\n" . $entry . "\n"
                    . substr($contents, $closePos);
            });
        }
    }

    /**
     * Add the plugin loader service registration to services.php.
     *
     * @param string $projectRoot Absolute path to the project root.
     * @return void
     */
    protected function installServices(string $projectRoot): void
    {
        $file = $projectRoot . '/app/config/services.php';

        if (!file_exists($file)) {
            $this->io->write('<warning>services.php not found, skipping.</warning>');
            return;
        }

        $block = <<<'PHP'

/**********************************************
 *         Flight School Plugin Loader        *
 **********************************************
 * Discovers and loads plugins from Composer packages with type "flightphp-*"
 * in installed.json.
 *
 * Plugins must implement Enlivenapp\FlightSchool\PluginInterface.
 * Enable/disable and configure plugins in config.php under the 'plugins' key.
 *
 * Views: Plugins serve views from their src/views/ directory.
 * Override any plugin view by placing a file at app/views/{vendor}/{package}/
 *
 * Docs: https://github.com/enlivenapp/FlightPHP-Flight-School
 **********************************************/
$app->register('pluginLoader', \Enlivenapp\FlightSchool\PluginLoader::class, [
	$app,
	$app->router(),
	PROJECT_ROOT . '/vendor',
	$config['plugins'] ?? []
]);
$app->pluginLoader()->loadPlugins();
PHP;

        $io = $this->io;
        $marker = '// Add more service registrations below as needed';

        $this->lockedFileUpdate($file, function ($contents) use ($block, $marker, $io) {
            if (str_contains($contents, 'FlightSchool\\PluginLoader')) {
                $io->write('  Plugin loader already in services.php, skipping.');
                return $contents;
            }

            if (str_contains($contents, $marker)) {
                $io->write('  Added plugin loader to services.php');
                return str_replace($marker, $block . "\n\n" . $marker, $contents);
            }

            $io->write('  Added plugin loader to services.php');
            return $contents . "\n" . $block . "\n";
        });
    }

    /**
     * Add a 'plugins' section to a config file if one doesn't exist.
     *
     * @param string $projectRoot Absolute path to the project root.
     * @param string $filename    Config filename (e.g. 'config.php').
     * @return void
     */
    protected function installPluginsSection(string $projectRoot, string $filename): void
    {
        $file = $projectRoot . '/app/config/' . $filename;

        if (!file_exists($file)) {
            return;
        }

        $pluginsBlock = <<<'PHP'

	/**************************************
	 *        Plugins                    *
	 **************************************/
	'plugins' => [
		// 'enlivenapp/flight-blog' => [
		//     'enabled' => true,
		//     'priority' => 10,
		// ],
	],
PHP;

        $io = $this->io;

        $this->lockedFileUpdate($file, function ($contents) use ($pluginsBlock, $filename, $io) {
            if (str_contains($contents, "'plugins'")) {
                $io->write("  Plugins config already in {$filename}, skipping.");
                return $contents;
            }

            // Find the final ]; (end of return array)
            $endPos = strrpos($contents, '];');
            if ($endPos === false) {
                $io->write("<warning>  Could not automatically add plugins config to {$filename}.</warning>");
                $io->write("<warning>  Please add the following to your return array in app/config/{$filename}:</warning>");
                $io->write($pluginsBlock);
                return $contents;
            }

            // Split everything before ]; into lines
            $before = substr($contents, 0, $endPos);
            $lines = explode("\n", $before);

            // Walk backward, skip blank and comment lines, add trailing comma if needed
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $trimmed = trim($lines[$i]);
                if ($trimmed === '' || str_starts_with($trimmed, '//')) {
                    continue;
                }
                if (!str_ends_with($trimmed, ',')) {
                    $lines[$i] .= ',';
                }
                break;
            }

            $io->write("  Added plugins config to {$filename}");

            return implode("\n", $lines) . "\n" . $pluginsBlock . "\n" . substr($contents, $endPos);
        });
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
