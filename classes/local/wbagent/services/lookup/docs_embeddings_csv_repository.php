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
 * CSV repository for documentation chunk embeddings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services\lookup;

/**
 * Handles storage and retrieval of documentation chunk embeddings in CSV format.
 *
 * Each row represents one documentation chunk (currently one .md file = one chunk).
 * The corpus_id field groups chunks by their source corpus (e.g. 'mod_booking').
 */
class docs_embeddings_csv_repository {
    /** Ordered CSV header columns. */
    public const HEADERS = [
        'corpus_id',
        'chunk_path',
        'chunk_title',
        'line_start',
        'line_end',
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
        return $dir . '/docs_embeddings.csv';
    }

    /**
     * Whether the CSV file exists and is readable.
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
     * Read rows filtered by corpus_id.
     *
     * @param string $corpusid
     * @return array<int,array<string,string>>
     */
    public function read_rows_for_corpus(string $corpusid): array {
        $rows = $this->read_rows();
        if ($corpusid === '') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($corpusid): bool {
            return trim((string)($row['corpus_id'] ?? '')) === $corpusid;
        }));
    }

    /**
     * Validate that rows have the required schema and non-empty key fields.
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

            if (
                trim((string)($row['corpus_id'] ?? '')) === ''
                || trim((string)($row['chunk_path'] ?? '')) === ''
                || trim((string)($row['content_hash'] ?? '')) === ''
            ) {
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
     * Delete all rows for a specific corpus_id and rewrite the CSV.
     *
     * @param string $corpusid
     * @return int Number of rows removed.
     */
    public function delete_corpus(string $corpusid): int {
        $rows = $this->read_rows();
        $kept = [];
        $removed = 0;
        foreach ($rows as $row) {
            if (trim((string)($row['corpus_id'] ?? '')) === $corpusid) {
                $removed++;
            } else {
                $kept[] = $row;
            }
        }

        if ($removed > 0) {
            $this->write_rows($kept);
        }

        return $removed;
    }

    /**
     * Compare CSV headers against the expected schema.
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
