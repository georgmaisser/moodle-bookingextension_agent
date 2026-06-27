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
 * Cross-language bridge for skill discovery: normalise the embedding query to English.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\llm;

use bookingextension_agent\local\wizard\conversation_store;

/**
 * Translates the discovery query to English before it is embedded (SKILL_REWORK.md §5.7, "Weg B").
 *
 * Skill anchors (description + example_utterances) are English-only, but user queries can be in any
 * language. With a non-multilingual embedding model a German query like "Buche Anna fuer den Kurs ..."
 * mis-matches (the noun "Kurs" is pulled to course.search_courses) instead of the English book_users
 * anchors. Normalising the query to English first puts query and anchors in the same language, so the
 * cross-language gap disappears regardless of the embedding model.
 *
 * Scope: ONLY the embedding query is normalised — the conversation and the planner prompts stay in the
 * user's language (the synchronizer still answers in it). This is a pre-embedding normalisation step,
 * NOT lexical routing (it adds no keyword/substring matching).
 *
 * Fail-open: on any error, an empty/over-long result, or a missing provider, the original query is
 * returned unchanged, so discovery degrades to the previous behaviour rather than breaking.
 */
class query_english_normalizer {
    /** @var llm_call_service */
    private llm_call_service $llm;

    /**
     * Constructor.
     *
     * @param llm_call_service|null $llm
     */
    public function __construct(?llm_call_service $llm = null) {
        $this->llm = $llm ?? new llm_call_service(new conversation_store());
    }

    /**
     * Return an English version of the query for embedding. Idempotent for English input; fail-open.
     *
     * @param string $query   The raw user/discovery query.
     * @param int $contextid
     * @param int $userid
     * @param int $threadid    Thread id for debug logging (0 for internal lookups).
     * @return string English query, or the original query if normalisation is unavailable/failed.
     */
    public function to_english(string $query, int $contextid, int $userid, int $threadid = 0): string {
        $query = trim($query);
        if ($query === '') {
            return $query;
        }

        // Uses the configured planner/chat model (core_ai generate_text) — no model name hardcoded.
        $prompt = "You are a translation function inside a search system. Translate the text after 'TEXT:' "
            . "into English. Output ONLY the English translation as plain text — no quotes, no labels, no "
            . "commentary. If it is already English, output it unchanged. Keep proper names, quoted titles "
            . "and numbers verbatim.\n\nTEXT:\n" . $query;

        try {
            $result = $this->llm->invoke_for_context(
                $threadid,
                $contextid,
                $userid,
                'orc|p=disc|st=qnorm|ac=txt|rt=wb',
                $prompt
            );
        } catch (\Throwable $e) {
            return $query;
        }

        if (empty($result['success'])) {
            return $query;
        }

        $out = trim((string)($result['rawcontent'] ?? ''));
        // Strip wrapping quotes a model may add despite the instruction.
        $out = trim($out, "\"'");
        $out = trim($out);
        if ($out === '') {
            return $query;
        }

        // Guard against a degenerate response (the model rambled instead of translating): fall back.
        if (\core_text::strlen($out) > \core_text::strlen($query) * 6 + 200) {
            return $query;
        }

        return $out;
    }
}
