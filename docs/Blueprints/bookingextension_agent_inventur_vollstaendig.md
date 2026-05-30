# Vollstaendige Inventur: bookingextension_agent

- Erfassungsdatum: 2026-05-30
- Plugin-Root: /var/www/moodle/public/mod/booking/bookingextension/agent
- Anzahl Ordner: 57
- Anzahl Dateien: 164
- Anzahl PHP-Methoden/Funktionen (benannte Deklarationen): 1004

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
./.github
./.github/workflows
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
./classes/local/wbagent/services/confirm_run_service.php
./classes/local/wbagent/services/execution_observation_ledger.php
./classes/local/wbagent/services/language_policy_service.php
./classes/local/wbagent/services/localized_string_service.php
./classes/local/wbagent/services/lookup/docs_lookup_service.php
./classes/local/wbagent/services/lookup/option_lookup_service.php
./classes/local/wbagent/services/mutation/entity_mutation_service.php
./classes/local/wbagent/services/mutation/option_mutation_service.php
./classes/local/wbagent/services/pending_intent_service.php
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
./classes/local/wbagent/task_registry_factory.php
./classes/local/wbagent/task_registry.php
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
./.github/workflows/erpnext.yml
./.github/workflows/moodle-plugin-ci.yml
./.github/workflows/moodle-release.yml
./.gitignore
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
classes/agent.php:35	bookingextension_agent\agent	get_plugin_name
classes/agent.php:44	bookingextension_agent\agent	contains_option_fields
classes/agent.php:53	bookingextension_agent\agent	get_option_fields_info_array
classes/agent.php:65	bookingextension_agent\agent	load_settings
classes/agent.php:77	bookingextension_agent\agent	load_data_for_settings_singleton
classes/agent.php:87	bookingextension_agent\agent	set_template_data_for_optionview
classes/agent.php:98	bookingextension_agent\agent	add_options_to_col_actions
classes/agent.php:107	bookingextension_agent\agent	get_allowedruleeventkeys
classes/agent.php:118	bookingextension_agent\agent	get_booking_history_description
classes/external/activate_trial_context.php:51	bookingextension_agent\external\activate_trial_context	execute_parameters
classes/external/activate_trial_context.php:63	bookingextension_agent\external\activate_trial_context	execute
classes/external/activate_trial_context.php:123	bookingextension_agent\external\activate_trial_context	execute_returns
classes/external/ai_confirm_run.php:59	bookingextension_agent\external\ai_confirm_run	execute_parameters
classes/external/ai_confirm_run.php:82	bookingextension_agent\external\ai_confirm_run	execute
classes/external/ai_confirm_run.php:159	bookingextension_agent\external\ai_confirm_run	execute_returns
classes/external/ai_get_doc_content.php:54	bookingextension_agent\external\ai_get_doc_content	execute_parameters
classes/external/ai_get_doc_content.php:68	bookingextension_agent\external\ai_get_doc_content	execute
classes/external/ai_get_doc_content.php:124	bookingextension_agent\external\ai_get_doc_content	execute_returns
classes/external/ai_get_doc_content.php:155	bookingextension_agent\external\ai_get_doc_content	markdown_to_html
classes/external/ai_get_doc_content.php:288	(global)	inline_format
classes/external/ai_get_doc_content.php:354	(global)	resolve_internal_doc_link
classes/external/ai_get_doc_content.php:395	(global)	normalize_relative_docs_path
classes/external/ai_get_doc_content.php:431	(global)	format_non_doc_link
classes/external/ai_get_doc_content.php:484	(global)	build_moodle_url_from_parts
classes/external/ai_get_thread_debug_logs.php:52	bookingextension_agent\external\ai_get_thread_debug_logs	execute_parameters
classes/external/ai_get_thread_debug_logs.php:68	bookingextension_agent\external\ai_get_thread_debug_logs	execute
classes/external/ai_get_thread_debug_logs.php:138	bookingextension_agent\external\ai_get_thread_debug_logs	execute_returns
classes/external/ai_list_candidate_options.php:54	bookingextension_agent\external\ai_list_candidate_options	execute_parameters
classes/external/ai_list_candidate_options.php:68	bookingextension_agent\external\ai_list_candidate_options	execute
classes/external/ai_list_candidate_options.php:124	bookingextension_agent\external\ai_list_candidate_options	execute_returns
classes/external/ai_poll_thread.php:52	bookingextension_agent\external\ai_poll_thread	execute_parameters
classes/external/ai_poll_thread.php:66	bookingextension_agent\external\ai_poll_thread	execute
classes/external/ai_poll_thread.php:121	bookingextension_agent\external\ai_poll_thread	execute_returns
classes/external/ai_privacy_precheck.php:48	bookingextension_agent\external\ai_privacy_precheck	execute_parameters
classes/external/ai_privacy_precheck.php:69	bookingextension_agent\external\ai_privacy_precheck	execute
classes/external/ai_privacy_precheck.php:153	bookingextension_agent\external\ai_privacy_precheck	execute_returns
classes/external/ai_render_command_preview.php:55	bookingextension_agent\external\ai_render_command_preview	execute_parameters
classes/external/ai_render_command_preview.php:97	bookingextension_agent\external\ai_render_command_preview	execute
classes/external/ai_render_command_preview.php:370	bookingextension_agent\external\ai_render_command_preview	render_preview_table
classes/external/ai_render_command_preview.php:434	(global)	execute_returns
classes/external/ai_send_message.php:66	bookingextension_agent\external\ai_send_message	execute_parameters
classes/external/ai_send_message.php:87	bookingextension_agent\external\ai_send_message	execute
classes/external/ai_send_message.php:269	bookingextension_agent\external\ai_send_message	normalize_string_list
classes/external/ai_send_message.php:292	bookingextension_agent\external\ai_send_message	resolve_response_queue_item_id
classes/external/ai_send_message.php:312	bookingextension_agent\external\ai_send_message	resolve_response_commands
classes/external/ai_send_message.php:362	bookingextension_agent\external\ai_send_message	resolve_preview_option_ids_json_for_response
classes/external/ai_send_message.php:410	bookingextension_agent\external\ai_send_message	resolve_preview_option_id_for_response
classes/external/ai_send_message.php:453	bookingextension_agent\external\ai_send_message	execute_returns
classes/external/booking_bulk_update_options.php:54	bookingextension_agent\external\booking_bulk_update_options	execute_parameters
classes/external/booking_bulk_update_options.php:78	bookingextension_agent\external\booking_bulk_update_options	execute
classes/external/booking_bulk_update_options.php:147	bookingextension_agent\external\booking_bulk_update_options	execute_returns
classes/external/booking_create_option.php:54	bookingextension_agent\external\booking_create_option	execute_parameters
classes/external/booking_create_option.php:75	bookingextension_agent\external\booking_create_option	execute
classes/external/booking_create_option.php:149	bookingextension_agent\external\booking_create_option	execute_returns
classes/external/booking_update_option.php:54	bookingextension_agent\external\booking_update_option	execute_parameters
classes/external/booking_update_option.php:78	bookingextension_agent\external\booking_update_option	execute
classes/external/booking_update_option.php:147	bookingextension_agent\external\booking_update_option	execute_returns
classes/external/booking_validate_option.php:58	bookingextension_agent\external\booking_validate_option	execute_parameters
classes/external/booking_validate_option.php:74	bookingextension_agent\external\booking_validate_option	execute
classes/external/booking_validate_option.php:137	(global)	execute_returns
classes/external/request_trial_key.php:48	bookingextension_agent\external\request_trial_key	execute_parameters
classes/external/request_trial_key.php:60	bookingextension_agent\external\request_trial_key	execute
classes/external/request_trial_key.php:105	bookingextension_agent\external\request_trial_key	execute_returns
classes/external/ws_message_formatter.php:38	bookingextension_agent\external\ws_message_formatter	format_ws_message
classes/local/wbagent/adaptive_task_catalog_service.php:70	bookingextension_agent\local\wbagent\adaptive_task_catalog_service	get_adaptive_catalog
classes/local/wbagent/adaptive_task_catalog_service.php:110	bookingextension_agent\local\wbagent\adaptive_task_catalog_service	get_mandatory_tasks
classes/local/wbagent/adaptive_task_catalog_service.php:136	bookingextension_agent\local\wbagent\adaptive_task_catalog_service	get_recency_filtered
classes/local/wbagent/agent_decision_service.php:138	bookingextension_agent\local\wbagent\agent_decision_service	__construct
classes/local/wbagent/agent_decision_service.php:173	bookingextension_agent\local\wbagent\agent_decision_service	process
classes/local/wbagent/agent_decision_service.php:364	bookingextension_agent\local\wbagent\agent_decision_service	should_block_new_intent_while_pending
classes/local/wbagent/agent_decision_service.php:394	bookingextension_agent\local\wbagent\agent_decision_service	build_pending_resolution_clarification
classes/local/wbagent/agent_decision_service.php:441	bookingextension_agent\local\wbagent\agent_decision_service	build_pending_intent_summary
classes/local/wbagent/agent_decision_service.php:460	bookingextension_agent\local\wbagent\agent_decision_service	build_commands_from_pending_queue
classes/local/wbagent/agent_decision_service.php:494	bookingextension_agent\local\wbagent\agent_decision_service	enforce_task_boundary_invariants
classes/local/wbagent/agent_decision_service.php:519	bookingextension_agent\local\wbagent\agent_decision_service	enforce_response_contract_invariants
classes/local/wbagent/agent_decision_service.php:561	bookingextension_agent\local\wbagent\agent_decision_service	normalize_commands_for_contract_recovery
classes/local/wbagent/agent_decision_service.php:602	bookingextension_agent\local\wbagent\agent_decision_service	build_fallback_message
classes/local/wbagent/agent_decision_service.php:654	bookingextension_agent\local\wbagent\agent_decision_service	handle_confirm_pending
classes/local/wbagent/agent_decision_service.php:786	bookingextension_agent\local\wbagent\agent_decision_service	handle_command_routing
classes/local/wbagent/agent_decision_service.php:972	bookingextension_agent\local\wbagent\agent_decision_service	slice_first_mutation_confirmation_stage
classes/local/wbagent/agent_decision_service.php:989	bookingextension_agent\local\wbagent\agent_decision_service	find_missing_option_anchor_readonly_task
classes/local/wbagent/agent_decision_service.php:1035	bookingextension_agent\local\wbagent\agent_decision_service	enrich_readonly_commands_with_planner
classes/local/wbagent/agent_decision_service.php:1087	bookingextension_agent\local\wbagent\agent_decision_service	enrich_option_anchor_inputs
classes/local/wbagent/agent_decision_service.php:1169	bookingextension_agent\local\wbagent\agent_decision_service	handle_preflight
classes/local/wbagent/agent_decision_service.php:1390	bookingextension_agent\local\wbagent\agent_decision_service	apply_preflight_queue_decision
classes/local/wbagent/agent_decision_service.php:1514	bookingextension_agent\local\wbagent\agent_decision_service	apply_confirmable_overrides
classes/local/wbagent/agent_decision_service.php:1557	bookingextension_agent\local\wbagent\agent_decision_service	apply_execution_guard_tokens
classes/local/wbagent/agent_decision_service.php:1599	bookingextension_agent\local\wbagent\agent_decision_service	execute_readonly_commands
classes/local/wbagent/agent_decision_service.php:1810	bookingextension_agent\local\wbagent\agent_decision_service	inject_output_language_into_commands
classes/local/wbagent/agent_decision_service.php:1836	bookingextension_agent\local\wbagent\agent_decision_service	with_output_language
classes/local/wbagent/agent_decision_service.php:1866	bookingextension_agent\local\wbagent\agent_decision_service	build_confirmation_validation_message
classes/local/wbagent/agent_decision_service.php:1919	bookingextension_agent\local\wbagent\agent_decision_service	extract_teacher_query_from_validation_errors
classes/local/wbagent/agent_decision_service.php:1939	bookingextension_agent\local\wbagent\agent_decision_service	has_mutating_commands
classes/local/wbagent/agent_decision_service.php:1964	bookingextension_agent\local\wbagent\agent_decision_service	split_commands_by_mutability
classes/local/wbagent/agent_decision_service.php:1990	bookingextension_agent\local\wbagent\agent_decision_service	execution_result_has_failures
classes/local/wbagent/agent_decision_service.php:2016	bookingextension_agent\local\wbagent\agent_decision_service	has_confirmable_prevalidation_issues
classes/local/wbagent/agent_decision_service.php:2037	bookingextension_agent\local\wbagent\agent_decision_service	has_recent_duplicate_title_prompt
classes/local/wbagent/agent_decision_service.php:2075	bookingextension_agent\local\wbagent\agent_decision_service	apply_duplicate_title_override
classes/local/wbagent/agent_decision_service.php:2124	bookingextension_agent\local\wbagent\agent_decision_service	augment_missing_teacher_autocreate_confirmation
classes/local/wbagent/agent_decision_service.php:2195	bookingextension_agent\local\wbagent\agent_decision_service	resolve_task_name_by_suffix
classes/local/wbagent/agent_decision_service.php:2220	bookingextension_agent\local\wbagent\agent_decision_service	user_allows_missing_user_autocreate
classes/local/wbagent/agent_decision_service.php:2244	bookingextension_agent\local\wbagent\agent_decision_service	get_last_user_message
classes/local/wbagent/agent_decision_service.php:2261	bookingextension_agent\local\wbagent\agent_decision_service	is_substantive_clarification_message
classes/local/wbagent/agent_decision_service.php:2272	bookingextension_agent\local\wbagent\agent_decision_service	is_non_substantive_clarification_message
classes/local/wbagent/agent_decision_service.php:2353	bookingextension_agent\local\wbagent\agent_decision_service	extract_option_id_from_message
classes/local/wbagent/agent_decision_service.php:2383	bookingextension_agent\local\wbagent\agent_decision_service	extract_option_search_query
classes/local/wbagent/agent_decision_service.php:2406	bookingextension_agent\local\wbagent\agent_decision_service	clarification_result
classes/local/wbagent/agent_decision_service.php:2434	bookingextension_agent\local\wbagent\agent_decision_service	localized
classes/local/wbagent/agent_decision_service.php:2444	bookingextension_agent\local\wbagent\agent_decision_service	normalize_queue_item_ids
classes/local/wbagent/agent_runtime.php:166	bookingextension_agent\local\wbagent\agent_runtime	__construct
classes/local/wbagent/agent_runtime.php:219	bookingextension_agent\local\wbagent\agent_runtime	run
classes/local/wbagent/agent_runtime.php:243	bookingextension_agent\local\wbagent\agent_runtime	budget_guard_allows_next_llm_call
classes/local/wbagent/agent_runtime.php:256	bookingextension_agent\local\wbagent\agent_runtime	build_budget_exceeded_result
classes/local/wbagent/agent_runtime.php:297	bookingextension_agent\local\wbagent\agent_runtime	refresh_pending_queue_retry_state
classes/local/wbagent/agent_runtime.php:374	bookingextension_agent\local\wbagent\agent_runtime	run_loop
classes/local/wbagent/agent_runtime.php:682	bookingextension_agent\local\wbagent\agent_runtime	is_readonly_signature_budget_reached
classes/local/wbagent/agent_runtime.php:702	bookingextension_agent\local\wbagent\agent_runtime	enforce_final_response_contract
classes/local/wbagent/agent_runtime.php:777	bookingextension_agent\local\wbagent\agent_runtime	normalize_iso_language
classes/local/wbagent/agent_runtime.php:787	bookingextension_agent\local\wbagent\agent_runtime	strip_markdown_fences_from_message
classes/local/wbagent/agent_runtime.php:809	bookingextension_agent\local\wbagent\agent_runtime	build_contract_fallback_message
classes/local/wbagent/agent_runtime.php:831	bookingextension_agent\local\wbagent\agent_runtime	attach_loop_results
classes/local/wbagent/agent_runtime.php:906	bookingextension_agent\local\wbagent\agent_runtime	loop_state_contains_only_readonly_results
classes/local/wbagent/agent_runtime.php:934	bookingextension_agent\local\wbagent\agent_runtime	deduplicate_loop_results
classes/local/wbagent/agent_runtime.php:977	bookingextension_agent\local\wbagent\agent_runtime	score_loop_result_entry
classes/local/wbagent/agent_runtime.php:1012	bookingextension_agent\local\wbagent\agent_runtime	has_issue_code
classes/local/wbagent/agent_runtime.php:1032	bookingextension_agent\local\wbagent\agent_runtime	build_loop_repeat_summary
classes/local/wbagent/agent_runtime.php:1112	bookingextension_agent\local\wbagent\agent_runtime	maybe_enrich_message_from_results
classes/local/wbagent/agent_runtime.php:1163	bookingextension_agent\local\wbagent\agent_runtime	should_finalize_after_execution_result
classes/local/wbagent/agent_runtime.php:1210	bookingextension_agent\local\wbagent\agent_runtime	build_sufficient_execution_result_clarification
classes/local/wbagent/agent_runtime.php:1257	bookingextension_agent\local\wbagent\agent_runtime	should_recover_from_missing_commands_error
classes/local/wbagent/agent_runtime.php:1285	bookingextension_agent\local\wbagent\agent_runtime	recover_missing_commands_error_result
classes/local/wbagent/agent_runtime.php:1345	bookingextension_agent\local\wbagent\agent_runtime	should_retry_preflight_clarification
classes/local/wbagent/agent_runtime.php:1420	bookingextension_agent\local\wbagent\agent_runtime	should_synthesize_after_success_without_pending_intent
classes/local/wbagent/agent_runtime.php:1447	bookingextension_agent\local\wbagent\agent_runtime	build_preflight_retry_observation
classes/local/wbagent/agent_runtime.php:1509	bookingextension_agent\local\wbagent\agent_runtime	build_retry_task_catalog_context
classes/local/wbagent/agent_runtime.php:1555	bookingextension_agent\local\wbagent\agent_runtime	slim_retry_task_contract
classes/local/wbagent/agent_runtime.php:1571	bookingextension_agent\local\wbagent\agent_runtime	build_preflight_fix_instructions
classes/local/wbagent/agent_runtime.php:1619	bookingextension_agent\local\wbagent\agent_runtime	observations_are_framework_retry_hints
classes/local/wbagent/agent_runtime.php:1643	bookingextension_agent\local\wbagent\agent_runtime	is_low_information_message
classes/local/wbagent/agent_runtime.php:1676	bookingextension_agent\local\wbagent\agent_runtime	build_step_label
classes/local/wbagent/agent_runtime.php:1727	bookingextension_agent\local\wbagent\agent_runtime	write_step_progress_message
classes/local/wbagent/agent_runtime.php:1754	bookingextension_agent\local\wbagent\agent_runtime	extract_next_step_intent
classes/local/wbagent/agent_runtime.php:1778	bookingextension_agent\local\wbagent\agent_runtime	extract_step_task_names
classes/local/wbagent/agent_runtime.php:1813	bookingextension_agent\local\wbagent\agent_runtime	humanize_task_name
classes/local/wbagent/agent_runtime.php:1840	bookingextension_agent\local\wbagent\agent_runtime	is_repeated_readonly_step
classes/local/wbagent/agent_runtime.php:1876	bookingextension_agent\local\wbagent\agent_runtime	extract_step_command_signatures
classes/local/wbagent/agent_runtime.php:1911	bookingextension_agent\local\wbagent\agent_runtime	normalize_command_input_for_signature
classes/local/wbagent/agent_runtime.php:1946	bookingextension_agent\local\wbagent\agent_runtime	run_internal
classes/local/wbagent/agent_runtime.php:2120	bookingextension_agent\local\wbagent\agent_runtime	apply_signature_based_recall_guard
classes/local/wbagent/agent_runtime.php:2198	bookingextension_agent\local\wbagent\agent_runtime	apply_observation_based_recall_guard
classes/local/wbagent/agent_runtime.php:2240	bookingextension_agent\local\wbagent\agent_runtime	all_commands_match_task
classes/local/wbagent/agent_runtime.php:2264	bookingextension_agent\local\wbagent\agent_runtime	all_commands_match_any_task
classes/local/wbagent/agent_runtime.php:2288	bookingextension_agent\local\wbagent\agent_runtime	get_diagnosis_task_names
classes/local/wbagent/agent_runtime.php:2316	bookingextension_agent\local\wbagent\agent_runtime	observations_include_diagnosis_result
classes/local/wbagent/agent_runtime.php:2345	bookingextension_agent\local\wbagent\agent_runtime	apply_hard_contract_gate
classes/local/wbagent/agent_runtime.php:2435	bookingextension_agent\local\wbagent\agent_runtime	normalize_unknown_response_type_to_contract_error
classes/local/wbagent/agent_runtime.php:2475	bookingextension_agent\local\wbagent\agent_runtime	is_hard_contract_error
classes/local/wbagent/agent_runtime.php:2502	bookingextension_agent\local\wbagent\agent_runtime	build_option_type_explanation_shortcut
classes/local/wbagent/agent_runtime.php:2577	bookingextension_agent\local\wbagent\agent_runtime	is_meta_clarification_follow_up
classes/local/wbagent/agent_runtime.php:2595	bookingextension_agent\local\wbagent\agent_runtime	assistant_prompted_for_option_type
classes/local/wbagent/agent_runtime.php:2622	bookingextension_agent\local\wbagent\agent_runtime	call_orchestrator_step
classes/local/wbagent/agent_runtime.php:2639	bookingextension_agent\local\wbagent\agent_runtime	resolve_output_language
classes/local/wbagent/agent_runtime.php:2653	bookingextension_agent\local\wbagent\agent_runtime	loop_continue_result
classes/local/wbagent/agent_runtime.php:2695	bookingextension_agent\local\wbagent\agent_runtime	run_synthesis_step
classes/local/wbagent/agent_runtime.php:2760	(global)	extract_recorded_step_task_names
classes/local/wbagent/agent_runtime.php:2784	(global)	has_explain_or_diagnose_task
classes/local/wbagent/agent_runtime.php:2815	(global)	should_convert_sufficient_to_readonly_clarification
classes/local/wbagent/agent_runtime.php:2846	(global)	is_sufficiency_exit_signal
classes/local/wbagent/agent_runtime.php:2880	(global)	resolve_synthesis_user_language
classes/local/wbagent/agent_runtime.php:2919	(global)	loop_repeat_narration_result
classes/local/wbagent/agent_runtime.php:2976	(global)	normalize_final_reasoning_narration
classes/local/wbagent/agent_runtime.php:3002	(global)	is_final_clarification_without_commands
classes/local/wbagent/agent_runtime.php:3025	(global)	should_run_synthesis_for_clarification
classes/local/wbagent/agent_runtime.php:3053	(global)	build_deterministic_loop_repeat_fallback
classes/local/wbagent/agent_runtime.php:3114	(global)	loop_repeat_result
classes/local/wbagent/agent_runtime.php:3157	(global)	resolve_preview_option_id
classes/local/wbagent/agent_runtime.php:3191	(global)	normalize_trimmed_string_list
classes/local/wbagent/agent_state.php:77	bookingextension_agent\local\wbagent\agent_state	__construct
classes/local/wbagent/agent_state.php:87	bookingextension_agent\local\wbagent\agent_state	make
classes/local/wbagent/agent_state.php:101	bookingextension_agent\local\wbagent\agent_state	make_resumed
classes/local/wbagent/agent_state.php:124	bookingextension_agent\local\wbagent\agent_state	record_step
classes/local/wbagent/agent_state.php:146	bookingextension_agent\local\wbagent\agent_state	get_observations
classes/local/wbagent/agent_state.php:155	bookingextension_agent\local\wbagent\agent_state	get_steps
classes/local/wbagent/agent_state.php:164	bookingextension_agent\local\wbagent\agent_state	step_count
classes/local/wbagent/agent_state.php:173	bookingextension_agent\local\wbagent\agent_state	has_observations
classes/local/wbagent/agent_state.php:186	bookingextension_agent\local\wbagent\agent_state	extract_observed_command_signatures
classes/local/wbagent/agent_state.php:227	bookingextension_agent\local\wbagent\agent_state	normalize_command_input
classes/local/wbagent/aiready.php:69	bookingextension_agent\local\wbagent\aiready	__construct
classes/local/wbagent/aiready.php:80	bookingextension_agent\local\wbagent\aiready	export_for_template
classes/local/wbagent/aiready.php:288	bookingextension_agent\local\wbagent\aiready	build_check
classes/local/wbagent/aiready.php:306	bookingextension_agent\local\wbagent\aiready	is_module_ai_toggle_enabled
classes/local/wbagent/aiready.php:320	bookingextension_agent\local\wbagent\aiready	get_booking_statistics
classes/local/wbagent/ai_error_classifier.php:53	bookingextension_agent\local\wbagent\ai_error_classifier	classify_from_response
classes/local/wbagent/ai_error_classifier.php:130	bookingextension_agent\local\wbagent\ai_error_classifier	classify_from_db
classes/local/wbagent/authorization_service.php:46	bookingextension_agent\local\wbagent\authorization_service	is_agent_extension_installed
classes/local/wbagent/authorization_service.php:65	bookingextension_agent\local\wbagent\authorization_service	require_booking_module_context
classes/local/wbagent/authorization_service.php:84	bookingextension_agent\local\wbagent\authorization_service	require_use_capability
classes/local/wbagent/authorization_service.php:101	bookingextension_agent\local\wbagent\authorization_service	can_use
classes/local/wbagent/authorization_service.php:120	bookingextension_agent\local\wbagent\authorization_service	require_valid_context
classes/local/wbagent/base_task.php:47	bookingextension_agent\local\wbagent\base_task	__construct
classes/local/wbagent/base_task.php:56	bookingextension_agent\local\wbagent\base_task	is_read_only
classes/local/wbagent/base_task.php:68	bookingextension_agent\local\wbagent\base_task	get_example_input
classes/local/wbagent/base_task.php:77	bookingextension_agent\local\wbagent\base_task	get_prompt_contract
classes/local/wbagent/base_task.php:106	bookingextension_agent\local\wbagent\base_task	check_structure
classes/local/wbagent/base_task.php:118	bookingextension_agent\local\wbagent\base_task	preflight
classes/local/wbagent/booking_issue_code_provider.php:36	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_duplicate_confirmation_issue_codes
classes/local/wbagent/booking_issue_code_provider.php:48	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_token_subscription_issue_codes
classes/local/wbagent/booking_issue_code_provider.php:63	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_prevalidation_confirmable_issue_codes
classes/local/wbagent/booking_issue_code_provider.php:80	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_basic_subscription_url
classes/local/wbagent/booking_issue_code_provider.php:89	bookingextension_agent\local\wbagent\booking_issue_code_provider	get_premium_subscription_url
classes/local/wbagent/conversation_store.php:54	bookingextension_agent\local\wbagent\conversation_store	get_active_thread
classes/local/wbagent/conversation_store.php:74	bookingextension_agent\local\wbagent\conversation_store	get_or_create_thread
classes/local/wbagent/conversation_store.php:109	bookingextension_agent\local\wbagent\conversation_store	create_fresh_thread
classes/local/wbagent/conversation_store.php:149	bookingextension_agent\local\wbagent\conversation_store	add_message
classes/local/wbagent/conversation_store.php:180	bookingextension_agent\local\wbagent\conversation_store	add_step_message
classes/local/wbagent/conversation_store.php:197	bookingextension_agent\local\wbagent\conversation_store	clear_step_messages
classes/local/wbagent/conversation_store.php:211	bookingextension_agent\local\wbagent\conversation_store	get_step_messages_since
classes/local/wbagent/conversation_store.php:231	bookingextension_agent\local\wbagent\conversation_store	get_messages
classes/local/wbagent/conversation_store.php:242	bookingextension_agent\local\wbagent\conversation_store	get_thread
classes/local/wbagent/conversation_store.php:255	bookingextension_agent\local\wbagent\conversation_store	get_recent_messages
classes/local/wbagent/conversation_store.php:282	bookingextension_agent\local\wbagent\conversation_store	get_last_thread_for_user
classes/local/wbagent/conversation_store.php:353	bookingextension_agent\local\wbagent\conversation_store	get_user_threads_by_date_window
classes/local/wbagent/conversation_store.php:392	bookingextension_agent\local\wbagent\conversation_store	get_user_messages_for_thread
classes/local/wbagent/conversation_store.php:456	bookingextension_agent\local\wbagent\conversation_store	create_run
classes/local/wbagent/conversation_store.php:482	bookingextension_agent\local\wbagent\conversation_store	update_run_status
classes/local/wbagent/conversation_store.php:502	bookingextension_agent\local\wbagent\conversation_store	get_run
classes/local/wbagent/conversation_store.php:513	bookingextension_agent\local\wbagent\conversation_store	get_latest_run
classes/local/wbagent/conversation_store.php:526	bookingextension_agent\local\wbagent\conversation_store	run_exists
classes/local/wbagent/conversation_store.php:538	bookingextension_agent\local\wbagent\conversation_store	run_exists_other_than
classes/local/wbagent/conversation_store.php:559	bookingextension_agent\local\wbagent\conversation_store	get_thread_metadata_value
classes/local/wbagent/conversation_store.php:583	bookingextension_agent\local\wbagent\conversation_store	set_thread_metadata_value
classes/local/wbagent/conversation_store.php:617	bookingextension_agent\local\wbagent\conversation_store	set_pending_intent
classes/local/wbagent/conversation_store.php:658	bookingextension_agent\local\wbagent\conversation_store	get_pending_intent
classes/local/wbagent/conversation_store.php:692	bookingextension_agent\local\wbagent\conversation_store	consume_pending_intent
classes/local/wbagent/conversation_store.php:718	bookingextension_agent\local\wbagent\conversation_store	clear_pending_intent
classes/local/wbagent/conversation_store.php:730	bookingextension_agent\local\wbagent\conversation_store	allow_confirmation_for_session
classes/local/wbagent/conversation_store.php:746	bookingextension_agent\local\wbagent\conversation_store	allow_confirmation_for_thread
classes/local/wbagent/conversation_store.php:764	bookingextension_agent\local\wbagent\conversation_store	is_confirmation_allowed_for_session
classes/local/wbagent/conversation_store.php:780	bookingextension_agent\local\wbagent\conversation_store	is_confirmation_allowed_for_thread
classes/local/wbagent/conversation_store.php:794	bookingextension_agent\local\wbagent\conversation_store	clear_confirmation_allowance
classes/local/wbagent/conversation_store.php:807	bookingextension_agent\local\wbagent\conversation_store	make_confirmation_session_allowlist_key
classes/local/wbagent/conversation_store.php:817	bookingextension_agent\local\wbagent\conversation_store	get_confirmation_session_allowlist
classes/local/wbagent/conversation_store.php:865	bookingextension_agent\local\wbagent\conversation_store	save_confirmation_session_allowlist
classes/local/wbagent/conversation_store.php:882	bookingextension_agent\local\wbagent\conversation_store	add_llm_debug_entry
classes/local/wbagent/conversation_store.php:915	bookingextension_agent\local\wbagent\conversation_store	get_llm_debug_entries
classes/local/wbagent/core/tasks/core_task_base.php:38	bookingextension_agent\local\wbagent\core\tasks\core_task_base	get_output_language
classes/local/wbagent/core/tasks/core_task_base.php:57	bookingextension_agent\local\wbagent\core\tasks\core_task_base	localized_string
classes/local/wbagent/core/tasks/core_task_base.php:74	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_task_debug_message
classes/local/wbagent/core/tasks/core_task_base.php:106	bookingextension_agent\local\wbagent\core\tasks\core_task_base	enrich_schema_with_prompt_meta
classes/local/wbagent/core/tasks/core_task_base.php:143	bookingextension_agent\local\wbagent\core\tasks\core_task_base	stringify_debug_value
classes/local/wbagent/core/tasks/core_task_base.php:159	bookingextension_agent\local\wbagent\core\tasks\core_task_base	resolve_userid
classes/local/wbagent/core/tasks/core_task_base.php:190	bookingextension_agent\local\wbagent\core\tasks\core_task_base	resolve_courseid
classes/local/wbagent/core/tasks/core_task_base.php:215	bookingextension_agent\local\wbagent\core\tasks\core_task_base	resolve_groupid
classes/local/wbagent/core/tasks/core_task_base.php:249	bookingextension_agent\local\wbagent\core\tasks\core_task_base	can_access_user
classes/local/wbagent/core/tasks/core_task_base.php:280	bookingextension_agent\local\wbagent\core\tasks\core_task_base	preflight
classes/local/wbagent/core/tasks/core_task_base.php:302	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_user_payload
classes/local/wbagent/core/tasks/core_task_base.php:355	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_user_courses_payload
classes/local/wbagent/core/tasks/core_task_base.php:402	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_user_roles_payload
classes/local/wbagent/core/tasks/core_task_base.php:450	bookingextension_agent\local\wbagent\core\tasks\core_task_base	extract_custom_profile_fields
classes/local/wbagent/core/tasks/core_task_base.php:470	bookingextension_agent\local\wbagent\core\tasks\core_task_base	search_user_candidates_for_preview
classes/local/wbagent/core/tasks/core_task_base.php:513	bookingextension_agent\local\wbagent\core\tasks\core_task_base	search_course_candidates_for_preview
classes/local/wbagent/core/tasks/core_task_base.php:562	bookingextension_agent\local\wbagent\core\tasks\core_task_base	count_active_course_enrolments
classes/local/wbagent/core/tasks/core_task_base.php:581	bookingextension_agent\local\wbagent\core\tasks\core_task_base	build_user_observation_full
classes/local/wbagent/core/tasks/core_task_base.php:639	(global)	format_observation_scalar
classes/local/wbagent/core/tasks/core_task_base.php:656	(global)	format_course_observation
classes/local/wbagent/core/tasks/core_task_base.php:684	(global)	format_role_observation
classes/local/wbagent/core/tasks/core_task_base.php:712	(global)	format_custom_profile_field_observation
classes/local/wbagent/core/tasks/get_current_user_task.php:34	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	__construct
classes/local/wbagent/core/tasks/get_current_user_task.php:43	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	get_name
classes/local/wbagent/core/tasks/get_current_user_task.php:52	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	get_schema
classes/local/wbagent/core/tasks/get_current_user_task.php:73	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	check_structure
classes/local/wbagent/core/tasks/get_current_user_task.php:86	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	get_message_triggers
classes/local/wbagent/core/tasks/get_current_user_task.php:105	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	get_contextual_prompt_packs
classes/local/wbagent/core/tasks/get_current_user_task.php:131	bookingextension_agent\local\wbagent\core\tasks\get_current_user_task	execute
classes/local/wbagent/core/tasks/list_actions_task.php:41	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	__construct
classes/local/wbagent/core/tasks/list_actions_task.php:50	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	get_name
classes/local/wbagent/core/tasks/list_actions_task.php:59	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	get_schema
classes/local/wbagent/core/tasks/list_actions_task.php:91	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	get_message_triggers
classes/local/wbagent/core/tasks/list_actions_task.php:110	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	check_structure
classes/local/wbagent/core/tasks/list_actions_task.php:133	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	get_contextual_prompt_packs
classes/local/wbagent/core/tasks/list_actions_task.php:159	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	execute
classes/local/wbagent/core/tasks/list_actions_task.php:239	bookingextension_agent\local\wbagent\core\tasks\list_actions_task	build_observation_full
classes/local/wbagent/core/tasks/list_actions_task.php:274	(global)	get_localized_string
classes/local/wbagent/core/tasks/list_actions_task.php:288	(global)	build_debug_summary
classes/local/wbagent/core/tasks/list_actions_task.php:312	(global)	build_user_summary
classes/local/wbagent/core/tasks/list_actions_task.php:391	(global)	describe_deny_reason
classes/local/wbagent/core/tasks/list_actions_task.php:423	(global)	build_unavailable_action_detail
classes/local/wbagent/core/tasks/list_actions_task.php:452	(global)	build_user_capabilities
classes/local/wbagent/core/tasks/recall_memory_task.php:36	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	__construct
classes/local/wbagent/core/tasks/recall_memory_task.php:45	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	get_name
classes/local/wbagent/core/tasks/recall_memory_task.php:54	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	get_schema
classes/local/wbagent/core/tasks/recall_memory_task.php:107	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	get_example_input
classes/local/wbagent/core/tasks/recall_memory_task.php:119	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	check_structure
classes/local/wbagent/core/tasks/recall_memory_task.php:145	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	get_message_triggers
classes/local/wbagent/core/tasks/recall_memory_task.php:175	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	execute
classes/local/wbagent/core/tasks/recall_memory_task.php:280	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	resolve_date_window
classes/local/wbagent/core/tasks/recall_memory_task.php:329	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	resolve_user_timezone
classes/local/wbagent/core/tasks/recall_memory_task.php:359	bookingextension_agent\local\wbagent\core\tasks\recall_memory_task	build_memory_observation_text
classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:37	bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task	__construct
classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:46	bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task	get_name
classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:55	bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task	get_schema
classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:93	bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task	get_message_triggers
classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:112	bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task	check_structure
classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:137	bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task	execute
classes/local/wbagent/core/tasks/search_courses_task.php:35	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	__construct
classes/local/wbagent/core/tasks/search_courses_task.php:44	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	get_name
classes/local/wbagent/core/tasks/search_courses_task.php:53	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	get_schema
classes/local/wbagent/core/tasks/search_courses_task.php:87	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	get_message_triggers
classes/local/wbagent/core/tasks/search_courses_task.php:105	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	get_contextual_prompt_packs
classes/local/wbagent/core/tasks/search_courses_task.php:134	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	check_structure
classes/local/wbagent/core/tasks/search_courses_task.php:155	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	execute
classes/local/wbagent/core/tasks/search_courses_task.php:209	bookingextension_agent\local\wbagent\core\tasks\search_courses_task	build_course_observation_full
classes/local/wbagent/core/tasks/search_users_task.php:35	bookingextension_agent\local\wbagent\core\tasks\search_users_task	__construct
classes/local/wbagent/core/tasks/search_users_task.php:44	bookingextension_agent\local\wbagent\core\tasks\search_users_task	get_name
classes/local/wbagent/core/tasks/search_users_task.php:53	bookingextension_agent\local\wbagent\core\tasks\search_users_task	get_schema
classes/local/wbagent/core/tasks/search_users_task.php:86	bookingextension_agent\local\wbagent\core\tasks\search_users_task	get_message_triggers
classes/local/wbagent/core/tasks/search_users_task.php:105	bookingextension_agent\local\wbagent\core\tasks\search_users_task	get_contextual_prompt_packs
classes/local/wbagent/core/tasks/search_users_task.php:133	bookingextension_agent\local\wbagent\core\tasks\search_users_task	check_structure
classes/local/wbagent/core/tasks/search_users_task.php:155	bookingextension_agent\local\wbagent\core\tasks\search_users_task	execute
classes/local/wbagent/dto/bulk_update_options_input_dto.php:45	bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto	__construct
classes/local/wbagent/dto/bulk_update_options_input_dto.php:55	bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto	from_array
classes/local/wbagent/dto/bulk_update_options_input_dto.php:64	bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto	to_array
classes/local/wbagent/dto/bulk_update_options_input_dto.php:75	bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto	get
classes/local/wbagent/dto/create_entity_input_dto.php:45	bookingextension_agent\local\wbagent\dto\create_entity_input_dto	__construct
classes/local/wbagent/dto/create_entity_input_dto.php:56	bookingextension_agent\local\wbagent\dto\create_entity_input_dto	from_array
classes/local/wbagent/dto/create_entity_input_dto.php:68	bookingextension_agent\local\wbagent\dto\create_entity_input_dto	to_array
classes/local/wbagent/dto/create_entity_input_dto.php:79	bookingextension_agent\local\wbagent\dto\create_entity_input_dto	get
classes/local/wbagent/dto/create_option_input_dto.php:45	bookingextension_agent\local\wbagent\dto\create_option_input_dto	__construct
classes/local/wbagent/dto/create_option_input_dto.php:56	bookingextension_agent\local\wbagent\dto\create_option_input_dto	from_array
classes/local/wbagent/dto/create_option_input_dto.php:68	bookingextension_agent\local\wbagent\dto\create_option_input_dto	to_array
classes/local/wbagent/dto/create_option_input_dto.php:79	bookingextension_agent\local\wbagent\dto\create_option_input_dto	get
classes/local/wbagent/dto/mutation_result_dto.php:63	bookingextension_agent\local\wbagent\dto\mutation_result_dto	__construct
classes/local/wbagent/dto/mutation_result_dto.php:86	bookingextension_agent\local\wbagent\dto\mutation_result_dto	success
classes/local/wbagent/dto/mutation_result_dto.php:101	bookingextension_agent\local\wbagent\dto\mutation_result_dto	error
classes/local/wbagent/dto/mutation_result_dto.php:111	bookingextension_agent\local\wbagent\dto\mutation_result_dto	skipped
classes/local/wbagent/dto/mutation_result_dto.php:122	bookingextension_agent\local\wbagent\dto\mutation_result_dto	dry_run_ok
classes/local/wbagent/dto/mutation_result_dto.php:131	bookingextension_agent\local\wbagent\dto\mutation_result_dto	to_array
classes/local/wbagent/dto/update_option_input_dto.php:45	bookingextension_agent\local\wbagent\dto\update_option_input_dto	__construct
classes/local/wbagent/dto/update_option_input_dto.php:55	bookingextension_agent\local\wbagent\dto\update_option_input_dto	from_array
classes/local/wbagent/dto/update_option_input_dto.php:64	bookingextension_agent\local\wbagent\dto\update_option_input_dto	to_array
classes/local/wbagent/dto/update_option_input_dto.php:75	bookingextension_agent\local\wbagent\dto\update_option_input_dto	get
classes/local/wbagent/embeddings_action_config_resolver.php:50	bookingextension_agent\local\wbagent\embeddings_action_config_resolver	resolve
classes/local/wbagent/embeddings_catalog_builder_service.php:41	bookingextension_agent\local\wbagent\embeddings_catalog_builder_service	build_full_catalog_rows
classes/local/wbagent/embeddings_catalog_builder_service.php:104	bookingextension_agent\local\wbagent\embeddings_catalog_builder_service	compute_content_hash
classes/local/wbagent/embeddings_catalog_builder_service.php:120	bookingextension_agent\local\wbagent\embeddings_catalog_builder_service	to_embedding_input
classes/local/wbagent/embeddings_catalog_builder_service.php:145	bookingextension_agent\local\wbagent\embeddings_catalog_builder_service	get_contextual_prompt_packs_for_task
classes/local/wbagent/embeddings_csv_repository.php:53	bookingextension_agent\local\wbagent\embeddings_csv_repository	get_csv_path
classes/local/wbagent/embeddings_csv_repository.php:63	bookingextension_agent\local\wbagent\embeddings_csv_repository	exists
classes/local/wbagent/embeddings_csv_repository.php:72	bookingextension_agent\local\wbagent\embeddings_csv_repository	read_rows
classes/local/wbagent/embeddings_csv_repository.php:107	bookingextension_agent\local\wbagent\embeddings_csv_repository	is_valid_schema
classes/local/wbagent/embeddings_csv_repository.php:133	bookingextension_agent\local\wbagent\embeddings_csv_repository	write_rows
classes/local/wbagent/embeddings_csv_repository.php:162	bookingextension_agent\local\wbagent\embeddings_csv_repository	headers_match
classes/local/wbagent/embeddings_csv_repository.php:181	bookingextension_agent\local\wbagent\embeddings_csv_repository	get_default_file_permissions
classes/local/wbagent/embeddings_readiness_service.php:43	bookingextension_agent\local\wbagent\embeddings_readiness_service	is_wunderbyte_embeddings_available
classes/local/wbagent/embeddings_readiness_service.php:55	bookingextension_agent\local\wbagent\embeddings_readiness_service	get_catalog_status
classes/local/wbagent/embeddings_readiness_service.php:113	bookingextension_agent\local\wbagent\embeddings_readiness_service	ensure_rebuild_scheduled_if_needed
classes/local/wbagent/embeddings_retrieval_service.php:41	bookingextension_agent\local\wbagent\embeddings_retrieval_service	search_top_k
classes/local/wbagent/embeddings_retrieval_service.php:75	bookingextension_agent\local\wbagent\embeddings_retrieval_service	build_planner_catalog_subset
classes/local/wbagent/embeddings_retrieval_service.php:134	bookingextension_agent\local\wbagent\embeddings_retrieval_service	build_live_contract_lookup
classes/local/wbagent/embeddings_retrieval_service.php:190	bookingextension_agent\local\wbagent\embeddings_retrieval_service	compact_properties_for_planner
classes/local/wbagent/embeddings_retrieval_service.php:227	bookingextension_agent\local\wbagent\embeddings_retrieval_service	cosine_similarity
classes/local/wbagent/embeddings_retrieval_service.php:258	bookingextension_agent\local\wbagent\embeddings_retrieval_service	decode_json_array
classes/local/wbagent/execution_feedback_service.php:57	bookingextension_agent\local\wbagent\execution_feedback_service	__construct
classes/local/wbagent/execution_feedback_service.php:76	bookingextension_agent\local\wbagent\execution_feedback_service	build_completion_feedback
classes/local/wbagent/execution_feedback_service.php:137	bookingextension_agent\local\wbagent\execution_feedback_service	should_apply_polish_step
classes/local/wbagent/execution_feedback_service.php:171	bookingextension_agent\local\wbagent\execution_feedback_service	generate_llm_feedback
classes/local/wbagent/execution_feedback_service.php:257	bookingextension_agent\local\wbagent\execution_feedback_service	generate_llm_follow_up_suggestions
classes/local/wbagent/execution_feedback_service.php:352	bookingextension_agent\local\wbagent\execution_feedback_service	build_follow_up_prompt
classes/local/wbagent/execution_feedback_service.php:399	bookingextension_agent\local\wbagent\execution_feedback_service	parse_follow_up_suggestions_json
classes/local/wbagent/execution_feedback_service.php:478	bookingextension_agent\local\wbagent\execution_feedback_service	get_follow_up_suggestions_limit
classes/local/wbagent/execution_feedback_service.php:493	bookingextension_agent\local\wbagent\execution_feedback_service	extract_latest_user_message
classes/local/wbagent/execution_feedback_service.php:513	bookingextension_agent\local\wbagent\execution_feedback_service	build_feedback_prompt
classes/local/wbagent/execution_feedback_service.php:570	bookingextension_agent\local\wbagent\execution_feedback_service	extract_message_from_feedback_response
classes/local/wbagent/execution_feedback_service.php:604	bookingextension_agent\local\wbagent\execution_feedback_service	build_execution_feedback_debug_source
classes/local/wbagent/execution_feedback_service.php:644	bookingextension_agent\local\wbagent\execution_feedback_service	sanitize_results_for_client
classes/local/wbagent/execution_feedback_service.php:811	bookingextension_agent\local\wbagent\execution_feedback_service	sanitize_result_detail
classes/local/wbagent/execution_feedback_service.php:900	bookingextension_agent\local\wbagent\execution_feedback_service	fallback_message_for_results
classes/local/wbagent/execution_feedback_service.php:960	bookingextension_agent\local\wbagent\execution_feedback_service	extract_primary_link_from_result
classes/local/wbagent/execution_feedback_service.php:980	bookingextension_agent\local\wbagent\execution_feedback_service	extract_primary_link_from_results
classes/local/wbagent/execution_feedback_service.php:1003	bookingextension_agent\local\wbagent\execution_feedback_service	localized
classes/local/wbagent/execution_feedback_service.php:1017	bookingextension_agent\local\wbagent\execution_feedback_service	localized_list_count_message
classes/local/wbagent/execution_feedback_service.php:1040	bookingextension_agent\local\wbagent\execution_feedback_service	append_link_to_message
classes/local/wbagent/executor.php:78	bookingextension_agent\local\wbagent\executor	__construct
classes/local/wbagent/executor.php:102	bookingextension_agent\local\wbagent\executor	execute_commands
classes/local/wbagent/executor.php:263	bookingextension_agent\local\wbagent\executor	execute_spawn_chain
classes/local/wbagent/executor.php:443	bookingextension_agent\local\wbagent\executor	build_safe_executed_input
classes/local/wbagent/executor.php:475	bookingextension_agent\local\wbagent\executor	enrich_result_with_follow_ups
classes/local/wbagent/executor.php:516	bookingextension_agent\local\wbagent\executor	build_follow_up_suggestions
classes/local/wbagent/executor.php:554	bookingextension_agent\local\wbagent\executor	get_follow_up_suggestions_limit
classes/local/wbagent/executor.php:573	bookingextension_agent\local\wbagent\executor	append_result_driven_suggestions
classes/local/wbagent/executor.php:615	bookingextension_agent\local\wbagent\executor	append_suggestion
classes/local/wbagent/executor.php:644	bookingextension_agent\local\wbagent\executor	get_first_row_field
classes/local/wbagent/executor.php:666	bookingextension_agent\local\wbagent\executor	get_follow_up_candidate_tasks
classes/local/wbagent/executor.php:698	bookingextension_agent\local\wbagent\executor	task_follow_up_score
classes/local/wbagent/executor.php:726	bookingextension_agent\local\wbagent\executor	task_namespace_prefix
classes/local/wbagent/executor.php:738	bookingextension_agent\local\wbagent\executor	get_task_label
classes/local/wbagent/executor.php:757	bookingextension_agent\local\wbagent\executor	truncate_label
classes/local/wbagent/interfaces/agent_authorization_service.php:48	bookingextension_agent\local\wbagent\interfaces\agent_authorization_service	require_use_capability
classes/local/wbagent/interfaces/agent_authorization_service.php:57	bookingextension_agent\local\wbagent\interfaces\agent_authorization_service	can_use
classes/local/wbagent/interfaces/agent_authorization_service.php:67	bookingextension_agent\local\wbagent\interfaces\agent_authorization_service	require_valid_context
classes/local/wbagent/interfaces/agent_conversation_store.php:43	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_or_create_thread
classes/local/wbagent/interfaces/agent_conversation_store.php:54	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	add_message
classes/local/wbagent/interfaces/agent_conversation_store.php:62	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_messages
classes/local/wbagent/interfaces/agent_conversation_store.php:71	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_recent_messages
classes/local/wbagent/interfaces/agent_conversation_store.php:80	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_last_thread_for_user
classes/local/wbagent/interfaces/agent_conversation_store.php:91	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_user_threads_by_date_window
classes/local/wbagent/interfaces/agent_conversation_store.php:108	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_user_messages_for_thread
classes/local/wbagent/interfaces/agent_conversation_store.php:126	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	create_run
classes/local/wbagent/interfaces/agent_conversation_store.php:136	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	update_run_status
classes/local/wbagent/interfaces/agent_conversation_store.php:144	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_run
classes/local/wbagent/interfaces/agent_conversation_store.php:152	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	get_latest_run
classes/local/wbagent/interfaces/agent_conversation_store.php:160	bookingextension_agent\local\wbagent\interfaces\agent_conversation_store	run_exists
classes/local/wbagent/interfaces/agent_executor.php:53	bookingextension_agent\local\wbagent\interfaces\agent_executor	execute_commands
classes/local/wbagent/interfaces/agent_interpreter.php:60	bookingextension_agent\local\wbagent\interfaces\agent_interpreter	interpret
classes/local/wbagent/interfaces/issue_code_provider_interface.php:38	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_duplicate_confirmation_issue_codes
classes/local/wbagent/interfaces/issue_code_provider_interface.php:49	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_token_subscription_issue_codes
classes/local/wbagent/interfaces/issue_code_provider_interface.php:61	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_prevalidation_confirmable_issue_codes
classes/local/wbagent/interfaces/issue_code_provider_interface.php:68	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_basic_subscription_url
classes/local/wbagent/interfaces/issue_code_provider_interface.php:75	bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface	get_premium_subscription_url
classes/local/wbagent/interfaces/preview_option_memory_interface.php:35	bookingextension_agent\local\wbagent\interfaces\preview_option_memory_interface	remember_last_preview_options_for_execute
classes/local/wbagent/interfaces/preview_option_memory_interface.php:44	bookingextension_agent\local\wbagent\interfaces\preview_option_memory_interface	resolve_last_preview_option_ids_for_execute
classes/local/wbagent/interfaces/preview_option_memory_provider_interface.php:32	bookingextension_agent\local\wbagent\interfaces\preview_option_memory_provider_interface	get_preview_option_memory
classes/local/wbagent/interfaces/queue_identity_provider_interface.php:36	bookingextension_agent\local\wbagent\interfaces\queue_identity_provider_interface	build_queue_business_identity
classes/local/wbagent/interfaces/result_summary_provider_interface.php:35	bookingextension_agent\local\wbagent\interfaces\result_summary_provider_interface	get_result_summary_contributors
classes/local/wbagent/interfaces/summarizer/result_summary_contributor_interface.php:40	bookingextension_agent\local\wbagent\interfaces\summarizer\result_summary_contributor_interface	supports
classes/local/wbagent/interfaces/summarizer/result_summary_contributor_interface.php:51	bookingextension_agent\local\wbagent\interfaces\summarizer\result_summary_contributor_interface	summarize
classes/local/wbagent/interfaces/task_input_normalizer_interface.php:34	bookingextension_agent\local\wbagent\interfaces\task_input_normalizer_interface	normalize
classes/local/wbagent/interfaces/task_input_normalizer_provider_interface.php:32	bookingextension_agent\local\wbagent\interfaces\task_input_normalizer_provider_interface	get_task_input_normalizer
classes/local/wbagent/interfaces/task_interface.php:46	bookingextension_agent\local\wbagent\interfaces\task_interface	get_name
classes/local/wbagent/interfaces/task_interface.php:53	bookingextension_agent\local\wbagent\interfaces\task_interface	get_schema
classes/local/wbagent/interfaces/task_interface.php:63	bookingextension_agent\local\wbagent\interfaces\task_interface	get_example_input
classes/local/wbagent/interfaces/task_interface.php:70	bookingextension_agent\local\wbagent\interfaces\task_interface	get_prompt_contract
classes/local/wbagent/interfaces/task_interface.php:82	bookingextension_agent\local\wbagent\interfaces\task_interface	check_structure
classes/local/wbagent/interfaces/task_interface.php:96	bookingextension_agent\local\wbagent\interfaces\task_interface	preflight
classes/local/wbagent/interfaces/task_interface.php:110	bookingextension_agent\local\wbagent\interfaces\task_interface	execute
classes/local/wbagent/interfaces/task_interface.php:117	bookingextension_agent\local\wbagent\interfaces\task_interface	is_read_only
classes/local/wbagent/interfaces/task_provider_interface.php:32	bookingextension_agent\local\wbagent\interfaces\task_provider_interface	get_component
classes/local/wbagent/interfaces/task_provider_interface.php:39	bookingextension_agent\local\wbagent\interfaces\task_provider_interface	get_tasks
classes/local/wbagent/interfaces/task_provider_interface.php:46	bookingextension_agent\local\wbagent\interfaces\task_provider_interface	get_contextual_prompt_packs
classes/local/wbagent/interfaces/task_provider_interface.php:56	bookingextension_agent\local\wbagent\interfaces\task_provider_interface	get_issue_code_provider
classes/local/wbagent/interfaces/task_provider_interface.php:66	bookingextension_agent\local\wbagent\interfaces\task_provider_interface	get_prompt_guidance
classes/local/wbagent/interfaces/task_result_summary_provider_interface.php:39	bookingextension_agent\local\wbagent\interfaces\task_result_summary_provider_interface	summarize_task_result
classes/local/wbagent/interfaces/task_trigger_provider_interface.php:36	bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface	get_message_triggers
classes/local/wbagent/interpreter.php:75	bookingextension_agent\local\wbagent\interpreter	__construct
classes/local/wbagent/interpreter.php:87	bookingextension_agent\local\wbagent\interpreter	interpret
classes/local/wbagent/interpreter.php:314	bookingextension_agent\local\wbagent\interpreter	normalize_commands_payload
classes/local/wbagent/interpreter.php:379	bookingextension_agent\local\wbagent\interpreter	extract_flat_command_input
classes/local/wbagent/interpreter.php:394	bookingextension_agent\local\wbagent\interpreter	prune_empty_input_values
classes/local/wbagent/interpreter.php:426	bookingextension_agent\local\wbagent\interpreter	with_optional_next_step_intent
classes/local/wbagent/interpreter.php:441	bookingextension_agent\local\wbagent\interpreter	looks_like_completed_action_intent
classes/local/wbagent/interpreter.php:473	bookingextension_agent\local\wbagent\interpreter	normalize_task_like_response
classes/local/wbagent/interpreter.php:583	bookingextension_agent\local\wbagent\interpreter	resolve_task_name_alias
classes/local/wbagent/interpreter.php:610	bookingextension_agent\local\wbagent\interpreter	hydrate_question_field
classes/local/wbagent/interpreter.php:635	bookingextension_agent\local\wbagent\interpreter	extract_command_input
classes/local/wbagent/interpreter.php:648	bookingextension_agent\local\wbagent\interpreter	parse
classes/local/wbagent/interpreter.php:670	bookingextension_agent\local\wbagent\interpreter	sanitize_json_payload
classes/local/wbagent/interpreter.php:705	bookingextension_agent\local\wbagent\interpreter	truncate_parse_excerpt
classes/local/wbagent/interpreter.php:724	bookingextension_agent\local\wbagent\interpreter	extract_used_triggers
classes/local/wbagent/interpreter.php:744	bookingextension_agent\local\wbagent\interpreter	validate_commands
classes/local/wbagent/interpreter.php:867	bookingextension_agent\local\wbagent\interpreter	normalize_ambiguity_options
classes/local/wbagent/interpreter.php:905	bookingextension_agent\local\wbagent\interpreter	normalize_self_user_references
classes/local/wbagent/interpreter.php:946	bookingextension_agent\local\wbagent\interpreter	canonicalize_command_input
classes/local/wbagent/interpreter.php:980	bookingextension_agent\local\wbagent\interpreter	normalize_timestamp_value
classes/local/wbagent/interpreter.php:1033	bookingextension_agent\local\wbagent\interpreter	error_result
classes/local/wbagent/interpreter.php:1047	bookingextension_agent\local\wbagent\interpreter	error_result_with_issue_code
classes/local/wbagent/interpreter.php:1068	bookingextension_agent\local\wbagent\interpreter	safe_string
classes/local/wbagent/interpreter.php:1081	bookingextension_agent\local\wbagent\interpreter	clarification_message
classes/local/wbagent/interpreter.php:1109	bookingextension_agent\local\wbagent\interpreter	confirmation_message_from_ambiguities
classes/local/wbagent/interpreter.php:1127	bookingextension_agent\local\wbagent\interpreter	user_facing_validation_message
classes/local/wbagent/interpreter.php:1186	bookingextension_agent\local\wbagent\interpreter	strip_command_prefix
classes/local/wbagent/llm_call_service.php:57	bookingextension_agent\local\wbagent\llm_call_service	__construct
classes/local/wbagent/llm_call_service.php:72	bookingextension_agent\local\wbagent\llm_call_service	invoke
classes/local/wbagent/llm_call_service.php:137	bookingextension_agent\local\wbagent\llm_call_service	invoke_embeddings
classes/local/wbagent/llm_call_service.php:218	bookingextension_agent\local\wbagent\llm_call_service	build_prompt_action
classes/local/wbagent/llm_call_service.php:257	bookingextension_agent\local\wbagent\llm_call_service	resolve_wunderbyte_prompt_action_class
classes/local/wbagent/llm_debug_logger.php:38	bookingextension_agent\local\wbagent\llm_debug_logger	is_enabled
classes/local/wbagent/llm_debug_logger.php:59	bookingextension_agent\local\wbagent\llm_debug_logger	log_exchange
classes/local/wbagent/llm_debug_logger.php:101	bookingextension_agent\local\wbagent\llm_debug_logger	log_exchange_always
classes/local/wbagent/loop_finalizer.php:46	bookingextension_agent\local\wbagent\loop_finalizer	finalize
classes/local/wbagent/loop_finalizer.php:76	bookingextension_agent\local\wbagent\loop_finalizer	should_finalize_after_execution_result
classes/local/wbagent/loop_finalizer.php:131	bookingextension_agent\local\wbagent\loop_finalizer	build_sufficient_execution_result_clarification
classes/local/wbagent/loop_finalizer.php:185	bookingextension_agent\local\wbagent\loop_finalizer	maybe_enrich_message_from_results
classes/local/wbagent/loop_finalizer.php:226	bookingextension_agent\local\wbagent\loop_finalizer	is_low_information_message
classes/local/wbagent/message_persistence_service.php:41	bookingextension_agent\local\wbagent\message_persistence_service	__construct
classes/local/wbagent/message_persistence_service.php:52	bookingextension_agent\local\wbagent\message_persistence_service	persist_assistant_message
classes/local/wbagent/message_trigger_registry.php:84	bookingextension_agent\local\wbagent\message_trigger_registry	__construct
classes/local/wbagent/message_trigger_registry.php:93	bookingextension_agent\local\wbagent\message_trigger_registry	get_available_triggers
classes/local/wbagent/message_trigger_registry.php:125	bookingextension_agent\local\wbagent\message_trigger_registry	get_available_trigger_ids
classes/local/wbagent/message_trigger_registry.php:136	bookingextension_agent\local\wbagent\message_trigger_registry	normalize_used_triggers
classes/local/wbagent/message_trigger_registry.php:164	bookingextension_agent\local\wbagent\message_trigger_registry	normalize_response_type
classes/local/wbagent/orchestrator.php:103	bookingextension_agent\local\wbagent\orchestrator	__construct
classes/local/wbagent/orchestrator.php:120	bookingextension_agent\local\wbagent\orchestrator	is_provider_available
classes/local/wbagent/orchestrator.php:134	bookingextension_agent\local\wbagent\orchestrator	get_runtime_provider_status
classes/local/wbagent/orchestrator.php:269	bookingextension_agent\local\wbagent\orchestrator	process
classes/local/wbagent/orchestrator.php:541	bookingextension_agent\local\wbagent\orchestrator	get_default_initial_prompt_template
classes/local/wbagent/orchestrator.php:561	bookingextension_agent\local\wbagent\orchestrator	get_default_initial_prompt_template_for_action
classes/local/wbagent/orchestrator.php:669	bookingextension_agent\local\wbagent\orchestrator	get_default_summary_prompt_prefix
classes/local/wbagent/orchestrator.php:678	bookingextension_agent\local\wbagent\orchestrator	get_default_initial_prompt_template_path
classes/local/wbagent/orchestrator.php:695	bookingextension_agent\local\wbagent\orchestrator	build_system_prompt
classes/local/wbagent/orchestrator.php:783	bookingextension_agent\local\wbagent\orchestrator	slim_prompt_catalog_for_planner
classes/local/wbagent/orchestrator.php:822	bookingextension_agent\local\wbagent\orchestrator	compact_catalog_description
classes/local/wbagent/orchestrator.php:844	bookingextension_agent\local\wbagent\orchestrator	compact_catalog_example_input
classes/local/wbagent/orchestrator.php:870	bookingextension_agent\local\wbagent\orchestrator	compact_catalog_message_triggers
classes/local/wbagent/orchestrator.php:913	bookingextension_agent\local\wbagent\orchestrator	extract_recent_task_names_from_messages
classes/local/wbagent/orchestrator.php:949	bookingextension_agent\local\wbagent\orchestrator	is_first_assistant_turn
classes/local/wbagent/orchestrator.php:975	bookingextension_agent\local\wbagent\orchestrator	build_prompt
classes/local/wbagent/orchestrator.php:1039	(global)	build_local_output_contract_block
classes/local/wbagent/orchestrator.php:1071	(global)	normalize_planner_trace_history
classes/local/wbagent/orchestrator.php:1106	(global)	append_planner_traces_and_observations
classes/local/wbagent/orchestrator.php:1140	(global)	build_runtime_context_block
classes/local/wbagent/orchestrator.php:1209	(global)	append_json_object_section
classes/local/wbagent/orchestrator.php:1228	(global)	append_json_list_section
classes/local/wbagent/orchestrator.php:1251	(global)	json_encode_or_empty
classes/local/wbagent/orchestrator.php:1268	(global)	build_unavailable_task_catalog_for_runtime
classes/local/wbagent/orchestrator.php:1316	(global)	availability_from_deny_reason
classes/local/wbagent/orchestrator.php:1338	(global)	sanitize_unavailable_task_catalog
classes/local/wbagent/orchestrator.php:1350	(global)	build_task_description_index
classes/local/wbagent/orchestrator.php:1375	(global)	extract_completed_commands_from_messages
classes/local/wbagent/orchestrator.php:1456	(global)	merge_completed_commands_from_queue
classes/local/wbagent/orchestrator.php:1527	(global)	build_completed_command_signature
classes/local/wbagent/orchestrator.php:1554	(global)	normalize_completed_command_input
classes/local/wbagent/orchestrator.php:1587	(global)	normalize_completed_command_value
classes/local/wbagent/orchestrator.php:1633	(global)	observations_are_framework_retry_hints
classes/local/wbagent/orchestrator.php:1657	(global)	normalize_step_type
classes/local/wbagent/orchestrator.php:1677	(global)	get_initial_prompt_config_key
classes/local/wbagent/orchestrator.php:1696	(global)	get_action_initial_prompt_config_key
classes/local/wbagent/orchestrator.php:1724	(global)	get_history_limit_for_step
classes/local/wbagent/orchestrator.php:1735	(global)	normalize_config_prompt_template
classes/local/wbagent/orchestrator.php:1754	(global)	resolve_action_class_for_step
classes/local/wbagent/orchestrator.php:1825	(global)	should_use_openai_step_routing
classes/local/wbagent/orchestrator.php:1845	(global)	is_wunderbyte_routing_available
classes/local/wbagent/orchestrator.php:1890	(global)	build_orchestrator_debug_source
classes/local/wbagent/orchestrator.php:1955	(global)	short_debug_token
classes/local/wbagent/orchestrator.php:1976	(global)	is_action_available_in_context
classes/local/wbagent/orchestrator.php:1992	(global)	build_assistant_state_blocks
classes/local/wbagent/orchestrator.php:2024	(global)	summarize_structured_state
classes/local/wbagent/orchestrator.php:2064	(global)	extract_result_facts
classes/local/wbagent/orchestrator.php:2123	(global)	normalize_nonempty_string_list
classes/local/wbagent/orchestrator.php:2149	(global)	build_contextual_guidance
classes/local/wbagent/orchestrator.php:2192	(global)	matches_contextual_pack
classes/local/wbagent/planner_service.php:49	bookingextension_agent\local\wbagent\planner_service	__construct
classes/local/wbagent/planner_service.php:65	bookingextension_agent\local\wbagent\planner_service	enrich_recovery_input
classes/local/wbagent/planner_service.php:162	bookingextension_agent\local\wbagent\planner_service	build_enrichment_cache_key
classes/local/wbagent/planner_service.php:193	bookingextension_agent\local\wbagent\planner_service	is_docs_retrieval_schema
classes/local/wbagent/planner_service.php:207	bookingextension_agent\local\wbagent\planner_service	build_docs_index_lines
classes/local/wbagent/planner_service.php:274	bookingextension_agent\local\wbagent\planner_service	build_planner_prompt
classes/local/wbagent/planner_service.php:339	bookingextension_agent\local\wbagent\planner_service	extract_search_terms
classes/local/wbagent/planner_service.php:367	bookingextension_agent\local\wbagent\planner_service	extract_planner_payload
classes/local/wbagent/planner_service.php:404	bookingextension_agent\local\wbagent\planner_service	merge_input_patch
classes/local/wbagent/planner_service.php:520	bookingextension_agent\local\wbagent\planner_service	is_input_value_empty
classes/local/wbagent/planner_service.php:541	bookingextension_agent\local\wbagent\planner_service	create_docs_lookup_service
classes/local/wbagent/planner_service.php:554	bookingextension_agent\local\wbagent\planner_service	build_planner_debug_source
classes/local/wbagent/preview_policy.php:55	bookingextension_agent\local\wbagent\preview_policy	supports_preview
classes/local/wbagent/preview_policy.php:66	bookingextension_agent\local\wbagent\preview_policy	filter_previewable_commands
classes/local/wbagent/preview_policy.php:79	bookingextension_agent\local\wbagent\preview_policy	has_previewable_command
classes/local/wbagent/privacy_anonymizer.php:73	bookingextension_agent\local\wbagent\privacy_anonymizer	__construct
classes/local/wbagent/privacy_anonymizer.php:82	bookingextension_agent\local\wbagent\privacy_anonymizer	get_mode
classes/local/wbagent/privacy_anonymizer.php:100	bookingextension_agent\local\wbagent\privacy_anonymizer	looks_like_anon_token
classes/local/wbagent/privacy_anonymizer.php:109	bookingextension_agent\local\wbagent\privacy_anonymizer	should_anonymize_user_input
classes/local/wbagent/privacy_anonymizer.php:118	bookingextension_agent\local\wbagent\privacy_anonymizer	should_anonymize_llm_backend_data
classes/local/wbagent/privacy_anonymizer.php:129	bookingextension_agent\local\wbagent\privacy_anonymizer	precheck_user_message
classes/local/wbagent/privacy_anonymizer.php:174	bookingextension_agent\local\wbagent\privacy_anonymizer	deanonymize_command_input
classes/local/wbagent/privacy_anonymizer.php:197	bookingextension_agent\local\wbagent\privacy_anonymizer	deanonymize_command_input_for_active_user
classes/local/wbagent/privacy_anonymizer.php:217	bookingextension_agent\local\wbagent\privacy_anonymizer	deanonymize_message_for_display
classes/local/wbagent/privacy_anonymizer.php:281	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_value_for_llm
classes/local/wbagent/privacy_anonymizer.php:301	bookingextension_agent\local\wbagent\privacy_anonymizer	deanonymize_recursive
classes/local/wbagent/privacy_anonymizer.php:335	bookingextension_agent\local\wbagent\privacy_anonymizer	resolve_token_entry
classes/local/wbagent/privacy_anonymizer.php:363	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_value_recursive
classes/local/wbagent/privacy_anonymizer.php:391	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_string_for_llm
classes/local/wbagent/privacy_anonymizer.php:424	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_labeled_user_fields
classes/local/wbagent/privacy_anonymizer.php:479	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_person_field_value
classes/local/wbagent/privacy_anonymizer.php:537	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_emails
classes/local/wbagent/privacy_anonymizer.php:571	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_names
classes/local/wbagent/privacy_anonymizer.php:733	bookingextension_agent\local\wbagent\privacy_anonymizer	find_email_spans
classes/local/wbagent/privacy_anonymizer.php:766	bookingextension_agent\local\wbagent\privacy_anonymizer	offset_overlaps_email_span
classes/local/wbagent/privacy_anonymizer.php:783	bookingextension_agent\local\wbagent\privacy_anonymizer	get_user_name_match_index
classes/local/wbagent/privacy_anonymizer.php:857	bookingextension_agent\local\wbagent\privacy_anonymizer	user_sets_intersect
classes/local/wbagent/privacy_anonymizer.php:876	bookingextension_agent\local\wbagent\privacy_anonymizer	get_distinct_name_index
classes/local/wbagent/privacy_anonymizer.php:922	bookingextension_agent\local\wbagent\privacy_anonymizer	normalize_name
classes/local/wbagent/privacy_anonymizer.php:940	bookingextension_agent\local\wbagent\privacy_anonymizer	get_token_map
classes/local/wbagent/privacy_anonymizer.php:965	bookingextension_agent\local\wbagent\privacy_anonymizer	set_token_map
classes/local/wbagent/privacy_anonymizer.php:978	bookingextension_agent\local\wbagent\privacy_anonymizer	get_or_create_token
classes/local/wbagent/privacy_anonymizer.php:1081	bookingextension_agent\local\wbagent\privacy_anonymizer	scope_identity_key_for_type
classes/local/wbagent/privacy_anonymizer.php:1096	bookingextension_agent\local\wbagent\privacy_anonymizer	build_field_token_from_base
classes/local/wbagent/privacy_anonymizer.php:1116	bookingextension_agent\local\wbagent\privacy_anonymizer	extract_base_token_from_anon_token
classes/local/wbagent/privacy_anonymizer.php:1134	bookingextension_agent\local\wbagent\privacy_anonymizer	resolve_entry_for_field
classes/local/wbagent/privacy_anonymizer.php:1170	bookingextension_agent\local\wbagent\privacy_anonymizer	resolve_identity_from_email
classes/local/wbagent/privacy_anonymizer.php:1209	bookingextension_agent\local\wbagent\privacy_anonymizer	resolve_identity_from_user_ids
classes/local/wbagent/privacy_anonymizer.php:1234	bookingextension_agent\local\wbagent\privacy_anonymizer	load_user_identity_record
classes/local/wbagent/privacy_anonymizer.php:1252	bookingextension_agent\local\wbagent\privacy_anonymizer	build_identity_variants_from_user_record
classes/local/wbagent/privacy_anonymizer.php:1282	bookingextension_agent\local\wbagent\privacy_anonymizer	merge_identity_variants
classes/local/wbagent/privacy_anonymizer.php:1303	bookingextension_agent\local\wbagent\privacy_anonymizer	array_contains_person_identity_fields
classes/local/wbagent/privacy_anonymizer.php:1320	bookingextension_agent\local\wbagent\privacy_anonymizer	anonymize_person_identity_field_group
classes/local/wbagent/privacy_anonymizer.php:1373	bookingextension_agent\local\wbagent\privacy_anonymizer	is_user_reference_field
classes/local/wbagent/prompt_policy_builder.php:51	bookingextension_agent\local\wbagent\prompt_policy_builder	build_all_policies
classes/local/wbagent/prompt_policy_builder.php:99	bookingextension_agent\local\wbagent\prompt_policy_builder	build_response_contract_policy
classes/local/wbagent/prompt_policy_builder.php:139	bookingextension_agent\local\wbagent\prompt_policy_builder	build_trigger_policy
classes/local/wbagent/prompt_policy_builder.php:158	bookingextension_agent\local\wbagent\prompt_policy_builder	build_trigger_policy_compact
classes/local/wbagent/prompt_policy_builder.php:174	bookingextension_agent\local\wbagent\prompt_policy_builder	build_routing_determinism_policy
classes/local/wbagent/prompt_policy_builder.php:200	bookingextension_agent\local\wbagent\prompt_policy_builder	build_step_intent_policy
classes/local/wbagent/prompt_policy_builder.php:229	bookingextension_agent\local\wbagent\prompt_policy_builder	is_planner_step_type
classes/local/wbagent/prompt_policy_builder.php:240	bookingextension_agent\local\wbagent\prompt_policy_builder	build_docs_answer_policy
classes/local/wbagent/prompt_policy_builder.php:260	bookingextension_agent\local\wbagent\prompt_policy_builder	build_sufficiency_policy
classes/local/wbagent/prompt_policy_builder.php:325	bookingextension_agent\local\wbagent\prompt_policy_builder	build_follow_up_state_policy
classes/local/wbagent/queue/observation_builder.php:39	bookingextension_agent\local\wbagent\queue\observation_builder	build_observation
classes/local/wbagent/queue/queue_manager.php:69	bookingextension_agent\local\wbagent\queue\queue_manager	__construct
classes/local/wbagent/queue/queue_manager.php:86	bookingextension_agent\local\wbagent\queue\queue_manager	enqueue_command
classes/local/wbagent/queue/queue_manager.php:208	bookingextension_agent\local\wbagent\queue\queue_manager	update_status
classes/local/wbagent/queue/queue_manager.php:257	bookingextension_agent\local\wbagent\queue\queue_manager	get_queue_items
classes/local/wbagent/queue/queue_manager.php:269	bookingextension_agent\local\wbagent\queue\queue_manager	get_queue_item
classes/local/wbagent/queue/queue_manager.php:291	bookingextension_agent\local\wbagent\queue\queue_manager	save_queue_items
classes/local/wbagent/queue/queue_manager.php:304	bookingextension_agent\local\wbagent\queue\queue_manager	set_prepared_input
classes/local/wbagent/queue/queue_manager.php:331	bookingextension_agent\local\wbagent\queue\queue_manager	has_running_item
classes/local/wbagent/queue/queue_manager.php:357	bookingextension_agent\local\wbagent\queue\queue_manager	try_mark_running
classes/local/wbagent/queue/queue_manager.php:440	(global)	can_pickup_now
classes/local/wbagent/queue/queue_manager.php:471	(global)	dependencies_succeeded
classes/local/wbagent/queue/queue_manager.php:482	(global)	dependencies_succeeded_from_items
classes/local/wbagent/queue/queue_manager.php:527	(global)	validate_depends_on_is_dag
classes/local/wbagent/queue/queue_manager.php:562	(global)	fail_expired_blocked_items
classes/local/wbagent/queue/queue_manager.php:599	(global)	build_input_signature
classes/local/wbagent/queue/queue_manager.php:611	(global)	build_input_signature_details
classes/local/wbagent/queue/queue_manager.php:650	(global)	normalize_for_signature
classes/local/wbagent/queue/queue_manager.php:673	(global)	next_sequence
classes/local/wbagent/queue/queue_manager.php:686	(global)	resolve_thread_contextid
classes/local/wbagent/queue/queue_manager.php:702	(global)	resolve_blocked_expires_at
classes/local/wbagent/queue/queue_manager.php:724	(global)	dfs_cycle_detect
classes/local/wbagent/result_payload_summarizer.php:65	bookingextension_agent\local\wbagent\result_payload_summarizer	for_observation
classes/local/wbagent/result_payload_summarizer.php:127	(global)	describe_result_for_state
classes/local/wbagent/result_payload_summarizer.php:149	(global)	detect_result_category
classes/local/wbagent/result_payload_summarizer.php:191	(global)	describe_entry
classes/local/wbagent/result_payload_summarizer.php:367	(global)	compact_text
classes/local/wbagent/result_payload_summarizer.php:389	(global)	summarize_with_contributors
classes/local/wbagent/result_payload_summarizer.php:415	(global)	build_summary_context
classes/local/wbagent/result_payload_summarizer.php:441	(global)	summarize_with_task_provider
classes/local/wbagent/services/confirm_run_service.php:71	bookingextension_agent\local\wbagent\services\confirm_run_service	__construct
classes/local/wbagent/services/confirm_run_service.php:90	bookingextension_agent\local\wbagent\services\confirm_run_service	confirm
classes/local/wbagent/services/confirm_run_service.php:648	bookingextension_agent\local\wbagent\services\confirm_run_service	build_error_payload
classes/local/wbagent/services/confirm_run_service.php:686	bookingextension_agent\local\wbagent\services\confirm_run_service	resolve_preview_option_ids_for_response
classes/local/wbagent/services/confirm_run_service.php:728	bookingextension_agent\local\wbagent\services\confirm_run_service	first_preview_option_id
classes/local/wbagent/services/confirm_run_service.php:748	bookingextension_agent\local\wbagent\services\confirm_run_service	remember_confirm_preview_option_ids
classes/local/wbagent/services/confirm_run_service.php:770	bookingextension_agent\local\wbagent\services\confirm_run_service	resolve_confirm_preview_option_ids_for_response
classes/local/wbagent/services/confirm_run_service.php:801	bookingextension_agent\local\wbagent\services\confirm_run_service	has_successful_execution_results
classes/local/wbagent/services/confirm_run_service.php:822	bookingextension_agent\local\wbagent\services\confirm_run_service	normalize_string_list
classes/local/wbagent/services/confirm_run_service.php:844	bookingextension_agent\local\wbagent\services\confirm_run_service	merge_preview_option_ids
classes/local/wbagent/services/confirm_run_service.php:872	bookingextension_agent\local\wbagent\services\confirm_run_service	build_retry_decision
classes/local/wbagent/services/confirm_run_service.php:917	bookingextension_agent\local\wbagent\services\confirm_run_service	build_queue_audit_context
classes/local/wbagent/services/confirm_run_service.php:941	bookingextension_agent\local\wbagent\services\confirm_run_service	should_continue_with_runtime_loop
classes/local/wbagent/services/confirm_run_service.php:964	bookingextension_agent\local\wbagent\services\confirm_run_service	find_next_mutating_queue_item
classes/local/wbagent/services/confirm_run_service.php:990	bookingextension_agent\local\wbagent\services\confirm_run_service	extract_attempted_tasks_from_commands
classes/local/wbagent/services/confirm_run_service.php:1015	bookingextension_agent\local\wbagent\services\confirm_run_service	resolve_pending_queue_item_id
classes/local/wbagent/services/confirm_run_service.php:1053	bookingextension_agent\local\wbagent\services\confirm_run_service	resolve_commands_for_run
classes/local/wbagent/services/confirm_run_service.php:1074	bookingextension_agent\local\wbagent\services\confirm_run_service	mark_dependents_skipped
classes/local/wbagent/services/confirm_run_service.php:1118	bookingextension_agent\local\wbagent\services\confirm_run_service	get_active_mutating_queue_item
classes/local/wbagent/services/confirm_run_service.php:1142	bookingextension_agent\local\wbagent\services\confirm_run_service	is_actionable_mutating_queue_item
classes/local/wbagent/services/execution_observation_ledger.php:50	bookingextension_agent\local\wbagent\services\execution_observation_ledger	__construct
classes/local/wbagent/services/execution_observation_ledger.php:62	bookingextension_agent\local\wbagent\services\execution_observation_ledger	append_from_results
classes/local/wbagent/services/execution_observation_ledger.php:158	bookingextension_agent\local\wbagent\services\execution_observation_ledger	get_recent_for_runtime
classes/local/wbagent/services/execution_observation_ledger.php:204	bookingextension_agent\local\wbagent\services\execution_observation_ledger	read_entries
classes/local/wbagent/services/execution_observation_ledger.php:219	bookingextension_agent\local\wbagent\services\execution_observation_ledger	normalize_input
classes/local/wbagent/services/execution_observation_ledger.php:247	bookingextension_agent\local\wbagent\services\execution_observation_ledger	normalize_value
classes/local/wbagent/services/execution_observation_ledger.php:269	bookingextension_agent\local\wbagent\services\execution_observation_ledger	build_signature
classes/local/wbagent/services/language_policy_service.php:47	bookingextension_agent\local\wbagent\services\language_policy_service	normalize_iso_language
classes/local/wbagent/services/language_policy_service.php:60	bookingextension_agent\local\wbagent\services\language_policy_service	resolve_output_language
classes/local/wbagent/services/language_policy_service.php:84	bookingextension_agent\local\wbagent\services\language_policy_service	fallback_string_id_for_response_type
classes/local/wbagent/services/language_policy_service.php:104	bookingextension_agent\local\wbagent\services\language_policy_service	preflight_retry_hint_string_id
classes/local/wbagent/services/localized_string_service.php:40	bookingextension_agent\local\wbagent\services\localized_string_service	get
classes/local/wbagent/services/lookup/docs_lookup_service.php:42	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	__construct
classes/local/wbagent/services/lookup/docs_lookup_service.php:55	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	get_root_doc_path
classes/local/wbagent/services/lookup/docs_lookup_service.php:66	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	read_root_doc
classes/local/wbagent/services/lookup/docs_lookup_service.php:77	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	search
classes/local/wbagent/services/lookup/docs_lookup_service.php:119	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	search_multi
classes/local/wbagent/services/lookup/docs_lookup_service.php:185	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	is_ambiguous
classes/local/wbagent/services/lookup/docs_lookup_service.php:212	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	get_ambiguity_candidates
classes/local/wbagent/services/lookup/docs_lookup_service.php:234	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	get_all_doc_index
classes/local/wbagent/services/lookup/docs_lookup_service.php:251	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	get_master_toc_index
classes/local/wbagent/services/lookup/docs_lookup_service.php:311	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	get_topic_doc_index
classes/local/wbagent/services/lookup/docs_lookup_service.php:339	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	render_master_toc_observation
classes/local/wbagent/services/lookup/docs_lookup_service.php:364	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	detect_best_topic
classes/local/wbagent/services/lookup/docs_lookup_service.php:434	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	search_in_topic
classes/local/wbagent/services/lookup/docs_lookup_service.php:464	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	load_docs_by_paths
classes/local/wbagent/services/lookup/docs_lookup_service.php:483	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	search_docs
classes/local/wbagent/services/lookup/docs_lookup_service.php:544	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	extract_topic_id_from_path
classes/local/wbagent/services/lookup/docs_lookup_service.php:561	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	build_topic_title
classes/local/wbagent/services/lookup/docs_lookup_service.php:576	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	extract_topic_terms
classes/local/wbagent/services/lookup/docs_lookup_service.php:595	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	score_topic
classes/local/wbagent/services/lookup/docs_lookup_service.php:645	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	read_doc_by_path
classes/local/wbagent/services/lookup/docs_lookup_service.php:712	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	build_summary
classes/local/wbagent/services/lookup/docs_lookup_service.php:738	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	load_docs
classes/local/wbagent/services/lookup/docs_lookup_service.php:788	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	score_doc
classes/local/wbagent/services/lookup/docs_lookup_service.php:844	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	has_exact_basename_hit
classes/local/wbagent/services/lookup/docs_lookup_service.php:867	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	extract_query_tokens
classes/local/wbagent/services/lookup/docs_lookup_service.php:889	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	extract_first_ordered_steps
classes/local/wbagent/services/lookup/docs_lookup_service.php:952	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	extract_title
classes/local/wbagent/services/lookup/docs_lookup_service.php:966	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	extract_excerpt
classes/local/wbagent/services/lookup/docs_lookup_service.php:1004	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	extract_markdown_links_from_text
classes/local/wbagent/services/lookup/docs_lookup_service.php:1049	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	resolve_relative_doc_link
classes/local/wbagent/services/lookup/docs_lookup_service.php:1090	bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service	strip_markdown
classes/local/wbagent/services/lookup/option_lookup_service.php:52	bookingextension_agent\local\wbagent\services\lookup\option_lookup_service	__construct
classes/local/wbagent/services/lookup/option_lookup_service.php:66	bookingextension_agent\local\wbagent\services\lookup\option_lookup_service	search_options
classes/local/wbagent/services/lookup/option_lookup_service.php:94	bookingextension_agent\local\wbagent\services\lookup\option_lookup_service	resolve_single_option
classes/local/wbagent/services/mutation/entity_mutation_service.php:50	bookingextension_agent\local\wbagent\services\mutation\entity_mutation_service	create_entity
classes/local/wbagent/services/mutation/entity_mutation_service.php:76	(global)	entity_exists_by_name
classes/local/wbagent/services/mutation/entity_mutation_service.php:90	(global)	entity_exists_by_shortname
classes/local/wbagent/services/mutation/option_mutation_service.php:52	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	validate_create
classes/local/wbagent/services/mutation/option_mutation_service.php:67	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	validate_update
classes/local/wbagent/services/mutation/option_mutation_service.php:82	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	validate_bulk_update
classes/local/wbagent/services/mutation/option_mutation_service.php:98	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	create_option
classes/local/wbagent/services/mutation/option_mutation_service.php:110	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	update_option
classes/local/wbagent/services/mutation/option_mutation_service.php:122	bookingextension_agent\local\wbagent\services\mutation\option_mutation_service	bulk_update_options
classes/local/wbagent/services/pending_intent_service.php:43	bookingextension_agent\local\wbagent\services\pending_intent_service	__construct
classes/local/wbagent/services/pending_intent_service.php:53	bookingextension_agent\local\wbagent\services\pending_intent_service	get
classes/local/wbagent/services/pending_intent_service.php:65	bookingextension_agent\local\wbagent\services\pending_intent_service	consume
classes/local/wbagent/services/pending_intent_service.php:75	bookingextension_agent\local\wbagent\services\pending_intent_service	clear
classes/local/wbagent/services/pending_intent_service.php:89	bookingextension_agent\local\wbagent\services\pending_intent_service	set
classes/local/wbagent/services/preflight_audit_logger.php:42	bookingextension_agent\local\wbagent\services\preflight_audit_logger	__construct
classes/local/wbagent/services/preflight_audit_logger.php:54	bookingextension_agent\local\wbagent\services\preflight_audit_logger	append
classes/local/wbagent/services/preflight_contract_validator.php:57	bookingextension_agent\local\wbagent\services\preflight_contract_validator	__construct
classes/local/wbagent/services/preflight_contract_validator.php:74	bookingextension_agent\local\wbagent\services\preflight_contract_validator	validate
classes/local/wbagent/services/preflight_domain_check_runner.php:39	bookingextension_agent\local\wbagent\services\preflight_domain_check_runner	run
classes/local/wbagent/services/preflight_error_classifier.php:40	bookingextension_agent\local\wbagent\services\preflight_error_classifier	infer_from_issue_codes
classes/local/wbagent/services/preflight_error_classifier.php:72	bookingextension_agent\local\wbagent\services\preflight_error_classifier	is_retryable_error_class
classes/local/wbagent/services/preflight_execution_gate.php:48	bookingextension_agent\local\wbagent\services\preflight_execution_gate	evaluate
classes/local/wbagent/services/preflight_execution_gate.php:91	bookingextension_agent\local\wbagent\services\preflight_execution_gate	build_guard_token
classes/local/wbagent/services/preflight_execution_gate.php:106	bookingextension_agent\local\wbagent\services\preflight_execution_gate	verify_guard_token
classes/local/wbagent/services/preflight_execution_gate.php:126	bookingextension_agent\local\wbagent\services\preflight_execution_gate	normalize_for_guard
classes/local/wbagent/services/preflight_pipeline.php:59	bookingextension_agent\local\wbagent\services\preflight_pipeline	__construct
classes/local/wbagent/services/preflight_pipeline.php:77	bookingextension_agent\local\wbagent\services\preflight_pipeline	run
classes/local/wbagent/services/preflight_pipeline.php:264	bookingextension_agent\local\wbagent\services\preflight_pipeline	build_output
classes/local/wbagent/services/preflight_pipeline.php:294	bookingextension_agent\local\wbagent\services\preflight_pipeline	build_audit_command_context
classes/local/wbagent/services/preflight_result_v2.php:74	bookingextension_agent\local\wbagent\services\preflight_result_v2	__construct
classes/local/wbagent/services/preflight_result_v2.php:106	bookingextension_agent\local\wbagent\services\preflight_result_v2	normalize_blocking_layer
classes/local/wbagent/services/preflight_result_v2.php:140	bookingextension_agent\local\wbagent\services\preflight_result_v2	to_array
classes/local/wbagent/services/preflight_result_v2.php:157	bookingextension_agent\local\wbagent\services\preflight_result_v2	ok
classes/local/wbagent/services/preflight_result_v2.php:168	bookingextension_agent\local\wbagent\services\preflight_result_v2	confirmable
classes/local/wbagent/services/preflight_result_v2.php:188	bookingextension_agent\local\wbagent\services\preflight_result_v2	invalid
classes/local/wbagent/services/preflight_result_v2.php:208	bookingextension_agent\local\wbagent\services\preflight_result_v2	extract_issue_codes_from_issues
classes/local/wbagent/services/preflight_schema_validator.php:38	bookingextension_agent\local\wbagent\services\preflight_schema_validator	validate
classes/local/wbagent/services/preflight_schema_validator.php:161	bookingextension_agent\local\wbagent\services\preflight_schema_validator	get_schema
classes/local/wbagent/services/preflight_version_validator.php:46	bookingextension_agent\local\wbagent\services\preflight_version_validator	__construct
classes/local/wbagent/services/preflight_version_validator.php:57	bookingextension_agent\local\wbagent\services\preflight_version_validator	validate
classes/local/wbagent/services/preflight_version_validator.php:126	bookingextension_agent\local\wbagent\services\preflight_version_validator	resolve_requested_version
classes/local/wbagent/services/provider_routing_util.php:41	bookingextension_agent\local\wbagent\services\provider_routing_util	resolve_primary_provider_for_action
classes/local/wbagent/services/provider_routing_util.php:61	bookingextension_agent\local\wbagent\services\provider_routing_util	short_provider_for_debug
classes/local/wbagent/services/queue_command_mapper.php:40	bookingextension_agent\local\wbagent\services\queue_command_mapper	from_queue_item
classes/local/wbagent/services/queue_command_mapper.php:78	bookingextension_agent\local\wbagent\services\queue_command_mapper	from_queue_items
classes/local/wbagent/services/queue_status_policy.php:44	bookingextension_agent\local\wbagent\services\queue_status_policy	actionable_mutating_statuses
classes/local/wbagent/services/queue_status_policy.php:53	bookingextension_agent\local\wbagent\services\queue_status_policy	pickup_ready_statuses
classes/local/wbagent/services/queue_status_policy.php:63	bookingextension_agent\local\wbagent\services\queue_status_policy	is_actionable_mutating_status
classes/local/wbagent/services/queue_status_policy.php:73	bookingextension_agent\local\wbagent\services\queue_status_policy	is_pickup_ready_status
classes/local/wbagent/services/queue_transition_service.php:48	bookingextension_agent\local\wbagent\services\queue_transition_service	to_status
classes/local/wbagent/services/queue_transition_service.php:70	bookingextension_agent\local\wbagent\services\queue_transition_service	to_ready
classes/local/wbagent/services/queue_transition_service.php:86	bookingextension_agent\local\wbagent\services\queue_transition_service	to_retry_waiting
classes/local/wbagent/services/queue_transition_service.php:109	bookingextension_agent\local\wbagent\services\queue_transition_service	to_failed
classes/local/wbagent/services/queue_transition_service.php:131	bookingextension_agent\local\wbagent\services\queue_transition_service	to_skipped
classes/local/wbagent/services/queue_transition_service.php:151	bookingextension_agent\local\wbagent\services\queue_transition_service	to_succeeded
classes/local/wbagent/services/shared_json_payload_extractor.php:39	bookingextension_agent\local\wbagent\services\shared_json_payload_extractor	extract_json_candidates
classes/local/wbagent/services/shared_json_payload_extractor.php:71	bookingextension_agent\local\wbagent\services\shared_json_payload_extractor	extract_balanced_json_objects
classes/local/wbagent/services/spawn_contract_service.php:36	bookingextension_agent\local\wbagent\services\spawn_contract_service	normalize_task_result
classes/local/wbagent/services/spawn_contract_service.php:50	bookingextension_agent\local\wbagent\services\spawn_contract_service	apply_output_bindings
classes/local/wbagent/services/spawn_contract_service.php:86	bookingextension_agent\local\wbagent\services\spawn_contract_service	normalize_spawn_commands
classes/local/wbagent/services/spawn_contract_service.php:127	bookingextension_agent\local\wbagent\services\spawn_contract_service	normalize_produced_outputs
classes/local/wbagent/services/spawn_contract_service.php:152	bookingextension_agent\local\wbagent\services\spawn_contract_service	normalize_binding_reference
classes/local/wbagent/services/task_prompt_contract.php:35	bookingextension_agent\local\wbagent\services\task_prompt_contract	__construct
classes/local/wbagent/services/task_prompt_contract.php:44	bookingextension_agent\local\wbagent\services\task_prompt_contract	to_array
classes/local/wbagent/services/task_version_policy.php:51	bookingextension_agent\local\wbagent\services\task_version_policy	evaluate
classes/local/wbagent/services/task_version_policy.php:88	bookingextension_agent\local\wbagent\services\task_version_policy	is_deprecated
classes/local/wbagent/services/trigger_result_util.php:38	bookingextension_agent\local\wbagent\services\trigger_result_util	has_trigger
classes/local/wbagent/summarizer/basic_collection_result_summary_contributor.php:42	bookingextension_agent\local\wbagent\summarizer\basic_collection_result_summary_contributor	supports
classes/local/wbagent/summarizer/basic_collection_result_summary_contributor.php:53	bookingextension_agent\local\wbagent\summarizer\basic_collection_result_summary_contributor	summarize
classes/local/wbagent/summarizer/diagnosis_result_summary_contributor.php:42	bookingextension_agent\local\wbagent\summarizer\diagnosis_result_summary_contributor	supports
classes/local/wbagent/summarizer/diagnosis_result_summary_contributor.php:53	bookingextension_agent\local\wbagent\summarizer\diagnosis_result_summary_contributor	summarize
classes/local/wbagent/summarizer/docs_result_summary_contributor.php:42	bookingextension_agent\local\wbagent\summarizer\docs_result_summary_contributor	supports
classes/local/wbagent/summarizer/docs_result_summary_contributor.php:53	bookingextension_agent\local\wbagent\summarizer\docs_result_summary_contributor	summarize
classes/local/wbagent/summarizer/single_object_result_summary_contributor.php:45	bookingextension_agent\local\wbagent\summarizer\single_object_result_summary_contributor	supports
classes/local/wbagent/summarizer/single_object_result_summary_contributor.php:66	bookingextension_agent\local\wbagent\summarizer\single_object_result_summary_contributor	summarize
classes/local/wbagent/task_contract_validator.php:70	bookingextension_agent\local\wbagent\task_contract_validator	build_task_metadata
classes/local/wbagent/task_contract_validator.php:100	bookingextension_agent\local\wbagent\task_contract_validator	build_task_capability_name
classes/local/wbagent/task_contract_validator.php:122	bookingextension_agent\local\wbagent\task_contract_validator	validate_task_metadata
classes/local/wbagent/task_contract_validator.php:192	bookingextension_agent\local\wbagent\task_contract_validator	validate_registry_contracts
classes/local/wbagent/task_contract_validator.php:228	bookingextension_agent\local\wbagent\task_contract_validator	get_deny_reason_priority
classes/local/wbagent/task_contract_validator.php:244	bookingextension_agent\local\wbagent\task_contract_validator	extract_task_namespace
classes/local/wbagent/task_contract_validator.php:260	bookingextension_agent\local\wbagent\task_contract_validator	is_namespaced_task_name
classes/local/wbagent/task_contract_validator.php:272	bookingextension_agent\local\wbagent\task_contract_validator	component_may_register_namespace
classes/local/wbagent/task_discovery.php:44	bookingextension_agent\local\wbagent\task_discovery	get_task_instances
classes/local/wbagent/task_discovery.php:89	bookingextension_agent\local\wbagent\task_discovery	get_trigger_provider_instances
classes/local/wbagent/task_discovery.php:109	bookingextension_agent\local\wbagent\task_discovery	get_last_diagnostics
classes/local/wbagent/task_discovery.php:119	bookingextension_agent\local\wbagent\task_discovery	find_candidate_classes
classes/local/wbagent/task_discovery.php:173	bookingextension_agent\local\wbagent\task_discovery	get_task_directories
classes/local/wbagent/task_discovery.php:198	bookingextension_agent\local\wbagent\task_discovery	instantiate_if_supported
classes/local/wbagent/task_discovery.php:226	bookingextension_agent\local\wbagent\task_discovery	ensure_class_loaded
classes/local/wbagent/task_discovery.php:265	bookingextension_agent\local\wbagent\task_discovery	add_diagnostic
classes/local/wbagent/task_discovery.php:280	bookingextension_agent\local\wbagent\task_discovery	compare_task_classes
classes/local/wbagent/task_discovery.php:297	bookingextension_agent\local\wbagent\task_discovery	get_namespace_priority
classes/local/wbagent/task_executability_evaluator.php:47	bookingextension_agent\local\wbagent\task_executability_evaluator	__construct
classes/local/wbagent/task_executability_evaluator.php:60	bookingextension_agent\local\wbagent\task_executability_evaluator	evaluate_task
classes/local/wbagent/task_executability_evaluator.php:114	bookingextension_agent\local\wbagent\task_executability_evaluator	evaluate_all_tasks
classes/local/wbagent/task_executability_evaluator.php:132	bookingextension_agent\local\wbagent\task_executability_evaluator	get_executable_task_names
classes/local/wbagent/task_executability_evaluator.php:152	bookingextension_agent\local\wbagent\task_executability_evaluator	deny_result
classes/local/wbagent/task_executability_evaluator.php:169	bookingextension_agent\local\wbagent\task_executability_evaluator	has_required_capabilities
classes/local/wbagent/task_executability_evaluator.php:199	bookingextension_agent\local\wbagent\task_executability_evaluator	is_valid_context
classes/local/wbagent/task_governance_service.php:51	bookingextension_agent\local\wbagent\task_governance_service	sync_enableall_toggles
classes/local/wbagent/task_provider.php:42	bookingextension_agent\local\wbagent\task_provider	get_component
classes/local/wbagent/task_provider.php:51	bookingextension_agent\local\wbagent\task_provider	get_tasks
classes/local/wbagent/task_provider.php:63	bookingextension_agent\local\wbagent\task_provider	get_discovery_diagnostics
classes/local/wbagent/task_provider.php:72	bookingextension_agent\local\wbagent\task_provider	get_contextual_prompt_packs
classes/local/wbagent/task_provider.php:103	bookingextension_agent\local\wbagent\task_provider	get_issue_code_provider
classes/local/wbagent/task_provider.php:116	bookingextension_agent\local\wbagent\task_provider	get_prompt_guidance
classes/local/wbagent/task_provider.php:127	bookingextension_agent\local\wbagent\task_provider	get_result_summary_contributors
classes/local/wbagent/task_registry.php:75	bookingextension_agent\local\wbagent\task_registry	register
classes/local/wbagent/task_registry.php:203	bookingextension_agent\local\wbagent\task_registry	get_task
classes/local/wbagent/task_registry.php:213	bookingextension_agent\local\wbagent\task_registry	get_provider_for_task
classes/local/wbagent/task_registry.php:224	bookingextension_agent\local\wbagent\task_registry	normalize_task_input
classes/local/wbagent/task_registry.php:244	bookingextension_agent\local\wbagent\task_registry	get_preview_option_memory_for_task
classes/local/wbagent/task_registry.php:258	bookingextension_agent\local\wbagent\task_registry	get_preview_option_memory_helpers
classes/local/wbagent/task_registry.php:279	bookingextension_agent\local\wbagent\task_registry	get_task_names
classes/local/wbagent/task_registry.php:292	bookingextension_agent\local\wbagent\task_registry	get_task_names_for_context
classes/local/wbagent/task_registry.php:310	bookingextension_agent\local\wbagent\task_registry	get_tasks
classes/local/wbagent/task_registry.php:320	bookingextension_agent\local\wbagent\task_registry	get_task_contract
classes/local/wbagent/task_registry.php:329	bookingextension_agent\local\wbagent\task_registry	get_task_contracts
classes/local/wbagent/task_registry.php:338	bookingextension_agent\local\wbagent\task_registry	get_contract_diagnostics
classes/local/wbagent/task_registry.php:347	bookingextension_agent\local\wbagent\task_registry	get_result_summary_contributors
classes/local/wbagent/task_registry.php:357	bookingextension_agent\local\wbagent\task_registry	is_read_only_task
classes/local/wbagent/task_registry.php:368	bookingextension_agent\local\wbagent\task_registry	is_task_active
classes/local/wbagent/task_registry.php:394	bookingextension_agent\local\wbagent\task_registry	get_task_toggle_setting_name
classes/local/wbagent/task_registry.php:410	bookingextension_agent\local\wbagent\task_registry	get_task_capabilities
classes/local/wbagent/task_registry.php:424	bookingextension_agent\local\wbagent\task_registry	get_all_schemas
classes/local/wbagent/task_registry.php:441	bookingextension_agent\local\wbagent\task_registry	get_all_schemas_for_context
classes/local/wbagent/task_registry.php:471	bookingextension_agent\local\wbagent\task_registry	explain_task_schema_for_context
classes/local/wbagent/task_registry.php:499	bookingextension_agent\local\wbagent\task_registry	get_all_prompt_contracts
classes/local/wbagent/task_registry.php:516	bookingextension_agent\local\wbagent\task_registry	get_prompt_contracts_for_context
classes/local/wbagent/task_registry.php:549	bookingextension_agent\local\wbagent\task_registry	build_prompt_contract
classes/local/wbagent/task_registry.php:616	bookingextension_agent\local\wbagent\task_registry	get_contextual_prompt_packs
classes/local/wbagent/task_registry.php:643	bookingextension_agent\local\wbagent\task_registry	get_message_triggers
classes/local/wbagent/task_registry.php:654	bookingextension_agent\local\wbagent\task_registry	get_trigger_id_to_task_name_map
classes/local/wbagent/task_registry.php:665	bookingextension_agent\local\wbagent\task_registry	make_default
classes/local/wbagent/task_registry.php:728	bookingextension_agent\local\wbagent\task_registry	register_discovered_tasks_without_provider
classes/local/wbagent/task_registry.php:761	bookingextension_agent\local\wbagent\task_provider_interface	__construct
classes/local/wbagent/task_registry.php:772	bookingextension_agent\local\wbagent\task_provider_interface	get_component
classes/local/wbagent/task_registry.php:781	bookingextension_agent\local\wbagent\task_provider_interface	get_tasks
classes/local/wbagent/task_registry.php:790	bookingextension_agent\local\wbagent\task_provider_interface	get_contextual_prompt_packs
classes/local/wbagent/task_registry.php:799	bookingextension_agent\local\wbagent\task_provider_interface	get_issue_code_provider
classes/local/wbagent/task_registry.php:808	bookingextension_agent\local\wbagent\task_provider_interface	get_prompt_guidance
classes/local/wbagent/task_registry.php:817	bookingextension_agent\local\wbagent\task_provider_interface	get_discovery_diagnostics
classes/local/wbagent/task_registry.php:841	bookingextension_agent\local\wbagent\task_registry	normalize_provider_component_name
classes/local/wbagent/task_registry.php:856	bookingextension_agent\local\wbagent\task_registry	append_provider_discovery_diagnostics
classes/local/wbagent/task_registry.php:882	bookingextension_agent\local\wbagent\task_registry	add_contract_diagnostic
classes/local/wbagent/task_registry.php:896	bookingextension_agent\local\wbagent\task_registry	fail_on_contract_diagnostics_when_strict
classes/local/wbagent/task_registry.php:916	bookingextension_agent\local\wbagent\task_registry	is_governance_strict_mode_enabled
classes/local/wbagent/task_registry_factory.php:44	bookingextension_agent\local\wbagent\task_registry_factory	get_default
classes/local/wbagent/task_registry_factory.php:65	bookingextension_agent\local\wbagent\task_registry_factory	get_last_build_warning
classes/local/wbagent/task_registry_factory.php:76	bookingextension_agent\local\wbagent\task_registry_factory	reset
classes/task/execute_ai_run_adhoc.php:57	bookingextension_agent\task\execute_ai_run_adhoc	get_name
classes/task/execute_ai_run_adhoc.php:66	bookingextension_agent\task\execute_ai_run_adhoc	execute
classes/task/rebuild_task_catalog_embeddings_adhoc.php:47	bookingextension_agent\task\rebuild_task_catalog_embeddings_adhoc	execute
cli/rebuild_embeddings_fixture.php:277	(global)	read_fixture_rows
cli/rebuild_embeddings_fixture.php:312	(global)	write_fixture_rows
db/upgrade.php:32	(global)	xmldb_bookingextension_agent_ensure_ai_messages_userid
db/upgrade.php:68	(global)	xmldb_bookingextension_agent_upgrade
tests/agent/abstract_agent_testcase.php:99	bookingextionsion_agent\abstract_agent_testcase	setUp
tests/agent/abstract_agent_testcase.php:142	bookingextionsion_agent\abstract_agent_testcase	grant_agent_capabilities_to_editingteacher
tests/agent/abstract_agent_testcase.php:208	bookingextionsion_agent\abstract_agent_testcase	maybe_register_live_ai_provider
tests/agent/abstract_agent_testcase.php:260	bookingextionsion_agent\abstract_agent_testcase	register_live_wunderbyte_provider
tests/agent/abstract_agent_testcase.php:334	bookingextionsion_agent\abstract_agent_testcase	register_live_openai_provider
tests/agent/abstract_agent_testcase.php:382	bookingextionsion_agent\abstract_agent_testcase	normalize_chat_endpoint
tests/agent/abstract_agent_testcase.php:396	bookingextionsion_agent\abstract_agent_testcase	chat_endpoint_to_embeddings_endpoint
tests/agent/abstract_agent_testcase.php:410	bookingextionsion_agent\abstract_agent_testcase	update_provider_actionconfig
tests/agent/abstract_agent_testcase.php:433	bookingextionsion_agent\abstract_agent_testcase	configure_wunderbyte_embeddings_model
tests/agent/abstract_agent_testcase.php:471	bookingextionsion_agent\abstract_agent_testcase	maybe_load_embeddings_fixture
tests/agent/abstract_agent_testcase.php:495	bookingextionsion_agent\abstract_agent_testcase	create_option
tests/agent/abstract_agent_testcase.php:524	bookingextionsion_agent\abstract_agent_testcase	make_executor
tests/agent/abstract_agent_testcase.php:543	bookingextionsion_agent\abstract_agent_testcase	exec_command
tests/agent/abstract_agent_testcase.php:583	bookingextionsion_agent\abstract_agent_testcase	get_option_from_db
tests/agent/abstract_agent_testcase.php:593	bookingextionsion_agent\abstract_agent_testcase	get_all_options
tests/agent/abstract_agent_testcase.php:607	bookingextionsion_agent\abstract_agent_testcase	require_real_llm
tests/agent/abstract_agent_testcase.php:632	bookingextionsion_agent\abstract_agent_testcase	build_runtime
tests/agent/abstract_agent_testcase.php:660	bookingextionsion_agent\abstract_agent_testcase	chat
tests/agent/abstract_agent_testcase.php:677	bookingextionsion_agent\abstract_agent_testcase	booking_contextid
tests/agent/abstract_agent_testcase.php:689	bookingextionsion_agent\abstract_agent_testcase	resolve_queue_item_id_for_confirmation
tests/agent/abstract_agent_testcase.php:739	bookingextionsion_agent\abstract_agent_testcase	confirm_pending_result
tests/agent/abstract_agent_testcase.php:764	bookingextionsion_agent\abstract_agent_testcase	extract_command
tests/agent/abstract_agent_testcase.php:780	bookingextionsion_agent\abstract_agent_testcase	extract_task_result
tests/agent/abstract_agent_testcase.php:795	bookingextionsion_agent\abstract_agent_testcase	execute_command
tests/agent/abstract_agent_testcase.php:818	bookingextionsion_agent\abstract_agent_testcase	execute_all_commands
tests/agent/abstract_agent_testcase.php:847	bookingextionsion_agent\abstract_agent_testcase	assert_generate_text_logged_for_thread
tests/agent/abstract_agent_testcase.php:873	bookingextionsion_agent\abstract_agent_testcase	tearDown
tests/agent/abstract_llm_task_matrix_testcase.php:51	bookingextionsion_agent\abstract_llm_task_matrix_testcase	setUp
tests/agent/abstract_llm_task_matrix_testcase.php:63	bookingextionsion_agent\abstract_llm_task_matrix_testcase	task_matrix_scenarios
tests/agent/abstract_llm_task_matrix_testcase.php:73	bookingextionsion_agent\abstract_llm_task_matrix_testcase	assert_llm_task_scenario_success
tests/agent/abstract_llm_task_matrix_testcase.php:201	bookingextionsion_agent\abstract_llm_task_matrix_testcase	grant_local_entities_capabilities_to_editingteacher
tests/agent/abstract_llm_task_matrix_testcase.php:222	bookingextionsion_agent\abstract_llm_task_matrix_testcase	grant_optional_capability_to_editingteacher
tests/agent/abstract_llm_task_matrix_testcase.php:247	bookingextionsion_agent\abstract_llm_task_matrix_testcase	assert_task_is_executable_or_skip
tests/agent/abstract_llm_task_matrix_testcase.php:277	bookingextionsion_agent\abstract_llm_task_matrix_testcase	prepare_scenario_runtime
tests/agent/abstract_llm_task_matrix_testcase.php:321	bookingextionsion_agent\abstract_llm_task_matrix_testcase	default_scenario_replacements
tests/agent/abstract_llm_task_matrix_testcase.php:346	bookingextionsion_agent\abstract_llm_task_matrix_testcase	prepare_recall_memory_scenario
tests/agent/abstract_llm_task_matrix_testcase.php:381	bookingextionsion_agent\abstract_llm_task_matrix_testcase	prepare_entity_scenario
tests/agent/abstract_llm_task_matrix_testcase.php:420	bookingextionsion_agent\abstract_llm_task_matrix_testcase	prepare_update_option_scenario
tests/agent/abstract_llm_task_matrix_testcase.php:462	bookingextionsion_agent\abstract_llm_task_matrix_testcase	prepare_booking_rules_service_scenario
tests/agent/abstract_llm_task_matrix_testcase.php:495	bookingextionsion_agent\abstract_llm_task_matrix_testcase	assert_scenario_assertions
tests/agent/abstract_llm_task_matrix_testcase.php:601	bookingextionsion_agent\abstract_llm_task_matrix_testcase	payload_text
tests/agent/abstract_llm_task_matrix_testcase.php:625	bookingextionsion_agent\abstract_llm_task_matrix_testcase	payload_field_value
tests/agent/abstract_llm_task_matrix_testcase.php:659	bookingextionsion_agent\abstract_llm_task_matrix_testcase	payload_field_count
tests/agent/abstract_llm_task_matrix_testcase.php:678	bookingextionsion_agent\abstract_llm_task_matrix_testcase	payload_step_count
tests/agent/abstract_llm_task_matrix_testcase.php:697	bookingextionsion_agent\abstract_llm_task_matrix_testcase	get_latest_debug_source
tests/agent/abstract_llm_task_matrix_testcase.php:720	bookingextionsion_agent\abstract_llm_task_matrix_testcase	render_assertion_value
tests/agent/abstract_llm_task_matrix_testcase.php:730	bookingextionsion_agent\abstract_llm_task_matrix_testcase	stringify_assertion_value
tests/agent/abstract_llm_task_matrix_testcase.php:746	bookingextionsion_agent\abstract_llm_task_matrix_testcase	resolve_task_result_payload
tests/agent/abstract_llm_task_matrix_testcase.php:810	bookingextionsion_agent\abstract_llm_task_matrix_testcase	render_scenario_template
tests/agent/abstract_llm_task_matrix_testcase.php:825	bookingextionsion_agent\abstract_llm_task_matrix_testcase	build_fallback_prompt
tests/agent/abstract_llm_task_matrix_testcase.php:843	bookingextionsion_agent\abstract_llm_task_matrix_testcase	scenario_matched_expected_task
tests/agent/abstract_llm_task_matrix_testcase.php:858	bookingextionsion_agent\abstract_llm_task_matrix_testcase	find_task_result_entry
tests/agent/abstract_llm_task_matrix_testcase.php:899	bookingextionsion_agent\abstract_llm_task_matrix_testcase	task_result_candidate_names
tests/agent/contracts/ai_confirm_run_contract_test.php:44	bookingextionsion_agent\ai_confirm_run_contract_test	test_follow_up_pending_intent_forces_confirmation_request
tests/agent/contracts/integration_agent_framework_test.php:39	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_registry_discovers_booking_tasks
tests/agent/contracts/integration_agent_framework_test.php:57	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_provider_interface_supports_issue_code_provider
tests/agent/contracts/integration_agent_framework_test.php:78	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_provider_interface_supports_prompt_guidance
tests/agent/contracts/integration_agent_framework_test.php:95	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_issue_code_provider_injected_into_agent_runtime
tests/agent/contracts/integration_agent_framework_test.php:113	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_schema_includes_prompt_meta
tests/agent/contracts/integration_agent_framework_test.php:138	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_registry_prioritizes_prompt_meta
tests/agent/contracts/integration_agent_framework_test.php:156	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_prompt_contracts_use_required_minimals_and_explicit_examples
tests/agent/contracts/integration_agent_framework_test.php:184	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_slim_catalog_keeps_examples_separate_from_minimals
tests/agent/contracts/integration_agent_framework_test.php:212	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_embedding_subset_keeps_full_descriptions
tests/agent/contracts/integration_agent_framework_test.php:253	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_embedding_subset_includes_property_descriptions
tests/agent/contracts/integration_agent_framework_test.php:287	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_orchestrator_prompts_are_generic
tests/agent/contracts/integration_agent_framework_test.php:302	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_action_specific_prompts_generic
tests/agent/contracts/integration_agent_framework_test.php:347	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_discovered_tasks_implement_task_interface
tests/agent/contracts/integration_agent_framework_test.php:363	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_multi_provider_discovery
tests/agent/contracts/integration_agent_framework_test.php:392	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_discovery_scans_all_wbagent_task_namespaces
tests/agent/contracts/integration_agent_framework_test.php:406	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_discovery_deduplicates_same_task_name
tests/agent/contracts/integration_agent_framework_test.php:418	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_trigger_provider_discovery_ignores_non_trigger_classes
tests/agent/contracts/integration_agent_framework_test.php:433	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_tasks_no_language_specific_logic
tests/agent/contracts/integration_agent_framework_test.php:454	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_task_schema_required_fields
tests/agent/contracts/integration_agent_framework_test.php:471	bookingextension_agent\local\wbagent\tests\integration_agent_framework_test	test_backward_compatibility_constants
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:42	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	test_registry_discovers_canonical_mod_booking_option_tasks
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:61	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	test_create_option_defaults_to_type_zero
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:89	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	test_create_option_emits_rich_observation_summary
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:123	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	test_update_option_sets_type_one_for_selflearning_input
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:163	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	test_create_slotbooking_option_requires_slot_fields
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:188	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	test_slotbooking_prompt_contracts_are_explicit
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:212	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	create_booking_test_context
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:238	bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test	grant_booking_option_task_capabilities
tests/agent/contracts/pending_intent_and_queue_transition_contract_test.php:39	bookingextension_agent\local\wbagent\tests\pending_intent_and_queue_transition_contract_test	test_pending_intent_service_set_returns_confirmation_code
tests/agent/contracts/pending_intent_and_queue_transition_contract_test.php:64	bookingextension_agent\local\wbagent\tests\pending_intent_and_queue_transition_contract_test	test_queue_transition_service_retry_waiting_transition
tests/agent/contracts/preflight_contract_validator_contract_test.php:38	bookingextension_agent\local\wbagent\tests\preflight_contract_validator_contract_test	test_validator_propagates_schema_error_contract
tests/agent/contracts/preflight_contract_validator_contract_test.php:63	bookingextension_agent\local\wbagent\tests\preflight_contract_validator_contract_test	test_validator_preserves_deprecation_issue_codes
tests/agent/contracts/preflight_contract_validator_contract_test.php:111	bookingextension_agent\local\wbagent\tests\preflight_contract_validator_contract_test	test_validator_blocks_unsupported_version
tests/agent/contracts/preflight_layers_contract_test.php:38	bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test	test_domain_runner_hard_blocks_permission_error
tests/agent/contracts/preflight_layers_contract_test.php:53	bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test	test_domain_runner_soft_blocks_duplicate_confirm_issue
tests/agent/contracts/preflight_layers_contract_test.php:65	bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test	test_execution_gate_retry_hint_for_provider_timeout
tests/agent/contracts/preflight_layers_contract_test.php:79	bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test	test_execution_gate_hard_blocks_after_max_retries
tests/agent/contracts/prompt_and_language_contract_test.php:41	bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test	test_prompt_contracts_do_not_use_name_based_heuristics
tests/agent/contracts/prompt_and_language_contract_test.php:83	bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test	test_language_policy_prefers_user_input_language
tests/agent/contracts/prompt_and_language_contract_test.php:112	bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test	test_language_policy_fallback_string_mapping
tests/agent/contracts/prompt_and_language_contract_test.php:128	bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test	test_language_policy_matrix_de_en_zh
tests/agent/contracts/queue_consolidation_contract_test.php:37	bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test	test_queue_status_policy_actionable_mutating_statuses_are_stable
tests/agent/contracts/queue_consolidation_contract_test.php:50	bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test	test_queue_status_policy_pickup_statuses_are_stable
tests/agent/contracts/queue_consolidation_contract_test.php:60	bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test	test_queue_command_mapper_prefers_prepared_input_and_preserves_metadata
tests/agent/contracts/queue_consolidation_contract_test.php:81	bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test	test_queue_command_mapper_filters_invalid_items_and_falls_back_to_raw_input
tests/agent/contracts/reference_scenarios_contract_test.php:37	bookingextension_agent\local\wbagent\tests\reference_scenarios_contract_test	test_scenario_a_readonly_result_contract
tests/agent/contracts/reference_scenarios_contract_test.php:54	bookingextension_agent\local\wbagent\tests\reference_scenarios_contract_test	test_scenario_b_multistep_command_schema_contract
tests/agent/contracts/reference_scenarios_contract_test.php:70	bookingextension_agent\local\wbagent\tests\reference_scenarios_contract_test	test_scenario_c_spawn_output_binding_contract
tests/agent/contracts/spawn_contract_service_test.php:35	bookingextension_agent\local\wbagent\tests\spawn_contract_service_test	test_normalize_task_result_adds_output_aliases
tests/agent/contracts/spawn_contract_service_test.php:53	bookingextension_agent\local\wbagent\tests\spawn_contract_service_test	test_apply_output_bindings_resolves_parent_aliases
tests/agent/contracts/spawn_contract_service_test.php:70	bookingextension_agent\local\wbagent\tests\spawn_contract_service_test	test_apply_output_bindings_reports_missing_reference
tests/agent/contracts/spawn_contract_service_test.php:86	bookingextension_agent\local\wbagent\tests\spawn_contract_service_test	test_normalize_spawn_commands_filters_invalid_entries
tests/agent/contracts/task_contract_validator_contract_test.php:39	bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test	test_namespaced_task_name_format
tests/agent/contracts/task_contract_validator_contract_test.php:49	bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test	test_reserved_namespace_ownership
tests/agent/contracts/task_contract_validator_contract_test.php:60	bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test	test_validate_registry_contracts_rejects_alias_version_mismatch
tests/agent/contracts/task_contract_validator_contract_test.php:94	bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test	test_registry_rejects_reserved_namespace_for_third_party_provider
tests/agent/contracts/task_contract_validator_contract_test.php:124	bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test	test_demo_task_onboards_via_provider_registration_only
tests/agent/contracts/task_contract_validator_contract_test.php:171	bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test	test_failing_provider_does_not_block_other_registered_tasks
tests/agent/llm_task_matrix_scenario_provider.php:39	bookingextionsion_agent\llm_task_matrix_scenario_provider	provide_registered_task_scenarios
tests/agent/llm_task_matrix_scenario_provider.php:64	bookingextionsion_agent\llm_task_matrix_scenario_provider	get_missing_registered_task_scenarios
tests/agent/llm_task_matrix_scenario_provider.php:84	bookingextionsion_agent\llm_task_matrix_scenario_provider	get_scenario_definitions
tests/agent/real_llm_multistep/all_tasks_real_llm_test.php:45	bookingextionsion_agent\all_tasks_real_llm_test	setUp
tests/agent/real_llm_multistep/all_tasks_real_llm_test.php:57	bookingextionsion_agent\all_tasks_real_llm_test	real_task_matrix_scenarios
tests/agent/real_llm_multistep/all_tasks_real_llm_test.php:61	bookingextionsion_agent\all_tasks_real_llm_test	test_task_matrix_covers_all_registered_tasks
tests/agent/real_llm_multistep/all_tasks_real_llm_test.php:71	bookingextionsion_agent\all_tasks_real_llm_test	test_all_registered_tasks_can_complete_via_real_llm
tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php:48	bookingextionsion_agent\confirmation_flow_real_llm_test	setUp
tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php:56	bookingextionsion_agent\confirmation_flow_real_llm_test	test_multistep_create_assign_teacher_and_make_visible
tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php:211	bookingextionsion_agent\confirmation_flow_real_llm_test	is_task_available
tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:40	bookingextionsion_agent\get_current_user_real_llm_test	setUp
tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:45	bookingextionsion_agent\get_current_user_real_llm_test	test_get_current_user_observation_contains_full_user_payload
tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:110	bookingextionsion_agent\get_current_user_real_llm_test	payload_text
tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:128	bookingextionsion_agent\get_current_user_real_llm_test	has_task_evidence
tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:50	bookingextionsion_agent\lecture_autoconfirm_real_llm_test	setUp
tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:62	bookingextionsion_agent\lecture_autoconfirm_real_llm_test	test_lecture_autoconfirm_single_pass_creates_five_actions
tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:209	bookingextionsion_agent\lecture_autoconfirm_real_llm_test	build_trace_line
tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:228	bookingextionsion_agent\lecture_autoconfirm_real_llm_test	has_create_option_commands
tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:238	bookingextionsion_agent\lecture_autoconfirm_real_llm_test	count_create_option_commands
tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:268	bookingextionsion_agent\lecture_autoconfirm_real_llm_test	is_task_available
tests/agent/real_llm_multistep/list_actions_real_llm_test.php:46	bookingextionsion_agent\list_actions_real_llm_test	setUp
tests/agent/real_llm_multistep/list_actions_real_llm_test.php:54	bookingextionsion_agent\list_actions_real_llm_test	test_list_actions_groups_by_provider_then_readonly_write_then_capability
tests/agent/real_llm_multistep/list_actions_real_llm_test.php:143	bookingextionsion_agent\list_actions_real_llm_test	payload_text
tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:42	bookingextionsion_agent\normal_option_datetime_real_llm_test	setUp
tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:53	bookingextionsion_agent\normal_option_datetime_real_llm_test	test_datetime_prompt_routes_to_create_option_and_type_zero
tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:114	bookingextionsion_agent\normal_option_datetime_real_llm_test	test_weekday_series_prompt_routes_to_create_option_and_creates_five_type_zero_options
tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:335	bookingextionsion_agent\normal_option_datetime_real_llm_test	is_task_available
tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:347	bookingextionsion_agent\normal_option_datetime_real_llm_test	extract_command_from_payload
tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:367	bookingextionsion_agent\normal_option_datetime_real_llm_test	decode_commands_from_payload
tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:389	bookingextionsion_agent\normal_option_datetime_real_llm_test	payload_text
tests/agent/real_llm_multistep/search_users_real_llm_test.php:40	bookingextionsion_agent\search_users_real_llm_test	setUp
tests/agent/real_llm_multistep/search_users_real_llm_test.php:45	bookingextionsion_agent\search_users_real_llm_test	test_search_users_observation_contains_roles_courses_and_profile
tests/agent/real_llm_multistep/search_users_real_llm_test.php:126	bookingextionsion_agent\search_users_real_llm_test	payload_text
tests/agent/real_llm_multistep/search_users_real_llm_test.php:144	bookingextionsion_agent\search_users_real_llm_test	has_task_evidence
```

## Methodeninventur (JS Source, vollstaendig)

Hinweis: Erfasst wurden nur Source-Dateien (z. B. amd/src), keine minifizierten Build-Artefakte.

Format: `datei:zeile<TAB>typ<TAB>funktion`

```text
./amd/src/aiinstructions.js:1015	(global-js)	extractFirstUrl
./amd/src/aiinstructions.js:1031	(global-js)	loadUrlInSidePreview
./amd/src/aiinstructions.js:1051	(global-js)	escapeCssIdentifier
./amd/src/aiinstructions.js:1063	(global-js)	scrollPreviewToFragment
./amd/src/aiinstructions.js:1074	(global-js)	decoded
./amd/src/aiinstructions.js:1104	(global-js)	loadDocInPreview
./amd/src/aiinstructions.js:113	(global-js)	renderMessageDebugMeta
./amd/src/aiinstructions.js:1152	(global-js)	isGenericStatusMessage
./amd/src/aiinstructions.js:1179	(global-js)	getFirstResultField
./amd/src/aiinstructions.js:1205	(global-js)	buildFriendlyRunMessage
./amd/src/aiinstructions.js:1249	(global-js)	buildDebugRunHtml
./amd/src/aiinstructions.js:1286	(global-js)	appendFriendlyAssistantMessage
./amd/src/aiinstructions.js:1308	(global-js)	buildAgentResponseMeta
./amd/src/aiinstructions.js:1317	(global-js)	handleFinalAgentResponse
./amd/src/aiinstructions.js:1342	(global-js)	handleAgentCommandResponse
./amd/src/aiinstructions.js:1381	(global-js)	handleConfirmationResponse
./amd/src/aiinstructions.js:1410	(global-js)	showConfirmPanel
./amd/src/aiinstructions.js:1466	(global-js)	renderOptionPreviewsInline
./amd/src/aiinstructions.js:1496	(global-js)	buildTaskPreviewHtml
./amd/src/aiinstructions.js:1549	(global-js)	hideConfirmPanel
./amd/src/aiinstructions.js:155	(global-js)	renderMessageDebugJson
./amd/src/aiinstructions.js:1563	(global-js)	clearActivePlanBubble
./amd/src/aiinstructions.js:1580	(global-js)	showRunStatus
./amd/src/aiinstructions.js:1693	(global-js)	extractPreviewOptionIds
./amd/src/aiinstructions.js:1725	(global-js)	collectPreviewOptionIds
./amd/src/aiinstructions.js:1761	(global-js)	appendStepBubble
./amd/src/aiinstructions.js:1781	(global-js)	clearStepBubbles
./amd/src/aiinstructions.js:1802	(global-js)	startStepPolling
./amd/src/aiinstructions.js:1833	(global-js)	refreshThreadDebugLogs
./amd/src/aiinstructions.js:184	(global-js)	renderDebugLogs
./amd/src/aiinstructions.js:1883	(global-js)	initDebugRefreshButton
./amd/src/aiinstructions.js:1914	(global-js)	stopStepPolling
./amd/src/aiinstructions.js:1924	(global-js)	resumeStepPolling
./amd/src/aiinstructions.js:1935	(global-js)	sendMessage
./amd/src/aiinstructions.js:2242	(global-js)	confirmRun
./amd/src/aiinstructions.js:2302	(global-js)	getTrialUiContext
./amd/src/aiinstructions.js:2333	(global-js)	requestTrialKey
./amd/src/aiinstructions.js:2398	(global-js)	activateTrialContext
./amd/src/aiinstructions.js:244	(global-js)	formatDebugLogsForClipboard
./amd/src/aiinstructions.js:2460	(global-js)	bindTrialButton
./amd/src/aiinstructions.js:2470	(global-js)	displayWelcomeMessage
./amd/src/aiinstructions.js:2498	(global-js)	stopCurrentRun
./amd/src/aiinstructions.js:2522	(global-js)	handleBodyClick
./amd/src/aiinstructions.js:2674	(global-js)	handleBodyKeydown
./amd/src/aiinstructions.js:2704	(global-js)	initCentralBodyHandlers
./amd/src/aiinstructions.js:2719	(global-js)	init
./amd/src/aiinstructions.js:286	(global-js)	parseJsonList
./amd/src/aiinstructions.js:301	(global-js)	parseJsonObjectList
./amd/src/aiinstructions.js:319	(global-js)	parseCommandPayload
./amd/src/aiinstructions.js:339	(global-js)	enforceErrorBubbleStyleFallback
./amd/src/aiinstructions.js:359	(global-js)	isTrialTokenInvalidError
./amd/src/aiinstructions.js:408	(global-js)	maybeShowTrialTokenInvalidAlert
./amd/src/aiinstructions.js:432	(global-js)	renderAmbiguityOptionsHtml
./amd/src/aiinstructions.js:468	(global-js)	renderFollowUpSuggestionsHtml
./amd/src/aiinstructions.js:513	(global-js)	appendMessage
./amd/src/aiinstructions.js:536	(global-js)	appendPrivacyNote
./amd/src/aiinstructions.js:554	(global-js)	appendAssistantPrivacyNote
./amd/src/aiinstructions.js:577	(global-js)	appendMessageHtml
./amd/src/aiinstructions.js:596	(global-js)	setSidePreviewHtml
./amd/src/aiinstructions.js:607	(global-js)	initResizableLayout
./amd/src/aiinstructions.js:617	(global-js)	applyColumns
./amd/src/aiinstructions.js:624	(global-js)	restoreOrDefault
./amd/src/aiinstructions.js:637	(global-js)	onPointerMove
./amd/src/aiinstructions.js:650	(global-js)	onMouseMove
./amd/src/aiinstructions.js:654	(global-js)	onTouchMove
./amd/src/aiinstructions.js:662	(global-js)	stopDragging
./amd/src/aiinstructions.js:671	(global-js)	startDragging
./amd/src/aiinstructions.js:69	(global-js)	runCollectedJavascript
./amd/src/aiinstructions.js:701	(global-js)	initMobilePreviewSwitch
./amd/src/aiinstructions.js:711	(global-js)	setPreviewActive
./amd/src/aiinstructions.js:766	(global-js)	escapeHtml
./amd/src/aiinstructions.js:779	(global-js)	updateThinkingLabel
./amd/src/aiinstructions.js:792	(global-js)	copyTextToClipboard
./amd/src/aiinstructions.js:835	(global-js)	showButtonFeedback
./amd/src/aiinstructions.js:861	(global-js)	getDocLinkMeta
./amd/src/aiinstructions.js:898	(global-js)	renderSmartLink
./amd/src/aiinstructions.js:920	(global-js)	renderTextWithLinks
./amd/src/aiinstructions.js:966	(global-js)	renderAssistantMessageHtml
./amd/src/aiinstructions.js:96	(global-js)	shouldAutoExecuteReadOnly
./amd/src/aiinstructions.js:991	(global-js)	extractFirstDoc
```
