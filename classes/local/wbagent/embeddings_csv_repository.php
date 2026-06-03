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
 * CSV repository for task-catalog embeddings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

/**
 * Handles storage and retrieval of task-catalog embeddings in CSV format.
 */
class embeddings_csv_repository {
    /** Ordered CSV header columns. */
    public const HEADERS = [
        'task',
        'intent',
        'readonly',
        'description',
        'minimal_input_json',
        'example_input_json',
        'message_triggers_json',
        'embedding_model',
        'embedding_dimensions',
        'content_hash',
        'embedding_json',
    ];

    /**
     * Return the absolute CSV path.
     *
     * @return string
     */
    public function get_csv_path(): string {
        $dir = make_temp_directory('bookingextension_agent/wbagent');
        return $dir . '/task_catalog_embeddings.csv';
    }

    /**
     * Whether the CSV file exists.
     *
     * @return bool
     */
    public function exists(): bool {
        return is_readable($this->get_csv_path());
    }

    /**
     * Read all CSV rows as associative arrays.
     *
     * @return array<int,array<string,string>>
     */
    public function read_rows(): array {
        $path = $this->get_csv_path();
        if (!is_readable($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);
        if (!is_array($headers) || !$this->headers_match($headers)) {
            fclose($handle);
            return [];
        }

        $rows = [];
        while (($cols = fgetcsv($handle)) !== false) {
            if (!is_array($cols) || count($cols) !== count(self::HEADERS)) {
                continue;
            }
            $rows[] = array_combine(self::HEADERS, $cols);
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Validate row schema and non-empty key fields.
     *
     * @param array<int,array<string,string>> $rows
     * @return bool
     */
    public function is_valid_schema(array $rows): bool {
        if (empty($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            foreach (self::HEADERS as $key) {
                if (!array_key_exists($key, $row)) {
                    return false;
                }
            }

            if (trim((string)$row['task']) === '' || trim((string)$row['content_hash']) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Atomically write rows to CSV.
     *
     * @param array<int,array<string,string>> $rows
     * @return void
     */
    public function write_rows(array $rows): void {
        $path = $this->get_csv_path();
        $tmppath = $path . '.tmp';

        $handle = fopen($tmppath, 'wb');
        if ($handle === false) {
            throw new \moodle_exception('cannotwritetempfile', 'error');
        }

        fputcsv($handle, self::HEADERS);
        foreach ($rows as $row) {
            $line = [];
            foreach (self::HEADERS as $header) {
                $line[] = (string)($row[$header] ?? '');
            }
            fputcsv($handle, $line);
        }

        fclose($handle);
        @chmod($tmppath, $this->get_default_file_permissions());
        rename($tmppath, $path);
    }

    /**
     * Compare CSV headers against expected schema.
     *
     * @param array<int,string> $headers
     * @return bool
     */
    private function headers_match(array $headers): bool {
        if (count($headers) !== count(self::HEADERS)) {
            return false;
        }

        foreach (self::HEADERS as $idx => $name) {
            if ((string)($headers[$idx] ?? '') !== $name) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get default file permissions from Moodle config.
     *
     * @return int
     */
    private function get_default_file_permissions(): int {
        global $CFG;

        if (!empty($CFG->filepermissions)) {
            return (int)$CFG->filepermissions;
        }

        return 0644;
    }
}
