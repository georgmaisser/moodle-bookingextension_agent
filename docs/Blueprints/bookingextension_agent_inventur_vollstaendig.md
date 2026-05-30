# Vollstaendige Inventur: bookingextension_agent

- Erfassungsdatum: 2026-05-30
- Plugin-Root: /var/www/moodle/public/mod/booking/bookingextension/agent
- Anzahl Ordner: 55
- Anzahl Dateien: 169
- Anzahl PHP-Methoden/Funktionen (benannte Deklarationen): 1020

## Ordnerstruktur (vollstaendig)

```text
.
./amd
./amd/build
./amd/src
./classes
./classes/external
./classes/local
./classes/local/wbagent
./classes/local/wbagent/config
./classes/local/wbagent/core
./classes/local/wbagent/core/tasks
./classes/local/wbagent/dto
./classes/local/wbagent/examples
./classes/local/wbagent/examples/tasks
./classes/local/wbagent/interfaces
./classes/local/wbagent/interfaces/summarizer
./classes/local/wbagent/prompts
./classes/local/wbagent/queue
./classes/local/wbagent/services
./classes/local/wbagent/services/lookup
./classes/local/wbagent/services/mutation
./classes/local/wbagent/summarizer
./classes/task
./cli
./cli/mod
./cli/mod/booking
./cli/mod/booking/bookingextension
./cli/mod/booking/bookingextension/agent
./cli/mod/booking/bookingextension/agent/tests
./cli/mod/booking/bookingextension/agent/tests/fixtures
./cli/public
./cli/public/mod
./cli/public/mod/booking
./cli/public/mod/booking/bookingextension
./cli/public/mod/booking/bookingextension/agent
./cli/public/mod/booking/bookingextension/agent/tests
./cli/public/mod/booking/bookingextension/agent/tests/agent
./cli/public/mod/booking/bookingextension/agent/tests/agent/fixtures
./db
./docs
./docs/Blueprints
./docs/Blueprints/flowcharts
./docs/Blueprints/pix
./lang
./lang/de
./lang/en
./templates
./tests
./tests/agent
./tests/agent/contracts
./tests/agent/embedded_llm
./tests/agent/embedded_llm/fixtures
./tests/agent/fixtures
./tests/agent/real_llm_multistep
./tests/fixtures
```

## Dateiliste (vollstaendig)

```text
./amd/build/aiinstructions.min.js
./amd/build/aiinstructions.min.js.map
./amd/src/aiinstructions.js
./classes/agent.php
./classes/external/activate_trial_context.php
./classes/external/ai_confirm_run.php
./classes/external/ai_get_doc_content.php
./classes/external/ai_get_thread_debug_logs.php
./classes/external/ai_list_candidate_options.php
./classes/external/ai_poll_thread.php
./classes/external/ai_privacy_precheck.php
./classes/external/ai_render_command_preview.php
./classes/external/ai_send_message.php
./classes/external/booking_bulk_update_options.php
./classes/external/booking_create_option.php
./classes/external/booking_update_option.php
./classes/external/booking_validate_option.php
./classes/external/request_trial_key.php
./classes/external/ws_message_formatter.php
./classes/local/wbagent/adaptive_task_catalog_service.php
./classes/local/wbagent/agent_decision_service.php
./classes/local/wbagent/agent_runtime.php
./classes/local/wbagent/agent_state.php
./classes/local/wbagent/ai_error_classifier.php
./classes/local/wbagent/aiready.php
./classes/local/wbagent/authorization_service.php
./classes/local/wbagent/base_task.php
./classes/local/wbagent/booking_issue_code_provider.php
./classes/local/wbagent/config/command_schema.json
./classes/local/wbagent/conversation_store.php
./classes/local/wbagent/core/tasks/core_task_base.php
./classes/local/wbagent/core/tasks/get_current_user_task.php
./classes/local/wbagent/core/tasks/list_actions_task.php
./classes/local/wbagent/core/tasks/recall_memory_task.php
./classes/local/wbagent/core/tasks/recreate_task_catalog_task.php
./classes/local/wbagent/core/tasks/search_courses_task.php
./classes/local/wbagent/core/tasks/search_users_task.php
./classes/local/wbagent/dto/bulk_update_options_input_dto.php
./classes/local/wbagent/dto/create_entity_input_dto.php
./classes/local/wbagent/dto/create_option_input_dto.php
./classes/local/wbagent/dto/mutation_result_dto.php
./classes/local/wbagent/dto/update_option_input_dto.php
./classes/local/wbagent/embeddings_action_config_resolver.php
./classes/local/wbagent/embeddings_catalog_builder_service.php
./classes/local/wbagent/embeddings_csv_repository.php
./classes/local/wbagent/embeddings_readiness_service.php
./classes/local/wbagent/embeddings_retrieval_service.php
./classes/local/wbagent/execution_feedback_service.php
./classes/local/wbagent/executor.php
./classes/local/wbagent/interfaces/agent_authorization_service.php
./classes/local/wbagent/interfaces/agent_conversation_store.php
./classes/local/wbagent/interfaces/agent_executor.php
./classes/local/wbagent/interfaces/agent_interpreter.php
./classes/local/wbagent/interfaces/issue_code_provider_interface.php
./classes/local/wbagent/interfaces/preview_option_memory_interface.php
./classes/local/wbagent/interfaces/preview_option_memory_provider_interface.php
./classes/local/wbagent/interfaces/queue_identity_provider_interface.php
./classes/local/wbagent/interfaces/result_summary_provider_interface.php
./classes/local/wbagent/interfaces/summarizer/result_summary_contributor_interface.php
./classes/local/wbagent/interfaces/task_input_normalizer_interface.php
./classes/local/wbagent/interfaces/task_input_normalizer_provider_interface.php
./classes/local/wbagent/interfaces/task_interface.php
./classes/local/wbagent/interfaces/task_provider_interface.php
./classes/local/wbagent/interfaces/task_result_summary_provider_interface.php
./classes/local/wbagent/interfaces/task_trigger_provider_interface.php
./classes/local/wbagent/interpreter.php
./classes/local/wbagent/llm_call_service.php
./classes/local/wbagent/llm_debug_logger.php
./classes/local/wbagent/loop_finalizer.php
./classes/local/wbagent/message_persistence_service.php
./classes/local/wbagent/message_trigger_registry.php
./classes/local/wbagent/orchestrator.php
./classes/local/wbagent/planner_service.php
./classes/local/wbagent/preview_policy.php
./classes/local/wbagent/privacy_anonymizer.php
./classes/local/wbagent/prompt_policy_builder.php
./classes/local/wbagent/prompts/initial_system_prompt.md
./classes/local/wbagent/queue/observation_builder.php
./classes/local/wbagent/queue/queue_manager.php
./classes/local/wbagent/result_payload_summarizer.php
./classes/local/wbagent/services/assistant_state_guidance_service.php
./classes/local/wbagent/services/completed_command_history_service.php
./classes/local/wbagent/services/confirm_preview_option_service.php
./classes/local/wbagent/services/confirm_run_service.php
./classes/local/wbagent/services/execution_observation_ledger.php
./classes/local/wbagent/services/language_policy_service.php
./classes/local/wbagent/services/localized_string_service.php
./classes/local/wbagent/services/lookup/docs_lookup_service.php
./classes/local/wbagent/services/lookup/option_lookup_service.php
./classes/local/wbagent/services/mutation/entity_mutation_service.php
./classes/local/wbagent/services/mutation/option_mutation_service.php
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php
./classes/local/wbagent/services/orchestrator_routing_service.php
./classes/local/wbagent/services/pending_intent_service.php
./classes/local/wbagent/services/pending_queue_command_service.php
./classes/local/wbagent/services/preflight_audit_logger.php
./classes/local/wbagent/services/preflight_contract_validator.php
./classes/local/wbagent/services/preflight_domain_check_runner.php
./classes/local/wbagent/services/preflight_error_classifier.php
./classes/local/wbagent/services/preflight_execution_gate.php
./classes/local/wbagent/services/preflight_pipeline.php
./classes/local/wbagent/services/preflight_result_v2.php
./classes/local/wbagent/services/preflight_schema_validator.php
./classes/local/wbagent/services/preflight_version_validator.php
./classes/local/wbagent/services/provider_routing_util.php
./classes/local/wbagent/services/queue_command_mapper.php
./classes/local/wbagent/services/queue_status_policy.php
./classes/local/wbagent/services/queue_transition_service.php
./classes/local/wbagent/services/runtime_step_analysis_service.php
./classes/local/wbagent/services/runtime_synthesis_policy_service.php
./classes/local/wbagent/services/shared_json_payload_extractor.php
./classes/local/wbagent/services/spawn_contract_service.php
./classes/local/wbagent/services/task_prompt_contract.php
./classes/local/wbagent/services/task_version_policy.php
./classes/local/wbagent/services/trigger_result_util.php
./classes/local/wbagent/summarizer/basic_collection_result_summary_contributor.php
./classes/local/wbagent/summarizer/diagnosis_result_summary_contributor.php
./classes/local/wbagent/summarizer/docs_result_summary_contributor.php
./classes/local/wbagent/summarizer/single_object_result_summary_contributor.php
./classes/local/wbagent/task_contract_validator.php
./classes/local/wbagent/task_discovery.php
./classes/local/wbagent/task_executability_evaluator.php
./classes/local/wbagent/task_governance_service.php
./classes/local/wbagent/task_provider.php
./classes/local/wbagent/task_registry.php
./classes/local/wbagent/task_registry_factory.php
./classes/local/wbagent/wunderbyte_trial_endpoint.py
./classes/task/execute_ai_run_adhoc.php
./classes/task/rebuild_task_catalog_embeddings_adhoc.php
./cli/rebuild_embeddings_fixture.php
./db/access.php
./db/caches.php
./db/install.xml
./db/services.php
./db/upgrade.php
./docs/Blueprints/bookingextension_agent_inventur_vollstaendig.md
./docs/Blueprints/bookingextension_agent_konsolidierung_checkliste_vollstaendig.md
./docs/Blueprints/bookingextension_agent_konsolidierung_zwischenstand_2026-05-30.md
./docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd
./lang/de/bookingextension_agent.php
./lang/en/bookingextension_agent.php
./lib.php
./settings.php
./styles.css
./templates/aiinstructions.mustache
./tests/agent/abstract_agent_testcase.php
./tests/agent/abstract_llm_task_matrix_testcase.php
./tests/agent/contracts/ai_confirm_run_contract_test.php
./tests/agent/contracts/integration_agent_framework_test.php
./tests/agent/contracts/mod_booking_option_tasks_contract_test.php
./tests/agent/contracts/pending_intent_and_queue_transition_contract_test.php
./tests/agent/contracts/preflight_contract_validator_contract_test.php
./tests/agent/contracts/preflight_layers_contract_test.php
./tests/agent/contracts/prompt_and_language_contract_test.php
./tests/agent/contracts/queue_consolidation_contract_test.php
./tests/agent/contracts/reference_scenarios_contract_test.php
./tests/agent/contracts/spawn_contract_service_test.php
./tests/agent/contracts/task_contract_validator_contract_test.php
./tests/agent/fixtures/task_catalog_embeddings.csv
./tests/agent/llm_task_matrix_scenario_provider.php
./tests/agent/real_llm_multistep/all_tasks_real_llm_test.php
./tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php
./tests/agent/real_llm_multistep/get_current_user_real_llm_test.php
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php
./tests/agent/real_llm_multistep/list_actions_real_llm_test.php
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php
./tests/agent/real_llm_multistep/search_users_real_llm_test.php
./trial_challenge.php
./version.php
```

## Methodeninventur (vollstaendig, PHP)

Format: `datei:zeile<TAB>kontext<TAB>methode`

```text
./classes/agent.php:35	bookingextension_agent\agent	get_plugin_name
./classes/agent.php:44	bookingextension_agent\agent	contains_option_fields
./classes/agent.php:53	bookingextension_agent\agent	get_option_fields_info_array
./classes/agent.php:65	bookingextension_agent\agent	load_settings
./classes/agent.php:77	bookingextension_agent\agent	load_data_for_settings_singleton
./classes/agent.php:87	bookingextension_agent\agent	set_template_data_for_optionview
./classes/agent.php:98	bookingextension_agent\agent	add_options_to_col_actions
./classes/agent.php:107	bookingextension_agent\agent	get_allowedruleeventkeys
./classes/agent.php:118	bookingextension_agent\agent	get_booking_history_description
./classes/external/activate_trial_context.php:172	bookingextension_agent\external\activate_trial_context	execute_parameters
./classes/external/activate_trial_context.php:184	bookingextension_agent\external\activate_trial_context	execute
./classes/external/activate_trial_context.php:244	bookingextension_agent\external\activate_trial_context	execute_returns
./classes/external/ai_confirm_run.php:309	bookingextension_agent\external\ai_confirm_run	execute_parameters
./classes/external/ai_confirm_run.php:332	bookingextension_agent\external\ai_confirm_run	execute
./classes/external/ai_confirm_run.php:409	bookingextension_agent\external\ai_confirm_run	execute_returns
./classes/external/ai_get_doc_content.php:490	bookingextension_agent\external\ai_get_doc_content	execute_parameters
./classes/external/ai_get_doc_content.php:504	bookingextension_agent\external\ai_get_doc_content	execute
./classes/external/ai_get_doc_content.php:560	bookingextension_agent\external\ai_get_doc_content	execute_returns
./classes/external/ai_get_doc_content.php:591	bookingextension_agent\external\ai_get_doc_content	markdown_to_html
./classes/external/ai_get_doc_content.php:724	bookingextension_agent\external\ai_get_doc_content	inline_format
./classes/external/ai_get_doc_content.php:790	bookingextension_agent\external\ai_get_doc_content	resolve_internal_doc_link
./classes/external/ai_get_doc_content.php:831	bookingextension_agent\external\ai_get_doc_content	normalize_relative_docs_path
./classes/external/ai_get_doc_content.php:867	bookingextension_agent\external\ai_get_doc_content	format_non_doc_link
./classes/external/ai_get_doc_content.php:920	bookingextension_agent\external\ai_get_doc_content	build_moodle_url_from_parts
./classes/external/ai_get_thread_debug_logs.php:983	bookingextension_agent\external\ai_get_thread_debug_logs	execute_parameters
./classes/external/ai_get_thread_debug_logs.php:999	bookingextension_agent\external\ai_get_thread_debug_logs	execute
./classes/external/ai_get_thread_debug_logs.php:1069	bookingextension_agent\external\ai_get_thread_debug_logs	execute_returns
./classes/external/ai_list_candidate_options.php:1132	bookingextension_agent\external\ai_list_candidate_options	execute_parameters
./classes/external/ai_list_candidate_options.php:1146	bookingextension_agent\external\ai_list_candidate_options	execute
./classes/external/ai_list_candidate_options.php:1202	bookingextension_agent\external\ai_list_candidate_options	execute_returns
./classes/external/ai_poll_thread.php:1268	bookingextension_agent\external\ai_poll_thread	execute_parameters
./classes/external/ai_poll_thread.php:1282	bookingextension_agent\external\ai_poll_thread	execute
./classes/external/ai_poll_thread.php:1337	bookingextension_agent\external\ai_poll_thread	execute_returns
./classes/external/ai_privacy_precheck.php:1399	bookingextension_agent\external\ai_privacy_precheck	execute_parameters
./classes/external/ai_privacy_precheck.php:1420	bookingextension_agent\external\ai_privacy_precheck	execute
./classes/external/ai_privacy_precheck.php:1504	bookingextension_agent\external\ai_privacy_precheck	execute_returns
./classes/external/ai_render_command_preview.php:1572	bookingextension_agent\external\ai_render_command_preview	execute_parameters
./classes/external/ai_render_command_preview.php:1614	bookingextension_agent\external\ai_render_command_preview	execute
./classes/external/ai_render_command_preview.php:1887	bookingextension_agent\external\ai_render_command_preview	render_preview_table
./classes/external/ai_render_command_preview.php:1951	bookingextension_agent\external\ai_render_command_preview	execute_returns
./classes/external/ai_send_message.php:2025	bookingextension_agent\external\ai_send_message	execute_parameters
./classes/external/ai_send_message.php:2046	bookingextension_agent\external\ai_send_message	execute
./classes/external/ai_send_message.php:2228	bookingextension_agent\external\ai_send_message	normalize_string_list
./classes/external/ai_send_message.php:2251	bookingextension_agent\external\ai_send_message	resolve_response_queue_item_id
./classes/external/ai_send_message.php:2271	bookingextension_agent\external\ai_send_message	resolve_response_commands
./classes/external/ai_send_message.php:2321	bookingextension_agent\external\ai_send_message	resolve_preview_option_ids_json_for_response
./classes/external/ai_send_message.php:2369	bookingextension_agent\external\ai_send_message	resolve_preview_option_id_for_response
./classes/external/ai_send_message.php:2412	bookingextension_agent\external\ai_send_message	execute_returns
./classes/external/booking_bulk_update_options.php:2496	bookingextension_agent\external\booking_bulk_update_options	execute_parameters
./classes/external/booking_bulk_update_options.php:2520	bookingextension_agent\external\booking_bulk_update_options	execute
./classes/external/booking_bulk_update_options.php:2589	bookingextension_agent\external\booking_bulk_update_options	execute_returns
./classes/external/booking_create_option.php:2656	bookingextension_agent\external\booking_create_option	execute_parameters
./classes/external/booking_create_option.php:2677	bookingextension_agent\external\booking_create_option	execute
./classes/external/booking_create_option.php:2751	bookingextension_agent\external\booking_create_option	execute_returns
./classes/external/booking_update_option.php:2818	bookingextension_agent\external\booking_update_option	execute_parameters
./classes/external/booking_update_option.php:2842	bookingextension_agent\external\booking_update_option	execute
./classes/external/booking_update_option.php:2911	bookingextension_agent\external\booking_update_option	execute_returns
./classes/external/booking_validate_option.php:2982	bookingextension_agent\external\booking_validate_option	execute_parameters
./classes/external/booking_validate_option.php:2998	bookingextension_agent\external\booking_validate_option	execute
./classes/external/booking_validate_option.php:3061	bookingextension_agent\external\booking_validate_option	execute_returns
./classes/external/request_trial_key.php:3126	bookingextension_agent\external\request_trial_key	execute_parameters
./classes/external/request_trial_key.php:3138	bookingextension_agent\external\request_trial_key	execute
./classes/external/request_trial_key.php:3183	bookingextension_agent\external\request_trial_key	execute_returns
./classes/external/ws_message_formatter.php:3227	bookingextension_agent\external\ws_message_formatter	format_ws_message
./classes/local/wbagent/adaptive_task_catalog_service.php:3308	bookingextension_agent\local\wbagent\adaptive_task_catalog_service	get_adaptive_catalog
./classes/local/wbagent/adaptive_task_catalog_service.php:3348	bookingextension_agent\local\wbagent\adaptive_task_catalog_service	get_mandatory_tasks
./classes/local/wbagent/adaptive_task_catalog_service.php:3374	bookingextension_agent\local\wbagent\adaptive_task_catalog_service	get_recency_filtered
./classes/local/wbagent/agent_decision_service.php:3572	bookingextension_agent\local\wbagent\agent_decision_service	__construct
./classes/local/wbagent/agent_decision_service.php:3608	bookingextension_agent\local\wbagent\agent_decision_service	process
./classes/local/wbagent/agent_decision_service.php:3786	bookingextension_agent\local\wbagent\agent_decision_service	should_block_new_intent_while_pending
./classes/local/wbagent/agent_decision_service.php:3816	bookingextension_agent\local\wbagent\agent_decision_service	build_pending_resolution_clarification
./classes/local/wbagent/agent_decision_service.php:3858	bookingextension_agent\local\wbagent\agent_decision_service	build_pending_intent_summary
./classes/local/wbagent/agent_decision_service.php:3880	bookingextension_agent\local\wbagent\agent_decision_service	enforce_task_boundary_invariants
./classes/local/wbagent/agent_decision_service.php:3905	bookingextension_agent\local\wbagent\agent_decision_service	enforce_response_contract_invariants
./classes/local/wbagent/agent_decision_service.php:3947	bookingextension_agent\local\wbagent\agent_decision_service	normalize_commands_for_contract_recovery
./classes/local/wbagent/agent_decision_service.php:3980	bookingextension_agent\local\wbagent\agent_decision_service	refresh_contract_command_fallback
./classes/local/wbagent/agent_decision_service.php:4000	bookingextension_agent\local\wbagent\agent_decision_service	build_fallback_message
./classes/local/wbagent/agent_decision_service.php:4052	bookingextension_agent\local\wbagent\agent_decision_service	handle_confirm_pending
./classes/local/wbagent/agent_decision_service.php:4172	bookingextension_agent\local\wbagent\agent_decision_service	handle_command_routing
./classes/local/wbagent/agent_decision_service.php:4357	bookingextension_agent\local\wbagent\agent_decision_service	find_missing_option_anchor_readonly_task
./classes/local/wbagent/agent_decision_service.php:4403	bookingextension_agent\local\wbagent\agent_decision_service	enrich_readonly_commands_with_planner
./classes/local/wbagent/agent_decision_service.php:4455	bookingextension_agent\local\wbagent\agent_decision_service	enrich_option_anchor_inputs
./classes/local/wbagent/agent_decision_service.php:4537	bookingextension_agent\local\wbagent\agent_decision_service	handle_preflight
./classes/local/wbagent/agent_decision_service.php:4759	bookingextension_agent\local\wbagent\agent_decision_service	apply_confirmable_overrides
./classes/local/wbagent/agent_decision_service.php:4802	bookingextension_agent\local\wbagent\agent_decision_service	apply_execution_guard_tokens
./classes/local/wbagent/agent_decision_service.php:4844	bookingextension_agent\local\wbagent\agent_decision_service	execute_readonly_commands
./classes/local/wbagent/agent_decision_service.php:5055	bookingextension_agent\local\wbagent\agent_decision_service	inject_output_language_into_commands
./classes/local/wbagent/agent_decision_service.php:5081	bookingextension_agent\local\wbagent\agent_decision_service	with_output_language
./classes/local/wbagent/agent_decision_service.php:5113	bookingextension_agent\local\wbagent\agent_decision_service	has_mutating_commands
./classes/local/wbagent/agent_decision_service.php:5138	bookingextension_agent\local\wbagent\agent_decision_service	split_commands_by_mutability
./classes/local/wbagent/agent_decision_service.php:5164	bookingextension_agent\local\wbagent\agent_decision_service	execution_result_has_failures
./classes/local/wbagent/agent_decision_service.php:5190	bookingextension_agent\local\wbagent\agent_decision_service	has_confirmable_prevalidation_issues
./classes/local/wbagent/agent_decision_service.php:5211	bookingextension_agent\local\wbagent\agent_decision_service	has_recent_duplicate_title_prompt
./classes/local/wbagent/agent_decision_service.php:5249	bookingextension_agent\local\wbagent\agent_decision_service	apply_duplicate_title_override
./classes/local/wbagent/agent_decision_service.php:5297	bookingextension_agent\local\wbagent\agent_decision_service	augment_missing_teacher_autocreate_confirmation
./classes/local/wbagent/agent_decision_service.php:5367	bookingextension_agent\local\wbagent\agent_decision_service	resolve_task_name_by_suffix
./classes/local/wbagent/agent_decision_service.php:5395	bookingextension_agent\local\wbagent\agent_decision_service	get_last_user_message
./classes/local/wbagent/agent_decision_service.php:5411	bookingextension_agent\local\wbagent\agent_decision_service	extract_option_id_from_message
./classes/local/wbagent/agent_decision_service.php:5441	bookingextension_agent\local\wbagent\agent_decision_service	clarification_result
./classes/local/wbagent/agent_decision_service.php:5466	bookingextension_agent\local\wbagent\agent_decision_service	clarification_result_with_context
./classes/local/wbagent/agent_decision_service.php:5492	bookingextension_agent\local\wbagent\agent_decision_service	build_confirm_pending_no_intent_fallback
./classes/local/wbagent/agent_decision_service.php:5523	bookingextension_agent\local\wbagent\agent_decision_service	localized
./classes/local/wbagent/agent_decision_service.php:5533	bookingextension_agent\local\wbagent\agent_decision_service	normalize_queue_item_ids
./classes/local/wbagent/agent_runtime.php:5710	bookingextension_agent\local\wbagent\agent_runtime	__construct
./classes/local/wbagent/agent_runtime.php:5765	bookingextension_agent\local\wbagent\agent_runtime	run
./classes/local/wbagent/agent_runtime.php:5789	bookingextension_agent\local\wbagent\agent_runtime	budget_guard_allows_next_llm_call
./classes/local/wbagent/agent_runtime.php:5802	bookingextension_agent\local\wbagent\agent_runtime	build_budget_exceeded_result
./classes/local/wbagent/agent_runtime.php:5843	bookingextension_agent\local\wbagent\agent_runtime	refresh_pending_queue_retry_state
./classes/local/wbagent/agent_runtime.php:5920	bookingextension_agent\local\wbagent\agent_runtime	run_loop
./classes/local/wbagent/agent_runtime.php:6238	bookingextension_agent\local\wbagent\agent_runtime	is_readonly_signature_budget_reached
./classes/local/wbagent/agent_runtime.php:6258	bookingextension_agent\local\wbagent\agent_runtime	enforce_final_response_contract
./classes/local/wbagent/agent_runtime.php:6333	bookingextension_agent\local\wbagent\agent_runtime	normalize_iso_language
./classes/local/wbagent/agent_runtime.php:6343	bookingextension_agent\local\wbagent\agent_runtime	strip_markdown_fences_from_message
./classes/local/wbagent/agent_runtime.php:6365	bookingextension_agent\local\wbagent\agent_runtime	build_contract_fallback_message
./classes/local/wbagent/agent_runtime.php:6387	bookingextension_agent\local\wbagent\agent_runtime	attach_loop_results
./classes/local/wbagent/agent_runtime.php:6462	bookingextension_agent\local\wbagent\agent_runtime	loop_state_contains_only_readonly_results
./classes/local/wbagent/agent_runtime.php:6490	bookingextension_agent\local\wbagent\agent_runtime	deduplicate_loop_results
./classes/local/wbagent/agent_runtime.php:6533	bookingextension_agent\local\wbagent\agent_runtime	score_loop_result_entry
./classes/local/wbagent/agent_runtime.php:6568	bookingextension_agent\local\wbagent\agent_runtime	has_issue_code
./classes/local/wbagent/agent_runtime.php:6588	bookingextension_agent\local\wbagent\agent_runtime	build_loop_repeat_summary
./classes/local/wbagent/agent_runtime.php:6668	bookingextension_agent\local\wbagent\agent_runtime	maybe_enrich_message_from_results
./classes/local/wbagent/agent_runtime.php:6719	bookingextension_agent\local\wbagent\agent_runtime	should_finalize_after_execution_result
./classes/local/wbagent/agent_runtime.php:6766	bookingextension_agent\local\wbagent\agent_runtime	build_sufficient_execution_result_clarification
./classes/local/wbagent/agent_runtime.php:6818	bookingextension_agent\local\wbagent\agent_runtime	should_recover_from_missing_commands_error
./classes/local/wbagent/agent_runtime.php:6846	bookingextension_agent\local\wbagent\agent_runtime	recover_missing_commands_error_result
./classes/local/wbagent/agent_runtime.php:6906	bookingextension_agent\local\wbagent\agent_runtime	should_retry_preflight_clarification
./classes/local/wbagent/agent_runtime.php:6981	bookingextension_agent\local\wbagent\agent_runtime	should_synthesize_after_success_without_pending_intent
./classes/local/wbagent/agent_runtime.php:7008	bookingextension_agent\local\wbagent\agent_runtime	build_preflight_retry_observation
./classes/local/wbagent/agent_runtime.php:7070	bookingextension_agent\local\wbagent\agent_runtime	build_retry_task_catalog_context
./classes/local/wbagent/agent_runtime.php:7116	bookingextension_agent\local\wbagent\agent_runtime	slim_retry_task_contract
./classes/local/wbagent/agent_runtime.php:7132	bookingextension_agent\local\wbagent\agent_runtime	build_preflight_fix_instructions
./classes/local/wbagent/agent_runtime.php:7180	bookingextension_agent\local\wbagent\agent_runtime	observations_are_framework_retry_hints
./classes/local/wbagent/agent_runtime.php:7204	bookingextension_agent\local\wbagent\agent_runtime	is_low_information_message
./classes/local/wbagent/agent_runtime.php:7237	bookingextension_agent\local\wbagent\agent_runtime	build_step_label
./classes/local/wbagent/agent_runtime.php:7288	bookingextension_agent\local\wbagent\agent_runtime	write_step_progress_message
./classes/local/wbagent/agent_runtime.php:7315	bookingextension_agent\local\wbagent\agent_runtime	extract_next_step_intent
./classes/local/wbagent/agent_runtime.php:7340	bookingextension_agent\local\wbagent\agent_runtime	is_repeated_readonly_step
./classes/local/wbagent/agent_runtime.php:7384	bookingextension_agent\local\wbagent\agent_runtime	run_internal
./classes/local/wbagent/agent_runtime.php:7558	bookingextension_agent\local\wbagent\agent_runtime	apply_signature_based_recall_guard
./classes/local/wbagent/agent_runtime.php:7636	bookingextension_agent\local\wbagent\agent_runtime	apply_observation_based_recall_guard
./classes/local/wbagent/agent_runtime.php:7678	bookingextension_agent\local\wbagent\agent_runtime	all_commands_match_task
./classes/local/wbagent/agent_runtime.php:7702	bookingextension_agent\local\wbagent\agent_runtime	all_commands_match_any_task
./classes/local/wbagent/agent_runtime.php:7726	bookingextension_agent\local\wbagent\agent_runtime	get_diagnosis_task_names
./classes/local/wbagent/agent_runtime.php:7754	bookingextension_agent\local\wbagent\agent_runtime	observations_include_diagnosis_result
./classes/local/wbagent/agent_runtime.php:7783	bookingextension_agent\local\wbagent\agent_runtime	apply_hard_contract_gate
./classes/local/wbagent/agent_runtime.php:7873	bookingextension_agent\local\wbagent\agent_runtime	normalize_unknown_response_type_to_contract_error
./classes/local/wbagent/agent_runtime.php:7913	bookingextension_agent\local\wbagent\agent_runtime	is_hard_contract_error
./classes/local/wbagent/agent_runtime.php:7940	bookingextension_agent\local\wbagent\agent_runtime	build_option_type_explanation_shortcut
./classes/local/wbagent/agent_runtime.php:8020	bookingextension_agent\local\wbagent\agent_runtime	assistant_prompted_for_option_type
./classes/local/wbagent/agent_runtime.php:8047	bookingextension_agent\local\wbagent\agent_runtime	call_orchestrator_step
./classes/local/wbagent/agent_runtime.php:8064	bookingextension_agent\local\wbagent\agent_runtime	resolve_output_language
./classes/local/wbagent/agent_runtime.php:8078	bookingextension_agent\local\wbagent\agent_runtime	loop_continue_result
./classes/local/wbagent/agent_runtime.php:8120	bookingextension_agent\local\wbagent\agent_runtime	run_synthesis_step
./classes/local/wbagent/agent_runtime.php:8189	bookingextension_agent\local\wbagent\agent_runtime	resolve_synthesis_user_language
./classes/local/wbagent/agent_runtime.php:8228	bookingextension_agent\local\wbagent\agent_runtime	loop_repeat_narration_result
./classes/local/wbagent/agent_runtime.php:8285	bookingextension_agent\local\wbagent\agent_runtime	normalize_final_reasoning_narration
./classes/local/wbagent/agent_runtime.php:8311	bookingextension_agent\local\wbagent\agent_runtime	is_final_clarification_without_commands
./classes/local/wbagent/agent_runtime.php:8334	bookingextension_agent\local\wbagent\agent_runtime	should_run_synthesis_for_clarification
./classes/local/wbagent/agent_runtime.php:8362	bookingextension_agent\local\wbagent\agent_runtime	build_deterministic_loop_repeat_fallback
./classes/local/wbagent/agent_runtime.php:8423	bookingextension_agent\local\wbagent\agent_runtime	loop_repeat_result
./classes/local/wbagent/agent_runtime.php:8466	bookingextension_agent\local\wbagent\agent_runtime	resolve_preview_option_id
./classes/local/wbagent/agent_runtime.php:8500	bookingextension_agent\local\wbagent\agent_runtime	normalize_trimmed_string_list
./classes/local/wbagent/agent_state.php:8583	bookingextension_agent\local\wbagent\agent_state	__construct
./classes/local/wbagent/agent_state.php:8593	bookingextension_agent\local\wbagent\agent_state	make
./classes/local/wbagent/agent_state.php:8607	bookingextension_agent\local\wbagent\agent_state	make_resumed
./classes/local/wbagent/agent_state.php:8630	bookingextension_agent\local\wbagent\agent_state	record_step
./classes/local/wbagent/agent_state.php:8652	bookingextension_agent\local\wbagent\agent_state	get_observations
./classes/local/wbagent/agent_state.php:8661	bookingextension_agent\local\wbagent\agent_state	get_steps
./classes/local/wbagent/agent_state.php:8670	bookingextension_agent\local\wbagent\agent_state	step_count
./classes/local/wbagent/agent_state.php:8679	bookingextension_agent\local\wbagent\agent_state	has_observations
./classes/local/wbagent/agent_state.php:8692	bookingextension_agent\local\wbagent\agent_state	extract_observed_command_signatures
./classes/local/wbagent/agent_state.php:8733	bookingextension_agent\local\wbagent\agent_state	normalize_command_input
./classes/local/wbagent/ai_error_classifier.php:8802	bookingextension_agent\local\wbagent\ai_error_classifier	classify_from_response
./classes/local/wbagent/ai_error_classifier.php:8879	bookingextension_agent\local\wbagent\ai_error_classifier	classify_from_db
./classes/local/wbagent/aiready.php:8993	bookingextension_agent\local\wbagent\aiready	__construct
./classes/local/wbagent/aiready.php:9004	bookingextension_agent\local\wbagent\aiready	export_for_template
./classes/local/wbagent/aiready.php:9212	bookingextension_agent\local\wbagent\aiready	build_check
./classes/local/wbagent/aiready.php:9230	bookingextension_agent\local\wbagent\aiready	is_module_ai_toggle_enabled
./classes/local/wbagent/aiready.php:9244	bookingextension_agent\local\wbagent\aiready	get_booking_statistics
./classes/local/wbagent/authorization_service.php:9331	bookingextension_agent\local\wbagent\authorization_service	is_agent_extension_installed
./classes/local/wbagent/authorization_service.php:9350	bookingextension_agent\local\wbagent\authorization_service	require_booking_module_context
./classes/local/wbagent/authorization_service.php:9369	bookingextension_agent\local\wbagent\authorization_service	require_use_capability
./classes/local/wbagent/authorization_service.php:9386	bookingextension_agent\local\wbagent\authorization_service	can_use
./classes/local/wbagent/authorization_service.php:9405	bookingextension_agent\local\wbagent\authorization_service	require_valid_context
./classes/local/wbagent/base_task.php:9455	bookingextension_agent\local\wbagent\base_task	__construct
./classes/local/wbagent/base_task.php:9464	bookingextension_agent\local\wbagent\base_task	is_read_only
./classes/local/wbagent/base_task.php:9476	bookingextension_agent\local\wbagent\base_task	get_example_input
./classes/local/wbagent/base_task.php:9485	bookingextension_agent\local\wbagent\base_task	get_prompt_contract
./classes/local/wbagent/base_task.php:9514	bookingextension_agent\local\wbagent\base_task	check_structure
./classes/local/wbagent/base_task.php:9526	bookingextension_agent\local\wbagent\base_task	preflight
./classes/local/wbagent/booking_issue_code_provider.php:9586	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_duplicate_confirmation_issue_codes
./classes/local/wbagent/booking_issue_code_provider.php:9598	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_token_subscription_issue_codes
./classes/local/wbagent/booking_issue_code_provider.php:9613	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_prevalidation_confirmable_issue_codes
./classes/local/wbagent/booking_issue_code_provider.php:9630	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_basic_subscription_url
./classes/local/wbagent/booking_issue_code_provider.php:9639	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_premium_subscription_url
./classes/local/wbagent/conversation_store.php:9696	bookingextension_agent\local\wbagent\conversation_store	get_active_thread
./classes/local/wbagent/conversation_store.php:9716	bookingextension_agent\local\wbagent\conversation_store	get_or_create_thread
./classes/local/wbagent/conversation_store.php:9751	bookingextension_agent\local\wbagent\conversation_store	create_fresh_thread
./classes/local/wbagent/conversation_store.php:9791	bookingextension_agent\local\wbagent\conversation_store	add_message
./classes/local/wbagent/conversation_store.php:9822	bookingextension_agent\local\wbagent\conversation_store	add_step_message
./classes/local/wbagent/conversation_store.php:9839	bookingextension_agent\local\wbagent\conversation_store	clear_step_messages
./classes/local/wbagent/conversation_store.php:9853	bookingextension_agent\local\wbagent\conversation_store	get_step_messages_since
./classes/local/wbagent/conversation_store.php:9873	bookingextension_agent\local\wbagent\conversation_store	get_messages
./classes/local/wbagent/conversation_store.php:9884	bookingextension_agent\local\wbagent\conversation_store	get_thread
./classes/local/wbagent/conversation_store.php:9897	bookingextension_agent\local\wbagent\conversation_store	get_recent_messages
./classes/local/wbagent/conversation_store.php:9924	bookingextension_agent\local\wbagent\conversation_store	get_last_thread_for_user
./classes/local/wbagent/conversation_store.php:9995	bookingextension_agent\local\wbagent\conversation_store	get_user_threads_by_date_window
./classes/local/wbagent/conversation_store.php:10034	bookingextension_agent\local\wbagent\conversation_store	get_user_messages_for_thread
./classes/local/wbagent/conversation_store.php:10098	bookingextension_agent\local\wbagent\conversation_store	create_run
./classes/local/wbagent/conversation_store.php:10124	bookingextension_agent\local\wbagent\conversation_store	update_run_status
./classes/local/wbagent/conversation_store.php:10144	bookingextension_agent\local\wbagent\conversation_store	get_run
./classes/local/wbagent/conversation_store.php:10155	bookingextension_agent\local\wbagent\conversation_store	get_latest_run
./classes/local/wbagent/conversation_store.php:10168	bookingextension_agent\local\wbagent\conversation_store	run_exists
./classes/local/wbagent/conversation_store.php:10180	bookingextension_agent\local\wbagent\conversation_store	run_exists_other_than
./classes/local/wbagent/conversation_store.php:10201	bookingextension_agent\local\wbagent\conversation_store	get_thread_metadata_value
./classes/local/wbagent/conversation_store.php:10225	bookingextension_agent\local\wbagent\conversation_store	set_thread_metadata_value
./classes/local/wbagent/conversation_store.php:10259	bookingextension_agent\local\wbagent\conversation_store	set_pending_intent
./classes/local/wbagent/conversation_store.php:10300	bookingextension_agent\local\wbagent\conversation_store	get_pending_intent
./classes/local/wbagent/conversation_store.php:10334	bookingextension_agent\local\wbagent\conversation_store	consume_pending_intent
./classes/local/wbagent/conversation_store.php:10360	bookingextension_agent\local\wbagent\conversation_store	clear_pending_intent
./classes/local/wbagent/conversation_store.php:10372	bookingextension_agent\local\wbagent\conversation_store	allow_confirmation_for_session
./classes/local/wbagent/conversation_store.php:10388	bookingextension_agent\local\wbagent\conversation_store	allow_confirmation_for_thread
./classes/local/wbagent/conversation_store.php:10406	bookingextension_agent\local\wbagent\conversation_store	is_confirmation_allowed_for_session
./classes/local/wbagent/conversation_store.php:10422	bookingextension_agent\local\wbagent\conversation_store	is_confirmation_allowed_for_thread
./classes/local/wbagent/conversation_store.php:10436	bookingextension_agent\local\wbagent\conversation_store	clear_confirmation_allowance
./classes/local/wbagent/conversation_store.php:10449	bookingextension_agent\local\wbagent\conversation_store	make_confirmation_session_allowlist_key
./classes/local/wbagent/conversation_store.php:10459	bookingextension_agent\local\wbagent\conversation_store	get_confirmation_session_allowlist
./classes/local/wbagent/conversation_store.php:10507	bookingextension_agent\local\wbagent\conversation_store	save_confirmation_session_allowlist
./classes/local/wbagent/conversation_store.php:10524	bookingextension_agent\local\wbagent\conversation_store	add_llm_debug_entry
./classes/local/wbagent/conversation_store.php:10557	bookingextension_agent\local\wbagent\conversation_store	get_llm_debug_entries
./classes/local/wbagent/core/tasks/core_task_base.php:10609	bookingextension_agent\local\wbagent\core\tasks\core_task_base	get_output_language
./classes/local/wbagent/core/tasks/core_task_base.php:10628	bookingextension_agent\local\wbagent\core\tasks\core_task_base	localized_string
./classes/local/wbagent/core/tasks/core_task_base.php:10645	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_task_debug_message
./classes/local/wbagent/core/tasks/core_task_base.php:10677	bookingextension_agent\local\wbagent\core\tasks\core_task_base	enrich_schema_with_prompt_meta
./classes/local/wbagent/core/tasks/core_task_base.php:10714	bookingextension_agent\local\wbagent\core\tasks\core_task_base	stringify_debug_value
./classes/local/wbagent/core/tasks/core_task_base.php:10730	bookingextension_agent\local\wbagent\core\tasks\core_task_base	resolve_userid
./classes/local/wbagent/core/tasks/core_task_base.php:10761	bookingextension_agent\local\wbagent\core\tasks\core_task_base	resolve_courseid
./classes/local/wbagent/core/tasks/core_task_base.php:10786	bookingextension_agent\local\wbagent\core\tasks\core_task_base	resolve_groupid
./classes/local/wbagent/core/tasks/core_task_base.php:10820	bookingextension_agent\local\wbagent\core\tasks\core_task_base	can_access_user
./classes/local/wbagent/core/tasks/core_task_base.php:10851	bookingextension_agent\local\wbagent\core\tasks\core_task_base	preflight
./classes/local/wbagent/core/tasks/core_task_base.php:10873	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_user_payload
./classes/local/wbagent/core/tasks/core_task_base.php:10926	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_user_courses_payload
./classes/local/wbagent/core/tasks/core_task_base.php:10973	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_user_roles_payload
./classes/local/wbagent/core/tasks/core_task_base.php:11021	bookingextension_agent\local\wbagent\core\tasks\core_task_base	extract_custom_profile_fields
./classes/local/wbagent/core/tasks/core_task_base.php:11041	bookingextension_agent\local\wbagent\core\tasks\core_task_base	search_user_candidates_for_preview
./classes/local/wbagent/core/tasks/core_task_base.php:11084	bookingextension_agent\local\wbagent\core\tasks\core_task_base	search_course_candidates_for_preview
./classes/local/wbagent/core/tasks/core_task_base.php:11133	bookingextension_agent\local\wbagent\core\tasks\core_task_base	count_active_course_enrolments
./classes/local/wbagent/core/tasks/core_task_base.php:11152	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_user_observation_full
./classes/local/wbagent/core/tasks/core_task_base.php:11210	bookingextension_agent\local\wbagent\core\tasks\core_task_base	format_observation_scalar
./classes/local/wbagent/core/tasks/core_task_base.php:11227	bookingextension_agent\local\wbagent\core\tasks\core_task_base	format_course_observation
./classes/local/wbagent/core/tasks/core_task_base.php:11255	bookingextension_agent\local\wbagent\core\tasks\core_task_base	format_role_observation
./classes/local/wbagent/core/tasks/core_task_base.php:11283	bookingextension_agent\local\wbagent\core\tasks\core_task_base	format_custom_profile_field_observation
./classes/local/wbagent/core/tasks/get_current_user_task.php:11329	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	__construct
./classes/local/wbagent/core/tasks/get_current_user_task.php:11338	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	get_name
./classes/local/wbagent/core/tasks/get_current_user_task.php:11347	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	get_schema
./classes/local/wbagent/core/tasks/get_current_user_task.php:11368	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	check_structure
./classes/local/wbagent/core/tasks/get_current_user_task.php:11381	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	get_message_triggers
./classes/local/wbagent/core/tasks/get_current_user_task.php:11400	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	get_contextual_prompt_packs
./classes/local/wbagent/core/tasks/get_current_user_task.php:11426	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	execute
./classes/local/wbagent/core/tasks/list_actions_task.php:11502	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	__construct
./classes/local/wbagent/core/tasks/list_actions_task.php:11511	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	get_name
./classes/local/wbagent/core/tasks/list_actions_task.php:11520	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	get_schema
./classes/local/wbagent/core/tasks/list_actions_task.php:11552	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	get_message_triggers
./classes/local/wbagent/core/tasks/list_actions_task.php:11571	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	check_structure
./classes/local/wbagent/core/tasks/list_actions_task.php:11594	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	get_contextual_prompt_packs
./classes/local/wbagent/core/tasks/list_actions_task.php:11620	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	execute
./classes/local/wbagent/core/tasks/list_actions_task.php:11700	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	build_observation_full
./classes/local/wbagent/core/tasks/list_actions_task.php:11735	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	get_localized_string
./classes/local/wbagent/core/tasks/list_actions_task.php:11749	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	build_debug_summary
./classes/local/wbagent/core/tasks/list_actions_task.php:11773	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	build_user_summary
./classes/local/wbagent/core/tasks/list_actions_task.php:11852	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	describe_deny_reason
./classes/local/wbagent/core/tasks/list_actions_task.php:11884	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	build_unavailable_action_detail
./classes/local/wbagent/core/tasks/list_actions_task.php:11913	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	build_user_capabilities
./classes/local/wbagent/core/tasks/recall_memory_task.php:11999	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	__construct
./classes/local/wbagent/core/tasks/recall_memory_task.php:12008	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	get_name
./classes/local/wbagent/core/tasks/recall_memory_task.php:12017	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	get_schema
./classes/local/wbagent/core/tasks/recall_memory_task.php:12070	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	get_example_input
./classes/local/wbagent/core/tasks/recall_memory_task.php:12082	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	check_structure
./classes/local/wbagent/core/tasks/recall_memory_task.php:12108	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	get_message_triggers
./classes/local/wbagent/core/tasks/recall_memory_task.php:12138	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	execute
./classes/local/wbagent/core/tasks/recall_memory_task.php:12243	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	resolve_date_window
./classes/local/wbagent/core/tasks/recall_memory_task.php:12292	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	resolve_user_timezone
./classes/local/wbagent/core/tasks/recall_memory_task.php:12322	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	build_memory_observation_text
./classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:12385	bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task	__construct
./classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:12394	bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task	get_name
./classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:12403	bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task	get_schema
./classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:12441	bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task	get_message_triggers
./classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:12460	bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task	check_structure
./classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:12485	bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task	execute
./classes/local/wbagent/core/tasks/search_courses_task.php:12553	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	__construct
./classes/local/wbagent/core/tasks/search_courses_task.php:12562	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	get_name
./classes/local/wbagent/core/tasks/search_courses_task.php:12571	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	get_schema
./classes/local/wbagent/core/tasks/search_courses_task.php:12605	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	get_message_triggers
./classes/local/wbagent/core/tasks/search_courses_task.php:12623	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	get_contextual_prompt_packs
./classes/local/wbagent/core/tasks/search_courses_task.php:12652	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	check_structure
./classes/local/wbagent/core/tasks/search_courses_task.php:12673	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	execute
./classes/local/wbagent/core/tasks/search_courses_task.php:12727	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	build_course_observation_full
./classes/local/wbagent/core/tasks/search_users_task.php:12795	bookingextension_agent\local\wbagent\core\tasks\search_users_task	__construct
./classes/local/wbagent/core/tasks/search_users_task.php:12804	bookingextension_agent\local\wbagent\core\tasks\search_users_task	get_name
./classes/local/wbagent/core/tasks/search_users_task.php:12813	bookingextension_agent\local\wbagent\core\tasks\search_users_task	get_schema
./classes/local/wbagent/core/tasks/search_users_task.php:12846	bookingextension_agent\local\wbagent\core\tasks\search_users_task	get_message_triggers
./classes/local/wbagent/core/tasks/search_users_task.php:12865	bookingextension_agent\local\wbagent\core\tasks\search_users_task	get_contextual_prompt_packs
./classes/local/wbagent/core/tasks/search_users_task.php:12893	bookingextension_agent\local\wbagent\core\tasks\search_users_task	check_structure
./classes/local/wbagent/core/tasks/search_users_task.php:12915	bookingextension_agent\local\wbagent\core\tasks\search_users_task	execute
./classes/local/wbagent/dto/bulk_update_options_input_dto.php:13028	bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto	__construct
./classes/local/wbagent/dto/bulk_update_options_input_dto.php:13038	bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto	from_array
./classes/local/wbagent/dto/bulk_update_options_input_dto.php:13047	bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto	to_array
./classes/local/wbagent/dto/bulk_update_options_input_dto.php:13058	bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto	get
./classes/local/wbagent/dto/create_entity_input_dto.php:13106	bookingextension_agent\local\wbagent\dto\create_entity_input_dto	__construct
./classes/local/wbagent/dto/create_entity_input_dto.php:13117	bookingextension_agent\local\wbagent\dto\create_entity_input_dto	from_array
./classes/local/wbagent/dto/create_entity_input_dto.php:13129	bookingextension_agent\local\wbagent\dto\create_entity_input_dto	to_array
./classes/local/wbagent/dto/create_entity_input_dto.php:13140	bookingextension_agent\local\wbagent\dto\create_entity_input_dto	get
./classes/local/wbagent/dto/create_option_input_dto.php:13188	bookingextension_agent\local\wbagent\dto\create_option_input_dto	__construct
./classes/local/wbagent/dto/create_option_input_dto.php:13199	bookingextension_agent\local\wbagent\dto\create_option_input_dto	from_array
./classes/local/wbagent/dto/create_option_input_dto.php:13211	bookingextension_agent\local\wbagent\dto\create_option_input_dto	to_array
./classes/local/wbagent/dto/create_option_input_dto.php:13222	bookingextension_agent\local\wbagent\dto\create_option_input_dto	get
./classes/local/wbagent/dto/mutation_result_dto.php:13288	bookingextension_agent\local\wbagent\dto\mutation_result_dto	__construct
./classes/local/wbagent/dto/mutation_result_dto.php:13311	bookingextension_agent\local\wbagent\dto\mutation_result_dto	success
./classes/local/wbagent/dto/mutation_result_dto.php:13326	bookingextension_agent\local\wbagent\dto\mutation_result_dto	error
./classes/local/wbagent/dto/mutation_result_dto.php:13336	bookingextension_agent\local\wbagent\dto\mutation_result_dto	skipped
./classes/local/wbagent/dto/mutation_result_dto.php:13347	bookingextension_agent\local\wbagent\dto\mutation_result_dto	dry_run_ok
./classes/local/wbagent/dto/mutation_result_dto.php:13356	bookingextension_agent\local\wbagent\dto\mutation_result_dto	to_array
./classes/local/wbagent/dto/update_option_input_dto.php:13410	bookingextension_agent\local\wbagent\dto\update_option_input_dto	__construct
./classes/local/wbagent/dto/update_option_input_dto.php:13420	bookingextension_agent\local\wbagent\dto\update_option_input_dto	from_array
./classes/local/wbagent/dto/update_option_input_dto.php:13429	bookingextension_agent\local\wbagent\dto\update_option_input_dto	to_array
./classes/local/wbagent/dto/update_option_input_dto.php:13440	bookingextension_agent\local\wbagent\dto\update_option_input_dto	get
./classes/local/wbagent/embeddings_action_config_resolver.php:13493	bookingextension_agent\local\wbagent\embeddings_action_config_resolver	resolve
./classes/local/wbagent/embeddings_catalog_builder_service.php:13587	bookingextension_agent\local\wbagent\embeddings_catalog_builder_service	build_full_catalog_rows
./classes/local/wbagent/embeddings_catalog_builder_service.php:13650	bookingextension_agent\local\wbagent\embeddings_catalog_builder_service	compute_content_hash
./classes/local/wbagent/embeddings_catalog_builder_service.php:13666	bookingextension_agent\local\wbagent\embeddings_catalog_builder_service	to_embedding_input
./classes/local/wbagent/embeddings_catalog_builder_service.php:13691	bookingextension_agent\local\wbagent\embeddings_catalog_builder_service	get_contextual_prompt_packs_for_task
./classes/local/wbagent/embeddings_csv_repository.php:13758	bookingextension_agent\local\wbagent\embeddings_csv_repository	get_csv_path
./classes/local/wbagent/embeddings_csv_repository.php:13768	bookingextension_agent\local\wbagent\embeddings_csv_repository	exists
./classes/local/wbagent/embeddings_csv_repository.php:13777	bookingextension_agent\local\wbagent\embeddings_csv_repository	read_rows
./classes/local/wbagent/embeddings_csv_repository.php:13812	bookingextension_agent\local\wbagent\embeddings_csv_repository	is_valid_schema
./classes/local/wbagent/embeddings_csv_repository.php:13838	bookingextension_agent\local\wbagent\embeddings_csv_repository	write_rows
./classes/local/wbagent/embeddings_csv_repository.php:13867	bookingextension_agent\local\wbagent\embeddings_csv_repository	headers_match
./classes/local/wbagent/embeddings_csv_repository.php:13886	bookingextension_agent\local\wbagent\embeddings_csv_repository	get_default_file_permissions
./classes/local/wbagent/embeddings_readiness_service.php:13938	bookingextension_agent\local\wbagent\embeddings_readiness_service	is_wunderbyte_embeddings_available
./classes/local/wbagent/embeddings_readiness_service.php:13950	bookingextension_agent\local\wbagent\embeddings_readiness_service	get_catalog_status
./classes/local/wbagent/embeddings_readiness_service.php:14008	bookingextension_agent\local\wbagent\embeddings_readiness_service	ensure_rebuild_scheduled_if_needed
./classes/local/wbagent/embeddings_retrieval_service.php:14075	bookingextension_agent\local\wbagent\embeddings_retrieval_service	search_top_k
./classes/local/wbagent/embeddings_retrieval_service.php:14109	bookingextension_agent\local\wbagent\embeddings_retrieval_service	build_planner_catalog_subset
./classes/local/wbagent/embeddings_retrieval_service.php:14168	bookingextension_agent\local\wbagent\embeddings_retrieval_service	build_live_contract_lookup
./classes/local/wbagent/embeddings_retrieval_service.php:14224	bookingextension_agent\local\wbagent\embeddings_retrieval_service	compact_properties_for_planner
./classes/local/wbagent/embeddings_retrieval_service.php:14261	bookingextension_agent\local\wbagent\embeddings_retrieval_service	cosine_similarity
./classes/local/wbagent/embeddings_retrieval_service.php:14292	bookingextension_agent\local\wbagent\embeddings_retrieval_service	decode_json_array
./classes/local/wbagent/execution_feedback_service.php:14353	bookingextension_agent\local\wbagent\execution_feedback_service	__construct
./classes/local/wbagent/execution_feedback_service.php:14372	bookingextension_agent\local\wbagent\execution_feedback_service	build_completion_feedback
./classes/local/wbagent/execution_feedback_service.php:14433	bookingextension_agent\local\wbagent\execution_feedback_service	should_apply_polish_step
./classes/local/wbagent/execution_feedback_service.php:14467	bookingextension_agent\local\wbagent\execution_feedback_service	generate_llm_feedback
./classes/local/wbagent/execution_feedback_service.php:14553	bookingextension_agent\local\wbagent\execution_feedback_service	generate_llm_follow_up_suggestions
./classes/local/wbagent/execution_feedback_service.php:14648	bookingextension_agent\local\wbagent\execution_feedback_service	build_follow_up_prompt
./classes/local/wbagent/execution_feedback_service.php:14695	bookingextension_agent\local\wbagent\execution_feedback_service	parse_follow_up_suggestions_json
./classes/local/wbagent/execution_feedback_service.php:14774	bookingextension_agent\local\wbagent\execution_feedback_service	get_follow_up_suggestions_limit
./classes/local/wbagent/execution_feedback_service.php:14789	bookingextension_agent\local\wbagent\execution_feedback_service	extract_latest_user_message
./classes/local/wbagent/execution_feedback_service.php:14809	bookingextension_agent\local\wbagent\execution_feedback_service	build_feedback_prompt
./classes/local/wbagent/execution_feedback_service.php:14866	bookingextension_agent\local\wbagent\execution_feedback_service	extract_message_from_feedback_response
./classes/local/wbagent/execution_feedback_service.php:14900	bookingextension_agent\local\wbagent\execution_feedback_service	build_execution_feedback_debug_source
./classes/local/wbagent/execution_feedback_service.php:14940	bookingextension_agent\local\wbagent\execution_feedback_service	sanitize_results_for_client
./classes/local/wbagent/execution_feedback_service.php:15107	bookingextension_agent\local\wbagent\execution_feedback_service	sanitize_result_detail
./classes/local/wbagent/execution_feedback_service.php:15196	bookingextension_agent\local\wbagent\execution_feedback_service	fallback_message_for_results
./classes/local/wbagent/execution_feedback_service.php:15256	bookingextension_agent\local\wbagent\execution_feedback_service	extract_primary_link_from_result
./classes/local/wbagent/execution_feedback_service.php:15276	bookingextension_agent\local\wbagent\execution_feedback_service	extract_primary_link_from_results
./classes/local/wbagent/execution_feedback_service.php:15299	bookingextension_agent\local\wbagent\execution_feedback_service	localized
./classes/local/wbagent/execution_feedback_service.php:15313	bookingextension_agent\local\wbagent\execution_feedback_service	localized_list_count_message
./classes/local/wbagent/execution_feedback_service.php:15336	bookingextension_agent\local\wbagent\execution_feedback_service	append_link_to_message
./classes/local/wbagent/executor.php:15432	bookingextension_agent\local\wbagent\executor	__construct
./classes/local/wbagent/executor.php:15456	bookingextension_agent\local\wbagent\executor	execute_commands
./classes/local/wbagent/executor.php:15617	bookingextension_agent\local\wbagent\executor	execute_spawn_chain
./classes/local/wbagent/executor.php:15797	bookingextension_agent\local\wbagent\executor	build_safe_executed_input
./classes/local/wbagent/executor.php:15829	bookingextension_agent\local\wbagent\executor	enrich_result_with_follow_ups
./classes/local/wbagent/executor.php:15870	bookingextension_agent\local\wbagent\executor	build_follow_up_suggestions
./classes/local/wbagent/executor.php:15908	bookingextension_agent\local\wbagent\executor	get_follow_up_suggestions_limit
./classes/local/wbagent/executor.php:15927	bookingextension_agent\local\wbagent\executor	append_result_driven_suggestions
./classes/local/wbagent/executor.php:15969	bookingextension_agent\local\wbagent\executor	append_suggestion
./classes/local/wbagent/executor.php:15998	bookingextension_agent\local\wbagent\executor	get_first_row_field
./classes/local/wbagent/executor.php:16020	bookingextension_agent\local\wbagent\executor	get_follow_up_candidate_tasks
./classes/local/wbagent/executor.php:16052	bookingextension_agent\local\wbagent\executor	task_follow_up_score
./classes/local/wbagent/executor.php:16080	bookingextension_agent\local\wbagent\executor	task_namespace_prefix
./classes/local/wbagent/executor.php:16092	bookingextension_agent\local\wbagent\executor	get_task_label
./classes/local/wbagent/executor.php:16111	bookingextension_agent\local\wbagent\executor	truncate_label
./classes/local/wbagent/interfaces/agent_authorization_service.php:16174	bookingextension_agent\local\wbagent\interfaces\agent_authorization_service	require_use_capability
./classes/local/wbagent/interfaces/agent_authorization_service.php:16183	bookingextension_agent\local\wbagent\interfaces\agent_authorization_service	can_use
./classes/local/wbagent/interfaces/agent_authorization_service.php:16193	bookingextension_agent\local\wbagent\interfaces\agent_authorization_service	require_valid_context
./classes/local/wbagent/interfaces/agent_conversation_store.php:16237	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_or_create_thread
./classes/local/wbagent/interfaces/agent_conversation_store.php:16248	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	add_message
./classes/local/wbagent/interfaces/agent_conversation_store.php:16256	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_messages
./classes/local/wbagent/interfaces/agent_conversation_store.php:16265	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_recent_messages
./classes/local/wbagent/interfaces/agent_conversation_store.php:16274	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_last_thread_for_user
./classes/local/wbagent/interfaces/agent_conversation_store.php:16285	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_user_threads_by_date_window
./classes/local/wbagent/interfaces/agent_conversation_store.php:16302	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_user_messages_for_thread
./classes/local/wbagent/interfaces/agent_conversation_store.php:16320	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	create_run
./classes/local/wbagent/interfaces/agent_conversation_store.php:16330	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	update_run_status
./classes/local/wbagent/interfaces/agent_conversation_store.php:16338	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_run
./classes/local/wbagent/interfaces/agent_conversation_store.php:16346	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_latest_run
./classes/local/wbagent/interfaces/agent_conversation_store.php:16354	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	run_exists
./classes/local/wbagent/interfaces/agent_executor.php:16408	bookingextension_agent\local\wbagent\interfaces\agent_executor	execute_commands
./classes/local/wbagent/interfaces/agent_interpreter.php:16469	bookingextension_agent\local\wbagent\interfaces\agent_interpreter	interpret
./classes/local/wbagent/interfaces/issue_code_provider_interface.php:16508	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_duplicate_confirmation_issue_codes
./classes/local/wbagent/interfaces/issue_code_provider_interface.php:16519	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_token_subscription_issue_codes
./classes/local/wbagent/interfaces/issue_code_provider_interface.php:16531	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_prevalidation_confirmable_issue_codes
./classes/local/wbagent/interfaces/issue_code_provider_interface.php:16538	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_basic_subscription_url
./classes/local/wbagent/interfaces/issue_code_provider_interface.php:16545	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_premium_subscription_url
./classes/local/wbagent/interfaces/preview_option_memory_interface.php:16581	bookingextension_agent\local\wbagent\interfaces\preview_option_memory_interface	remember_last_preview_options_for_execute
./classes/local/wbagent/interfaces/preview_option_memory_interface.php:16590	bookingextension_agent\local\wbagent\interfaces\preview_option_memory_interface	resolve_last_preview_option_ids_for_execute
./classes/local/wbagent/interfaces/preview_option_memory_provider_interface.php:16623	bookingextension_agent\local\wbagent\interfaces\preview_option_memory_provider_interface	get_preview_option_memory
./classes/local/wbagent/interfaces/queue_identity_provider_interface.php:16660	bookingextension_agent\local\wbagent\interfaces\queue_identity_provider_interface	build_queue_business_identity
./classes/local/wbagent/interfaces/result_summary_provider_interface.php:16696	bookingextension_agent\local\wbagent\interfaces\result_summary_provider_interface	get_result_summary_contributors
./classes/local/wbagent/interfaces/summarizer/result_summary_contributor_interface.php:16737	bookingextension_agent\local\wbagent\interfaces\summarizer\result_summary_contributor_interface	supports
./classes/local/wbagent/interfaces/summarizer/result_summary_contributor_interface.php:16748	bookingextension_agent\local\wbagent\interfaces\summarizer\result_summary_contributor_interface	summarize
./classes/local/wbagent/interfaces/task_input_normalizer_interface.php:16783	bookingextension_agent\local\wbagent\interfaces\task_input_normalizer_interface	normalize
./classes/local/wbagent/interfaces/task_input_normalizer_provider_interface.php:16816	bookingextension_agent\local\wbagent\interfaces\task_input_normalizer_provider_interface	get_task_input_normalizer
./classes/local/wbagent/interfaces/task_interface.php:16863	bookingextension_agent\local\wbagent\interfaces\task_interface	get_name
./classes/local/wbagent/interfaces/task_interface.php:16870	bookingextension_agent\local\wbagent\interfaces\task_interface	get_schema
./classes/local/wbagent/interfaces/task_interface.php:16880	bookingextension_agent\local\wbagent\interfaces\task_interface	get_example_input
./classes/local/wbagent/interfaces/task_interface.php:16887	bookingextension_agent\local\wbagent\interfaces\task_interface	get_prompt_contract
./classes/local/wbagent/interfaces/task_interface.php:16899	bookingextension_agent\local\wbagent\interfaces\task_interface	check_structure
./classes/local/wbagent/interfaces/task_interface.php:16913	bookingextension_agent\local\wbagent\interfaces\task_interface	preflight
./classes/local/wbagent/interfaces/task_interface.php:16927	bookingextension_agent\local\wbagent\interfaces\task_interface	execute
./classes/local/wbagent/interfaces/task_interface.php:16934	bookingextension_agent\local\wbagent\interfaces\task_interface	is_read_only
./classes/local/wbagent/interfaces/task_provider_interface.php:16967	bookingextension_agent\local\wbagent\interfaces\task_provider_interface	get_component
./classes/local/wbagent/interfaces/task_provider_interface.php:16974	bookingextension_agent\local\wbagent\interfaces\task_provider_interface	get_tasks
./classes/local/wbagent/interfaces/task_provider_interface.php:16981	bookingextension_agent\local\wbagent\interfaces\task_provider_interface	get_contextual_prompt_packs
./classes/local/wbagent/interfaces/task_provider_interface.php:16991	bookingextension_agent\local\wbagent\interfaces\task_provider_interface	get_issue_code_provider
./classes/local/wbagent/interfaces/task_provider_interface.php:17001	bookingextension_agent\local\wbagent\interfaces\task_provider_interface	get_prompt_guidance
./classes/local/wbagent/interfaces/task_result_summary_provider_interface.php:17041	bookingextension_agent\local\wbagent\interfaces\task_result_summary_provider_interface	summarize_task_result
./classes/local/wbagent/interfaces/task_trigger_provider_interface.php:17078	bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface	get_message_triggers
./classes/local/wbagent/interpreter.php:17154	bookingextension_agent\local\wbagent\interpreter	__construct
./classes/local/wbagent/interpreter.php:17166	bookingextension_agent\local\wbagent\interpreter	interpret
./classes/local/wbagent/interpreter.php:17393	bookingextension_agent\local\wbagent\interpreter	normalize_commands_payload
./classes/local/wbagent/interpreter.php:17458	bookingextension_agent\local\wbagent\interpreter	extract_flat_command_input
./classes/local/wbagent/interpreter.php:17473	bookingextension_agent\local\wbagent\interpreter	prune_empty_input_values
./classes/local/wbagent/interpreter.php:17505	bookingextension_agent\local\wbagent\interpreter	with_optional_next_step_intent
./classes/local/wbagent/interpreter.php:17520	bookingextension_agent\local\wbagent\interpreter	looks_like_completed_action_intent
./classes/local/wbagent/interpreter.php:17552	bookingextension_agent\local\wbagent\interpreter	normalize_task_like_response
./classes/local/wbagent/interpreter.php:17662	bookingextension_agent\local\wbagent\interpreter	resolve_task_name_alias
./classes/local/wbagent/interpreter.php:17689	bookingextension_agent\local\wbagent\interpreter	hydrate_question_field
./classes/local/wbagent/interpreter.php:17714	bookingextension_agent\local\wbagent\interpreter	extract_command_input
./classes/local/wbagent/interpreter.php:17727	bookingextension_agent\local\wbagent\interpreter	parse
./classes/local/wbagent/interpreter.php:17749	bookingextension_agent\local\wbagent\interpreter	sanitize_json_payload
./classes/local/wbagent/interpreter.php:17784	bookingextension_agent\local\wbagent\interpreter	truncate_parse_excerpt
./classes/local/wbagent/interpreter.php:17803	bookingextension_agent\local\wbagent\interpreter	extract_used_triggers
./classes/local/wbagent/interpreter.php:17823	bookingextension_agent\local\wbagent\interpreter	validate_commands
./classes/local/wbagent/interpreter.php:17946	bookingextension_agent\local\wbagent\interpreter	normalize_ambiguity_options
./classes/local/wbagent/interpreter.php:17984	bookingextension_agent\local\wbagent\interpreter	normalize_self_user_references
./classes/local/wbagent/interpreter.php:18025	bookingextension_agent\local\wbagent\interpreter	canonicalize_command_input
./classes/local/wbagent/interpreter.php:18059	bookingextension_agent\local\wbagent\interpreter	normalize_timestamp_value
./classes/local/wbagent/interpreter.php:18112	bookingextension_agent\local\wbagent\interpreter	error_result
./classes/local/wbagent/interpreter.php:18126	bookingextension_agent\local\wbagent\interpreter	error_result_with_issue_code
./classes/local/wbagent/interpreter.php:18147	bookingextension_agent\local\wbagent\interpreter	safe_string
./classes/local/wbagent/interpreter.php:18160	bookingextension_agent\local\wbagent\interpreter	clarification_message
./classes/local/wbagent/interpreter.php:18188	bookingextension_agent\local\wbagent\interpreter	confirmation_message_from_ambiguities
./classes/local/wbagent/interpreter.php:18206	bookingextension_agent\local\wbagent\interpreter	user_facing_validation_message
./classes/local/wbagent/interpreter.php:18265	bookingextension_agent\local\wbagent\interpreter	strip_command_prefix
./classes/local/wbagent/llm_call_service.php:18326	bookingextension_agent\local\wbagent\llm_call_service	__construct
./classes/local/wbagent/llm_call_service.php:18341	bookingextension_agent\local\wbagent\llm_call_service	invoke
./classes/local/wbagent/llm_call_service.php:18406	bookingextension_agent\local\wbagent\llm_call_service	invoke_embeddings
./classes/local/wbagent/llm_call_service.php:18487	bookingextension_agent\local\wbagent\llm_call_service	build_prompt_action
./classes/local/wbagent/llm_call_service.php:18526	bookingextension_agent\local\wbagent\llm_call_service	resolve_wunderbyte_prompt_action_class
./classes/local/wbagent/llm_debug_logger.php:18579	bookingextension_agent\local\wbagent\llm_debug_logger	is_enabled
./classes/local/wbagent/llm_debug_logger.php:18600	bookingextension_agent\local\wbagent\llm_debug_logger	log_exchange
./classes/local/wbagent/llm_debug_logger.php:18642	bookingextension_agent\local\wbagent\llm_debug_logger	log_exchange_always
./classes/local/wbagent/loop_finalizer.php:18712	bookingextension_agent\local\wbagent\loop_finalizer	finalize
./classes/local/wbagent/loop_finalizer.php:18742	bookingextension_agent\local\wbagent\loop_finalizer	should_finalize_after_execution_result
./classes/local/wbagent/loop_finalizer.php:18797	bookingextension_agent\local\wbagent\loop_finalizer	build_sufficient_execution_result_clarification
./classes/local/wbagent/loop_finalizer.php:18851	bookingextension_agent\local\wbagent\loop_finalizer	maybe_enrich_message_from_results
./classes/local/wbagent/loop_finalizer.php:18892	bookingextension_agent\local\wbagent\loop_finalizer	is_low_information_message
./classes/local/wbagent/message_persistence_service.php:18957	bookingextension_agent\local\wbagent\message_persistence_service	__construct
./classes/local/wbagent/message_persistence_service.php:18968	bookingextension_agent\local\wbagent\message_persistence_service	persist_assistant_message
./classes/local/wbagent/message_trigger_registry.php:19071	bookingextension_agent\local\wbagent\message_trigger_registry	__construct
./classes/local/wbagent/message_trigger_registry.php:19080	bookingextension_agent\local\wbagent\message_trigger_registry	get_available_triggers
./classes/local/wbagent/message_trigger_registry.php:19112	bookingextension_agent\local\wbagent\message_trigger_registry	get_available_trigger_ids
./classes/local/wbagent/message_trigger_registry.php:19123	bookingextension_agent\local\wbagent\message_trigger_registry	normalize_used_triggers
./classes/local/wbagent/message_trigger_registry.php:19151	bookingextension_agent\local\wbagent\message_trigger_registry	normalize_response_type
./classes/local/wbagent/orchestrator.php:19282	bookingextension_agent\local\wbagent\orchestrator	__construct
./classes/local/wbagent/orchestrator.php:19317	bookingextension_agent\local\wbagent\orchestrator	is_provider_available
./classes/local/wbagent/orchestrator.php:19331	bookingextension_agent\local\wbagent\orchestrator	get_runtime_provider_status
./classes/local/wbagent/orchestrator.php:19482	bookingextension_agent\local\wbagent\orchestrator	process
./classes/local/wbagent/orchestrator.php:19758	bookingextension_agent\local\wbagent\orchestrator	get_default_initial_prompt_template
./classes/local/wbagent/orchestrator.php:19778	bookingextension_agent\local\wbagent\orchestrator	get_default_initial_prompt_template_for_action
./classes/local/wbagent/orchestrator.php:19886	bookingextension_agent\local\wbagent\orchestrator	get_default_summary_prompt_prefix
./classes/local/wbagent/orchestrator.php:19895	bookingextension_agent\local\wbagent\orchestrator	get_default_initial_prompt_template_path
./classes/local/wbagent/orchestrator.php:19912	bookingextension_agent\local\wbagent\orchestrator	build_system_prompt
./classes/local/wbagent/orchestrator.php:20000	bookingextension_agent\local\wbagent\orchestrator	slim_prompt_catalog_for_planner
./classes/local/wbagent/orchestrator.php:20039	bookingextension_agent\local\wbagent\orchestrator	compact_catalog_description
./classes/local/wbagent/orchestrator.php:20061	bookingextension_agent\local\wbagent\orchestrator	compact_catalog_example_input
./classes/local/wbagent/orchestrator.php:20087	bookingextension_agent\local\wbagent\orchestrator	compact_catalog_message_triggers
./classes/local/wbagent/orchestrator.php:20130	bookingextension_agent\local\wbagent\orchestrator	extract_recent_task_names_from_messages
./classes/local/wbagent/orchestrator.php:20166	bookingextension_agent\local\wbagent\orchestrator	is_first_assistant_turn
./classes/local/wbagent/orchestrator.php:20192	bookingextension_agent\local\wbagent\orchestrator	build_prompt
./classes/local/wbagent/orchestrator.php:20256	bookingextension_agent\local\wbagent\orchestrator	build_local_output_contract_block
./classes/local/wbagent/orchestrator.php:20290	bookingextension_agent\local\wbagent\orchestrator	normalize_planner_trace_history
./classes/local/wbagent/orchestrator.php:20325	bookingextension_agent\local\wbagent\orchestrator	append_planner_traces_and_observations
./classes/local/wbagent/orchestrator.php:20359	bookingextension_agent\local\wbagent\orchestrator	build_runtime_context_block
./classes/local/wbagent/orchestrator.php:20428	bookingextension_agent\local\wbagent\orchestrator	append_json_object_section
./classes/local/wbagent/orchestrator.php:20447	bookingextension_agent\local\wbagent\orchestrator	append_json_list_section
./classes/local/wbagent/orchestrator.php:20470	bookingextension_agent\local\wbagent\orchestrator	json_encode_or_empty
./classes/local/wbagent/orchestrator.php:20487	bookingextension_agent\local\wbagent\orchestrator	build_unavailable_task_catalog_for_runtime
./classes/local/wbagent/orchestrator.php:20535	bookingextension_agent\local\wbagent\orchestrator	availability_from_deny_reason
./classes/local/wbagent/orchestrator.php:20557	bookingextension_agent\local\wbagent\orchestrator	sanitize_unavailable_task_catalog
./classes/local/wbagent/orchestrator.php:20569	bookingextension_agent\local\wbagent\orchestrator	build_task_description_index
./classes/local/wbagent/planner_service.php:20636	bookingextension_agent\local\wbagent\planner_service	__construct
./classes/local/wbagent/planner_service.php:20652	bookingextension_agent\local\wbagent\planner_service	enrich_recovery_input
./classes/local/wbagent/planner_service.php:20749	bookingextension_agent\local\wbagent\planner_service	build_enrichment_cache_key
./classes/local/wbagent/planner_service.php:20780	bookingextension_agent\local\wbagent\planner_service	is_docs_retrieval_schema
./classes/local/wbagent/planner_service.php:20794	bookingextension_agent\local\wbagent\planner_service	build_docs_index_lines
./classes/local/wbagent/planner_service.php:20861	bookingextension_agent\local\wbagent\planner_service	build_planner_prompt
./classes/local/wbagent/planner_service.php:20926	bookingextension_agent\local\wbagent\planner_service	extract_search_terms
./classes/local/wbagent/planner_service.php:20954	bookingextension_agent\local\wbagent\planner_service	extract_planner_payload
./classes/local/wbagent/planner_service.php:20991	bookingextension_agent\local\wbagent\planner_service	merge_input_patch
./classes/local/wbagent/planner_service.php:21107	bookingextension_agent\local\wbagent\planner_service	is_input_value_empty
./classes/local/wbagent/planner_service.php:21128	bookingextension_agent\local\wbagent\planner_service	create_docs_lookup_service
./classes/local/wbagent/planner_service.php:21141	bookingextension_agent\local\wbagent\planner_service	build_planner_debug_source
./classes/local/wbagent/preview_policy.php:21212	bookingextension_agent\local\wbagent\preview_policy	supports_preview
./classes/local/wbagent/preview_policy.php:21223	bookingextension_agent\local\wbagent\preview_policy	filter_previewable_commands
./classes/local/wbagent/preview_policy.php:21236	bookingextension_agent\local\wbagent\preview_policy	has_previewable_command
./classes/local/wbagent/privacy_anonymizer.php:21317	bookingextension_agent\local\wbagent\privacy_anonymizer	__construct
./classes/local/wbagent/privacy_anonymizer.php:21326	bookingextension_agent\local\wbagent\privacy_anonymizer	get_mode
./classes/local/wbagent/privacy_anonymizer.php:21344	bookingextension_agent\local\wbagent\privacy_anonymizer	looks_like_anon_token
./classes/local/wbagent/privacy_anonymizer.php:21353	bookingextension_agent\local\wbagent\privacy_anonymizer	should_anonymize_user_input
./classes/local/wbagent/privacy_anonymizer.php:21362	bookingextension_agent\local\wbagent\privacy_anonymizer	should_anonymize_llm_backend_data
./classes/local/wbagent/privacy_anonymizer.php:21373	bookingextension_agent\local\wbagent\privacy_anonymizer	precheck_user_message
./classes/local/wbagent/privacy_anonymizer.php:21418	bookingextension_agent\local\wbagent\privacy_anonymizer	deanonymize_command_input
./classes/local/wbagent/privacy_anonymizer.php:21441	bookingextension_agent\local\wbagent\privacy_anonymizer	deanonymize_command_input_for_active_user
./classes/local/wbagent/privacy_anonymizer.php:21461	bookingextension_agent\local\wbagent\privacy_anonymizer	deanonymize_message_for_display
./classes/local/wbagent/privacy_anonymizer.php:21525	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_value_for_llm
./classes/local/wbagent/privacy_anonymizer.php:21545	bookingextension_agent\local\wbagent\privacy_anonymizer	deanonymize_recursive
./classes/local/wbagent/privacy_anonymizer.php:21579	bookingextension_agent\local\wbagent\privacy_anonymizer	resolve_token_entry
./classes/local/wbagent/privacy_anonymizer.php:21607	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_value_recursive
./classes/local/wbagent/privacy_anonymizer.php:21635	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_string_for_llm
./classes/local/wbagent/privacy_anonymizer.php:21668	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_labeled_user_fields
./classes/local/wbagent/privacy_anonymizer.php:21723	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_person_field_value
./classes/local/wbagent/privacy_anonymizer.php:21781	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_emails
./classes/local/wbagent/privacy_anonymizer.php:21815	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_names
./classes/local/wbagent/privacy_anonymizer.php:21977	bookingextension_agent\local\wbagent\privacy_anonymizer	find_email_spans
./classes/local/wbagent/privacy_anonymizer.php:22010	bookingextension_agent\local\wbagent\privacy_anonymizer	offset_overlaps_email_span
./classes/local/wbagent/privacy_anonymizer.php:22027	bookingextension_agent\local\wbagent\privacy_anonymizer	get_user_name_match_index
./classes/local/wbagent/privacy_anonymizer.php:22101	bookingextension_agent\local\wbagent\privacy_anonymizer	user_sets_intersect
./classes/local/wbagent/privacy_anonymizer.php:22120	bookingextension_agent\local\wbagent\privacy_anonymizer	get_distinct_name_index
./classes/local/wbagent/privacy_anonymizer.php:22166	bookingextension_agent\local\wbagent\privacy_anonymizer	normalize_name
./classes/local/wbagent/privacy_anonymizer.php:22184	bookingextension_agent\local\wbagent\privacy_anonymizer	get_token_map
./classes/local/wbagent/privacy_anonymizer.php:22209	bookingextension_agent\local\wbagent\privacy_anonymizer	set_token_map
./classes/local/wbagent/privacy_anonymizer.php:22222	bookingextension_agent\local\wbagent\privacy_anonymizer	get_or_create_token
./classes/local/wbagent/privacy_anonymizer.php:22325	bookingextension_agent\local\wbagent\privacy_anonymizer	scope_identity_key_for_type
./classes/local/wbagent/privacy_anonymizer.php:22340	bookingextension_agent\local\wbagent\privacy_anonymizer	build_field_token_from_base
./classes/local/wbagent/privacy_anonymizer.php:22360	bookingextension_agent\local\wbagent\privacy_anonymizer	extract_base_token_from_anon_token
./classes/local/wbagent/privacy_anonymizer.php:22378	bookingextension_agent\local\wbagent\privacy_anonymizer	resolve_entry_for_field
./classes/local/wbagent/privacy_anonymizer.php:22414	bookingextension_agent\local\wbagent\privacy_anonymizer	resolve_identity_from_email
./classes/local/wbagent/privacy_anonymizer.php:22453	bookingextension_agent\local\wbagent\privacy_anonymizer	resolve_identity_from_user_ids
./classes/local/wbagent/privacy_anonymizer.php:22478	bookingextension_agent\local\wbagent\privacy_anonymizer	load_user_identity_record
./classes/local/wbagent/privacy_anonymizer.php:22496	bookingextension_agent\local\wbagent\privacy_anonymizer	build_identity_variants_from_user_record
./classes/local/wbagent/privacy_anonymizer.php:22526	bookingextension_agent\local\wbagent\privacy_anonymizer	merge_identity_variants
./classes/local/wbagent/privacy_anonymizer.php:22547	bookingextension_agent\local\wbagent\privacy_anonymizer	array_contains_person_identity_fields
./classes/local/wbagent/privacy_anonymizer.php:22564	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_person_identity_field_group
./classes/local/wbagent/privacy_anonymizer.php:22617	bookingextension_agent\local\wbagent\privacy_anonymizer	is_user_reference_field
./classes/local/wbagent/prompt_policy_builder.php:22679	bookingextension_agent\local\wbagent\prompt_policy_builder	build_all_policies
./classes/local/wbagent/prompt_policy_builder.php:22727	bookingextension_agent\local\wbagent\prompt_policy_builder	build_response_contract_policy
./classes/local/wbagent/prompt_policy_builder.php:22767	bookingextension_agent\local\wbagent\prompt_policy_builder	build_trigger_policy
./classes/local/wbagent/prompt_policy_builder.php:22786	bookingextension_agent\local\wbagent\prompt_policy_builder	build_trigger_policy_compact
./classes/local/wbagent/prompt_policy_builder.php:22802	bookingextension_agent\local\wbagent\prompt_policy_builder	build_routing_determinism_policy
./classes/local/wbagent/prompt_policy_builder.php:22828	bookingextension_agent\local\wbagent\prompt_policy_builder	build_step_intent_policy
./classes/local/wbagent/prompt_policy_builder.php:22857	bookingextension_agent\local\wbagent\prompt_policy_builder	is_planner_step_type
./classes/local/wbagent/prompt_policy_builder.php:22868	bookingextension_agent\local\wbagent\prompt_policy_builder	build_docs_answer_policy
./classes/local/wbagent/prompt_policy_builder.php:22888	bookingextension_agent\local\wbagent\prompt_policy_builder	build_sufficiency_policy
./classes/local/wbagent/prompt_policy_builder.php:22953	bookingextension_agent\local\wbagent\prompt_policy_builder	build_follow_up_state_policy
./classes/local/wbagent/queue/observation_builder.php:23001	bookingextension_agent\local\wbagent\queue\observation_builder	build_observation
./classes/local/wbagent/queue/queue_manager.php:23098	bookingextension_agent\local\wbagent\queue\queue_manager	__construct
./classes/local/wbagent/queue/queue_manager.php:23115	bookingextension_agent\local\wbagent\queue\queue_manager	enqueue_command
./classes/local/wbagent/queue/queue_manager.php:23236	bookingextension_agent\local\wbagent\queue\queue_manager	update_status
./classes/local/wbagent/queue/queue_manager.php:23285	bookingextension_agent\local\wbagent\queue\queue_manager	get_queue_items
./classes/local/wbagent/queue/queue_manager.php:23297	bookingextension_agent\local\wbagent\queue\queue_manager	get_queue_item
./classes/local/wbagent/queue/queue_manager.php:23319	bookingextension_agent\local\wbagent\queue\queue_manager	save_queue_items
./classes/local/wbagent/queue/queue_manager.php:23332	bookingextension_agent\local\wbagent\queue\queue_manager	set_prepared_input
./classes/local/wbagent/queue/queue_manager.php:23359	bookingextension_agent\local\wbagent\queue\queue_manager	has_running_item
./classes/local/wbagent/queue/queue_manager.php:23385	bookingextension_agent\local\wbagent\queue\queue_manager	try_mark_running
./classes/local/wbagent/queue/queue_manager.php:23468	bookingextension_agent\local\wbagent\queue\queue_manager	can_pickup_now
./classes/local/wbagent/queue/queue_manager.php:23499	bookingextension_agent\local\wbagent\queue\queue_manager	dependencies_succeeded
./classes/local/wbagent/queue/queue_manager.php:23510	bookingextension_agent\local\wbagent\queue\queue_manager	dependencies_succeeded_from_items
./classes/local/wbagent/queue/queue_manager.php:23555	bookingextension_agent\local\wbagent\queue\queue_manager	validate_depends_on_is_dag
./classes/local/wbagent/queue/queue_manager.php:23590	bookingextension_agent\local\wbagent\queue\queue_manager	fail_expired_blocked_items
./classes/local/wbagent/queue/queue_manager.php:23627	bookingextension_agent\local\wbagent\queue\queue_manager	build_input_signature
./classes/local/wbagent/queue/queue_manager.php:23639	bookingextension_agent\local\wbagent\queue\queue_manager	build_input_signature_details
./classes/local/wbagent/queue/queue_manager.php:23678	bookingextension_agent\local\wbagent\queue\queue_manager	normalize_for_signature
./classes/local/wbagent/queue/queue_manager.php:23701	bookingextension_agent\local\wbagent\queue\queue_manager	next_sequence
./classes/local/wbagent/queue/queue_manager.php:23714	bookingextension_agent\local\wbagent\queue\queue_manager	resolve_thread_contextid
./classes/local/wbagent/queue/queue_manager.php:23730	bookingextension_agent\local\wbagent\queue\queue_manager	resolve_blocked_expires_at
./classes/local/wbagent/queue/queue_manager.php:23752	bookingextension_agent\local\wbagent\queue\queue_manager	dfs_cycle_detect
./classes/local/wbagent/result_payload_summarizer.php:23839	bookingextension_agent\local\wbagent\result_payload_summarizer	for_observation
./classes/local/wbagent/result_payload_summarizer.php:23901	bookingextension_agent\local\wbagent\result_payload_summarizer	describe_result_for_state
./classes/local/wbagent/result_payload_summarizer.php:23923	bookingextension_agent\local\wbagent\result_payload_summarizer	detect_result_category
./classes/local/wbagent/result_payload_summarizer.php:23965	bookingextension_agent\local\wbagent\result_payload_summarizer	describe_entry
./classes/local/wbagent/result_payload_summarizer.php:24141	bookingextension_agent\local\wbagent\result_payload_summarizer	compact_text
./classes/local/wbagent/result_payload_summarizer.php:24163	bookingextension_agent\local\wbagent\result_payload_summarizer	summarize_with_contributors
./classes/local/wbagent/result_payload_summarizer.php:24189	bookingextension_agent\local\wbagent\result_payload_summarizer	build_summary_context
./classes/local/wbagent/result_payload_summarizer.php:24215	bookingextension_agent\local\wbagent\result_payload_summarizer	summarize_with_task_provider
./classes/local/wbagent/services/assistant_state_guidance_service.php:24270	bookingextension_agent\local\wbagent\services\assistant_state_guidance_service	__construct
./classes/local/wbagent/services/assistant_state_guidance_service.php:24280	bookingextension_agent\local\wbagent\services\assistant_state_guidance_service	build_assistant_state_blocks
./classes/local/wbagent/services/assistant_state_guidance_service.php:24312	bookingextension_agent\local\wbagent\services\assistant_state_guidance_service	build_contextual_guidance
./classes/local/wbagent/services/assistant_state_guidance_service.php:24356	bookingextension_agent\local\wbagent\services\assistant_state_guidance_service	normalize_nonempty_string_list
./classes/local/wbagent/services/assistant_state_guidance_service.php:24382	bookingextension_agent\local\wbagent\services\assistant_state_guidance_service	summarize_structured_state
./classes/local/wbagent/services/assistant_state_guidance_service.php:24422	bookingextension_agent\local\wbagent\services\assistant_state_guidance_service	extract_result_facts
./classes/local/wbagent/services/assistant_state_guidance_service.php:24478	bookingextension_agent\local\wbagent\services\assistant_state_guidance_service	matches_contextual_pack
./classes/local/wbagent/services/completed_command_history_service.php:24547	bookingextension_agent\local\wbagent\services\completed_command_history_service	__construct
./classes/local/wbagent/services/completed_command_history_service.php:24557	bookingextension_agent\local\wbagent\services\completed_command_history_service	extract_from_messages
./classes/local/wbagent/services/completed_command_history_service.php:24636	bookingextension_agent\local\wbagent\services\completed_command_history_service	merge_from_queue
./classes/local/wbagent/services/completed_command_history_service.php:24707	bookingextension_agent\local\wbagent\services\completed_command_history_service	build_signature
./classes/local/wbagent/services/completed_command_history_service.php:24733	bookingextension_agent\local\wbagent\services\completed_command_history_service	normalize_input
./classes/local/wbagent/services/completed_command_history_service.php:24766	bookingextension_agent\local\wbagent\services\completed_command_history_service	normalize_value
./classes/local/wbagent/services/confirm_preview_option_service.php:24849	bookingextension_agent\local\wbagent\services\confirm_preview_option_service	__construct
./classes/local/wbagent/services/confirm_preview_option_service.php:24862	bookingextension_agent\local\wbagent\services\confirm_preview_option_service	resolve_preview_option_ids_for_response
./classes/local/wbagent/services/confirm_preview_option_service.php:24904	bookingextension_agent\local\wbagent\services\confirm_preview_option_service	first_preview_option_id
./classes/local/wbagent/services/confirm_preview_option_service.php:24925	bookingextension_agent\local\wbagent\services\confirm_preview_option_service	remember_confirm_preview_option_ids
./classes/local/wbagent/services/confirm_preview_option_service.php:24954	bookingextension_agent\local\wbagent\services\confirm_preview_option_service	resolve_confirm_preview_option_ids_for_response
./classes/local/wbagent/services/confirm_preview_option_service.php:24986	bookingextension_agent\local\wbagent\services\confirm_preview_option_service	merge_preview_option_ids
./classes/local/wbagent/services/confirm_run_service.php:25077	bookingextension_agent\local\wbagent\services\confirm_run_service	__construct
./classes/local/wbagent/services/confirm_run_service.php:25097	bookingextension_agent\local\wbagent\services\confirm_run_service	confirm
./classes/local/wbagent/services/confirm_run_service.php:25656	bookingextension_agent\local\wbagent\services\confirm_run_service	build_error_payload
./classes/local/wbagent/services/confirm_run_service.php:25691	bookingextension_agent\local\wbagent\services\confirm_run_service	build_preview_response_fields
./classes/local/wbagent/services/confirm_run_service.php:25705	bookingextension_agent\local\wbagent\services\confirm_run_service	has_successful_execution_results
./classes/local/wbagent/services/confirm_run_service.php:25726	bookingextension_agent\local\wbagent\services\confirm_run_service	normalize_string_list
./classes/local/wbagent/services/confirm_run_service.php:25752	bookingextension_agent\local\wbagent\services\confirm_run_service	build_retry_decision
./classes/local/wbagent/services/confirm_run_service.php:25797	bookingextension_agent\local\wbagent\services\confirm_run_service	build_queue_audit_context
./classes/local/wbagent/services/confirm_run_service.php:25821	bookingextension_agent\local\wbagent\services\confirm_run_service	should_continue_with_runtime_loop
./classes/local/wbagent/services/confirm_run_service.php:25844	bookingextension_agent\local\wbagent\services\confirm_run_service	find_next_mutating_queue_item
./classes/local/wbagent/services/confirm_run_service.php:25870	bookingextension_agent\local\wbagent\services\confirm_run_service	extract_attempted_tasks_from_commands
./classes/local/wbagent/services/confirm_run_service.php:25895	bookingextension_agent\local\wbagent\services\confirm_run_service	resolve_pending_queue_item_id
./classes/local/wbagent/services/confirm_run_service.php:25933	bookingextension_agent\local\wbagent\services\confirm_run_service	resolve_commands_for_run
./classes/local/wbagent/services/confirm_run_service.php:25954	bookingextension_agent\local\wbagent\services\confirm_run_service	mark_dependents_skipped
./classes/local/wbagent/services/confirm_run_service.php:25998	bookingextension_agent\local\wbagent\services\confirm_run_service	get_active_mutating_queue_item
./classes/local/wbagent/services/confirm_run_service.php:26022	bookingextension_agent\local\wbagent\services\confirm_run_service	is_actionable_mutating_queue_item
./classes/local/wbagent/services/execution_observation_ledger.php:26089	bookingextension_agent\local\wbagent\services\execution_observation_ledger	__construct
./classes/local/wbagent/services/execution_observation_ledger.php:26101	bookingextension_agent\local\wbagent\services\execution_observation_ledger	append_from_results
./classes/local/wbagent/services/execution_observation_ledger.php:26197	bookingextension_agent\local\wbagent\services\execution_observation_ledger	get_recent_for_runtime
./classes/local/wbagent/services/execution_observation_ledger.php:26243	bookingextension_agent\local\wbagent\services\execution_observation_ledger	read_entries
./classes/local/wbagent/services/execution_observation_ledger.php:26258	bookingextension_agent\local\wbagent\services\execution_observation_ledger	normalize_input
./classes/local/wbagent/services/execution_observation_ledger.php:26286	bookingextension_agent\local\wbagent\services\execution_observation_ledger	normalize_value
./classes/local/wbagent/services/execution_observation_ledger.php:26308	bookingextension_agent\local\wbagent\services\execution_observation_ledger	build_signature
./classes/local/wbagent/services/language_policy_service.php:26373	bookingextension_agent\local\wbagent\services\language_policy_service	normalize_iso_language
./classes/local/wbagent/services/language_policy_service.php:26386	bookingextension_agent\local\wbagent\services\language_policy_service	resolve_output_language
./classes/local/wbagent/services/language_policy_service.php:26410	bookingextension_agent\local\wbagent\services\language_policy_service	fallback_string_id_for_response_type
./classes/local/wbagent/services/language_policy_service.php:26430	bookingextension_agent\local\wbagent\services\language_policy_service	preflight_retry_hint_string_id
./classes/local/wbagent/services/localized_string_service.php:26473	bookingextension_agent\local\wbagent\services\localized_string_service	get
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26532	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	__construct
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26545	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	get_root_doc_path
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26556	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	read_root_doc
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26567	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	search
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26609	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	search_multi
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26675	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	is_ambiguous
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26702	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	get_ambiguity_candidates
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26724	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	get_all_doc_index
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26741	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	get_master_toc_index
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26801	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	get_topic_doc_index
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26829	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	render_master_toc_observation
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26854	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	detect_best_topic
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26924	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	search_in_topic
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26954	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	load_docs_by_paths
./classes/local/wbagent/services/lookup/docs_lookup_service.php:26973	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	search_docs
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27034	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	extract_topic_id_from_path
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27051	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	build_topic_title
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27066	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	extract_topic_terms
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27085	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	score_topic
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27135	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	read_doc_by_path
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27202	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	build_summary
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27228	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	load_docs
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27278	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	score_doc
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27334	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	has_exact_basename_hit
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27357	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	extract_query_tokens
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27379	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	extract_first_ordered_steps
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27442	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	extract_title
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27456	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	extract_excerpt
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27494	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	extract_markdown_links_from_text
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27539	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	resolve_relative_doc_link
./classes/local/wbagent/services/lookup/docs_lookup_service.php:27580	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	strip_markdown
./classes/local/wbagent/services/lookup/option_lookup_service.php:27637	bookingextension_agent\local\wbagent\services\lookup\option_lookup_service	__construct
./classes/local/wbagent/services/lookup/option_lookup_service.php:27651	bookingextension_agent\local\wbagent\services\lookup\option_lookup_service	search_options
./classes/local/wbagent/services/lookup/option_lookup_service.php:27679	bookingextension_agent\local\wbagent\services\lookup\option_lookup_service	resolve_single_option
./classes/local/wbagent/services/mutation/entity_mutation_service.php:27743	bookingextension_agent\local\wbagent\services\mutation\entity_mutation_service	create_entity
./classes/local/wbagent/services/mutation/entity_mutation_service.php:27769	bookingextension_agent\local\wbagent\services\mutation\entity_mutation_service	entity_exists_by_name
./classes/local/wbagent/services/mutation/entity_mutation_service.php:27783	bookingextension_agent\local\wbagent\services\mutation\entity_mutation_service	entity_exists_by_shortname
./classes/local/wbagent/services/mutation/option_mutation_service.php:27842	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	validate_create
./classes/local/wbagent/services/mutation/option_mutation_service.php:27857	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	validate_update
./classes/local/wbagent/services/mutation/option_mutation_service.php:27872	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	validate_bulk_update
./classes/local/wbagent/services/mutation/option_mutation_service.php:27888	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	create_option
./classes/local/wbagent/services/mutation/option_mutation_service.php:27900	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	update_option
./classes/local/wbagent/services/mutation/option_mutation_service.php:27912	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	bulk_update_options
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php:27977	bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service	__construct
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php:27999	bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service	observations_are_framework_retry_hints
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php:28023	bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service	normalize_step_type
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php:28043	bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service	get_initial_prompt_config_key
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php:28062	bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service	get_action_initial_prompt_config_key
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php:28081	bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service	get_history_limit_for_step
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php:28092	bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service	normalize_config_prompt_template
./classes/local/wbagent/services/orchestrator_routing_service.php:28166	bookingextension_agent\local\wbagent\services\orchestrator_routing_service	__construct
./classes/local/wbagent/services/orchestrator_routing_service.php:28190	bookingextension_agent\local\wbagent\services\orchestrator_routing_service	resolve_action_class_for_step
./classes/local/wbagent/services/orchestrator_routing_service.php:28260	bookingextension_agent\local\wbagent\services\orchestrator_routing_service	is_action_available_in_context
./classes/local/wbagent/services/orchestrator_routing_service.php:28287	bookingextension_agent\local\wbagent\services\orchestrator_routing_service	build_debug_source
./classes/local/wbagent/services/orchestrator_routing_service.php:28352	bookingextension_agent\local\wbagent\services\orchestrator_routing_service	should_use_openai_step_routing
./classes/local/wbagent/services/orchestrator_routing_service.php:28372	bookingextension_agent\local\wbagent\services\orchestrator_routing_service	is_wunderbyte_routing_available
./classes/local/wbagent/services/orchestrator_routing_service.php:28403	bookingextension_agent\local\wbagent\services\orchestrator_routing_service	short_debug_token
./classes/local/wbagent/services/pending_intent_service.php:28458	bookingextension_agent\local\wbagent\services\pending_intent_service	__construct
./classes/local/wbagent/services/pending_intent_service.php:28468	bookingextension_agent\local\wbagent\services\pending_intent_service	get
./classes/local/wbagent/services/pending_intent_service.php:28480	bookingextension_agent\local\wbagent\services\pending_intent_service	consume
./classes/local/wbagent/services/pending_intent_service.php:28490	bookingextension_agent\local\wbagent\services\pending_intent_service	clear
./classes/local/wbagent/services/pending_intent_service.php:28504	bookingextension_agent\local\wbagent\services\pending_intent_service	set
./classes/local/wbagent/services/pending_queue_command_service.php:28563	bookingextension_agent\local\wbagent\services\pending_queue_command_service	__construct
./classes/local/wbagent/services/pending_queue_command_service.php:28574	bookingextension_agent\local\wbagent\services\pending_queue_command_service	build_mutating_commands_from_pending_intent
./classes/local/wbagent/services/pending_queue_command_service.php:28604	bookingextension_agent\local\wbagent\services\pending_queue_command_service	normalize_queue_item_ids
./classes/local/wbagent/services/preflight_audit_logger.php:28661	bookingextension_agent\local\wbagent\services\preflight_audit_logger	__construct
./classes/local/wbagent/services/preflight_audit_logger.php:28673	bookingextension_agent\local\wbagent\services\preflight_audit_logger	append
./classes/local/wbagent/services/preflight_contract_validator.php:28760	bookingextension_agent\local\wbagent\services\preflight_contract_validator	__construct
./classes/local/wbagent/services/preflight_contract_validator.php:28777	bookingextension_agent\local\wbagent\services\preflight_contract_validator	validate
./classes/local/wbagent/services/preflight_domain_check_runner.php:28852	bookingextension_agent\local\wbagent\services\preflight_domain_check_runner	run
./classes/local/wbagent/services/preflight_error_classifier.php:28931	bookingextension_agent\local\wbagent\services\preflight_error_classifier	infer_from_issue_codes
./classes/local/wbagent/services/preflight_error_classifier.php:28963	bookingextension_agent\local\wbagent\services\preflight_error_classifier	is_retryable_error_class
./classes/local/wbagent/services/preflight_execution_gate.php:29014	bookingextension_agent\local\wbagent\services\preflight_execution_gate	evaluate
./classes/local/wbagent/services/preflight_execution_gate.php:29057	bookingextension_agent\local\wbagent\services\preflight_execution_gate	build_guard_token
./classes/local/wbagent/services/preflight_execution_gate.php:29072	bookingextension_agent\local\wbagent\services\preflight_execution_gate	verify_guard_token
./classes/local/wbagent/services/preflight_execution_gate.php:29092	bookingextension_agent\local\wbagent\services\preflight_execution_gate	normalize_for_guard
./classes/local/wbagent/services/preflight_pipeline.php:29167	bookingextension_agent\local\wbagent\services\preflight_pipeline	__construct
./classes/local/wbagent/services/preflight_pipeline.php:29185	bookingextension_agent\local\wbagent\services\preflight_pipeline	run
./classes/local/wbagent/services/preflight_pipeline.php:29372	bookingextension_agent\local\wbagent\services\preflight_pipeline	build_output
./classes/local/wbagent/services/preflight_pipeline.php:29402	bookingextension_agent\local\wbagent\services\preflight_pipeline	build_audit_command_context
./classes/local/wbagent/services/preflight_result_v2.php:29488	bookingextension_agent\local\wbagent\services\preflight_result_v2	__construct
./classes/local/wbagent/services/preflight_result_v2.php:29520	bookingextension_agent\local\wbagent\services\preflight_result_v2	normalize_blocking_layer
./classes/local/wbagent/services/preflight_result_v2.php:29554	bookingextension_agent\local\wbagent\services\preflight_result_v2	to_array
./classes/local/wbagent/services/preflight_result_v2.php:29571	bookingextension_agent\local\wbagent\services\preflight_result_v2	ok
./classes/local/wbagent/services/preflight_result_v2.php:29582	bookingextension_agent\local\wbagent\services\preflight_result_v2	confirmable
./classes/local/wbagent/services/preflight_result_v2.php:29602	bookingextension_agent\local\wbagent\services\preflight_result_v2	invalid
./classes/local/wbagent/services/preflight_result_v2.php:29622	bookingextension_agent\local\wbagent\services\preflight_result_v2	extract_issue_codes_from_issues
./classes/local/wbagent/services/preflight_schema_validator.php:29666	bookingextension_agent\local\wbagent\services\preflight_schema_validator	validate
./classes/local/wbagent/services/preflight_schema_validator.php:29789	bookingextension_agent\local\wbagent\services\preflight_schema_validator	get_schema
./classes/local/wbagent/services/preflight_version_validator.php:29851	bookingextension_agent\local\wbagent\services\preflight_version_validator	__construct
./classes/local/wbagent/services/preflight_version_validator.php:29862	bookingextension_agent\local\wbagent\services\preflight_version_validator	validate
./classes/local/wbagent/services/preflight_version_validator.php:29931	bookingextension_agent\local\wbagent\services\preflight_version_validator	resolve_requested_version
./classes/local/wbagent/services/provider_routing_util.php:29990	bookingextension_agent\local\wbagent\services\provider_routing_util	resolve_primary_provider_for_action
./classes/local/wbagent/services/provider_routing_util.php:30010	bookingextension_agent\local\wbagent\services\provider_routing_util	short_provider_for_debug
./classes/local/wbagent/services/queue_command_mapper.php:30066	bookingextension_agent\local\wbagent\services\queue_command_mapper	from_queue_item
./classes/local/wbagent/services/queue_command_mapper.php:30104	bookingextension_agent\local\wbagent\services\queue_command_mapper	from_queue_items
./classes/local/wbagent/services/queue_status_policy.php:30195	bookingextension_agent\local\wbagent\services\queue_status_policy	ready_status
./classes/local/wbagent/services/queue_status_policy.php:30204	bookingextension_agent\local\wbagent\services\queue_status_policy	failed_status
./classes/local/wbagent/services/queue_status_policy.php:30213	bookingextension_agent\local\wbagent\services\queue_status_policy	succeeded_status
./classes/local/wbagent/services/queue_status_policy.php:30222	bookingextension_agent\local\wbagent\services\queue_status_policy	skipped_status
./classes/local/wbagent/services/queue_status_policy.php:30231	bookingextension_agent\local\wbagent\services\queue_status_policy	actionable_mutating_statuses
./classes/local/wbagent/services/queue_status_policy.php:30240	bookingextension_agent\local\wbagent\services\queue_status_policy	pickup_ready_statuses
./classes/local/wbagent/services/queue_status_policy.php:30250	bookingextension_agent\local\wbagent\services\queue_status_policy	is_actionable_mutating_status
./classes/local/wbagent/services/queue_status_policy.php:30260	bookingextension_agent\local\wbagent\services\queue_status_policy	is_pickup_ready_status
./classes/local/wbagent/services/queue_status_policy.php:30270	bookingextension_agent\local\wbagent\services\queue_status_policy	is_terminal_status
./classes/local/wbagent/services/queue_status_policy.php:30280	bookingextension_agent\local\wbagent\services\queue_status_policy	is_succeeded_status
./classes/local/wbagent/services/queue_status_policy.php:30290	bookingextension_agent\local\wbagent\services\queue_status_policy	is_failed_status
./classes/local/wbagent/services/queue_status_policy.php:30300	bookingextension_agent\local\wbagent\services\queue_status_policy	is_ready_status
./classes/local/wbagent/services/queue_status_policy.php:30310	bookingextension_agent\local\wbagent\services\queue_status_policy	is_dependency_satisfied_status
./classes/local/wbagent/services/queue_status_policy.php:30320	bookingextension_agent\local\wbagent\services\queue_status_policy	is_blocked_confirmation_status
./classes/local/wbagent/services/queue_status_policy.php:30330	bookingextension_agent\local\wbagent\services\queue_status_policy	is_retry_waiting_status
./classes/local/wbagent/services/queue_transition_service.php:30381	bookingextension_agent\local\wbagent\services\queue_transition_service	apply_preflight_decision
./classes/local/wbagent/services/queue_transition_service.php:30476	bookingextension_agent\local\wbagent\services\queue_transition_service	to_status
./classes/local/wbagent/services/queue_transition_service.php:30498	bookingextension_agent\local\wbagent\services\queue_transition_service	to_ready
./classes/local/wbagent/services/queue_transition_service.php:30511	bookingextension_agent\local\wbagent\services\queue_transition_service	to_blocked_confirmation
./classes/local/wbagent/services/queue_transition_service.php:30532	bookingextension_agent\local\wbagent\services\queue_transition_service	to_retry_waiting
./classes/local/wbagent/services/queue_transition_service.php:30555	bookingextension_agent\local\wbagent\services\queue_transition_service	to_failed
./classes/local/wbagent/services/queue_transition_service.php:30584	bookingextension_agent\local\wbagent\services\queue_transition_service	to_skipped
./classes/local/wbagent/services/queue_transition_service.php:30611	bookingextension_agent\local\wbagent\services\queue_transition_service	to_succeeded
./classes/local/wbagent/services/queue_transition_service.php:30621	bookingextension_agent\local\wbagent\services\queue_transition_service	normalize_queue_item_ids
./classes/local/wbagent/services/runtime_step_analysis_service.php:30670	bookingextension_agent\local\wbagent\services\runtime_step_analysis_service	extract_step_task_names
./classes/local/wbagent/services/runtime_step_analysis_service.php:30705	bookingextension_agent\local\wbagent\services\runtime_step_analysis_service	humanize_task_name
./classes/local/wbagent/services/runtime_step_analysis_service.php:30728	bookingextension_agent\local\wbagent\services\runtime_step_analysis_service	extract_step_command_signatures
./classes/local/wbagent/services/runtime_step_analysis_service.php:30763	bookingextension_agent\local\wbagent\services\runtime_step_analysis_service	extract_recorded_step_task_names
./classes/local/wbagent/services/runtime_step_analysis_service.php:30787	bookingextension_agent\local\wbagent\services\runtime_step_analysis_service	normalize_command_input_for_signature
./classes/local/wbagent/services/runtime_synthesis_policy_service.php:30840	bookingextension_agent\local\wbagent\services\runtime_synthesis_policy_service	has_explain_or_diagnose_task
./classes/local/wbagent/services/runtime_synthesis_policy_service.php:30870	bookingextension_agent\local\wbagent\services\runtime_synthesis_policy_service	should_convert_sufficient_to_readonly_clarification
./classes/local/wbagent/services/runtime_synthesis_policy_service.php:30899	bookingextension_agent\local\wbagent\services\runtime_synthesis_policy_service	is_sufficiency_exit_signal
./classes/local/wbagent/services/shared_json_payload_extractor.php:30959	bookingextension_agent\local\wbagent\services\shared_json_payload_extractor	extract_json_candidates
./classes/local/wbagent/services/shared_json_payload_extractor.php:30991	bookingextension_agent\local\wbagent\services\shared_json_payload_extractor	extract_balanced_json_objects
./classes/local/wbagent/services/spawn_contract_service.php:31081	bookingextension_agent\local\wbagent\services\spawn_contract_service	normalize_task_result
./classes/local/wbagent/services/spawn_contract_service.php:31095	bookingextension_agent\local\wbagent\services\spawn_contract_service	apply_output_bindings
./classes/local/wbagent/services/spawn_contract_service.php:31131	bookingextension_agent\local\wbagent\services\spawn_contract_service	normalize_spawn_commands
./classes/local/wbagent/services/spawn_contract_service.php:31172	bookingextension_agent\local\wbagent\services\spawn_contract_service	normalize_produced_outputs
./classes/local/wbagent/services/spawn_contract_service.php:31197	bookingextension_agent\local\wbagent\services\spawn_contract_service	normalize_binding_reference
./classes/local/wbagent/services/task_prompt_contract.php:31248	bookingextension_agent\local\wbagent\services\task_prompt_contract	__construct
./classes/local/wbagent/services/task_prompt_contract.php:31257	bookingextension_agent\local\wbagent\services\task_prompt_contract	to_array
./classes/local/wbagent/services/task_version_policy.php:31320	bookingextension_agent\local\wbagent\services\task_version_policy	evaluate
./classes/local/wbagent/services/task_version_policy.php:31357	bookingextension_agent\local\wbagent\services\task_version_policy	is_deprecated
./classes/local/wbagent/services/trigger_result_util.php:31412	bookingextension_agent\local\wbagent\services\trigger_result_util	has_trigger
./classes/local/wbagent/summarizer/basic_collection_result_summary_contributor.php:31468	bookingextension_agent\local\wbagent\summarizer\basic_collection_result_summary_contributor	supports
./classes/local/wbagent/summarizer/basic_collection_result_summary_contributor.php:31479	bookingextension_agent\local\wbagent\summarizer\basic_collection_result_summary_contributor	summarize
./classes/local/wbagent/summarizer/diagnosis_result_summary_contributor.php:31595	bookingextension_agent\local\wbagent\summarizer\diagnosis_result_summary_contributor	supports
./classes/local/wbagent/summarizer/diagnosis_result_summary_contributor.php:31606	bookingextension_agent\local\wbagent\summarizer\diagnosis_result_summary_contributor	summarize
./classes/local/wbagent/summarizer/docs_result_summary_contributor.php:31689	bookingextension_agent\local\wbagent\summarizer\docs_result_summary_contributor	supports
./classes/local/wbagent/summarizer/docs_result_summary_contributor.php:31700	bookingextension_agent\local\wbagent\summarizer\docs_result_summary_contributor	summarize
./classes/local/wbagent/summarizer/single_object_result_summary_contributor.php:31821	bookingextension_agent\local\wbagent\summarizer\single_object_result_summary_contributor	supports
./classes/local/wbagent/summarizer/single_object_result_summary_contributor.php:31842	bookingextension_agent\local\wbagent\summarizer\single_object_result_summary_contributor	summarize
./classes/local/wbagent/task_contract_validator.php:31943	bookingextension_agent\local\wbagent\task_contract_validator	build_task_metadata
./classes/local/wbagent/task_contract_validator.php:31973	bookingextension_agent\local\wbagent\task_contract_validator	build_task_capability_name
./classes/local/wbagent/task_contract_validator.php:31995	bookingextension_agent\local\wbagent\task_contract_validator	validate_task_metadata
./classes/local/wbagent/task_contract_validator.php:32065	bookingextension_agent\local\wbagent\task_contract_validator	validate_registry_contracts
./classes/local/wbagent/task_contract_validator.php:32101	bookingextension_agent\local\wbagent\task_contract_validator	get_deny_reason_priority
./classes/local/wbagent/task_contract_validator.php:32117	bookingextension_agent\local\wbagent\task_contract_validator	extract_task_namespace
./classes/local/wbagent/task_contract_validator.php:32133	bookingextension_agent\local\wbagent\task_contract_validator	is_namespaced_task_name
./classes/local/wbagent/task_contract_validator.php:32145	bookingextension_agent\local\wbagent\task_contract_validator	component_may_register_namespace
./classes/local/wbagent/task_discovery.php:32203	bookingextension_agent\local\wbagent\task_discovery	get_task_instances
./classes/local/wbagent/task_discovery.php:32248	bookingextension_agent\local\wbagent\task_discovery	get_trigger_provider_instances
./classes/local/wbagent/task_discovery.php:32268	bookingextension_agent\local\wbagent\task_discovery	get_last_diagnostics
./classes/local/wbagent/task_discovery.php:32278	bookingextension_agent\local\wbagent\task_discovery	find_candidate_classes
./classes/local/wbagent/task_discovery.php:32332	bookingextension_agent\local\wbagent\task_discovery	get_task_directories
./classes/local/wbagent/task_discovery.php:32357	bookingextension_agent\local\wbagent\task_discovery	instantiate_if_supported
./classes/local/wbagent/task_discovery.php:32385	bookingextension_agent\local\wbagent\task_discovery	ensure_class_loaded
./classes/local/wbagent/task_discovery.php:32424	bookingextension_agent\local\wbagent\task_discovery	add_diagnostic
./classes/local/wbagent/task_discovery.php:32439	bookingextension_agent\local\wbagent\task_discovery	compare_task_classes
./classes/local/wbagent/task_discovery.php:32456	bookingextension_agent\local\wbagent\task_discovery	get_namespace_priority
./classes/local/wbagent/task_executability_evaluator.php:32522	bookingextension_agent\local\wbagent\task_executability_evaluator	__construct
./classes/local/wbagent/task_executability_evaluator.php:32535	bookingextension_agent\local\wbagent\task_executability_evaluator	evaluate_task
./classes/local/wbagent/task_executability_evaluator.php:32589	bookingextension_agent\local\wbagent\task_executability_evaluator	evaluate_all_tasks
./classes/local/wbagent/task_executability_evaluator.php:32607	bookingextension_agent\local\wbagent\task_executability_evaluator	get_executable_task_names
./classes/local/wbagent/task_executability_evaluator.php:32627	bookingextension_agent\local\wbagent\task_executability_evaluator	deny_result
./classes/local/wbagent/task_executability_evaluator.php:32644	bookingextension_agent\local\wbagent\task_executability_evaluator	has_required_capabilities
./classes/local/wbagent/task_executability_evaluator.php:32674	bookingextension_agent\local\wbagent\task_executability_evaluator	is_valid_context
./classes/local/wbagent/task_governance_service.php:32733	bookingextension_agent\local\wbagent\task_governance_service	sync_enableall_toggles
./classes/local/wbagent/task_provider.php:32798	bookingextension_agent\local\wbagent\task_provider	get_component
./classes/local/wbagent/task_provider.php:32807	bookingextension_agent\local\wbagent\task_provider	get_tasks
./classes/local/wbagent/task_provider.php:32819	bookingextension_agent\local\wbagent\task_provider	get_discovery_diagnostics
./classes/local/wbagent/task_provider.php:32828	bookingextension_agent\local\wbagent\task_provider	get_contextual_prompt_packs
./classes/local/wbagent/task_provider.php:32859	bookingextension_agent\local\wbagent\task_provider	get_issue_code_provider
./classes/local/wbagent/task_provider.php:32872	bookingextension_agent\local\wbagent\task_provider	get_prompt_guidance
./classes/local/wbagent/task_provider.php:32883	bookingextension_agent\local\wbagent\task_provider	get_result_summary_contributors
./classes/local/wbagent/task_registry.php:32966	bookingextension_agent\local\wbagent\task_registry	register
./classes/local/wbagent/task_registry.php:33094	bookingextension_agent\local\wbagent\task_registry	get_task
./classes/local/wbagent/task_registry.php:33104	bookingextension_agent\local\wbagent\task_registry	get_provider_for_task
./classes/local/wbagent/task_registry.php:33115	bookingextension_agent\local\wbagent\task_registry	normalize_task_input
./classes/local/wbagent/task_registry.php:33135	bookingextension_agent\local\wbagent\task_registry	get_preview_option_memory_for_task
./classes/local/wbagent/task_registry.php:33149	bookingextension_agent\local\wbagent\task_registry	get_preview_option_memory_helpers
./classes/local/wbagent/task_registry.php:33170	bookingextension_agent\local\wbagent\task_registry	get_task_names
./classes/local/wbagent/task_registry.php:33183	bookingextension_agent\local\wbagent\task_registry	get_task_names_for_context
./classes/local/wbagent/task_registry.php:33201	bookingextension_agent\local\wbagent\task_registry	get_tasks
./classes/local/wbagent/task_registry.php:33211	bookingextension_agent\local\wbagent\task_registry	get_task_contract
./classes/local/wbagent/task_registry.php:33220	bookingextension_agent\local\wbagent\task_registry	get_task_contracts
./classes/local/wbagent/task_registry.php:33229	bookingextension_agent\local\wbagent\task_registry	get_contract_diagnostics
./classes/local/wbagent/task_registry.php:33238	bookingextension_agent\local\wbagent\task_registry	get_result_summary_contributors
./classes/local/wbagent/task_registry.php:33248	bookingextension_agent\local\wbagent\task_registry	is_read_only_task
./classes/local/wbagent/task_registry.php:33259	bookingextension_agent\local\wbagent\task_registry	is_task_active
./classes/local/wbagent/task_registry.php:33285	bookingextension_agent\local\wbagent\task_registry	get_task_toggle_setting_name
./classes/local/wbagent/task_registry.php:33301	bookingextension_agent\local\wbagent\task_registry	get_task_capabilities
./classes/local/wbagent/task_registry.php:33315	bookingextension_agent\local\wbagent\task_registry	get_all_schemas
./classes/local/wbagent/task_registry.php:33332	bookingextension_agent\local\wbagent\task_registry	get_all_schemas_for_context
./classes/local/wbagent/task_registry.php:33362	bookingextension_agent\local\wbagent\task_registry	explain_task_schema_for_context
./classes/local/wbagent/task_registry.php:33390	bookingextension_agent\local\wbagent\task_registry	get_all_prompt_contracts
./classes/local/wbagent/task_registry.php:33407	bookingextension_agent\local\wbagent\task_registry	get_prompt_contracts_for_context
./classes/local/wbagent/task_registry.php:33440	bookingextension_agent\local\wbagent\task_registry	build_prompt_contract
./classes/local/wbagent/task_registry.php:33507	bookingextension_agent\local\wbagent\task_registry	get_contextual_prompt_packs
./classes/local/wbagent/task_registry.php:33534	bookingextension_agent\local\wbagent\task_registry	get_message_triggers
./classes/local/wbagent/task_registry.php:33545	bookingextension_agent\local\wbagent\task_registry	get_trigger_id_to_task_name_map
./classes/local/wbagent/task_registry.php:33556	bookingextension_agent\local\wbagent\task_registry	make_default
./classes/local/wbagent/task_registry.php:33619	bookingextension_agent\local\wbagent\task_registry	register_discovered_tasks_without_provider
./classes/local/wbagent/task_registry.php:33652	bookingextension_agent\local\wbagent\task_registry	__construct
./classes/local/wbagent/task_registry.php:33663	bookingextension_agent\local\wbagent\task_registry	get_component
./classes/local/wbagent/task_registry.php:33672	bookingextension_agent\local\wbagent\task_registry	get_tasks
./classes/local/wbagent/task_registry.php:33681	bookingextension_agent\local\wbagent\task_registry	get_contextual_prompt_packs
./classes/local/wbagent/task_registry.php:33690	bookingextension_agent\local\wbagent\task_registry	get_issue_code_provider
./classes/local/wbagent/task_registry.php:33699	bookingextension_agent\local\wbagent\task_registry	get_prompt_guidance
./classes/local/wbagent/task_registry.php:33708	bookingextension_agent\local\wbagent\task_registry	get_discovery_diagnostics
./classes/local/wbagent/task_registry.php:33732	bookingextension_agent\local\wbagent\task_registry	normalize_provider_component_name
./classes/local/wbagent/task_registry.php:33747	bookingextension_agent\local\wbagent\task_registry	append_provider_discovery_diagnostics
./classes/local/wbagent/task_registry.php:33773	bookingextension_agent\local\wbagent\task_registry	add_contract_diagnostic
./classes/local/wbagent/task_registry.php:33787	bookingextension_agent\local\wbagent\task_registry	fail_on_contract_diagnostics_when_strict
./classes/local/wbagent/task_registry.php:33807	bookingextension_agent\local\wbagent\task_registry	is_governance_strict_mode_enabled
./classes/local/wbagent/task_registry_factory.php:33854	bookingextension_agent\local\wbagent\task_registry_factory	get_default
./classes/local/wbagent/task_registry_factory.php:33875	bookingextension_agent\local\wbagent\task_registry_factory	get_last_build_warning
./classes/local/wbagent/task_registry_factory.php:33886	bookingextension_agent\local\wbagent\task_registry_factory	reset
./classes/task/execute_ai_run_adhoc.php:33947	bookingextension_agent\task\execute_ai_run_adhoc	get_name
./classes/task/execute_ai_run_adhoc.php:33956	bookingextension_agent\task\execute_ai_run_adhoc	execute
./classes/task/rebuild_task_catalog_embeddings_adhoc.php:34096	bookingextension_agent\task\rebuild_task_catalog_embeddings_adhoc	execute
./cli/rebuild_embeddings_fixture.php:34543	bookingextension_agent\task\rebuild_task_catalog_embeddings_adhoc	read_fixture_rows
./cli/rebuild_embeddings_fixture.php:34578	bookingextension_agent\task\rebuild_task_catalog_embeddings_adhoc	write_fixture_rows
./db/upgrade.php:34960	bookingextension_agent\task\rebuild_task_catalog_embeddings_adhoc	xmldb_bookingextension_agent_ensure_ai_messages_userid
./db/upgrade.php:34996	bookingextension_agent\task\rebuild_task_catalog_embeddings_adhoc	xmldb_bookingextension_agent_upgrade
./tests/agent/abstract_agent_testcase.php:36655	bookingextionsion_agent\abstract_agent_testcase	setUp
./tests/agent/abstract_agent_testcase.php:36698	bookingextionsion_agent\abstract_agent_testcase	grant_agent_capabilities_to_editingteacher
./tests/agent/abstract_agent_testcase.php:36764	bookingextionsion_agent\abstract_agent_testcase	maybe_register_live_ai_provider
./tests/agent/abstract_agent_testcase.php:36816	bookingextionsion_agent\abstract_agent_testcase	register_live_wunderbyte_provider
./tests/agent/abstract_agent_testcase.php:36890	bookingextionsion_agent\abstract_agent_testcase	register_live_openai_provider
./tests/agent/abstract_agent_testcase.php:36938	bookingextionsion_agent\abstract_agent_testcase	normalize_chat_endpoint
./tests/agent/abstract_agent_testcase.php:36952	bookingextionsion_agent\abstract_agent_testcase	chat_endpoint_to_embeddings_endpoint
./tests/agent/abstract_agent_testcase.php:36966	bookingextionsion_agent\abstract_agent_testcase	update_provider_actionconfig
./tests/agent/abstract_agent_testcase.php:36989	bookingextionsion_agent\abstract_agent_testcase	configure_wunderbyte_embeddings_model
./tests/agent/abstract_agent_testcase.php:37027	bookingextionsion_agent\abstract_agent_testcase	maybe_load_embeddings_fixture
./tests/agent/abstract_agent_testcase.php:37051	bookingextionsion_agent\abstract_agent_testcase	create_option
./tests/agent/abstract_agent_testcase.php:37080	bookingextionsion_agent\abstract_agent_testcase	make_executor
./tests/agent/abstract_agent_testcase.php:37099	bookingextionsion_agent\abstract_agent_testcase	exec_command
./tests/agent/abstract_agent_testcase.php:37139	bookingextionsion_agent\abstract_agent_testcase	get_option_from_db
./tests/agent/abstract_agent_testcase.php:37149	bookingextionsion_agent\abstract_agent_testcase	get_all_options
./tests/agent/abstract_agent_testcase.php:37163	bookingextionsion_agent\abstract_agent_testcase	require_real_llm
./tests/agent/abstract_agent_testcase.php:37188	bookingextionsion_agent\abstract_agent_testcase	build_runtime
./tests/agent/abstract_agent_testcase.php:37216	bookingextionsion_agent\abstract_agent_testcase	chat
./tests/agent/abstract_agent_testcase.php:37233	bookingextionsion_agent\abstract_agent_testcase	booking_contextid
./tests/agent/abstract_agent_testcase.php:37245	bookingextionsion_agent\abstract_agent_testcase	resolve_queue_item_id_for_confirmation
./tests/agent/abstract_agent_testcase.php:37295	bookingextionsion_agent\abstract_agent_testcase	confirm_pending_result
./tests/agent/abstract_agent_testcase.php:37320	bookingextionsion_agent\abstract_agent_testcase	extract_command
./tests/agent/abstract_agent_testcase.php:37336	bookingextionsion_agent\abstract_agent_testcase	extract_task_result
./tests/agent/abstract_agent_testcase.php:37351	bookingextionsion_agent\abstract_agent_testcase	execute_command
./tests/agent/abstract_agent_testcase.php:37374	bookingextionsion_agent\abstract_agent_testcase	execute_all_commands
./tests/agent/abstract_agent_testcase.php:37403	bookingextionsion_agent\abstract_agent_testcase	assert_generate_text_logged_for_thread
./tests/agent/abstract_agent_testcase.php:37429	bookingextionsion_agent\abstract_agent_testcase	tearDown
./tests/agent/abstract_llm_task_matrix_testcase.php:37494	bookingextionsion_agent\abstract_llm_task_matrix_testcase	setUp
./tests/agent/abstract_llm_task_matrix_testcase.php:37506	bookingextionsion_agent\abstract_llm_task_matrix_testcase	task_matrix_scenarios
./tests/agent/abstract_llm_task_matrix_testcase.php:37516	bookingextionsion_agent\abstract_llm_task_matrix_testcase	assert_llm_task_scenario_success
./tests/agent/abstract_llm_task_matrix_testcase.php:37644	bookingextionsion_agent\abstract_llm_task_matrix_testcase	grant_local_entities_capabilities_to_editingteacher
./tests/agent/abstract_llm_task_matrix_testcase.php:37665	bookingextionsion_agent\abstract_llm_task_matrix_testcase	grant_optional_capability_to_editingteacher
./tests/agent/abstract_llm_task_matrix_testcase.php:37690	bookingextionsion_agent\abstract_llm_task_matrix_testcase	assert_task_is_executable_or_skip
./tests/agent/abstract_llm_task_matrix_testcase.php:37720	bookingextionsion_agent\abstract_llm_task_matrix_testcase	prepare_scenario_runtime
./tests/agent/abstract_llm_task_matrix_testcase.php:37764	bookingextionsion_agent\abstract_llm_task_matrix_testcase	default_scenario_replacements
./tests/agent/abstract_llm_task_matrix_testcase.php:37789	bookingextionsion_agent\abstract_llm_task_matrix_testcase	prepare_recall_memory_scenario
./tests/agent/abstract_llm_task_matrix_testcase.php:37824	bookingextionsion_agent\abstract_llm_task_matrix_testcase	prepare_entity_scenario
./tests/agent/abstract_llm_task_matrix_testcase.php:37863	bookingextionsion_agent\abstract_llm_task_matrix_testcase	prepare_update_option_scenario
./tests/agent/abstract_llm_task_matrix_testcase.php:37905	bookingextionsion_agent\abstract_llm_task_matrix_testcase	prepare_booking_rules_service_scenario
./tests/agent/abstract_llm_task_matrix_testcase.php:37938	bookingextionsion_agent\abstract_llm_task_matrix_testcase	assert_scenario_assertions
./tests/agent/abstract_llm_task_matrix_testcase.php:38044	bookingextionsion_agent\abstract_llm_task_matrix_testcase	payload_text
./tests/agent/abstract_llm_task_matrix_testcase.php:38068	bookingextionsion_agent\abstract_llm_task_matrix_testcase	payload_field_value
./tests/agent/abstract_llm_task_matrix_testcase.php:38102	bookingextionsion_agent\abstract_llm_task_matrix_testcase	payload_field_count
./tests/agent/abstract_llm_task_matrix_testcase.php:38121	bookingextionsion_agent\abstract_llm_task_matrix_testcase	payload_step_count
./tests/agent/abstract_llm_task_matrix_testcase.php:38140	bookingextionsion_agent\abstract_llm_task_matrix_testcase	get_latest_debug_source
./tests/agent/abstract_llm_task_matrix_testcase.php:38163	bookingextionsion_agent\abstract_llm_task_matrix_testcase	render_assertion_value
./tests/agent/abstract_llm_task_matrix_testcase.php:38173	bookingextionsion_agent\abstract_llm_task_matrix_testcase	stringify_assertion_value
./tests/agent/abstract_llm_task_matrix_testcase.php:38189	bookingextionsion_agent\abstract_llm_task_matrix_testcase	resolve_task_result_payload
./tests/agent/abstract_llm_task_matrix_testcase.php:38253	bookingextionsion_agent\abstract_llm_task_matrix_testcase	render_scenario_template
./tests/agent/abstract_llm_task_matrix_testcase.php:38268	bookingextionsion_agent\abstract_llm_task_matrix_testcase	build_fallback_prompt
./tests/agent/abstract_llm_task_matrix_testcase.php:38286	bookingextionsion_agent\abstract_llm_task_matrix_testcase	scenario_matched_expected_task
./tests/agent/abstract_llm_task_matrix_testcase.php:38301	bookingextionsion_agent\abstract_llm_task_matrix_testcase	find_task_result_entry
./tests/agent/abstract_llm_task_matrix_testcase.php:38342	bookingextionsion_agent\abstract_llm_task_matrix_testcase	task_result_candidate_names
./tests/agent/contracts/ai_confirm_run_contract_test.php:38414	bookingextionsion_agent\ai_confirm_run_contract_test	test_follow_up_pending_intent_forces_confirmation_request
./tests/agent/contracts/integration_agent_framework_test.php:38581	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_registry_discovers_booking_tasks
./tests/agent/contracts/integration_agent_framework_test.php:38599	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_provider_interface_supports_issue_code_provider
./tests/agent/contracts/integration_agent_framework_test.php:38620	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_provider_interface_supports_prompt_guidance
./tests/agent/contracts/integration_agent_framework_test.php:38637	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_issue_code_provider_injected_into_agent_runtime
./tests/agent/contracts/integration_agent_framework_test.php:38655	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_schema_includes_prompt_meta
./tests/agent/contracts/integration_agent_framework_test.php:38680	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_registry_prioritizes_prompt_meta
./tests/agent/contracts/integration_agent_framework_test.php:38698	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_prompt_contracts_use_required_minimals_and_explicit_examples
./tests/agent/contracts/integration_agent_framework_test.php:38726	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_slim_catalog_keeps_examples_separate_from_minimals
./tests/agent/contracts/integration_agent_framework_test.php:38757	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_embedding_subset_keeps_full_descriptions
./tests/agent/contracts/integration_agent_framework_test.php:38798	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_embedding_subset_includes_property_descriptions
./tests/agent/contracts/integration_agent_framework_test.php:38832	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_orchestrator_prompts_are_generic
./tests/agent/contracts/integration_agent_framework_test.php:38847	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_action_specific_prompts_generic
./tests/agent/contracts/integration_agent_framework_test.php:38892	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_discovered_tasks_implement_task_interface
./tests/agent/contracts/integration_agent_framework_test.php:38908	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_multi_provider_discovery
./tests/agent/contracts/integration_agent_framework_test.php:38937	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_discovery_scans_all_wbagent_task_namespaces
./tests/agent/contracts/integration_agent_framework_test.php:38955	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_discovery_deduplicates_same_task_name
./tests/agent/contracts/integration_agent_framework_test.php:38967	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_trigger_provider_discovery_ignores_non_trigger_classes
./tests/agent/contracts/integration_agent_framework_test.php:38982	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_tasks_no_language_specific_logic
./tests/agent/contracts/integration_agent_framework_test.php:39003	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_schema_required_fields
./tests/agent/contracts/integration_agent_framework_test.php:39020	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_backward_compatibility_constants
./tests/agent/contracts/mod_booking_option_tasks_contract_test.php:39069	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	test_registry_discovers_canonical_mod_booking_option_tasks
./tests/agent/contracts/mod_booking_option_tasks_contract_test.php:39088	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	test_create_option_defaults_to_type_zero
./tests/agent/contracts/mod_booking_option_tasks_contract_test.php:39116	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	test_create_option_emits_rich_observation_summary
./tests/agent/contracts/mod_booking_option_tasks_contract_test.php:39150	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	test_update_option_sets_type_one_for_selflearning_input
./tests/agent/contracts/mod_booking_option_tasks_contract_test.php:39190	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	test_create_slotbooking_option_requires_slot_fields
./tests/agent/contracts/mod_booking_option_tasks_contract_test.php:39215	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	test_slotbooking_prompt_contracts_are_explicit
./tests/agent/contracts/mod_booking_option_tasks_contract_test.php:39239	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	create_booking_test_context
./tests/agent/contracts/mod_booking_option_tasks_contract_test.php:39265	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	grant_booking_option_task_capabilities
./tests/agent/contracts/pending_intent_and_queue_transition_contract_test.php:39320	bookingextension_agent\local\wbagent\tests\pending_intent_and_queue_transition_contract_test	test_pending_intent_service_set_returns_confirmation_code
./tests/agent/contracts/pending_intent_and_queue_transition_contract_test.php:39345	bookingextension_agent\local\wbagent\tests\pending_intent_and_queue_transition_contract_test	test_queue_transition_service_retry_waiting_transition
./tests/agent/contracts/preflight_contract_validator_contract_test.php:39412	bookingextension_agent\local\wbagent\tests\preflight_contract_validator_contract_test	test_validator_propagates_schema_error_contract
./tests/agent/contracts/preflight_contract_validator_contract_test.php:39437	bookingextension_agent\local\wbagent\tests\preflight_contract_validator_contract_test	test_validator_preserves_deprecation_issue_codes
./tests/agent/contracts/preflight_contract_validator_contract_test.php:39485	bookingextension_agent\local\wbagent\tests\preflight_contract_validator_contract_test	test_validator_blocks_unsupported_version
./tests/agent/contracts/preflight_layers_contract_test.php:39558	bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test	test_domain_runner_hard_blocks_permission_error
./tests/agent/contracts/preflight_layers_contract_test.php:39573	bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test	test_domain_runner_soft_blocks_duplicate_confirm_issue
./tests/agent/contracts/preflight_layers_contract_test.php:39585	bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test	test_execution_gate_retry_hint_for_provider_timeout
./tests/agent/contracts/preflight_layers_contract_test.php:39599	bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test	test_execution_gate_hard_blocks_after_max_retries
./tests/agent/contracts/prompt_and_language_contract_test.php:39649	bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test	test_prompt_contracts_do_not_use_name_based_heuristics
./tests/agent/contracts/prompt_and_language_contract_test.php:39691	bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test	test_language_policy_prefers_user_input_language
./tests/agent/contracts/prompt_and_language_contract_test.php:39720	bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test	test_language_policy_fallback_string_mapping
./tests/agent/contracts/prompt_and_language_contract_test.php:39736	bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test	test_language_policy_matrix_de_en_zh
./tests/agent/contracts/queue_consolidation_contract_test.php:39795	bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test	test_queue_status_policy_actionable_mutating_statuses_are_stable
./tests/agent/contracts/queue_consolidation_contract_test.php:39808	bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test	test_queue_status_policy_pickup_statuses_are_stable
./tests/agent/contracts/queue_consolidation_contract_test.php:39818	bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test	test_queue_command_mapper_prefers_prepared_input_and_preserves_metadata
./tests/agent/contracts/queue_consolidation_contract_test.php:39839	bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test	test_queue_command_mapper_filters_invalid_items_and_falls_back_to_raw_input
./tests/agent/contracts/reference_scenarios_contract_test.php:39887	bookingextension_agent\local\wbagent\tests\reference_scenarios_contract_test	test_scenario_a_readonly_result_contract
./tests/agent/contracts/reference_scenarios_contract_test.php:39904	bookingextension_agent\local\wbagent\tests\reference_scenarios_contract_test	test_scenario_b_multistep_command_schema_contract
./tests/agent/contracts/reference_scenarios_contract_test.php:39920	bookingextension_agent\local\wbagent\tests\reference_scenarios_contract_test	test_scenario_c_spawn_output_binding_contract
./tests/agent/contracts/spawn_contract_service_test.php:39981	bookingextension_agent\local\wbagent\tests\spawn_contract_service_test	test_normalize_task_result_adds_output_aliases
./tests/agent/contracts/spawn_contract_service_test.php:39999	bookingextension_agent\local\wbagent\tests\spawn_contract_service_test	test_apply_output_bindings_resolves_parent_aliases
./tests/agent/contracts/spawn_contract_service_test.php:40016	bookingextension_agent\local\wbagent\tests\spawn_contract_service_test	test_apply_output_bindings_reports_missing_reference
./tests/agent/contracts/spawn_contract_service_test.php:40032	bookingextension_agent\local\wbagent\tests\spawn_contract_service_test	test_normalize_spawn_commands_filters_invalid_entries
./tests/agent/contracts/task_contract_validator_contract_test.php:40095	bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test	test_namespaced_task_name_format
./tests/agent/contracts/task_contract_validator_contract_test.php:40105	bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test	test_reserved_namespace_ownership
./tests/agent/contracts/task_contract_validator_contract_test.php:40116	bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test	test_validate_registry_contracts_rejects_alias_version_mismatch
./tests/agent/contracts/task_contract_validator_contract_test.php:40150	bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test	test_registry_rejects_reserved_namespace_for_third_party_provider
./tests/agent/contracts/task_contract_validator_contract_test.php:40180	bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test	test_demo_task_onboards_via_provider_registration_only
./tests/agent/contracts/task_contract_validator_contract_test.php:40227	bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test	test_failing_provider_does_not_block_other_registered_tasks
./tests/agent/llm_task_matrix_scenario_provider.php:40299	bookingextionsion_agent\llm_task_matrix_scenario_provider	provide_registered_task_scenarios
./tests/agent/llm_task_matrix_scenario_provider.php:40324	bookingextionsion_agent\llm_task_matrix_scenario_provider	get_missing_registered_task_scenarios
./tests/agent/llm_task_matrix_scenario_provider.php:40344	bookingextionsion_agent\llm_task_matrix_scenario_provider	get_scenario_definitions
./tests/agent/real_llm_multistep/all_tasks_real_llm_test.php:41194	bookingextionsion_agent\all_tasks_real_llm_test	setUp
./tests/agent/real_llm_multistep/all_tasks_real_llm_test.php:41206	bookingextionsion_agent\all_tasks_real_llm_test	real_task_matrix_scenarios
./tests/agent/real_llm_multistep/all_tasks_real_llm_test.php:41210	bookingextionsion_agent\all_tasks_real_llm_test	test_task_matrix_covers_all_registered_tasks
./tests/agent/real_llm_multistep/all_tasks_real_llm_test.php:41220	bookingextionsion_agent\all_tasks_real_llm_test	test_all_registered_tasks_can_complete_via_real_llm
./tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php:41271	bookingextionsion_agent\confirmation_flow_real_llm_test	setUp
./tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php:41279	bookingextionsion_agent\confirmation_flow_real_llm_test	test_multistep_create_assign_teacher_and_make_visible
./tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php:41434	bookingextionsion_agent\confirmation_flow_real_llm_test	is_task_available
./tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:41478	bookingextionsion_agent\get_current_user_real_llm_test	setUp
./tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:41483	bookingextionsion_agent\get_current_user_real_llm_test	test_get_current_user_observation_contains_full_user_payload
./tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:41548	bookingextionsion_agent\get_current_user_real_llm_test	payload_text
./tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:41566	bookingextionsion_agent\get_current_user_real_llm_test	has_task_evidence
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:41620	bookingextionsion_agent\lecture_autoconfirm_real_llm_test	setUp
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:41632	bookingextionsion_agent\lecture_autoconfirm_real_llm_test	test_lecture_autoconfirm_single_pass_creates_five_actions
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:41779	bookingextionsion_agent\lecture_autoconfirm_real_llm_test	build_trace_line
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:41798	bookingextionsion_agent\lecture_autoconfirm_real_llm_test	has_create_option_commands
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:41808	bookingextionsion_agent\lecture_autoconfirm_real_llm_test	count_create_option_commands
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:41838	bookingextionsion_agent\lecture_autoconfirm_real_llm_test	is_task_available
./tests/agent/real_llm_multistep/list_actions_real_llm_test.php:41888	bookingextionsion_agent\list_actions_real_llm_test	setUp
./tests/agent/real_llm_multistep/list_actions_real_llm_test.php:41896	bookingextionsion_agent\list_actions_real_llm_test	test_list_actions_groups_by_provider_then_readonly_write_then_capability
./tests/agent/real_llm_multistep/list_actions_real_llm_test.php:41985	bookingextionsion_agent\list_actions_real_llm_test	payload_text
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:42037	bookingextionsion_agent\normal_option_datetime_real_llm_test	setUp
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:42048	bookingextionsion_agent\normal_option_datetime_real_llm_test	test_datetime_prompt_routes_to_create_option_and_type_zero
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:42109	bookingextionsion_agent\normal_option_datetime_real_llm_test	test_weekday_series_prompt_routes_to_create_option_and_creates_five_type_zero_options
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:42330	bookingextionsion_agent\normal_option_datetime_real_llm_test	is_task_available
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:42342	bookingextionsion_agent\normal_option_datetime_real_llm_test	extract_command_from_payload
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:42362	bookingextionsion_agent\normal_option_datetime_real_llm_test	decode_commands_from_payload
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:42384	bookingextionsion_agent\normal_option_datetime_real_llm_test	payload_text
./tests/agent/real_llm_multistep/search_users_real_llm_test.php:42440	bookingextionsion_agent\search_users_real_llm_test	setUp
./tests/agent/real_llm_multistep/search_users_real_llm_test.php:42445	bookingextionsion_agent\search_users_real_llm_test	test_search_users_observation_contains_roles_courses_and_profile
./tests/agent/real_llm_multistep/search_users_real_llm_test.php:42526	bookingextionsion_agent\search_users_real_llm_test	payload_text
./tests/agent/real_llm_multistep/search_users_real_llm_test.php:42544	bookingextionsion_agent\search_users_real_llm_test	has_task_evidence
```

## Methodeninventur (JS Source, vollstaendig)

Hinweis: Erfasst wurden nur Source-Dateien (z. B. amd/src), keine minifizierten Build-Artefakte.

Format: `datei:zeile<TAB>typ<TAB>funktion`

```text
```
