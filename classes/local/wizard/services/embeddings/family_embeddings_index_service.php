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
 * Index service for family-level skill catalog embeddings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\embeddings;

use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\embeddings_csv_repository;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use context_system;

/**
 * Rebuilds and persists the skill-catalog embeddings index.
 */
class family_embeddings_index_service {
    /**
     * Rebuild the embeddings CSV from the current registry.
     *
     * @param skill_registry $registry
     * @param string|null $model
     * @param int|null $dimensions
     * @param bool $forcefullregen
     * @return array
     */
    public function rebuild_catalog(
        skill_registry $registry,
        ?string $model = null,
        ?int $dimensions = null,
        bool $forcefullregen = false
    ): array {
        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            return [
                'status' => 'skipped',
                'reason' => 'embeddings_provider_unavailable',
                'written' => 0,
                'embedded' => 0,
                'reused' => 0,
                'deleted' => 0,
            ];
        }

        $resolved = (new embeddings_action_config_resolver())->resolve_with_overrides($model, $dimensions);
        $resolvedmodel = $resolved['model'];
        $resolveddimensions = $resolved['dimensions'];

        $builder = new embeddings_catalog_builder_service();
        // Write into the active model's variant file (respects model/dimensions overrides above).
        $repo = embeddings_csv_repository::for_variant($resolvedmodel, $resolveddimensions);
        $rows = $builder->build_full_catalog_rows($registry, $resolvedmodel, $resolveddimensions);
        if (empty($rows)) {
            return [
                'status' => 'empty',
                'written' => 0,
                'embedded' => 0,
                'reused' => 0,
                'deleted' => 0,
            ];
        }

        // Multi-vector store (SKILL_REWORK.md §5): a skill spans several anchor rows. Reuse and state
        // tracking are keyed per ANCHOR (skill#anchor_index); the per-skill state is then aggregated.
        $existingrows = $repo->read_rows();
        $existingbyanchor = [];
        $existingskillset = [];
        $existinganchorcount = [];
        if ($repo->is_valid_schema($existingrows)) {
            foreach ($existingrows as $existingrow) {
                $skillname = trim((string)($existingrow['skill'] ?? ''));
                if ($skillname === '') {
                    continue;
                }
                $existingbyanchor[$this->anchor_key($existingrow)] = $existingrow;
                $existingskillset[$skillname] = true;
                $existinganchorcount[$skillname] = ($existinganchorcount[$skillname] ?? 0) + 1;
            }
        }

        // A skill is 'untouched' only when EVERY current anchor matches a stored anchor by
        // content_hash AND the anchor count is unchanged (an added/removed utterance => 'updated').
        $currentanchorcount = [];
        $skillunchanged = [];
        $currentskillnames = [];
        foreach ($rows as $row) {
            $skillname = trim((string)($row['skill'] ?? ''));
            if ($skillname === '') {
                continue;
            }
            $currentskillnames[$skillname] = true;
            $currentanchorcount[$skillname] = ($currentanchorcount[$skillname] ?? 0) + 1;
            if (!array_key_exists($skillname, $skillunchanged)) {
                $skillunchanged[$skillname] = true;
            }
            $existinganchor = $existingbyanchor[$this->anchor_key($row)] ?? null;
            if (
                $existinganchor === null
                || trim((string)($existinganchor['content_hash'] ?? '')) !== trim((string)($row['content_hash'] ?? ''))
            ) {
                $skillunchanged[$skillname] = false;
            }
        }

        $skillstates = [];
        foreach (array_keys($currentskillnames) as $skillname) {
            if (empty($existingskillset[$skillname])) {
                $skillstates[$skillname] = 'created';
            } else if (
                $forcefullregen
                || empty($skillunchanged[$skillname])
                || (int)($existinganchorcount[$skillname] ?? 0) !== (int)($currentanchorcount[$skillname] ?? 0)
            ) {
                $skillstates[$skillname] = 'updated';
            } else {
                $skillstates[$skillname] = 'untouched';
            }
        }

        $currentskillnameslist = array_keys($currentskillnames);
        sort($currentskillnameslist);
        $removedskills = array_values(array_diff(array_keys($existingskillset), $currentskillnameslist));
        sort($removedskills);
        foreach ($removedskills as $skillname) {
            $skillstates[$skillname] = 'deleted';
        }

        $context = context_system::instance();
        $admin = get_admin();
        $userid = !empty($admin->id) ? (int)$admin->id : 2;
        // Counted per ANCHOR row (one skill can have some anchors reused and others re-embedded).
        $embeddedcount = 0;
        $reusedcount = 0;
        $llm = new llm_call_service(new conversation_store());

        foreach ($rows as $idx => $row) {
            $contenthash = trim((string)($row['content_hash'] ?? ''));
            $existingrow = $existingbyanchor[$this->anchor_key($row)] ?? null;

            if (
                !$forcefullregen
                && is_array($existingrow)
                && trim((string)($existingrow['content_hash'] ?? '')) === $contenthash
                && trim((string)($existingrow['embedding_json'] ?? '')) !== ''
            ) {
                $rows[$idx]['embedding_json'] = (string)$existingrow['embedding_json'];
                $reusedcount++;
                unset($rows[$idx]['_embedding_input']);
                continue;
            }

            $inputtext = (string)($row['_embedding_input'] ?? '');
            if ($inputtext === '') {
                continue;
            }

            $embeddingcall = $llm->invoke_embeddings_for_context(
                0,
                (int)$context->id,
                $userid,
                'idx|p=disc|st=emb|ac=emb|rt=wb',
                $inputtext,
                $resolveddimensions
            );

            if (empty($embeddingcall['success'])) {
                continue;
            }

            $embedding = (array)($embeddingcall['embedding'] ?? []);
            if (empty($embedding)) {
                continue;
            }

            $rows[$idx]['embedding_json'] = json_encode($embedding, JSON_UNESCAPED_UNICODE);
            $embeddedcount++;
            unset($rows[$idx]['_embedding_input']);
        }

        foreach ($rows as $idx => $row) {
            unset($rows[$idx]['_embedding_input']);
        }

        $repo->write_rows($rows);

        return [
            'status' => 'written',
            'model' => $resolvedmodel,
            'dimensions' => $resolveddimensions,
            'written' => count($rows),
            'embedded' => $embeddedcount,
            'reused' => $reusedcount,
            'deleted' => count($removedskills),
            'skillstates' => $skillstates,
        ];
    }

    /**
     * Stable per-anchor identity key (skill + anchor_index) for multi-vector reuse.
     *
     * @param array $row
     * @return string
     */
    private function anchor_key(array $row): string {
        $skillname = trim((string)($row['skill'] ?? ''));
        if ($skillname === '') {
            return '';
        }
        return $skillname . '#' . (string)($row['anchor_index'] ?? '0');
    }
}
