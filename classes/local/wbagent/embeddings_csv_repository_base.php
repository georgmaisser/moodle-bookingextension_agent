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
 * Shared base for the embeddings CSV repositories (skill-catalog and documentation).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

/**
 * RFC-4180 compliant, atomic CSV store for embedding rows.
 *
 * Both embedding stacks (skill-catalog and documentation) share the same storage shape:
 *  - a header row that must match the declared schema exactly,
 *  - payload columns that routinely contain JSON escapes (\/, \", \uXXXX) and commas/newlines,
 *  - a content_hash column enabling per-row reuse on rebuild.
 *
 * Concrete repositories declare their {@see headers()}, {@see required_nonempty_columns()},
 * a {@see default_csv_path()} and a {@see store_label()} for diagnostics. Everything else
 * (parsing, validation, atomic round-trip-verified write) lives here so the two stacks cannot
 * drift apart.
 *
 * Variant awareness (Phase F): a non-empty variant key (model + dimensions) is appended to the
 * file name as "…__<variant>.csv", so embeddings for different models live in separate files and
 * a model switch never invalidates the others. An empty variant key keeps the legacy, un-suffixed
 * file name — behaviour is therefore unchanged until a caller passes a variant.
 */
abstract class embeddings_csv_repository_base {
    /**
     * Empty CSV escape character.
     *
     * PHP's default fputcsv()/fgetcsv() escape character is a backslash, which is NOT RFC-4180 and
     * does not round-trip fields that contain backslashes — and our payload columns routinely
     * contain JSON escapes such as \/, \" and \uXXXX. With the default escape, fgetcsv() desyncs the
     * column count and rows are silently dropped on read. Passing an empty escape to BOTH the writer
     * and the reader makes them RFC-4180 compliant (internal quotes are doubled, never
     * backslash-escaped), so the store round-trips losslessly.
     */
    protected const CSV_ESCAPE = '';

    /** @var string|null Optional absolute path override (testing / alternate stores). */
    private $pathoverride;

    /** @var string Normalized variant key; empty means the legacy un-suffixed file. */
    private string $variantkey;

    /**
     * Constructor.
     *
     * @param string|null $pathoverride Absolute CSV path to use instead of the default location.
     * @param string      $variantkey   Optional variant (e.g. "model__dims"); appended to the file name.
     */
    public function __construct(?string $pathoverride = null, string $variantkey = '') {
        $this->pathoverride = $pathoverride;
        $this->variantkey = self::normalize_variant_key($variantkey);
    }

    // -------------------------------------------------------------------------
    // Subclass contract.

    /**
     * Ordered CSV header columns for this store.
     *
     * @return string[]
     */
    abstract protected function headers(): array;

    /**
     * Columns that must be non-empty for a row to be considered schema-valid.
     *
     * @return string[]
     */
    abstract protected function required_nonempty_columns(): array;

    /**
     * Absolute default CSV path (ending in ".csv") used when no path override is given.
     *
     * @return string
     */
    abstract protected function default_csv_path(): string;

    /**
     * Short human label for corruption diagnostics (e.g. "skill-catalog embeddings").
     *
     * @return string
     */
    abstract protected function store_label(): string;

    // -------------------------------------------------------------------------
    // Path / variant.

    /**
     * Return the absolute CSV path, including the variant suffix when a variant key is set.
     *
     * @return string
     */
    public function get_csv_path(): string {
        $base = $this->pathoverride ?? $this->default_csv_path();
        if ($this->variantkey === '') {
            return $base;
        }

        // Insert "__<variant>" before the .csv extension (or append it when there is none).
        if (preg_match('/\.csv$/i', $base)) {
            return (string)preg_replace('/\.csv$/i', '__' . $this->variantkey . '.csv', $base);
        }

        return $base . '__' . $this->variantkey;
    }

    /**
     * The normalized variant key in effect (empty for the legacy file).
     *
     * @return string
     */
    public function get_variant_key(): string {
        return $this->variantkey;
    }

    /**
     * Normalize a variant key to a filename-safe token.
     *
     * @param string $key
     * @return string
     */
    public static function normalize_variant_key(string $key): string {
        $key = strtolower(trim($key));
        $key = (string)preg_replace('/[^a-z0-9._-]+/', '_', $key);
        return trim($key, '_');
    }

    // -------------------------------------------------------------------------
    // Read.

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

        [$rows, $skipped] = $this->parse_file($path);
        if ($skipped > 0) {
            // A malformed row means the on-disk store is corrupt. Never hide it: readiness checks
            // treat a short read as not-ready and schedule a full rebuild, and the rebuild's
            // round-trip validation (see write_rows) prevents a corrupt file from being republished.
            debugging(
                static::class . ": skipped {$skipped} malformed row(s) while reading {$path}; "
                    . 'the ' . $this->store_label() . ' file is corrupt and must be rebuilt.',
                DEBUG_DEVELOPER
            );
        }

        return $rows;
    }

    /**
     * Number of rows dropped during the parse of the on-disk file.
     *
     * Lets readiness checks distinguish a genuinely complete store from one that only parses
     * partially, so a corrupt file forces a rebuild instead of silently serving fewer rows.
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
    protected function parse_file(string $path): array {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [[], 0];
        }

        $headers = fgetcsv($handle, 0, ',', '"', static::CSV_ESCAPE);
        if (!is_array($headers) || !$this->headers_match($headers)) {
            fclose($handle);
            return [[], 0];
        }

        $cols = $this->headers();
        $expected = count($cols);
        $rows = [];
        $skipped = 0;
        while (($fields = fgetcsv($handle, 0, ',', '"', static::CSV_ESCAPE)) !== false) {
            if ($fields === null || $fields === [null]) {
                // Blank line: not a data row, and not corruption.
                continue;
            }
            if (!is_array($fields) || count($fields) !== $expected) {
                $skipped++;
                continue;
            }
            $rows[] = array_combine($cols, $fields);
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

        $required = $this->required_nonempty_columns();
        foreach ($rows as $row) {
            foreach ($this->headers() as $key) {
                if (!array_key_exists($key, $row)) {
                    return false;
                }
            }

            foreach ($required as $key) {
                if (trim((string)($row[$key] ?? '')) === '') {
                    return false;
                }
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Write.

    /**
     * Atomically write rows to CSV, verifying a lossless round-trip before publishing.
     *
     * Writes to a temp file, re-reads it, and only renames it into place when every row parses
     * back. A corrupt serialization therefore never goes live (the previous file stays), and the
     * caller sees an exception — which lets Moodle's task scheduler apply faildelay backoff instead
     * of looping expensive embeddings rebuilds.
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

        $headers = $this->headers();
        fputcsv($handle, $headers, ',', '"', static::CSV_ESCAPE);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = (string)($row[$header] ?? '');
            }
            fputcsv($handle, $line, ',', '"', static::CSV_ESCAPE);
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

    // -------------------------------------------------------------------------
    // Helpers.

    /**
     * Compare CSV headers against expected schema.
     *
     * @param array<int,string> $headers
     * @return bool
     */
    protected function headers_match(array $headers): bool {
        $expected = $this->headers();
        if (count($headers) !== count($expected)) {
            return false;
        }

        foreach ($expected as $idx => $name) {
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
    protected function get_default_file_permissions(): int {
        global $CFG;

        if (!empty($CFG->filepermissions)) {
            return (int)$CFG->filepermissions;
        }

        return 0644;
    }
}
