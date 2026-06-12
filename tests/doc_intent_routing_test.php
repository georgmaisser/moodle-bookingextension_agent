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
use ReflectionMethod;
use stdClass;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\wbagent\skills\explain_docs_skill;

/**
 * Documentation-intent routing: wbagent.explain_docs is forced into the candidate catalog for
 * doc-intent questions so the selector can pick it over domain skills (thread-209: "explain the
 * booking rules" was routed to analyze_rules because explain_docs never reached the candidate set).
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers \bookingextension_agent\local\wbagent\orchestrator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class doc_intent_routing_test extends advanced_testcase {
    /**
     * Build a user message object.
     *
     * @param string $content
     * @return stdClass
     */
    private function usermsg(string $content): stdClass {
        $m = new stdClass();
        $m->role = 'user';
        $m->content = $content;
        return $m;
    }

    /**
     * Invoke a private orchestrator method without booting its constructor dependencies.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private function call_private(string $method, array $args) {
        $orchestrator = (new \ReflectionClass(orchestrator::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionMethod(orchestrator::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($orchestrator, $args);
    }

    /**
     * Documentation phrasings (the two thread-209 questions + variants) are recognised; a plain
     * mutation request is not.
     */
    public function test_documentation_intent_detection(): void {
        $this->resetAfterTest(true);

        $docqueries = [
            'was kannst du mir zu den buchungsregeln erklären?',
            'finde mehr informationen zu den buchungsbestätigungen',
            'wie funktioniert die Warteliste?',
            'explain how booking rules work',
            'what is a booking option?',
        ];
        foreach ($docqueries as $q) {
            $this->assertTrue(
                (bool)$this->call_private('looks_like_documentation_intent', [$q]),
                'Expected documentation intent for: ' . $q
            );
        }

        $nondoc = [
            'setze maxanswers auf 20',
            'buche Anna in die Yoga-Option',
            'erstelle eine neue Buchungsoption',
        ];
        foreach ($nondoc as $q) {
            $this->assertFalse(
                (bool)$this->call_private('looks_like_documentation_intent', [$q]),
                'Did not expect documentation intent for: ' . $q
            );
        }
    }

    /**
     * For a doc-intent question, the doc skill is appended to a candidate catalog that lacks it.
     */
    public function test_doc_skill_forced_in_for_doc_intent(): void {
        $this->resetAfterTest(true);

        $runtimecatalog = [
            ['skill' => 'mod_booking.analyze_rules', 'description' => 'Analyze configured rules.'],
        ];
        $allcontracts = array_merge($runtimecatalog, [
            ['skill' => explain_docs_skill::SKILL_NAME, 'description' => 'Search the plugin documentation.'],
        ]);
        $messages = [$this->usermsg('was kannst du mir zu den buchungsregeln erklären?')];

        $result = $this->call_private('ensure_doc_skill_for_doc_intent', [$runtimecatalog, $allcontracts, $messages]);

        $skills = array_map(static fn(array $r): string => (string)$r['skill'], $result);
        $this->assertContains(explain_docs_skill::SKILL_NAME, $skills, 'Doc skill must be forced in for a doc question.');
        $this->assertContains('mod_booking.analyze_rules', $skills, 'Existing candidates must be preserved.');
    }

    /**
     * A non-documentation request leaves the candidate catalog untouched (no noise).
     */
    public function test_doc_skill_not_added_for_non_doc_intent(): void {
        $this->resetAfterTest(true);

        $runtimecatalog = [
            ['skill' => 'mod_booking.update_option', 'description' => 'Update an option.'],
        ];
        $allcontracts = array_merge($runtimecatalog, [
            ['skill' => explain_docs_skill::SKILL_NAME, 'description' => 'Search the plugin documentation.'],
        ]);
        $messages = [$this->usermsg('setze maxanswers auf 20')];

        $result = $this->call_private('ensure_doc_skill_for_doc_intent', [$runtimecatalog, $allcontracts, $messages]);

        $skills = array_map(static fn(array $r): string => (string)$r['skill'], $result);
        $this->assertNotContains(explain_docs_skill::SKILL_NAME, $skills);
        $this->assertCount(1, $result);
    }

    /**
     * When the doc skill is already a candidate, the catalog is returned unchanged (no duplicate).
     */
    public function test_doc_skill_not_duplicated_when_present(): void {
        $this->resetAfterTest(true);

        $runtimecatalog = [
            ['skill' => explain_docs_skill::SKILL_NAME, 'description' => 'Search the plugin documentation.'],
            ['skill' => 'mod_booking.analyze_rules', 'description' => 'Analyze configured rules.'],
        ];
        $messages = [$this->usermsg('erkläre mir die buchungsregeln')];

        $result = $this->call_private('ensure_doc_skill_for_doc_intent', [$runtimecatalog, $runtimecatalog, $messages]);

        $occurrences = array_filter(
            $result,
            static fn(array $r): bool => (string)$r['skill'] === explain_docs_skill::SKILL_NAME
        );
        $this->assertCount(1, $occurrences, 'Doc skill must not be duplicated.');
    }
}
