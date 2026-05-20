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
 * Generic planner service for compact input enrichment.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

use bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service;

/**
 * Planner service.
 *
 * Provides a compact, task-agnostic planning call that can enrich task input
 * without the large orchestrator prompt.
 */
class planner_service {
    /** @var array<string,array> Request-local cache for identical enrichment calls. */
    private static array $enrichmentcache = [];

    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store) {
        $this->store = $store;
    }

    /**
     * Enrich task input via a compact planner model call when suitable.
     *
     * @param string $taskname
     * @param array $schema
     * @param string $usermessage
     * @param array $input
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function enrich_recovery_input(
        string $taskname,
        array $schema,
        string $usermessage,
        array $input,
        int $threadid,
        int $cmid,
        int $userid
    ): array {
        if ($userid <= 0) {
            return $input;
        }

        $cachekey = $this->build_enrichment_cache_key($taskname, $usermessage, $input, $threadid, $cmid, $userid);
        if (isset(self::$enrichmentcache[$cachekey])) {
            return self::$enrichmentcache[$cachekey];
        }

        $properties = (array)($schema['properties'] ?? []);
        if (empty($properties) || trim($usermessage) === '') {
            self::$enrichmentcache[$cachekey] = $input;
            return $input;
        }

        $capabilities = array_values(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            (array)($schema['planner_capabilities'] ?? [])
        )));

        $isdockind = $this->is_docs_retrieval_schema($properties);
        $shouldplan = in_array('semantic_input_enrichment', $capabilities, true) || $isdockind;
        if (!$shouldplan) {
            self::$enrichmentcache[$cachekey] = $input;
            return $input;
        }

        $docsindexlines = [];
        $docsservice = null;
        if ($isdockind) {
            $docsservice = $this->create_docs_lookup_service();
            $docsindexlines = $this->build_docs_index_lines($docsservice, $usermessage);
        }

        $prompt = $this->build_planner_prompt(
            $taskname,
            $usermessage,
            $properties,
            $input,
            $docsindexlines
        );

        $llm = new llm_call_service($this->store);
        $call = $llm->invoke(
            $threadid,
            $cmid,
            $userid,
            $this->build_planner_debug_source($threadid),
            $prompt
        );
        if (empty($call['success'])) {
            self::$enrichmentcache[$cachekey] = $input;
            return $input;
        }

        $plannerpayload = $this->extract_planner_payload((string)($call['rawcontent'] ?? ''));
        $patch = (array)($plannerpayload['input_patch'] ?? []);
        $confidence = (float)($plannerpayload['confidence'] ?? 0.0);

        if (
            isset($properties['planner_confidence'])
            && $this->is_input_value_empty($input['planner_confidence'] ?? null)
            && $confidence > 0.0
        ) {
            $input['planner_confidence'] = max(0.0, min(1.0, $confidence));
        }

        if (empty($patch)) {
            self::$enrichmentcache[$cachekey] = $input;
            return $input;
        }

        $merged = $this->merge_input_patch($input, $patch, $properties, $docsservice);
        self::$enrichmentcache[$cachekey] = $merged;
        return $merged;
    }

    /**
     * Build a request-local deterministic cache key for enrichment invocations.
     *
     * @param string $taskname
     * @param string $usermessage
     * @param array $input
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @return string
     */
    private function build_enrichment_cache_key(
        string $taskname,
        string $usermessage,
        array $input,
        int $threadid,
        int $cmid,
        int $userid
    ): string {
        $payload = [
            'task' => trim($taskname),
            'user_message' => trim($usermessage),
            'input' => $input,
            'threadid' => $threadid,
            'cmid' => $cmid,
            'userid' => $userid,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            $json = serialize($payload);
        }

        return sha1($json);
    }

    /**
     * Determine whether a task schema looks like docs retrieval.
     *
     * @param array $properties
     * @return bool
     */
    private function is_docs_retrieval_schema(array $properties): bool {
        if (!isset($properties['question']) || !is_array($properties['question'])) {
            return false;
        }

        return isset($properties['doc_path']) || isset($properties['search_queries']);
    }

    /**
     * Build compact docs index lines for planner context.
     *
     * @param docs_lookup_service $service
     * @return array
     */
    private function build_docs_index_lines(docs_lookup_service $service, string $usermessage = ''): array {
        $lines = [];
        $index = $service->get_all_doc_index();
        $terms = $this->extract_search_terms($usermessage);

        if (!empty($terms)) {
            $scored = [];
            foreach ($index as $row) {
                $path = trim((string)($row['path'] ?? ''));
                if ($path === '') {
                    continue;
                }

                $haystack = mb_strtolower(
                    $path . ' ' . trim((string)($row['title'] ?? '')) . ' ' . trim((string)($row['excerpt'] ?? ''))
                );
                $score = 0;
                foreach ($terms as $term) {
                    if ($term !== '' && str_contains($haystack, $term)) {
                        $score++;
                    }
                }

                $row['__score'] = $score;
                $scored[] = $row;
            }

            usort($scored, static function (array $a, array $b): int {
                $left = (int)($a['__score'] ?? 0);
                $right = (int)($b['__score'] ?? 0);
                if ($left === $right) {
                    return strcmp((string)($a['path'] ?? ''), (string)($b['path'] ?? ''));
                }
                return $right <=> $left;
            });
            $index = array_slice($scored, 0, 24);
        } else {
            $index = array_slice($index, 0, 40);
        }

        foreach ($index as $row) {
            $path = trim((string)($row['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $title = trim((string)($row['title'] ?? ''));
            $excerpt = trim((string)($row['excerpt'] ?? ''));
            if (mb_strlen($excerpt) > 80) {
                $excerpt = mb_substr($excerpt, 0, 80);
            }
            $lines[] = '- ' . $path . ' | ' . $title . ' | ' . $excerpt;
        }

        return $lines;
    }

    /**
     * Build planner prompt.
     *
     * @param string $taskname
     * @param string $usermessage
     * @param array $properties
     * @param array $input
     * @param array $docsindexlines
     * @return string
     */
    private function build_planner_prompt(
        string $taskname,
        string $usermessage,
        array $properties,
        array $input,
        array $docsindexlines
    ): string {
        $propertynames = array_values(array_map('strval', array_keys($properties)));
        $propertyjson = json_encode($propertynames, JSON_UNESCAPED_UNICODE);
        $inputjson = json_encode($input, JSON_UNESCAPED_UNICODE);
        if (!is_string($propertyjson) || $propertyjson === '') {
            $propertyjson = '[]';
        }
        if (!is_string($inputjson) || $inputjson === '') {
            $inputjson = '{}';
        }

        $prompt = "You are a compact planner that enriches task input fields.\n"
            . "Return ONLY JSON object with keys: input_patch (object), confidence (number), reason (string).\n"
            . "Do not include markdown.\n"
            . "Rules:\n"
            . "- Only use keys from allowed_property_names.\n"
            . "- Prefer filling missing fields; do not overwrite non-empty values.\n"
            . "- Keep output concise and deterministic.\n"
            . "- If uncertain, return empty input_patch.\n"
            . "- For search_queries, return at most 2 short strings.\n"
            . "- For doc_path, use an exact listed docs path when index is provided.\n\n"
            . "- Never invent placeholder paths like path/to/... or docs/faq/... unless exactly present in docs_index.\n\n"
            . "task_name:\n"
            . $taskname
            . "\n\n"
            . "allowed_property_names:\n"
            . $propertyjson
            . "\n\n"
            . "current_input:\n"
            . $inputjson
            . "\n\n"
            . "user_message:\n"
            . trim($usermessage);

        if (!empty($docsindexlines)) {
            $prompt .= "\n\n"
                . "docs_planning_hints:\n"
                . "- For docs tasks, prefer structured keys in input_patch: topic_hint, doc_path_candidates, retrieval_goal.\n"
                . "- If docs_index contains relevant paths, provide doc_path or non-empty doc_path_candidates.\n"
                . "- topic_hint should be short and semantic.\n"
                . "- doc_path_candidates should contain 1 to 3 exact paths from docs_index.\n"
                . "- retrieval_goal should be one of: configure_howto, concept_explanation, troubleshooting, api_reference.\n"
                . "- Questions about booking confirmations, reminders, cancellation mails, or what happens when someone booked belong to booking_rules/* docs first.\n"
                . "- Prefer booking_rules/templates.md or booking_rules/actions.md over actions_after_booking/* for rule-based notification questions.\n"
                . "- Do not map 'wenn jemand gebucht hat' / 'when someone booked' to actions_after_booking unless the user explicitly asks about the Actions After Booking feature.\n"
                . "- Keep search_queries short and language-agnostic.\n\n"
                . "docs_index:\n"
                . implode("\n", $docsindexlines);
        }

        return $prompt;
    }

    /**
     * Extract compact search terms from user message.
     *
     * @param string $message
     * @return array<int,string>
     */
    private function extract_search_terms(string $message): array {
        $message = mb_strtolower(trim($message));
        if ($message === '') {
            return [];
        }

        $parts = preg_split('/[^\p{L}\p{N}_-]+/u', $message) ?: [];
        $terms = [];
        foreach ($parts as $part) {
            $term = trim($part);
            if (mb_strlen($term) < 3) {
                continue;
            }
            $terms[$term] = true;
            if (count($terms) >= 8) {
                break;
            }
        }

        return array_keys($terms);
    }

    /**
     * Parse planner output and return normalized planner payload.
     *
     * @param string $raw
     * @return array{input_patch:array,confidence:float}
     */
    private function extract_planner_payload(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') {
            return ['input_patch' => [], 'confidence' => 0.0];
        }

        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            $fence = str_repeat(chr(96), 3);
            $stripped = str_replace([$fence . 'json', $fence], '', $raw);
            $parsed = json_decode(trim($stripped), true);
        }
        if (!is_array($parsed)) {
            return ['input_patch' => [], 'confidence' => 0.0];
        }

        $patch = (array)($parsed['input_patch'] ?? []);
        $confidence = (float)($parsed['confidence'] ?? 0.0);
        if ($confidence < 0.0 || $confidence > 1.0) {
            $confidence = max(0.0, min(1.0, $confidence));
        }

        return [
            'input_patch' => is_array($patch) ? $patch : [],
            'confidence' => $confidence,
        ];
    }

    /**
     * Merge planner patch into input using schema-safe constraints.
     *
     * @param array $input
     * @param array $patch
     * @param array $properties
     * @param docs_lookup_service|null $docsservice
     * @return array
     */
    private function merge_input_patch(
        array $input,
        array $patch,
        array $properties,
        ?docs_lookup_service $docsservice = null
    ): array {
        foreach ($patch as $key => $value) {
            $name = trim((string)$key);
            if ($name === '' || !isset($properties[$name])) {
                continue;
            }

            if ($name === 'search_queries') {
                if (!$this->is_input_value_empty($input[$name] ?? null)) {
                    continue;
                }
                $queries = array_values(array_filter(array_slice(array_map(
                    'trim',
                    (array)$value
                ), 0, 2)));
                if (!empty($queries)) {
                    $input[$name] = $queries;
                }
                continue;
            }

            if ($name === 'doc_path') {
                $docpath = trim((string)$value);
                if ($docpath === '') {
                    continue;
                }
                if ($docsservice !== null && $docsservice->read_doc_by_path($docpath, 1, 20) === null) {
                    continue;
                }

                 $currentpath = trim((string)($input[$name] ?? ''));
                if ($currentpath !== '') {
                    if ($docsservice === null || $docsservice->read_doc_by_path($currentpath, 1, 20) !== null) {
                        continue;
                    }
                }

                $input[$name] = $docpath;
                continue;
            }

            if ($name === 'doc_path_candidates') {
                $candidates = array_values(array_filter(array_slice(array_map(
                    'trim',
                    (array)$value
                ), 0, 3)));
                if ($docsservice !== null) {
                    $candidates = array_values(array_filter(
                        $candidates,
                        static function (string $candidate) use ($docsservice): bool {
                            return $docsservice->read_doc_by_path($candidate, 1, 20) !== null;
                        }
                    ));
                }

                $existingcandidates = array_values(array_filter(array_map(
                    'trim',
                    (array)($input[$name] ?? [])
                )));
                if ($docsservice !== null) {
                    $existingcandidates = array_values(array_filter(
                        $existingcandidates,
                        static function (string $candidate) use ($docsservice): bool {
                            return $docsservice->read_doc_by_path($candidate, 1, 20) !== null;
                        }
                    ));
                }
                if (!empty($existingcandidates)) {
                    continue;
                }

                if (!empty($candidates)) {
                    $input[$name] = $candidates;
                }
                continue;
            }

            if (!$this->is_input_value_empty($input[$name] ?? null)) {
                continue;
            }

            if ($name === 'planner_confidence') {
                $confidence = (float)$value;
                if ($confidence > 0.0) {
                    $input[$name] = max(0.0, min(1.0, $confidence));
                }
                continue;
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    $input[$name] = $trimmed;
                }
                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value) || is_array($value)) {
                $input[$name] = $value;
            }
        }

        return $input;
    }

    /**
     * Determine whether an input value should be considered empty.
     *
     * @param mixed $value
     * @return bool
     */
    private function is_input_value_empty($value): bool {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return empty($value);
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value <= 0;
        }
        return false;
    }

    /**
     * Create docs lookup service from booking config.
     *
     * @return docs_lookup_service
     */
    private function create_docs_lookup_service(): docs_lookup_service {
        $docsroot = trim((string)(get_config('bookingextension_agent', 'aidocsroot') ?? ''));
        $rootdocpath = trim((string)(get_config('bookingextension_agent', 'aidocsentry') ?? 'README.md'));

        return new docs_lookup_service($docsroot !== '' ? $docsroot : null, $rootdocpath !== '' ? $rootdocpath : null);
    }

    /**
     * Build a compact debug source string in the same style as orchestrator telemetry.
     *
     * @param int $threadid
     * @return string
     */
    private function build_planner_debug_source(int $threadid): string {
        $historycount = 0;
        if ($threadid > 0) {
            $historycount = count($this->store->get_recent_messages($threadid, 8));
        }

        return 'orc'
            . '|st=pln'
            . '|ac=gen'
            . '|rt=oa'
            . '|fb=0'
            . '|pv=oai'
            . '|hm=' . max(0, $historycount)
            . '|ob=0'
            . '|ex=0';
    }
}
