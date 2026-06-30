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

namespace bookingextension_agent\local\wizard\benchmark;

use bookingextension_agent\local\wizard\config\runtime_feature_flags;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\skill_registry_factory;
use core\di;
use core_ai\manager as ai_manager;

/**
 * Runs a benchmark scenario set and persists the result, independent of the
 * caller (CLI runner or the "run from interface" adhoc task).
 *
 * This is the wiring + scenario loop, factored out so the interface "run" path
 * (run_benchmark_adhoc) can reuse it. It MIRRORS cli/benchmark_runner.php, which
 * remains the canonical reference; the two are intentionally kept behaviourally
 * identical (the CLI was left untouched to avoid any risk to the CI runner — a
 * follow-up can make the CLI delegate here to remove the duplication). Output is
 * surfaced via the optional progress callback (cli_writeln for CLI, mtrace for the task).
 *
 * Provider resolution is the SAME as production: when BOOKING_TEST_AI_KEY is set
 * the benchmark_envkey_manager is injected to apply BOOKING_TEST_AI_* overrides,
 * otherwise the configured provider is used exactly as everywhere else. See
 * {@see benchmark_provider_preview} for the human-readable "what will be used".
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class benchmark_run_service {
    /**
     * Run a benchmark and persist it.
     *
     * @param array $options Keys (all optional, CLI-compatible): scenario-set, model, label, env,
     *                       git-ref, stub, pin-baseline, baseline-label, cmid, userid, tier.
     * @param callable|null $progress Optional callback invoked with a single string per progress line.
     * @return array Summary: runid, total, passed, failed, success_rate, duration_ms, regression, label.
     */
    public function run(array $options = [], ?callable $progress = null): array {
        global $DB;

        $emit = static function (string $line) use ($progress): void {
            if ($progress !== null) {
                $progress($line);
            }
        };

        $setname   = (string)($options['scenario-set'] ?? 'decision_core');
        $envmodel  = trim((string)(getenv('BOOKING_TEST_AI_MODEL') ?: ''));
        $defaultmodel = (string)(get_config('bookingextension_agent', 'default_model') ?: 'unknown');
        $modelid   = (string)(($options['model'] ?? '') ?: ($envmodel ?: $defaultmodel));
        $label     = (string)(($options['label'] ?? '') ?: date('Y-m-d H:i') . ' ' . $setname);
        $env       = (string)($options['env'] ?? 'local');
        $gitref    = (string)($options['git-ref'] ?? '');
        $usestub   = (bool)($options['stub'] ?? false);
        $pinbase   = (bool)($options['pin-baseline'] ?? false);
        $baselabel = (string)(($options['baseline-label'] ?? '') ?: $label);
        $benchcmid   = (int)($options['cmid'] ?? 25);
        $benchuserid = (int)($options['userid'] ?? 2);
        $tier        = (string)($options['tier'] ?? 'probabilistic');
        $providerinstanceid = (int)($options['provider_instance_id'] ?? 0);

        // Record whether family/skill embeddings are live for this run (vs keyword-only routing), so a
        // run's score is attributable to its routing mode. This is the same flag the orchestrator's
        // discovery path reads, captured at run time.
        $embeddingsused = runtime_feature_flags::is_enabled(runtime_feature_flags::FAMILY_EMBEDDINGS_ENABLED) ? 1 : 0;

        $registry  = new benchmark_scenario_registry();
        $collector = new benchmark_result_collector();
        $metrics   = new benchmark_metrics_calculator();
        $dbwriter  = new benchmark_db_writer();

        // Choose the AI provider for the run (process-local DI override, no DB writes). Precedence:
        // 1. an explicitly chosen provider instance (from the interface) — pins every action to it;
        // 2. else BOOKING_TEST_AI_KEY env vars (CLI/CI only — web/cron never sees them);
        // 3. else the default configured provider.
        $envkey = trim((string)(getenv('BOOKING_TEST_AI_KEY') ?: ''));
        if (!$usestub) {
            if ($providerinstanceid > 0) {
                di::set(ai_manager::class, new benchmark_instance_manager($DB, $providerinstanceid));
            } else if ($envkey !== '') {
                di::set(ai_manager::class, new benchmark_envkey_manager($DB));
            }
        }

        $store = null;
        $orc   = null;
        if (!$usestub) {
            $skillregistry = skill_registry_factory::get_default();
            $store         = new conversation_store();
            $orc           = new orchestrator($skillregistry, new interpreter($skillregistry), $store);
        }

        $allscenarios = $registry->get_scenarios($setname);

        // Tier filter (BENCHMARK_REDESIGN.md): the live benchmark measures only model-dependent routing
        // (probabilistic). Deterministic contract behaviour belongs in PHPUnit unless explicitly asked.
        $scenarios = [];
        $excluded  = [];
        foreach ($allscenarios as $sc) {
            $sctier = method_exists($sc, 'get_tier') ? (string)$sc->get_tier() : 'probabilistic';
            if ($tier === 'all' || $sctier === $tier) {
                $scenarios[] = $sc;
            } else {
                $excluded[] = $sc->get_key() . ' (' . $sctier . ')';
            }
        }
        $scenarios = array_values($scenarios);
        $total     = count($scenarios);
        $emit('Tier: ' . $tier . ' | scenarios: ' . $total
            . (empty($excluded) ? '' : ' | excluded ' . count($excluded) . ': ' . implode(', ', $excluded)));

        $runstart        = microtime(true);
        $scenarioresults = [];
        $totaltokens     = 0;

        foreach ($scenarios as $i => $scenario) {
            $idx = $i + 1;
            $key = $scenario->get_key();
            $t0  = microtime(true);

            if ($usestub) {
                $stub = $scenario->get_stub_selector_response();
                if ($stub === '') {
                    $stub = json_encode([
                        'response_type'    => $scenario->get_expected_response_type() ?: 'clarification',
                        'commands'         => [],
                        'planned_steps'    => [],
                        'next_step_intent' => '',
                        'used_triggers'    => [],
                        'lang'             => 'de',
                        'user_lang'        => 'de',
                        'message'          => 'stub',
                    ]);
                }
                $rawresponse      = $stub;
                $tokensprompt     = 0;
                $tokenscompletion = 0;
            } else {
                try {
                    $contextid = (int)\context_module::instance($benchcmid)->id;
                    $thread    = $store->create_fresh_thread($benchuserid, $contextid, 0);
                    $threadid  = (int)$thread->id;

                    foreach ($scenario->get_prior_messages() as $msg) {
                        $store->add_message($threadid, (string)($msg['role'] ?? 'user'), (string)($msg['content'] ?? ''));
                    }
                    if (method_exists($scenario, 'setup_state')) {
                        $scenario->setup_state($store, $threadid, $contextid, $benchuserid);
                    }
                    $store->add_message($threadid, 'user', $scenario->get_user_message());

                    $orc->process($threadid, $benchcmid, $benchuserid);

                    $logrow = $DB->get_record_sql(
                        "SELECT requesttext, responsetext FROM {bx_agent_ai_llm_debug}
                          WHERE threadid = :tid AND source LIKE 'orc|p=sel%'
                          ORDER BY id DESC LIMIT 1",
                        ['tid' => $threadid]
                    );
                    $rawresponse      = $logrow ? trim((string)$logrow->responsetext) : '{}';
                    $tokensprompt     = $logrow ? (int)round(strlen($logrow->requesttext ?? '') / 4) : 0;
                    $tokenscompletion = $logrow ? (int)round(strlen($logrow->responsetext ?? '') / 4) : 0;

                    $DB->set_field('bx_agent_ai_threads', 'status', 'archived', ['id' => $threadid]);
                } catch (\Throwable $ex) {
                    $durationms = (int)round((microtime(true) - $t0) * 1000);
                    $emit("[{$idx}/{$total}] {$key} ... ERROR — " . $ex->getMessage());
                    $scenarioresults[] = [
                        'scenario_key'           => $key,
                        'scenario_class'         => $scenario->get_class(),
                        'passed'                 => 0,
                        'response_type_expected' => $scenario->get_expected_response_type(),
                        'response_type_actual'   => '',
                        'skill_expected'         => $scenario->get_expected_skill(),
                        'skill_selected'         => '',
                        'json_valid'             => 0,
                        'contract_compliant'     => 0,
                        'planned_steps_present'  => 0,
                        'tokens_prompt'          => 0,
                        'tokens_completion'      => 0,
                        'duration_ms'            => $durationms,
                        'step_count'             => 0,
                        'error_message'          => 'exception: ' . $ex->getMessage(),
                        'result_json'            => null,
                    ];
                    continue;
                }
            }

            $durationms   = (int)round((microtime(true) - $t0) * 1000);
            $result       = $collector->evaluate($scenario, $rawresponse, $durationms, $tokensprompt, $tokenscompletion);
            $totaltokens += $tokensprompt + $tokenscompletion;
            $scenarioresults[] = $result;

            $status = $result['passed'] ? 'PASS' : 'FAIL';
            $detail = $result['passed'] ? '' : ' — ' . ($result['error_message'] ?? '');
            $emit("[{$idx}/{$total}] {$key} ... {$status}{$detail}");
        }

        $rundurationms = (int)round((microtime(true) - $runstart) * 1000);
        $passed        = array_sum(array_column($scenarioresults, 'passed'));
        $failed        = $total - $passed;
        $rate          = $total > 0 ? round($passed / $total * 100, 2) : 0.0;
        $metricrecords = $metrics->calculate($scenarioresults);
        $metricsmap    = array_column($metricrecords, 'metric_value', 'metric_key');
        $regression    = $metrics->has_critical_regression($metricsmap);

        // Sub-metrics (BENCHMARK_REDESIGN.md §4): keep skill-routing, JSON validity and contract distinct
        // so a dip is attributable. Single-run % stays noisy — judge changes over N runs (benchmark_matrix).
        $skillscoped = 0;
        $skillhit    = 0;
        $jsonok      = 0;
        $contractok  = 0;
        foreach ($scenarioresults as $r) {
            if (!empty($r['json_valid'])) {
                $jsonok++;
            }
            if (!empty($r['contract_compliant'])) {
                $contractok++;
            }
            if ((string)($r['skill_expected'] ?? '') !== '') {
                $skillscoped++;
                if ((string)($r['skill_selected'] ?? '') === (string)$r['skill_expected']) {
                    $skillhit++;
                }
            }
        }
        $emit(str_repeat('-', 60));
        $emit("RESULTS: {$passed}/{$total} passed ({$rate}%) in {$rundurationms}ms");
        $emit("  skill-hit (scoped): {$skillhit}/{$skillscoped} | json-valid: {$jsonok}/{$total}"
            . " | contract: {$contractok}/{$total}");
        // Rate "regression" is meaningless for the binary deterministic tier (must be 4/4); skip it there.
        if ($regression && $tier !== 'deterministic') {
            $emit('WARNING: Critical metric regression detected!');
        }

        $rundata = [
            'label'               => $label,
            'model_id'            => $modelid,
            'prompt_profile'      => 'default',
            'skill_set'           => $setname,
            'total_scenarios'     => $total,
            'passed'              => $passed,
            'failed'              => $failed,
            'skipped'             => 0,
            'success_rate'        => $rate,
            'total_tokens'        => $totaltokens,
            'duration_ms'         => $rundurationms,
            'environment'         => $env,
            'git_ref'             => $gitref,
            'embeddings_used'     => $embeddingsused,
            'regression_detected' => $regression ? 1 : 0,
        ];

        $runid = $dbwriter->write_run($rundata, $scenarioresults, $metricrecords);
        $emit("Run saved: ID={$runid}");

        if ($pinbase) {
            $dbwriter->pin_baseline($runid, $baselabel, "Pinned from run {$runid}");
            $emit("Pinned as baseline: {$baselabel}");
        }

        return [
            'runid'        => $runid,
            'total'        => $total,
            'passed'       => $passed,
            'failed'       => $failed,
            'success_rate' => $rate,
            'duration_ms'  => $rundurationms,
            'regression'   => $regression,
            'label'        => $label,
            'tier'         => $tier,
            'scenario_set' => $setname,
            'embeddings_used' => (bool)$embeddingsused,
        ];
    }
}
