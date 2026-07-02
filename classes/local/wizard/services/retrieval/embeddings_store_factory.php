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
 * Factory that resolves the active embeddings store implementation.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\retrieval;

/**
 * Single entry point for obtaining an {@see embeddings_store}.
 *
 * The `embeddingsstore` admin setting selects the backend (csv | db). During the migration only the
 * CSV backend exists, so this always returns {@see csv_embeddings_store}; the DB backend (Phase 1)
 * plugs in here behind the flag without touching any caller.
 */
class embeddings_store_factory {
    /**
     * Return the active embeddings store.
     *
     * @return embeddings_store
     */
    public static function instance(): embeddings_store {
        // Phase 1 will branch on get_config('bookingextension_agent', 'embeddingsstore') === 'db'.
        return new csv_embeddings_store(self::mappers());
    }

    /**
     * The registered per-area row mappers, keyed by area.
     *
     * @return embeddings_row_mapper[]
     */
    public static function mappers(): array {
        return [
            docs_row_mapper::AREA => new docs_row_mapper(),
            skill_row_mapper::AREA => new skill_row_mapper(),
        ];
    }
}
