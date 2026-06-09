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

namespace bookingextension_agent\local\wbagent\core\skills;

use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\dto\skill_risk_class;
use bookingextension_agent\local\wbagent\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wbagent\services\preflight_result_v2;
use bookingextension_agent\local\wbagent\services\questions\question_bank_target_resolver;
use bookingextension_agent\local\wbagent\services\questions\question_generation_service;
use bookingextension_agent\local\wbagent\services\questions\question_import_service;
use context;
use moodle_url;

/**
 * Core skill: generate Moodle questions from an uploaded document (core.generate_questions).
 *
 * Reads the PDF/document text the user uploaded (already injected into the conversation as a
 * "--- DOCUMENT --" block), asks the model to write the questions as GIFT, and imports them
 * into the course's question bank (a mod_qbank activity, created if needed). If an import
 * fails, the import errors are fed back to the model and generation is retried.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_questions_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'core.generate_questions';

    /** Default number of questions when not specified. */
    private const DEFAULT_COUNT = 5;

    /** How many generate+import attempts before giving up. */
    private const MAX_RETRIES = 3;

    /** Supported question types (MVP). */
    private const ALLOWED_QTYPES = ['multichoice', 'truefalse', 'shortanswer'];

    /**
     * Constructor. Mutating skill (writes questions) — broad write, requires confirmation.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2);
    }

    /**
     * Return skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * The questions land in a course question bank, so this skill needs course scope.
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_COURSE;
    }

    /**
     * Native capability required to create questions (Gate 2).
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return ['moodle/question:add'];
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Generate Moodle quiz questions from an uploaded document or PDF and save them into '
                . 'the course question bank. Use this whenever the user wants questions, a quiz, or a test created '
                . 'from a document they uploaded.',
            'readonly' => false,
            'properties' => [
                'count' => [
                    'type' => 'integer',
                    'description' => 'How many questions to generate (default ' . self::DEFAULT_COUNT
                        . ', max ' . question_generation_service::MAX_COUNT . ').',
                    'required' => false,
                ],
                'qtypes' => [
                    'type' => 'array',
                    'description' => 'Question types to use: ' . implode(', ', self::ALLOWED_QTYPES) . '.',
                    'items' => ['type' => 'string'],
                    'required' => false,
                ],
                'difficulty' => [
                    'type' => 'string',
                    'description' => 'Difficulty level: easy, medium or hard.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'ISO 639-1 language code for the questions (e.g. "de", "en").',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['count', 'qtypes', 'difficulty'],
                'anchor_fields' => [],
            ],
        ];
    }

    /**
     * Return example input for planner contract rendering.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return [
            'count' => 5,
            'qtypes' => ['multichoice', 'truefalse'],
            'difficulty' => 'medium',
            'outputlang' => 'en',
        ];
    }

    /**
     * Return message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'core.generate_questions_request',
                'description' => 'User wants Moodle quiz/test questions generated from an uploaded document or PDF '
                    . '(e.g. "create 10 questions from this PDF", "erstelle Fragen aus dem Dokument").',
            ],
        ];
    }

    /**
     * Structural validation (pure, no DB).
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];

        if (isset($input['count']) && $input['count'] !== '') {
            $count = (int)$input['count'];
            if ($count < 1 || $count > question_generation_service::MAX_COUNT) {
                $errors[] = 'count must be between 1 and ' . question_generation_service::MAX_COUNT . '.';
            }
        }

        if (isset($input['qtypes']) && $input['qtypes'] !== '') {
            foreach ((array)$input['qtypes'] as $qtype) {
                if (!in_array((string)$qtype, self::ALLOWED_QTYPES, true)) {
                    $errors[] = 'Unsupported question type: ' . (string)$qtype . '.';
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Deep validation: document text present + native capability at the course context.
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return preflight_result_v2
     */
    public function preflight(array $input, int $contextid, int $userid): preflight_result_v2 {
        $sourcetext = $this->extract_document_text($contextid, $userid);
        if ($sourcetext === null) {
            return preflight_result_v2::invalid([[
                'severity' => 'needs_clarification',
                'message' => 'No uploaded document was found in this conversation. Please upload a PDF first, '
                    . 'then ask me to generate the questions.',
                'code' => 'GENERATE_QUESTIONS_NO_DOCUMENT',
            ]]);
        }

        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        $coursecontext = $context ? $context->get_course_context(false) : false;
        if (!$coursecontext) {
            return preflight_result_v2::invalid([[
                'severity' => 'needs_clarification',
                'message' => 'Questions can only be generated within a course.',
                'code' => 'GENERATE_QUESTIONS_NO_COURSE',
            ]]);
        }

        // Gate 2: the user must natively be allowed to add questions in this course.
        if (!has_capability('moodle/question:add', $coursecontext, $userid)) {
            return preflight_result_v2::invalid([[
                'severity' => 'needs_clarification',
                'message' => get_string('nopermissions', 'error', 'moodle/question:add'),
                'code' => 'NO_NATIVE_CAPABILITY',
            ]]);
        }

        $qtypes = array_values(array_filter(array_map('strval', (array)($input['qtypes'] ?? []))));
        $qtypes = array_values(array_intersect($qtypes, self::ALLOWED_QTYPES));

        return preflight_result_v2::ok([
            'sourcetext' => $sourcetext,
            'count' => max(1, min(question_generation_service::MAX_COUNT, (int)($input['count'] ?? self::DEFAULT_COUNT))),
            'qtypes' => $qtypes,
            'difficulty' => (string)($input['difficulty'] ?? 'medium'),
            'outputlang' => $this->get_output_language($input),
        ]);
    }

    /**
     * Generate the questions and import them into the course question bank, retrying on import errors.
     *
     * @param array $preparedinput
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $sourcetext = (string)($preparedinput['sourcetext'] ?? '');
        if (trim($sourcetext) === '') {
            return $this->build_error_result('No document text was available to generate questions from.');
        }

        $params = [
            'count' => (int)($preparedinput['count'] ?? self::DEFAULT_COUNT),
            'qtypes' => (array)($preparedinput['qtypes'] ?? []),
            'difficulty' => (string)($preparedinput['difficulty'] ?? 'medium'),
            'outputlang' => (string)($preparedinput['outputlang'] ?? 'en'),
        ];

        $store = new conversation_store();
        $thread = $store->get_active_thread($userid, $contextid);
        $threadid = $thread ? (int)$thread->id : 0;

        // Resolve (get-or-create) the course question bank. This is the confirmed mutation point.
        $ambient = context::instance_by_id($contextid, MUST_EXIST);
        try {
            $target = (new question_bank_target_resolver())->resolve_for_context($ambient);
        } catch (\Throwable $e) {
            return $this->build_error_result($e->getMessage());
        }

        $generator = new question_generation_service($store);
        $importer = new question_import_service();

        $feedback = '';
        $lasterror = '';
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $generated = $generator->generate_gift($threadid, $contextid, $userid, $sourcetext, $params, $feedback);
            if (empty($generated['success'])) {
                $lasterror = (string)$generated['error'];
                $feedback = $lasterror;
                continue;
            }

            $imported = $importer->import_gift((string)$generated['gift'], $target['context'], $target['course']);
            if (!empty($imported['success'])) {
                return $this->build_success_result(
                    (int)$imported['imported'],
                    array_map('intval', (array)$imported['questionids']),
                    (int)$target['cm']->id,
                    (string)$target['cm']->get_formatted_name(),
                    $attempt
                );
            }

            $lasterror = (string)$imported['errors'];
            $feedback = $lasterror;
        }

        return $this->build_error_result(
            'Could not generate importable questions after ' . self::MAX_RETRIES . ' attempts. '
                . 'Last error: ' . $lasterror
        );
    }

    /**
     * Find the most recent uploaded-document text in the conversation.
     *
     * @param int $contextid
     * @param int $userid
     * @return string|null
     */
    private function extract_document_text(int $contextid, int $userid): ?string {
        $store = new conversation_store();
        $thread = $store->get_active_thread($userid, $contextid);
        if (!$thread) {
            return null;
        }

        $messages = $store->get_recent_messages((int)$thread->id, 20);
        foreach (array_reverse($messages) as $message) {
            if ((string)($message->role ?? '') !== 'user') {
                continue;
            }
            $document = self::parse_document_block((string)($message->content ?? ''));
            if ($document !== null) {
                return $document;
            }
        }

        return null;
    }

    /**
     * Extract the text from a "--- DOCUMENT: … --- … --- END DOCUMENT ---" block.
     *
     * @param string $content
     * @return string|null
     */
    private static function parse_document_block(string $content): ?string {
        if (preg_match('/---\s*DOCUMENT:.*?---\s*(.*?)\s*---\s*END DOCUMENT\s*---/s', $content, $matches)) {
            $text = trim($matches[1]);
            return $text !== '' ? $text : null;
        }
        return null;
    }

    /**
     * Build the success result payload.
     *
     * @param int    $imported
     * @param int[]  $questionids
     * @param int    $cmid
     * @param string $bankname
     * @param int    $attempts
     * @return array<string,mixed>
     */
    private function build_success_result(int $imported, array $questionids, int $cmid, string $bankname, int $attempts): array {
        $bankurl = (new moodle_url('/question/edit.php', ['cmid' => $cmid]))->out(false);
        $message = $imported . ' question(s) were created in the course question bank "' . $bankname . '".';

        $observation = implode("\n", [
            'Created ' . $imported . ' question(s) in question bank "' . $bankname . '" (after ' . $attempts . ' attempt(s)).',
            'Question ids: ' . implode(', ', $questionids),
            'Question bank: ' . $bankurl,
        ]);

        return [
            'status' => 'executed',
            'detail' => $message,
            'usermessage' => $message . ' You can review them here: ' . $bankurl,
            'resultid' => null,
            'question_count' => $imported,
            'created_question_ids' => $questionids,
            'question_bank_url' => $bankurl,
            'observation_full' => $observation,
        ];
    }

    /**
     * Build an error result payload.
     *
     * @param string $message
     * @return array<string,mixed>
     */
    private function build_error_result(string $message): array {
        return [
            'status' => 'error',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'observation_full' => $message,
        ];
    }
}
