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

namespace bookingextension_agent\local\wbagent\core\tasks;

use core\task\manager;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;
use bookingextension_agent\task\rebuild_task_catalog_embeddings_adhoc;

/**
 * Task definition for booking.recreate_task_catalog.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recreate_task_catalog_task extends \bookingextension_agent\local\wbagent\booking\tasks\booking_task_base implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.recreate_task_catalog';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false);
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        $schema = [
            'version' => 1,
            'description' => 'Recreate the embeddings task catalog CSV used for vector task retrieval.'
                . ' Queues an adhoc rebuild job and can be used when the catalog is stale or missing.',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_recreate_task_catalog',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_recreate_task_catalog',
            'properties' => [
                'force' => [
                    'type' => 'boolean',
                    'description' => 'If true, force regeneration for all task embeddings (skip incremental reuse). Don\'t set if we talk of update or newly added tasks only.',
                    'required' => false,
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Optional embeddings model override for this rebuild run.',
                    'required' => false,
                ],
                'dimensions' => [
                    'type' => 'integer',
                    'description' => 'Optional embedding dimensions override (> 0).',
                    'required' => false,
                ],
            ],
        ];

        return $this->enrich_schema_with_prompt_meta($schema);
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'booking.recreate_task_catalog_requested',
                'description' => 'User asks to rebuild/recreate task catalog embeddings.',
            ],
            [
                'id' => 'booking.recrate_task_catalog_requested',
                'description' => 'User asks with typo: "recrate the task catalog".',
            ],
        ];
    }

    /**
     * Validate task input.
     *
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
        $errors = [];

        if (isset($input['dimensions'])) {
            $dimensions = (int)$input['dimensions'];
            if ($dimensions < 1) {
                $errors[] = get_string('agent_booking_recreate_task_catalog_invalid_dimensions', 'bookingextension_agent');
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $force = !empty($input['force']);
        $model = trim((string)($input['model'] ?? ''));
        $dimensions = isset($input['dimensions']) ? (int)$input['dimensions'] : 0;

        $customdata = [];
        if ($force) {
            $customdata['force'] = true;
        }
        if ($model !== '') {
            $customdata['model'] = $model;
        }
        if ($dimensions > 0) {
            $customdata['dimensions'] = $dimensions;
        }

        $task = new rebuild_task_catalog_embeddings_adhoc();
        if (!empty($customdata)) {
            $task->set_custom_data($customdata);
        }

        manager::reschedule_or_queue_adhoc_task($task);

        return [
            'status' => 'executed',
            'detail' => get_string('agent_booking_recreate_task_catalog_queued', 'bookingextension_agent'),
            'resultid' => null,
            'queued_task_class' => rebuild_task_catalog_embeddings_adhoc::class,
            'force' => $force,
            'model' => $model,
            'dimensions' => $dimensions > 0 ? $dimensions : null,
        ];
    }
}
