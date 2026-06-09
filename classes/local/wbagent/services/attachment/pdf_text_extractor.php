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

namespace bookingextension_agent\local\wbagent\services\attachment;

/**
 * Extracts plain text from PDF files.
 *
 * Strategy (in order):
 *   1. pdftotext (poppler-utils shell command) — fast, accurate.
 *   2. smalot/pdfparser (PHP library) — fallback if composer package available.
 *   3. Throws moodle_exception if neither is available.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_text_extractor {
    /** Maximum characters of extracted text to keep. ~3750 tokens. */
    public const MAX_CHARS = 15000;

    /**
     * Whether at least one extraction method is available.
     *
     * @return bool
     */
    public function is_available(): bool {
        return $this->has_pdftotext() || $this->has_pdfparser();
    }

    /**
     * Extract text from a PDF file.
     *
     * @param string $filepath Absolute path to PDF file.
     * @return string Extracted text (possibly truncated).
     * @throws \moodle_exception When no extraction method is available.
     */
    public function extract(string $filepath): string {
        if ($this->has_pdftotext()) {
            $text = $this->extract_via_shell($filepath);
            if ($text !== null) {
                return $this->truncate($text);
            }
        }

        if ($this->has_pdfparser()) {
            $text = $this->extract_via_pdfparser($filepath);
            if ($text !== null) {
                return $this->truncate($text);
            }
        }

        throw new \moodle_exception('ai_pdf_extraction_unavailable', 'bookingextension_agent');
    }

    /**
     * Truncate text to MAX_CHARS and append a note if truncated.
     *
     * @param string $text
     * @return string
     */
    private function truncate(string $text): string {
        $text = trim($text);
        if (mb_strlen($text) <= self::MAX_CHARS) {
            return $text;
        }

        $truncated = mb_substr($text, 0, self::MAX_CHARS);
        $note = get_string('ai_pdf_truncated', 'bookingextension_agent', number_format(self::MAX_CHARS));
        return $truncated . "\n\n" . $note;
    }

    /**
     * Whether pdftotext is available on this system.
     *
     * @return bool
     */
    private function has_pdftotext(): bool {
        if (!function_exists('exec')) {
            return false;
        }
        $output = [];
        $ret = 0;
        @exec('pdftotext -v 2>&1', $output, $ret);
        // Pdftotext returns 0 or 99 on version print; just check the command exists.
        return $ret !== 127;
    }

    /**
     * Whether smalot/pdfparser is available via Composer autoload.
     *
     * @return bool
     */
    private function has_pdfparser(): bool {
        return class_exists('\Smalot\PdfParser\Parser');
    }

    /**
     * Extract text via pdftotext shell command.
     *
     * @param string $filepath
     * @return string|null Extracted text or null on failure.
     */
    private function extract_via_shell(string $filepath): ?string {
        $output = [];
        $ret = 0;
        $safepath = escapeshellarg($filepath);

        // Limit execution time for this call.
        $prevlimit = ini_get('max_execution_time');
        @set_time_limit(30);

        @exec('pdftotext -enc UTF-8 ' . $safepath . ' - 2>/dev/null', $output, $ret);

        if ((int)$prevlimit > 0) {
            @set_time_limit((int)$prevlimit);
        }

        if ($ret !== 0 || empty($output)) {
            return null;
        }

        return implode("\n", $output);
    }

    /**
     * Extract text via smalot/pdfparser PHP library.
     *
     * @param string $filepath
     * @return string|null Extracted text or null on failure.
     */
    private function extract_via_pdfparser(string $filepath): ?string {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filepath);
            $text = $pdf->getText();
            return is_string($text) && trim($text) !== '' ? $text : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
