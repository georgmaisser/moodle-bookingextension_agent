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

namespace bookingextension_agent\local\wbagent\wbagent\skills;

use bookingextension_agent\local\wbagent\core\skills\core_skill_base;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\dto\skill_risk_class;
use bookingextension_agent\local\wbagent\embeddings_action_config_resolver;
use bookingextension_agent\local\wbagent\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wbagent\services\embeddings\embeddings_readiness_service;
use bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service;
use bookingextension_agent\local\wbagent\services\llm\llm_call_service;
use bookingextension_agent\local\wbagent\skill_registry_factory;

/**
 * Skill definition for wbagent.search_skills (Dynamic Skill Discovery).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_skills_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'wbagent.search_skills';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Return skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'If none of the provided skills match the user\'s request, ' .
                'use this tool with a descriptive query to search the tool registry for additional capabilities.',
            'readonly' => true,
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'A short, descriptive search term or user intent to find the ' .
                        'right tool (e.g. "download certificate" or "delete user").',
                    'required' => true,
                ],
            ],
        ];
    }

    /**
     * Return example input for planner contract rendering.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return [
            'query' => 'download certificate',
        ];
    }

    /**
     * Return skill-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'wbagent.search_skills_request',
                'description' => 'User asks to perform an action but the necessary tool is not immediately visible.',
            ],
        ];
    }

    /**
     * Check skill input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $query = trim((string)($input['query'] ?? ''));
        if ($query === '') {
            $errors[] = 'Search query must not be empty.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
            'issue_codes' => empty($errors) ? [] : ['RECOVERABLE_INPUT_ERROR'],
        ];
    }

    /**
     * Execute skill.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $query = trim((string)($input['query'] ?? ''));
        if ($query === '') {
            return [
                'status' => 'failed',
                'message' => 'Empty search query.',
                'discovered_skills' => [],
                'observation_full' => 'No search query was provided to wbagent.search_skills. Re-run this skill '
                    . 'with a concrete "query" describing the capability the user needs (use the user\'s own '
                    . 'request). Do NOT conclude from this that the capability is unavailable.',
                // Instructional engine text — exempt from privacy anonymization
                // (masking instructions corrupts them, see threads 286/288).
                'observation_engine_static' => true,
            ];
        }

        $registry = skill_registry_factory::get_default();
        $readiness = new embeddings_readiness_service();

        if (!$readiness->is_wunderbyte_embeddings_available()) {
            return [
                'status' => 'failed',
                'message' => 'Skill discovery is unavailable because embeddings are disabled.',
                'discovered_skills' => [],
            ];
        }

        $embeddingsettings = (new embeddings_action_config_resolver())->resolve();
        $embeddingmodel = (string)($embeddingsettings['model'] ?? 'text-embedding-3-small');
        $embeddingdimensions = (int)($embeddingsettings['dimensions'] ?? 1536);

        $status = $readiness->get_catalog_status($registry, $embeddingmodel, $embeddingdimensions);
        if (empty($status['ready']) || empty($status['rows']) || !is_array($status['rows'])) {
            return [
                'status' => 'failed',
                'message' => 'Skill catalog embeddings are not ready.',
                'discovered_skills' => [],
            ];
        }

        $store = new conversation_store();
        $llm = new llm_call_service($store);

        $embeddingcall = $llm->invoke_embeddings_for_context(
            0, // Thread ID 0 indicates internal retrieval lookup without thread context.
            $contextid,
            $userid,
            'wbagent.search_skills',
            $query,
            $embeddingdimensions
        );

        if (empty($embeddingcall['success']) || empty($embeddingcall['embedding'])) {
            return [
                'status' => 'failed',
                'message' => 'Failed to generate embedding for the query.',
                'discovered_skills' => [],
            ];
        }

        $retrieval = new embeddings_retrieval_service();
        $toprows = $retrieval->search_top_k(
            (array)$embeddingcall['embedding'],
            $status['rows'],
            5 // Top-k 5 is enough to inject into the next RAG iteration.
        );

        $discovered = [];
        foreach ($toprows as $row) {
            $skillname = trim((string)($row['skill'] ?? ''));
            if ($skillname === '') {
                continue;
            }
            try {
                $skill = $registry->get_skill($skillname);
                $discovered[] = [
                    'skill' => $skillname,
                    'schema' => $skill->get_schema(),
                ];
            } catch (\Exception $e) {
                // Ignore missing skills.
                unset($e);
            }
        }

        // Surface the discovered skills as an authoritative observation so the next planner turn can
        // select one of them — they are registered/allowed skills even if they were not in the slim
        // catalog shown initially.
        $lines = [];
        foreach ($discovered as $entry) {
            $name = trim((string)($entry['skill'] ?? ''));
            if ($name === '') {
                continue;
            }
            $desc = trim((string)($entry['schema']['description'] ?? ''));
            $lines[] = '- ' . $name . ($desc !== '' ? ': ' . $desc : '');
        }
        $observationfull = empty($lines)
            ? 'Skill search for "' . $query . '" found no matching skills. '
                . 'Tell the user this capability is not available, or ask for clarification.'
            : 'Skill search for "' . $query . '" found these capabilities. You MUST select ONE of them as a '
                . 'skill_call in your next step — they are valid, registered, executable skills. Do NOT tell the '
                . 'user the capability is unavailable, and do NOT ask the user to perform it manually:'
                . "\n" . implode("\n", $lines);

        return [
            'status' => 'executed',
            'message' => 'Successfully discovered relevant skills.',
            'query' => $query,
            'discovered_skills' => $discovered,
            'observation_full' => $observationfull,
            // Instructional engine text built from registry descriptions — exempt
            // from privacy anonymization (masking instructions corrupts them and
            // made the planner emit non-registered skills, threads 286/288).
            'observation_engine_static' => true,
        ];
    }
}
