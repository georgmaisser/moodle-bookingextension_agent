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
 * Application service for booking option mutations.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services\mutation;

use bookingextension_agent\local\wbagent\dto\create_option_input_dto;
use bookingextension_agent\local\wbagent\dto\update_option_input_dto;
use bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto;
use bookingextension_agent\local\wbagent\dto\mutation_result_dto;

/**
 * Centralises booking option mutation logic previously spread across booking_task_support.
 *
 * Tasks orchestrate, services execute.  Both paths call the same underlying logic
 * so architectural tests can verify identical results for identical input.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_mutation_service {
    /**
     * Validate a create-option request without executing it.
     *
     * @param create_option_input_dto $dto
     * @param int                     $cmid
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function validate_create(create_option_input_dto $dto, int $cmid): array {
        return [
            'valid' => false,
            'errors' => [get_string('agent_booking_unknown_task', 'bookingextension_agent', 'booking.create_option')],
            'ambiguities' => [],
        ];
    }

    /**
     * Validate an update-option request without executing it.
     *
     * @param update_option_input_dto $dto
     * @param int                     $cmid
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function validate_update(update_option_input_dto $dto, int $cmid): array {
        return [
            'valid' => false,
            'errors' => [get_string('agent_booking_unknown_task', 'bookingextension_agent', 'booking.update_option')],
            'ambiguities' => [],
        ];
    }

    /**
     * Validate a bulk-update-options request without executing it.
     *
     * @param bulk_update_options_input_dto $dto
     * @param int                           $cmid
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function validate_bulk_update(bulk_update_options_input_dto $dto, int $cmid): array {
        return [
            'valid' => false,
            'errors' => [get_string('agent_booking_unknown_task', 'bookingextension_agent', 'booking.bulk_update_options')],
            'ambiguities' => [],
        ];
    }

    /**
     * Execute a create-option mutation.
     *
     * @param create_option_input_dto $dto
     * @param int                     $cmid
     * @param int                     $userid
     * @return mutation_result_dto
     */
    public function create_option(create_option_input_dto $dto, int $cmid, int $userid): mutation_result_dto {
        return mutation_result_dto::error(get_string('agent_booking_unknown_task', 'bookingextension_agent', 'booking.create_option'));
    }

    /**
     * Execute an update-option mutation.
     *
     * @param update_option_input_dto $dto
     * @param int                     $cmid
     * @param int                     $userid
     * @return mutation_result_dto
     */
    public function update_option(update_option_input_dto $dto, int $cmid, int $userid): mutation_result_dto {
        return mutation_result_dto::error(get_string('agent_booking_unknown_task', 'bookingextension_agent', 'booking.update_option'));
    }

    /**
     * Execute a bulk-update-options mutation.
     *
     * @param bulk_update_options_input_dto $dto
     * @param int                           $cmid
     * @param int                           $userid
     * @return mutation_result_dto
     */
    public function bulk_update_options(bulk_update_options_input_dto $dto, int $cmid, int $userid): mutation_result_dto {
        return mutation_result_dto::error(get_string('agent_booking_unknown_task', 'bookingextension_agent', 'booking.bulk_update_options'));
    }
}
