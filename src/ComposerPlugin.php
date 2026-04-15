<?php

/**
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

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
        ];
    }

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
     */
    protected function addPluginConfigEntry(string $projectRoot, string $packageName): void
    {
        foreach (['config.php', 'config_sample.php'] as $filename) {
            $file = $projectRoot . '/app/config/' . $filename;

            if (!file_exists($file)) {
                continue;
            }

            $this->lockedFileUpdate($file, function ($contents) use ($packageName) {
                if (str_contains($contents, "'" . $packageName . "'")) {
                    return $contents;
                }

                $entry = "\t\t'" . $packageName . "' => [\n"
                       . "\t\t\t'enabled' => false,\n"
                       . "\t\t\t'config' => [],\n"
                       . "\t\t],";

                $pattern = "/('plugins'\s*=>\s*\[)(.*?)(\n\t\])/s";

                if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                    $insertPos = $matches[3][1];
                    return substr($contents, 0, $insertPos)
                        . "\n" . $entry
                        . substr($contents, $insertPos);
                }

                return $contents;
            });
        }

        $this->io->write("<info>  Plugin '{$packageName}' added to config (disabled). Enable it in config.php.</info>");
    }

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
                return str_replace($marker, $block . "\n\n" . $marker, $contents);
            }

            return $contents . "\n" . $block . "\n";
        });

        $this->io->write('  Added plugin loader to services.php');
    }

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
		//     'config' => [],
		// ],
	],
PHP;

        $io = $this->io;

        $this->lockedFileUpdate($file, function ($contents) use ($pluginsBlock, $filename, $io) {
            if (str_contains($contents, "'plugins'")) {
                $io->write("  Plugins config already in {$filename}, skipping.");
                return $contents;
            }

            $pattern = '/(\])(\s*)(\/\/[^\n]*\n\s*)?\];/s';

            if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                $lastBracketPos = $matches[1][1];

                $afterBracket = substr($contents, $lastBracketPos + 1);
                $needsComma = !preg_match('/^\s*,/', $afterBracket);

                $insertion = ($needsComma ? ',' : '') . "\n" . $pluginsBlock . "\n";

                return substr($contents, 0, $lastBracketPos + 1)
                    . $insertion
                    . substr($contents, $lastBracketPos + 1);
            }

            return $contents;
        });

        $io->write("  Added plugins config to {$filename}");
    }

    /**
     * Atomically read, modify, and write a file under an exclusive lock.
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
