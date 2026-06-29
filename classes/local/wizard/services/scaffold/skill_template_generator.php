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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\scaffold;

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\skill_contract_validator;
use core_text;

/**
 * Deterministic generator for third-party skill templates.
 *
 * Produces a ready-to-drop, heavily-commented skill class plus the wiring snippets a developer
 * needs, packaged as a downloadable ZIP. It NEVER emits business logic: run_preflight()/execute()
 * bodies are guided TODO comments and execute() is an inert "not implemented yet" placeholder, so
 * the agent (and Wunderbyte) never authors the third party's actual behaviour.
 *
 * The output is contract-valid by construction (the spec is validated against the same rules the
 * runtime applies via {@see skill_contract_validator}).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_template_generator {
    /** @var string Placeholder message rendered by an unfinished skill. */
    public const NOT_IMPLEMENTED_MESSAGE =
        'This skill has not implemented its actual task yet.';

    /**
     * Generate the template bundle for a structured skill spec.
     *
     * @param array<string,mixed> $spec see {@see self::normalize_spec()} for accepted keys
     * @return array{
     *     files: array<string,string>,
     *     zip_filename: string,
     *     zip_base64: string,
     *     skillname: string,
     *     capability: string,
     *     relativepath: string,
     *     warnings: array<int,string>
     * }
     * @throws \invalid_parameter_exception when the spec cannot produce a contract-valid skill
     */
    public static function generate(array $spec): array {
        $spec = self::normalize_spec($spec);
        self::validate_spec($spec);

        $skillfile = self::build_skill_php($spec);
        $relativepath = 'classes/local/wizard/' . $spec['domain'] . '/skills/' . $spec['classfile'];

        $files = [
            $relativepath => $skillfile,
            'README.md' => self::build_readme($spec, $relativepath),
            'snippets/db_access.php.txt' => self::build_access_snippet($spec),
            'snippets/lang_strings.php.txt' => self::build_lang_snippet($spec),
        ];

        [$zipbase64, $zipfilename] = self::build_zip($spec, $files);

        return [
            'files' => $files,
            'zip_filename' => $zipfilename,
            'zip_base64' => $zipbase64,
            'skillname' => $spec['skillname'],
            'capability' => $spec['capability'],
            'relativepath' => $relativepath,
            'warnings' => $spec['warnings'],
        ];
    }

    /**
     * Normalize a raw spec into the canonical shape used by the builders.
     *
     * Accepted raw keys: component (required), skillname, description (required), intent,
     * risk_class, properties[], anchor_fields[], context_scopes[], capabilities[], triggers[].
     *
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private static function normalize_spec(array $spec): array {
        $warnings = [];

        $component = trim(core_text::strtolower((string)($spec['component'] ?? '')));
        $component = str_replace('\\', '/', $component);
        $namespaceroot = str_replace('/', '_', $component);

        $description = trim((string)($spec['description'] ?? ''));

        // Risk class + read-only are coupled: R0 == read-only, everything else mutates.
        $risk = trim((string)($spec['risk_class'] ?? skill_risk_class::R0));
        if (!skill_risk_class::is_valid($risk)) {
            $warnings[] = "Unknown risk_class '{$risk}', defaulting to read_only.";
            $risk = skill_risk_class::R0;
        }
        $readonly = ($risk === skill_risk_class::R0);

        // Skill name: keep a valid namespaced name or derive one from component + description.
        $skillname = trim(core_text::strtolower((string)($spec['skillname'] ?? '')));
        if (!skill_contract_validator::is_namespaced_skill_name($skillname)) {
            $namespace = self::derive_namespace($component);
            $action = self::slugify($description !== '' ? $description : 'do_something');
            $skillname = $namespace . '.' . $action;
            $warnings[] = "Derived skill name '{$skillname}' - rename it to something meaningful.";
        }
        $namespace = skill_contract_validator::extract_skill_namespace($skillname);
        $action = substr($skillname, strpos($skillname, '.') + 1);

        $properties = self::normalize_properties((array)($spec['properties'] ?? []));
        $anchors = self::normalize_string_list((array)($spec['anchor_fields'] ?? []));
        $scopes = self::normalize_string_list((array)($spec['context_scopes'] ?? []));
        $capabilities = self::normalize_string_list((array)($spec['capabilities'] ?? []));
        $triggers = self::normalize_triggers((array)($spec['triggers'] ?? []), $description);

        return [
            'component' => $component,
            'namespaceroot' => $namespaceroot,
            'namespace' => $namespace,
            'action' => $action,
            'skillname' => $skillname,
            'classname' => $action . '_skill',
            'classfile' => $action . '_skill.php',
            'domain' => trim((string)($spec['domain'] ?? $namespace)) ?: $namespace,
            'description' => $description !== '' ? $description : 'TODO: describe what this skill does.',
            'intent' => trim((string)($spec['intent'] ?? '')) ?: $description,
            'risk_class' => $risk,
            'readonly' => $readonly,
            'risk_const' => self::risk_constant_name($risk),
            'properties' => $properties,
            'anchor_fields' => $anchors !== [] ? $anchors : array_slice(array_column($properties, 'name'), 0, 1),
            'context_scopes' => $scopes,
            'capabilities' => $capabilities,
            'triggers' => $triggers,
            'capability' => skill_contract_validator::build_skill_capability_name($component, $skillname),
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate the normalized spec against the runtime contract rules so the output is usable.
     *
     * @param array<string,mixed> $spec
     * @return void
     * @throws \invalid_parameter_exception
     */
    private static function validate_spec(array $spec): void {
        if ($spec['component'] === '') {
            throw new \invalid_parameter_exception('A target component (e.g. "mod/myplugin") is required.');
        }
        if (!skill_contract_validator::is_namespaced_skill_name($spec['skillname'])) {
            throw new \invalid_parameter_exception('Could not build a valid namespaced skill name.');
        }
        if (!skill_contract_validator::component_may_register_namespace($spec['component'], $spec['namespace'])) {
            throw new \invalid_parameter_exception(
                "Component '{$spec['component']}' may not register the reserved namespace '{$spec['namespace']}'. "
                . 'Choose a namespace specific to your plugin.'
            );
        }
        // Broad/irreversible skills must declare explicit context scopes (mirrors the validator).
        $mutatingbroad = in_array($spec['risk_class'], [skill_risk_class::R2, skill_risk_class::R3], true);
        if ($mutatingbroad && empty($spec['context_scopes'])) {
            throw new \invalid_parameter_exception(
                'Broad or irreversible skills (R2/R3) must declare at least one context scope '
                . '(e.g. "module", "course", "user").'
            );
        }
        if ($spec['capability'] === '') {
            throw new \invalid_parameter_exception('Could not derive a capability name for the skill.');
        }
    }

    /**
     * Build the fully-commented skill class file.
     *
     * @param array<string,mixed> $spec
     * @return string
     */
    private static function build_skill_php(array $spec): string {
        $namespace = $spec['namespaceroot'] . '\\local\\wizard\\' . $spec['domain'] . '\\skills';
        $useprefix = 'bookingextension_agent\\local\\wizard';

        $template = <<<'PHP'
<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify it under the terms of the GNU
// General Public License as published by the Free Software Foundation, either version 3 of the
// License, or (at your option) any later version. See <http://www.gnu.org/licenses/>.

namespace {{NAMESPACE}};

use {{USEPREFIX}}\base_skill;
use {{USEPREFIX}}\dto\skill_risk_class;
use {{USEPREFIX}}\interfaces\skill_trigger_provider_interface;

/**
 * AI agent skill: {{SKILLNAME}}.
 *
 * GENERATED SCAFFOLD - the contract is filled in; the behaviour is NOT.
 * Implement run_preflight() and execute() where marked "TODO (you)". Until then this skill is inert
 * and only reports that it is unfinished.
 *
 * Drop this file at: {{RELATIVEPATH}}
 * No skill_provider class is required - the agent auto-discovers skills in this directory.
 *
 * @package {{NAMESPACEROOT}}
 */
class {{CLASSNAME}} extends base_skill implements skill_trigger_provider_interface {
    /**
     * Declare the skill's read-only flag and risk class.
     *
     * Risk classes: R0 read_only | R1 scoped_write | R2 broad_write | R3 irreversible_or_external.
     * The boolean MUST be true only for R0 (read-only) skills, false otherwise.
     */
    public function __construct() {
        parent::__construct({{READONLY}}, skill_risk_class::{{RISKCONST}});
    }

    /**
     * Unique, namespaced skill name: "<namespace>.<action>", lowercase, matching ^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$.
     *
     * @return string
     */
    public function get_name(): string {
        return '{{SKILLNAME}}';
    }

    /**
     * The schema the planner reads to route to and call this skill.
     *
     * Rules:
     * - 'description': ONE clear sentence - what this does and when to use it.
     * - every entry in 'properties' needs: type, a one-sentence 'description' (the LLM reads it), and required(bool).
     * - 'prompt_meta.intent': one line; 'anchor_fields': the main lookup field(s).
     * - For R2/R3 skills 'context_scopes' is MANDATORY (e.g. ['module','course']) or the skill is rejected.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => {{DESCRIPTION}},
            'readonly' => $this->is_read_only(),
            'properties' => [
{{PROPERTIES}}
            ],
            'prompt_meta' => [
                'intent' => {{INTENT}},
                'anchor_fields' => [{{ANCHORS}}],
                'context_scopes' => [{{SCOPES}}],
            ],
        ];
    }

    /**
     * Native Moodle capabilities the underlying action requires (enforced in run_preflight via
     * require_native_capabilities()). Return [] for a purely read-only skill with no extra gate.
     *
     * @return array<int,string>
     */
    public function get_required_native_capabilities(): array {
        return [{{CAPABILITIES}}];
    }

    /**
     * Cheap, pure structural validation (NO database access).
     *
     * @param array $input raw input from the planner
     * @return array{valid: bool, errors: array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        // TODO (you): check required fields are present / well-formed, e.g.:
        // if (trim((string)($input['yourfield'] ?? '')) === '') { $errors[] = 'yourfield is required.'; }
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Deep validation + preparation. Resolve entities (DB lookups), authorise the user, and return
     * the data execute() will act on. DO NOT mutate anything here.
     *
     * Return one of (DTO-free - the base class wraps these into the engine result):
     *   $this->pass($preparedinput)              - ready to execute
     *   $this->confirmable($prepared, $issues)   - needs user confirmation
     *   $this->invalid($issues)                  - cannot proceed
     *
     * @param array $input raw input from the planner
     * @param int $contextid operating context id
     * @param int $userid acting user id
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        // TODO (you): resolve the target entity from $input, authorise via
        // $this->require_native_capabilities($context, $userid), and collect everything execute() needs.
        return $this->pass($input);
    }

    /**
     * Perform the action. Use ONLY $preparedinput from run_preflight() - do not re-resolve.
     *
     * @param array $preparedinput prepared input returned by run_preflight()
     * @param int $contextid operating context id
     * @param int $userid acting user id
     * @return array result, e.g. ['status' => 'executed', 'detail' => '...', 'observation_full' => '...']
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        // TODO (you): implement the real behaviour here, then remove this placeholder return.
        // Until implemented this skill intentionally does nothing and reports it is unfinished.
        return [
            'status' => 'executed',
            'detail' => self::NOT_IMPLEMENTED_MESSAGE,
            'observation_full' => 'Scaffold stub "{{SKILLNAME}}" is not implemented yet.',
        ];
    }

    /** Placeholder message shown until the real behaviour is implemented. */
    private const NOT_IMPLEMENTED_MESSAGE = {{NOTIMPLEMENTED}};

    /**
     * Optional rich preview of the result (rendered in the agent's side panel). While unimplemented
     * this shows an "under construction" card; replace it with a real preview of your output.
     *
     * @param array $resultentry the stored result entry from execute()
     * @param int $contextid operating context id
     * @param int $userid acting user id
     * @return array|null preview descriptor (e.g. ['type' => ..., 'html' => ...]) or null for no preview
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        return [
            'type' => 'under_construction',
            'html' => '<div class="text-center p-4">'
                . '<i class="fa-solid fa-person-digging fa-bounce fa-3x text-warning" aria-hidden="true"></i>'
                . '<p class="mt-3 mb-0">' . s(self::NOT_IMPLEMENTED_MESSAGE) . '</p>'
                . '</div>',
        ];
    }

    /**
     * Example phrases that should route the planner to this skill. Keep them natural and varied.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => {{TRIGGERID}},
                'description' => {{TRIGGERDESC}},
                'examples' => [{{TRIGGEREXAMPLES}}],
            ],
        ];
    }
}

PHP;

        return strtr($template, [
            '{{NAMESPACE}}' => $namespace,
            '{{USEPREFIX}}' => $useprefix,
            '{{NAMESPACEROOT}}' => $spec['namespaceroot'],
            '{{CLASSNAME}}' => $spec['classname'],
            '{{SKILLNAME}}' => $spec['skillname'],
            '{{RELATIVEPATH}}' => 'classes/local/wizard/' . $spec['domain'] . '/skills/' . $spec['classfile'],
            '{{READONLY}}' => $spec['readonly'] ? 'true' : 'false',
            '{{RISKCONST}}' => $spec['risk_const'],
            '{{DESCRIPTION}}' => self::php_single_quote($spec['description']),
            '{{INTENT}}' => self::php_single_quote($spec['intent']),
            '{{PROPERTIES}}' => self::render_properties($spec['properties']),
            '{{ANCHORS}}' => self::render_quoted_list($spec['anchor_fields']),
            '{{SCOPES}}' => self::render_quoted_list($spec['context_scopes']),
            '{{CAPABILITIES}}' => self::render_quoted_list($spec['capabilities']),
            '{{NOTIMPLEMENTED}}' => self::php_single_quote(self::NOT_IMPLEMENTED_MESSAGE),
            '{{TRIGGERID}}' => self::php_single_quote($spec['skillname'] . '_request'),
            '{{TRIGGERDESC}}' => self::php_single_quote($spec['triggers'][0]['description']),
            '{{TRIGGEREXAMPLES}}' => self::render_quoted_list($spec['triggers'][0]['examples']),
        ]);
    }

    /**
     * Render the properties block of get_schema().
     *
     * @param array<int,array<string,mixed>> $properties
     * @return string
     */
    private static function render_properties(array $properties): string {
        if (empty($properties)) {
            // Provide one illustrative property so the developer sees the required shape.
            $properties = [[
                'name' => 'yourfield',
                'type' => 'string',
                'description' => 'TODO: describe this input for the planner.',
                'required' => false,
            ]];
        }

        $lines = [];
        foreach ($properties as $prop) {
            $name = self::php_single_quote((string)$prop['name']);
            $type = self::php_single_quote((string)$prop['type']);
            $desc = self::php_single_quote((string)$prop['description']);
            $required = !empty($prop['required']) ? 'true' : 'false';
            $lines[] = "                {$name} => ["
                . "\n                    'type' => {$type},"
                . "\n                    'description' => {$desc},"
                . "\n                    'required' => {$required},"
                . "\n                ],";
        }
        return implode("\n", $lines);
    }

    /**
     * Render a PHP array body of single-quoted strings (without the surrounding brackets).
     *
     * @param array<int,string> $items
     * @return string
     */
    private static function render_quoted_list(array $items): string {
        if (empty($items)) {
            return '';
        }
        return implode(', ', array_map([self::class, 'php_single_quote'], $items));
    }

    /**
     * Build the db/access.php capability snippet.
     *
     * @param array<string,mixed> $spec
     * @return string
     */
    private static function build_access_snippet(array $spec): string {
        $contextlevel = in_array('course', $spec['context_scopes'], true) ? 'CONTEXT_COURSE' : 'CONTEXT_MODULE';
        $captype = $spec['readonly'] ? 'read' : 'write';
        return "<?php\n"
            . "// Add this entry to your plugin's db/access.php \$capabilities array.\n"
            . "// The agent governs each skill behind a per-skill capability of the form\n"
            . "// '<component>:skill_<normalized_skill_name>'.\n\n"
            . "'{$spec['capability']}' => [\n"
            . "    'captype' => '{$captype}',\n"
            . "    'contextlevel' => {$contextlevel},\n"
            . "    'archetypes' => [\n"
            . "        'editingteacher' => CAP_ALLOW,\n"
            . "        'manager' => CAP_ALLOW,\n"
            . "    ],\n"
            . "],\n";
    }

    /**
     * Build the lang strings snippet (capability label).
     *
     * @param array<string,mixed> $spec
     * @return string
     */
    private static function build_lang_snippet(array $spec): string {
        // Capability lang key: '<frankenstyle>:<permission>' renders as '<component>/<permission>'.
        $key = str_replace('/', ':', $spec['capability']);
        return "<?php\n"
            . "// Add to your plugin's lang/en/{component}.php for the capability label.\n\n"
            . "\$string['{$key}'] = 'Use the {$spec['skillname']} AI skill';\n";
    }

    /**
     * Build the README with the exact wiring steps.
     *
     * @param array<string,mixed> $spec
     * @param string $relativepath
     * @return string
     */
    private static function build_readme(array $spec, string $relativepath): string {
        $warnings = '';
        if (!empty($spec['warnings'])) {
            $warnings = "\n> NOTE:\n> - " . implode("\n> - ", $spec['warnings']) . "\n";
        }
        // phpcs:disable moodle.Strings.ForbiddenStrings.Found -- Literal backticks in scaffold README/Markdown template content, not shell execution.
        return "# Skill template: {$spec['skillname']}\n"
            . $warnings
            . "\nThis bundle scaffolds an AI agent skill for component `{$spec['component']}`.\n"
            . "It fills the **contract** (name, schema, risk class, capability, triggers) and leaves the\n"
            . "**behaviour** to you - `run_preflight()` and `execute()` contain `TODO (you)` markers and the\n"
            . "skill is inert until you implement them.\n\n"
            . "## 1. Drop the skill file\n"
            . "Copy the generated file to:\n\n    {$relativepath}\n\n"
            . "The agent auto-discovers any skill under `classes/local/wizard/<domain>/skills/` - **no**\n"
            . "`skill_provider` class is required. (A provider is only needed for advanced cases such as\n"
            . "custom issue codes or prompt guidance.)\n\n"
            . "## 2. Declare the capability\n"
            . "Add the entry from `snippets/db_access.php.txt` to your plugin's `db/access.php`, and the\n"
            . "label from `snippets/lang_strings.php.txt` to your language file. The skill capability is:\n\n"
            . "    {$spec['capability']}\n\n"
            . "## 3. Bump version & upgrade\n"
            . "Increase your plugin's `version.php` and run the Moodle upgrade so the new capability and\n"
            . "skill are registered.\n\n"
            . "## 4. Enable the skill\n"
            . "Skills are **default-off**. Enable `{$spec['skillname']}` on the agent governance page\n"
            . "(Site administration > Plugins > Booking AI agent > Skill governance).\n\n"
            . "## 5. Rebuild skill embeddings\n"
            . "So the planner can route to your skill, rebuild the skill-catalog embeddings from the\n"
            . "governance page (or via the agent's embeddings CLI).\n\n"
            . "## 6. Implement the behaviour\n"
            . "Fill in `run_preflight()` (resolve + authorise, no mutation) and `execute()` (act using only the\n"
            . "prepared input). Replace the placeholder return and the \"under construction\" preview.\n";
        // phpcs:enable moodle.Strings.ForbiddenStrings.Found
    }

    /**
     * Package the files into a base64-encoded ZIP.
     *
     * @param array<string,mixed> $spec
     * @param array<string,string> $files
     * @return array{0:string,1:string} [base64, filename]
     */
    private static function build_zip(array $spec, array $files): array {
        $tmpdir = make_request_directory();
        $zippath = $tmpdir . '/skill_template.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zippath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \moodle_exception('cannotcreatetempdir', 'error');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $bytes = (string)file_get_contents($zippath);
        @unlink($zippath);

        // Name the archive after the actual skill, e.g. "myplugin.archive_item.zip".
        $filename = $spec['skillname'] . '.zip';
        return [base64_encode($bytes), $filename];
    }

    // Small helpers.

    /**
     * Normalize the properties list.
     *
     * @param array<int,mixed> $properties
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_properties(array $properties): array {
        $result = [];
        foreach ($properties as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            $name = self::slugify((string)($prop['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $type = trim((string)($prop['type'] ?? 'string'));
            if (!in_array($type, ['string', 'integer', 'number', 'boolean', 'object', 'array'], true)) {
                $type = 'string';
            }
            $result[] = [
                'name' => $name,
                'type' => $type,
                'description' => trim((string)($prop['description'] ?? '')) ?: 'TODO: describe this input.',
                'required' => !empty($prop['required']),
            ];
        }
        return $result;
    }

    /**
     * Normalize triggers, falling back to a single derived trigger.
     *
     * @param array<int,mixed> $triggers
     * @param string $description
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_triggers(array $triggers, string $description): array {
        $result = [];
        foreach ($triggers as $trigger) {
            if (!is_array($trigger)) {
                continue;
            }
            $examples = self::normalize_string_list((array)($trigger['examples'] ?? []));
            if (empty($examples)) {
                continue;
            }
            $result[] = [
                'description' => trim((string)($trigger['description'] ?? '')) ?: 'User asks for this action.',
                'examples' => $examples,
            ];
        }
        if (empty($result)) {
            $result[] = [
                'description' => 'User asks for this action.',
                'examples' => array_values(array_filter([
                    $description !== '' ? $description : 'TODO: add a natural example phrase.',
                ])),
            ];
        }
        return [$result[0]];
    }

    /**
     * Trim, lowercase, and string-cast a flat list.
     *
     * @param array<int,mixed> $items
     * @return array<int,string>
     */
    private static function normalize_string_list(array $items): array {
        $result = [];
        foreach ($items as $item) {
            $value = trim((string)$item);
            if ($value !== '') {
                $result[] = $value;
            }
        }
        return array_values(array_unique($result));
    }

    /**
     * Derive a plausible skill namespace from a component (e.g. "mod/booking" -> "booking").
     *
     * @param string $component
     * @return string
     */
    private static function derive_namespace(string $component): string {
        $parts = preg_split('#[/_]#', $component) ?: [];
        $candidate = self::slugify((string)end($parts));
        if ($candidate === '') {
            $candidate = 'myplugin';
        }
        // Reserved namespaces (booking/core/wizard) belong to the agent engine. If the derived
        // namespace is reserved, fall back to the full component name (e.g. local/wizard ->
        // local_wizard), which is unique to the plugin and never reserved.
        if (!skill_contract_validator::component_may_register_namespace($component, $candidate)) {
            $fallback = self::slugify(str_replace('/', '_', $component));
            if ($fallback !== '') {
                $candidate = $fallback;
            }
        }
        return $candidate;
    }

    /**
     * Slugify a string into a lowercase [a-z][a-z0-9_]* identifier.
     *
     * @param string $value
     * @return string
     */
    private static function slugify(string $value): string {
        $value = core_text::strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');
        // Limit to a sane length and ensure it starts with a letter.
        $value = (string)preg_replace('/^[^a-z]+/', '', $value);
        if (core_text::strlen($value) > 40) {
            $value = core_text::substr($value, 0, 40);
            $value = trim($value, '_');
        }
        return $value;
    }

    /**
     * Map a risk class value to its skill_risk_class constant name.
     *
     * @param string $risk
     * @return string
     */
    private static function risk_constant_name(string $risk): string {
        switch ($risk) {
            case skill_risk_class::R1:
                return 'R1';
            case skill_risk_class::R2:
                return 'R2';
            case skill_risk_class::R3:
                return 'R3';
            default:
                return 'R0';
        }
    }

    /**
     * Escape a value for embedding inside single-quoted generated PHP.
     *
     * @param string $value
     * @return string
     */
    private static function php_single_quote(string $value): string {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}
