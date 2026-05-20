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
 * AI readiness helper for booking AI instructions.
 *
 * @package     mod_booking
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wbagent;

use context_module;
use context_system;
use core\di;
use core_ai\aiactions\generate_text;
use core_ai\manager as ai_manager;
use mod_booking\singleton_service;

/**
 * Central readiness state for the booking AI panel.
 */
class aiready {
    /** Wunderbyte provider class name. */
    private const WB_PROVIDER_CLASS = 'aiprovider_wunderbyte\\provider';

    /** Legacy trial provider class name used via OpenAI compatibility. */
    private const WB_LEGACY_PROVIDER_CLASS = 'aiprovider_openai\\provider';

    /** Legacy trial provider display name. */
    private const WB_LEGACY_PROVIDER_NAME = 'Wunderbyte';

    /** Wunderbyte planner action class name. */
    private const WB_ACTION_PLANNER_DECIDE = '\\aiprovider_wunderbyte\\aiactions\\planner_decide';

    /** Wunderbyte final reply action class name. */
    private const WB_ACTION_GENERATE_AGENT_REPLY = '\\aiprovider_wunderbyte\\aiactions\\generate_agent_reply';

    /** @var int */
    private int $cmid;

    /** @var int */
    private int $userid;

    /** @var int */
    private int $bookingid;

    /**
     * Constructor.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $bookingid
     */
    public function __construct(int $cmid, int $userid, int $bookingid) {
        $this->cmid = $cmid;
        $this->userid = $userid;
        $this->bookingid = $bookingid;
    }

    /**
     * Export readiness and chat config for mustache/JS.
     *
     * @return array
     */
    public function export_for_template(): array {
        global $CFG;

        $context = context_module::instance($this->cmid);
        $authz = new authorization_service();

        if (!authorization_service::is_agent_extension_installed()) {
            return [
                'readyforchat' => false,
                'isplatformadmin' => has_capability('moodle/site:config', context_system::instance(), $this->userid),
                'threadid' => 0,
                'checks' => [],
                'introtext' => '',
                'admintext' => '',
                'nonadmintext' => '',
                'initialpanelhidden' => true,
                'chatpanelhidden' => true,
                'cmid' => $this->cmid,
            ];
        }

        $isplatformadmin = has_capability('moodle/site:config', context_system::instance(), $this->userid);
        $hascapability = $authz->can_use($this->userid, (int)$context->id);

        $providersconfigured = false;
        $haswunderbyteprovider = false;
        $provideractive = false;
        $courseenabled = false;
        $contextenabled = false;
        $debugmode = !empty(get_config('booking', 'bookingdebugmode'))
            || (isset($CFG->debug) && $CFG->debug >= DEBUG_DEVELOPER);

        $cm = get_coursemodule_from_id('booking', $this->cmid, 0, false, MUST_EXIST);
        $providerconfigurl = (new \moodle_url('/admin/settings.php', ['section' => 'aiprovider']))->out(false);
        $courseconfigurl = (new \moodle_url('/course/edit.php', ['id' => $cm->course]))->out(false);
        $moduleconfigurl = (new \moodle_url('/course/modedit.php', ['update' => $this->cmid, 'return' => 1]))->out(false);
        $capabilityurl = (new \moodle_url('/admin/roles/check.php', [
            'contextid' => $context->id,
            'capability' => 'mod/booking:useaiinstructions',
        ]))->out(false);

        if (class_exists('\\core_ai\\manager')) {
            try {
                $manager = di::get(ai_manager::class);
                $providersconfigured = !empty($manager->get_provider_instances());

                $hasnativewunderbyteprovider = !empty($manager->get_provider_instances([
                    'provider' => self::WB_PROVIDER_CLASS,
                ]));
                $haslegacywunderbyteprovider = !empty($manager->get_provider_instances([
                    'name' => self::WB_LEGACY_PROVIDER_NAME,
                    'provider' => self::WB_LEGACY_PROVIDER_CLASS,
                ]));
                $haswunderbyteprovider = $hasnativewunderbyteprovider || $haslegacywunderbyteprovider;

                $registry = task_registry::make_default();
                $store = new conversation_store();
                $interp = new interpreter($registry);
                $orchestrator = new orchestrator($registry, $interp, $store);
                $runtimeproviderstatus = $orchestrator->get_runtime_provider_status($this->cmid);

                $provideractive = (bool)($runtimeproviderstatus['provideractive'] ?? false);

                if (
                    method_exists($manager, 'is_ai_tools_enabled_in_course') &&
                    method_exists($manager, 'is_action_enabled_in_context')
                ) {
                    $courseenabled = (bool)($runtimeproviderstatus['courseenabled'] ?? false);
                    $contextenabled = (bool)($runtimeproviderstatus['contextenabled'] ?? false);

                    // For wunderbyte custom actions (without placement wiring),
                    // fall back to the module-level AI toggle when provider + course are active.
                    if (
                        !$contextenabled
                        && $haswunderbyteprovider
                        && $provideractive
                        && $courseenabled
                    ) {
                        $contextenabled = $this->is_module_ai_toggle_enabled();
                    }
                } else {
                    // Fallback if method does not exist (e.g. older core version) - assume enabled.
                    $courseenabled = true;
                    $contextenabled = true;
                }
            } catch (\Throwable $e) {
                $providersconfigured = false;
                $haswunderbyteprovider = false;
                $provideractive = false;
                $courseenabled = false;
                $contextenabled = false;
            }
        }

        $readyforchat = $provideractive && $courseenabled && $contextenabled && $hascapability;
        $threadid = 0;

        if ($readyforchat) {
            $store = new conversation_store();
            $thread = $store->get_or_create_thread($this->userid, $this->cmid, $this->bookingid);
            $threadid = (int)$thread->id;
        }

        $checks = [
            $this->build_check(
                $providersconfigured,
                get_string('aiready_check_provider_configured', 'bookingextension_agent'),
                $providersconfigured
                    ? get_string('aiready_check_provider_configured_done', 'bookingextension_agent')
                    : get_string('aiready_check_provider_configured_todo', 'bookingextension_agent'),
                $providerconfigurl
            ),
            $this->build_check(
                $provideractive,
                get_string('aiready_check_provider_active', 'bookingextension_agent'),
                $provideractive
                    ? get_string('aiready_check_provider_active_done', 'bookingextension_agent')
                    : get_string('aiready_check_provider_active_todo', 'bookingextension_agent'),
                $providerconfigurl
            ),
            $this->build_check(
                $courseenabled,
                get_string('aiready_check_course_enabled', 'bookingextension_agent'),
                $courseenabled
                    ? get_string('aiready_check_course_enabled_done', 'bookingextension_agent')
                    : get_string('aiready_check_course_enabled_todo', 'bookingextension_agent'),
                $courseconfigurl
            ),
            $this->build_check(
                $contextenabled,
                get_string('aiready_check_context_enabled', 'bookingextension_agent'),
                $contextenabled
                    ? get_string('aiready_check_context_enabled_done', 'bookingextension_agent')
                    : get_string('aiready_check_context_enabled_todo', 'bookingextension_agent'),
                $moduleconfigurl
            ),
            $this->build_check(
                $hascapability,
                get_string('aiready_check_capability', 'bookingextension_agent'),
                $hascapability
                    ? get_string('aiready_check_capability_done', 'bookingextension_agent')
                    : get_string('aiready_check_capability_todo', 'bookingextension_agent'),
                $capabilityurl
            ),
        ];

        $introtext = get_string('aiready_intro_text', 'bookingextension_agent');

        $admintext = '';
        $nonadmintext = '';

        if (!$readyforchat) {
            if ($isplatformadmin) {
                $admintext = $haswunderbyteprovider
                    ? ''
                    : get_string('aiready_admin_text', 'bookingextension_agent');
            } else {
                $nonadmintext = get_string('aiready_nonadmin_text', 'bookingextension_agent');
            }
        }

        $activationquestiontext = $haswunderbyteprovider
            ? get_string('aitrial_activation_question_existing_provider', 'bookingextension_agent')
            : get_string('aitrial_activation_question', 'bookingextension_agent');

        $stats = $this->get_booking_statistics();
        $welcometext = ($stats['num_options'] === 0)
            ? get_string('ai_welcome_empty', 'bookingextension_agent')
            : get_string('ai_welcome_with_options', 'bookingextension_agent', (object) [
                'numoptions' => $stats['num_options'],
                'numbooked' => $stats['num_booked'],
            ]);

        return [
            'cmid' => $this->cmid,
            'threadid' => $threadid,
            'sesskey' => sesskey(),
            'wwwroot' => $CFG->wwwroot,
            'ready_for_chat' => $readyforchat,
            'provider_available' => $provideractive,
            'is_platform_admin' => $isplatformadmin,
            'has_use_capability' => $hascapability,
            'show_trial_button' => $isplatformadmin && !$readyforchat && !$haswunderbyteprovider,
            'show_trial_activate_button' => $isplatformadmin && !$readyforchat && $haswunderbyteprovider,
            'activation_question_text' => $activationquestiontext,
            'intro_text' => $introtext,
            'admin_text' => $admintext,
            'nonadmin_text' => $nonadmintext,
            'readiness_checks' => $checks,
            'num_options' => $stats['num_options'],
            'num_booked' => $stats['num_booked'],
            'welcome_text' => $welcometext,
            'debug_mode' => $debugmode,
        ];
    }

    /**
     * Build a single readiness check row.
     *
     * @param bool $done
     * @param string $label
     * @param string $detail
     * @param string|null $configureurl
     * @return array
     */
    private function build_check(bool $done, string $label, string $detail, ?string $configureurl = null): array {
        return [
            'done' => $done,
            'label' => $label,
            'detail' => $detail,
            'configureurl' => $configureurl,
            'configurelabel' => get_string('aiready_configure_here', 'bookingextension_agent'),
            'icon' => $done
                ? '<i class="fa fa-check-square text-success" aria-hidden="true"></i>'
                : '<i class="fa fa-square-o text-muted" aria-hidden="true"></i>',
        ];
    }

    /**
     * Check whether the module-level AI toggle is enabled.
     *
     * @return bool
     */
    private function is_module_ai_toggle_enabled(): bool {
        try {
            $fields = ai_manager::get_ai_fields_from_course_module($this->cmid);
            return is_null($fields->enableaitools) || (bool)$fields->enableaitools;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get booking statistics using singletons and cached objects.
     *
     * @return array with 'num_options' and 'num_booked' keys
     */
    private function get_booking_statistics(): array {
        $numoptions = 0;
        $numbooked = 0;

        try {
            // Get booking instance via singleton.
            $bookinginstance = singleton_service::get_instance_of_booking_by_bookingid($this->bookingid);
            if (!$bookinginstance) {
                return [
                    'num_options' => 0,
                    'num_booked' => 0,
                ];
            }

            $numoptions = $bookinginstance->get_all_options_count();

            // Get all option IDs for this booking.
            $optionids = $bookinginstance->get_all_options(0, 0);

            // Count booked persons by iterating through all options.
            foreach ($optionids as $option) {
                $optionid = $option->id;
                // Get option settings via singleton.
                $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
                // Get booking answers via singleton to count booked persons.
                $answers = singleton_service::get_instance_of_booking_answers($optionsettings);
                // Count booked persons.
                $bookedusers = $answers->get_usersonlist();
                $numbooked += count($bookedusers);
            }
        } catch (\Exception $e) {
            // If something goes wrong, return zeros.
            $numoptions = 0;
            $numbooked = 0;
        }

        return [
            'num_options' => $numoptions,
            'num_booked' => $numbooked,
        ];
    }
}
