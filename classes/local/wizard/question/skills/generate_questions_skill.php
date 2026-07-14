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

namespace bookingextension_agent\local\wizard\question\skills;

use bookingextension_agent\local\wizard\course_targeted_skill;
use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\dto\target_selector;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\attachment\pdf_text_extractor;
use bookingextension_agent\local\wizard\services\questions\course_pdf_resolver;
use bookingextension_agent\local\wizard\services\questions\question_bank_target_resolver;
use bookingextension_agent\local\wizard\services\questions\question_generation_service;
use bookingextension_agent\local\wizard\services\questions\question_import_service;
use bookingextension_agent\local\wizard\services\questions\question_preview_renderer;
use bookingextension_agent\local\wizard\services\preview_support;
use context;
use moodle_url;

/**
 * Core skill: generate Moodle questions (question.generate_questions).
 *
 * Takes its source text from the `content` input the user provided directly in the chat, from PDF
 * files that live IN the target course as resource activities (`resourcecmid`/`usecoursepdfs`, read
 * server-side via course_pdf_resolver — no re-upload needed), or from the most recent uploaded
 * document (injected into the conversation as a "--- DOCUMENT --" block); a document upload is
 * optional. Asks the model to write the questions as GIFT and imports them into the course's
 * question bank (a mod_qbank activity, created if needed). If an import fails, the import errors
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

    /** @var int Runtime thread id injected by the executor (0 = resolve the ambient chat thread). */
    private int $runtimethreadid = 0;

    /**
     * Constructor. Mutating skill (writes questions) — broad write, requires confirmation.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2);
    }

    /**
     * Receive the executing thread id from the engine (duck-typed executor injection).
     *
     * Chat executions resolve the ambient chat thread themselves, but channel-bound
     * executions (e.g. the MCP facade) run on a thread get_active_thread() cannot see.
     * The executor hands the actual thread id to skills that declare this setter.
     *
     * @param int $threadid
     * @return void
     */
    public function set_runtime_threadid(int $threadid): void {
        $this->runtimethreadid = $threadid;
    }

    /**
     * Resolve the thread this execution belongs to (injected id first, chat thread fallback).
     *
     * @param int $userid
     * @param int $contextid
     * @return int Thread id, or 0 when none exists.
     */
    private function resolve_thread_id(int $userid, int $contextid): int {
        if ($this->runtimethreadid > 0) {
            return $this->runtimethreadid;
        }
        $store = new conversation_store();
        $thread = $store->get_active_thread($userid, $contextid);
        return $thread ? (int)$thread->id : 0;
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
     * Human-readable preview of the question generation (tier-3): target + plan.
     *
     * The source content (PDF text) is intentionally not shown — only the plan.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = preview_support::lang($input);
        $rows = [];
        preview_support::push($rows, preview_support::str('previewlabel_course', $lang), preview_support::course_name($input));
        preview_support::push(
            $rows,
            preview_support::str('previewlabel_category', $lang),
            preview_support::text($input['target_category'] ?? null)
        );
        preview_support::push(
            $rows,
            preview_support::str('previewlabel_number', $lang),
            preview_support::posint($input['count'] ?? null)
        );
        preview_support::push(
            $rows,
            preview_support::str('previewlabel_types', $lang),
            preview_support::list_value($input['qtypes'] ?? null)
        );
        preview_support::push(
            $rows,
            preview_support::str('previewlabel_difficulty', $lang),
            preview_support::text($input['difficulty'] ?? null)
        );
        preview_support::push(
            $rows,
            preview_support::str('previewlabel_sourcepdfs', $lang),
            $this->describe_pdf_source($input, $lang)
        );
        return [
            'title' => preview_support::str('previewtitle_generatequestions', $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Preview value for the course-PDF source, or null when the input does not use one.
     *
     * @param array $input Raw command input.
     * @param string $lang Conversation language.
     * @return string|null
     */
    private function describe_pdf_source(array $input, string $lang): ?string {
        $resourcecmid = (int)($input['resourcecmid'] ?? 0);
        if ($resourcecmid > 0) {
            try {
                $cm = get_coursemodule_from_id('resource', $resourcecmid);
                if ($cm) {
                    return format_string($cm->name);
                }
            } catch (\Throwable $e) {
                // Fall through to the generic label; the preview must never throw.
                $cm = null;
            }
            return 'cmid ' . $resourcecmid;
        }
        if (preview_support::truthy($input['usecoursepdfs'] ?? null)) {
            return preview_support::str('ai_generatequestions_previewallcoursepdfs', $lang);
        }
        return null;
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
                . 'save them into the course question bank. The questions can be based on a document/PDF the '
                . 'user uploaded, on the PDF files already stored IN the target course as resource activities '
                . '(set usecoursepdfs, or resourcecmid for one specific file — the system reads them itself, no '
                . 're-upload needed), OR on a topic, facts, or an explicit question and answer the user provides '
                . 'directly in the chat — an upload is NOT required. Use this whenever the user wants a question, '
                . 'quiz or test created or inserted into Moodle (e.g. "make me a question", "create a question", '
                . '"create a quiz", "create questions from the document", "create a quiz from the PDFs in the '
                . 'course", "insert a question in Moodle").',
            'readonly' => false,
            'example_utterances' => [
                'create quiz questions from this PDF',
                'make me a multiple choice question about photosynthesis',
                'generate 10 test questions from the document',
                'create a quiz from the PDFs in the course',
                'make questions from the handout file in course Biology 101',
                'add some questions to the question bank',
                'turn this material into a quiz',
            ],
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
                    'description' => 'How many questions to generate (max ' . question_generation_service::MAX_COUNT
                        . '). There is NO default — set it to the number the user gave; if they did not say how many, '
                        . 'leave it out so the system asks (never invent a number).',
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
                'resourcecmid' => [
                    'type' => 'integer',
                    'description' => 'Course-module id (cmid) of ONE specific file/resource activity in the target '
                        . 'course whose PDF should be the source material, when the user points at one specific '
                        . 'course file AND the id is already known (e.g. from a prior listing). Never guess an id; '
                        . 'leave empty otherwise.',
                    'required' => false,
                ],
                'usecoursepdfs' => [
                    'type' => 'boolean',
                    'description' => 'Set true when the questions should be based on the PDF files stored IN the '
                        . 'target course (as file/resource activities), e.g. "create a quiz from the PDFs in this '
                        . 'course". The system reads and extracts those files itself — do NOT ask the user to upload '
                        . 'them again. Combine with courseid/coursequery when the user names another course.',
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
     * @return array
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
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'question.generate_questions_request',
                'description' => 'User wants a Moodle quiz/test question (a question, quiz or test) generated or '
                    . 'inserted into Moodle — based on an uploaded document/PDF, on the PDF files stored in a '
                    . 'course, OR on content the user provides directly (e.g. "make me a question", '
                    . '"create a question", "create 10 questions from this PDF", '
                    . '"create a quiz from the PDFs in the course", "insert a question into Moodle").',
            ],
        ];
    }

    /**
     * Construction-phase guidance and discovery triggers.
     *
     * Surfaced unconditionally once this skill is selected, so the constructor knows a document upload
     * is optional and the user's inline content can be used directly.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'question.generate_questions',
                'triggers' => [
                    'make a question', 'create a question', 'generate questions', 'create a quiz', 'create a test',
                    'questions from pdf', 'questions from document', 'insert question in moodle',
                    'make me a question', 'create a question', 'generate question',
                    'create quiz', 'create test', 'questions from the document', 'insert question into moodle',
                    'quiz from the pdfs in the course', 'questions from the files in the course',
                    'quiz about the pdfs in course',
                ],
                'guidance' => [
                    '- question.generate_questions creates Moodle quiz questions and saves them into the course question'
                        . ' bank itself, so do NOT look for a separate skill to "insert" a question.',
                    '- A document/PDF upload is OPTIONAL. If the user states the topic, facts, or an explicit question'
                        . ' and correct answer in the chat, pass that text verbatim as input.content and proceed; do'
                        . ' NOT ask the user to upload a document.',
                    '- When the user wants the questions based on PDFs/files that are already IN the course (e.g. "a'
                        . ' quiz about the PDFs in this course"), set input.usecoursepdfs=true (or input.resourcecmid'
                        . ' for one specific file whose cmid is known). The system reads and extracts those course'
                        . ' files itself — do NOT ask the user to upload them and do NOT paste file text into'
                        . ' input.content.',
                    '- Only ask the user for a source if NEITHER a document was uploaded NOR course PDFs were requested'
                        . ' NOR any content was provided.',
                    '- Set input.count to the number of questions the user asked for; if they did not say how many,'
                        . ' leave input.count out so the system asks (no silent default). Set input.qtypes when named'
                        . ' (allowed types: multichoice, truefalse, shortanswer).',
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
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];

        // B4 (Georg 2026-07-14): the question count is NEVER silently defaulted — if the user did
        // not say how many questions, ask. RECOVERABLE_INPUT_ERROR routes it as a clarification turn,
        // not a confirmation card with a fabricated number (thread 587).
        if (!isset($input['count']) || trim((string)$input['count']) === '') {
            return [
                'valid' => false,
                'errors' => ['How many questions should be generated?'],
                'repair' => ['count is required: ask the user for the number of questions; never default it.'],
                'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
                'ambiguities' => [],
            ];
        }

        if ($input['count'] !== '') {
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
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        // The course context comes first: the course-PDF source paths need the target course id.
        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        $coursecontext = $context ? $context->get_course_context(false) : false;
        if (!$coursecontext) {
            return $this->invalid([[
                'severity' => 'needs_clarification',
                'message' => 'Questions can only be generated within a course.',
                'code' => 'GENERATE_QUESTIONS_NO_COURSE',
            ]]);
        }

        $source = $this->resolve_source($input, $contextid, $userid, (int)$coursecontext->instanceid);
        if (!empty($source['issues'])) {
            return $this->invalid($source['issues']);
        }
        $sourcetext = $source['text'];
        if ($sourcetext === null) {
            return $this->invalid([[
                'severity' => 'needs_clarification',
                'message' => 'I need something to base the questions on. Either upload a document/PDF, or tell me '
                    . 'the topic, the facts, or the exact question and its correct answer.',
                'code' => 'GENERATE_QUESTIONS_NO_SOURCE',
            ]]);
        }

        // Gate 2: the user must natively be allowed to add questions in this course.
        if (!has_capability('moodle/question:add', $coursecontext, $userid)) {
            return $this->invalid([[
                'severity' => 'needs_clarification',
                'message' => get_string('nopermissions', 'error', 'moodle/question:add'),
                'code' => 'NO_NATIVE_CAPABILITY',
            ]]);
        }

        // When the course already offers more than one writable question-bank category, ask the user
        // where exactly to create the questions instead of silently picking the default bank.
        $targetselection = $this->resolve_target_selection($input, $context, $userid);
        if (is_array($targetselection)) {
            return $targetselection;
        }

        $qtypes = array_values(array_filter(array_map('strval', (array)($input['qtypes'] ?? []))));
        $qtypes = array_values(array_intersect($qtypes, self::ALLOWED_QTYPES));

        return $this->pass([
            'sourcetext' => $sourcetext,
            'sourcefiles' => $source['files'],
            'sourcetruncated' => $source['truncated'],
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
     * @return int|array
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
     * @param array[] $targets
     * @param string $name
     * @return array[]
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
     * @param array[] $targets
     * @param string $lead Lead-in sentence for the message.
     * @return array
     */
    private function build_target_clarification(array $targets, string $lead): array {
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

        return $this->invalid([[
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
        $threadid = $this->resolve_thread_id($userid, $contextid);

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
                    $attempt,
                    (array)($preparedinput['sourcefiles'] ?? []),
                    !empty($preparedinput['sourcetruncated'])
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
     * Resolve the text the questions are generated from.
     *
     * Priority: explicit `content` from the chat > PDFs stored in the target course
     * (resourcecmid for one specific resource, usecoursepdfs for all visible ones) > the most
     * recent uploaded-document block in the conversation.
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @param int   $courseid Target course id (already resolved from the operating context).
     * @return array text (string|null), files (array of cmid/name/filename per used course PDF),
     *               truncated (bool), issues (array, non-empty when the course-PDF source failed).
     */
    private function resolve_source(array $input, int $contextid, int $userid, int $courseid): array {
        $none = ['text' => null, 'files' => [], 'truncated' => false, 'issues' => []];

        $content = trim((string)($input['content'] ?? ''));
        if ($content !== '') {
            return ['text' => $content] + $none;
        }

        $resourcecmid = (int)($input['resourcecmid'] ?? 0);
        if ($resourcecmid > 0 || preview_support::truthy($input['usecoursepdfs'] ?? null)) {
            return $this->resolve_course_pdf_source($resourcecmid, $courseid, $userid, $this->get_output_language($input));
        }

        return ['text' => $this->extract_document_text($contextid, $userid)] + $none;
    }

    /**
     * Source the text from PDFs that live in the target course as resource activities.
     *
     * The files are read server-side (never through the LLM) for the ACTING user only —
     * course_pdf_resolver lists nothing the user cannot see. Every failure is returned as a
     * localized needs_clarification issue, never as a raw exception.
     *
     * @param int    $resourcecmid One specific resource cm id, or 0 for all visible course PDFs.
     * @param int    $courseid Target course id.
     * @param int    $userid Acting user id.
     * @param string $lang Conversation output language.
     * @return array Same shape as resolve_source().
     */
    private function resolve_course_pdf_source(int $resourcecmid, int $courseid, int $userid, string $lang): array {
        $issue = function (string $identifier, $a, string $code) use ($lang): array {
            return ['text' => null, 'files' => [], 'truncated' => false, 'issues' => [[
                'severity' => 'needs_clarification',
                'message' => $this->localized_string($identifier, $a, $lang),
                'code' => $code,
            ]]];
        };

        if (!(new pdf_text_extractor())->is_available()) {
            return $issue('ai_pdf_extraction_unavailable', null, 'GENERATE_QUESTIONS_EXTRACTOR_UNAVAILABLE');
        }

        $resolver = new course_pdf_resolver();
        if ($resourcecmid > 0) {
            $lookup = $resolver->get_resource_pdf($courseid, $resourcecmid, $userid);
            switch ($lookup['status']) {
                case course_pdf_resolver::STATUS_NOT_FOUND:
                    return $issue(
                        'ai_generatequestions_resourcenotfound',
                        $resourcecmid,
                        'GENERATE_QUESTIONS_RESOURCE_NOT_FOUND'
                    );
                case course_pdf_resolver::STATUS_NO_PDF:
                    return $issue(
                        'ai_generatequestions_resourcenopdf',
                        $lookup['name'],
                        'GENERATE_QUESTIONS_RESOURCE_NO_PDF'
                    );
                case course_pdf_resolver::STATUS_TOO_LARGE:
                    return $issue(
                        'ai_generatequestions_pdftoolarge',
                        (object)[
                            'name' => $lookup['name'],
                            'limitmb' => (int)(course_pdf_resolver::MAX_FILE_BYTES / (1024 * 1024)),
                        ],
                        'GENERATE_QUESTIONS_PDF_TOO_LARGE'
                    );
            }
            $pdfs = [$lookup['pdf']];
        } else {
            $pdfs = $resolver->list_course_pdfs($courseid, $userid);
            if (empty($pdfs)) {
                return $issue(
                    'ai_generatequestions_nopdfsincourse',
                    $this->course_display_name($courseid),
                    'GENERATE_QUESTIONS_NO_COURSE_PDFS'
                );
            }
        }

        try {
            $extracted = $resolver->extract_texts($pdfs, self::effective_pdf_budget());
        } catch (\Throwable $e) {
            return $issue('ai_pdf_extraction_unavailable', null, 'GENERATE_QUESTIONS_EXTRACTOR_UNAVAILABLE');
        }
        if (trim($extracted['text']) === '') {
            return $issue('ai_generatequestions_extractionfailed', null, 'GENERATE_QUESTIONS_EXTRACTION_FAILED');
        }

        return [
            'text' => $extracted['text'],
            'files' => $extracted['used'],
            'truncated' => (bool)$extracted['truncated'],
            'issues' => [],
        ];
    }

    /**
     * The effective character cap for course-PDF source text before the LLM call.
     *
     * The chat-upload path already caps the injected document text at pdf_text_extractor::MAX_CHARS
     * (applied by attachment_processor at upload time), so the course-PDF path reuses exactly that
     * existing cap as its total extraction budget instead of inventing a new one.
     *
     * @return int
     */
    private static function effective_pdf_budget(): int {
        return min(course_pdf_resolver::DEFAULT_TOTAL_BUDGET, pdf_text_extractor::MAX_CHARS);
    }

    /**
     * The formatted course full name, or '#<id>' when the course cannot be read.
     *
     * @param int $courseid
     * @return string
     */
    private function course_display_name(int $courseid): string {
        try {
            return format_string(get_course($courseid)->fullname);
        } catch (\Throwable $e) {
            return '#' . $courseid;
        }
    }

    /**
     * Find the most recent uploaded-document text in the conversation.
     *
     * @param int $contextid
     * @param int $userid
     * @return string|null
     */
    private function extract_document_text(int $contextid, int $userid): ?string {
        $threadid = $this->resolve_thread_id($userid, $contextid);
        if ($threadid <= 0) {
            return null;
        }

        $store = new conversation_store();
        $messages = $store->get_recent_messages($threadid, 20);
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
     * @param array  $sourcefiles Course PDFs the source text came from (cmid/name/filename each).
     * @param bool   $sourcetruncated Whether the assembled PDF text hit the extraction budget.
     * @return array
     */
    private function build_success_result(
        int $imported,
        array $questionids,
        int $cmid,
        string $bankname,
        int $bankcontextid,
        int $attempts,
        array $sourcefiles = [],
        bool $sourcetruncated = false
    ): array {
        $bankurl = (new moodle_url('/question/edit.php', ['cmid' => $cmid]))->out(false);
        $message = $imported . ' question(s) were created in the course question bank "' . $bankname . '".';

        $lines = [
            'Created ' . $imported . ' question(s) in question bank "' . $bankname . '" (after ' . $attempts . ' attempt(s)).',
            'Question ids: ' . implode(', ', $questionids),
            'Question bank: ' . $bankurl,
        ];

        // Name the course PDFs the questions are based on — each with the real resource link, so
        // the final answer can reference them as clickable entities.
        if (!empty($sourcefiles)) {
            $lines[] = get_string('ai_generatequestions_sourcepdfsheading', 'bookingextension_agent');
            foreach ($sourcefiles as $sourcefile) {
                $resourceurl = (new moodle_url(
                    '/mod/resource/view.php',
                    ['id' => (int)($sourcefile['cmid'] ?? 0)]
                ))->out(false);
                $lines[] = '- ' . (string)($sourcefile['filename'] ?? '')
                    . ' ("' . (string)($sourcefile['name'] ?? '') . '"): ' . $resourceurl;
            }
            if ($sourcetruncated) {
                $lines[] = get_string(
                    'ai_generatequestions_sourcetruncated',
                    'bookingextension_agent',
                    number_format(self::effective_pdf_budget())
                );
            }
        }

        $observation = implode("\n", $lines);

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
     * @return array
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
