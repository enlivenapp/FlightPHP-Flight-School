<?php

/**
 * Admin extension point registry for plugin content injection.
 *
 * Plugins register contributions during the load cycle.
 * Admin reads contributions at render time.
 *
 * @package   Enlivenapp\FlightSchool
 * @copyright 2026 enlivenapp
 * @license   MIT
 */

declare(strict_types=1);

namespace Enlivenapp\FlightSchool;

class AdminExtend
{
    /** @var array<string, array<string, array<string, array>>> Contributions keyed by type, name, then contributor key */
    protected array $extensions = [];

    /**
     * Register a contribution to a typed extension point.
     *
     * @param string $type   Extension type (e.g. 'menu', 'page')
     * @param string $name   Extension name (e.g. 'content', 'users.edit.tabs')
     * @param string $key    Unique contributor key (e.g. 'pubvana.blog', 'pubvana.profile')
     * @param array  $config Contribution data — shape depends on the type contract
     */
    public function register(string $type, string $name, string $key, array $config): void
    {
        if (isset($this->extensions[$type][$name][$key])) {
            error_log("Flight School AdminExtend: duplicate key '{$key}' in [{$type}][{$name}] — registration rejected.");
            return;
        }

        // Prepend /admin to urls — this is an admin extension system
        if (isset($config['url'])) {
            $config['url'] = '/admin' . $config['url'];
        }
        if (!empty($config['submenu'])) {
            foreach ($config['submenu'] as $subKey => $sub) {
                if (isset($sub['url'])) {
                    $config['submenu'][$subKey]['url'] = '/admin' . $sub['url'];
                }
            }
        }

        $this->extensions[$type][$name][$key] = $config;
    }

    /**
     * Get all contributions for a typed extension point, sorted by priority.
     *
     * When context is provided and a contribution has a 'callable',
     * the callable is invoked with the context and its return value
     * is merged into the contribution array.
     *
     * @param string $type    Extension type (e.g. 'menu', 'page')
     * @param string $name    Extension name
     * @param array  $context Optional context passed to callables (e.g. ['user_id' => 5])
     * @return array<string, array> Contributions sorted by priority, keyed by contributor key
     */
    public function get(string $type, string $name, array $context = []): array
    {
        $items = $this->extensions[$type][$name] ?? [];

        if (!empty($context)) {
            foreach ($items as $key => &$item) {
                if (isset($item['callable']) && is_callable($item['callable'])) {
                    $result = call_user_func($item['callable'], $context);
                    if (is_array($result)) {
                        if (isset($result['post_url'])) {
                            $result['post_url'] = '/admin' . $result['post_url'];
                        }
                        if (isset($result['return_url'])) {
                            $result['return_url'] = '/admin' . $result['return_url'];
                        }
                        $item = array_merge($item, $result);
                    }
                }
            }
            unset($item);
        }

        uasort($items, fn($a, $b) => ($a['priority'] ?? 50) <=> ($b['priority'] ?? 50));

        // Sort submenu items by priority when present
        foreach ($items as &$item) {
            if (!empty($item['submenu']) && is_array($item['submenu'])) {
                uasort($item['submenu'], fn($a, $b) => ($a['priority'] ?? 50) <=> ($b['priority'] ?? 50));
            }
        }
        unset($item);

        return $items;
    }

    /**
     * Check if a typed extension point has any contributions.
     *
     * @param string $type Extension type
     * @param string $name Extension name
     * @return bool
     */
    public function has(string $type, string $name): bool
    {
        return !empty($this->extensions[$type][$name]);
    }
}
