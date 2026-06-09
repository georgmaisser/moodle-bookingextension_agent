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
 * Registry of documentation corpora (the single corpus_id → docs-root authority).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services\lookup;

/**
 * Single source of truth mapping a corpus_id to its absolute docs root.
 *
 * A document is addressed end-to-end as the pair (corpus_id, relpath); a bare relpath is
 * meaningless without its corpus_id. Index, lookup, skill result and preview all resolve the
 * absolute root exclusively through this registry, and every file read is confined to the
 * resolved root of that corpus_id.
 *
 * Corpora are discovered component-agnostically: any plugin may expose a class
 *   \{component}\local\wbagent\docs_provider
 * with a static method get_doc_corpora(): array<string,string>  (corpus_id => absolute root).
 * An admin-configured corpus (aidocsroot / aidocs_corpusid) is added on top.
 */
class docs_corpus_registry {
    /** Provider class (relative to a component frankenstyle namespace). */
    private const PROVIDER_CLASS_SUFFIX = '\\local\\wbagent\\docs_provider';

    /** Provider method that returns corpus_id => absolute root. */
    private const PROVIDER_METHOD = 'get_doc_corpora';

    /** @var array<string,string>|null Per-instance resolved map (corpus_id => abs root). */
    private ?array $corpora = null;

    /** @var array<string,string>|null Request-level cache of discovered corpora. */
    private static ?array $discovered = null;

    /** @var array<string,string>|null Test override (only honoured under PHPUNIT_TEST). */
    private static ?array $testcorpora = null;

    /**
     * Constructor.
     *
     * @param array<string,string>|null $corpora Explicit corpus_id => absolute root map (bypasses
     *                                           discovery; mainly for callers that already know the
     *                                           set). When null, the registry discovers + config.
     */
    public function __construct(?array $corpora = null) {
        if ($corpora !== null) {
            $this->corpora = $this->sanitize($corpora);
        }
    }

    /**
     * Return all known corpora as corpus_id => absolute root.
     *
     * @return array<string,string>
     */
    public function list(): array {
        if ($this->corpora !== null) {
            return $this->corpora;
        }
        if (self::$testcorpora !== null) {
            return $this->corpora = $this->sanitize(self::$testcorpora);
        }
        return $this->corpora = $this->resolve_all();
    }

    /**
     * Resolve the absolute docs root for a corpus_id.
     *
     * @param string $corpusid
     * @return string|null Absolute root, or null when the corpus is unknown.
     */
    public function resolve_root(string $corpusid): ?string {
        return $this->list()[trim($corpusid)] ?? null;
    }

    /**
     * Whether a corpus_id is registered.
     *
     * @param string $corpusid
     * @return bool
     */
    public function is_known(string $corpusid): bool {
        return $this->resolve_root($corpusid) !== null;
    }

    /**
     * Return the primary corpus_id (first registered), or null when none.
     *
     * @return string|null
     */
    public function primary(): ?string {
        foreach ($this->list() as $corpusid => $unused) {
            return $corpusid;
        }
        return null;
    }

    /**
     * Discover provider corpora and merge the admin-configured corpus on top.
     *
     * @return array<string,string>
     */
    private function resolve_all(): array {
        $corpora = $this->discover();

        // Admin-configured corpus (points at an arbitrary root) wins over a provider with the same id.
        $configuredroot = trim((string)get_config('bookingextension_agent', 'aidocsroot'));
        if ($configuredroot !== '' && is_dir($configuredroot)) {
            $corpusid = trim((string)get_config('bookingextension_agent', 'aidocs_corpusid'));
            if ($corpusid === '') {
                $corpusid = docs_embeddings_index_service::DEFAULT_CORPUS_ID;
            }
            $corpora[$corpusid] = rtrim($configuredroot, '/\\');
        }

        return $corpora;
    }

    /**
     * Scan all components for a docs_provider and collect their declared corpora.
     *
     * @return array<string,string>
     */
    private function discover(): array {
        if (self::$discovered !== null) {
            return self::$discovered;
        }

        $corpora = [];
        foreach (\core_component::get_component_names() as $component) {
            $class = '\\' . $component . self::PROVIDER_CLASS_SUFFIX;
            if (!class_exists($class) || !method_exists($class, self::PROVIDER_METHOD)) {
                continue;
            }
            try {
                $declared = (array) $class::{self::PROVIDER_METHOD}();
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($declared as $corpusid => $root) {
                $corpusid = trim((string)$corpusid);
                $root = rtrim((string)$root, '/\\');
                // First declaration wins; only existing directories are registered.
                if ($corpusid === '' || $root === '' || isset($corpora[$corpusid]) || !is_dir($root)) {
                    continue;
                }
                $corpora[$corpusid] = $root;
            }
        }

        return self::$discovered = $corpora;
    }

    /**
     * Normalise an explicit corpus map (drop empties, trim roots).
     *
     * @param array<string,string> $corpora
     * @return array<string,string>
     */
    private function sanitize(array $corpora): array {
        $clean = [];
        foreach ($corpora as $corpusid => $root) {
            $corpusid = trim((string)$corpusid);
            $root = rtrim((string)$root, '/\\');
            if ($corpusid !== '' && $root !== '') {
                $clean[$corpusid] = $root;
            }
        }
        return $clean;
    }

    /**
     * Override the corpus set for unit tests (e.g. temp-dir corpora).
     *
     * @param array<string,string>|null $corpora Map to use, or null to restore discovery.
     * @return void
     */
    public static function set_corpora_for_testing(?array $corpora): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('set_corpora_for_testing() is only available under PHPUNIT_TEST.');
        }
        self::$testcorpora = $corpora;
        self::$discovered = null;
    }
}
