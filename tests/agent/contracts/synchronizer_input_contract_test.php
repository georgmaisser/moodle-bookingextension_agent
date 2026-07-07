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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\tests;

use bookingextension_agent\local\wizard\services\synchronizer_input_builder;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for synchronizer input shaping.
 *
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_input_builder
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class synchronizer_input_contract_test extends TestCase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * PHASE_TRACE should keep only minimal telemetry and exclude discovery payloads.
     */
    public function test_phase_trace_excludes_skill_discovery_payload(): void {
        $builder = new synchronizer_input_builder();

        $observations = $builder->build_observations([
            'phase_trace' => [
                'discovery' => [
                    'phase' => 'discovery',
                    'response_type' => 'ok',
                    'issue_codes' => ['a', 'B'],
                    'errors' => ['x'],
                    'selected_families' => ['mod_booking.options'],
                    'ranked_families' => [['family' => 'mod_booking.options', 'score' => 0.9]],
                    'catalogselectionmode' => 'embed_topk',
                    'embeddingstatus' => 'applied',
                ],
                'selection' => [
                    'phase' => 'selection',
                    'response_type' => 'clarification',
                    'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
                    'errors' => [],
                    'runtimecatalog' => [['skill' => 'mod_booking.create_option']],
                ],
                'parameter_construction' => [
                    'phase' => 'parameter_construction',
                    'response_type' => 'skill_call',
                    'issue_codes' => [],
                    'errors' => [],
                    'commands' => [['skill' => 'mod_booking.create_option']],
                ],
            ],
        ]);

        $phasetraceobs = '';
        foreach ($observations as $observation) {
            if (is_string($observation) && str_starts_with($observation, 'PHASE_TRACE' . "\n")) {
                $phasetraceobs = $observation;
                break;
            }
        }

        $this->assertNotSame('', $phasetraceobs);
        $json = substr($phasetraceobs, strlen('PHASE_TRACE' . "\n"));
        $payload = json_decode($json, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('discovery', $payload);
        $this->assertArrayHasKey('selection', $payload);
        $this->assertArrayHasKey('parameter_construction', $payload);

        $this->assertSame(['A', 'B'], $payload['discovery']['issue_codes']);
        $this->assertArrayNotHasKey('selected_families', $payload['discovery']);
        $this->assertArrayNotHasKey('ranked_families', $payload['discovery']);
        $this->assertArrayNotHasKey('runtimecatalog', $payload['selection']);
        $this->assertArrayNotHasKey('commands', $payload['parameter_construction']);
    }
}
