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
 * Tests for the third-party skill template generator and the agent.scaffold_skill skill.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\wizard\skills\scaffold_skill;
use bookingextension_agent\local\wizard\skill_contract_validator;
use bookingextension_agent\local\wizard\services\scaffold\skill_template_generator;

/**
 * Tests for the skill template generator and the scaffold skill.
 *
 * @covers \bookingextension_agent\local\wizard\services\scaffold\skill_template_generator
 * @covers \bookingextension_agent\local\wizard\agent\skills\scaffold_skill
 */
final class scaffold_skill_template_test extends \advanced_testcase {
    /**
     * The generated skill file is loadable, instantiable and contract-valid.
     */
    public function test_generator_produces_loadable_contract_valid_skill(): void {
        $this->resetAfterTest();

        $component = 'mod/scaffolddemo';
        $bundle = skill_template_generator::generate([
            'component' => $component,
            'description' => 'Archive an item when the teacher asks for it.',
            'skillname' => 'scaffolddemo.archive_item',
            'risk_class' => 'broad_write',
            'properties' => [
                ['name' => 'itemquery', 'type' => 'string', 'description' => 'Which item.', 'required' => true],
            ],
            'context_scopes' => ['module', 'course'],
            'capabilities' => ['moodle/course:manageactivities'],
        ]);

        // Bundle shape.
        $this->assertSame('scaffolddemo.archive_item', $bundle['skillname']);
        $this->assertSame('mod/scaffolddemo:skill_scaffolddemo_archive_item', $bundle['capability']);
        // The ZIP is named after the actual skill.
        $this->assertSame('scaffolddemo.archive_item.zip', $bundle['zip_filename']);
        $this->assertArrayHasKey('README.md', $bundle['files']);
        $this->assertArrayHasKey(
            'classes/local/wizard/scaffolddemo/skills/archive_item_skill.php',
            $bundle['files']
        );

        // The ZIP decodes and contains the skill file.
        $tmp = make_request_directory() . '/bundle.zip';
        file_put_contents($tmp, base64_decode($bundle['zip_base64']));
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $this->assertNotFalse($zip->locateName('classes/local/wizard/scaffolddemo/skills/archive_item_skill.php'));
        $zip->close();

        // The generated PHP loads, instantiates and passes the runtime skill contract validator.
        $skillsource = $bundle['files']['classes/local/wizard/scaffolddemo/skills/archive_item_skill.php'];
        // Method docblocks must carry correct @param/@return tags, not just prose.
        $this->assertStringContainsString('@param array $input', $skillsource);
        $this->assertStringContainsString('@return array{status:string,prepared_input:array,issues:array}', $skillsource);

        // A mutating (broad_write) skill carries the full mutating surface: a confirm-queue identity
        // and the tier-3 pre-confirmation preview hook.
        $this->assertStringContainsString(
            'implements skill_trigger_provider_interface, queue_identity_provider_interface',
            $skillsource
        );
        $this->assertStringContainsString('function run_preflight(', $skillsource);
        $this->assertStringContainsString('public function describe_proposed_action(', $skillsource);
        $this->assertStringContainsString('public function build_queue_business_identity(', $skillsource);

        $skillfile = make_request_directory() . '/archive_item_skill.php';
        file_put_contents($skillfile, $skillsource);
        $this->load_bundle_engine_layer($bundle);
        require($skillfile);

        $fqcn = 'mod_scaffolddemo\\local\\wizard\\scaffolddemo\\skills\\archive_item_skill';
        $this->assertTrue(class_exists($fqcn), 'Generated skill class must be loadable');

        $skill = new $fqcn();
        $this->assertSame('scaffolddemo.archive_item', $skill->get_name());
        $this->assertFalse($skill->is_read_only());

        $meta = skill_contract_validator::build_skill_metadata($skill, $component);
        $validation = skill_contract_validator::validate_skill_metadata($meta);
        $this->assertTrue($validation['valid'], 'Generated skill must be contract-valid: '
            . implode('; ', $validation['errors'] ?? []));
    }

    /**
     * An unfinished generated skill stays inert: it reports it is not implemented and renders the
     * "under construction" preview instead of faking success.
     */
    public function test_generated_skill_reports_not_implemented_with_construction_preview(): void {
        $this->resetAfterTest();

        $bundle = skill_template_generator::generate([
            'component' => 'mod/scaffolddemo',
            'description' => 'Peek at an item.',
            'skillname' => 'scaffolddemo.peek_item',
            'risk_class' => 'read_only',
        ]);

        $relative = 'classes/local/wizard/scaffolddemo/skills/peek_item_skill.php';

        // A read-only (R0) skill stays lean: no confirm-queue identity, no preflight and no
        // pre-confirmation preview hook (it auto-executes without confirmation).
        $readonlysource = $bundle['files'][$relative];
        $this->assertStringContainsString('implements skill_trigger_provider_interface {', $readonlysource);
        $this->assertStringNotContainsString('queue_identity_provider_interface', $readonlysource);
        $this->assertStringNotContainsString('function describe_proposed_action(', $readonlysource);
        $this->assertStringNotContainsString('function build_queue_business_identity(', $readonlysource);
        $this->assertStringNotContainsString('function run_preflight(', $readonlysource);

        $skillfile = make_request_directory() . '/peek_item_skill.php';
        file_put_contents($skillfile, $bundle['files'][$relative]);
        $this->load_bundle_engine_layer($bundle);
        require($skillfile);

        $fqcn = 'mod_scaffolddemo\\local\\wizard\\scaffolddemo\\skills\\peek_item_skill';
        $skill = new $fqcn();

        $result = $skill->execute([], \context_system::instance()->id, 0);
        $this->assertSame(skill_template_generator::NOT_IMPLEMENTED_MESSAGE, $result['detail']);

        $preview = $skill->get_result_preview(['anything' => 1], \context_system::instance()->id, 0);
        $this->assertIsArray($preview);
        $this->assertStringContainsString('fa-person-digging', $preview['html']);
        $this->assertStringContainsString(skill_template_generator::NOT_IMPLEMENTED_MESSAGE, $preview['html']);
    }

    /**
     * The scaffold skill produces a downloadable ZIP and a download preview.
     */
    public function test_scaffold_skill_offers_download_preview(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $skill = new scaffold_skill();
        $this->assertSame('wizard.scaffold_skill', $skill->get_name());
        $this->assertTrue($skill->is_read_only());

        $result = $skill->execute(
            ['component' => 'mod/foo', 'description' => 'Archive an item when asked.'],
            (int)\context_system::instance()->id,
            0
        );

        $this->assertSame('executed', $result['status']);
        $this->assertNotEmpty($result['scaffold_zip_base64']);
        // The base64 ZIP must not leak into the LLM-bound observation.
        $this->assertStringNotContainsString($result['scaffold_zip_base64'], (string)$result['observation_full']);

        $preview = $skill->get_result_preview($result, (int)\context_system::instance()->id, 0);
        $this->assertIsArray($preview);
        $this->assertStringContainsString('data:application/zip;base64,', $preview['html']);
        $this->assertStringContainsString('download="', $preview['html']);
    }

    /**
     * When the namespace derived from the component is reserved (booking/core/wizard), the
     * generator falls back to the full component name instead of rejecting it.
     *
     * The example component must never coincide with an engine component in either engine
     * (the engine itself MAY register reserved namespaces, which would skip the fallback):
     * "local/core" derives the reserved "core" and is not a real plugin anywhere.
     */
    public function test_generator_falls_back_for_reserved_namespace(): void {
        $this->resetAfterTest();

        $bundle = skill_template_generator::generate([
            'component' => 'local/core',
            'description' => 'Do a custom thing.',
            // No skillname: derived namespace would be the reserved "core" -> falls back.
        ]);

        $this->assertStringStartsWith('local_core.', $bundle['skillname']);
        $this->assertSame($bundle['skillname'] . '.zip', $bundle['zip_filename']);
    }

    /**
     * A broad-write skill without context scopes is rejected (mirrors the runtime contract rule).
     */
    public function test_generator_rejects_broad_write_without_context_scope(): void {
        $this->resetAfterTest();

        $this->expectException(\invalid_parameter_exception::class);
        skill_template_generator::generate([
            'component' => 'mod/scaffolddemo',
            'description' => 'Do a broad thing.',
            'skillname' => 'scaffolddemo.broad_thing',
            'risk_class' => 'broad_write',
            // No context_scopes -> must throw.
        ]);
    }

    /**
     * Require the bundle's engine alias layer so the generated skill class can load.
     *
     * The fake mod_scaffolddemo component has no autoloader, and both loadability tests
     * share one PHP process, so every engine file is required at most once.
     *
     * @param array $bundle generator result with the files map
     */
    private function load_bundle_engine_layer(array $bundle): void {
        $enginefiles = array_filter(
            $bundle['files'],
            fn ($path) => str_starts_with($path, 'classes/local/wizard/engine/'),
            ARRAY_FILTER_USE_KEY
        );
        // The resolver first: alias files call it while being required.
        uksort($enginefiles, fn ($a, $b) => strcmp(
            str_contains($a, 'engine_resolver') ? '0' : $a,
            str_contains($b, 'engine_resolver') ? '0' : $b
        ));

        $dir = make_request_directory();
        foreach ($enginefiles as $path => $content) {
            $fqcn = 'mod_scaffolddemo\\local\\wizard\\engine\\' . basename($path, '.php');
            if (
                class_exists($fqcn, false) || interface_exists($fqcn, false)
                || trait_exists($fqcn, false) || enum_exists($fqcn, false)
            ) {
                continue;
            }
            $file = $dir . '/' . basename($path);
            file_put_contents($file, $content);
            require($file);
        }
    }
}
