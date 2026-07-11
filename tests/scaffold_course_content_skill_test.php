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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\course\skills\scaffold_course_content_skill;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use context_course;

/**
 * Contract and behaviour tests for course.scaffold_course_content.
 *
 * Content generation is scripted through llm_call_service::set_test_responder(), so the
 * whole scaffold (sections, pages) runs deterministically without a provider.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\course\skills\scaffold_course_content_skill
 * @covers     \bookingextension_agent\local\wizard\services\course\course_content_generation_service
 */
final class scaffold_course_content_skill_test extends advanced_testcase {
    /**
     * Reset the scripted responder after every test.
     */
    protected function tearDown(): void {
        llm_call_service::set_test_responder(null);
        parent::tearDown();
    }

    /**
     * Contract: R2 mutating course-scoped skill with section+activity gates and course targeting.
     */
    public function test_contract_shape(): void {
        $skill = new scaffold_course_content_skill();

        $this->assertSame('course.scaffold_course_content', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(CONTEXT_COURSE, $skill->get_required_context_level());
        $this->assertTrue($skill->supports_target_context());
        $this->assertSame(
            ['moodle/course:update', 'moodle/course:manageactivities'],
            $skill->get_required_native_capabilities()
        );

        $structure = $skill->check_structure([]);
        $this->assertFalse($structure['valid']);
    }

    /**
     * G2b: with NO structure parameter in the input, preflight asks the ONE consolidated
     * structure question (deterministic trigger, never lexical).
     */
    public function test_preflight_asks_structure_question_when_no_structure_given(): void {
        $env = $this->setup_course();

        $result = (new scaffold_course_content_skill())->preflight(
            ['topic' => 'Das Leben der Wikinger'],
            $env['contextid'],
            $env['userid']
        )->to_array();

        $this->assertNotSame('pass', $result['status']);
        $this->assertContains('SCAFFOLD_STRUCTURE_REQUIRED', (array)($result['issue_codes'] ?? []));
    }

    /**
     * Any explicit structure parameter suppresses the question — even "just the chapter count".
     */
    public function test_preflight_passes_with_explicit_structure(): void {
        $env = $this->setup_course();

        $dto = (new scaffold_course_content_skill())->preflight(
            ['topic' => 'Das Leben der Wikinger', 'chapters' => 2],
            $env['contextid'],
            $env['userid']
        );

        $this->assertSame('pass', $dto->to_array()['status'], json_encode($dto->issues));
        $this->assertSame(2, (int)$dto->preparedinput['chapters']);
        $this->assertFalse((bool)$dto->preparedinput['practicequizzes']);
        $this->assertFalse((bool)$dto->preparedinput['finalquiz']);
    }

    /**
     * Moodle's auto-created announcements forum is an EXPECTED activity: a fresh course
     * containing only the news forum passes without any override (thread 586: the plain
     * count>0 check blocked the chain on every fresh course).
     */
    public function test_default_news_forum_does_not_block_scaffolding(): void {
        $env = $this->setup_course();
        $this->getDataGenerator()->create_module('forum', ['course' => $env['courseid'], 'type' => 'news']);

        $result = (new scaffold_course_content_skill())->preflight(
            ['topic' => 'Wikinger', 'chapters' => 2],
            $env['contextid'],
            $env['userid']
        )->to_array();

        $this->assertSame('pass', $result['status'], json_encode($result['issue_codes'] ?? []));
    }

    /**
     * A non-empty course soft-blocks once and passes with the override token.
     */
    public function test_non_empty_course_soft_blocks_until_override(): void {
        $env = $this->setup_course();
        $this->getDataGenerator()->create_module('page', ['course' => $env['courseid']]);

        $blocked = (new scaffold_course_content_skill())->preflight(
            ['topic' => 'Wikinger', 'chapters' => 2],
            $env['contextid'],
            $env['userid']
        )->to_array();
        $this->assertNotSame('pass', $blocked['status']);
        $this->assertContains(
            'SCAFFOLD_COURSE_NOT_EMPTY_CONFIRM_REQUIRED',
            (array)($blocked['issue_codes'] ?? [])
        );

        $confirmed = (new scaffold_course_content_skill())->preflight(
            ['topic' => 'Wikinger', 'chapters' => 2, 'override' => ['course_not_empty']],
            $env['contextid'],
            $env['userid']
        )->to_array();
        $this->assertSame('pass', $confirmed['status']);
    }

    /**
     * Execute builds the full anatomy from scripted generation output: named sections,
     * welcome + chapter + summary pages — exactly N+2 pages, nothing more (exact-N doctrine).
     */
    public function test_execute_builds_anatomy_from_scripted_generation(): void {
        $env = $this->setup_course();

        // The responder receives ($actionclass, $prompt) and returns the raw content string;
        // outline vs. chapter calls are told apart by this skill's own deterministic prompt text.
        llm_call_service::set_test_responder(function (string $actionclass, string $prompt): string {
            if (str_contains($prompt, 'drafting the structure')) {
                return json_encode([
                    'welcometitle' => 'Willkommen bei den Wikingern',
                    'welcomehtml' => '<p>Willkommen!</p>',
                    'overviewhtml' => '<h3>Ziele</h3><p>Überblick.</p>',
                    'chapters' => [
                        ['title' => 'Alltag und Gesellschaft'],
                        ['title' => 'Seefahrt und Schiffe'],
                    ],
                    'summarytitle' => 'Zusammenfassung',
                    'summaryhtml' => '<p>Recap.</p>',
                ]);
            }
            return '<h3>Abschnitt</h3><p>' . str_repeat('Inhalt. ', 50) . '</p>';
        });

        $skill = new scaffold_course_content_skill();
        $dto = $skill->preflight(
            ['topic' => 'Das Leben der Wikinger', 'chapters' => 2],
            $env['contextid'],
            $env['userid']
        );
        $this->assertSame('pass', $dto->to_array()['status'], json_encode($dto->issues));

        $result = $skill->execute($dto->preparedinput, $env['contextid'], $env['userid']);

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $this->assertStringNotContainsString('WARNINGS', (string)$result['detail']);

        $course = get_course($env['courseid']);
        $modinfo = get_fast_modinfo($course, $env['userid']);

        $pages = array_filter($modinfo->get_cms(), static fn($cm): bool => $cm->modname === 'page');
        $this->assertCount(4, $pages, 'welcome + 2 chapters + summary = exactly 4 pages');
        $quizzes = array_filter($modinfo->get_cms(), static fn($cm): bool => $cm->modname === 'quiz');
        $this->assertCount(0, $quizzes, 'no quizzes were requested');

        $sections = $modinfo->get_section_info_all();
        $this->assertGreaterThanOrEqual(4, count($sections));
        $this->assertSame('Willkommen bei den Wikingern', (string)$sections[0]->name);
        $this->assertSame('Alltag und Gesellschaft', (string)$sections[1]->name);
        $this->assertSame('Seefahrt und Schiffe', (string)$sections[2]->name);
        $this->assertSame('Zusammenfassung', (string)$sections[3]->name);

        $this->assertSame(2, (int)($result['produced_outputs']['chapters'] ?? 0));
    }

    /**
     * A failed outline call writes NOTHING (outline-first): the course stays empty.
     */
    public function test_failed_outline_writes_nothing(): void {
        $env = $this->setup_course();

        // The scripted seam cannot fail the call itself; an unparseable outline exercises the
        // same nothing-written guarantee (extract_json → null → error before any write).
        llm_call_service::set_test_responder(static function (): string {
            return 'this is not json';
        });

        $skill = new scaffold_course_content_skill();
        $dto = $skill->preflight(
            ['topic' => 'Wikinger', 'chapters' => 2],
            $env['contextid'],
            $env['userid']
        );
        $result = $skill->execute($dto->preparedinput, $env['contextid'], $env['userid']);

        $this->assertSame('error', $result['status']);
        $modinfo = get_fast_modinfo(get_course($env['courseid']), $env['userid']);
        $this->assertCount(0, $modinfo->get_cms(), 'a failed outline must not leave partial content');
    }

    /**
     * The skill is auto-discovered by the registry under its canonical name.
     */
    public function test_registry_discovers_the_skill(): void {
        $this->resetAfterTest();
        $registry = \bookingextension_agent\local\wizard\skill_registry::make_default();
        $this->assertInstanceOf(
            scaffold_course_content_skill::class,
            $registry->get_skill('course.scaffold_course_content')
        );
    }

    /**
     * Create an empty course and return admin-context env.
     *
     * @return array{courseid:int,contextid:int,userid:int}
     */
    private function setup_course(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        return [
            'courseid' => (int)$course->id,
            'contextid' => (int)context_course::instance($course->id)->id,
            'userid' => (int)$USER->id,
        ];
    }
}
