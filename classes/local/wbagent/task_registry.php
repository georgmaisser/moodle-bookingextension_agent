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
 * @package    mod_booking
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
 * @package    mod_booking
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
            foreach ($provider->get_result_summary_contributors() as $contributor) {
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

        foreach ($provider->get_tasks() as $task) {
            $taskname = trim($task->get_name());
            if ($taskname === '') {
                $this->add_contract_diagnostic(
                    'Ignoring AI task with empty name from component ' . $provider->get_component()
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

            $metadata = task_contract_validator::build_task_metadata($task, $provider->get_component());
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
        $properties = (array)($schema['properties'] ?? []);
        $promptmeta = (array)($schema['prompt_meta'] ?? []);

        // Extract input fields: prefer schema metadata, fall back to legacy detection.
        $minimalinput = [];
        if (!empty($promptmeta['input_fields_for_prompt']) && is_array($promptmeta['input_fields_for_prompt'])) {
            $minimalinput = array_values(array_filter($promptmeta['input_fields_for_prompt']));
        } else {
            $minimalinput = $this->build_minimal_input_fields($taskname, $properties);
        }

        // Extract anchor fields: prefer schema metadata, fall back to legacy detection.
        $anchorfields = [];
        if (!empty($promptmeta['anchor_fields']) && is_array($promptmeta['anchor_fields'])) {
            $anchorfields = array_values(array_filter($promptmeta['anchor_fields']));
        } else {
            $anchorfields = $this->extract_anchor_fields($properties);
        }

        $exampleinput = $task->get_example_input();

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
            'intent' => $this->detect_task_intent($taskname, $schema),
            'anchors' => $anchorfields,
            'minimal_input' => $minimalinput,
            'example_input' => $exampleinput,
            'message_triggers' => $messagetriggers,
        ];
    }

    /**
     * Build minimal planner input fields when schema prompt_meta is absent.
     *
     * @param string $taskname
     * @param array<string,mixed> $properties
     * @return array<int,string>
     */
    private function build_minimal_input_fields(string $taskname, array $properties): array {
        $fields = [];

        foreach ($properties as $name => $spec) {
            if (!is_string($name) || $name === '' || !is_array($spec)) {
                continue;
            }
            if (!empty($spec['required'])) {
                $fields[] = $name;
            }
        }

        if (empty($fields)) {
            $preferred = [
                'query',
                'question',
                'scope',
                'taskname',
                'optionquery',
                'userquery',
                'coursequery',
                'doc_path',
                'doc_path_candidates',
                'topic_hint',
            ];

            foreach ($preferred as $name) {
                if (array_key_exists($name, $properties)) {
                    $fields[] = $name;
                }
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Derive compact anchor fields from available task properties.
     *
     * @param array<string,mixed> $properties
     * @return array<int,string>
     */
    private function extract_anchor_fields(array $properties): array {
        $anchors = [];

        $keys = array_map('strval', array_keys($properties));
        $has = static function (string $needle) use ($keys): bool {
            return in_array($needle, $keys, true);
        };

        if ($has('optionquery') || $has('optionid') || $has('optionids')) {
            $anchors[] = 'option';
        }
        if ($has('userquery') || $has('userid') || $has('userids')) {
            $anchors[] = 'user';
        }
        if ($has('courseid') || $has('coursequery') || $has('courseids')) {
            $anchors[] = 'course';
        }
        if ($has('question')) {
            $anchors[] = 'question';
        }
        if ($has('doc_path') || $has('doc_path_candidates') || $has('search_queries') || $has('topic_hint')) {
            $anchors[] = 'docs';
        }

        return array_values(array_unique($anchors));
    }

    /**
     * Derive a compact intent label for routing.
     *
     * @param string $taskname
     * @param array $schema
     * @return string
     */
    private function detect_task_intent(string $taskname, array $schema): string {
        if (!empty($schema['readonly'])) {
            if (strpos($taskname, '.diagnose_') !== false) {
                return 'diagnose';
            }
            if (strpos($taskname, '.explain_') !== false) {
                return 'explain';
            }
            if (strpos($taskname, '.search_') !== false) {
                return 'search';
            }
            if (strpos($taskname, '.list_') !== false) {
                return 'list';
            }
            if (strpos($taskname, '.get_') !== false) {
                return 'get';
            }
            return 'read';
        }

        if (strpos($taskname, '.bulk_') !== false) {
            return 'bulk_update';
        }
        if (strpos($taskname, '.create_') !== false) {
            return 'create';
        }
        if (strpos($taskname, '.update_') !== false) {
            return 'update';
        }
        if (strpos($taskname, '.add_') !== false) {
            return 'add';
        }
        if (strpos($taskname, '.book_') !== false) {
            return 'book';
        }

        return 'mutate';
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
                continue;
            }

            if (!$provider instanceof task_provider_interface) {
                continue;
            }

            try {
                $registry->register($provider);
            } catch (\Throwable $e) {
                continue;
            }
            $registeredcomponents[$provider->get_component()] = true;
        }

        if (!isset($registeredcomponents['bookingextension_agent'])) {
            $provider = new task_provider();
            $registry->register($provider);
        }

        return $registry;
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
        \debugging($message, DEBUG_DEVELOPER);
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
