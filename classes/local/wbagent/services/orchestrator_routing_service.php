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

use context_module;
use core_ai\manager as ai_manager;
use core_ai\aiactions\explain_text;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use core_text;

/**
 * Routing and debug helpers for orchestrator provider/action selection.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class orchestrator_routing_service {
    /** @var string */
    private string $toolcallparse;

    /** @var string */
    private string $simpleretrieval;

    /** @var string */
    private string $finalreasoning;

    /** @var string */
    private string $finalsynthesis;

    /** @var string */
    private string $wbplanneraction;

    /** @var string */
    private string $wbreplyaction;

    /**
     * Constructor.
     *
     * @param string $toolcallparse
     * @param string $simpleretrieval
     * @param string $finalreasoning
     * @param string $finalsynthesis
     * @param string $wbplanneraction
     * @param string $wbreplyaction
     */
    public function __construct(
        string $toolcallparse,
        string $simpleretrieval,
        string $finalreasoning,
        string $finalsynthesis,
        string $wbplanneraction,
        string $wbreplyaction
    ) {
        $this->toolcallparse = $toolcallparse;
        $this->simpleretrieval = $simpleretrieval;
        $this->finalreasoning = $finalreasoning;
        $this->finalsynthesis = $finalsynthesis;
        $this->wbplanneraction = $wbplanneraction;
        $this->wbreplyaction = $wbreplyaction;
    }

    /**
     * Route to action classes by step profile for OpenAI providers, with fallback.
     *
     * @param ai_manager $manager
     * @param context_module $context
     * @param string $steptype
     * @return array{actionclass:string, routepolicy:string, routingfallback:bool}
     */
    public function resolve_action_class_for_step(ai_manager $manager, context_module $context, string $steptype): array {
        if ($this->is_wunderbyte_routing_available($manager)) {
            if ($steptype === $this->finalreasoning || $steptype === $this->finalsynthesis) {
                return [
                    'actionclass' => $this->wbreplyaction,
                    'routepolicy' => 'wunderbyte',
                    'routingfallback' => false,
                ];
            }

            return [
                'actionclass' => $this->wbplanneraction,
                'routepolicy' => 'wunderbyte',
                'routingfallback' => false,
            ];
        }

        if (!$this->should_use_openai_step_routing($manager)) {
            return [
                'actionclass' => generate_text::class,
                'routepolicy' => 'default',
                'routingfallback' => false,
            ];
        }

        if ($steptype === $this->finalreasoning || $steptype === $this->finalsynthesis) {
            if ($this->is_action_available_in_context($manager, $context, generate_text::class)) {
                return [
                    'actionclass' => generate_text::class,
                    'routepolicy' => 'openai',
                    'routingfallback' => false,
                ];
            }
            if ($this->is_action_available_in_context($manager, $context, explain_text::class)) {
                return [
                    'actionclass' => explain_text::class,
                    'routepolicy' => 'openai',
                    'routingfallback' => true,
                ];
            }
            return [
                'actionclass' => generate_text::class,
                'routepolicy' => 'openai',
                'routingfallback' => true,
            ];
        }

        if ($this->is_action_available_in_context($manager, $context, summarise_text::class)) {
            return [
                'actionclass' => summarise_text::class,
                'routepolicy' => 'openai',
                'routingfallback' => false,
            ];
        }

        return [
            'actionclass' => generate_text::class,
            'routepolicy' => 'openai',
            'routingfallback' => true,
        ];
    }

    /**
     * Check action availability with context and global provider state.
     *
     * @param ai_manager $manager
     * @param context_module $context
     * @param string $actionclass
     * @return bool
     */
    public function is_action_available_in_context(ai_manager $manager, context_module $context, string $actionclass): bool {
        if (!$manager->is_action_available($actionclass)) {
            return false;
        }
        if (!method_exists($manager, 'is_action_enabled_in_context')) {
            return true;
        }
        return $manager->is_action_enabled_in_context($context, $actionclass);
    }

    /**
     * Build compact orchestrator telemetry in source field.
     *
     * @param string $steptype
     * @param string $actionclass
     * @param string $routepolicy
     * @param bool $routingfallback
     * @param string $primaryprovider
     * @param int $historycount
     * @param int $observationcount
     * @param string $catalogselectionmode
     * @param string $embeddingstatus
     * @param int $catalogsize
     * @param bool $embeddingrebuildqueued
     * @param bool $exception
     * @return string
     */
    public function build_debug_source(
        string $steptype,
        string $actionclass,
        string $routepolicy,
        bool $routingfallback,
        string $primaryprovider,
        int $historycount,
        int $observationcount,
        string $catalogselectionmode,
        string $embeddingstatus,
        int $catalogsize,
        bool $embeddingrebuildqueued,
        bool $exception
    ): string {
        $stepmap = [
            $this->toolcallparse => 'tcp',
            $this->simpleretrieval => 'sr',
            $this->finalreasoning => 'fr',
            $this->finalsynthesis => 'syn',
        ];
        $actionmap = [
            generate_text::class => 'gen',
            summarise_text::class => 'sum',
            explain_text::class => 'exp',
            $this->wbplanneraction => 'wpl',
            $this->wbreplyaction => 'wgr',
        ];

        $step = $stepmap[$steptype] ?? 'unk';
        $action = $actionmap[$actionclass] ?? 'oth';
        $route = 'df';
        if ($routepolicy === 'openai') {
            $route = 'oa';
        } else if ($routepolicy === 'wunderbyte') {
            $route = 'wb';
        }
        $provider = provider_routing_util::short_provider_for_debug($primaryprovider);

        $source = 'orc'
            . '|st=' . $step
            . '|ac=' . $action
            . '|rt=' . $route
            . '|fb=' . ($routingfallback ? '1' : '0')
            . '|pv=' . $provider
            . '|hm=' . max(0, $historycount)
            . '|ob=' . max(0, $observationcount)
            . '|cm=' . $this->short_debug_token($catalogselectionmode)
            . '|em=' . $this->short_debug_token($embeddingstatus)
            . '|tk=' . max(0, $catalogsize)
            . '|rq=' . ($embeddingrebuildqueued ? '1' : '0')
            . '|ex=' . ($exception ? '1' : '0');

        if (core_text::strlen($source) > 100) {
            return core_text::substr($source, 0, 100);
        }

        return $source;
    }

    /**
     * Use step-based action routing only when OpenAI provider is active for text actions.
     *
     * @param ai_manager $manager
     * @return bool
     */
    private function should_use_openai_step_routing(ai_manager $manager): bool {
        try {
            $providers = $manager->get_providers_for_actions([generate_text::class], true);
            $forgenerate = (array)($providers[generate_text::class] ?? []);
            if (empty($forgenerate)) {
                return false;
            }
            $primary = reset($forgenerate);
            return (string)($primary->provider ?? '') === 'aiprovider_openai';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Determine whether wunderbyte-specific action routing is available.
     *
     * @param ai_manager $manager
     * @return bool
     */
    private function is_wunderbyte_routing_available(ai_manager $manager): bool {
        try {
            $instances = $manager->get_provider_instances(['provider' => 'aiprovider_wunderbyte\\provider']);
            if (empty($instances)) {
                return false;
            }

            foreach ($instances as $instance) {
                if (empty($instance->enabled)) {
                    continue;
                }

                if (method_exists($instance, 'is_provider_configured') && !$instance->is_provider_configured()) {
                    continue;
                }

                return true;
            }

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Keep debug token values compact and stable.
     *
     * @param string $value
     * @return string
     */
    private function short_debug_token(string $value): string {
        $normalized = preg_replace('/[^a-z0-9_\-]+/i', '', core_text::strtolower(trim($value)));
        if (!is_string($normalized) || $normalized === '') {
            return 'na';
        }

        if (core_text::strlen($normalized) > 10) {
            return core_text::substr($normalized, 0, 10);
        }

        return $normalized;
    }
}
