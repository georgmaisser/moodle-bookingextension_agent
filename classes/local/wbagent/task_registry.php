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

/**
 * Task schema registry.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wbagent;

use core_component;
use core_text;
use bookingextension_agent\local\wbagent\interfaces\result_summary_provider_interface;
use bookingextension_agent\local\wbagent\interfaces\summarizer\result_summary_contributor_interface;
use bookingextension_agent\local\wbagent\interfaces\task_interface;
use bookingextension_agent\local\wbagent\interfaces\task_provider_interface;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

/**
 * Central registry that maps task names to their provider instances.
 *
 * Providers register themselves here. The orchestrator uses the registry
 * to embed task schemas in the system prompt and the executor uses it to
 * dispatch commands to the correct provider.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_registry {
    /** @var array<string, task_provider_interface> component => provider instance */
    private array $providers = [];

    /** @var array<string, task_interface> task name => task instance */
    private array $tasks = [];

    /** @var array<string,array<string,mixed>> task name => normalized governance metadata */
    private array $taskcontracts = [];

    /** @var array<int,string> contract diagnostics collected during registration */
    private array $contractdiagnostics = [];

    /** @var array<int,result_summary_contributor_interface> */
    private array $resultsummarycontributors = [];

    /**
     * Register a task provider.  All tasks it declares are mapped to it.
     *
     * @param task_provider_interface $provider
     * @return void
     */
    public function register(task_provider_interface $provider): void {
        $this->providers[$provider->get_component()] = $provider;

        if ($provider instanceof result_summary_provider_interface) {
            try {
                $contributors = $provider->get_result_summary_contributors();
            } catch (\Throwable $e) {
                $this->add_contract_diagnostic(
                    'Ignoring result summary contributors from component ' . $provider->get_component()
                    . ' due to provider error: ' . get_class($e) . ': ' . $e->getMessage()
                );
                $contributors = [];
            }

            foreach ($contributors as $contributor) {
                if (!$contributor instanceof result_summary_contributor_interface) {
                    continue;
                }

                $class = get_class($contributor);
                $alreadyregistered = false;
                foreach ($this->resultsummarycontributors as $existing) {
                    if (get_class($existing) === $class) {
                        $alreadyregistered = true;
                        break;
                    }
                }

                if (!$alreadyregistered) {
                    $this->resultsummarycontributors[] = $contributor;
                }
            }
        }

        try {
            $providertasks = $provider->get_tasks();
        } catch (\Throwable $e) {
            $this->add_contract_diagnostic(
                'Ignoring AI tasks from component ' . $provider->get_component()
                . ' due to provider error: ' . get_class($e) . ': ' . $e->getMessage()
            );
            $this->append_provider_discovery_diagnostics($provider);
            $this->fail_on_contract_diagnostics_when_strict();
            return;
        }

        $this->append_provider_discovery_diagnostics($provider);

        foreach ($providertasks as $task) {
            if (!$task instanceof task_interface) {
                $this->add_contract_diagnostic(
                    'Ignoring non-task contribution from component ' . $provider->get_component()
                );
                continue;
            }

            try {
                $taskname = trim($task->get_name());
            } catch (\Throwable $e) {
                $this->add_contract_diagnostic(
                    'Ignoring AI task from component ' . $provider->get_component()
                    . ' because get_name() failed: ' . get_class($e) . ': ' . $e->getMessage()
                );
                continue;
            }

            if ($taskname === '') {
                $this->add_contract_diagnostic(
                    'Ignoring AI task with empty name from component ' . $provider->get_component()
                );
                continue;
            }

            $tasknamespace = task_contract_validator::extract_task_namespace($taskname);
            if (!task_contract_validator::component_may_register_namespace($provider->get_component(), $tasknamespace)) {
                $this->add_contract_diagnostic(
                    'Ignoring AI task because namespace is reserved: ' . $taskname
                    . ' (component: ' . $provider->get_component() . ')'
                );
                continue;
            }

            if (isset($this->tasks[$taskname])) {
                $this->add_contract_diagnostic(
                    'Duplicate AI task name detected: ' . $taskname
                    . ' (component: ' . $provider->get_component() . '). Keeping first registered task.'
                );
                continue;
            }

            try {
                $metadata = task_contract_validator::build_task_metadata($task, $provider->get_component());
            } catch (\Throwable $e) {
                $this->add_contract_diagnostic(
                    'Ignoring task due to metadata build error: ' . $taskname
                    . ' [' . get_class($e) . ': ' . $e->getMessage() . ']'
                );
                continue;
            }

            $validation = task_contract_validator::validate_task_metadata($metadata);
            if (!$validation['valid']) {
                $this->add_contract_diagnostic(
                    'Ignoring task due to contract validation errors: ' . $taskname
                    . ' [' . implode(' | ', (array)$validation['errors']) . ']'
                );
                continue;
            }

            $this->tasks[$taskname] = $task;
            $this->taskcontracts[$taskname] = $metadata;
        }

        $registryerrors = task_contract_validator::validate_registry_contracts($this->taskcontracts);
        foreach ($registryerrors as $error) {
            $this->add_contract_diagnostic($error);
        }

        $this->fail_on_contract_diagnostics_when_strict();
    }

    /**
     * Return the task for a given task name, or null if not found.
     *
     * @param string $taskname
     * @return task_interface|null
     */
    public function get_task(string $taskname): ?task_interface {
        return $this->tasks[$taskname] ?? null;
    }

    /**
     * Return all registered task names (the allow-list).
     *
     * @return string[]
     */
    public function get_task_names(): array {
        return array_keys($this->tasks);
    }

    /**
     * Return task names for the given user/context filtered by executability.
     *
     * @param task_executability_evaluator $evaluator
     * @param int $userid
     * @param int $contextid
     * @param bool $includeunavailable
     * @return array<int,string>
     */
    public function get_task_names_for_context(
        task_executability_evaluator $evaluator,
        int $userid,
        int $contextid,
        bool $includeunavailable = false
    ): array {
        if ($includeunavailable) {
            return $this->get_task_names();
        }

        return $evaluator->get_executable_task_names($userid, $contextid);
    }

    /**
     * Return all registered task instances.
     *
     * @return array
     */
    public function get_tasks(): array {
        return $this->tasks;
    }

    /**
     * Return normalized governance metadata for a single task.
     *
     * @param string $taskname
     * @return array<string,mixed>|null
     */
    public function get_task_contract(string $taskname): ?array {
        return $this->taskcontracts[$taskname] ?? null;
    }

    /**
     * Return normalized governance metadata for all registered tasks.
     *
     * @return array<string,array<string,mixed>>
     */
    public function get_task_contracts(): array {
        return $this->taskcontracts;
    }

    /**
     * Return contract diagnostics collected during provider/task registration.
     *
     * @return array<int,string>
     */
    public function get_contract_diagnostics(): array {
        return $this->contractdiagnostics;
    }

    /**
     * Return all registered result summary contributors.
     *
     * @return array<int,result_summary_contributor_interface>
     */
    public function get_result_summary_contributors(): array {
        return $this->resultsummarycontributors;
    }

    /**
     * Whether a task is read-only.
     *
     * @param string $taskname
     * @return bool
     */
    public function is_read_only_task(string $taskname): bool {
        $task = $this->get_task($taskname);
        return $task ? $task->is_read_only() : false;
    }

    /**
     * Return whether a task is active according to governance metadata.
     *
     * @param string $taskname
     * @return bool
     */
    public function is_task_active(string $taskname): bool {
        $meta = $this->get_task_contract($taskname);
        if ($meta === null) {
            return false;
        }

        if ((bool)get_config('bookingextension_agent', 'aitaskenableall')) {
            return true;
        }

        $settingname = self::get_task_toggle_setting_name($taskname);
        $configured = get_config('bookingextension_agent', $settingname);
        if ($configured !== false) {
            return (bool)$configured;
        }

        // Default-off for newly discovered tasks until explicitly enabled.
        return false;
    }

    /**
     * Return config key used for system-wide task enabled/disabled flag.
     *
     * @param string $taskname
     * @return string
     */
    public static function get_task_toggle_setting_name(string $taskname): string {
        $normalized = preg_replace('/[^a-z0-9]+/', '_', core_text::strtolower(trim($taskname)));
        $normalized = trim((string)$normalized, '_');
        if ($normalized === '') {
            $normalized = 'unknown_task';
        }

        return 'aitaskenabled_' . $normalized;
    }

    /**
     * Return task capability requirements from governance metadata.
     *
     * @param string $taskname
     * @return array<int,string>
     */
    public function get_task_capabilities(string $taskname): array {
        $meta = $this->get_task_contract($taskname);
        if ($meta === null) {
            return [];
        }

        return array_values((array)($meta['capabilities'] ?? []));
    }

    /**
     * Return schemas for all registered tasks (for inclusion in the system prompt).
     *
     * @return array  task name => schema array
     */
    public function get_all_schemas(): array {
        $schemas = [];
        foreach ($this->tasks as $name => $task) {
            $schemas[$name] = $task->get_schema();
        }
        return $schemas;
    }

    /**
     * Return schemas filtered for the given user/context executability.
     *
     * @param task_executability_evaluator $evaluator
     * @param int $userid
     * @param int $contextid
     * @param bool $includeunavailable
     * @return array<string,mixed>
     */
    public function get_all_schemas_for_context(
        task_executability_evaluator $evaluator,
        int $userid,
        int $contextid,
        bool $includeunavailable = false
    ): array {
        $schemas = [];
        $tasknames = $this->get_task_names_for_context($evaluator, $userid, $contextid, $includeunavailable);

        foreach ($tasknames as $name) {
            $task = $this->get_task((string)$name);
            if ($task === null) {
                continue;
            }

            $schemas[(string)$name] = $task->get_schema();
        }

        return $schemas;
    }

    /**
     * Return one task schema enriched with executability diagnostics.
     *
     * @param string $taskname
     * @param task_executability_evaluator $evaluator
     * @param int $userid
     * @param int $contextid
     * @return array<string,mixed>|null
     */
    public function explain_task_schema_for_context(
        string $taskname,
        task_executability_evaluator $evaluator,
        int $userid,
        int $contextid
    ): ?array {
        $task = $this->get_task($taskname);
        if ($task === null) {
            return null;
        }

        $schema = (array)$task->get_schema();
        $evaluation = $evaluator->evaluate_task($taskname, $userid, $contextid);
        $schema['executable_state'] = (string)($evaluation['executable_state'] ?? 'deny');
        $schema['deny_reason'] = (string)($evaluation['deny_reason'] ?? task_contract_validator::DENY_NOT_REGISTERED);
        $schema['governance_diagnostics'] = (array)($evaluation['diagnostics'] ?? []);

        return $schema;
    }

    /**
     * Return compact task metadata for system-prompt routing.
     *
     * This intentionally excludes full field descriptions so the initial prompt
     * stays small. Runtime validation continues to use the full task schemas.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_all_prompt_contracts(): array {
        $contracts = [];
        foreach ($this->tasks as $name => $task) {
            $contracts[] = $this->build_prompt_contract($name, $task);
        }
        return $contracts;
    }

    /**
     * Return prompt contracts filtered for the given user/context executability.
     *
     * @param task_executability_evaluator $evaluator
     * @param int $userid
     * @param int $contextid
     * @param bool $includeunavailable
     * @return array<int,array<string,mixed>>
     */
    public function get_prompt_contracts_for_context(
        task_executability_evaluator $evaluator,
        int $userid,
        int $contextid,
        bool $includeunavailable = false
    ): array {
        $allowed = array_fill_keys(
            $this->get_task_names_for_context($evaluator, $userid, $contextid, $includeunavailable),
            true
        );
        $contracts = [];

        foreach ($this->tasks as $name => $task) {
            if (!isset($allowed[$name])) {
                continue;
            }

            $contracts[] = $this->build_prompt_contract($name, $task);
        }

        return $contracts;
    }

    /**
     * Build compact prompt metadata for one task.
     *
     * Attempts to extract input_fields and anchor_fields from schema['prompt_meta'].
     * Falls back to legacy detection logic for tasks that don't declare prompt_meta.
     *
     * @param string $taskname
     * @param task_interface $task
     * @return array<string,mixed>
     */
    private function build_prompt_contract(string $taskname, task_interface $task): array {
        $schema = (array)$task->get_schema();
        $promptcontract = $task->get_prompt_contract()->to_array();
        $taskmeta = (array)($this->get_task_contract($taskname) ?? []);
        $minimalinput = array_values(array_filter(array_map('strval', (array)($promptcontract['minimal_input'] ?? []))));
        $anchorfields = array_values(array_filter(array_map('strval', (array)($promptcontract['anchors'] ?? []))));

        $exampleinput = is_array($promptcontract['example_input'] ?? null)
            ? (array)$promptcontract['example_input']
            : $task->get_example_input();

        $namespace = trim((string)($promptcontract['namespace'] ?? ''));
        if ($namespace === '' && strpos($taskname, '.') !== false) {
            $namespace = (string)substr($taskname, 0, (int)strpos($taskname, '.'));
        }
        if ($namespace === '') {
            $namespace = 'core';
        }

        $contractversion = max(1, (int)($promptcontract['version'] ?? 1));
        $metaversion = max(1, (int)($taskmeta['version'] ?? 1));
        $version = max($contractversion, $metaversion);

        $capabilities = array_values(array_unique(array_filter(array_map(
            'strval',
            !empty($promptcontract['capabilities'])
                ? (array)$promptcontract['capabilities']
                : (array)($taskmeta['capabilities'] ?? [])
        ))));

        $contextscopes = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($promptcontract['context_scopes'] ?? ['module'])
        ))));

        $messagetriggers = [];
        if ($task instanceof task_trigger_provider_interface) {
            try {
                $messagetriggers = array_values(array_filter((array)$task->get_message_triggers()));
            } catch (\Throwable $e) {
                $messagetriggers = [];
            }
        }

        return [
            'task' => $taskname,
            'description' => trim((string)($schema['description'] ?? '')),
            'readonly' => (bool)($schema['readonly'] ?? $task->is_read_only()),
            'intent' => trim((string)($promptcontract['intent'] ?? '')) !== ''
                ? trim((string)$promptcontract['intent'])
                : 'task',
            'anchors' => $anchorfields,
            'minimal_input' => $minimalinput,
            'example_input' => $exampleinput,
            'namespace' => $namespace,
            'version' => $version,
            'capabilities' => $capabilities,
            'context_scopes' => $contextscopes,
            'message_triggers' => $messagetriggers,
        ];
    }

    /**
     * Return all context-specific prompt packs from registered providers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        $packs = [];
        $seenids = [];

        foreach ($this->providers as $provider) {
            $providerpacks = $provider->get_contextual_prompt_packs();
            foreach ($providerpacks as $pack) {
                if (!is_array($pack)) {
                    continue;
                }
                $id = (string)($pack['id'] ?? '');
                if ($id === '' || isset($seenids[$id])) {
                    continue;
                }
                $seenids[$id] = true;
                $packs[] = $pack;
            }
        }

        return $packs;
    }

    /**
     * Return all message trigger definitions contributed by tasks.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        // Breaking cleanup: task semantics are routed by task catalog only.
        // Task-contributed message triggers are intentionally disabled.
        return [];
    }

    /**
     * Return a map of trigger-id → task-name for all registered trigger-providing tasks.
     *
     * @return array<string,string>  e.g. ['booking.explain_docs_topic_feature_help' => 'booking.explain_docs_topic']
     */
    public function get_trigger_id_to_task_name_map(): array {
        // Breaking cleanup: trigger-to-task routing is disabled.
        // Routing decisions must use the task catalog and command payload only.
        return [];
    }

    /**
     * Build and return the default registry loaded with all booking task providers.
     *
     * @return self
     */
    public static function make_default(): self {
        $registry = new self();
        $registeredcomponents = [];

        foreach (core_component::get_component_names() as $component) {
            $classname = '\\' . $component . '\\local\\wbagent\\task_provider';
            if (!class_exists($classname)) {
                continue;
            }

            try {
                $provider = new $classname();
            } catch (\Throwable $e) {
                $registry->add_contract_diagnostic(
                    'Ignoring AI task provider ' . $classname . ' because construction failed: '
                    . get_class($e) . ': ' . $e->getMessage()
                );
                continue;
            }

            if (!$provider instanceof task_provider_interface) {
                continue;
            }

            try {
                $registry->register($provider);
            } catch (\Throwable $e) {
                $registry->add_contract_diagnostic(
                    'Ignoring AI task provider ' . $classname . ' because registration failed: '
                    . get_class($e) . ': ' . $e->getMessage()
                );
                continue;
            }
            $registeredcomponents[$provider->get_component()] = true;
        }

        if (!isset($registeredcomponents['bookingextension/agent'])) {
            $provider = new task_provider();
            try {
                $registry->register($provider);
            } catch (\Throwable $e) {
                $registry->add_contract_diagnostic(
                    'Ignoring default bookingextension_agent provider because registration failed: '
                    . get_class($e) . ': ' . $e->getMessage()
                );
            }
        }

        $registry->fail_on_contract_diagnostics_when_strict();
        return $registry;
    }

    /**
     * Append optional discovery diagnostics exposed by a provider.
     *
     * @param task_provider_interface $provider
     * @return void
     */
    private function append_provider_discovery_diagnostics(task_provider_interface $provider): void {
        if (!method_exists($provider, 'get_discovery_diagnostics')) {
            return;
        }

        try {
            $diagnostics = (array)$provider->get_discovery_diagnostics();
        } catch (\Throwable $e) {
            $this->add_contract_diagnostic(
                'Could not read discovery diagnostics for component ' . $provider->get_component()
                . ': ' . get_class($e) . ': ' . $e->getMessage()
            );
            return;
        }

        foreach ($diagnostics as $diagnostic) {
            $this->add_contract_diagnostic((string)$diagnostic);
        }
    }

    /**
     * Append a contract diagnostic and forward to developer debugging output.
     *
     * @param string $message
     * @return void
     */
    private function add_contract_diagnostic(string $message): void {
        $message = trim($message);
        if ($message === '') {
            return;
        }

        $this->contractdiagnostics[] = $message;
    }

    /**
     * Throw when strict governance mode is enabled and diagnostics exist.
     *
     * @return void
     */
    private function fail_on_contract_diagnostics_when_strict(): void {
        if (!$this->is_governance_strict_mode_enabled()) {
            return;
        }

        if (empty($this->contractdiagnostics)) {
            return;
        }

        throw new \coding_exception(
            'AI task governance strict mode blocked registry initialization: '
            . implode(' || ', $this->contractdiagnostics)
        );
    }

    /**
     * Whether governance strict mode is enabled via plugin config.
     *
     * @return bool
     */
    private function is_governance_strict_mode_enabled(): bool {
        return (bool)get_config('bookingextension_agent', 'aigovernancestrictmode');
    }
}
