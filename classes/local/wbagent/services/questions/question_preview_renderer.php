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
 * Server-side renderer for the native Moodle question preview.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services\questions;

use context;
use question_bank;
use question_engine;
use question_display_options;

/**
 * Renders freshly created questions inline using Moodle's native question rendering.
 *
 * This mirrors what /question/bank/previewquestion/preview.php does (build a transient
 * question_usage in the question bank's module context, start the question, render it),
 * but returns the rendered HTML so it can be handed to the agent preview pane via a skill's
 * get_result_preview() instead of opening the standalone preview page.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_preview_renderer {
    /** Hard cap on how many questions we render inline (keeps the payload sane). */
    private const MAX_RENDER = 20;

    /** Behaviour used for the (non-interactive) preview render. */
    private const PREVIEW_BEHAVIOUR = 'deferredfeedback';

    /**
     * Render the given questions as a single block of trusted HTML.
     *
     * Best-effort: any question that cannot be loaded or rendered is skipped. Returns an empty
     * string when nothing could be rendered, so the caller can decide to expose no preview.
     *
     * @param int[]  $questionids     Question ids to render (in creation order).
     * @param int    $bankcontextid   Context id of the question bank module the questions live in.
     * @param string $bankurl         URL of the question bank (for the "open in bank" link).
     * @return string Rendered HTML, or '' when nothing could be rendered.
     */
    public function render(array $questionids, int $bankcontextid, string $bankurl = ''): string {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');

        $questionids = array_values(array_filter(array_map('intval', $questionids)));
        if (empty($questionids)) {
            return '';
        }

        $context = context::instance_by_id($bankcontextid, IGNORE_MISSING);
        if (!$context) {
            return '';
        }

        $options = self::build_display_options();
        $bodyhtml = '';
        $rendered = 0;

        // This runs inside the synchronous webservice request that returns JSON. Question rendering can
        // emit stray output (developer-debug notices, filter warnings) straight to the output buffer,
        // which would corrupt the JSON envelope ("Unexpected token '<'"). Buffer the whole render and
        // discard anything echoed: only the returned HTML strings are kept. We also deliberately do NOT
        // call question_engine::initialise_js()/render_question_head_html() — those mutate $PAGE page
        // requirements (and would log "added too late" debugging here), and their JS/CSS would not run
        // when the block is injected via innerHTML anyway.
        ob_start();
        try {
            foreach ($questionids as $questionid) {
                if ($rendered >= self::MAX_RENDER) {
                    break;
                }
                try {
                    $question = question_bank::load_question($questionid);
                    // Each question gets its own transient usage so one bad question cannot poison the rest.
                    $quba = question_engine::make_questions_usage_by_activity('bookingextension_agent', $context);
                    $quba->set_preferred_behaviour(self::PREVIEW_BEHAVIOUR);
                    $slot = $quba->add_question($question, $question->defaultmark);
                    $quba->start_question($slot);

                    $bodyhtml .= \html_writer::div(
                        $quba->render_question($slot, $options, (string)($rendered + 1)),
                        'bookingextension_agent-question-preview-item'
                    );
                    $rendered++;
                } catch (\Throwable $e) {
                    continue;
                }
            }
        } finally {
            // Drop any stray echoed output so it never reaches the JSON response.
            ob_end_clean();
        }

        if ($rendered === 0) {
            return '';
        }

        $heading = \html_writer::tag(
            'h5',
            get_string('previewquestions_heading', 'bookingextension_agent', $rendered),
            ['class' => 'bookingextension_agent-question-preview-heading']
        );

        $banklink = '';
        if (trim($bankurl) !== '') {
            $banklink = \html_writer::div(
                \html_writer::link(
                    $bankurl,
                    get_string('previewquestions_openbank', 'bookingextension_agent'),
                    ['target' => '_blank', 'rel' => 'noopener']
                ),
                'bookingextension_agent-question-preview-banklink'
            );
        }

        return \html_writer::div(
            $heading . $bodyhtml . $banklink,
            'bookingextension_agent-question-preview'
        );
    }

    /**
     * Read-only display options that surface the correct answer and feedback so a teacher can judge
     * the generated question, without showing marks or an attempt.
     *
     * @return question_display_options
     */
    private static function build_display_options(): question_display_options {
        $options = new question_display_options();
        $options->readonly = true;
        $options->flags = question_display_options::HIDDEN;
        $options->marks = question_display_options::HIDDEN;
        $options->manualcomment = question_display_options::HIDDEN;
        $options->history = question_display_options::HIDDEN;
        $options->correctness = question_display_options::HIDDEN;
        $options->numpartscorrect = false;
        $options->feedback = question_display_options::VISIBLE;
        $options->generalfeedback = question_display_options::VISIBLE;
        $options->rightanswer = question_display_options::VISIBLE;
        return $options;
    }
}
