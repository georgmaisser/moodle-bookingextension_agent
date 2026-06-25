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
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\wizard\skills\explain_docs_skill;

/**
 * Documentation-intent routing: wizard.explain_docs is forced into the candidate catalog for
 * doc-intent questions so the selector can pick it over domain skills (thread-209: "explain the
 * booking rules" was routed to analyze_rules because explain_docs never reached the candidate set).
 *
 * After the LG_AGN cleanup this is driven entirely by the skill's declared governance
 * (mandatory_on_trigger + intent_triggers) and a generic engine injector — no skill names or
 * language keywords live in the orchestrator. These tests therefore drive the REAL skill's declared
 * triggers through the generic injector, so they still pin the exact doc-intent behaviour.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers \bookingextension_agent\local\wizard\orchestrator
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
        // The catalog/trigger methods now live in planner_catalog_service (orchestrator split).
        $svc = new \bookingextension_agent\local\wizard\services\planner_catalog_service(
            new \bookingextension_agent\local\wizard\services\assistant_state_guidance_service(
                new \bookingextension_agent\local\wizard\skill_registry()
            )
        );
        return $svc->$method(...$args);
    }

    /**
     * The explain_docs contract as it reaches the orchestrator — with the skill's REAL declared
     * governance (mandatory_on_trigger + intent_triggers), so the test exercises the actual markers.
     *
     * @return array<string,mixed>
     */
    private function doc_contract(): array {
        $governance = (array)((new explain_docs_skill())->get_schema()['governance'] ?? []);
        return [
            'skill' => explain_docs_skill::SKILL_NAME,
            'description' => 'Search the plugin documentation.',
            'mandatory_on_trigger' => (bool)($governance['mandatory_on_trigger'] ?? false),
            'intent_triggers' => (array)($governance['intent_triggers'] ?? []),
        ];
    }

    /**
     * Run the generic injector with a domain candidate + the doc contract for the given user text.
     *
     * @param string $usertext
     * @param array<int,array<string,mixed>> $runtimecatalog
     * @return string[] resulting candidate skill names
     */
    private function inject_for(string $usertext, array $runtimecatalog): array {
        $allcontracts = array_merge($runtimecatalog, [$this->doc_contract()]);
        $result = $this->call_private(
            'ensure_trigger_mandatory_skills',
            [$runtimecatalog, $allcontracts, [$this->usermsg($usertext)]]
        );
        return array_map(static fn(array $r): string => (string)$r['skill'], $result);
    }

    /**
     * The skill declares the mandatory_on_trigger flag and the doc-intent markers — the wiring the
     * generic injector depends on.
     */
    public function test_skill_declares_governance_markers(): void {
        $governance = (array)((new explain_docs_skill())->get_schema()['governance'] ?? []);
        $this->assertTrue((bool)($governance['mandatory_on_trigger'] ?? false));
        $triggers = (array)($governance['intent_triggers'] ?? []);
        foreach (['explain', 'was ist', 'erklär', 'documentation', 'wie funktion'] as $marker) {
            $this->assertContains($marker, $triggers, "Doc skill must declare the '{$marker}' intent trigger.");
        }
    }

    /**
     * Documentation phrasings (the thread-209 questions + variants) inject the doc skill; plain
     * mutation requests do not — pinned through the real markers + generic injector.
     */
    public function test_doc_intent_phrasings_inject_doc_skill(): void {
        $this->resetAfterTest(true);
        $domain = [['skill' => 'mod_booking.analyze_rules', 'description' => 'Analyze configured rules.']];

        $docqueries = [
            'was kannst du mir zu den buchungsregeln erklären?',
            'finde mehr informationen zu den buchungsbestätigungen',
            'wie funktioniert die Warteliste?',
            'explain how booking rules work',
            'what is a booking option?',
        ];
        foreach ($docqueries as $q) {
            $skills = $this->inject_for($q, $domain);
            $this->assertContains(explain_docs_skill::SKILL_NAME, $skills, "Doc skill must be offered for: {$q}");
            $this->assertContains('mod_booking.analyze_rules', $skills, 'Existing candidates must be preserved.');
        }

        $nondoc = [
            'setze maxanswers auf 20',
            'buche Anna in die Yoga-Option',
            'erstelle eine neue Buchungsoption',
        ];
        foreach ($nondoc as $q) {
            $skills = $this->inject_for($q, $domain);
            $this->assertNotContains(explain_docs_skill::SKILL_NAME, $skills, "Doc skill must NOT be offered for: {$q}");
        }
    }

    /**
     * A non-documentation request leaves the candidate catalog untouched (no noise).
     */
    public function test_no_injection_for_non_doc_intent(): void {
        $this->resetAfterTest(true);
        $runtimecatalog = [['skill' => 'mod_booking.update_option', 'description' => 'Update an option.']];

        $result = $this->call_private(
            'ensure_trigger_mandatory_skills',
            [$runtimecatalog, array_merge($runtimecatalog, [$this->doc_contract()]), [$this->usermsg('setze maxanswers auf 20')]]
        );

        $this->assertCount(1, $result);
        $this->assertSame('mod_booking.update_option', (string)$result[0]['skill']);
    }

    /**
     * When the doc skill is already a candidate, it is not duplicated.
     */
    public function test_not_duplicated_when_present(): void {
        $this->resetAfterTest(true);
        $runtimecatalog = [
            $this->doc_contract(),
            ['skill' => 'mod_booking.analyze_rules', 'description' => 'Analyze configured rules.'],
        ];

        $result = $this->call_private(
            'ensure_trigger_mandatory_skills',
            [$runtimecatalog, $runtimecatalog, [$this->usermsg('erkläre mir die buchungsregeln')]]
        );

        $occurrences = array_filter(
            $result,
            static fn(array $r): bool => (string)$r['skill'] === explain_docs_skill::SKILL_NAME
        );
        $this->assertCount(1, $occurrences, 'Doc skill must not be duplicated.');
    }
}
