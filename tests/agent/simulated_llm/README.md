# Simulated LLM Agent Tests

This folder mirrors the real LLM conversation test set with deterministic, scripted orchestrator responses.

Purpose:
- keep conversation-path coverage runnable without external LLM/network dependencies
- test confirmation and loop orchestration behavior with stable fixtures
- provide a clear structural counterpart to agent/real_llm

Scope:
- create/update/search/book users
- diagnose booking/cancellation issues
- bulk updates
- multi-step read-only loop scenarios

Implementation note:
- tests use a mocked orchestrator that returns scripted response payloads (`task_call`, `confirmation_request`, `clarification`)
- task execution still uses the real executor and test DB
