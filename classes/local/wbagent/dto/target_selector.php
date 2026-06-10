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

namespace bookingextension_agent\local\wbagent\dto;

/**
 * Value object describing an explicit operating-context target for a single skill operation.
 *
 * Generic, domain-agnostic: it names a target by Moodle context LEVEL plus an id and/or a free
 * text query. The engine never hardcodes "course"; the level drives which resolver the
 * {@see \bookingextension_agent\local\wbagent\services\security\operating_context_target_registry}
 * uses to turn this into a concrete context. An empty selector means "no explicit target — use
 * the ambient/ancestor context" (today's behaviour).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class target_selector {
    /** @var int Moodle CONTEXT_* level the target names. */
    private int $level;

    /** @var int|null Explicit instance id of the target (e.g. a course id), when known. */
    private ?int $id;

    /** @var string|null Free-text query naming the target (e.g. a course name), when no id. */
    private ?string $query;

    /**
     * Constructor.
     *
     * @param int         $level Moodle CONTEXT_* level.
     * @param int|null    $id    Explicit instance id, or null.
     * @param string|null $query Free-text query, or null.
     */
    private function __construct(int $level, ?int $id, ?string $query) {
        $this->level = $level;
        $this->id = ($id !== null && $id > 0) ? $id : null;
        $query = $query !== null ? trim($query) : null;
        $this->query = ($query !== null && $query !== '') ? $query : null;
    }

    /**
     * Create a selector for an explicit level.
     *
     * @param int         $level
     * @param int|null    $id
     * @param string|null $query
     * @return self
     */
    public static function create(int $level, ?int $id = null, ?string $query = null): self {
        return new self($level, $id, $query);
    }

    /**
     * Convenience factory for a course-level target.
     *
     * @param int|null    $id
     * @param string|null $query
     * @return self
     */
    public static function for_course(?int $id = null, ?string $query = null): self {
        return new self(CONTEXT_COURSE, $id, $query);
    }

    /**
     * The Moodle context level this target names.
     *
     * @return int
     */
    public function level(): int {
        return $this->level;
    }

    /**
     * Explicit instance id, or null.
     *
     * @return int|null
     */
    public function id(): ?int {
        return $this->id;
    }

    /**
     * Free-text query, or null.
     *
     * @return string|null
     */
    public function query(): ?string {
        return $this->query;
    }

    /**
     * Whether this selector names no concrete target (neither id nor query).
     *
     * @return bool
     */
    public function is_empty(): bool {
        return $this->id === null && $this->query === null;
    }
}
