<?php

/**
 * Scan for new plugins and add missing entries to config.php.
 *
 * @package   Enlivenapp\FlightSchool\Commands
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

    /**
     * @param array $config Runway config.
     */
    public function __construct(array $config)
    {
        parent::__construct('plugins:sync', 'Discover plugins and add missing entries to config.php', $config);
    }

    /**
     * Discover plugins and add missing config entries.
     *
     * @return void
     */
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

                // Find the 'plugins' => [ block
                $pluginsPos = strpos($contents, "'plugins'");
                if ($pluginsPos === false) {
                    return $contents;
                }

                $openBracket = strpos($contents, '[', $pluginsPos);
                if ($openBracket === false) {
                    return $contents;
                }

                foreach ($allDiscovered as $packageName => $source) {
                    if (str_contains($contents, "'" . $packageName . "'")) {
                        continue;
                    }

                    $entry = "\t\t'" . $packageName . "' => [\n"
                           . "\t\t\t'enabled' => false,\n"
                           . "\t\t\t'priority' => 50,\n"
                           . "\t\t],";

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
                        $io->warn("  Could not find closing bracket for 'plugins' array in {$filename}.", true);
                        return $contents;
                    }

                    // Walk backward inside the plugins array, skip blanks and comments, add comma if needed
                    $inside = substr($contents, $openBracket + 1, $closePos - $openBracket - 1);
                    $lines = explode("\n", $inside);

                    for ($j = count($lines) - 1; $j >= 0; $j--) {
                        $trimmed = trim($lines[$j]);
                        if ($trimmed === '' || str_starts_with($trimmed, '//')) {
                            continue;
                        }
                        if (!str_ends_with($trimmed, ',')) {
                            $lines[$j] .= ',';
                        }
                        break;
                    }

                    $contents = substr($contents, 0, $openBracket + 1)
                        . implode("\n", $lines) . "\n" . $entry . "\n"
                        . substr($contents, $closePos);

                    if ($filename === 'config.php') {
                        $added++;
                        $io->ok("  Added '{$packageName}' to {$filename} (disabled)", true);
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
