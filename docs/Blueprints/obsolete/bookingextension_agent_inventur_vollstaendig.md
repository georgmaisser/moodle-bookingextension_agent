# Vollstaendige Inventur: bookingextension_agent

- Erfassungsdatum: 2026-06-01
- Plugin-Root: /var/www/moodle/public/mod/booking/bookingextension/agent
- Anzahl Ordner: 58
- Anzahl Dateien: 183
- Anzahl PHP-Methoden/Funktionen (benannte Deklarationen): 945

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
./classes/local/wbagent/interfaces
./classes/local/wbagent/interfaces/summarizer
./classes/local/wbagent/prompts
./classes/local/wbagent/queue
./classes/local/wbagent/services
./classes/local/wbagent/services/catalog
./classes/local/wbagent/services/decision
./classes/local/wbagent/services/embeddings
./classes/local/wbagent/services/execution
./classes/local/wbagent/services/governance
./classes/local/wbagent/services/llm
./classes/local/wbagent/services/lookup
./classes/local/wbagent/services/messaging
./classes/local/wbagent/services/mutation
./classes/local/wbagent/services/planning
./classes/local/wbagent/services/security
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
./lang
./lang/de
./lang/en
./templates
./tests
./tests/agent
./tests/agent/contracts
./tests/agent/fixtures
./tests/agent/real_llm_multistep
```

## Dateiliste (vollstaendig)

```text
./amd/build/aiinstructions.min.js
./amd/build/aiinstructions.min.js.map
./amd/src/aiinstructions.js
./classes/agent.php
./classes/external/activate_trial_context.php
./classes/external/ai_confirm_run.php
./classes/external/ai_discard_pending.php
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
./classes/local/wbagent/agent_runtime.php
./classes/local/wbagent/agent_state.php
./classes/local/wbagent/ai_error_classifier.php
./classes/local/wbagent/aiready.php
./classes/local/wbagent/base_task.php
./classes/local/wbagent/booking_issue_code_provider.php
./classes/local/wbagent/config/command_schema.json
./classes/local/wbagent/conversation_store.php
./classes/local/wbagent/core/tasks/core_task_base.php
./classes/local/wbagent/core/tasks/get_current_user_task.php
./classes/local/wbagent/core/tasks/list_actions_task.php
./classes/local/wbagent/core/tasks/recall_memory_task.php
./classes/local/wbagent/core/tasks/recreate_skill_catalog_task.php
./classes/local/wbagent/core/tasks/search_courses_task.php
./classes/local/wbagent/core/tasks/search_users_task.php
./classes/local/wbagent/dto/bulk_update_options_input_dto.php
./classes/local/wbagent/dto/create_entity_input_dto.php
./classes/local/wbagent/dto/create_option_input_dto.php
./classes/local/wbagent/dto/mutation_result_dto.php
./classes/local/wbagent/dto/update_option_input_dto.php
./classes/local/wbagent/embeddings_action_config_resolver.php
./classes/local/wbagent/embeddings_csv_repository.php
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
./classes/local/wbagent/interfaces/skill_provider_interface.php
./classes/local/wbagent/interfaces/task_result_summary_provider_interface.php
./classes/local/wbagent/interfaces/task_trigger_provider_interface.php
./classes/local/wbagent/interpreter.php
./classes/local/wbagent/llm_debug_logger.php
./classes/local/wbagent/loop_finalizer.php
./classes/local/wbagent/message_trigger_registry.php
./classes/local/wbagent/orchestrator.php
./classes/local/wbagent/preview_policy.php
./classes/local/wbagent/privacy_anonymizer.php
./classes/local/wbagent/prompt_policy_builder.php
./classes/local/wbagent/prompts/initial_system_prompt.md
./classes/local/wbagent/queue/observation_builder.php
./classes/local/wbagent/queue/queue_manager.php
./classes/local/wbagent/result_payload_summarizer.php
./classes/local/wbagent/services/assistant_state_guidance_service.php
./classes/local/wbagent/services/attempt_budget_dto.php
./classes/local/wbagent/services/catalog/adaptive_task_catalog_service.php
./classes/local/wbagent/services/completed_command_history_service.php
./classes/local/wbagent/services/confirm_preview_option_service.php
./classes/local/wbagent/services/confirm_run_service.php
./classes/local/wbagent/services/decision/agent_decision_service.php
./classes/local/wbagent/services/embeddings/embeddings_catalog_builder_service.php
./classes/local/wbagent/services/embeddings/embeddings_readiness_service.php
./classes/local/wbagent/services/embeddings/embeddings_retrieval_service.php
./classes/local/wbagent/services/execution/execution_feedback_service.php
./classes/local/wbagent/services/execution_observation_ledger.php
./classes/local/wbagent/services/finalization_classifier.php
./classes/local/wbagent/services/finalization_template_service.php
./classes/local/wbagent/services/governance/skill_governance_service.php
./classes/local/wbagent/services/language_policy_service.php
./classes/local/wbagent/services/llm/llm_call_service.php
./classes/local/wbagent/services/localized_string_service.php
./classes/local/wbagent/services/lookup/option_lookup_service.php
./classes/local/wbagent/services/messaging/message_persistence_service.php
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
./classes/local/wbagent/services/security/authorization_service.php
./classes/local/wbagent/services/shared_json_payload_extractor.php
./classes/local/wbagent/services/spawn_contract_service.php
./classes/local/wbagent/services/skill_prompt_contract.php
./classes/local/wbagent/services/skill_version_policy.php
./classes/local/wbagent/services/trigger_result_util.php
./classes/local/wbagent/summarizer/basic_collection_result_summary_contributor.php
./classes/local/wbagent/summarizer/diagnosis_result_summary_contributor.php
./classes/local/wbagent/summarizer/docs_result_summary_contributor.php
./classes/local/wbagent/summarizer/single_object_result_summary_contributor.php
./classes/local/wbagent/skill_contract_validator.php
./classes/local/wbagent/skill_discovery.php
./classes/local/wbagent/skill_executability_evaluator.php
./classes/local/wbagent/skill_provider.php
./classes/local/wbagent/skill_registry.php
./classes/local/wbagent/skill_registry_factory.php
./classes/local/wbagent/wunderbyte_trial_endpoint.py
./classes/task/execute_ai_run_adhoc.php
./classes/task/rebuild_skill_catalog_embeddings_adhoc.php
./cli/rebuild_embeddings_fixture.php
./db/access.php
./db/caches.php
./db/install.xml
./db/services.php
./db/upgrade.php
./docs/Blueprints/ROADMAP.md
./docs/Blueprints/architecture_reduction_checklist.md
./docs/Blueprints/bookingextension_agent_inventur_vergleich_2026-05-30.md
./docs/Blueprints/bookingextension_agent_inventur_vollstaendig.md
./docs/Blueprints/bookingextension_agent_konsolidierung_checkliste_vollstaendig.md
./docs/Blueprints/bookingextension_agent_konsolidierung_zwischenstand_2026-05-30.md
./docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd
./docs/Blueprints/flowcharts/ARCHITEKTUR_REVIEW_CHECKLISTE.md
./docs/Blueprints/flowcharts/ARCHITEKTUR_REVIEW_CHECKLISTE_QUEUE_AGENT_FLOW.md
./docs/Blueprints/flowcharts/REFACTOR_SKIZZE_QUEUE_AGENT_FLOW.md
./docs/Blueprints/refactoring_prompt_risk_classes.md
./docs/Blueprints/synchronizer_migration_path.md
./lang/de/bookingextension_agent.php
./lang/en/bookingextension_agent.php
./lib.php
./settings.php
./styles.css
./templates/aiinstructions.mustache
./tests/agent/abstract_agent_testcase.php
./tests/agent/abstract_llm_skill_matrix_testcase.php
./tests/agent/contracts/ai_confirm_run_contract_test.php
./tests/agent/contracts/attempt_budget_dto_contract_test.php
./tests/agent/contracts/finalization_classifier_contract_test.php
./tests/agent/contracts/finalization_template_service_contract_test.php
./tests/agent/contracts/integration_agent_framework_test.php
./tests/agent/contracts/mod_booking_option_skills_contract_test.php
./tests/agent/contracts/pending_intent_and_queue_transition_contract_test.php
./tests/agent/contracts/preflight_audit_logger_contract_test.php
./tests/agent/contracts/preflight_contract_validator_contract_test.php
./tests/agent/contracts/preflight_layers_contract_test.php
./tests/agent/contracts/prompt_and_language_contract_test.php
./tests/agent/contracts/queue_consolidation_contract_test.php
./tests/agent/contracts/reference_scenarios_contract_test.php
./tests/agent/contracts/runtime_finalization_contract_test.php
./tests/agent/contracts/spawn_contract_service_test.php
./tests/agent/contracts/skill_contract_validator_contract_test.php
./tests/agent/fixtures/skill_catalog_embeddings.csv
./tests/agent/llm_skill_matrix_scenario_provider.php
./tests/agent/real_llm_multistep/all_skills_real_llm_test.php
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
./classes/external/activate_trial_context.php:51	bookingextension_agent\external\activate_trial_context	execute_parameters
./classes/external/activate_trial_context.php:63	bookingextension_agent\external\activate_trial_context	execute
./classes/external/activate_trial_context.php:123	bookingextension_agent\external\activate_trial_context	execute_returns
./classes/external/ai_confirm_run.php:59	bookingextension_agent\external\ai_confirm_run	execute_parameters
./classes/external/ai_confirm_run.php:82	bookingextension_agent\external\ai_confirm_run	execute
./classes/external/ai_confirm_run.php:157	bookingextension_agent\external\ai_confirm_run	execute_returns
./classes/external/ai_discard_pending.php:51	bookingextension_agent\external\ai_discard_pending	execute_parameters
./classes/external/ai_discard_pending.php:65	bookingextension_agent\external\ai_discard_pending	execute
./classes/external/ai_discard_pending.php:142	bookingextension_agent\external\ai_discard_pending	execute_returns
./classes/external/ai_get_doc_content.php:54	bookingextension_agent\external\ai_get_doc_content	execute_parameters
./classes/external/ai_get_doc_content.php:68	bookingextension_agent\external\ai_get_doc_content	execute
./classes/external/ai_get_doc_content.php:124	bookingextension_agent\external\ai_get_doc_content	execute_returns
./classes/external/ai_get_doc_content.php:155	bookingextension_agent\external\ai_get_doc_content	markdown_to_html
./classes/external/ai_get_doc_content.php:288	(global)	inline_format
./classes/external/ai_get_doc_content.php:354	(global)	resolve_internal_doc_link
./classes/external/ai_get_doc_content.php:395	(global)	normalize_relative_docs_path
./classes/external/ai_get_doc_content.php:431	(global)	format_non_doc_link
./classes/external/ai_get_doc_content.php:484	(global)	build_moodle_url_from_parts
./classes/external/ai_get_thread_debug_logs.php:52	bookingextension_agent\external\ai_get_thread_debug_logs	execute_parameters
./classes/external/ai_get_thread_debug_logs.php:68	bookingextension_agent\external\ai_get_thread_debug_logs	execute
./classes/external/ai_get_thread_debug_logs.php:138	bookingextension_agent\external\ai_get_thread_debug_logs	execute_returns
./classes/external/ai_list_candidate_options.php:54	bookingextension_agent\external\ai_list_candidate_options	execute_parameters
./classes/external/ai_list_candidate_options.php:68	bookingextension_agent\external\ai_list_candidate_options	execute
./classes/external/ai_list_candidate_options.php:124	bookingextension_agent\external\ai_list_candidate_options	execute_returns
./classes/external/ai_poll_thread.php:52	bookingextension_agent\external\ai_poll_thread	execute_parameters
./classes/external/ai_poll_thread.php:66	bookingextension_agent\external\ai_poll_thread	execute
./classes/external/ai_poll_thread.php:121	bookingextension_agent\external\ai_poll_thread	execute_returns
./classes/external/ai_privacy_precheck.php:48	bookingextension_agent\external\ai_privacy_precheck	execute_parameters
./classes/external/ai_privacy_precheck.php:69	bookingextension_agent\external\ai_privacy_precheck	execute
./classes/external/ai_privacy_precheck.php:153	bookingextension_agent\external\ai_privacy_precheck	execute_returns
./classes/external/ai_render_command_preview.php:55	bookingextension_agent\external\ai_render_command_preview	execute_parameters
./classes/external/ai_render_command_preview.php:97	bookingextension_agent\external\ai_render_command_preview	execute
./classes/external/ai_render_command_preview.php:370	bookingextension_agent\external\ai_render_command_preview	render_preview_table
./classes/external/ai_render_command_preview.php:434	(global)	execute_returns
./classes/external/ai_send_message.php:66	bookingextension_agent\external\ai_send_message	execute_parameters
./classes/external/ai_send_message.php:87	bookingextension_agent\external\ai_send_message	execute
./classes/external/ai_send_message.php:263	bookingextension_agent\external\ai_send_message	normalize_string_list
./classes/external/ai_send_message.php:286	bookingextension_agent\external\ai_send_message	resolve_response_queue_item_id
./classes/external/ai_send_message.php:306	bookingextension_agent\external\ai_send_message	resolve_response_commands
./classes/external/ai_send_message.php:356	bookingextension_agent\external\ai_send_message	resolve_preview_option_ids_json_for_response
./classes/external/ai_send_message.php:404	bookingextension_agent\external\ai_send_message	resolve_preview_option_id_for_response
./classes/external/ai_send_message.php:447	bookingextension_agent\external\ai_send_message	execute_returns
./classes/external/booking_bulk_update_options.php:54	bookingextension_agent\external\booking_bulk_update_options	execute_parameters
./classes/external/booking_bulk_update_options.php:78	bookingextension_agent\external\booking_bulk_update_options	execute
./classes/external/booking_bulk_update_options.php:147	bookingextension_agent\external\booking_bulk_update_options	execute_returns
./classes/external/booking_create_option.php:54	bookingextension_agent\external\booking_create_option	execute_parameters
./classes/external/booking_create_option.php:75	bookingextension_agent\external\booking_create_option	execute
./classes/external/booking_create_option.php:149	bookingextension_agent\external\booking_create_option	execute_returns
./classes/external/booking_update_option.php:54	bookingextension_agent\external\booking_update_option	execute_parameters
./classes/external/booking_update_option.php:78	bookingextension_agent\external\booking_update_option	execute
./classes/external/booking_update_option.php:147	bookingextension_agent\external\booking_update_option	execute_returns
./classes/external/booking_validate_option.php:58	bookingextension_agent\external\booking_validate_option	execute_parameters
./classes/external/booking_validate_option.php:74	bookingextension_agent\external\booking_validate_option	execute
./classes/external/booking_validate_option.php:137	(global)	execute_returns
./classes/external/request_trial_key.php:48	bookingextension_agent\external\request_trial_key	execute_parameters
./classes/external/request_trial_key.php:60	bookingextension_agent\external\request_trial_key	execute
./classes/external/request_trial_key.php:105	bookingextension_agent\external\request_trial_key	execute_returns
./classes/external/ws_message_formatter.php:38	bookingextension_agent\external\ws_message_formatter	format_ws_message
./classes/local/wbagent/agent_runtime.php:93	bookingextension_agent\local\wbagent\agent_runtime	__construct
./classes/local/wbagent/agent_runtime.php:118	bookingextension_agent\local\wbagent\agent_runtime	run
./classes/local/wbagent/agent_runtime.php:133	bookingextension_agent\local\wbagent\agent_runtime	run_loop
./classes/local/wbagent/agent_runtime.php:176	bookingextension_agent\local\wbagent\agent_runtime	finalize_terminal_result
./classes/local/wbagent/agent_runtime.php:186	bookingextension_agent\local\wbagent\agent_runtime	resolve_cmid_from_contextid
./classes/local/wbagent/agent_runtime.php:206	bookingextension_agent\local\wbagent\agent_runtime	finalize_and_persist_result
./classes/local/wbagent/agent_runtime.php:224	bookingextension_agent\local\wbagent\agent_runtime	apply_finalization_strategy
./classes/local/wbagent/agent_runtime.php:245	bookingextension_agent\local\wbagent\agent_runtime	apply_template_only_finalization
./classes/local/wbagent/agent_runtime.php:271	bookingextension_agent\local\wbagent\agent_runtime	apply_synchronizer_message_polish
./classes/local/wbagent/agent_runtime.php:324	bookingextension_agent\local\wbagent\agent_runtime	merge_synchronized_message
./classes/local/wbagent/agent_runtime.php:362	bookingextension_agent\local\wbagent\agent_runtime	finalize_and_persist_budget_exceeded
./classes/local/wbagent/agent_runtime.php:379	bookingextension_agent\local\wbagent\agent_runtime	budget_guard_allows_next_llm_call
./classes/local/wbagent/agent_runtime.php:392	bookingextension_agent\local\wbagent\agent_runtime	build_budget_exceeded_result
./classes/local/wbagent/agent_runtime.php:437	bookingextension_agent\local\wbagent\agent_runtime	enforce_final_response_contract
./classes/local/wbagent/agent_runtime.php:516	bookingextension_agent\local\wbagent\agent_runtime	strip_markdown_fences_from_message
./classes/local/wbagent/agent_runtime.php:544	bookingextension_agent\local\wbagent\agent_runtime	build_contract_fallback_message
./classes/local/wbagent/agent_runtime.php:582	bookingextension_agent\local\wbagent\agent_runtime	attach_loop_results
./classes/local/wbagent/agent_runtime.php:603	bookingextension_agent\local\wbagent\agent_runtime	run_internal
./classes/local/wbagent/agent_runtime.php:656	bookingextension_agent\local\wbagent\agent_runtime	call_orchestrator_step
./classes/local/wbagent/agent_runtime.php:673	bookingextension_agent\local\wbagent\agent_runtime	resolve_output_language
./classes/local/wbagent/agent_state.php:77	bookingextension_agent\local\wbagent\agent_state	__construct
./classes/local/wbagent/agent_state.php:87	bookingextension_agent\local\wbagent\agent_state	make
./classes/local/wbagent/agent_state.php:101	bookingextension_agent\local\wbagent\agent_state	make_resumed
./classes/local/wbagent/agent_state.php:124	bookingextension_agent\local\wbagent\agent_state	record_step
./classes/local/wbagent/agent_state.php:146	bookingextension_agent\local\wbagent\agent_state	get_observations
./classes/local/wbagent/agent_state.php:155	bookingextension_agent\local\wbagent\agent_state	get_steps
./classes/local/wbagent/agent_state.php:164	bookingextension_agent\local\wbagent\agent_state	step_count
./classes/local/wbagent/agent_state.php:173	bookingextension_agent\local\wbagent\agent_state	has_observations
./classes/local/wbagent/agent_state.php:186	bookingextension_agent\local\wbagent\agent_state	extract_observed_command_signatures
./classes/local/wbagent/agent_state.php:227	bookingextension_agent\local\wbagent\agent_state	normalize_command_input
./classes/local/wbagent/ai_error_classifier.php:53	bookingextension_agent\local\wbagent\ai_error_classifier	classify_from_response
./classes/local/wbagent/ai_error_classifier.php:130	bookingextension_agent\local\wbagent\ai_error_classifier	classify_from_db
./classes/local/wbagent/aiready.php:70	bookingextension_agent\local\wbagent\aiready	__construct
./classes/local/wbagent/aiready.php:81	bookingextension_agent\local\wbagent\aiready	export_for_template
./classes/local/wbagent/aiready.php:289	bookingextension_agent\local\wbagent\aiready	build_check
./classes/local/wbagent/aiready.php:307	bookingextension_agent\local\wbagent\aiready	is_module_ai_toggle_enabled
./classes/local/wbagent/aiready.php:321	bookingextension_agent\local\wbagent\aiready	get_booking_statistics
./classes/local/wbagent/base_task.php:47	bookingextension_agent\local\wbagent\base_task	__construct
./classes/local/wbagent/base_task.php:56	bookingextension_agent\local\wbagent\base_task	is_read_only
./classes/local/wbagent/base_task.php:68	bookingextension_agent\local\wbagent\base_task	get_example_input
./classes/local/wbagent/base_task.php:77	bookingextension_agent\local\wbagent\base_task	get_prompt_contract
./classes/local/wbagent/base_task.php:106	bookingextension_agent\local\wbagent\base_task	check_structure
./classes/local/wbagent/base_task.php:118	bookingextension_agent\local\wbagent\base_task	preflight
./classes/local/wbagent/booking_issue_code_provider.php:36	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_duplicate_confirmation_issue_codes
./classes/local/wbagent/booking_issue_code_provider.php:48	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_token_subscription_issue_codes
./classes/local/wbagent/booking_issue_code_provider.php:63	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_prevalidation_confirmable_issue_codes
./classes/local/wbagent/booking_issue_code_provider.php:80	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_basic_subscription_url
./classes/local/wbagent/booking_issue_code_provider.php:89	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_premium_subscription_url
./classes/local/wbagent/conversation_store.php:54	bookingextension_agent\local\wbagent\conversation_store	get_active_thread
./classes/local/wbagent/conversation_store.php:74	bookingextension_agent\local\wbagent\conversation_store	get_or_create_thread
./classes/local/wbagent/conversation_store.php:109	bookingextension_agent\local\wbagent\conversation_store	create_fresh_thread
./classes/local/wbagent/conversation_store.php:149	bookingextension_agent\local\wbagent\conversation_store	add_message
./classes/local/wbagent/conversation_store.php:180	bookingextension_agent\local\wbagent\conversation_store	add_step_message
./classes/local/wbagent/conversation_store.php:197	bookingextension_agent\local\wbagent\conversation_store	clear_step_messages
./classes/local/wbagent/conversation_store.php:211	bookingextension_agent\local\wbagent\conversation_store	get_step_messages_since
./classes/local/wbagent/conversation_store.php:231	bookingextension_agent\local\wbagent\conversation_store	get_messages
./classes/local/wbagent/conversation_store.php:242	bookingextension_agent\local\wbagent\conversation_store	get_thread
./classes/local/wbagent/conversation_store.php:255	bookingextension_agent\local\wbagent\conversation_store	get_recent_messages
./classes/local/wbagent/conversation_store.php:282	bookingextension_agent\local\wbagent\conversation_store	get_last_thread_for_user
./classes/local/wbagent/conversation_store.php:353	bookingextension_agent\local\wbagent\conversation_store	get_user_threads_by_date_window
./classes/local/wbagent/conversation_store.php:392	bookingextension_agent\local\wbagent\conversation_store	get_user_messages_for_thread
./classes/local/wbagent/conversation_store.php:456	bookingextension_agent\local\wbagent\conversation_store	create_run
./classes/local/wbagent/conversation_store.php:482	bookingextension_agent\local\wbagent\conversation_store	update_run_status
./classes/local/wbagent/conversation_store.php:502	bookingextension_agent\local\wbagent\conversation_store	get_run
./classes/local/wbagent/conversation_store.php:513	bookingextension_agent\local\wbagent\conversation_store	get_latest_run
./classes/local/wbagent/conversation_store.php:526	bookingextension_agent\local\wbagent\conversation_store	run_exists
./classes/local/wbagent/conversation_store.php:538	bookingextension_agent\local\wbagent\conversation_store	run_exists_other_than
./classes/local/wbagent/conversation_store.php:559	bookingextension_agent\local\wbagent\conversation_store	get_thread_metadata_value
./classes/local/wbagent/conversation_store.php:583	bookingextension_agent\local\wbagent\conversation_store	set_thread_metadata_value
./classes/local/wbagent/conversation_store.php:616	bookingextension_agent\local\wbagent\conversation_store	set_pending_intent
./classes/local/wbagent/conversation_store.php:660	bookingextension_agent\local\wbagent\conversation_store	get_pending_intent
./classes/local/wbagent/conversation_store.php:693	bookingextension_agent\local\wbagent\conversation_store	consume_pending_intent
./classes/local/wbagent/conversation_store.php:719	bookingextension_agent\local\wbagent\conversation_store	clear_pending_intent
./classes/local/wbagent/conversation_store.php:731	bookingextension_agent\local\wbagent\conversation_store	allow_confirmation_for_session
./classes/local/wbagent/conversation_store.php:747	bookingextension_agent\local\wbagent\conversation_store	allow_confirmation_for_thread
./classes/local/wbagent/conversation_store.php:765	bookingextension_agent\local\wbagent\conversation_store	is_confirmation_allowed_for_session
./classes/local/wbagent/conversation_store.php:781	bookingextension_agent\local\wbagent\conversation_store	is_confirmation_allowed_for_thread
./classes/local/wbagent/conversation_store.php:795	bookingextension_agent\local\wbagent\conversation_store	clear_confirmation_allowance
./classes/local/wbagent/conversation_store.php:808	bookingextension_agent\local\wbagent\conversation_store	make_confirmation_session_allowlist_key
./classes/local/wbagent/conversation_store.php:818	bookingextension_agent\local\wbagent\conversation_store	get_confirmation_session_allowlist
./classes/local/wbagent/conversation_store.php:866	bookingextension_agent\local\wbagent\conversation_store	save_confirmation_session_allowlist
./classes/local/wbagent/conversation_store.php:883	bookingextension_agent\local\wbagent\conversation_store	add_llm_debug_entry
./classes/local/wbagent/conversation_store.php:916	bookingextension_agent\local\wbagent\conversation_store	get_llm_debug_entries
./classes/local/wbagent/core/tasks/core_task_base.php:38	bookingextension_agent\local\wbagent\core\tasks\core_task_base	get_output_language
./classes/local/wbagent/core/tasks/core_task_base.php:57	bookingextension_agent\local\wbagent\core\tasks\core_task_base	localized_string
./classes/local/wbagent/core/tasks/core_task_base.php:74	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_task_debug_message
./classes/local/wbagent/core/tasks/core_task_base.php:106	bookingextension_agent\local\wbagent\core\tasks\core_task_base	enrich_schema_with_prompt_meta
./classes/local/wbagent/core/tasks/core_task_base.php:143	bookingextension_agent\local\wbagent\core\tasks\core_task_base	stringify_debug_value
./classes/local/wbagent/core/tasks/core_task_base.php:159	bookingextension_agent\local\wbagent\core\tasks\core_task_base	resolve_userid
./classes/local/wbagent/core/tasks/core_task_base.php:190	bookingextension_agent\local\wbagent\core\tasks\core_task_base	resolve_courseid
./classes/local/wbagent/core/tasks/core_task_base.php:215	bookingextension_agent\local\wbagent\core\tasks\core_task_base	resolve_groupid
./classes/local/wbagent/core/tasks/core_task_base.php:249	bookingextension_agent\local\wbagent\core\tasks\core_task_base	can_access_user
./classes/local/wbagent/core/tasks/core_task_base.php:280	bookingextension_agent\local\wbagent\core\tasks\core_task_base	preflight
./classes/local/wbagent/core/tasks/core_task_base.php:302	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_user_payload
./classes/local/wbagent/core/tasks/core_task_base.php:355	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_user_courses_payload
./classes/local/wbagent/core/tasks/core_task_base.php:402	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_user_roles_payload
./classes/local/wbagent/core/tasks/core_task_base.php:450	bookingextension_agent\local\wbagent\core\tasks\core_task_base	extract_custom_profile_fields
./classes/local/wbagent/core/tasks/core_task_base.php:470	bookingextension_agent\local\wbagent\core\tasks\core_task_base	search_user_candidates_for_preview
./classes/local/wbagent/core/tasks/core_task_base.php:513	bookingextension_agent\local\wbagent\core\tasks\core_task_base	search_course_candidates_for_preview
./classes/local/wbagent/core/tasks/core_task_base.php:562	bookingextension_agent\local\wbagent\core\tasks\core_task_base	count_active_course_enrolments
./classes/local/wbagent/core/tasks/core_task_base.php:581	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_user_observation_full
./classes/local/wbagent/core/tasks/core_task_base.php:639	(global)	format_observation_scalar
./classes/local/wbagent/core/tasks/core_task_base.php:656	(global)	format_course_observation
./classes/local/wbagent/core/tasks/core_task_base.php:684	(global)	format_role_observation
./classes/local/wbagent/core/tasks/core_task_base.php:712	(global)	format_custom_profile_field_observation
./classes/local/wbagent/core/tasks/get_current_user_task.php:34	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	__construct
./classes/local/wbagent/core/tasks/get_current_user_task.php:43	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	get_name
./classes/local/wbagent/core/tasks/get_current_user_task.php:52	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	get_schema
./classes/local/wbagent/core/tasks/get_current_user_task.php:73	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	check_structure
./classes/local/wbagent/core/tasks/get_current_user_task.php:86	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	get_message_triggers
./classes/local/wbagent/core/tasks/get_current_user_task.php:105	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	get_contextual_prompt_packs
./classes/local/wbagent/core/tasks/get_current_user_task.php:131	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	execute
./classes/local/wbagent/core/tasks/list_actions_task.php:41	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	__construct
./classes/local/wbagent/core/tasks/list_actions_task.php:50	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	get_name
./classes/local/wbagent/core/tasks/list_actions_task.php:59	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	get_schema
./classes/local/wbagent/core/tasks/list_actions_task.php:91	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	get_message_triggers
./classes/local/wbagent/core/tasks/list_actions_task.php:110	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	check_structure
./classes/local/wbagent/core/tasks/list_actions_task.php:133	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	get_contextual_prompt_packs
./classes/local/wbagent/core/tasks/list_actions_task.php:159	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	execute
./classes/local/wbagent/core/tasks/list_actions_task.php:239	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	build_observation_full
./classes/local/wbagent/core/tasks/list_actions_task.php:274	(global)	get_localized_string
./classes/local/wbagent/core/tasks/list_actions_task.php:288	(global)	build_debug_summary
./classes/local/wbagent/core/tasks/list_actions_task.php:312	(global)	build_user_summary
./classes/local/wbagent/core/tasks/list_actions_task.php:391	(global)	describe_deny_reason
./classes/local/wbagent/core/tasks/list_actions_task.php:423	(global)	build_unavailable_action_detail
./classes/local/wbagent/core/tasks/list_actions_task.php:452	(global)	build_user_capabilities
./classes/local/wbagent/core/tasks/recall_memory_task.php:36	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	__construct
./classes/local/wbagent/core/tasks/recall_memory_task.php:45	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	get_name
./classes/local/wbagent/core/tasks/recall_memory_task.php:54	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	get_schema
./classes/local/wbagent/core/tasks/recall_memory_task.php:107	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	get_example_input
./classes/local/wbagent/core/tasks/recall_memory_task.php:119	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	check_structure
./classes/local/wbagent/core/tasks/recall_memory_task.php:145	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	get_message_triggers
./classes/local/wbagent/core/tasks/recall_memory_task.php:175	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	execute
./classes/local/wbagent/core/tasks/recall_memory_task.php:280	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	resolve_date_window
./classes/local/wbagent/core/tasks/recall_memory_task.php:329	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	resolve_user_timezone
./classes/local/wbagent/core/tasks/recall_memory_task.php:359	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	build_memory_observation_text
./classes/local/wbagent/core/tasks/recreate_skill_catalog_task.php:37	bookingextension_agent\local\wbagent\core\tasks\recreate_skill_catalog_task	__construct
./classes/local/wbagent/core/tasks/recreate_skill_catalog_task.php:46	bookingextension_agent\local\wbagent\core\tasks\recreate_skill_catalog_task	get_name
./classes/local/wbagent/core/tasks/recreate_skill_catalog_task.php:55	bookingextension_agent\local\wbagent\core\tasks\recreate_skill_catalog_task	get_schema
./classes/local/wbagent/core/tasks/recreate_skill_catalog_task.php:93	bookingextension_agent\local\wbagent\core\tasks\recreate_skill_catalog_task	get_message_triggers
./classes/local/wbagent/core/tasks/recreate_skill_catalog_task.php:112	bookingextension_agent\local\wbagent\core\tasks\recreate_skill_catalog_task	check_structure
./classes/local/wbagent/core/tasks/recreate_skill_catalog_task.php:137	bookingextension_agent\local\wbagent\core\tasks\recreate_skill_catalog_task	execute
./classes/local/wbagent/core/tasks/search_courses_task.php:35	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	__construct
./classes/local/wbagent/core/tasks/search_courses_task.php:44	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	get_name
./classes/local/wbagent/core/tasks/search_courses_task.php:53	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	get_schema
./classes/local/wbagent/core/tasks/search_courses_task.php:107	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	get_message_triggers
./classes/local/wbagent/core/tasks/search_courses_task.php:125	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	get_contextual_prompt_packs
./classes/local/wbagent/core/tasks/search_courses_task.php:154	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	check_structure
./classes/local/wbagent/core/tasks/search_courses_task.php:175	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	execute
./classes/local/wbagent/core/tasks/search_courses_task.php:229	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	build_course_observation_full
./classes/local/wbagent/core/tasks/search_courses_task.php:269	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	resolve_query
./classes/local/wbagent/core/tasks/search_users_task.php:35	bookingextension_agent\local\wbagent\core\tasks\search_users_task	__construct
./classes/local/wbagent/core/tasks/search_users_task.php:44	bookingextension_agent\local\wbagent\core\tasks\search_users_task	get_name
./classes/local/wbagent/core/tasks/search_users_task.php:53	bookingextension_agent\local\wbagent\core\tasks\search_users_task	get_schema
./classes/local/wbagent/core/tasks/search_users_task.php:86	bookingextension_agent\local\wbagent\core\tasks\search_users_task	get_message_triggers
./classes/local/wbagent/core/tasks/search_users_task.php:105	bookingextension_agent\local\wbagent\core\tasks\search_users_task	get_contextual_prompt_packs
./classes/local/wbagent/core/tasks/search_users_task.php:133	bookingextension_agent\local\wbagent\core\tasks\search_users_task	check_structure
./classes/local/wbagent/core/tasks/search_users_task.php:157	bookingextension_agent\local\wbagent\core\tasks\search_users_task	execute
./classes/local/wbagent/core/tasks/search_users_task.php:234	bookingextension_agent\local\wbagent\core\tasks\search_users_task	normalize_query_input
./classes/local/wbagent/core/tasks/search_users_task.php:287	bookingextension_agent\local\wbagent\core\tasks\search_users_task	build_query_retry_hint
./classes/local/wbagent/dto/bulk_update_options_input_dto.php:45	bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto	__construct
./classes/local/wbagent/dto/bulk_update_options_input_dto.php:55	bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto	from_array
./classes/local/wbagent/dto/bulk_update_options_input_dto.php:64	bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto	to_array
./classes/local/wbagent/dto/bulk_update_options_input_dto.php:75	bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto	get
./classes/local/wbagent/dto/create_entity_input_dto.php:45	bookingextension_agent\local\wbagent\dto\create_entity_input_dto	__construct
./classes/local/wbagent/dto/create_entity_input_dto.php:56	bookingextension_agent\local\wbagent\dto\create_entity_input_dto	from_array
./classes/local/wbagent/dto/create_entity_input_dto.php:68	bookingextension_agent\local\wbagent\dto\create_entity_input_dto	to_array
./classes/local/wbagent/dto/create_entity_input_dto.php:79	bookingextension_agent\local\wbagent\dto\create_entity_input_dto	get
./classes/local/wbagent/dto/create_option_input_dto.php:45	bookingextension_agent\local\wbagent\dto\create_option_input_dto	__construct
./classes/local/wbagent/dto/create_option_input_dto.php:56	bookingextension_agent\local\wbagent\dto\create_option_input_dto	from_array
./classes/local/wbagent/dto/create_option_input_dto.php:68	bookingextension_agent\local\wbagent\dto\create_option_input_dto	to_array
./classes/local/wbagent/dto/create_option_input_dto.php:79	bookingextension_agent\local\wbagent\dto\create_option_input_dto	get
./classes/local/wbagent/dto/mutation_result_dto.php:63	bookingextension_agent\local\wbagent\dto\mutation_result_dto	__construct
./classes/local/wbagent/dto/mutation_result_dto.php:86	bookingextension_agent\local\wbagent\dto\mutation_result_dto	success
./classes/local/wbagent/dto/mutation_result_dto.php:101	bookingextension_agent\local\wbagent\dto\mutation_result_dto	error
./classes/local/wbagent/dto/mutation_result_dto.php:111	bookingextension_agent\local\wbagent\dto\mutation_result_dto	skipped
./classes/local/wbagent/dto/mutation_result_dto.php:122	bookingextension_agent\local\wbagent\dto\mutation_result_dto	dry_run_ok
./classes/local/wbagent/dto/mutation_result_dto.php:131	bookingextension_agent\local\wbagent\dto\mutation_result_dto	to_array
./classes/local/wbagent/dto/update_option_input_dto.php:45	bookingextension_agent\local\wbagent\dto\update_option_input_dto	__construct
./classes/local/wbagent/dto/update_option_input_dto.php:55	bookingextension_agent\local\wbagent\dto\update_option_input_dto	from_array
./classes/local/wbagent/dto/update_option_input_dto.php:64	bookingextension_agent\local\wbagent\dto\update_option_input_dto	to_array
./classes/local/wbagent/dto/update_option_input_dto.php:75	bookingextension_agent\local\wbagent\dto\update_option_input_dto	get
./classes/local/wbagent/embeddings_action_config_resolver.php:50	bookingextension_agent\local\wbagent\embeddings_action_config_resolver	resolve
./classes/local/wbagent/embeddings_csv_repository.php:53	bookingextension_agent\local\wbagent\embeddings_csv_repository	get_csv_path
./classes/local/wbagent/embeddings_csv_repository.php:63	bookingextension_agent\local\wbagent\embeddings_csv_repository	exists
./classes/local/wbagent/embeddings_csv_repository.php:72	bookingextension_agent\local\wbagent\embeddings_csv_repository	read_rows
./classes/local/wbagent/embeddings_csv_repository.php:107	bookingextension_agent\local\wbagent\embeddings_csv_repository	is_valid_schema
./classes/local/wbagent/embeddings_csv_repository.php:133	bookingextension_agent\local\wbagent\embeddings_csv_repository	write_rows
./classes/local/wbagent/embeddings_csv_repository.php:162	bookingextension_agent\local\wbagent\embeddings_csv_repository	headers_match
./classes/local/wbagent/embeddings_csv_repository.php:181	bookingextension_agent\local\wbagent\embeddings_csv_repository	get_default_file_permissions
./classes/local/wbagent/executor.php:71	bookingextension_agent\local\wbagent\executor	__construct
./classes/local/wbagent/executor.php:95	bookingextension_agent\local\wbagent\executor	execute_commands
./classes/local/wbagent/executor.php:232	bookingextension_agent\local\wbagent\executor	build_safe_executed_input
./classes/local/wbagent/interfaces/agent_authorization_service.php:48	bookingextension_agent\local\wbagent\interfaces\agent_authorization_service	require_use_capability
./classes/local/wbagent/interfaces/agent_authorization_service.php:57	bookingextension_agent\local\wbagent\interfaces\agent_authorization_service	can_use
./classes/local/wbagent/interfaces/agent_authorization_service.php:67	bookingextension_agent\local\wbagent\interfaces\agent_authorization_service	require_valid_context
./classes/local/wbagent/interfaces/agent_conversation_store.php:43	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_or_create_thread
./classes/local/wbagent/interfaces/agent_conversation_store.php:54	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	add_message
./classes/local/wbagent/interfaces/agent_conversation_store.php:62	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_messages
./classes/local/wbagent/interfaces/agent_conversation_store.php:71	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_recent_messages
./classes/local/wbagent/interfaces/agent_conversation_store.php:80	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_last_thread_for_user
./classes/local/wbagent/interfaces/agent_conversation_store.php:91	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_user_threads_by_date_window
./classes/local/wbagent/interfaces/agent_conversation_store.php:108	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_user_messages_for_thread
./classes/local/wbagent/interfaces/agent_conversation_store.php:126	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	create_run
./classes/local/wbagent/interfaces/agent_conversation_store.php:136	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	update_run_status
./classes/local/wbagent/interfaces/agent_conversation_store.php:144	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_run
./classes/local/wbagent/interfaces/agent_conversation_store.php:152	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_latest_run
./classes/local/wbagent/interfaces/agent_conversation_store.php:160	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	run_exists
./classes/local/wbagent/interfaces/agent_executor.php:53	bookingextension_agent\local\wbagent\interfaces\agent_executor	execute_commands
./classes/local/wbagent/interfaces/agent_interpreter.php:60	bookingextension_agent\local\wbagent\interfaces\agent_interpreter	interpret
./classes/local/wbagent/interfaces/issue_code_provider_interface.php:38	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_duplicate_confirmation_issue_codes
./classes/local/wbagent/interfaces/issue_code_provider_interface.php:49	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_token_subscription_issue_codes
./classes/local/wbagent/interfaces/issue_code_provider_interface.php:61	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_prevalidation_confirmable_issue_codes
./classes/local/wbagent/interfaces/issue_code_provider_interface.php:68	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_basic_subscription_url
./classes/local/wbagent/interfaces/issue_code_provider_interface.php:75	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_premium_subscription_url
./classes/local/wbagent/interfaces/preview_option_memory_interface.php:35	bookingextension_agent\local\wbagent\interfaces\preview_option_memory_interface	remember_last_preview_options_for_execute
./classes/local/wbagent/interfaces/preview_option_memory_interface.php:44	bookingextension_agent\local\wbagent\interfaces\preview_option_memory_interface	resolve_last_preview_option_ids_for_execute
./classes/local/wbagent/interfaces/preview_option_memory_provider_interface.php:32	bookingextension_agent\local\wbagent\interfaces\preview_option_memory_provider_interface	get_preview_option_memory
./classes/local/wbagent/interfaces/queue_identity_provider_interface.php:36	bookingextension_agent\local\wbagent\interfaces\queue_identity_provider_interface	build_queue_business_identity
./classes/local/wbagent/interfaces/result_summary_provider_interface.php:35	bookingextension_agent\local\wbagent\interfaces\result_summary_provider_interface	get_result_summary_contributors
./classes/local/wbagent/interfaces/summarizer/result_summary_contributor_interface.php:40	bookingextension_agent\local\wbagent\interfaces\summarizer\result_summary_contributor_interface	supports
./classes/local/wbagent/interfaces/summarizer/result_summary_contributor_interface.php:51	bookingextension_agent\local\wbagent\interfaces\summarizer\result_summary_contributor_interface	summarize
./classes/local/wbagent/interfaces/task_input_normalizer_interface.php:34	bookingextension_agent\local\wbagent\interfaces\task_input_normalizer_interface	normalize
./classes/local/wbagent/interfaces/task_input_normalizer_provider_interface.php:32	bookingextension_agent\local\wbagent\interfaces\task_input_normalizer_provider_interface	get_task_input_normalizer
./classes/local/wbagent/interfaces/task_interface.php:46	bookingextension_agent\local\wbagent\interfaces\task_interface	get_name
./classes/local/wbagent/interfaces/task_interface.php:53	bookingextension_agent\local\wbagent\interfaces\task_interface	get_schema
./classes/local/wbagent/interfaces/task_interface.php:63	bookingextension_agent\local\wbagent\interfaces\task_interface	get_example_input
./classes/local/wbagent/interfaces/task_interface.php:70	bookingextension_agent\local\wbagent\interfaces\task_interface	get_prompt_contract
./classes/local/wbagent/interfaces/task_interface.php:82	bookingextension_agent\local\wbagent\interfaces\task_interface	check_structure
./classes/local/wbagent/interfaces/task_interface.php:96	bookingextension_agent\local\wbagent\interfaces\task_interface	preflight
./classes/local/wbagent/interfaces/task_interface.php:110	bookingextension_agent\local\wbagent\interfaces\task_interface	execute
./classes/local/wbagent/interfaces/task_interface.php:117	bookingextension_agent\local\wbagent\interfaces\task_interface	is_read_only
./classes/local/wbagent/interfaces/skill_provider_interface.php:32	bookingextension_agent\local\wbagent\interfaces\skill_provider_interface	get_component
./classes/local/wbagent/interfaces/skill_provider_interface.php:39	bookingextension_agent\local\wbagent\interfaces\skill_provider_interface	get_tasks
./classes/local/wbagent/interfaces/skill_provider_interface.php:46	bookingextension_agent\local\wbagent\interfaces\skill_provider_interface	get_contextual_prompt_packs
./classes/local/wbagent/interfaces/skill_provider_interface.php:56	bookingextension_agent\local\wbagent\interfaces\skill_provider_interface	get_issue_code_provider
./classes/local/wbagent/interfaces/skill_provider_interface.php:66	bookingextension_agent\local\wbagent\interfaces\skill_provider_interface	get_prompt_guidance
./classes/local/wbagent/interfaces/task_result_summary_provider_interface.php:39	bookingextension_agent\local\wbagent\interfaces\task_result_summary_provider_interface	summarize_task_result
./classes/local/wbagent/interfaces/task_trigger_provider_interface.php:36	bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface	get_message_triggers
./classes/local/wbagent/interpreter.php:76	bookingextension_agent\local\wbagent\interpreter	__construct
./classes/local/wbagent/interpreter.php:88	bookingextension_agent\local\wbagent\interpreter	interpret
./classes/local/wbagent/interpreter.php:315	bookingextension_agent\local\wbagent\interpreter	normalize_commands_payload
./classes/local/wbagent/interpreter.php:380	bookingextension_agent\local\wbagent\interpreter	extract_flat_command_input
./classes/local/wbagent/interpreter.php:395	bookingextension_agent\local\wbagent\interpreter	prune_empty_input_values
./classes/local/wbagent/interpreter.php:427	bookingextension_agent\local\wbagent\interpreter	with_optional_next_step_intent
./classes/local/wbagent/interpreter.php:442	bookingextension_agent\local\wbagent\interpreter	looks_like_completed_action_intent
./classes/local/wbagent/interpreter.php:474	bookingextension_agent\local\wbagent\interpreter	normalize_task_like_response
./classes/local/wbagent/interpreter.php:584	bookingextension_agent\local\wbagent\interpreter	resolve_task_name_alias
./classes/local/wbagent/interpreter.php:611	bookingextension_agent\local\wbagent\interpreter	hydrate_question_field
./classes/local/wbagent/interpreter.php:636	bookingextension_agent\local\wbagent\interpreter	extract_command_input
./classes/local/wbagent/interpreter.php:649	bookingextension_agent\local\wbagent\interpreter	parse
./classes/local/wbagent/interpreter.php:671	bookingextension_agent\local\wbagent\interpreter	sanitize_json_payload
./classes/local/wbagent/interpreter.php:706	bookingextension_agent\local\wbagent\interpreter	truncate_parse_excerpt
./classes/local/wbagent/interpreter.php:725	bookingextension_agent\local\wbagent\interpreter	extract_used_triggers
./classes/local/wbagent/interpreter.php:745	bookingextension_agent\local\wbagent\interpreter	validate_commands
./classes/local/wbagent/interpreter.php:868	bookingextension_agent\local\wbagent\interpreter	normalize_ambiguity_options
./classes/local/wbagent/interpreter.php:906	bookingextension_agent\local\wbagent\interpreter	normalize_self_user_references
./classes/local/wbagent/interpreter.php:947	bookingextension_agent\local\wbagent\interpreter	canonicalize_command_input
./classes/local/wbagent/interpreter.php:981	bookingextension_agent\local\wbagent\interpreter	normalize_timestamp_value
./classes/local/wbagent/interpreter.php:1034	bookingextension_agent\local\wbagent\interpreter	error_result
./classes/local/wbagent/interpreter.php:1048	bookingextension_agent\local\wbagent\interpreter	error_result_with_issue_code
./classes/local/wbagent/interpreter.php:1069	bookingextension_agent\local\wbagent\interpreter	safe_string
./classes/local/wbagent/interpreter.php:1082	bookingextension_agent\local\wbagent\interpreter	clarification_message
./classes/local/wbagent/interpreter.php:1110	bookingextension_agent\local\wbagent\interpreter	confirmation_message_from_ambiguities
./classes/local/wbagent/interpreter.php:1128	bookingextension_agent\local\wbagent\interpreter	user_facing_validation_message
./classes/local/wbagent/interpreter.php:1139	bookingextension_agent\local\wbagent\interpreter	strip_command_prefix
./classes/local/wbagent/llm_debug_logger.php:38	bookingextension_agent\local\wbagent\llm_debug_logger	is_enabled
./classes/local/wbagent/llm_debug_logger.php:59	bookingextension_agent\local\wbagent\llm_debug_logger	log_exchange
./classes/local/wbagent/llm_debug_logger.php:101	bookingextension_agent\local\wbagent\llm_debug_logger	log_exchange_always
./classes/local/wbagent/loop_finalizer.php:46	bookingextension_agent\local\wbagent\loop_finalizer	finalize
./classes/local/wbagent/loop_finalizer.php:76	bookingextension_agent\local\wbagent\loop_finalizer	should_finalize_after_execution_result
./classes/local/wbagent/loop_finalizer.php:131	bookingextension_agent\local\wbagent\loop_finalizer	build_sufficient_execution_result_clarification
./classes/local/wbagent/loop_finalizer.php:185	bookingextension_agent\local\wbagent\loop_finalizer	maybe_enrich_message_from_results
./classes/local/wbagent/loop_finalizer.php:226	bookingextension_agent\local\wbagent\loop_finalizer	is_low_information_message
./classes/local/wbagent/message_trigger_registry.php:84	bookingextension_agent\local\wbagent\message_trigger_registry	__construct
./classes/local/wbagent/message_trigger_registry.php:93	bookingextension_agent\local\wbagent\message_trigger_registry	get_available_triggers
./classes/local/wbagent/message_trigger_registry.php:125	bookingextension_agent\local\wbagent\message_trigger_registry	get_available_trigger_ids
./classes/local/wbagent/message_trigger_registry.php:136	bookingextension_agent\local\wbagent\message_trigger_registry	normalize_used_triggers
./classes/local/wbagent/message_trigger_registry.php:164	bookingextension_agent\local\wbagent\message_trigger_registry	normalize_response_type
./classes/local/wbagent/orchestrator.php:120	bookingextension_agent\local\wbagent\orchestrator	__construct
./classes/local/wbagent/orchestrator.php:162	bookingextension_agent\local\wbagent\orchestrator	get_runtime_provider_status
./classes/local/wbagent/orchestrator.php:313	bookingextension_agent\local\wbagent\orchestrator	process
./classes/local/wbagent/orchestrator.php:589	bookingextension_agent\local\wbagent\orchestrator	get_default_initial_prompt_template
./classes/local/wbagent/orchestrator.php:609	bookingextension_agent\local\wbagent\orchestrator	get_default_initial_prompt_template_for_action
./classes/local/wbagent/orchestrator.php:716	bookingextension_agent\local\wbagent\orchestrator	get_default_summary_prompt_prefix
./classes/local/wbagent/orchestrator.php:725	bookingextension_agent\local\wbagent\orchestrator	get_default_initial_prompt_template_path
./classes/local/wbagent/orchestrator.php:742	bookingextension_agent\local\wbagent\orchestrator	build_system_prompt
./classes/local/wbagent/orchestrator.php:830	bookingextension_agent\local\wbagent\orchestrator	slim_prompt_catalog_for_planner
./classes/local/wbagent/orchestrator.php:869	bookingextension_agent\local\wbagent\orchestrator	compact_catalog_description
./classes/local/wbagent/orchestrator.php:891	bookingextension_agent\local\wbagent\orchestrator	compact_catalog_example_input
./classes/local/wbagent/orchestrator.php:917	bookingextension_agent\local\wbagent\orchestrator	compact_catalog_message_triggers
./classes/local/wbagent/orchestrator.php:960	bookingextension_agent\local\wbagent\orchestrator	extract_recent_task_names_from_messages
./classes/local/wbagent/orchestrator.php:996	bookingextension_agent\local\wbagent\orchestrator	is_first_assistant_turn
./classes/local/wbagent/orchestrator.php:1022	bookingextension_agent\local\wbagent\orchestrator	build_prompt
./classes/local/wbagent/orchestrator.php:1086	(global)	build_local_output_contract_block
./classes/local/wbagent/orchestrator.php:1121	(global)	normalize_planner_trace_history
./classes/local/wbagent/orchestrator.php:1156	(global)	append_planner_traces_and_observations
./classes/local/wbagent/orchestrator.php:1190	(global)	build_runtime_context_block
./classes/local/wbagent/orchestrator.php:1259	(global)	append_json_object_section
./classes/local/wbagent/orchestrator.php:1278	(global)	append_json_list_section
./classes/local/wbagent/orchestrator.php:1301	(global)	json_encode_or_empty
./classes/local/wbagent/orchestrator.php:1316	(global)	availability_from_deny_reason
./classes/local/wbagent/orchestrator.php:1338	(global)	sanitize_unavailable_task_catalog
./classes/local/wbagent/orchestrator.php:1350	(global)	build_task_description_index
./classes/local/wbagent/preview_policy.php:55	bookingextension_agent\local\wbagent\preview_policy	supports_preview
./classes/local/wbagent/preview_policy.php:66	bookingextension_agent\local\wbagent\preview_policy	filter_previewable_commands
./classes/local/wbagent/preview_policy.php:79	bookingextension_agent\local\wbagent\preview_policy	has_previewable_command
./classes/local/wbagent/privacy_anonymizer.php:73	bookingextension_agent\local\wbagent\privacy_anonymizer	__construct
./classes/local/wbagent/privacy_anonymizer.php:82	bookingextension_agent\local\wbagent\privacy_anonymizer	get_mode
./classes/local/wbagent/privacy_anonymizer.php:100	bookingextension_agent\local\wbagent\privacy_anonymizer	looks_like_anon_token
./classes/local/wbagent/privacy_anonymizer.php:109	bookingextension_agent\local\wbagent\privacy_anonymizer	should_anonymize_user_input
./classes/local/wbagent/privacy_anonymizer.php:118	bookingextension_agent\local\wbagent\privacy_anonymizer	should_anonymize_llm_backend_data
./classes/local/wbagent/privacy_anonymizer.php:129	bookingextension_agent\local\wbagent\privacy_anonymizer	precheck_user_message
./classes/local/wbagent/privacy_anonymizer.php:174	bookingextension_agent\local\wbagent\privacy_anonymizer	deanonymize_command_input
./classes/local/wbagent/privacy_anonymizer.php:197	bookingextension_agent\local\wbagent\privacy_anonymizer	deanonymize_command_input_for_active_user
./classes/local/wbagent/privacy_anonymizer.php:217	bookingextension_agent\local\wbagent\privacy_anonymizer	deanonymize_message_for_display
./classes/local/wbagent/privacy_anonymizer.php:281	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_value_for_llm
./classes/local/wbagent/privacy_anonymizer.php:301	bookingextension_agent\local\wbagent\privacy_anonymizer	deanonymize_recursive
./classes/local/wbagent/privacy_anonymizer.php:335	bookingextension_agent\local\wbagent\privacy_anonymizer	resolve_token_entry
./classes/local/wbagent/privacy_anonymizer.php:363	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_value_recursive
./classes/local/wbagent/privacy_anonymizer.php:391	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_string_for_llm
./classes/local/wbagent/privacy_anonymizer.php:424	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_labeled_user_fields
./classes/local/wbagent/privacy_anonymizer.php:479	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_person_field_value
./classes/local/wbagent/privacy_anonymizer.php:537	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_emails
./classes/local/wbagent/privacy_anonymizer.php:571	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_names
./classes/local/wbagent/privacy_anonymizer.php:733	bookingextension_agent\local\wbagent\privacy_anonymizer	find_email_spans
./classes/local/wbagent/privacy_anonymizer.php:766	bookingextension_agent\local\wbagent\privacy_anonymizer	offset_overlaps_email_span
./classes/local/wbagent/privacy_anonymizer.php:783	bookingextension_agent\local\wbagent\privacy_anonymizer	get_user_name_match_index
./classes/local/wbagent/privacy_anonymizer.php:857	bookingextension_agent\local\wbagent\privacy_anonymizer	user_sets_intersect
./classes/local/wbagent/privacy_anonymizer.php:876	bookingextension_agent\local\wbagent\privacy_anonymizer	get_distinct_name_index
./classes/local/wbagent/privacy_anonymizer.php:922	bookingextension_agent\local\wbagent\privacy_anonymizer	normalize_name
./classes/local/wbagent/privacy_anonymizer.php:940	bookingextension_agent\local\wbagent\privacy_anonymizer	get_token_map
./classes/local/wbagent/privacy_anonymizer.php:965	bookingextension_agent\local\wbagent\privacy_anonymizer	set_token_map
./classes/local/wbagent/privacy_anonymizer.php:978	bookingextension_agent\local\wbagent\privacy_anonymizer	get_or_create_token
./classes/local/wbagent/privacy_anonymizer.php:1081	bookingextension_agent\local\wbagent\privacy_anonymizer	scope_identity_key_for_type
./classes/local/wbagent/privacy_anonymizer.php:1096	bookingextension_agent\local\wbagent\privacy_anonymizer	build_field_token_from_base
./classes/local/wbagent/privacy_anonymizer.php:1116	bookingextension_agent\local\wbagent\privacy_anonymizer	extract_base_token_from_anon_token
./classes/local/wbagent/privacy_anonymizer.php:1134	bookingextension_agent\local\wbagent\privacy_anonymizer	resolve_entry_for_field
./classes/local/wbagent/privacy_anonymizer.php:1170	bookingextension_agent\local\wbagent\privacy_anonymizer	resolve_identity_from_email
./classes/local/wbagent/privacy_anonymizer.php:1209	bookingextension_agent\local\wbagent\privacy_anonymizer	resolve_identity_from_user_ids
./classes/local/wbagent/privacy_anonymizer.php:1234	bookingextension_agent\local\wbagent\privacy_anonymizer	load_user_identity_record
./classes/local/wbagent/privacy_anonymizer.php:1252	bookingextension_agent\local\wbagent\privacy_anonymizer	build_identity_variants_from_user_record
./classes/local/wbagent/privacy_anonymizer.php:1282	bookingextension_agent\local\wbagent\privacy_anonymizer	merge_identity_variants
./classes/local/wbagent/privacy_anonymizer.php:1303	bookingextension_agent\local\wbagent\privacy_anonymizer	array_contains_person_identity_fields
./classes/local/wbagent/privacy_anonymizer.php:1320	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_person_identity_field_group
./classes/local/wbagent/privacy_anonymizer.php:1373	bookingextension_agent\local\wbagent\privacy_anonymizer	is_user_reference_field
./classes/local/wbagent/prompt_policy_builder.php:51	bookingextension_agent\local\wbagent\prompt_policy_builder	build_all_policies
./classes/local/wbagent/prompt_policy_builder.php:99	bookingextension_agent\local\wbagent\prompt_policy_builder	build_response_contract_policy
./classes/local/wbagent/prompt_policy_builder.php:139	bookingextension_agent\local\wbagent\prompt_policy_builder	build_trigger_policy
./classes/local/wbagent/prompt_policy_builder.php:158	bookingextension_agent\local\wbagent\prompt_policy_builder	build_trigger_policy_compact
./classes/local/wbagent/prompt_policy_builder.php:174	bookingextension_agent\local\wbagent\prompt_policy_builder	build_routing_determinism_policy
./classes/local/wbagent/prompt_policy_builder.php:197	bookingextension_agent\local\wbagent\prompt_policy_builder	build_step_intent_policy
./classes/local/wbagent/prompt_policy_builder.php:226	bookingextension_agent\local\wbagent\prompt_policy_builder	is_planner_step_type
./classes/local/wbagent/prompt_policy_builder.php:237	bookingextension_agent\local\wbagent\prompt_policy_builder	build_docs_answer_policy
./classes/local/wbagent/prompt_policy_builder.php:257	bookingextension_agent\local\wbagent\prompt_policy_builder	build_sufficiency_policy
./classes/local/wbagent/prompt_policy_builder.php:295	bookingextension_agent\local\wbagent\prompt_policy_builder	build_follow_up_state_policy
./classes/local/wbagent/queue/observation_builder.php:39	bookingextension_agent\local\wbagent\queue\observation_builder	build_observation
./classes/local/wbagent/queue/queue_manager.php:70	bookingextension_agent\local\wbagent\queue\queue_manager	__construct
./classes/local/wbagent/queue/queue_manager.php:87	bookingextension_agent\local\wbagent\queue\queue_manager	enqueue_command
./classes/local/wbagent/queue/queue_manager.php:211	bookingextension_agent\local\wbagent\queue\queue_manager	update_status
./classes/local/wbagent/queue/queue_manager.php:260	bookingextension_agent\local\wbagent\queue\queue_manager	get_queue_items
./classes/local/wbagent/queue/queue_manager.php:272	bookingextension_agent\local\wbagent\queue\queue_manager	get_queue_item
./classes/local/wbagent/queue/queue_manager.php:294	bookingextension_agent\local\wbagent\queue\queue_manager	save_queue_items
./classes/local/wbagent/queue/queue_manager.php:307	bookingextension_agent\local\wbagent\queue\queue_manager	set_prepared_input
./classes/local/wbagent/queue/queue_manager.php:334	bookingextension_agent\local\wbagent\queue\queue_manager	has_running_item
./classes/local/wbagent/queue/queue_manager.php:360	bookingextension_agent\local\wbagent\queue\queue_manager	try_mark_running
./classes/local/wbagent/queue/queue_manager.php:443	(global)	can_pickup_now
./classes/local/wbagent/queue/queue_manager.php:474	(global)	dependencies_succeeded
./classes/local/wbagent/queue/queue_manager.php:485	(global)	dependencies_succeeded_from_items
./classes/local/wbagent/queue/queue_manager.php:530	(global)	validate_depends_on_is_dag
./classes/local/wbagent/queue/queue_manager.php:565	(global)	fail_expired_blocked_items
./classes/local/wbagent/queue/queue_manager.php:602	(global)	build_input_signature
./classes/local/wbagent/queue/queue_manager.php:614	(global)	build_input_signature_details
./classes/local/wbagent/queue/queue_manager.php:653	(global)	normalize_for_signature
./classes/local/wbagent/queue/queue_manager.php:676	(global)	next_sequence
./classes/local/wbagent/queue/queue_manager.php:689	(global)	resolve_thread_contextid
./classes/local/wbagent/queue/queue_manager.php:705	(global)	resolve_blocked_expires_at
./classes/local/wbagent/queue/queue_manager.php:727	(global)	dfs_cycle_detect
./classes/local/wbagent/result_payload_summarizer.php:65	bookingextension_agent\local\wbagent\result_payload_summarizer	for_observation
./classes/local/wbagent/result_payload_summarizer.php:127	(global)	describe_result_for_state
./classes/local/wbagent/result_payload_summarizer.php:149	(global)	detect_result_category
./classes/local/wbagent/result_payload_summarizer.php:191	(global)	describe_entry
./classes/local/wbagent/result_payload_summarizer.php:367	(global)	compact_text
./classes/local/wbagent/result_payload_summarizer.php:389	(global)	summarize_with_contributors
./classes/local/wbagent/result_payload_summarizer.php:415	(global)	build_summary_context
./classes/local/wbagent/result_payload_summarizer.php:441	(global)	summarize_with_skill_provider
./classes/local/wbagent/services/assistant_state_guidance_service.php:41	bookingextension_agent\local\wbagent\services\assistant_state_guidance_service	__construct
./classes/local/wbagent/services/assistant_state_guidance_service.php:51	bookingextension_agent\local\wbagent\services\assistant_state_guidance_service	build_assistant_state_blocks
./classes/local/wbagent/services/assistant_state_guidance_service.php:83	bookingextension_agent\local\wbagent\services\assistant_state_guidance_service	build_contextual_guidance
./classes/local/wbagent/services/assistant_state_guidance_service.php:127	bookingextension_agent\local\wbagent\services\assistant_state_guidance_service	normalize_nonempty_string_list
./classes/local/wbagent/services/assistant_state_guidance_service.php:153	bookingextension_agent\local\wbagent\services\assistant_state_guidance_service	summarize_structured_state
./classes/local/wbagent/services/assistant_state_guidance_service.php:193	bookingextension_agent\local\wbagent\services\assistant_state_guidance_service	extract_result_facts
./classes/local/wbagent/services/assistant_state_guidance_service.php:249	bookingextension_agent\local\wbagent\services\assistant_state_guidance_service	matches_contextual_pack
./classes/local/wbagent/services/attempt_budget_dto.php:65	bookingextension_agent\local\wbagent\services\attempt_budget_dto	__construct
./classes/local/wbagent/services/attempt_budget_dto.php:91	bookingextension_agent\local\wbagent\services\attempt_budget_dto	from_loop
./classes/local/wbagent/services/attempt_budget_dto.php:111	bookingextension_agent\local\wbagent\services\attempt_budget_dto	from_queue_item
./classes/local/wbagent/services/attempt_budget_dto.php:131	bookingextension_agent\local\wbagent\services\attempt_budget_dto	to_array
./classes/local/wbagent/services/catalog/adaptive_task_catalog_service.php:70	bookingextension_agent\local\wbagent\services\catalog\adaptive_task_catalog_service	get_adaptive_catalog
./classes/local/wbagent/services/catalog/adaptive_task_catalog_service.php:110	bookingextension_agent\local\wbagent\services\catalog\adaptive_task_catalog_service	get_mandatory_tasks
./classes/local/wbagent/services/catalog/adaptive_task_catalog_service.php:136	bookingextension_agent\local\wbagent\services\catalog\adaptive_task_catalog_service	get_recency_filtered
./classes/local/wbagent/services/completed_command_history_service.php:42	bookingextension_agent\local\wbagent\services\completed_command_history_service	__construct
./classes/local/wbagent/services/completed_command_history_service.php:52	bookingextension_agent\local\wbagent\services\completed_command_history_service	extract_from_messages
./classes/local/wbagent/services/completed_command_history_service.php:131	bookingextension_agent\local\wbagent\services\completed_command_history_service	merge_from_queue
./classes/local/wbagent/services/completed_command_history_service.php:202	bookingextension_agent\local\wbagent\services\completed_command_history_service	build_signature
./classes/local/wbagent/services/completed_command_history_service.php:228	bookingextension_agent\local\wbagent\services\completed_command_history_service	normalize_input
./classes/local/wbagent/services/completed_command_history_service.php:261	bookingextension_agent\local\wbagent\services\completed_command_history_service	normalize_value
./classes/local/wbagent/services/confirm_preview_option_service.php:44	bookingextension_agent\local\wbagent\services\confirm_preview_option_service	__construct
./classes/local/wbagent/services/confirm_preview_option_service.php:57	bookingextension_agent\local\wbagent\services\confirm_preview_option_service	resolve_preview_option_ids_for_response
./classes/local/wbagent/services/confirm_preview_option_service.php:99	bookingextension_agent\local\wbagent\services\confirm_preview_option_service	first_preview_option_id
./classes/local/wbagent/services/confirm_preview_option_service.php:120	bookingextension_agent\local\wbagent\services\confirm_preview_option_service	remember_confirm_preview_option_ids
./classes/local/wbagent/services/confirm_preview_option_service.php:149	bookingextension_agent\local\wbagent\services\confirm_preview_option_service	resolve_confirm_preview_option_ids_for_response
./classes/local/wbagent/services/confirm_preview_option_service.php:181	bookingextension_agent\local\wbagent\services\confirm_preview_option_service	merge_preview_option_ids
./classes/local/wbagent/services/confirm_run_service.php:75	bookingextension_agent\local\wbagent\services\confirm_run_service	__construct
./classes/local/wbagent/services/confirm_run_service.php:95	bookingextension_agent\local\wbagent\services\confirm_run_service	confirm
./classes/local/wbagent/services/confirm_run_service.php:679	bookingextension_agent\local\wbagent\services\confirm_run_service	build_error_payload
./classes/local/wbagent/services/confirm_run_service.php:716	bookingextension_agent\local\wbagent\services\confirm_run_service	build_preview_response_fields
./classes/local/wbagent/services/confirm_run_service.php:730	bookingextension_agent\local\wbagent\services\confirm_run_service	has_successful_execution_results
./classes/local/wbagent/services/confirm_run_service.php:751	bookingextension_agent\local\wbagent\services\confirm_run_service	normalize_string_list
./classes/local/wbagent/services/confirm_run_service.php:777	bookingextension_agent\local\wbagent\services\confirm_run_service	build_retry_decision
./classes/local/wbagent/services/confirm_run_service.php:834	bookingextension_agent\local\wbagent\services\confirm_run_service	build_queue_audit_context
./classes/local/wbagent/services/confirm_run_service.php:859	bookingextension_agent\local\wbagent\services\confirm_run_service	should_continue_with_runtime_loop
./classes/local/wbagent/services/confirm_run_service.php:882	bookingextension_agent\local\wbagent\services\confirm_run_service	find_next_mutating_queue_item
./classes/local/wbagent/services/confirm_run_service.php:908	bookingextension_agent\local\wbagent\services\confirm_run_service	extract_attempted_skills_from_commands
./classes/local/wbagent/services/confirm_run_service.php:933	bookingextension_agent\local\wbagent\services\confirm_run_service	resolve_pending_queue_item_id
./classes/local/wbagent/services/confirm_run_service.php:971	bookingextension_agent\local\wbagent\services\confirm_run_service	resolve_commands_for_run
./classes/local/wbagent/services/confirm_run_service.php:992	bookingextension_agent\local\wbagent\services\confirm_run_service	mark_dependents_skipped
./classes/local/wbagent/services/confirm_run_service.php:1037	bookingextension_agent\local\wbagent\services\confirm_run_service	get_active_mutating_queue_item
./classes/local/wbagent/services/confirm_run_service.php:1061	bookingextension_agent\local\wbagent\services\confirm_run_service	is_actionable_mutating_queue_item
./classes/local/wbagent/services/decision/agent_decision_service.php:133	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	__construct
./classes/local/wbagent/services/decision/agent_decision_service.php:169	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	process
./classes/local/wbagent/services/decision/agent_decision_service.php:311	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	should_block_new_intent_while_pending
./classes/local/wbagent/services/decision/agent_decision_service.php:341	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	build_pending_resolution_clarification
./classes/local/wbagent/services/decision/agent_decision_service.php:383	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	build_pending_intent_summary
./classes/local/wbagent/services/decision/agent_decision_service.php:402	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	build_fallback_message
./classes/local/wbagent/services/decision/agent_decision_service.php:454	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	handle_confirm_pending
./classes/local/wbagent/services/decision/agent_decision_service.php:570	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	handle_command_routing
./classes/local/wbagent/services/decision/agent_decision_service.php:736	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	handle_preflight
./classes/local/wbagent/services/decision/agent_decision_service.php:918	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	apply_execution_guard_tokens
./classes/local/wbagent/services/decision/agent_decision_service.php:956	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	persist_pending_intent_pointer
./classes/local/wbagent/services/decision/agent_decision_service.php:986	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	execute_readonly_commands
./classes/local/wbagent/services/decision/agent_decision_service.php:1205	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	inject_output_language_into_commands
./classes/local/wbagent/services/decision/agent_decision_service.php:1231	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	with_output_language
./classes/local/wbagent/services/decision/agent_decision_service.php:1263	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	has_mutating_commands
./classes/local/wbagent/services/decision/agent_decision_service.php:1288	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	split_commands_by_mutability
./classes/local/wbagent/services/decision/agent_decision_service.php:1314	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	execution_result_has_failures
./classes/local/wbagent/services/decision/agent_decision_service.php:1340	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	has_confirmable_prevalidation_issues
./classes/local/wbagent/services/decision/agent_decision_service.php:1358	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	clarification_result
./classes/local/wbagent/services/decision/agent_decision_service.php:1383	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	clarification_result_with_context
./classes/local/wbagent/services/decision/agent_decision_service.php:1409	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	build_confirm_pending_no_intent_fallback
./classes/local/wbagent/services/decision/agent_decision_service.php:1440	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	localized
./classes/local/wbagent/services/decision/agent_decision_service.php:1450	bookingextension_agent\local\wbagent\services\decision\agent_decision_service	normalize_queue_item_ids
./classes/local/wbagent/services/embeddings/embeddings_catalog_builder_service.php:43	bookingextension_agent\local\wbagent\services\embeddings\embeddings_catalog_builder_service	build_full_catalog_rows
./classes/local/wbagent/services/embeddings/embeddings_catalog_builder_service.php:106	bookingextension_agent\local\wbagent\services\embeddings\embeddings_catalog_builder_service	compute_content_hash
./classes/local/wbagent/services/embeddings/embeddings_catalog_builder_service.php:122	bookingextension_agent\local\wbagent\services\embeddings\embeddings_catalog_builder_service	to_embedding_input
./classes/local/wbagent/services/embeddings/embeddings_catalog_builder_service.php:147	bookingextension_agent\local\wbagent\services\embeddings\embeddings_catalog_builder_service	get_contextual_prompt_packs_for_task
./classes/local/wbagent/services/embeddings/embeddings_readiness_service.php:45	bookingextension_agent\local\wbagent\services\embeddings\embeddings_readiness_service	is_wunderbyte_embeddings_available
./classes/local/wbagent/services/embeddings/embeddings_readiness_service.php:57	bookingextension_agent\local\wbagent\services\embeddings\embeddings_readiness_service	get_catalog_status
./classes/local/wbagent/services/embeddings/embeddings_readiness_service.php:115	bookingextension_agent\local\wbagent\services\embeddings\embeddings_readiness_service	ensure_rebuild_scheduled_if_needed
./classes/local/wbagent/services/embeddings/embeddings_retrieval_service.php:43	bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service	search_top_k
./classes/local/wbagent/services/embeddings/embeddings_retrieval_service.php:77	bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service	build_planner_catalog_subset
./classes/local/wbagent/services/embeddings/embeddings_retrieval_service.php:136	bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service	build_live_contract_lookup
./classes/local/wbagent/services/embeddings/embeddings_retrieval_service.php:192	bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service	compact_properties_for_planner
./classes/local/wbagent/services/embeddings/embeddings_retrieval_service.php:229	bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service	cosine_similarity
./classes/local/wbagent/services/embeddings/embeddings_retrieval_service.php:260	bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service	decode_json_array
./classes/local/wbagent/services/execution/execution_feedback_service.php:51	bookingextension_agent\local\wbagent\services\execution\execution_feedback_service	__construct
./classes/local/wbagent/services/execution/execution_feedback_service.php:70	bookingextension_agent\local\wbagent\services\execution\execution_feedback_service	build_completion_feedback
./classes/local/wbagent/services/execution/execution_feedback_service.php:91	bookingextension_agent\local\wbagent\services\execution\execution_feedback_service	sanitize_results_for_client
./classes/local/wbagent/services/execution/execution_feedback_service.php:258	bookingextension_agent\local\wbagent\services\execution\execution_feedback_service	sanitize_result_detail
./classes/local/wbagent/services/execution/execution_feedback_service.php:347	bookingextension_agent\local\wbagent\services\execution\execution_feedback_service	fallback_message_for_results
./classes/local/wbagent/services/execution/execution_feedback_service.php:407	bookingextension_agent\local\wbagent\services\execution\execution_feedback_service	extract_primary_link_from_result
./classes/local/wbagent/services/execution/execution_feedback_service.php:427	bookingextension_agent\local\wbagent\services\execution\execution_feedback_service	extract_primary_link_from_results
./classes/local/wbagent/services/execution/execution_feedback_service.php:450	bookingextension_agent\local\wbagent\services\execution\execution_feedback_service	localized
./classes/local/wbagent/services/execution/execution_feedback_service.php:464	bookingextension_agent\local\wbagent\services\execution\execution_feedback_service	localized_list_count_message
./classes/local/wbagent/services/execution/execution_feedback_service.php:487	bookingextension_agent\local\wbagent\services\execution\execution_feedback_service	append_link_to_message
./classes/local/wbagent/services/execution_observation_ledger.php:50	bookingextension_agent\local\wbagent\services\execution_observation_ledger	__construct
./classes/local/wbagent/services/execution_observation_ledger.php:62	bookingextension_agent\local\wbagent\services\execution_observation_ledger	append_from_results
./classes/local/wbagent/services/execution_observation_ledger.php:158	bookingextension_agent\local\wbagent\services\execution_observation_ledger	get_recent_for_runtime
./classes/local/wbagent/services/execution_observation_ledger.php:204	bookingextension_agent\local\wbagent\services\execution_observation_ledger	read_entries
./classes/local/wbagent/services/execution_observation_ledger.php:219	bookingextension_agent\local\wbagent\services\execution_observation_ledger	normalize_input
./classes/local/wbagent/services/execution_observation_ledger.php:247	bookingextension_agent\local\wbagent\services\execution_observation_ledger	normalize_value
./classes/local/wbagent/services/execution_observation_ledger.php:269	bookingextension_agent\local\wbagent\services\execution_observation_ledger	build_signature
./classes/local/wbagent/services/finalization_classifier.php:79	bookingextension_agent\local\wbagent\services\finalization_classifier	classify
./classes/local/wbagent/services/finalization_classifier.php:125	bookingextension_agent\local\wbagent\services\finalization_classifier	has_commands
./classes/local/wbagent/services/finalization_classifier.php:149	bookingextension_agent\local\wbagent\services\finalization_classifier	normalize_issue_codes
./classes/local/wbagent/services/finalization_classifier.php:173	bookingextension_agent\local\wbagent\services\finalization_classifier	contains_any
./classes/local/wbagent/services/finalization_template_service.php:67	bookingextension_agent\local\wbagent\services\finalization_template_service	resolve_message
./classes/local/wbagent/services/finalization_template_service.php:88	bookingextension_agent\local\wbagent\services\finalization_template_service	normalize_issue_codes
./classes/local/wbagent/services/governance/skill_governance_service.php:54	bookingextension_agent\local\wbagent\services\governance\skill_governance_service	sync_enableall_toggles
./classes/local/wbagent/services/language_policy_service.php:47	bookingextension_agent\local\wbagent\services\language_policy_service	normalize_iso_language
./classes/local/wbagent/services/language_policy_service.php:60	bookingextension_agent\local\wbagent\services\language_policy_service	resolve_output_language
./classes/local/wbagent/services/language_policy_service.php:84	bookingextension_agent\local\wbagent\services\language_policy_service	fallback_string_id_for_response_type
./classes/local/wbagent/services/language_policy_service.php:104	bookingextension_agent\local\wbagent\services\language_policy_service	preflight_retry_hint_string_id
./classes/local/wbagent/services/llm/llm_call_service.php:59	bookingextension_agent\local\wbagent\services\llm\llm_call_service	__construct
./classes/local/wbagent/services/llm/llm_call_service.php:74	bookingextension_agent\local\wbagent\services\llm\llm_call_service	invoke
./classes/local/wbagent/services/llm/llm_call_service.php:139	bookingextension_agent\local\wbagent\services\llm\llm_call_service	invoke_embeddings
./classes/local/wbagent/services/llm/llm_call_service.php:220	bookingextension_agent\local\wbagent\services\llm\llm_call_service	build_prompt_action
./classes/local/wbagent/services/llm/llm_call_service.php:259	bookingextension_agent\local\wbagent\services\llm\llm_call_service	resolve_wunderbyte_prompt_action_class
./classes/local/wbagent/services/localized_string_service.php:40	bookingextension_agent\local\wbagent\services\localized_string_service	get
./classes/local/wbagent/services/lookup/option_lookup_service.php:52	bookingextension_agent\local\wbagent\services\lookup\option_lookup_service	__construct
./classes/local/wbagent/services/lookup/option_lookup_service.php:66	bookingextension_agent\local\wbagent\services\lookup\option_lookup_service	search_options
./classes/local/wbagent/services/lookup/option_lookup_service.php:94	bookingextension_agent\local\wbagent\services\lookup\option_lookup_service	resolve_single_option
./classes/local/wbagent/services/messaging/message_persistence_service.php:43	bookingextension_agent\local\wbagent\services\messaging\message_persistence_service	__construct
./classes/local/wbagent/services/messaging/message_persistence_service.php:54	bookingextension_agent\local\wbagent\services\messaging\message_persistence_service	persist_assistant_message
./classes/local/wbagent/services/mutation/entity_mutation_service.php:50	bookingextension_agent\local\wbagent\services\mutation\entity_mutation_service	create_entity
./classes/local/wbagent/services/mutation/entity_mutation_service.php:76	(global)	entity_exists_by_name
./classes/local/wbagent/services/mutation/entity_mutation_service.php:90	(global)	entity_exists_by_shortname
./classes/local/wbagent/services/mutation/option_mutation_service.php:52	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	validate_create
./classes/local/wbagent/services/mutation/option_mutation_service.php:67	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	validate_update
./classes/local/wbagent/services/mutation/option_mutation_service.php:82	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	validate_bulk_update
./classes/local/wbagent/services/mutation/option_mutation_service.php:98	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	create_option
./classes/local/wbagent/services/mutation/option_mutation_service.php:110	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	update_option
./classes/local/wbagent/services/mutation/option_mutation_service.php:122	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	bulk_update_options
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php:58	bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service	__construct
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php:78	bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service	observations_are_framework_retry_hints
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php:102	bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service	normalize_step_type
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php:119	bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service	get_initial_prompt_config_key
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php:135	bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service	get_action_initial_prompt_config_key
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php:154	bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service	get_history_limit_for_step
./classes/local/wbagent/services/orchestrator_prompt_profile_service.php:165	bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service	normalize_config_prompt_template
./classes/local/wbagent/services/orchestrator_routing_service.php:60	bookingextension_agent\local\wbagent\services\orchestrator_routing_service	__construct
./classes/local/wbagent/services/orchestrator_routing_service.php:82	bookingextension_agent\local\wbagent\services\orchestrator_routing_service	resolve_action_class_for_step
./classes/local/wbagent/services/orchestrator_routing_service.php:152	bookingextension_agent\local\wbagent\services\orchestrator_routing_service	is_action_available_in_context
./classes/local/wbagent/services/orchestrator_routing_service.php:179	bookingextension_agent\local\wbagent\services\orchestrator_routing_service	build_debug_source
./classes/local/wbagent/services/orchestrator_routing_service.php:243	bookingextension_agent\local\wbagent\services\orchestrator_routing_service	should_use_openai_step_routing
./classes/local/wbagent/services/orchestrator_routing_service.php:263	bookingextension_agent\local\wbagent\services\orchestrator_routing_service	is_wunderbyte_routing_available
./classes/local/wbagent/services/orchestrator_routing_service.php:294	bookingextension_agent\local\wbagent\services\orchestrator_routing_service	short_debug_token
./classes/local/wbagent/services/pending_intent_service.php:43	bookingextension_agent\local\wbagent\services\pending_intent_service	__construct
./classes/local/wbagent/services/pending_intent_service.php:53	bookingextension_agent\local\wbagent\services\pending_intent_service	get
./classes/local/wbagent/services/pending_intent_service.php:65	bookingextension_agent\local\wbagent\services\pending_intent_service	consume
./classes/local/wbagent/services/pending_intent_service.php:75	bookingextension_agent\local\wbagent\services\pending_intent_service	clear
./classes/local/wbagent/services/pending_intent_service.php:88	bookingextension_agent\local\wbagent\services\pending_intent_service	set
./classes/local/wbagent/services/pending_queue_command_service.php:39	bookingextension_agent\local\wbagent\services\pending_queue_command_service	__construct
./classes/local/wbagent/services/pending_queue_command_service.php:50	bookingextension_agent\local\wbagent\services\pending_queue_command_service	build_mutating_commands_from_pending_intent
./classes/local/wbagent/services/pending_queue_command_service.php:80	bookingextension_agent\local\wbagent\services\pending_queue_command_service	normalize_queue_item_ids
./classes/local/wbagent/services/preflight_audit_logger.php:42	bookingextension_agent\local\wbagent\services\preflight_audit_logger	__construct
./classes/local/wbagent/services/preflight_audit_logger.php:54	bookingextension_agent\local\wbagent\services\preflight_audit_logger	append
./classes/local/wbagent/services/preflight_audit_logger.php:92	bookingextension_agent\local\wbagent\services\preflight_audit_logger	get_events
./classes/local/wbagent/services/preflight_audit_logger.php:107	bookingextension_agent\local\wbagent\services\preflight_audit_logger	summarize_reason_codes
./classes/local/wbagent/services/preflight_audit_logger.php:149	bookingextension_agent\local\wbagent\services\preflight_audit_logger	resolve_reason_code
./classes/local/wbagent/services/preflight_contract_validator.php:57	bookingextension_agent\local\wbagent\services\preflight_contract_validator	__construct
./classes/local/wbagent/services/preflight_contract_validator.php:74	bookingextension_agent\local\wbagent\services\preflight_contract_validator	validate
./classes/local/wbagent/services/preflight_domain_check_runner.php:39	bookingextension_agent\local\wbagent\services\preflight_domain_check_runner	run
./classes/local/wbagent/services/preflight_error_classifier.php:40	bookingextension_agent\local\wbagent\services\preflight_error_classifier	infer_from_issue_codes
./classes/local/wbagent/services/preflight_error_classifier.php:72	bookingextension_agent\local\wbagent\services\preflight_error_classifier	is_retryable_error_class
./classes/local/wbagent/services/preflight_execution_gate.php:48	bookingextension_agent\local\wbagent\services\preflight_execution_gate	evaluate
./classes/local/wbagent/services/preflight_execution_gate.php:91	bookingextension_agent\local\wbagent\services\preflight_execution_gate	build_guard_token
./classes/local/wbagent/services/preflight_execution_gate.php:106	bookingextension_agent\local\wbagent\services\preflight_execution_gate	verify_guard_token
./classes/local/wbagent/services/preflight_execution_gate.php:126	bookingextension_agent\local\wbagent\services\preflight_execution_gate	normalize_for_guard
./classes/local/wbagent/services/preflight_pipeline.php:59	bookingextension_agent\local\wbagent\services\preflight_pipeline	__construct
./classes/local/wbagent/services/preflight_pipeline.php:77	bookingextension_agent\local\wbagent\services\preflight_pipeline	run
./classes/local/wbagent/services/preflight_pipeline.php:266	bookingextension_agent\local\wbagent\services\preflight_pipeline	build_output
./classes/local/wbagent/services/preflight_pipeline.php:296	bookingextension_agent\local\wbagent\services\preflight_pipeline	build_audit_command_context
./classes/local/wbagent/services/preflight_pipeline.php:314	bookingextension_agent\local\wbagent\services\preflight_pipeline	resolve_preflight_reason_code
./classes/local/wbagent/services/preflight_result_v2.php:74	bookingextension_agent\local\wbagent\services\preflight_result_v2	__construct
./classes/local/wbagent/services/preflight_result_v2.php:106	bookingextension_agent\local\wbagent\services\preflight_result_v2	normalize_blocking_layer
./classes/local/wbagent/services/preflight_result_v2.php:140	bookingextension_agent\local\wbagent\services\preflight_result_v2	to_array
./classes/local/wbagent/services/preflight_result_v2.php:157	bookingextension_agent\local\wbagent\services\preflight_result_v2	ok
./classes/local/wbagent/services/preflight_result_v2.php:168	bookingextension_agent\local\wbagent\services\preflight_result_v2	confirmable
./classes/local/wbagent/services/preflight_result_v2.php:188	bookingextension_agent\local\wbagent\services\preflight_result_v2	invalid
./classes/local/wbagent/services/preflight_result_v2.php:208	bookingextension_agent\local\wbagent\services\preflight_result_v2	extract_issue_codes_from_issues
./classes/local/wbagent/services/preflight_schema_validator.php:38	bookingextension_agent\local\wbagent\services\preflight_schema_validator	validate
./classes/local/wbagent/services/preflight_schema_validator.php:161	bookingextension_agent\local\wbagent\services\preflight_schema_validator	get_schema
./classes/local/wbagent/services/preflight_version_validator.php:46	bookingextension_agent\local\wbagent\services\preflight_version_validator	__construct
./classes/local/wbagent/services/preflight_version_validator.php:57	bookingextension_agent\local\wbagent\services\preflight_version_validator	validate
./classes/local/wbagent/services/preflight_version_validator.php:126	bookingextension_agent\local\wbagent\services\preflight_version_validator	resolve_requested_version
./classes/local/wbagent/services/provider_routing_util.php:41	bookingextension_agent\local\wbagent\services\provider_routing_util	resolve_primary_provider_for_action
./classes/local/wbagent/services/provider_routing_util.php:61	bookingextension_agent\local\wbagent\services\provider_routing_util	short_provider_for_debug
./classes/local/wbagent/services/queue_command_mapper.php:40	bookingextension_agent\local\wbagent\services\queue_command_mapper	from_queue_item
./classes/local/wbagent/services/queue_command_mapper.php:78	bookingextension_agent\local\wbagent\services\queue_command_mapper	from_queue_items
./classes/local/wbagent/services/queue_status_policy.php:76	bookingextension_agent\local\wbagent\services\queue_status_policy	ready_status
./classes/local/wbagent/services/queue_status_policy.php:85	bookingextension_agent\local\wbagent\services\queue_status_policy	failed_status
./classes/local/wbagent/services/queue_status_policy.php:94	bookingextension_agent\local\wbagent\services\queue_status_policy	succeeded_status
./classes/local/wbagent/services/queue_status_policy.php:103	bookingextension_agent\local\wbagent\services\queue_status_policy	skipped_status
./classes/local/wbagent/services/queue_status_policy.php:112	bookingextension_agent\local\wbagent\services\queue_status_policy	actionable_mutating_statuses
./classes/local/wbagent/services/queue_status_policy.php:121	bookingextension_agent\local\wbagent\services\queue_status_policy	pickup_ready_statuses
./classes/local/wbagent/services/queue_status_policy.php:131	bookingextension_agent\local\wbagent\services\queue_status_policy	is_actionable_mutating_status
./classes/local/wbagent/services/queue_status_policy.php:141	bookingextension_agent\local\wbagent\services\queue_status_policy	is_pickup_ready_status
./classes/local/wbagent/services/queue_status_policy.php:151	bookingextension_agent\local\wbagent\services\queue_status_policy	is_terminal_status
./classes/local/wbagent/services/queue_status_policy.php:161	bookingextension_agent\local\wbagent\services\queue_status_policy	is_succeeded_status
./classes/local/wbagent/services/queue_status_policy.php:171	bookingextension_agent\local\wbagent\services\queue_status_policy	is_failed_status
./classes/local/wbagent/services/queue_status_policy.php:181	bookingextension_agent\local\wbagent\services\queue_status_policy	is_ready_status
./classes/local/wbagent/services/queue_status_policy.php:191	bookingextension_agent\local\wbagent\services\queue_status_policy	is_dependency_satisfied_status
./classes/local/wbagent/services/queue_status_policy.php:201	bookingextension_agent\local\wbagent\services\queue_status_policy	is_blocked_confirmation_status
./classes/local/wbagent/services/queue_status_policy.php:211	bookingextension_agent\local\wbagent\services\queue_status_policy	is_retry_waiting_status
./classes/local/wbagent/services/queue_transition_service.php:51	bookingextension_agent\local\wbagent\services\queue_transition_service	apply_preflight_decision
./classes/local/wbagent/services/queue_transition_service.php:157	bookingextension_agent\local\wbagent\services\queue_transition_service	to_ready
./classes/local/wbagent/services/queue_transition_service.php:184	bookingextension_agent\local\wbagent\services\queue_transition_service	to_blocked_confirmation
./classes/local/wbagent/services/queue_transition_service.php:214	bookingextension_agent\local\wbagent\services\queue_transition_service	to_retry_waiting
./classes/local/wbagent/services/queue_transition_service.php:247	bookingextension_agent\local\wbagent\services\queue_transition_service	to_failed
./classes/local/wbagent/services/queue_transition_service.php:279	bookingextension_agent\local\wbagent\services\queue_transition_service	to_skipped
./classes/local/wbagent/services/queue_transition_service.php:309	bookingextension_agent\local\wbagent\services\queue_transition_service	to_succeeded
./classes/local/wbagent/services/queue_transition_service.php:333	bookingextension_agent\local\wbagent\services\queue_transition_service	normalize_reason_code
./classes/local/wbagent/services/queue_transition_service.php:344	bookingextension_agent\local\wbagent\services\queue_transition_service	normalize_queue_item_ids
./classes/local/wbagent/services/runtime_step_analysis_service.php:38	bookingextension_agent\local\wbagent\services\runtime_step_analysis_service	extract_step_task_names
./classes/local/wbagent/services/runtime_step_analysis_service.php:73	bookingextension_agent\local\wbagent\services\runtime_step_analysis_service	humanize_task_name
./classes/local/wbagent/services/runtime_step_analysis_service.php:96	bookingextension_agent\local\wbagent\services\runtime_step_analysis_service	extract_step_command_signatures
./classes/local/wbagent/services/runtime_step_analysis_service.php:131	bookingextension_agent\local\wbagent\services\runtime_step_analysis_service	extract_recorded_step_task_names
./classes/local/wbagent/services/runtime_step_analysis_service.php:155	bookingextension_agent\local\wbagent\services\runtime_step_analysis_service	normalize_command_input_for_signature
./classes/local/wbagent/services/security/authorization_service.php:46	bookingextension_agent\local\wbagent\services\security\authorization_service	is_agent_extension_installed
./classes/local/wbagent/services/security/authorization_service.php:65	bookingextension_agent\local\wbagent\services\security\authorization_service	require_booking_module_context
./classes/local/wbagent/services/security/authorization_service.php:84	bookingextension_agent\local\wbagent\services\security\authorization_service	require_use_capability
./classes/local/wbagent/services/security/authorization_service.php:101	bookingextension_agent\local\wbagent\services\security\authorization_service	can_use
./classes/local/wbagent/services/security/authorization_service.php:120	bookingextension_agent\local\wbagent\services\security\authorization_service	require_valid_context
./classes/local/wbagent/services/shared_json_payload_extractor.php:39	bookingextension_agent\local\wbagent\services\shared_json_payload_extractor	extract_json_candidates
./classes/local/wbagent/services/shared_json_payload_extractor.php:71	bookingextension_agent\local\wbagent\services\shared_json_payload_extractor	extract_balanced_json_objects
./classes/local/wbagent/services/spawn_contract_service.php:36	bookingextension_agent\local\wbagent\services\spawn_contract_service	normalize_task_result
./classes/local/wbagent/services/spawn_contract_service.php:50	bookingextension_agent\local\wbagent\services\spawn_contract_service	apply_output_bindings
./classes/local/wbagent/services/spawn_contract_service.php:86	bookingextension_agent\local\wbagent\services\spawn_contract_service	normalize_spawn_commands
./classes/local/wbagent/services/spawn_contract_service.php:127	bookingextension_agent\local\wbagent\services\spawn_contract_service	normalize_produced_outputs
./classes/local/wbagent/services/spawn_contract_service.php:152	bookingextension_agent\local\wbagent\services\spawn_contract_service	normalize_binding_reference
./classes/local/wbagent/services/skill_prompt_contract.php:35	bookingextension_agent\local\wbagent\services\skill_prompt_contract	__construct
./classes/local/wbagent/services/skill_prompt_contract.php:44	bookingextension_agent\local\wbagent\services\skill_prompt_contract	to_array
./classes/local/wbagent/services/skill_prompt_contract.php:63	bookingextension_agent\local\wbagent\services\skill_prompt_contract	normalize_string_list
./classes/local/wbagent/services/skill_version_policy.php:51	bookingextension_agent\local\wbagent\services\skill_version_policy	evaluate
./classes/local/wbagent/services/skill_version_policy.php:88	bookingextension_agent\local\wbagent\services\skill_version_policy	is_deprecated
./classes/local/wbagent/services/trigger_result_util.php:38	bookingextension_agent\local\wbagent\services\trigger_result_util	has_trigger
./classes/local/wbagent/summarizer/basic_collection_result_summary_contributor.php:42	bookingextension_agent\local\wbagent\summarizer\basic_collection_result_summary_contributor	supports
./classes/local/wbagent/summarizer/basic_collection_result_summary_contributor.php:53	bookingextension_agent\local\wbagent\summarizer\basic_collection_result_summary_contributor	summarize
./classes/local/wbagent/summarizer/diagnosis_result_summary_contributor.php:42	bookingextension_agent\local\wbagent\summarizer\diagnosis_result_summary_contributor	supports
./classes/local/wbagent/summarizer/diagnosis_result_summary_contributor.php:53	bookingextension_agent\local\wbagent\summarizer\diagnosis_result_summary_contributor	summarize
./classes/local/wbagent/summarizer/docs_result_summary_contributor.php:42	bookingextension_agent\local\wbagent\summarizer\docs_result_summary_contributor	supports
./classes/local/wbagent/summarizer/docs_result_summary_contributor.php:53	bookingextension_agent\local\wbagent\summarizer\docs_result_summary_contributor	summarize
./classes/local/wbagent/summarizer/single_object_result_summary_contributor.php:45	bookingextension_agent\local\wbagent\summarizer\single_object_result_summary_contributor	supports
./classes/local/wbagent/summarizer/single_object_result_summary_contributor.php:66	bookingextension_agent\local\wbagent\summarizer\single_object_result_summary_contributor	summarize
./classes/local/wbagent/skill_contract_validator.php:70	bookingextension_agent\local\wbagent\skill_contract_validator	build_task_metadata
./classes/local/wbagent/skill_contract_validator.php:100	bookingextension_agent\local\wbagent\skill_contract_validator	build_task_capability_name
./classes/local/wbagent/skill_contract_validator.php:122	bookingextension_agent\local\wbagent\skill_contract_validator	validate_task_metadata
./classes/local/wbagent/skill_contract_validator.php:192	bookingextension_agent\local\wbagent\skill_contract_validator	validate_registry_contracts
./classes/local/wbagent/skill_contract_validator.php:228	bookingextension_agent\local\wbagent\skill_contract_validator	get_deny_reason_priority
./classes/local/wbagent/skill_contract_validator.php:244	bookingextension_agent\local\wbagent\skill_contract_validator	extract_task_namespace
./classes/local/wbagent/skill_contract_validator.php:260	bookingextension_agent\local\wbagent\skill_contract_validator	is_namespaced_task_name
./classes/local/wbagent/skill_contract_validator.php:272	bookingextension_agent\local\wbagent\skill_contract_validator	component_may_register_namespace
./classes/local/wbagent/skill_discovery.php:44	bookingextension_agent\local\wbagent\skill_discovery	get_task_instances
./classes/local/wbagent/skill_discovery.php:89	bookingextension_agent\local\wbagent\skill_discovery	get_trigger_provider_instances
./classes/local/wbagent/skill_discovery.php:109	bookingextension_agent\local\wbagent\skill_discovery	get_last_diagnostics
./classes/local/wbagent/skill_discovery.php:119	bookingextension_agent\local\wbagent\skill_discovery	find_candidate_classes
./classes/local/wbagent/skill_discovery.php:173	bookingextension_agent\local\wbagent\skill_discovery	get_task_directories
./classes/local/wbagent/skill_discovery.php:198	bookingextension_agent\local\wbagent\skill_discovery	instantiate_if_supported
./classes/local/wbagent/skill_discovery.php:226	bookingextension_agent\local\wbagent\skill_discovery	ensure_class_loaded
./classes/local/wbagent/skill_discovery.php:265	bookingextension_agent\local\wbagent\skill_discovery	add_diagnostic
./classes/local/wbagent/skill_discovery.php:280	bookingextension_agent\local\wbagent\skill_discovery	compare_task_classes
./classes/local/wbagent/skill_discovery.php:297	bookingextension_agent\local\wbagent\skill_discovery	get_namespace_priority
./classes/local/wbagent/skill_executability_evaluator.php:48	bookingextension_agent\local\wbagent\skill_executability_evaluator	__construct
./classes/local/wbagent/skill_executability_evaluator.php:61	bookingextension_agent\local\wbagent\skill_executability_evaluator	evaluate_task
./classes/local/wbagent/skill_executability_evaluator.php:115	bookingextension_agent\local\wbagent\skill_executability_evaluator	evaluate_all_tasks
./classes/local/wbagent/skill_executability_evaluator.php:133	bookingextension_agent\local\wbagent\skill_executability_evaluator	get_executable_task_names
./classes/local/wbagent/skill_executability_evaluator.php:153	bookingextension_agent\local\wbagent\skill_executability_evaluator	deny_result
./classes/local/wbagent/skill_executability_evaluator.php:170	bookingextension_agent\local\wbagent\skill_executability_evaluator	has_required_capabilities
./classes/local/wbagent/skill_executability_evaluator.php:200	bookingextension_agent\local\wbagent\skill_executability_evaluator	is_valid_context
./classes/local/wbagent/skill_provider.php:42	bookingextension_agent\local\wbagent\skill_provider	get_component
./classes/local/wbagent/skill_provider.php:51	bookingextension_agent\local\wbagent\skill_provider	get_tasks
./classes/local/wbagent/skill_provider.php:63	bookingextension_agent\local\wbagent\skill_provider	get_discovery_diagnostics
./classes/local/wbagent/skill_provider.php:72	bookingextension_agent\local\wbagent\skill_provider	get_contextual_prompt_packs
./classes/local/wbagent/skill_provider.php:103	bookingextension_agent\local\wbagent\skill_provider	get_issue_code_provider
./classes/local/wbagent/skill_provider.php:116	bookingextension_agent\local\wbagent\skill_provider	get_prompt_guidance
./classes/local/wbagent/skill_provider.php:127	bookingextension_agent\local\wbagent\skill_provider	get_result_summary_contributors
./classes/local/wbagent/skill_registry.php:75	bookingextension_agent\local\wbagent\skill_registry	register
./classes/local/wbagent/skill_registry.php:203	bookingextension_agent\local\wbagent\skill_registry	get_task
./classes/local/wbagent/skill_registry.php:213	bookingextension_agent\local\wbagent\skill_registry	get_provider_for_task
./classes/local/wbagent/skill_registry.php:224	bookingextension_agent\local\wbagent\skill_registry	normalize_task_input
./classes/local/wbagent/skill_registry.php:244	bookingextension_agent\local\wbagent\skill_registry	get_preview_option_memory_for_task
./classes/local/wbagent/skill_registry.php:258	bookingextension_agent\local\wbagent\skill_registry	get_preview_option_memory_helpers
./classes/local/wbagent/skill_registry.php:279	bookingextension_agent\local\wbagent\skill_registry	get_task_names
./classes/local/wbagent/skill_registry.php:292	bookingextension_agent\local\wbagent\skill_registry	get_task_names_for_context
./classes/local/wbagent/skill_registry.php:310	bookingextension_agent\local\wbagent\skill_registry	get_tasks
./classes/local/wbagent/skill_registry.php:320	bookingextension_agent\local\wbagent\skill_registry	get_task_contract
./classes/local/wbagent/skill_registry.php:329	bookingextension_agent\local\wbagent\skill_registry	get_task_contracts
./classes/local/wbagent/skill_registry.php:338	bookingextension_agent\local\wbagent\skill_registry	get_contract_diagnostics
./classes/local/wbagent/skill_registry.php:347	bookingextension_agent\local\wbagent\skill_registry	get_result_summary_contributors
./classes/local/wbagent/skill_registry.php:357	bookingextension_agent\local\wbagent\skill_registry	is_read_only_task
./classes/local/wbagent/skill_registry.php:368	bookingextension_agent\local\wbagent\skill_registry	is_task_active
./classes/local/wbagent/skill_registry.php:394	bookingextension_agent\local\wbagent\skill_registry	get_skill_toggle_setting_name
./classes/local/wbagent/skill_registry.php:410	bookingextension_agent\local\wbagent\skill_registry	get_task_capabilities
./classes/local/wbagent/skill_registry.php:424	bookingextension_agent\local\wbagent\skill_registry	get_all_schemas
./classes/local/wbagent/skill_registry.php:441	bookingextension_agent\local\wbagent\skill_registry	get_all_schemas_for_context
./classes/local/wbagent/skill_registry.php:471	bookingextension_agent\local\wbagent\skill_registry	explain_task_schema_for_context
./classes/local/wbagent/skill_registry.php:499	bookingextension_agent\local\wbagent\skill_registry	get_all_prompt_contracts
./classes/local/wbagent/skill_registry.php:516	bookingextension_agent\local\wbagent\skill_registry	get_prompt_contracts_for_context
./classes/local/wbagent/skill_registry.php:549	bookingextension_agent\local\wbagent\skill_registry	build_prompt_contract
./classes/local/wbagent/skill_registry.php:616	bookingextension_agent\local\wbagent\skill_registry	get_contextual_prompt_packs
./classes/local/wbagent/skill_registry.php:643	bookingextension_agent\local\wbagent\skill_registry	get_message_triggers
./classes/local/wbagent/skill_registry.php:654	bookingextension_agent\local\wbagent\skill_registry	get_trigger_id_to_task_name_map
./classes/local/wbagent/skill_registry.php:665	bookingextension_agent\local\wbagent\skill_registry	make_default
./classes/local/wbagent/skill_registry.php:728	bookingextension_agent\local\wbagent\skill_registry	register_discovered_tasks_without_provider
./classes/local/wbagent/skill_registry.php:761	bookingextension_agent\local\wbagent\skill_provider_interface	__construct
./classes/local/wbagent/skill_registry.php:772	bookingextension_agent\local\wbagent\skill_provider_interface	get_component
./classes/local/wbagent/skill_registry.php:781	bookingextension_agent\local\wbagent\skill_provider_interface	get_tasks
./classes/local/wbagent/skill_registry.php:790	bookingextension_agent\local\wbagent\skill_provider_interface	get_contextual_prompt_packs
./classes/local/wbagent/skill_registry.php:799	bookingextension_agent\local\wbagent\skill_provider_interface	get_issue_code_provider
./classes/local/wbagent/skill_registry.php:808	bookingextension_agent\local\wbagent\skill_provider_interface	get_prompt_guidance
./classes/local/wbagent/skill_registry.php:817	bookingextension_agent\local\wbagent\skill_provider_interface	get_discovery_diagnostics
./classes/local/wbagent/skill_registry.php:841	bookingextension_agent\local\wbagent\skill_registry	normalize_provider_component_name
./classes/local/wbagent/skill_registry.php:856	bookingextension_agent\local\wbagent\skill_registry	append_provider_discovery_diagnostics
./classes/local/wbagent/skill_registry.php:882	bookingextension_agent\local\wbagent\skill_registry	add_contract_diagnostic
./classes/local/wbagent/skill_registry.php:896	bookingextension_agent\local\wbagent\skill_registry	fail_on_contract_diagnostics_when_strict
./classes/local/wbagent/skill_registry.php:916	bookingextension_agent\local\wbagent\skill_registry	is_governance_strict_mode_enabled
./classes/local/wbagent/skill_registry_factory.php:44	bookingextension_agent\local\wbagent\skill_registry_factory	get_default
./classes/local/wbagent/skill_registry_factory.php:65	bookingextension_agent\local\wbagent\skill_registry_factory	get_last_build_warning
./classes/local/wbagent/skill_registry_factory.php:76	bookingextension_agent\local\wbagent\skill_registry_factory	reset
./classes/task/execute_ai_run_adhoc.php:57	bookingextension_agent\task\execute_ai_run_adhoc	get_name
./classes/task/execute_ai_run_adhoc.php:66	bookingextension_agent\task\execute_ai_run_adhoc	execute
./classes/task/rebuild_skill_catalog_embeddings_adhoc.php:47	bookingextension_agent\task\rebuild_skill_catalog_embeddings_adhoc	execute
./cli/rebuild_embeddings_fixture.php:277	(global)	read_fixture_rows
./cli/rebuild_embeddings_fixture.php:312	(global)	write_fixture_rows
./db/upgrade.php:32	(global)	xmldb_bookingextension_agent_ensure_ai_messages_userid
./db/upgrade.php:68	(global)	xmldb_bookingextension_agent_upgrade
./tests/agent/abstract_agent_testcase.php:99	bookingextension_agent\abstract_agent_testcase	setUp
./tests/agent/abstract_agent_testcase.php:142	bookingextension_agent\abstract_agent_testcase	grant_agent_capabilities_to_editingteacher
./tests/agent/abstract_agent_testcase.php:208	bookingextension_agent\abstract_agent_testcase	maybe_register_live_ai_provider
./tests/agent/abstract_agent_testcase.php:260	bookingextension_agent\abstract_agent_testcase	register_live_wunderbyte_provider
./tests/agent/abstract_agent_testcase.php:334	bookingextension_agent\abstract_agent_testcase	register_live_openai_provider
./tests/agent/abstract_agent_testcase.php:382	bookingextension_agent\abstract_agent_testcase	normalize_chat_endpoint
./tests/agent/abstract_agent_testcase.php:396	bookingextension_agent\abstract_agent_testcase	chat_endpoint_to_embeddings_endpoint
./tests/agent/abstract_agent_testcase.php:410	bookingextension_agent\abstract_agent_testcase	update_provider_actionconfig
./tests/agent/abstract_agent_testcase.php:433	bookingextension_agent\abstract_agent_testcase	configure_wunderbyte_embeddings_model
./tests/agent/abstract_agent_testcase.php:471	bookingextension_agent\abstract_agent_testcase	maybe_load_embeddings_fixture
./tests/agent/abstract_agent_testcase.php:495	bookingextension_agent\abstract_agent_testcase	create_option
./tests/agent/abstract_agent_testcase.php:524	bookingextension_agent\abstract_agent_testcase	make_executor
./tests/agent/abstract_agent_testcase.php:543	bookingextension_agent\abstract_agent_testcase	exec_command
./tests/agent/abstract_agent_testcase.php:583	bookingextension_agent\abstract_agent_testcase	get_option_from_db
./tests/agent/abstract_agent_testcase.php:593	bookingextension_agent\abstract_agent_testcase	get_all_options
./tests/agent/abstract_agent_testcase.php:607	bookingextension_agent\abstract_agent_testcase	require_real_llm
./tests/agent/abstract_agent_testcase.php:634	bookingextension_agent\abstract_agent_testcase	build_runtime
./tests/agent/abstract_agent_testcase.php:662	bookingextension_agent\abstract_agent_testcase	chat
./tests/agent/abstract_agent_testcase.php:679	bookingextension_agent\abstract_agent_testcase	booking_contextid
./tests/agent/abstract_agent_testcase.php:691	bookingextension_agent\abstract_agent_testcase	resolve_queue_item_id_for_confirmation
./tests/agent/abstract_agent_testcase.php:741	bookingextension_agent\abstract_agent_testcase	confirm_pending_result
./tests/agent/abstract_agent_testcase.php:766	bookingextension_agent\abstract_agent_testcase	extract_command
./tests/agent/abstract_agent_testcase.php:782	bookingextension_agent\abstract_agent_testcase	extract_task_result
./tests/agent/abstract_agent_testcase.php:797	bookingextension_agent\abstract_agent_testcase	execute_command
./tests/agent/abstract_agent_testcase.php:820	bookingextension_agent\abstract_agent_testcase	execute_all_commands
./tests/agent/abstract_agent_testcase.php:849	bookingextension_agent\abstract_agent_testcase	assert_generate_text_logged_for_thread
./tests/agent/abstract_agent_testcase.php:875	bookingextension_agent\abstract_agent_testcase	tearDown
./tests/agent/abstract_llm_skill_matrix_testcase.php:51	bookingextension_agent\abstract_llm_skill_matrix_testcase	setUp
./tests/agent/abstract_llm_skill_matrix_testcase.php:65	bookingextension_agent\abstract_llm_skill_matrix_testcase	task_matrix_scenarios
./tests/agent/abstract_llm_skill_matrix_testcase.php:75	bookingextension_agent\abstract_llm_skill_matrix_testcase	assert_llm_task_scenario_success
./tests/agent/abstract_llm_skill_matrix_testcase.php:231	bookingextension_agent\abstract_llm_skill_matrix_testcase	grant_local_entities_capabilities_to_editingteacher
./tests/agent/abstract_llm_skill_matrix_testcase.php:252	bookingextension_agent\abstract_llm_skill_matrix_testcase	grant_optional_capability_to_editingteacher
./tests/agent/abstract_llm_skill_matrix_testcase.php:277	bookingextension_agent\abstract_llm_skill_matrix_testcase	assert_task_is_executable_or_skip
./tests/agent/abstract_llm_skill_matrix_testcase.php:315	bookingextension_agent\abstract_llm_skill_matrix_testcase	sync_capability_definition_from_component
./tests/agent/abstract_llm_skill_matrix_testcase.php:336	bookingextension_agent\abstract_llm_skill_matrix_testcase	prepare_scenario_runtime
./tests/agent/abstract_llm_skill_matrix_testcase.php:380	bookingextension_agent\abstract_llm_skill_matrix_testcase	default_scenario_replacements
./tests/agent/abstract_llm_skill_matrix_testcase.php:405	bookingextension_agent\abstract_llm_skill_matrix_testcase	prepare_recall_memory_scenario
./tests/agent/abstract_llm_skill_matrix_testcase.php:440	bookingextension_agent\abstract_llm_skill_matrix_testcase	prepare_entity_scenario
./tests/agent/abstract_llm_skill_matrix_testcase.php:479	bookingextension_agent\abstract_llm_skill_matrix_testcase	prepare_update_option_scenario
./tests/agent/abstract_llm_skill_matrix_testcase.php:521	bookingextension_agent\abstract_llm_skill_matrix_testcase	prepare_booking_rules_service_scenario
./tests/agent/abstract_llm_skill_matrix_testcase.php:549	bookingextension_agent\abstract_llm_skill_matrix_testcase	prepare_booking_rule_update_scenario
./tests/agent/abstract_llm_skill_matrix_testcase.php:621	bookingextension_agent\abstract_llm_skill_matrix_testcase	assert_scenario_assertions
./tests/agent/abstract_llm_skill_matrix_testcase.php:728	bookingextension_agent\abstract_llm_skill_matrix_testcase	payload_text
./tests/agent/abstract_llm_skill_matrix_testcase.php:756	bookingextension_agent\abstract_llm_skill_matrix_testcase	payload_field_value
./tests/agent/abstract_llm_skill_matrix_testcase.php:790	bookingextension_agent\abstract_llm_skill_matrix_testcase	payload_field_count
./tests/agent/abstract_llm_skill_matrix_testcase.php:809	bookingextension_agent\abstract_llm_skill_matrix_testcase	payload_step_count
./tests/agent/abstract_llm_skill_matrix_testcase.php:828	bookingextension_agent\abstract_llm_skill_matrix_testcase	get_latest_debug_source
./tests/agent/abstract_llm_skill_matrix_testcase.php:851	bookingextension_agent\abstract_llm_skill_matrix_testcase	thread_has_debug_source_fragment
./tests/agent/abstract_llm_skill_matrix_testcase.php:881	bookingextension_agent\abstract_llm_skill_matrix_testcase	render_assertion_value
./tests/agent/abstract_llm_skill_matrix_testcase.php:891	bookingextension_agent\abstract_llm_skill_matrix_testcase	stringify_assertion_value
./tests/agent/abstract_llm_skill_matrix_testcase.php:907	bookingextension_agent\abstract_llm_skill_matrix_testcase	resolve_task_result_payload
./tests/agent/abstract_llm_skill_matrix_testcase.php:994	bookingextension_agent\abstract_llm_skill_matrix_testcase	render_scenario_template
./tests/agent/abstract_llm_skill_matrix_testcase.php:1009	bookingextension_agent\abstract_llm_skill_matrix_testcase	first_loop_has_expected_tool_call
./tests/agent/abstract_llm_skill_matrix_testcase.php:1058	bookingextension_agent\abstract_llm_skill_matrix_testcase	find_task_result_entry
./tests/agent/abstract_llm_skill_matrix_testcase.php:1114	bookingextension_agent\abstract_llm_skill_matrix_testcase	task_result_candidate_names
./tests/agent/contracts/ai_confirm_run_contract_test.php:43	bookingextension_agent\ai_confirm_run_contract_test	test_terminal_confirm_success_triggers_finalizer_when_no_follow_up_queue_item_exists
./tests/agent/contracts/ai_confirm_run_contract_test.php:144	bookingextension_agent\ai_confirm_run_contract_test	test_follow_up_pending_intent_forces_confirmation_request
./tests/agent/contracts/attempt_budget_dto_contract_test.php:35	bookingextension_agent\local\wbagent\tests\attempt_budget_dto_contract_test	test_from_loop_exports_stable_payload
./tests/agent/contracts/attempt_budget_dto_contract_test.php:52	bookingextension_agent\local\wbagent\tests\attempt_budget_dto_contract_test	test_from_queue_item_maps_retry_counters
./tests/agent/contracts/finalization_classifier_contract_test.php:35	bookingextension_agent\local\wbagent\tests\finalization_classifier_contract_test	test_classifies_has_commands_as_direct_final
./tests/agent/contracts/finalization_classifier_contract_test.php:49	bookingextension_agent\local\wbagent\tests\finalization_classifier_contract_test	test_classifies_confirmation_request_as_direct_final
./tests/agent/contracts/finalization_classifier_contract_test.php:63	bookingextension_agent\local\wbagent\tests\finalization_classifier_contract_test	test_classifies_structural_issue_code_as_direct_final
./tests/agent/contracts/finalization_classifier_contract_test.php:78	bookingextension_agent\local\wbagent\tests\finalization_classifier_contract_test	test_classifies_budget_exceeded_as_template_only
./tests/agent/contracts/finalization_classifier_contract_test.php:93	bookingextension_agent\local\wbagent\tests\finalization_classifier_contract_test	test_classifies_provider_timeout_error_class_as_template_only
./tests/agent/contracts/finalization_classifier_contract_test.php:108	bookingextension_agent\local\wbagent\tests\finalization_classifier_contract_test	test_classifies_sufficient_as_llm_polish
./tests/agent/contracts/finalization_classifier_contract_test.php:122	bookingextension_agent\local\wbagent\tests\finalization_classifier_contract_test	test_classifies_non_structural_error_as_llm_polish
./tests/agent/contracts/finalization_classifier_contract_test.php:138	bookingextension_agent\local\wbagent\tests\finalization_classifier_contract_test	test_classifies_structural_flag_error_as_direct_final
./tests/agent/contracts/finalization_classifier_contract_test.php:153	bookingextension_agent\local\wbagent\tests\finalization_classifier_contract_test	test_direct_precedence_over_template_when_commands_present
./tests/agent/contracts/finalization_classifier_contract_test.php:168	bookingextension_agent\local\wbagent\tests\finalization_classifier_contract_test	test_direct_precedence_for_structural_issue_code
./tests/agent/contracts/finalization_template_service_contract_test.php:35	bookingextension_agent\local\wbagent\tests\finalization_template_service_contract_test	test_resolves_message_from_issue_code
./tests/agent/contracts/finalization_template_service_contract_test.php:48	bookingextension_agent\local\wbagent\tests\finalization_template_service_contract_test	test_resolves_message_from_error_class
./tests/agent/contracts/finalization_template_service_contract_test.php:62	bookingextension_agent\local\wbagent\tests\finalization_template_service_contract_test	test_issue_code_precedence_over_error_class
./tests/agent/contracts/finalization_template_service_contract_test.php:76	bookingextension_agent\local\wbagent\tests\finalization_template_service_contract_test	test_returns_empty_message_for_unknown_values
./tests/agent/contracts/integration_agent_framework_test.php:39	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_skill_registry_discovers_booking_tasks
./tests/agent/contracts/integration_agent_framework_test.php:57	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_skill_provider_interface_supports_issue_code_provider
./tests/agent/contracts/integration_agent_framework_test.php:78	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_skill_provider_interface_supports_prompt_guidance
./tests/agent/contracts/integration_agent_framework_test.php:95	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_issue_code_provider_injected_into_agent_runtime
./tests/agent/contracts/integration_agent_framework_test.php:113	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_schema_includes_prompt_meta
./tests/agent/contracts/integration_agent_framework_test.php:138	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_skill_registry_prioritizes_prompt_meta
./tests/agent/contracts/integration_agent_framework_test.php:156	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_prompt_contracts_use_required_minimals_and_explicit_examples
./tests/agent/contracts/integration_agent_framework_test.php:184	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_slim_catalog_keeps_examples_separate_from_minimals
./tests/agent/contracts/integration_agent_framework_test.php:215	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_embedding_subset_keeps_full_descriptions
./tests/agent/contracts/integration_agent_framework_test.php:256	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_embedding_subset_includes_property_descriptions
./tests/agent/contracts/integration_agent_framework_test.php:290	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_orchestrator_prompts_are_generic
./tests/agent/contracts/integration_agent_framework_test.php:305	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_action_specific_prompts_generic
./tests/agent/contracts/integration_agent_framework_test.php:350	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_discovered_tasks_implement_task_interface
./tests/agent/contracts/integration_agent_framework_test.php:366	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_multi_provider_discovery
./tests/agent/contracts/integration_agent_framework_test.php:395	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_skill_discovery_scans_all_wbagent_task_namespaces
./tests/agent/contracts/integration_agent_framework_test.php:413	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_skill_discovery_deduplicates_same_task_name
./tests/agent/contracts/integration_agent_framework_test.php:425	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_trigger_provider_discovery_ignores_non_trigger_classes
./tests/agent/contracts/integration_agent_framework_test.php:440	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_tasks_no_language_specific_logic
./tests/agent/contracts/integration_agent_framework_test.php:461	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_schema_required_fields
./tests/agent/contracts/integration_agent_framework_test.php:478	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_backward_compatibility_constants
./tests/agent/contracts/mod_booking_option_skills_contract_test.php:42	bookingextension_agent\local\wbagent\tests\mod_booking_option_skills_contract_test	test_registry_discovers_canonical_mod_booking_option_tasks
./tests/agent/contracts/mod_booking_option_skills_contract_test.php:61	bookingextension_agent\local\wbagent\tests\mod_booking_option_skills_contract_test	test_create_option_defaults_to_type_zero
./tests/agent/contracts/mod_booking_option_skills_contract_test.php:89	bookingextension_agent\local\wbagent\tests\mod_booking_option_skills_contract_test	test_create_option_emits_rich_observation_summary
./tests/agent/contracts/mod_booking_option_skills_contract_test.php:124	bookingextension_agent\local\wbagent\tests\mod_booking_option_skills_contract_test	test_update_option_sets_type_one_for_selflearning_input
./tests/agent/contracts/mod_booking_option_skills_contract_test.php:164	bookingextension_agent\local\wbagent\tests\mod_booking_option_skills_contract_test	test_create_slotbooking_option_requires_slot_fields
./tests/agent/contracts/mod_booking_option_skills_contract_test.php:196	bookingextension_agent\local\wbagent\tests\mod_booking_option_skills_contract_test	test_slotbooking_prompt_contracts_are_explicit
./tests/agent/contracts/mod_booking_option_skills_contract_test.php:219	bookingextension_agent\local\wbagent\tests\mod_booking_option_skills_contract_test	create_booking_test_context
./tests/agent/contracts/mod_booking_option_skills_contract_test.php:245	bookingextension_agent\local\wbagent\tests\mod_booking_option_skills_contract_test	grant_booking_option_task_capabilities
./tests/agent/contracts/pending_intent_and_queue_transition_contract_test.php:39	bookingextension_agent\local\wbagent\tests\pending_intent_and_queue_transition_contract_test	test_pending_intent_service_set_returns_confirmation_code
./tests/agent/contracts/pending_intent_and_queue_transition_contract_test.php:63	bookingextension_agent\local\wbagent\tests\pending_intent_and_queue_transition_contract_test	test_queue_transition_service_retry_waiting_transition
./tests/agent/contracts/preflight_audit_logger_contract_test.php:36	bookingextension_agent\local\wbagent\tests\preflight_audit_logger_contract_test	test_summarize_reason_codes_groups_counts
./tests/agent/contracts/preflight_contract_validator_contract_test.php:38	bookingextension_agent\local\wbagent\tests\preflight_contract_validator_contract_test	test_validator_propagates_schema_error_contract
./tests/agent/contracts/preflight_contract_validator_contract_test.php:63	bookingextension_agent\local\wbagent\tests\preflight_contract_validator_contract_test	test_validator_preserves_deprecation_issue_codes
./tests/agent/contracts/preflight_contract_validator_contract_test.php:111	bookingextension_agent\local\wbagent\tests\preflight_contract_validator_contract_test	test_validator_blocks_unsupported_version
./tests/agent/contracts/preflight_layers_contract_test.php:38	bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test	test_domain_runner_hard_blocks_permission_error
./tests/agent/contracts/preflight_layers_contract_test.php:53	bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test	test_domain_runner_soft_blocks_duplicate_confirm_issue
./tests/agent/contracts/preflight_layers_contract_test.php:65	bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test	test_execution_gate_retry_hint_for_provider_timeout
./tests/agent/contracts/preflight_layers_contract_test.php:79	bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test	test_execution_gate_hard_blocks_after_max_retries
./tests/agent/contracts/prompt_and_language_contract_test.php:41	bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test	test_prompt_contracts_do_not_use_name_based_heuristics
./tests/agent/contracts/prompt_and_language_contract_test.php:83	bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test	test_language_policy_prefers_user_input_language
./tests/agent/contracts/prompt_and_language_contract_test.php:112	bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test	test_language_policy_fallback_string_mapping
./tests/agent/contracts/prompt_and_language_contract_test.php:128	bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test	test_language_policy_matrix_de_en_zh
./tests/agent/contracts/queue_consolidation_contract_test.php:37	bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test	test_queue_status_policy_actionable_mutating_statuses_are_stable
./tests/agent/contracts/queue_consolidation_contract_test.php:50	bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test	test_queue_status_policy_pickup_statuses_are_stable
./tests/agent/contracts/queue_consolidation_contract_test.php:60	bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test	test_queue_command_mapper_prefers_prepared_input_and_preserves_metadata
./tests/agent/contracts/queue_consolidation_contract_test.php:81	bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test	test_queue_command_mapper_filters_invalid_items_and_falls_back_to_raw_input
./tests/agent/contracts/reference_scenarios_contract_test.php:37	bookingextension_agent\local\wbagent\tests\reference_scenarios_contract_test	test_scenario_a_readonly_result_contract
./tests/agent/contracts/reference_scenarios_contract_test.php:54	bookingextension_agent\local\wbagent\tests\reference_scenarios_contract_test	test_scenario_b_multistep_command_schema_contract
./tests/agent/contracts/reference_scenarios_contract_test.php:70	bookingextension_agent\local\wbagent\tests\reference_scenarios_contract_test	test_scenario_c_spawn_output_binding_contract
./tests/agent/contracts/runtime_finalization_contract_test.php:39	bookingextension_agent\local\wbagent\tests\runtime_finalization_contract_test	test_template_only_finalization_sets_deterministic_message
./tests/agent/contracts/runtime_finalization_contract_test.php:59	bookingextension_agent\local\wbagent\tests\runtime_finalization_contract_test	test_merge_rolls_back_when_response_type_drifts
./tests/agent/contracts/runtime_finalization_contract_test.php:83	bookingextension_agent\local\wbagent\tests\runtime_finalization_contract_test	test_merge_accepts_stable_response_type_without_commands
./tests/agent/contracts/runtime_finalization_contract_test.php:111	bookingextension_agent\local\wbagent\tests\runtime_finalization_contract_test	build_runtime
./tests/agent/contracts/runtime_finalization_contract_test.php:128	bookingextension_agent\local\wbagent\tests\runtime_finalization_contract_test	invoke_private_method
./tests/agent/contracts/spawn_contract_service_test.php:35	bookingextension_agent\local\wbagent\tests\spawn_contract_service_test	test_normalize_task_result_adds_output_aliases
./tests/agent/contracts/spawn_contract_service_test.php:53	bookingextension_agent\local\wbagent\tests\spawn_contract_service_test	test_apply_output_bindings_resolves_parent_aliases
./tests/agent/contracts/spawn_contract_service_test.php:70	bookingextension_agent\local\wbagent\tests\spawn_contract_service_test	test_apply_output_bindings_reports_missing_reference
./tests/agent/contracts/spawn_contract_service_test.php:86	bookingextension_agent\local\wbagent\tests\spawn_contract_service_test	test_normalize_spawn_commands_filters_invalid_entries
./tests/agent/contracts/skill_contract_validator_contract_test.php:39	bookingextension_agent\local\wbagent\tests\skill_contract_validator_contract_test	test_namespaced_task_name_format
./tests/agent/contracts/skill_contract_validator_contract_test.php:49	bookingextension_agent\local\wbagent\tests\skill_contract_validator_contract_test	test_reserved_namespace_ownership
./tests/agent/contracts/skill_contract_validator_contract_test.php:60	bookingextension_agent\local\wbagent\tests\skill_contract_validator_contract_test	test_validate_registry_contracts_rejects_alias_version_mismatch
./tests/agent/contracts/skill_contract_validator_contract_test.php:94	bookingextension_agent\local\wbagent\tests\skill_contract_validator_contract_test	test_registry_rejects_reserved_namespace_for_third_party_provider
./tests/agent/contracts/skill_contract_validator_contract_test.php:124	bookingextension_agent\local\wbagent\tests\skill_contract_validator_contract_test	test_demo_task_onboards_via_provider_registration_only
./tests/agent/contracts/skill_contract_validator_contract_test.php:171	bookingextension_agent\local\wbagent\tests\skill_contract_validator_contract_test	test_failing_provider_does_not_block_other_registered_tasks
./tests/agent/llm_skill_matrix_scenario_provider.php:39	bookingextension_agent\llm_skill_matrix_scenario_provider	provide_registered_task_scenarios
./tests/agent/llm_skill_matrix_scenario_provider.php:64	bookingextension_agent\llm_skill_matrix_scenario_provider	get_missing_registered_task_scenarios
./tests/agent/llm_skill_matrix_scenario_provider.php:84	bookingextension_agent\llm_skill_matrix_scenario_provider	get_scenario_definitions
./tests/agent/real_llm_multistep/all_skills_real_llm_test.php:45	bookingextension_agent\all_skills_real_llm_test	setUp
./tests/agent/real_llm_multistep/all_skills_real_llm_test.php:57	bookingextension_agent\all_skills_real_llm_test	real_task_matrix_scenarios
./tests/agent/real_llm_multistep/all_skills_real_llm_test.php:61	bookingextension_agent\all_skills_real_llm_test	test_task_matrix_covers_all_registered_tasks
./tests/agent/real_llm_multistep/all_skills_real_llm_test.php:71	bookingextension_agent\all_skills_real_llm_test	test_all_registered_tasks_can_complete_via_real_llm
./tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php:48	bookingextension_agent\confirmation_flow_real_llm_test	setUp
./tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php:56	bookingextension_agent\confirmation_flow_real_llm_test	test_multistep_create_assign_teacher_and_make_visible
./tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php:226	bookingextension_agent\confirmation_flow_real_llm_test	is_task_available
./tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:42	bookingextension_agent\get_current_user_real_llm_test	setUp
./tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:47	bookingextension_agent\get_current_user_real_llm_test	test_get_current_user_observation_contains_full_user_payload
./tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:111	bookingextension_agent\get_current_user_real_llm_test	payload_text
./tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:129	bookingextension_agent\get_current_user_real_llm_test	has_task_evidence
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:50	bookingextension_agent\lecture_autoconfirm_real_llm_test	setUp
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:62	bookingextension_agent\lecture_autoconfirm_real_llm_test	test_lecture_autoconfirm_single_pass_creates_five_actions
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:268	bookingextension_agent\lecture_autoconfirm_real_llm_test	build_trace_line
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:287	bookingextension_agent\lecture_autoconfirm_real_llm_test	has_create_option_commands
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:297	bookingextension_agent\lecture_autoconfirm_real_llm_test	count_create_option_commands
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:327	bookingextension_agent\lecture_autoconfirm_real_llm_test	is_task_available
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:338	bookingextension_agent\lecture_autoconfirm_real_llm_test	is_dependency_waiting_error
./tests/agent/real_llm_multistep/list_actions_real_llm_test.php:46	bookingextension_agent\list_actions_real_llm_test	setUp
./tests/agent/real_llm_multistep/list_actions_real_llm_test.php:54	bookingextension_agent\list_actions_real_llm_test	test_list_actions_groups_by_provider_then_readonly_write_then_capability
./tests/agent/real_llm_multistep/list_actions_real_llm_test.php:143	bookingextension_agent\list_actions_real_llm_test	payload_text
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:42	bookingextension_agent\normal_option_datetime_real_llm_test	setUp
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:53	bookingextension_agent\normal_option_datetime_real_llm_test	test_datetime_prompt_routes_to_create_option_and_type_zero
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:147	bookingextension_agent\normal_option_datetime_real_llm_test	test_weekday_series_prompt_routes_to_create_option_and_creates_five_type_zero_options
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:467	bookingextension_agent\normal_option_datetime_real_llm_test	is_task_available
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:479	bookingextension_agent\normal_option_datetime_real_llm_test	extract_command_from_payload
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:499	bookingextension_agent\normal_option_datetime_real_llm_test	decode_commands_from_payload
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:521	bookingextension_agent\normal_option_datetime_real_llm_test	payload_text
./tests/agent/real_llm_multistep/search_users_real_llm_test.php:40	bookingextension_agent\search_users_real_llm_test	setUp
./tests/agent/real_llm_multistep/search_users_real_llm_test.php:45	bookingextension_agent\search_users_real_llm_test	test_search_users_observation_contains_roles_courses_and_profile
./tests/agent/real_llm_multistep/search_users_real_llm_test.php:128	bookingextension_agent\search_users_real_llm_test	payload_text
./tests/agent/real_llm_multistep/search_users_real_llm_test.php:146	bookingextension_agent\search_users_real_llm_test	has_task_evidence
```

## Methodeninventur (JS Source, vollstaendig)

Hinweis: Erfasst wurden nur Source-Dateien (z. B. amd/src), keine minifizierten Build-Artefakte.

Format: `datei:zeile<TAB>typ<TAB>funktion`

```text
./amd/src/aiinstructions.js:69	const	runCollectedJavascript
./amd/src/aiinstructions.js:96	const	shouldAutoExecuteReadOnly
./amd/src/aiinstructions.js:113	const	renderMessageDebugMeta
./amd/src/aiinstructions.js:155	const	renderMessageDebugJson
./amd/src/aiinstructions.js:184	const	renderDebugLogs
./amd/src/aiinstructions.js:244	const	formatDebugLogsForClipboard
./amd/src/aiinstructions.js:286	const	parseJsonList
./amd/src/aiinstructions.js:301	const	parseJsonObjectList
./amd/src/aiinstructions.js:319	const	parseCommandPayload
./amd/src/aiinstructions.js:339	const	enforceErrorBubbleStyleFallback
./amd/src/aiinstructions.js:359	const	isTrialTokenInvalidError
./amd/src/aiinstructions.js:360	const	normalizedCodes
./amd/src/aiinstructions.js:408	const	maybeShowTrialTokenInvalidAlert
./amd/src/aiinstructions.js:432	const	renderAmbiguityOptionsHtml
./amd/src/aiinstructions.js:438	const	buttons
./amd/src/aiinstructions.js:468	const	renderFollowUpSuggestionsHtml
./amd/src/aiinstructions.js:478	const	buttons
./amd/src/aiinstructions.js:513	const	appendMessage
./amd/src/aiinstructions.js:536	const	appendPrivacyNote
./amd/src/aiinstructions.js:554	const	appendAssistantPrivacyNote
./amd/src/aiinstructions.js:577	const	appendMessageHtml
./amd/src/aiinstructions.js:596	const	setSidePreviewHtml
./amd/src/aiinstructions.js:607	const	initResizableLayout
./amd/src/aiinstructions.js:617	const	applyColumns
./amd/src/aiinstructions.js:624	const	restoreOrDefault
./amd/src/aiinstructions.js:637	const	onPointerMove
./amd/src/aiinstructions.js:645	const	previewPercent
./amd/src/aiinstructions.js:650	const	onMouseMove
./amd/src/aiinstructions.js:654	const	onTouchMove
./amd/src/aiinstructions.js:662	const	stopDragging
./amd/src/aiinstructions.js:671	const	startDragging
./amd/src/aiinstructions.js:701	const	initMobilePreviewSwitch
./amd/src/aiinstructions.js:711	const	setPreviewActive
./amd/src/aiinstructions.js:766	const	escapeHtml
./amd/src/aiinstructions.js:779	const	updateThinkingLabel
./amd/src/aiinstructions.js:792	const	copyTextToClipboard
./amd/src/aiinstructions.js:835	const	showButtonFeedback
./amd/src/aiinstructions.js:861	const	getDocLinkMeta
./amd/src/aiinstructions.js:898	const	renderSmartLink
./amd/src/aiinstructions.js:920	const	renderTextWithLinks
./amd/src/aiinstructions.js:966	const	renderAssistantMessageHtml
./amd/src/aiinstructions.js:991	const	extractFirstDoc
./amd/src/aiinstructions.js:1015	const	extractFirstUrl
./amd/src/aiinstructions.js:1031	const	loadUrlInSidePreview
./amd/src/aiinstructions.js:1051	const	escapeCssIdentifier
./amd/src/aiinstructions.js:1063	const	scrollPreviewToFragment
./amd/src/aiinstructions.js:1074	const	decoded
./amd/src/aiinstructions.js:1083	const	uniqueCandidates
./amd/src/aiinstructions.js:1104	const	loadDocInPreview
./amd/src/aiinstructions.js:1152	const	isGenericStatusMessage
./amd/src/aiinstructions.js:1179	const	getFirstResultField
./amd/src/aiinstructions.js:1205	const	buildFriendlyRunMessage
./amd/src/aiinstructions.js:1249	const	buildDebugRunHtml
./amd/src/aiinstructions.js:1263	const	items
./amd/src/aiinstructions.js:1286	const	appendFriendlyAssistantMessage
./amd/src/aiinstructions.js:1308	const	buildAgentResponseMeta
./amd/src/aiinstructions.js:1317	const	handleFinalAgentResponse
./amd/src/aiinstructions.js:1340	const	handleAgentCommandResponse
./amd/src/aiinstructions.js:1376	const	handleConfirmationResponse
./amd/src/aiinstructions.js:1405	const	showConfirmPanel
./amd/src/aiinstructions.js:1461	const	renderOptionPreviewsInline
./amd/src/aiinstructions.js:1462	const	uniqueIds
./amd/src/aiinstructions.js:1491	const	buildTaskPreviewHtml
./amd/src/aiinstructions.js:1501	const	payload
./amd/src/aiinstructions.js:1520	const	userResult
./amd/src/aiinstructions.js:1544	const	hideConfirmPanel
./amd/src/aiinstructions.js:1558	const	clearActivePlanBubble
./amd/src/aiinstructions.js:1575	const	showRunStatus
./amd/src/aiinstructions.js:1620	const	alertClass
./amd/src/aiinstructions.js:1626	const	items
./amd/src/aiinstructions.js:1688	const	extractPreviewOptionIds
./amd/src/aiinstructions.js:1720	const	collectPreviewOptionIds
./amd/src/aiinstructions.js:1756	const	appendStepBubble
./amd/src/aiinstructions.js:1776	const	clearStepBubbles
./amd/src/aiinstructions.js:1797	const	startStepPolling
./amd/src/aiinstructions.js:1828	const	refreshThreadDebugLogs
./amd/src/aiinstructions.js:1878	const	initDebugRefreshButton
./amd/src/aiinstructions.js:1909	const	stopStepPolling
./amd/src/aiinstructions.js:1919	const	resumeStepPolling
./amd/src/aiinstructions.js:1930	const	sendMessage
./amd/src/aiinstructions.js:2232	const	confirmRun
./amd/src/aiinstructions.js:2292	const	discardPendingConfirmation
./amd/src/aiinstructions.js:2329	const	getTrialUiContext
./amd/src/aiinstructions.js:2360	const	requestTrialKey
./amd/src/aiinstructions.js:2425	const	activateTrialContext
./amd/src/aiinstructions.js:2487	const	bindTrialButton
./amd/src/aiinstructions.js:2497	const	displayWelcomeMessage
./amd/src/aiinstructions.js:2525	const	stopCurrentRun
./amd/src/aiinstructions.js:2549	const	handleBodyClick
./amd/src/aiinstructions.js:2703	const	handleBodyKeydown
./amd/src/aiinstructions.js:2733	const	initCentralBodyHandlers
```
