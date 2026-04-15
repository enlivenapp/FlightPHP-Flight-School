<?php

/**
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool\Commands;

use Enlivenapp\FlightSchool\PluginDiscoveryTrait;
use flight\commands\AbstractBaseCommand;

class PluginsSyncCommand extends AbstractBaseCommand
{
    use PluginDiscoveryTrait;

    public function __construct(array $config)
    {
        parent::__construct('plugins:sync', 'Discover plugins and add missing entries to config.php', $config);
    }

    public function execute(): void
    {
        $io = $this->app()->io();
        $configPath = $this->projectRoot . '/app/config';

        $io->info('Scanning for plugins...', true);

        $allDiscovered = $this->discoverAll();

        if (empty($allDiscovered)) {
            $io->info('No plugins found.', true);
            return;
        }

        $io->info('Found ' . count($allDiscovered) . ' plugin(s):', true);
        foreach ($allDiscovered as $name => $source) {
            $io->write('  ' . $name . ' (' . $source . ')', true);
        }

        $added = 0;
        foreach (['config.php', 'config_sample.php'] as $filename) {
            $file = $configPath . '/' . $filename;

            if (!file_exists($file)) {
                continue;
            }

            $this->lockedFileUpdate($file, function ($contents) use ($allDiscovered, $filename, $io, &$added) {
                if (!str_contains($contents, "'plugins'")) {
                    $io->warn("  No 'plugins' section found in {$filename}. Run 'composer require enlivenapp/flight-school' to set it up.", true);
                    return $contents;
                }

                foreach ($allDiscovered as $packageName => $source) {
                    if (str_contains($contents, "'" . $packageName . "'")) {
                        continue;
                    }

                    $entry = "\t\t'" . $packageName . "' => [\n"
                           . "\t\t\t'enabled' => false,\n"
                           . "\t\t\t'config' => [],\n"
                           . "\t\t],";

                    $pattern = "/('plugins'\s*=>\s*\[)(.*?)(\n\t\])/s";

                    if (preg_match($pattern, $contents, $matches, \PREG_OFFSET_CAPTURE)) {
                        $insertPos = $matches[3][1];
                        $contents = substr($contents, 0, $insertPos)
                            . "\n" . $entry
                            . substr($contents, $insertPos);

                        if ($filename === 'config.php') {
                            $added++;
                            $io->ok("  Added '{$packageName}' to {$filename} (disabled)", true);
                        }
                    }
                }

                return $contents;
            });
        }

        if ($added === 0) {
            $io->info('All plugins are already in config.php.', true);
        } else {
            $io->ok($added . ' plugin(s) added to config.php. Enable them by setting \'enabled\' => true.', true);
        }
    }
}
