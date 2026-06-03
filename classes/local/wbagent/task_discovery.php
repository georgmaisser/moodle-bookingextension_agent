<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace bookingextension_agent\local\wbagent;

use core_component;
use bookingextension_agent\local\wbagent\interfaces\task_interface;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

/**
 * Discovers agent classes below a component's local/wbagent tree.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_discovery {
    /** @var array<int,string> diagnostics collected during the last discovery run */
    private static array $lastdiagnostics = [];

    /**
     * Return all concrete task instances keyed by task name.
     *
     * Discovery is intentionally constrained to canonical task directories.
     * Broken task classes are skipped and reported as diagnostics so a single
     * third-party task cannot break the whole agent.
     *
     * @param string $component
     * @return array<string,task_interface>
     */
    public static function get_task_instances(string $component = 'bookingextension_agent'): array {
        self::$lastdiagnostics = [];
        $classes = self::find_candidate_classes($component);
        usort($classes, [self::class, 'compare_task_classes']);

        $tasks = [];
        foreach ($classes as $classname) {
            $instance = self::instantiate_if_supported($classname, task_interface::class);
            if (!$instance instanceof task_interface) {
                continue;
            }

            try {
                $taskname = trim($instance->get_name());
            } catch (\Throwable $e) {
                self::add_diagnostic('Skipping discovered task that failed get_name(): ' . $classname, $e);
                continue;
            }

            if ($taskname === '' || isset($tasks[$taskname])) {
                if ($taskname === '') {
                    self::add_diagnostic('Skipping discovered task with empty task name: ' . $classname);
                } else {
                    self::add_diagnostic('Skipping duplicate discovered task name: ' . $taskname . ' from ' . $classname);
                }
                continue;
            }

            $tasks[$taskname] = $instance;
        }

        ksort($tasks);
        return $tasks;
    }

    /**
     * Return all discovered trigger providers for the given component.
     *
     * Discovery is intentionally broad: every PHP class below
     * classes/local/wbagent is considered. Classes without the explicit trigger
     * provider interface are ignored silently.
     *
     * @param string $component
     * @return array<int,task_trigger_provider_interface>
     */
    public static function get_trigger_provider_instances(string $component = 'bookingextension_agent'): array {
        $providers = [];

        foreach (self::find_candidate_classes($component) as $classname) {
            $instance = self::instantiate_if_supported($classname, task_trigger_provider_interface::class);
            if (!$instance instanceof task_trigger_provider_interface) {
                continue;
            }

            $providers[] = $instance;
        }

        return $providers;
    }

    /**
     * Return diagnostics collected during the last discovery run.
     *
     * @return array<int,string>
     */
    public static function get_last_diagnostics(): array {
        return self::$lastdiagnostics;
    }

    /**
     * Find all PHP classes under a component's local/wbagent tree.
     *
     * @param string $component
     * @return array<int,string>
     */
    private static function find_candidate_classes(string $component): array {
        [$plugintype, $pluginname] = core_component::normalize_component($component);
        if ($plugintype === 'core' || empty($pluginname)) {
            return [];
        }

        $plugindir = core_component::get_plugin_directory($plugintype, $pluginname);
        if (empty($plugindir)) {
            return [];
        }

        $basedir = $plugindir . '/classes/local/wbagent';
        if (!is_dir($basedir)) {
            return [];
        }

        $classes = [];
        $classesdir = $plugindir . '/classes/';
        $taskdirs = self::get_task_directories($basedir);

        foreach ($taskdirs as $taskdir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($taskdir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileinfo) {
                if (!$fileinfo->isFile() || $fileinfo->getExtension() !== 'php') {
                    continue;
                }

                $path = $fileinfo->getPathname();
                if (strpos($path, '/tests/') !== false) {
                    continue;
                }

                $relative = substr($path, strlen($classesdir));
                if ($relative === false || $relative === '') {
                    continue;
                }

                $relativeclass = str_replace(['/', '.php'], ['\\', ''], $relative);
                $classes[] = core_component::normalize_componentname($component) . '\\' . $relativeclass;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * Return canonical task directories below local/wbagent.
     *
     * @param string $basedir
     * @return array<int,string>
     */
    private static function get_task_directories(string $basedir): array {
        $taskdirs = [];
        $iterator = new \DirectoryIterator($basedir);
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isDir() || $fileinfo->isDot()) {
                continue;
            }

            $taskdir = $fileinfo->getPathname() . '/tasks';
            if (is_dir($taskdir)) {
                $taskdirs[] = $taskdir;
            }
        }

        sort($taskdirs);
        return $taskdirs;
    }

    /**
     * Instantiate a class and return it when it implements the expected interface.
     *
     * @param string $classname
     * @param string $interfacename
     * @return object|null
     */
    private static function instantiate_if_supported(string $classname, string $interfacename): ?object {
        try {
            self::ensure_class_loaded($classname);

            $reflection = new \ReflectionClass($classname);
            if ($reflection->isAbstract()) {
                return null;
            }

            $instance = $reflection->newInstance();
        } catch (\Throwable $e) {
            self::add_diagnostic('Skipping discovered class that cannot be instantiated: ' . $classname, $e);
            return null;
        }

        return $instance instanceof $interfacename ? $instance : null;
    }

    /**
     * Ensure a discovered class file is loaded even when classloader caches are stale.
     *
     * New classes can be visible in filesystem discovery before Moodle's runtime
     * class map notices them. Resolve the expected file path from the component
     * namespace and include it directly as a safe fallback.
     *
     * @param string $classname
     * @return void
     */
    private static function ensure_class_loaded(string $classname): void {
        if (class_exists($classname, false)) {
            return;
        }

        $parts = explode('\\', $classname);
        if (count($parts) < 3) {
            return;
        }

        $component = $parts[0] . '_' . $parts[1];
        [$plugintype, $pluginname] = core_component::normalize_component($component);
        if ($plugintype === 'core' || empty($pluginname)) {
            return;
        }

        $plugindir = core_component::get_plugin_directory($plugintype, $pluginname);
        if (empty($plugindir)) {
            return;
        }

        $relativeparts = array_slice($parts, 2);
        if (empty($relativeparts)) {
            return;
        }

        $file = $plugindir . '/classes/' . implode('/', $relativeparts) . '.php';
        if (is_file($file)) {
            require_once($file);
        }
    }

    /**
     * Add a discovery diagnostic.
     *
     * @param string $message
     * @param \Throwable|null $exception
     * @return void
     */
    private static function add_diagnostic(string $message, ?\Throwable $exception = null): void {
        if ($exception !== null) {
            $message .= ' (' . get_class($exception) . ': ' . $exception->getMessage() . ')';
        }

        self::$lastdiagnostics[] = $message;
    }

    /**
     * Sort task classes so domain-specific namespaces win over core defaults.
     *
     * @param string $left
     * @param string $right
     * @return int
     */
    private static function compare_task_classes(string $left, string $right): int {
        $leftpriority = self::get_namespace_priority($left);
        $rightpriority = self::get_namespace_priority($right);

        if ($leftpriority !== $rightpriority) {
            return $rightpriority <=> $leftpriority;
        }

        return strcmp($left, $right);
    }

    /**
     * Return namespace priority for duplicate task resolution.
     *
     * @param string $classname
     * @return int
     */
    private static function get_namespace_priority(string $classname): int {
        $marker = '\\local\\wbagent\\';
        $position = strpos($classname, $marker);
        if ($position === false) {
            return 0;
        }

        $remainder = substr($classname, $position + strlen($marker));
        if ($remainder === false || $remainder === '') {
            return 0;
        }

        $parts = explode('\\', $remainder);
        if (count($parts) < 2 || $parts[1] !== 'tasks') {
            return 0;
        }

        return $parts[0] === 'core' ? 0 : 1;
    }
}
