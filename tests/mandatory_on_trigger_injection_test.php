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
use bookingextension_agent\local\wbagent\skill_contract_validator;
use bookingextension_agent\local\wbagent\wbagent\skills\list_skills_skill;

/**
 * The generic, skill-agnostic intent-trigger injection (S5a): the engine carries no skill names and
 * no language keywords — it injects ANY skill that declares governance mandatory_on_trigger when one
 * of that skill's declared intent_triggers matches the user message, and ONLY such skills.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers \bookingextension_agent\local\wbagent\orchestrator
 * @covers \bookingextension_agent\local\wbagent\skill_contract_validator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mandatory_on_trigger_injection_test extends advanced_testcase {
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
     * Invoke ensure_trigger_mandatory_skills without booting the orchestrator constructor.
     *
     * @param array<int,array<string,mixed>> $runtimecatalog
     * @param array<int,array<string,mixed>> $allcontracts
     * @param string $usertext
     * @return string[] resulting candidate skill names
     */
    private function inject(array $runtimecatalog, array $allcontracts, string $usertext): array {
        $orchestrator = (new \ReflectionClass(orchestrator::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionMethod(orchestrator::class, 'ensure_trigger_mandatory_skills');
        $ref->setAccessible(true);
        $result = $ref->invokeArgs($orchestrator, [$runtimecatalog, $allcontracts, [$this->usermsg($usertext)]]);
        return array_map(static fn(array $r): string => (string)$r['skill'], $result);
    }

    /**
     * The flag gates injection: a skill WITHOUT mandatory_on_trigger is never injected, even when its
     * own trigger phrase appears verbatim in the message; a skill WITH the flag is injected on match.
     */
    public function test_flag_gates_injection(): void {
        $this->resetAfterTest(true);

        $meta = [
            'skill' => 'test.meta', 'description' => 'meta',
            'mandatory_on_trigger' => true, 'intent_triggers' => ['frobnicate the widget'],
        ];
        // Same trigger phrase, but no flag -> must never be force-injected.
        $plain = [
            'skill' => 'test.plain', 'description' => 'plain',
            'mandatory_on_trigger' => false, 'intent_triggers' => ['frobnicate the widget'],
        ];
        $contracts = [$meta, $plain];

        $matched = $this->inject([], $contracts, 'please FROBNICATE the widget now');
        $this->assertContains('test.meta', $matched, 'Flagged skill is injected on trigger match.');
        $this->assertNotContains('test.plain', $matched, 'Unflagged skill must never be force-injected.');

        $nomatch = $this->inject([], $contracts, 'something entirely unrelated');
        $this->assertSame([], $nomatch, 'No flagged trigger matches -> nothing injected.');
    }

    /**
     * Already-present skills are not duplicated; matching is case-insensitive substring.
     */
    public function test_no_duplicate_and_case_insensitive(): void {
        $this->resetAfterTest(true);

        $meta = [
            'skill' => 'test.meta', 'description' => 'meta',
            'mandatory_on_trigger' => true, 'intent_triggers' => ['HELP me'],
        ];
        $existing = [['skill' => 'test.meta', 'description' => 'already here']];

        $result = $this->inject($existing, [$meta], 'can you help me please');
        $occurrences = array_filter($result, static fn(string $s): bool => $s === 'test.meta');
        $this->assertCount(1, $occurrences, 'Present skill must not be duplicated.');
    }

    /**
     * Capability questions inject the real wbagent.list_skills (its declared markers), regular
     * mutation requests do not — through the same generic injector.
     */
    public function test_real_list_skills_capability_routing(): void {
        $this->resetAfterTest(true);

        $governance = (array)((new list_skills_skill())->get_schema()['governance'] ?? []);
        $listcontract = [
            'skill' => list_skills_skill::SKILL_NAME, 'description' => 'List capabilities.',
            'mandatory_on_trigger' => (bool)($governance['mandatory_on_trigger'] ?? false),
            'intent_triggers' => (array)($governance['intent_triggers'] ?? []),
        ];
        $domain = [['skill' => 'mod_booking.create_option', 'description' => 'Create an option.']];
        $contracts = array_merge($domain, [$listcontract]);

        $cap = $this->inject($domain, $contracts, 'was kannst du hier eigentlich alles?');
        $this->assertContains(list_skills_skill::SKILL_NAME, $cap, 'Capability question must offer list_skills.');

        $mutate = $this->inject($domain, $contracts, 'erstelle eine neue Buchungsoption');
        $this->assertNotContains(list_skills_skill::SKILL_NAME, $mutate);
    }

    /**
     * The contract validator extracts the governance flag + triggers from the real skill schema, so
     * they actually reach the prompt contract the engine reads.
     */
    public function test_validator_extracts_governance_from_real_skill(): void {
        $this->resetAfterTest(true);

        $metadata = skill_contract_validator::build_skill_metadata(new list_skills_skill(), 'bookingextension_agent');
        $this->assertTrue((bool)($metadata['mandatory_on_trigger'] ?? false));
        $this->assertContains('what can you do', (array)($metadata['intent_triggers'] ?? []));
    }
}
