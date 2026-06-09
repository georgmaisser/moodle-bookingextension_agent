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
 * Generic, domain-agnostic preview passthrough.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services;

use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\skill_registry;

/**
 * Resolves the user-facing preview for a webservice response purely from skill-provided data.
 *
 * A skill that wants a preview simply exposes a method:
 *
 *   public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array
 *
 * returning a plain data block (no framework types):
 *
 *   [
 *     'type'      => 'booking_option',  // free, skill-defined string (for client dispatch)
 *     'html'      => '<div>…</div>',     // optional, server-rendered HTML (trusted plugin output)
 *     'js_module' => 'mod_x/preview',    // optional AMD module name for client-side rendering
 *     'payload'   => [ … ],              // optional data handed to the js_module
 *   ]
 *
 * The engine never inspects the type and never renders anything — it just forwards the block and,
 * across a multi-step confirm chain, concatenates HTML of the same type. This keeps the framework
 * free of any per-skill/domain preview knowledge (no booking_option/user_profile/… branches) and
 * lets a skill output whatever HTML/JS it wants, produced entirely inside its own plugin.
 */
class preview_passthrough {
    /** Optional method a skill may expose to provide its preview as data. */
    public const PREVIEW_METHOD = 'get_result_preview';

    /**
     * Resolve the preview JSON for a webservice response from executed skill results.
     *
     * @param skill_registry $registry
     * @param array<int,mixed> $results Executed skill results.
     * @param int $contextid
     * @param int $userid
     * @param int $threadid
     * @param string $metadatakey Thread-metadata key used to accumulate previews across a chain.
     * @return string JSON-encoded preview block, or '' when there is none.
     */
    public static function resolve_preview_json(
        skill_registry $registry,
        array $results,
        int $contextid,
        int $userid,
        int $threadid,
        string $metadatakey = '_confirm_previews',
        array $loopresults = []
    ): string {
        $preview = self::extract_first_preview($registry, $results, $loopresults, $contextid, $userid);

        $store = new conversation_store();
        $stored = $store->get_thread_metadata_value($threadid, $metadatakey);
        $accumulated = is_array($stored) ? $stored : [];

        if ($preview !== null) {
            $preview = self::merge_with_accumulated($accumulated, $preview);
            $store->set_thread_metadata_value($threadid, $metadatakey, $preview);
        } else {
            $preview = !empty($accumulated) ? $accumulated : null;
        }

        if ($preview === null) {
            return '';
        }

        $encoded = json_encode($preview);
        return is_string($encoded) ? $encoded : '';
    }

    /**
     * Return the first valid skill-provided preview block, or null.
     *
     * Scans the terminal top-level results first (e.g. a confirmed mutation), then the loop-step
     * results (read skills such as get_option_details/search_options execute as internal loop steps,
     * so their result lives in loop_results, not in the terminal `results`). Most recent loop step
     * wins.
     *
     * @param skill_registry $registry
     * @param array<int,mixed> $results Terminal top-level results.
     * @param array<int,mixed> $loopresults Per-step loop results (each entry: {..., results: [...]}).
     * @param int $contextid
     * @param int $userid
     * @return array<string,mixed>|null
     */
    private static function extract_first_preview(
        skill_registry $registry,
        array $results,
        array $loopresults,
        int $contextid,
        int $userid
    ): ?array {
        $preview = self::first_preview_in_entries($registry, $results, $contextid, $userid);
        if ($preview !== null) {
            return $preview;
        }

        // Most recent loop step first.
        for ($i = count($loopresults) - 1; $i >= 0; $i--) {
            $step = $loopresults[$i];
            if (!is_array($step)) {
                continue;
            }
            $preview = self::first_preview_in_entries(
                $registry,
                (array)($step['results'] ?? []),
                $contextid,
                $userid
            );
            if ($preview !== null) {
                return $preview;
            }
        }

        return null;
    }

    /**
     * Return the first valid skill-provided preview block within a flat list of result entries.
     *
     * @param skill_registry $registry
     * @param array<int,mixed> $entries
     * @param int $contextid
     * @param int $userid
     * @return array<string,mixed>|null
     */
    private static function first_preview_in_entries(
        skill_registry $registry,
        array $entries,
        int $contextid,
        int $userid
    ): ?array {
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $skillname = trim((string)($entry['skill'] ?? ''));
            if ($skillname === '') {
                continue;
            }
            $skill = $registry->get_skill($skillname);
            if ($skill === null || !method_exists($skill, self::PREVIEW_METHOD)) {
                continue;
            }

            try {
                $candidate = $skill->{self::PREVIEW_METHOD}($entry, $contextid, $userid);
            } catch (\Throwable $e) {
                continue;
            }

            if (is_array($candidate) && trim((string)($candidate['type'] ?? '')) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Combine a freshly produced preview with the accumulated one (same chain, same type).
     *
     * Type-agnostic: for HTML-based previews of the same type, the new HTML is appended to the
     * accumulated HTML so a multi-step chain shows every affected item.
     *
     * @param array<string,mixed> $accumulated
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private static function merge_with_accumulated(array $accumulated, array $preview): array {
        $sametype = isset($accumulated['type'], $preview['type'])
            && (string)$accumulated['type'] === (string)$preview['type'];
        if (!$sametype) {
            return $preview;
        }

        $oldhtml = isset($accumulated['html']) && is_string($accumulated['html']) ? $accumulated['html'] : '';
        $newhtml = isset($preview['html']) && is_string($preview['html']) ? $preview['html'] : '';
        if ($oldhtml !== '' && $newhtml !== '' && strpos($newhtml, $oldhtml) === false) {
            $preview['html'] = $oldhtml . $newhtml;
        }

        // Merge payloads (especially list of optionids) across the confirm chain.
        $oldpayload = isset($accumulated['payload']) && is_array($accumulated['payload']) ? $accumulated['payload'] : [];
        $newpayload = isset($preview['payload']) && is_array($preview['payload']) ? $preview['payload'] : [];
        if (!empty($oldpayload) && !empty($newpayload)) {
            $mergedpayload = $newpayload;
            foreach ($oldpayload as $key => $oldval) {
                if (isset($newpayload[$key])) {
                    if (is_array($oldval) && is_array($newpayload[$key])) {
                        $mergedpayload[$key] = array_values(array_unique(array_merge($oldval, $newpayload[$key])));
                    }
                } else {
                    $mergedpayload[$key] = $oldval;
                }
            }
            $preview['payload'] = $mergedpayload;
        } else if (!empty($oldpayload)) {
            $preview['payload'] = $oldpayload;
        }

        return $preview;
    }
}
