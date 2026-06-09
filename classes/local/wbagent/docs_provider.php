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

namespace bookingextension_agent\local\wbagent;

/**
 * Documentation corpus provider for bookingextension_agent.
 *
 * Exposes the bookingextension_agent/docs tree as the "bookingextension_agent" corpus so the
 * wbagent docs registry can index, search and preview the agent's own documentation. This lets
 * the core.explain_docs skill answer questions about the engine itself (the runtime loop, the
 * planner contract, risk classes, …) directly from this corpus.
 *
 * The mechanism is component-agnostic: any plugin exposes its docs the same way by adding a
 * \{component}\local\wbagent\docs_provider with a static get_doc_corpora().
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class docs_provider {
    /** Corpus id for the bookingextension_agent documentation. */
    public const CORPUS_ID = 'bookingextension_agent';

    /**
     * Return the documentation corpora this plugin contributes.
     *
     * @return array<string,string> corpus_id => absolute docs root path
     */
    public static function get_doc_corpora(): array {
        $dir = \core_component::get_component_directory('bookingextension_agent');
        if ($dir === null) {
            return [];
        }

        $root = rtrim($dir, '/\\') . '/docs';
        if (!is_dir($root)) {
            return [];
        }

        return [self::CORPUS_ID => $root];
    }
}
