# Agent Framework File Disposition

Stand: 2026-05-25
Quelle: Vollinventur aller Dateien in classes, tests, docs

Statuswerte: KEEP, REFACTOR, REMOVE_NOW, REMOVE_LATER

| Datei | Status | Hinweis |
|---|---|---|
| classes/task/rebuild_task_catalog_embeddings_adhoc.php | KEEP |  |
| classes/task/execute_ai_run_adhoc.php | KEEP |  |
| classes/local/wbagent/message_persistence_service.php | KEEP |  |
| classes/local/wbagent/privacy_anonymizer.php | KEEP |  |
| classes/local/wbagent/prompt_policy_builder.php | KEEP |  |
| classes/local/wbagent/embeddings_csv_repository.php | REMOVE_LATER | Optionaler Embeddings-Featurepfad |
| classes/local/wbagent/booking/support/booking_mutation_validation.php | KEEP |  |
| classes/local/wbagent/booking/support/booking_rules_agent_service.php | REMOVE_NOW | Ohne produktive Referenz |
| classes/local/wbagent/booking/support/slot_booking_normalizer.php | KEEP |  |
| classes/local/wbagent/booking/booking_task_provider.php | KEEP |  |
| classes/local/wbagent/booking/booking_task_mutation_execute_service.php | KEEP |  |
| classes/local/wbagent/booking/booking_task_support.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_list_course_groups_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_update_calendar_event_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_create_calendar_event_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_user_profile_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_list_course_modules_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/explain_task_schema_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_delete_group_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_task_base.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_course_overview_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_activity_completion_status_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/search_courses_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_user_completion_report_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_user_enrolments_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_list_course_sections_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_current_user_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_site_summary_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_user_preferences_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_list_course_calendar_events_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/recall_memory_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_search_course_enrolments_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_create_group_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/create_user_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_delete_calendar_event_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_list_user_calendar_events_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_unenrol_user_manual_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/recreate_task_catalog_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_list_course_participants_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_send_user_message_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_user_roles_in_course_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_list_grade_items_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/search_users_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_update_group_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/get_current_user_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_set_user_preference_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_group_members_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_enrol_user_manual_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_module_details_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_user_grades_for_course_task.php | KEEP |  |
| classes/local/wbagent/core/tasks/list_actions_task.php | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/task_registry.php | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/task_provider.php | KEEP |  |
| classes/local/wbagent/summarizer/single_object_result_summary_contributor.php | KEEP |  |
| classes/local/wbagent/summarizer/docs_result_summary_contributor.php | KEEP |  |
| classes/local/wbagent/summarizer/diagnosis_result_summary_contributor.php | KEEP |  |
| classes/local/wbagent/summarizer/basic_collection_result_summary_contributor.php | KEEP |  |
| classes/local/wbagent/llm_debug_logger.php | KEEP |  |
| classes/local/wbagent/task_discovery.php | REMOVE_LATER | Trigger-Discovery Anteil entfernen |
| classes/local/wbagent/embeddings_retrieval_service.php | REMOVE_LATER | Optionaler Embeddings-Featurepfad |
| classes/local/wbagent/task_registry_factory.php | KEEP |  |
| classes/local/wbagent/interfaces/task_interface.php | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/interfaces/agent_task_provider.php | REMOVE_NOW | Unbenutzter Legacy-Vertrag |
| classes/local/wbagent/interfaces/task_provider_interface.php | KEEP |  |
| classes/local/wbagent/interfaces/summarizer/result_summary_contributor_interface.php | KEEP |  |
| classes/local/wbagent/interfaces/task_trigger_provider_interface.php | REMOVE_LATER | Nach Trigger-Entkopplung |
| classes/local/wbagent/interfaces/agent_conversation_store.php | KEEP |  |
| classes/local/wbagent/interfaces/result_summary_provider_interface.php | KEEP |  |
| classes/local/wbagent/interfaces/task_result_summary_provider_interface.php | KEEP |  |
| classes/local/wbagent/interfaces/agent_interpreter.php | KEEP |  |
| classes/local/wbagent/interfaces/issue_code_provider_interface.php | KEEP |  |
| classes/local/wbagent/interfaces/agent_executor.php | KEEP |  |
| classes/local/wbagent/interfaces/agent_authorization_service.php | KEEP |  |
| classes/local/wbagent/execution_feedback_service.php | KEEP |  |
| classes/local/wbagent/wunderbyte_trial_endpoint.py | KEEP |  |
| classes/local/wbagent/authorization_service.php | KEEP |  |
| tests/agent/contracts/integration_agent_framework_test.php | KEEP | In Standard-Teststruktur verschoben |
| classes/local/wbagent/adaptive_task_catalog_service.php | REMOVE_LATER | Falls planner_catalog_service ersetzt |
| classes/local/wbagent/agent_runtime.php | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/embeddings_catalog_builder_service.php | REMOVE_LATER | Optionaler Embeddings-Featurepfad |
| classes/local/wbagent/task_executability_evaluator.php | KEEP |  |
| classes/local/wbagent/task_contract_validator.php | KEEP |  |
| classes/local/wbagent/agent_decision_service.php | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/preview_policy.php | KEEP |  |
| classes/local/wbagent/services/preflight_domain_check_runner.php | KEEP |  |
| classes/local/wbagent/services/preflight_version_validator.php | KEEP |  |
| classes/local/wbagent/services/preflight_pipeline.php | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/services/task_version_policy.php | KEEP |  |
| classes/local/wbagent/services/preflight_audit_logger.php | KEEP |  |
| classes/local/wbagent/services/preflight_result_v2.php | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/services/mutation/entity_mutation_service.php | KEEP |  |
| classes/local/wbagent/services/mutation/option_mutation_service.php | KEEP |  |
| classes/local/wbagent/services/preflight_execution_gate.php | KEEP |  |
| classes/local/wbagent/services/preflight_schema_validator.php | KEEP |  |
| classes/local/wbagent/services/lookup/option_lookup_service.php | KEEP |  |
| classes/local/wbagent/services/lookup/docs_lookup_service.php | KEEP |  |
| classes/local/wbagent/interpreter.php | KEEP |  |
| classes/local/wbagent/planner_service.php | REMOVE_LATER | Nach strict preflight prepared_input |
| classes/local/wbagent/base_task.php | KEEP |  |
| classes/local/wbagent/aiready.php | KEEP |  |
| classes/local/wbagent/dto/create_option_input_dto.php | KEEP |  |
| classes/local/wbagent/dto/bulk_update_options_input_dto.php | KEEP |  |
| classes/local/wbagent/dto/mutation_result_dto.php | KEEP |  |
| classes/local/wbagent/dto/create_entity_input_dto.php | KEEP |  |
| classes/local/wbagent/dto/update_option_input_dto.php | KEEP |  |
| classes/local/wbagent/queue/observation_builder.php | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/queue/queue_manager.php | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/result_payload_summarizer.php | KEEP |  |
| classes/local/wbagent/prompts/initial_system_prompt.md | KEEP |  |
| classes/local/wbagent/loop_finalizer.php | KEEP |  |
| classes/local/wbagent/booking_issue_code_provider.php | KEEP |  |
| classes/local/wbagent/embeddings_action_config_resolver.php | REMOVE_LATER | Optionaler Embeddings-Featurepfad |
| classes/local/wbagent/orchestrator.php | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/ai_error_classifier.php | KEEP |  |
| classes/local/wbagent/message_trigger_registry.php | KEEP |  |
| classes/local/wbagent/config/command_schema.json | KEEP |  |
| classes/local/wbagent/recovery_enrichment_service.php | REMOVE_NOW | Heuristische Recovery entfernen |
| classes/local/wbagent/agent_state.php | KEEP |  |
| classes/local/wbagent/llm_call_service.php | KEEP |  |
| classes/local/wbagent/executor.php | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/embeddings_readiness_service.php | REMOVE_LATER | Optionaler Embeddings-Featurepfad |
| classes/local/wbagent/conversation_store.php | KEEP |  |
| classes/external/booking_create_option.php | KEEP |  |
| classes/external/ai_confirm_run.php | KEEP |  |
| classes/external/request_trial_key.php | KEEP |  |
| classes/external/booking_validate_option.php | KEEP |  |
| classes/external/ai_privacy_precheck.php | KEEP |  |
| classes/external/ai_get_doc_content.php | KEEP |  |
| classes/external/activate_trial_context.php | KEEP |  |
| classes/external/booking_update_option.php | KEEP |  |
| classes/external/ai_render_command_preview.php | KEEP |  |
| classes/external/ai_list_candidate_options.php | KEEP |  |
| classes/external/ai_send_message.php | KEEP |  |
| classes/external/ai_get_thread_debug_logs.php | KEEP |  |
| classes/external/booking_bulk_update_options.php | KEEP |  |
| classes/external/ai_poll_thread.php | KEEP |  |
| classes/agent.php | KEEP |  |
| tests/preflight_version_validator_test.php | KEEP |  |
| tests/task_version_policy_test.php | KEEP |  |
| tests/agent/queue_manager_test.php | KEEP |  |
| tests/agent/agent_executor_test.php | KEEP |  |
| tests/agent/agent_interpreter_test.php | KEEP |  |
| tests/agent/agent_error_matrix_test.php | KEEP |  |
| tests/agent/agent_privacy_mode_test.php | KEEP |  |
| tests/agent/agent_decision_service_test.php | KEEP |  |
| tests/agent/abstract_agent_testcase.php | KEEP |  |
| tests/agent/permanent/WAVE_2_README.md | KEEP |  |
| tests/agent/permanent/tasks/task_validation_matrix_test.php | KEEP |  |
| tests/agent/permanent/README.md | KEEP |  |
| tests/agent/permanent/contracts/agent_architecture_contract_test.php | REFACTOR | Recovery-basierte Contract-Annahmen anpassen |
| tests/agent/permanent/contracts/task_registry_resilience_contract_test.php | KEEP |  |
| tests/agent/permanent/contracts/agent_inventory_contract_test.php | KEEP |  |
| tests/agent/permanent/contracts/confirmation_session_allow_service_test.php | KEEP |  |
| tests/agent/permanent/llm_sim/interpreter_realistic_llm_matrix_test.php | KEEP |  |
| tests/agent/agent_e2e_update_option_test.php | KEEP |  |
| tests/agent/booking_task_class_ownership_test.php | KEEP |  |
| tests/agent/ai_send_message_internal_test.php | KEEP |  |
| tests/agent/booking_task_mutation_execute_service_test.php | KEEP |  |
| tests/agent/diagnose_booking_issue_task_test.php | KEEP |  |
| tests/agent/task_pure_data_contract_test.php | KEEP |  |
| tests/agent/docs_explainer_task_test.php | KEEP |  |
| tests/agent/agent_task_execution_test.php | KEEP |  |
| tests/agent/core_moodle_tasks_test.php | KEEP |  |
| tests/agent/simulated_llm/multi_step_loop_simulated_llm_test.php | KEEP |  |
| tests/agent/simulated_llm/abstract_simulated_llm_testcase.php | KEEP |  |
| tests/agent/simulated_llm/update_option_simulated_llm_test.php | KEEP |  |
| tests/agent/simulated_llm/book_users_simulated_llm_test.php | KEEP |  |
| tests/agent/simulated_llm/README.md | KEEP |  |
| tests/agent/simulated_llm/agent_simulated_llm_test.php | KEEP |  |
| tests/agent/simulated_llm/webservice/README.md | KEEP |  |
| tests/agent/simulated_llm/webservice/ai_send_message_mock_scenarios.php | KEEP |  |
| tests/agent/simulated_llm/webservice/ai_send_message_simulated_llm_test.php | KEEP |  |
| tests/agent/simulated_llm/bulk_update_options_simulated_llm_test.php | KEEP |  |
| tests/agent/simulated_llm/create_option_simulated_llm_test.php | KEEP |  |
| tests/agent/simulated_llm/search_options_simulated_llm_test.php | KEEP |  |
| tests/agent/simulated_llm/routed_ai_manager_mock.php | KEEP |  |
| tests/agent/simulated_llm/diagnose_booking_issue_simulated_llm_test.php | KEEP |  |
| tests/agent/simulated_llm/diagnose_cancellation_issue_simulated_llm_test.php | KEEP |  |
| tests/agent/agent_task_dual_output_test.php | KEEP |  |
| tests/agent/task_registry_test.php | KEEP |  |
| tests/agent/agent_runtime_unit_test.php | KEEP |  |
| tests/agent/task_registry_prompt_meta_test.php | KEEP |  |
| tests/agent/message_trigger_registry_test.php | KEEP |  |
| tests/agent/agent_conversation_store_test.php | KEEP |  |
| tests/agent/embedded_llm/embeddings_retrieval_simulated_llm_test.php | KEEP |  |
| tests/agent/embedded_llm/fixtures/task_catalog_embeddings.csv | KEEP |  |
| tests/agent/embedded_llm/embeddings_runtime_real_llm_test.php | KEEP |  |
| tests/agent/ai_confirm_run_internal_test.php | KEEP |  |
| tests/agent/ai_messages_userid_upgrade_test.php | KEEP |  |
| tests/agent/booking_task_provider_test.php | KEEP |  |
| tests/agent/real_llm/webservice/ai_send_message_real_llm_test.php | KEEP |  |
| tests/agent/real_llm/agent_real_llm_test.php | KEEP |  |
| tests/agent/real_llm/search_options_real_llm_test.php | KEEP |  |
| tests/agent/real_llm/diagnose_booking_issue_real_llm_test.php | KEEP |  |
| tests/agent/real_llm/diagnose_cancellation_issue_real_llm_test.php | KEEP |  |
| tests/agent/real_llm/update_option_real_llm_test.php | KEEP |  |
| tests/agent/real_llm/multi_step_loop_real_llm_test.php | KEEP |  |
| tests/agent/real_llm/bulk_update_options_real_llm_test.php | KEEP |  |
| tests/agent/real_llm/create_option_real_llm_test.php | KEEP |  |
| tests/agent/real_llm/book_users_real_llm_test.php | KEEP |  |
| tests/agent/WAVE_3_README.md | KEEP |  |
| tests/agent/AGENT_CONVERSATIONS.md | KEEP |  |
| tests/agent/agent_e2e_bulk_update_test.php | KEEP |  |
| tests/agent/agent_e2e_scenarios_test.php | KEEP |  |
| tests/agent/MASTER_README.md | KEEP |  |
| tests/agent/issue_code_provider_test.php | KEEP |  |
| tests/agent/agent_e2e_create_option_test.php | KEEP |  |
| tests/agent/simulated_llm_multistep/confirmation_flow_simulated_llm_test.php | KEEP |  |
| tests/agent/recall_memory_task_test.php | KEEP |  |
| tests/agent/agent_internal_loop_test.php | KEEP |  |
| tests/agent/create_option_task_validation_test.php | KEEP |  |
| tests/agent/diagnose_cancellation_issue_task_test.php | KEEP |  |
| tests/agent/task_executability_evaluator_test.php | KEEP |  |
| tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php | KEEP |  |
| tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php | KEEP |  |
| tests/agent/real_llm_multistep/slotbooking_autoconfirm_real_llm_test.php | KEEP |  |
| tests/agent/agent_e2e_readonly_test.php | KEEP |  |
| tests/fixtures/task_catalog_embeddings.csv | KEEP |  |
| tests/task_contract_validator_test.php | KEEP |  |
| docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd | REFACTOR | Sollbild bereits aktualisiert, mit Code synchron halten |
