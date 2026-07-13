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
use bookingextension_agent\local\wizard\skill_registry_factory;

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
    /**
     * Discovery meta-skills (registry introspection + RAG fallback). They are only meaningful while
     * the catalog is a SEMANTIC SUBSET (embed_topk): there they let the planner widen beyond the
     * top-k. Once the FULL/static catalog is present (slim_all / slim_family) they would only imply
     * non-existent "hidden" skills, so they are excluded from the full slim catalog.
     */
    public const DISCOVERY_META_SKILLS = ['wizard.list_skills', 'wizard.search_skills'];

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
     * Drop the discovery meta-skills ({@see self::DISCOVERY_META_SKILLS}) from a catalog.
     *
     * Used for the full/static catalog (slim_all / slim_family) and for the full skill listing that
     * wizard.list_skills produces: once the planner already sees every skill, advertising "list/search
     * skills" only implies more hidden skills that do not exist.
     *
     * @param mixed[] $catalog
     * @return array[]
     */
    public function exclude_discovery_meta_skills(array $catalog): array {
        return array_values(array_filter(
            $catalog,
            static function ($entry): bool {
                if (!is_array($entry)) {
                    return false;
                }
                return !in_array((string)($entry['skill'] ?? ''), self::DISCOVERY_META_SKILLS, true);
            }
        ));
    }

    /**
     * Keep only planner-relevant fields before runtime catalog prompt injection.
     *
     * @param array[] $catalog
     * @return array[]
     */
    public function sanitize_runtime_catalog_for_prompt(array $catalog): array {
        $sanitized = [];
        $livecontracts = $this->live_contract_lookup();

        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $skill = trim((string)($entry['skill'] ?? ''));
            if ($skill === '') {
                continue;
            }

            // Card metadata (minimal_input/example_input/intent/readonly/description/message_triggers)
            // is sourced LIVE from the skill registry, never from the embeddings-catalog row, which is
            // only a frozen snapshot from the last rebuild. The row identifies WHICH skill matched; the
            // planner-facing contract must reflect the CURRENT skill schema (e.g. a newly added cross-
            // context targeting field). Without this, a skill change that does not alter the embedding
            // anchor text (description/utterances) is invisible to the catalog until a manual rebuild.
            $live = $livecontracts[$skill] ?? null;
            if ($live !== null) {
                $minimalinput = (array)($live['minimal_input'] ?? []);
                $exampleinputraw = (array)($live['example_input'] ?? []);
                $triggerraw = (array)($live['message_triggers'] ?? []);
                $intent = trim((string)($live['intent'] ?? ''));
                $readonly = !empty($live['readonly']);
                $description = (string)($live['description'] ?? '');
            } else {
                // A row whose skill is no longer registered has no live metadata (the CSV stores none),
                // so emit a minimal entry rather than fabricating fields.
                $minimalinput = [];
                $exampleinputraw = [];
                $triggerraw = [];
                $intent = '';
                $readonly = false;
                $description = '';
            }

            $row = [
                'skill' => $skill,
                'readonly' => $readonly,
                'intent' => $intent,
                'minimal_input' => $minimalinput,
                'description' => $this->compact_catalog_description($description),
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
     * Live skill-name → prompt-contract lookup from the registry.
     *
     * The planner catalog's card metadata must reflect the current skill schema, not a frozen
     * embeddings-catalog snapshot, so {@see self::sanitize_runtime_catalog_for_prompt()} re-joins it
     * live by skill name. Returns an empty map when the registry is unavailable (defensive).
     *
     * @return array
     */
    private function live_contract_lookup(): array {
        try {
            $registry = skill_registry_factory::get_default();
        } catch (\Throwable $e) {
            return [];
        }

        $lookup = [];
        foreach ($registry->get_all_prompt_contracts() as $contract) {
            if (!is_array($contract)) {
                continue;
            }
            $skill = trim((string)($contract['skill'] ?? ''));
            if ($skill !== '') {
                $lookup[$skill] = $contract;
            }
        }

        return $lookup;
    }

    /**
     * Decode JSON array/object payload safely.
     *
     * @param string $json
     * @return array
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

        // Sentence-aware truncation (C1, thread 589): a hard character cap can slice inside a
        // sentence and invert the card's meaning — the live course.create_course card was cut
        // right after "asks which category to use unless", reading as an instruction to ASK
        // instead of ACT. Cut at the LAST sentence boundary within the cap instead.
        $window = core_text::substr($normalized, 0, 240);
        if (preg_match('/^(.*[.!?]["\'\)\]]*)(?:\s|$)/us', $window, $matches)) {
            return rtrim($matches[1]);
        }

        // No sentence boundary within the window: fall back to a word boundary + ellipsis so the
        // card at least never breaks a word.
        $wordsafe = preg_replace('/\s+\S*$/u', '', core_text::substr($normalized, 0, 237));
        return rtrim(($wordsafe ?? '') !== '' ? $wordsafe : core_text::substr($normalized, 0, 237)) . '…';
    }

    /**
     * Keep example_input as a compact property-name list for routing hints.
     *
     * This preserves only explicitly declared example fields while avoiding
     * token-heavy concrete sample payloads.
     *
     * @param array $exampleinput
     * @return string[]
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
     * @return array[]
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
     * @param array[] $catalog
     * @param string[] $selectedfamilies
     * @return array[]
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
     * @param array[] $contracts
     * @return array{0: array[], 1: array[]}
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

            // Only the write skills of Wunderbyte's own gated components move to the locked list.
            // Read-only skills and all third-party write skills stay selectable for the planner.
            $requiresfullaccess = agent_access_service::skill_requires_full_access(
                !empty($contract['readonly']),
                (string)($contract['component'] ?? '')
            );
            if (!$requiresfullaccess) {
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
     * @param array[] $promptcontracts
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
