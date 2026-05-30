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

namespace bookingextension_agent\local\wbagent\services;

use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\task_registry;

/**
 * Resolves and aggregates preview option ids for confirm-run responses.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirm_preview_option_service {
    /** @var task_registry */
    private task_registry $registry;

    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param task_registry $registry
     * @param conversation_store $store
     */
    public function __construct(task_registry $registry, conversation_store $store) {
        $this->registry = $registry;
        $this->store = $store;
    }

    /**
     * Resolve all preview option ids for responses.
     *
     * @param int $cmid
     * @param int $userid
     * @param array $results
     * @return int[]
     */
    public function resolve_preview_option_ids_for_response(int $cmid, int $userid, array $results): array {
        $ids = [];
        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $previewids = is_array($entry['previewoptionids'] ?? null) ? (array)$entry['previewoptionids'] : [];
            foreach ($previewids as $id) {
                $normalized = (int)$id;
                if ($normalized > 0) {
                    $ids[] = $normalized;
                }
            }
            $resultid = (int)($entry['resultid'] ?? 0);
            if ($resultid > 0 && !in_array($resultid, $ids, true)) {
                $ids[] = $resultid;
            }
        }

        if (empty($ids)) {
            foreach ($this->registry->get_preview_option_memory_helpers() as $helper) {
                $storedids = array_map(
                    'intval',
                    (array)$helper->resolve_last_preview_option_ids_for_execute($cmid, $userid)
                );
                foreach ($storedids as $storedid) {
                    if ($storedid > 0) {
                        $ids[] = $storedid;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Return the first available preview option id.
     *
     * @param int[] $ids
     * @return int
     */
    public function first_preview_option_id(array $ids): int {
        foreach ($ids as $id) {
            $normalized = (int)$id;
            if ($normalized > 0) {
                return $normalized;
            }
        }

        return 0;
    }

    /**
     * Aggregate preview option ids into thread metadata.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param array $results
     * @param string $metadatakey
     * @return int[]
     */
    public function remember_confirm_preview_option_ids(
        int $threadid,
        int $cmid,
        int $userid,
        array $results,
        string $metadatakey
    ): array {
        $currentids = $this->resolve_preview_option_ids_for_response($cmid, $userid, $results);
        $storedids = $this->store->get_thread_metadata_value($threadid, $metadatakey);
        $aggregatedids = $this->merge_preview_option_ids(
            is_array($storedids) ? $storedids : [],
            $currentids
        );

        $this->store->set_thread_metadata_value($threadid, $metadatakey, $aggregatedids);
        return $aggregatedids;
    }

    /**
     * Resolve preview ids for confirm responses.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param array $results
     * @param string $metadatakey
     * @param int[]|null $aggregatedids
     * @return int[]
     */
    public function resolve_confirm_preview_option_ids_for_response(
        int $threadid,
        int $cmid,
        int $userid,
        array $results,
        string $metadatakey,
        ?array $aggregatedids = null
    ): array {
        $ids = is_array($aggregatedids)
            ? $aggregatedids
            : (is_array($this->store->get_thread_metadata_value($threadid, $metadatakey))
                ? $this->store->get_thread_metadata_value($threadid, $metadatakey)
                : []);

        $ids = $this->merge_preview_option_ids(
            is_array($ids) ? $ids : [],
            $this->resolve_preview_option_ids_for_response($cmid, $userid, $results)
        );

        if (empty($ids)) {
            return $this->resolve_preview_option_ids_for_response($cmid, $userid, $results);
        }

        return $ids;
    }

    /**
     * Merge list-like sources into positive unique integer ids.
     *
     * @param mixed ...$sources
     * @return int[]
     */
    public function merge_preview_option_ids(...$sources): array {
        $ids = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $entry) {
                $normalized = (int)$entry;
                if ($normalized > 0) {
                    $ids[] = $normalized;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
