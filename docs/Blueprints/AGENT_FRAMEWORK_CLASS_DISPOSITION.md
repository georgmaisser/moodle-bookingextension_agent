# Agent Framework Class Disposition

Stand: 2026-05-25
Quelle: Vollinventur aller class/interface/trait Deklarationen unter classes

Statuswerte: KEEP, REFACTOR, REMOVE_NOW, REMOVE_LATER

| Datei | Symbol | Typ | Status | Hinweis |
|---|---|---|---|---|
| classes/task/rebuild_task_catalog_embeddings_adhoc.php | rebuild_task_catalog_embeddings_adhoc | class | KEEP |  |
| classes/task/execute_ai_run_adhoc.php | execute_ai_run_adhoc | class | KEEP |  |
| classes/local/wbagent/message_persistence_service.php | message_persistence_service | class | KEEP |  |
| classes/local/wbagent/privacy_anonymizer.php | privacy_anonymizer | class | KEEP |  |
| classes/local/wbagent/prompt_policy_builder.php | prompt_policy_builder | class | KEEP |  |
| classes/local/wbagent/embeddings_csv_repository.php | embeddings_csv_repository | class | REMOVE_LATER | Optionaler Embeddings-Featurepfad |
| classes/local/wbagent/booking/support/booking_mutation_validation.php | booking_mutation_validation | class | KEEP |  |
| classes/local/wbagent/booking/support/booking_rules_agent_service.php | booking_rules_agent_service | class | REMOVE_NOW | Derzeit ohne produktive Referenz |
| classes/local/wbagent/booking/support/slot_booking_normalizer.php | slot_booking_normalizer | class | KEEP |  |
| classes/local/wbagent/booking/booking_task_provider.php | booking_task_provider | class | KEEP |  |
| classes/local/wbagent/booking/booking_task_mutation_execute_service.php | booking_task_mutation_execute_service | class | KEEP |  |
| classes/local/wbagent/booking/booking_task_support.php | booking_task_support | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_list_course_groups_task.php | core_list_course_groups_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_update_calendar_event_task.php | core_update_calendar_event_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_create_calendar_event_task.php | core_create_calendar_event_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_user_profile_task.php | core_get_user_profile_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_list_course_modules_task.php | core_list_course_modules_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/explain_task_schema_task.php | explain_task_schema_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_delete_group_task.php | core_delete_group_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_task_base.php | core_task_base | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_course_overview_task.php | core_get_course_overview_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_activity_completion_status_task.php | core_get_activity_completion_status_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/search_courses_task.php | search_courses_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_user_completion_report_task.php | core_get_user_completion_report_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_user_enrolments_task.php | core_get_user_enrolments_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_list_course_sections_task.php | core_list_course_sections_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_current_user_task.php | core_get_current_user_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_site_summary_task.php | core_get_site_summary_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_user_preferences_task.php | core_get_user_preferences_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_list_course_calendar_events_task.php | core_list_course_calendar_events_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/recall_memory_task.php | recall_memory_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_search_course_enrolments_task.php | core_search_course_enrolments_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_create_group_task.php | core_create_group_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/create_user_task.php | create_user_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_delete_calendar_event_task.php | core_delete_calendar_event_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_list_user_calendar_events_task.php | core_list_user_calendar_events_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_unenrol_user_manual_task.php | core_unenrol_user_manual_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/recreate_task_catalog_task.php | recreate_task_catalog_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_list_course_participants_task.php | core_list_course_participants_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_send_user_message_task.php | core_send_user_message_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_user_roles_in_course_task.php | core_get_user_roles_in_course_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_list_grade_items_task.php | core_list_grade_items_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/search_users_task.php | search_users_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_update_group_task.php | core_update_group_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/get_current_user_task.php | get_current_user_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_set_user_preference_task.php | core_set_user_preference_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_group_members_task.php | core_get_group_members_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_enrol_user_manual_task.php | core_enrol_user_manual_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_module_details_task.php | core_get_module_details_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/core_get_user_grades_for_course_task.php | core_get_user_grades_for_course_task | class | KEEP |  |
| classes/local/wbagent/core/tasks/list_actions_task.php | list_actions_task | class | KEEP |  |
| classes/local/wbagent/task_registry.php | task_registry | class | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/task_provider.php | task_provider | class | KEEP |  |
| classes/local/wbagent/summarizer/single_object_result_summary_contributor.php | single_object_result_summary_contributor | class | KEEP |  |
| classes/local/wbagent/summarizer/docs_result_summary_contributor.php | docs_result_summary_contributor | class | KEEP |  |
| classes/local/wbagent/summarizer/diagnosis_result_summary_contributor.php | diagnosis_result_summary_contributor | class | KEEP |  |
| classes/local/wbagent/summarizer/basic_collection_result_summary_contributor.php | basic_collection_result_summary_contributor | class | KEEP |  |
| classes/local/wbagent/llm_debug_logger.php | llm_debug_logger | class | KEEP |  |
| classes/local/wbagent/task_discovery.php | task_discovery | class | REMOVE_LATER | Trigger-Discovery Anteil entfernen |
| classes/local/wbagent/embeddings_retrieval_service.php | embeddings_retrieval_service | class | REMOVE_LATER | Optionaler Embeddings-Featurepfad |
| classes/local/wbagent/task_registry_factory.php | task_registry_factory | class | KEEP |  |
| classes/local/wbagent/interfaces/task_interface.php | task_interface | interface | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/interfaces/agent_task_provider.php | agent_task_provider | interface | REMOVE_NOW | Unbenutzter Legacy-Vertrag |
| classes/local/wbagent/interfaces/task_provider_interface.php | task_provider_interface | interface | KEEP |  |
| classes/local/wbagent/interfaces/summarizer/result_summary_contributor_interface.php | result_summary_contributor_interface | interface | KEEP |  |
| classes/local/wbagent/interfaces/task_trigger_provider_interface.php | task_trigger_provider_interface | interface | REMOVE_LATER | Nach Trigger-Entkopplung |
| classes/local/wbagent/interfaces/agent_conversation_store.php | agent_conversation_store | interface | KEEP |  |
| classes/local/wbagent/interfaces/result_summary_provider_interface.php | result_summary_provider_interface | interface | KEEP |  |
| classes/local/wbagent/interfaces/task_result_summary_provider_interface.php | task_result_summary_provider_interface | interface | KEEP |  |
| classes/local/wbagent/interfaces/agent_interpreter.php | agent_interpreter | interface | KEEP |  |
| classes/local/wbagent/interfaces/issue_code_provider_interface.php | issue_code_provider_interface | interface | KEEP |  |
| classes/local/wbagent/interfaces/agent_executor.php | agent_executor | interface | KEEP |  |
| classes/local/wbagent/interfaces/agent_authorization_service.php | agent_authorization_service | interface | KEEP |  |
| classes/local/wbagent/execution_feedback_service.php | execution_feedback_service | class | KEEP |  |
| classes/local/wbagent/wunderbyte_trial_endpoint.py | TrialRequest | class | KEEP |  |
| classes/local/wbagent/wunderbyte_trial_endpoint.py | TrialResponse | class | KEEP |  |
| classes/local/wbagent/authorization_service.php | authorization_service | class | KEEP |  |
| tests/agent/contracts/integration_agent_framework_test.php | integration_agent_framework_test | class | KEEP | In Standard-Teststruktur verschoben |
| classes/local/wbagent/adaptive_task_catalog_service.php | adaptive_task_catalog_service | class | REMOVE_LATER | Falls durch expliziten planner catalog ersetzt |
| classes/local/wbagent/agent_runtime.php | agent_runtime | class | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/embeddings_catalog_builder_service.php | embeddings_catalog_builder_service | class | REMOVE_LATER | Optionaler Embeddings-Featurepfad |
| classes/local/wbagent/task_executability_evaluator.php | task_executability_evaluator | class | KEEP |  |
| classes/local/wbagent/task_contract_validator.php | task_contract_validator | class | KEEP |  |
| classes/local/wbagent/agent_decision_service.php | agent_decision_service | class | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/preview_policy.php | preview_policy | class | KEEP |  |
| classes/local/wbagent/services/preflight_domain_check_runner.php | preflight_domain_check_runner | class | KEEP |  |
| classes/local/wbagent/services/preflight_version_validator.php | preflight_version_validator | class | KEEP |  |
| classes/local/wbagent/services/preflight_pipeline.php | preflight_pipeline | class | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/services/task_version_policy.php | task_version_policy | class | KEEP |  |
| classes/local/wbagent/services/preflight_audit_logger.php | preflight_audit_logger | class | KEEP |  |
| classes/local/wbagent/services/preflight_result_v2.php | preflight_result_v2 | class | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/services/mutation/entity_mutation_service.php | entity_mutation_service | class | KEEP |  |
| classes/local/wbagent/services/mutation/option_mutation_service.php | option_mutation_service | class | KEEP |  |
| classes/local/wbagent/services/preflight_execution_gate.php | preflight_execution_gate | class | KEEP |  |
| classes/local/wbagent/services/preflight_schema_validator.php | preflight_schema_validator | class | KEEP |  |
| classes/local/wbagent/services/lookup/option_lookup_service.php | option_lookup_service | class | KEEP |  |
| classes/local/wbagent/services/lookup/docs_lookup_service.php | docs_lookup_service | class | KEEP |  |
| classes/local/wbagent/interpreter.php | interpreter | class | KEEP |  |
| classes/local/wbagent/planner_service.php | planner_service | class | REMOVE_LATER | Nach strict preflight prepared_input entfernen |
| classes/local/wbagent/base_task.php | base_task | class | KEEP |  |
| classes/local/wbagent/aiready.php | aiready | class | KEEP |  |
| classes/local/wbagent/dto/create_option_input_dto.php | create_option_input_dto | class | KEEP |  |
| classes/local/wbagent/dto/bulk_update_options_input_dto.php | bulk_update_options_input_dto | class | KEEP |  |
| classes/local/wbagent/dto/mutation_result_dto.php | mutation_result_dto | class | KEEP |  |
| classes/local/wbagent/dto/create_entity_input_dto.php | create_entity_input_dto | class | KEEP |  |
| classes/local/wbagent/dto/update_option_input_dto.php | update_option_input_dto | class | KEEP |  |
| classes/local/wbagent/queue/observation_builder.php | observation_builder | class | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/queue/queue_manager.php | queue_manager | class | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/result_payload_summarizer.php | result_payload_summarizer | class | KEEP |  |
| classes/local/wbagent/loop_finalizer.php | loop_finalizer | class | KEEP |  |
| classes/local/wbagent/booking_issue_code_provider.php | booking_issue_code_provider | class | KEEP |  |
| classes/local/wbagent/embeddings_action_config_resolver.php | embeddings_action_config_resolver | class | REMOVE_LATER | Optionaler Embeddings-Featurepfad |
| classes/local/wbagent/orchestrator.php | orchestrator | class | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/ai_error_classifier.php | ai_error_classifier | class | KEEP |  |
| classes/local/wbagent/message_trigger_registry.php | message_trigger_registry | class | KEEP |  |
| classes/local/wbagent/recovery_enrichment_service.php | recovery_enrichment_service | class | REMOVE_NOW | Heuristische Recovery faellt weg |
| classes/local/wbagent/agent_state.php | agent_state | class | KEEP |  |
| classes/local/wbagent/llm_call_service.php | llm_call_service | class | KEEP |  |
| classes/local/wbagent/executor.php | executor | class | REFACTOR | Kernumbau laut Zielarchitektur |
| classes/local/wbagent/embeddings_readiness_service.php | embeddings_readiness_service | class | REMOVE_LATER | Optionaler Embeddings-Featurepfad |
| classes/local/wbagent/conversation_store.php | conversation_store | class | KEEP |  |
| classes/external/booking_create_option.php | booking_create_option | class | KEEP |  |
| classes/external/ai_confirm_run.php | ai_confirm_run | class | KEEP |  |
| classes/external/request_trial_key.php | request_trial_key | class | KEEP |  |
| classes/external/booking_validate_option.php | booking_validate_option | class | KEEP |  |
| classes/external/ai_privacy_precheck.php | ai_privacy_precheck | class | KEEP |  |
| classes/external/ai_get_doc_content.php | ai_get_doc_content | class | KEEP |  |
| classes/external/activate_trial_context.php | activate_trial_context | class | KEEP |  |
| classes/external/booking_update_option.php | booking_update_option | class | KEEP |  |
| classes/external/ai_render_command_preview.php | ai_render_command_preview | class | KEEP |  |
| classes/external/ai_list_candidate_options.php | ai_list_candidate_options | class | KEEP |  |
| classes/external/ai_send_message.php | ai_send_message | class | KEEP |  |
| classes/external/ai_get_thread_debug_logs.php | ai_get_thread_debug_logs | class | KEEP |  |
| classes/external/booking_bulk_update_options.php | booking_bulk_update_options | class | KEEP |  |
| classes/external/ai_poll_thread.php | ai_poll_thread | class | KEEP |  |
| classes/agent.php | agent | class | KEEP |  |
