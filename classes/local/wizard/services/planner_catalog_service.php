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

namespace bookingextension_agent\local\wizard\services;

use core_text;
use bookingextension_agent\local\wizard\contracts\skill_family_contract;

/**
 * Planner skill-catalog shaping: slim / sanitize / render / compact / filter.
 *
 * Extracted verbatim from the orchestrator (orchestrator split, planner-catalog seam).
 * Pure catalog transformations; the only collaborator is assistant_state_guidance_service
 * (string-list normalisation for trigger examples). The orchestrator keeps thin delegating
 * methods for the externally-called entries so call sites are unchanged. Behaviour-preserving.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class planner_catalog_service {
    /** @var assistant_state_guidance_service */
    private assistant_state_guidance_service $assistantsummariesvc;

    /**
     * Constructor.
     *
     * @param assistant_state_guidance_service $assistantsummariesvc
     */
    public function __construct(assistant_state_guidance_service $assistantsummariesvc) {
        $this->assistantsummariesvc = $assistantsummariesvc;
    }

    /**
     * Reduce skill catalog entries to planner-facing routing metadata only.
     *
     * @param array $skillcatalog
     * @return array
     */
    public function slim_prompt_catalog_for_planner(array $skillcatalog): array {
        $slimcatalog = [];

        foreach ($skillcatalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $skillname = (string)($entry['skill'] ?? '');
            if ($skillname === '') {
                continue;
            }

            $newentry = [
                'skill' => $skillname,
                'readonly' => (bool)($entry['readonly'] ?? false),
                'intent' => (string)($entry['intent'] ?? ''),
                'minimal_input' => (array)($entry['minimal_input'] ?? []),
                'example_input' => $this->compact_catalog_example_input((array)($entry['example_input'] ?? [])),
                'description' => $this->compact_catalog_description((string)($entry['description'] ?? '')),
                'message_triggers' => $this->compact_catalog_message_triggers((array)($entry['message_triggers'] ?? [])),
            ];

            if (empty($newentry['example_input']) || $newentry['minimal_input'] == $newentry['example_input']) {
                unset($newentry['example_input']);
            }

            $slimcatalog[] = $newentry;
        }

        return $slimcatalog;
    }

    /**
     * Inject skills that declare governance mandatory_on_trigger when the latest user message matches
     * one of their declared intent_triggers.
     *
     * This replaces the previous skill-name-specific routing (hardcoded explain_docs / list_skills +
     * de/en keyword heuristics in the engine). Both the "must be offered" decision and the trigger
     * phrases now live entirely in the skill's governance contract, so the engine stays agnostic:
     * it carries no skill names and no language keywords. Embedding top-k discovery can rank domain
     * skills above such a meta-skill; this guarantees it still reaches the selector, which decides.
     * No-op for skills already present, for skills not declaring the flag, or on no trigger match.
     *
     * @param array<int,array<string,mixed>> $runtimecatalog Final (post-filter) candidate catalog.
     * @param array<int,array<string,mixed>> $allcontracts   Full skill contracts (source of the row).
     * @param array<int,object> $messages                    Conversation messages (latest user text).
     * @return array<int,array<string,mixed>>
     */
    public function ensure_trigger_mandatory_skills(
        array $runtimecatalog,
        array $allcontracts,
        array $messages
    ): array {
        $present = [];
        foreach ($runtimecatalog as $row) {
            $skill = trim((string)($row['skill'] ?? ''));
            if ($skill !== '') {
                $present[$skill] = true;
            }
        }

        $usertext = '';
        foreach (array_reverse($messages) as $msg) {
            if (($msg->role ?? '') === 'user') {
                $usertext = trim((string)($msg->content ?? ''));
                break;
            }
        }
        if ($usertext === '') {
            return $runtimecatalog;
        }
        $haystack = \core_text::strtolower($usertext);

        foreach ($allcontracts as $entry) {
            if (!is_array($entry) || empty($entry['mandatory_on_trigger'])) {
                continue;
            }
            $skill = trim((string)($entry['skill'] ?? ''));
            if ($skill === '' || isset($present[$skill])) {
                continue;
            }
            if (!$this->message_matches_intent_triggers($haystack, (array)($entry['intent_triggers'] ?? []))) {
                continue;
            }

            $sanitized = $this->sanitize_runtime_catalog_for_prompt([$entry]);
            if (!empty($sanitized)) {
                $runtimecatalog[] = $sanitized[0];
                $present[$skill] = true;
            }
        }

        return $runtimecatalog;
    }

    /**
     * Whether any declared intent trigger occurs (case-insensitive substring) in the user message.
     *
     * @param string $haystack Already-lowercased user message.
     * @param array<int,mixed> $triggers Skill-declared trigger phrases.
     * @return bool
     */
    public function message_matches_intent_triggers(string $haystack, array $triggers): bool {
        foreach ($triggers as $trigger) {
            $needle = \core_text::strtolower(trim((string)$trigger));
            if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep only planner-relevant fields before runtime catalog prompt injection.
     *
     * @param array<int,array<string,mixed>> $catalog
     * @return array<int,array<string,mixed>>
     */
    public function sanitize_runtime_catalog_for_prompt(array $catalog): array {
        $sanitized = [];

        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $skill = trim((string)($entry['skill'] ?? ''));
            if ($skill === '') {
                continue;
            }

            $minimalinput = is_array($entry['minimal_input'] ?? null)
                ? (array)$entry['minimal_input']
                : $this->decode_catalog_json_array((string)($entry['minimal_input_json'] ?? '[]'));

            $exampleinputraw = is_array($entry['example_input'] ?? null)
                ? (array)$entry['example_input']
                : $this->decode_catalog_json_array((string)($entry['example_input_json'] ?? '[]'));

            $triggerraw = is_array($entry['message_triggers'] ?? null)
                ? (array)$entry['message_triggers']
                : $this->decode_catalog_json_array((string)($entry['message_triggers_json'] ?? '[]'));

            $row = [
                'skill' => $skill,
                'readonly' => !empty($entry['readonly']) && (string)$entry['readonly'] !== '0',
                'intent' => trim((string)($entry['intent'] ?? '')),
                'minimal_input' => $minimalinput,
                'description' => $this->compact_catalog_description((string)($entry['description'] ?? '')),
                'message_triggers' => $this->compact_catalog_message_triggers($triggerraw),
            ];

            $exampleinput = $this->compact_catalog_example_input($exampleinputraw);
            if (!empty($exampleinput) && $exampleinput !== $minimalinput) {
                $row['example_input'] = $exampleinput;
            }

            $sanitized[] = $row;
        }

        return $sanitized;
    }

    /**
     * Decode JSON array/object payload safely.
     *
     * @param string $json
     * @return array<int|string,mixed>
     */
    public function decode_catalog_json_array(string $json): array {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Render the skill catalog as compact plain text instead of JSON.
     *
     * Each skill gets a heading line plus WHEN / REQUIRED / OPTIONAL / TRIGGERS lines.
     * This is ~75% more token-efficient than JSON and easier for the LLM to scan.
     *
     * @param array $catalog
     * @return string
     */
    public function render_catalog_as_text(array $catalog): string {
        $blocks = [];

        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $skillname = trim((string)($entry['skill'] ?? ''));
            if ($skillname === '') {
                continue;
            }

            $readonly = !empty($entry['readonly']) && (string)($entry['readonly']) !== '0';
            $mutability = $readonly ? 'readonly' : 'mutating';
            $lines = [];
            $lines[] = "## {$skillname} [{$mutability}]";

            $description = trim(preg_replace('/\s+/', ' ', (string)($entry['description'] ?? '')) ?? '');
            if ($description !== '') {
                $lines[] = core_text::substr($description, 0, 160);
            }

            // WHEN: from first message trigger description.
            $triggers = (array)($entry['message_triggers'] ?? []);
            $firsttrigger = !empty($triggers) && is_array($triggers[0]) ? (array)$triggers[0] : [];
            $when = trim(preg_replace('/\s+/', ' ', (string)($firsttrigger['description'] ?? '')) ?? '');
            if ($when !== '') {
                $lines[] = 'WHEN: ' . core_text::substr($when, 0, 180);
            }

            // REQUIRED: minimal_input fields.
            $minimal = array_filter(array_map('strval', (array)($entry['minimal_input'] ?? [])));
            if (!empty($minimal)) {
                $lines[] = 'REQUIRED: ' . implode(', ', array_values($minimal));
            }

            // OPTIONAL parameters are deliberately NOT listed in the selection catalog: selection must
            // not construct parameters (the selector picks exactly one skill and omits input), so optional
            // field names carry no routing value and are pure token noise across all skills every turn.
            // The full parameter schema (incl. optional fields, types, descriptions) is provided separately
            // to the constructor as JSON for the single selected skill (see PHASE_PARAMETER_CONSTRUCTION).

            // TRIGGERS: trigger IDs as readable keywords (strip namespace prefix for brevity).
            $triggerids = [];
            foreach ($triggers as $trigger) {
                if (!is_array($trigger)) {
                    continue;
                }
                $id = trim((string)($trigger['id'] ?? ''));
                if ($id !== '') {
                    // Strip module prefix for brevity.
                    // (e.g. "mod_booking.create_option_canonical_fallback" → "create_option_canonical_fallback").
                    $shortid = (string)preg_replace('/^[a-z_]+\./', '', $id);
                    $triggerids[] = $shortid;
                }
            }
            if (!empty($triggerids)) {
                $lines[] = 'TRIGGERS: ' . implode(' | ', array_slice($triggerids, 0, 5));
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Compact the skill catalog description to a shorter length.
     *
     * @param string $description The raw description.
     * @return string The compacted description.
     */
    public function compact_catalog_description(string $description): string {
        $normalized = trim(preg_replace('/\s+/', ' ', $description) ?? $description);
        if ($normalized === '') {
            return '';
        }

        if (core_text::strlen($normalized) <= 240) {
            return $normalized;
        }

        return rtrim(core_text::substr($normalized, 0, 237)) . '...';
    }

    /**
     * Keep example_input as a compact property-name list for routing hints.
     *
     * This preserves only explicitly declared example fields while avoiding
     * token-heavy concrete sample payloads.
     *
     * @param array $exampleinput
     * @return array<int,string>
     */
    public function compact_catalog_example_input(array $exampleinput): array {
        $keys = [];

        foreach (array_keys($exampleinput) as $key) {
            $name = trim((string)$key);
            if ($name !== '') {
                $keys[] = $name;
            }
        }

        $keys = array_values(array_unique($keys));
        if (empty($keys)) {
            return [];
        }

        // Keep enough fields so slotbooking/selflearning skill variants do not
        // lose critical execution hints (e.g. slot_day_* or duration fields).
        return array_slice($keys, 0, 12);
    }

    /**
     * Drop verbose trigger examples and keep compact id + short description only.
     *
     * @param array $triggers
     * @return array<int,array<string,string>>
     */
    public function compact_catalog_message_triggers(array $triggers): array {
        $compact = [];

        foreach ($triggers as $trigger) {
            if (!is_array($trigger)) {
                continue;
            }

            $id = trim((string)($trigger['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $description = trim((string)($trigger['description'] ?? ''));
            $description = trim(preg_replace('/\s+/', ' ', $description) ?? $description);

            $row = ['id' => $id];
            if ($description !== '') {
                $row['description'] = core_text::substr($description, 0, 320);
            }

            $examples = (array)($trigger['examples'] ?? []);
            if (!empty($examples)) {
                $row['examples'] = $this->assistantsummariesvc->normalize_nonempty_string_list($examples, 2, 160);
                if (empty($row['examples'])) {
                    unset($row['examples']);
                }
            }

            $compact[] = $row;
        }

        return $compact;
    }

    /**
     * Keep only catalog entries whose skill family is in selected discovery families.
     *
     * @param array<int,array<string,mixed>> $catalog
     * @param array<int,string> $selectedfamilies
     * @return array<int,array<string,mixed>>
     */
    public function filter_catalog_by_selected_families(array $catalog, array $selectedfamilies): array {
        if (empty($catalog) || empty($selectedfamilies)) {
            return $catalog;
        }

        $allow = [];
        foreach ($selectedfamilies as $family) {
            $normalized = skill_family_contract::normalize_family((string)$family);
            if ($normalized !== skill_family_contract::DEFAULT_FAMILY) {
                $allow[$normalized] = true;
            }
        }

        if (empty($allow)) {
            return [];
        }

        $filtered = [];
        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $skillname = trim((string)($entry['skill'] ?? ''));
            if ($skillname === '') {
                continue;
            }

            $family = skill_family_contract::from_skill_name($skillname);
            if (!isset($allow[$family])) {
                continue;
            }

            $filtered[] = $entry;
        }

        return array_values($filtered);
    }

    /**
     * Whether the active skill catalog is static across turns (no embeddings / slim_all family).
     *
     * @param string $catalogselectionmode the resolved catalog selection mode
     * @return bool
     */
    public function catalog_mode_is_static(string $catalogselectionmode): bool {
        return str_starts_with($catalogselectionmode, 'slim');
    }

    /**
     * Split prompt contracts into readonly (selectable without full access) and
     * mutating ones, which move to the unavailable catalog with an upgrade hint.
     *
     * @param array<int,array<string,mixed>> $contracts
     * @return array{0: array<int,array<string,mixed>>, 1: array<int,array<string,mixed>>}
     */
    public function split_prompt_contracts_by_full_access(array $contracts): array {
        $available = [];
        $locked = [];
        $upgradeurl = trim((string)get_string('aitrial_pro_license_url', 'bookingextension_agent'));
        // Prepended (not appended): the catalog renderer truncates descriptions,
        // and the lock notice must survive that.
        $lockednote = '[Locked: requires the Wunderbyte PRO license or subscription'
            . ($upgradeurl !== '' ? ' — ' . $upgradeurl : '')
            . '] ';

        foreach ($contracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            if (!empty($contract['readonly'])) {
                $available[] = $contract;
                continue;
            }

            $contract['description'] = trim($lockednote . trim((string)($contract['description'] ?? '')));
            $locked[] = $contract;
        }

        return [$available, $locked];
    }

    /**
     * Resolve a deterministic namespace hint from prompt contracts.
     *
     * @param array<int,array<string,mixed>> $promptcontracts
     * @return string
     */
    public function resolve_namespace_hint_from_prompt_contracts(array $promptcontracts): string {
        $counts = [];
        foreach ($promptcontracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            $namespace = trim((string)($contract['namespace'] ?? ''));
            if ($namespace === '') {
                continue;
            }

            $counts[$namespace] = (int)($counts[$namespace] ?? 0) + 1;
        }

        if (empty($counts)) {
            return '';
        }

        arsort($counts, SORT_NUMERIC);
        return (string)array_key_first($counts);
    }
}
