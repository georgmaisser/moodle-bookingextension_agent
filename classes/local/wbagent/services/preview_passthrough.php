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

/**
 * Resolves the user-facing preview for a webservice response purely from skill-provided data.
 *
 * A skill that wants a preview exposes get_result_preview(), which the executor invokes on the raw
 * result and attaches to the result as a self-contained data block under the 'preview' key:
 *
 *   [
 *     'type'      => 'booking_option',  // free, skill-defined string (for client dispatch)
 *     'html'      => '<div>…</div>',     // optional, server-rendered HTML (trusted plugin output)
 *     'js'        => 'require([...])…',   // optional, render-time JS (trusted, collected via get_end_code);
 *                                         // the client injects html + runs js via core/templates
 *     'js_module' => 'mod_x/preview',    // optional AMD module name for client-side rendering
 *     'payload'   => [ … ],              // optional data handed to the js_module
 *   ]
 *
 * This service never calls into skills and never renders anything: it only collects the precomputed
 * 'preview' blocks from the results and, across a multi-step confirm chain, concatenates HTML of the
 * same type. Because the block is computed before result sanitization (in the executor), previews no
 * longer depend on any per-skill result field surviving the sanitizer's whitelist.
 */
class preview_passthrough {
    /**
     * Resolve the preview JSON for a webservice response from executed skill results.
     *
     * @param array<int,mixed> $results Executed skill results (each may carry a precomputed 'preview').
     * @param int $threadid
     * @param string $metadatakey Thread-metadata key used to accumulate previews across a chain.
     * @param array<int,mixed> $loopresults Per-step loop results (each entry: {..., results: [...]}).
     * @return string JSON-encoded preview block, or '' when there is none.
     */
    public static function resolve_preview_json(
        array $results,
        int $threadid,
        string $metadatakey = '_confirm_previews',
        array $loopresults = []
    ): string {
        $preview = self::extract_first_preview($results, $loopresults);

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
     * Return the first precomputed preview block, or null.
     *
     * Scans the terminal top-level results first (e.g. a confirmed mutation), then the loop-step
     * results (read skills such as get_option_details/search_options/explain_docs execute as internal
     * loop steps, so their result lives in loop_results, not in the terminal `results`). Most recent
     * loop step wins.
     *
     * @param array<int,mixed> $results Terminal top-level results.
     * @param array<int,mixed> $loopresults Per-step loop results (each entry: {..., results: [...]}).
     * @return array<string,mixed>|null
     */
    private static function extract_first_preview(array $results, array $loopresults): ?array {
        $preview = self::first_preview_in_entries($results);
        if ($preview !== null) {
            return $preview;
        }

        // Most recent loop step first.
        for ($i = count($loopresults) - 1; $i >= 0; $i--) {
            $step = $loopresults[$i];
            if (!is_array($step)) {
                continue;
            }
            $preview = self::first_preview_in_entries((array)($step['results'] ?? []));
            if ($preview !== null) {
                return $preview;
            }
        }

        return null;
    }

    /**
     * Return the first precomputed preview block within a flat list of result entries.
     *
     * Each entry may carry a self-contained 'preview' block attached by the executor at execution
     * time. This service does not call into skills; it only forwards the precomputed data.
     *
     * @param array<int,mixed> $entries
     * @return array<string,mixed>|null
     */
    private static function first_preview_in_entries(array $entries): ?array {
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $preview = $entry['preview'] ?? null;
            if (is_array($preview) && trim((string)($preview['type'] ?? '')) !== '') {
                return $preview;
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

        // Concatenate render-time JS the same way as HTML, so a multi-step chain that accumulates
        // several HTML blocks also accumulates each block's initialisation JS.
        $oldjs = isset($accumulated['js']) && is_string($accumulated['js']) ? $accumulated['js'] : '';
        $newjs = isset($preview['js']) && is_string($preview['js']) ? $preview['js'] : '';
        if ($oldjs !== '' && strpos($newjs, $oldjs) === false) {
            $preview['js'] = trim($oldjs . "\n" . $newjs);
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
