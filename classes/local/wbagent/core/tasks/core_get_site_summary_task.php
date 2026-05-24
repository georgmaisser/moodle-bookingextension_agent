<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace bookingextension_agent\local\wbagent\core\tasks;

require_once(__DIR__ . '/../../interfaces/task_result_summary_provider_interface.php');

use bookingextension_agent\local\wbagent\interfaces\task_result_summary_provider_interface;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

class core_get_site_summary_task extends core_task_base implements task_result_summary_provider_interface, task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_get_site_summary';

    public function __construct() {
        parent::__construct(true);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Get read-only Moodle site summary without direct SQL.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function check_structure(array $input): array {
        return ['valid' => true, 'errors' => [], 'ambiguities' => []];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        global $CFG;

        $lang = $this->get_output_language($input);
        $site = get_site();
        $timezone = !empty($CFG->timezone) ? (string)$CFG->timezone : (string)get_config('core', 'timezone');
        $activelang = current_language();
        $defaultlang = (string)(get_config('core', 'lang') ?? ($CFG->lang ?? ''));

        return [
            'status' => 'executed',
            'detail' => $this->localized_string('agent_booking_core_site_summary_loaded', null, $lang),
            'resultid' => (int)$site->id,
            'site' => [
                'id' => (int)$site->id,
                'fullname' => format_string($site->fullname),
                'shortname' => format_string($site->shortname),
                'lang' => $activelang,
                'default_lang' => $defaultlang,
                'timezone' => $timezone,
                'release' => (string)($CFG->release ?? ''),
                'wwwroot' => (string)$CFG->wwwroot,
            ],
            'observation_full' => $this->build_site_observation_full([
                'id' => (int)$site->id,
                'fullname' => format_string($site->fullname),
                'shortname' => format_string($site->shortname),
                'lang' => $activelang,
                'default_lang' => $defaultlang,
                'timezone' => $timezone,
                'release' => (string)($CFG->release ?? ''),
                'wwwroot' => (string)$CFG->wwwroot,
            ]),
        ];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_get_site_summary_request',
            'description' => 'User asks for Moodle site summary information.',
            'examples' => ['Show site summary', 'Zeige Website-Zusammenfassung', 'What Moodle version and timezone is this site using?'],
        ]];
    }

    /**
     * Build deterministic summary for observer/state/client fallback modes.
     *
     * @param array $result
     * @param array $context
     * @return string
     */
    public function summarize_task_result(array $result, array $context = []): string {
        $site = (array)($result['site'] ?? []);
        if (empty($site)) {
            return '';
        }

        $fullname = trim((string)($site['fullname'] ?? ''));
        $shortname = trim((string)($site['shortname'] ?? ''));
        $lang = trim((string)($site['lang'] ?? ''));
        $defaultlang = trim((string)($site['default_lang'] ?? ''));
        $timezone = trim((string)($site['timezone'] ?? ''));
        $release = trim((string)($site['release'] ?? ''));

        $parts = [];
        if ($fullname !== '') {
            $parts[] = 'site name "' . $fullname . '"';
        }
        if ($shortname !== '') {
            $parts[] = 'short name "' . $shortname . '"';
        }
        if ($lang !== '') {
            $parts[] = 'active language code "' . $lang . '"';
        }
        if ($defaultlang !== '') {
            $parts[] = 'default language code "' . $defaultlang . '"';
        }
        if ($timezone !== '') {
            $parts[] = 'timezone "' . $timezone . '"';
        }
        if ($release !== '') {
            $parts[] = 'release "' . $release . '"';
        }

        if (empty($parts)) {
            return 'Loaded Moodle site snapshot.';
        }

        return 'Loaded Moodle site snapshot with ' . implode(', ', $parts) . '.';
    }

    /**
     * Build stable, detailed observation content for the loop context.
     *
     * @param array $site
     * @return string
     */
    private function build_site_observation_full(array $site): string {
        $id = trim((string)($site['id'] ?? ''));
        $fullname = trim((string)($site['fullname'] ?? ''));
        $shortname = trim((string)($site['shortname'] ?? ''));
        $language = trim((string)($site['lang'] ?? ''));
        $defaultlang = trim((string)($site['default_lang'] ?? ''));
        $timezone = trim((string)($site['timezone'] ?? ''));
        $release = trim((string)($site['release'] ?? ''));
        $wwwroot = trim((string)($site['wwwroot'] ?? ''));

        $lines = ['Moodle site snapshot (authoritative environment facts):'];
        if ($fullname !== '') {
            $lines[] = '- Site name: ' . $fullname;
        }
        if ($shortname !== '') {
            $lines[] = '- Site short name: ' . $shortname;
        }
        if ($language !== '') {
            $lines[] = '- Active language code: ' . $language;
        }
        if ($defaultlang !== '') {
            $lines[] = '- Default language code: ' . $defaultlang;
        }
        if ($timezone !== '') {
            $lines[] = '- Site timezone: ' . $timezone;
        }
        if ($release !== '') {
            $lines[] = '- Moodle release: ' . $release;
        }
        if ($wwwroot !== '') {
            $lines[] = '- Base URL: ' . $wwwroot;
        }
        if ($id !== '') {
            $lines[] = '- Site id: ' . $id;
        }

        if (count($lines) === 1) {
            return 'Moodle site snapshot loaded.';
        }

        return implode("\n", $lines);
    }
}
