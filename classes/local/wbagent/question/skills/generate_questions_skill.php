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

namespace bookingextension_agent\local\wbagent\question\skills;

use bookingextension_agent\local\wbagent\course_targeted_skill;

use bookingextension_agent\local\wbagent\core\skills\core_skill_base;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\dto\skill_risk_class;
use bookingextension_agent\local\wbagent\dto\target_selector;
use bookingextension_agent\local\wbagent\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wbagent\services\preflight_result_v2;
use bookingextension_agent\local\wbagent\services\questions\question_bank_target_resolver;
use bookingextension_agent\local\wbagent\services\questions\question_generation_service;
use bookingextension_agent\local\wbagent\services\questions\question_import_service;
use bookingextension_agent\local\wbagent\services\questions\question_preview_renderer;
use context;
use moodle_url;

/**
 * Core skill: generate Moodle questions (question.generate_questions).
 *
 * Takes its source text either from the `content` input the user provided directly in the chat, or
 * from the most recent uploaded document (injected into the conversation as a "--- DOCUMENT --" block);
 * a document upload is optional. Asks the model to write the questions as GIFT and imports them into the
 * course's question bank (a mod_qbank activity, created if needed). If an import fails, the import errors
 * are fed back to the model and generation is retried.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_questions_skill extends core_skill_base implements skill_trigger_provider_interface {
    use course_targeted_skill;
    /** Skill name constant. */
    public const SKILL_NAME = 'question.generate_questions';

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
     * The cross-context target is a course.
     *
     * @return int
     */
    public function get_target_context_level(): int {
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
            'description' => 'Generate Moodle quiz/test questions (multiple choice, true/false, short answer) and '
                . 'save them into the course question bank. The questions can be based EITHER on a document/PDF the '
                . 'user uploaded OR on a topic, facts, or an explicit question and answer the user provides directly '
                . 'in the chat — an upload is NOT required. Use this whenever the user wants a question, quiz or test '
                . 'created or inserted into Moodle (e.g. "make me a question", "mach mir / erstelle eine Frage", '
                . '"create a quiz", "erstelle Fragen aus dem Dokument", "Frage in Moodle einfügen").',
            'readonly' => false,
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => 'SOURCE MATERIAL only — the topic, the facts, or (if the user dictated it) the '
                        . 'exact question and its correct answer, passed verbatim from the chat. Do NOT author or '
                        . 'pre-formulate the questions yourself here; this skill writes the questions. Leave empty if '
                        . 'the user uploaded a document/PDF instead.',
                    'required' => false,
                ],
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
                'target_category' => [
                    'type' => 'string',
                    'description' => 'The question-bank category to use, ONLY when the user explicitly names one '
                        . '(e.g. "use the Biology category"). Pass the user\'s wording verbatim. Do NOT ask the user '
                        . 'which category to use and do NOT invent one — if the choice matters, the system lists the '
                        . 'available categories itself. Leave empty otherwise.',
                    'required' => false,
                ],
                'target_categoryid' => [
                    'type' => 'integer',
                    'description' => 'Internal: numeric id of the chosen question-bank category. Normally leave empty '
                        . '— never guess an id. The system fills it in when the user picks from the listed categories.',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'Target a DIFFERENT course than the current one, ONLY when the user explicitly '
                        . 'names one (e.g. "create the questions in the course Biology 101"). Pass the user\'s wording '
                        . 'verbatim; resolve via course.search_courses first if you only know the name. Leave empty to '
                        . 'create the questions in the current course.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the target course, when already known. Leave empty for the current '
                        . 'course; never guess an id.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['content', 'count', 'qtypes', 'difficulty'],
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
                'id' => 'question.generate_questions_request',
                'description' => 'User wants a Moodle quiz/test question (a question, quiz or test) generated or '
                    . 'inserted into Moodle — based on an uploaded document/PDF OR on content the user provides '
                    . 'directly (e.g. "make me a question", "mach mir / erstelle eine Frage", "erstelle ein Quiz", '
                    . '"create 10 questions from this PDF", "Frage in Moodle einfügen").',
            ],
        ];
    }

    /**
     * Construction-phase guidance and discovery triggers.
     *
     * Surfaced unconditionally once this skill is selected, so the constructor knows a document upload
     * is optional and the user's inline content can be used directly.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'question.generate_questions',
                'triggers' => [
                    'make a question', 'create a question', 'generate questions', 'create a quiz', 'create a test',
                    'questions from pdf', 'questions from document', 'insert question in moodle',
                    'mach mir eine frage', 'erstelle eine frage', 'frage generieren', 'frage erstellen',
                    'quiz erstellen', 'test erstellen', 'fragen aus dem dokument', 'frage in moodle einfügen',
                ],
                'guidance' => [
                    '- question.generate_questions creates Moodle quiz questions and saves them into the course question'
                        . ' bank itself, so do NOT look for a separate skill to "insert" a question.',
                    '- A document/PDF upload is OPTIONAL. If the user states the topic, facts, or an explicit question'
                        . ' and correct answer in the chat, pass that text verbatim as input.content and proceed; do'
                        . ' NOT ask the user to upload a document.',
                    '- Only ask the user for a source if NEITHER a document was uploaded NOR any content was provided.',
                    '- Default to a single multiple-choice question unless the user asks otherwise; set input.count and'
                        . ' input.qtypes accordingly (allowed types: multichoice, truefalse, shortanswer).',
                    '- Do NOT ask the user which question bank or category to use, and never invent a category id. Leave'
                        . ' input.target_category and input.target_categoryid empty: if the course has more than one'
                        . ' category the system itself lists them and asks. Only if the user explicitly names a'
                        . ' category, pass that name verbatim as input.target_category.',
                ],
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
        $sourcetext = $this->resolve_source_text($input, $contextid, $userid);
        if ($sourcetext === null) {
            return preflight_result_v2::invalid([[
                'severity' => 'needs_clarification',
                'message' => 'I need something to base the questions on. Either upload a document/PDF, or tell me '
                    . 'the topic, the facts, or the exact question and its correct answer.',
                'code' => 'GENERATE_QUESTIONS_NO_SOURCE',
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

        // When the course already offers more than one writable question-bank category, ask the user
        // where exactly to create the questions instead of silently picking the default bank.
        $targetselection = $this->resolve_target_selection($input, $context, $userid);
        if ($targetselection instanceof preflight_result_v2) {
            return $targetselection;
        }

        $qtypes = array_values(array_filter(array_map('strval', (array)($input['qtypes'] ?? []))));
        $qtypes = array_values(array_intersect($qtypes, self::ALLOWED_QTYPES));

        return preflight_result_v2::ok([
            'sourcetext' => $sourcetext,
            'count' => max(1, min(question_generation_service::MAX_COUNT, (int)($input['count'] ?? self::DEFAULT_COUNT))),
            'qtypes' => $qtypes,
            'difficulty' => (string)($input['difficulty'] ?? 'medium'),
            'outputlang' => $this->get_output_language($input),
            'target_categoryid' => $targetselection,
        ]);
    }

    /**
     * Decide which question-bank category the questions go into.
     *
     * Returns the chosen category id (0 = let execute auto-resolve the course default bank), or a
     * needs_clarification preflight result when the course offers more than one writable target and
     * the user has not picked one yet.
     *
     * @param array   $input
     * @param context $context Ambient context of the run.
     * @param int     $userid
     * @return int|preflight_result_v2
     */
    private function resolve_target_selection(array $input, context $context, int $userid) {
        $resolver = new question_bank_target_resolver();
        $targets = $resolver->list_writable_targets($context, $userid);

        // No bank exists yet: nothing to choose between, execute lazily creates the default.
        if (empty($targets)) {
            return 0;
        }

        // 1) An explicit, valid category id (the system filled it in from a prior selection) wins.
        $chosenid = (int)($input['target_categoryid'] ?? 0);
        if ($chosenid > 0) {
            foreach ($targets as $target) {
                if ($target['categoryid'] === $chosenid) {
                    return $chosenid;
                }
            }
        }

        // 2) A category the user named in plain text: resolve it deterministically against the real
        // list here (the planner never knows the ids, so it can only pass the wording).
        $name = trim((string)($input['target_category'] ?? ''));
        if ($name !== '') {
            $matches = $this->match_targets_by_name($targets, $name);
            if (count($matches) === 1) {
                return (int)$matches[0]['categoryid'];
            }
            if (count($matches) > 1) {
                return $this->build_target_clarification(
                    $matches,
                    'More than one question category matches "' . $name . '". Which one did you mean?'
                );
            }
            return $this->build_target_clarification(
                $targets,
                'I could not find a question category called "' . $name . '". Please choose one of these:'
            );
        }

        // 3) An explicit id that did not match (stale / not writable) and no name to fall back on: re-ask.
        if ($chosenid > 0) {
            return $this->build_target_clarification(
                $targets,
                'That question category is not available to you. Please choose one of these:'
            );
        }

        // 4) A single writable target => no ambiguity; execute resolves (and lazily creates) the default.
        if (count($targets) <= 1) {
            return 0;
        }

        // 5) Several writable targets and nothing chosen yet => ask, listing them all.
        return $this->build_target_clarification(
            $targets,
            'This course has more than one question bank category you can add to. '
                . 'Where exactly should I create the questions?'
        );
    }

    /**
     * Match the writable targets against a user-provided category name.
     *
     * Tries an exact (case-insensitive) match on the category name or the "Bank › Category" label
     * first, then falls back to a substring match on the category name.
     *
     * @param array<int,array<string,mixed>> $targets
     * @param string $name
     * @return array<int,array<string,mixed>>
     */
    private function match_targets_by_name(array $targets, string $name): array {
        $needle = \core_text::strtolower(trim($name));
        if ($needle === '') {
            return [];
        }

        $exact = [];
        foreach ($targets as $target) {
            $category = \core_text::strtolower((string)$target['categoryname']);
            $label = \core_text::strtolower($target['bankname'] . ' › ' . $target['categoryname']);
            if ($category === $needle || $label === $needle) {
                $exact[] = $target;
            }
        }
        if (!empty($exact)) {
            return $exact;
        }

        $partial = [];
        foreach ($targets as $target) {
            if (str_contains(\core_text::strtolower((string)$target['categoryname']), $needle)) {
                $partial[] = $target;
            }
        }
        return $partial;
    }

    /**
     * Build a needs_clarification result that lists the available question-bank categories.
     *
     * The human-readable message carries the category ids, and a structured 'options' list is attached
     * so the answer can be mapped back deterministically to the target_categoryid input.
     *
     * @param array<int,array<string,mixed>> $targets
     * @param string $lead Lead-in sentence for the message.
     * @return preflight_result_v2
     */
    private function build_target_clarification(array $targets, string $lead): preflight_result_v2 {
        $lines = [$lead, ''];
        $options = [];
        foreach ($targets as $target) {
            $lines[] = sprintf(
                '- %s › %s (%d question(s)) [category id %d]',
                $target['bankname'],
                $target['categoryname'],
                (int)$target['questioncount'],
                (int)$target['categoryid']
            );
            $options[] = [
                'categoryid' => (int)$target['categoryid'],
                'label' => $target['bankname'] . ' › ' . $target['categoryname'],
                'bank' => $target['bankname'],
                'category' => $target['categoryname'],
                'questioncount' => (int)$target['questioncount'],
            ];
        }
        $lines[] = '';
        $lines[] = 'Just reply with the name of the category you want and I will create the questions there.';

        return preflight_result_v2::invalid([[
            'severity' => 'needs_clarification',
            'message' => implode("\n", $lines),
            'code' => 'GENERATE_QUESTIONS_TARGET_AMBIGUOUS',
            'options' => $options,
        ]]);
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

        // Resolve the target question bank. This is the confirmed mutation point. When the user picked
        // a specific category in the clarification, honour it; otherwise get-or-create the course default.
        $targetcategoryid = (int)($preparedinput['target_categoryid'] ?? 0);
        $ambient = context::instance_by_id($contextid, MUST_EXIST);
        try {
            $resolver = new question_bank_target_resolver();
            $target = $targetcategoryid > 0
                ? $resolver->resolve_selected_target($ambient, $targetcategoryid, $userid)
                : $resolver->resolve_for_context($ambient);
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

            $imported = $importer->import_gift(
                (string)$generated['gift'],
                $target['context'],
                $target['course'],
                $targetcategoryid > 0 ? $targetcategoryid : null
            );
            if (!empty($imported['success'])) {
                return $this->build_success_result(
                    (int)$imported['imported'],
                    array_map('intval', (array)$imported['questionids']),
                    (int)$target['cm']->id,
                    (string)$target['cm']->get_formatted_name(),
                    (int)$target['context']->id,
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
     * Resolve the text the questions are generated from: the content the user passed directly takes
     * precedence, otherwise the most recent uploaded-document block in the conversation is used.
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return string|null
     */
    private function resolve_source_text(array $input, int $contextid, int $userid): ?string {
        $content = trim((string)($input['content'] ?? ''));
        if ($content !== '') {
            return $content;
        }
        return $this->extract_document_text($contextid, $userid);
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
     * @param int    $bankcontextid Context id of the question bank module (used by the inline preview).
     * @param int    $attempts
     * @return array<string,mixed>
     */
    private function build_success_result(
        int $imported,
        array $questionids,
        int $cmid,
        string $bankname,
        int $bankcontextid,
        int $attempts
    ): array {
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
            'question_bank_contextid' => $bankcontextid,
            'observation_full' => $observation,
        ];
    }

    /**
     * Render the freshly created questions inline for the agent preview pane.
     *
     * The executor calls this on the raw execute() result; the returned block is attached under the
     * result's 'preview' key and surfaced in the preview pane. We render the questions with Moodle's
     * native question rendering (the same machinery the standalone preview page uses), so the teacher
     * sees the real, rendered questions inline instead of having to open the preview page.
     *
     * @param array $resultentry The skill result (carries created_question_ids + question_bank_contextid).
     * @param int   $contextid   Ambient context id of the run (unused: questions render in their bank context).
     * @param int   $userid      Acting user id.
     * @return array{type:string,html:string,payload:array}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $questionids = array_values(array_filter(array_map('intval', (array)($resultentry['created_question_ids'] ?? []))));
        $bankcontextid = (int)($resultentry['question_bank_contextid'] ?? 0);
        if (empty($questionids) || $bankcontextid <= 0) {
            return null;
        }

        $bankurl = (string)($resultentry['question_bank_url'] ?? '');
        $rendered = (new question_preview_renderer())->render($questionids, $bankcontextid, $bankurl);
        $html = (string)($rendered['html'] ?? '');
        if (trim($html) === '') {
            return null;
        }

        return [
            'type' => 'generated_questions',
            'html' => $html,
            // Render-time JS (qtype init, filters, MathJax) the client runs via core/templates.
            'js' => (string)($rendered['js'] ?? ''),
            'payload' => [
                'question_ids' => $questionids,
                'question_bank_url' => $bankurl,
            ],
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
