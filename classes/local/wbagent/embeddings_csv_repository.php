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
 * CSV repository for skill-catalog embeddings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

/**
 * Handles storage and retrieval of skill-catalog embeddings in CSV format.
 */
class embeddings_csv_repository {
    /** Ordered CSV header columns. */
    public const HEADERS = [
        'skill',
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
     * Empty CSV escape character.
     *
     * PHP's default fputcsv()/fgetcsv() escape character is a backslash, which is NOT RFC-4180
     * and does not round-trip fields that contain backslashes — and our payload columns
     * (example_input_json, message_triggers_json, embedding_json) routinely contain JSON escapes
     * such as \/, \" and \uXXXX. With the default escape, fgetcsv() desyncs the column count and
     * rows are silently dropped on read. Passing an empty escape to BOTH the writer and the reader
     * makes them RFC-4180 compliant (internal quotes are doubled, never backslash-escaped), so the
     * catalog round-trips losslessly.
     */
    private const CSV_ESCAPE = '';

    /** @var string|null Optional absolute path override (testing / alternate stores). */
    private $pathoverride;

    /**
     * Constructor.
     *
     * @param string|null $pathoverride Absolute CSV path to use instead of the default location.
     */
    public function __construct(?string $pathoverride = null) {
        $this->pathoverride = $pathoverride;
    }

    /**
     * Return the absolute CSV path.
     *
     * @return string
     */
    public function get_csv_path(): string {
        if ($this->pathoverride !== null) {
            return $this->pathoverride;
        }

        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            global $CFG;
            return $CFG->dirroot . '/mod/booking/bookingextension/agent/tests/agent/fixtures/skill_catalog_embeddings.csv';
        }

        $dir = make_temp_directory('bookingextension_agent/wbagent');
        return $dir . '/skill_catalog_embeddings.csv';
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

        [$rows, $skipped] = $this->parse_file($path);
        if ($skipped > 0) {
            // A malformed row means the on-disk catalog is corrupt. Never hide it: readiness checks
            // treat a short read as not-ready and schedule a full rebuild, and the rebuild's
            // round-trip validation (see write_rows) prevents a corrupt file from being republished.
            debugging(
                "embeddings_csv_repository: skipped {$skipped} malformed row(s) while reading {$path}; "
                    . 'the skill-catalog embeddings file is corrupt and must be rebuilt.',
                DEBUG_DEVELOPER
            );
        }

        return $rows;
    }

    /**
     * Number of rows that were dropped during the most relevant parse of the on-disk file.
     *
     * Lets readiness checks distinguish a genuinely complete catalog from one that only parses
     * partially, so a corrupt file forces a rebuild instead of silently serving fewer skills.
     *
     * @return int
     */
    public function count_unreadable_rows(): int {
        $path = $this->get_csv_path();
        if (!is_readable($path)) {
            return 0;
        }

        [, $skipped] = $this->parse_file($path);
        return $skipped;
    }

    /**
     * Parse a CSV file into associative rows using RFC-4180 quoting (escape disabled).
     *
     * @param string $path
     * @return array{0: array<int,array<string,string>>, 1: int} parsed rows and skipped-row count
     */
    private function parse_file(string $path): array {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [[], 0];
        }

        $headers = fgetcsv($handle, 0, ',', '"', self::CSV_ESCAPE);
        if (!is_array($headers) || !$this->headers_match($headers)) {
            fclose($handle);
            return [[], 0];
        }

        $rows = [];
        $skipped = 0;
        while (($cols = fgetcsv($handle, 0, ',', '"', self::CSV_ESCAPE)) !== false) {
            if ($cols === null || $cols === [null]) {
                // Blank line: not a data row, and not corruption.
                continue;
            }
            if (!is_array($cols) || count($cols) !== count(self::HEADERS)) {
                $skipped++;
                continue;
            }
            $rows[] = array_combine(self::HEADERS, $cols);
        }

        fclose($handle);
        return [$rows, $skipped];
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

            if (trim((string)$row['skill']) === '' || trim((string)$row['content_hash']) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Atomically write rows to CSV, verifying a lossless round-trip before publishing.
     *
     * Writes to a temp file, re-reads it, and only renames it into place when every row parses
     * back. A corrupt serialization therefore never goes live (the previous file stays), and the
     * caller (the rebuild adhoc task) sees an exception — which lets Moodle's task scheduler apply
     * faildelay backoff instead of looping expensive embeddings rebuilds.
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

        fputcsv($handle, self::HEADERS, ',', '"', self::CSV_ESCAPE);
        foreach ($rows as $row) {
            $line = [];
            foreach (self::HEADERS as $header) {
                $line[] = (string)($row[$header] ?? '');
            }
            fputcsv($handle, $line, ',', '"', self::CSV_ESCAPE);
        }

        fclose($handle);
        @chmod($tmppath, $this->get_default_file_permissions());

        // Round-trip sanity check before the atomic swap.
        [$verified, $skipped] = $this->parse_file($tmppath);
        if ($skipped > 0 || count($verified) !== count($rows)) {
            @unlink($tmppath);
            throw new \moodle_exception(
                'embeddingscatalogwritecorrupt',
                'bookingextension_agent',
                '',
                (object)[
                    'expected' => count($rows),
                    'parsed' => count($verified),
                    'skipped' => $skipped,
                ]
            );
        }

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
