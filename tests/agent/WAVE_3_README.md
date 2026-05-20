# Wave 3: Real LLM Conversation Tests

## Overview

Wave 3 covers the live-provider agent conversation tests in `real_llm/`.
These tests validate the full runtime path:

- user message
- orchestrator + provider call
- interpreter response shaping
- confirmation or loop behavior
- executor/database effects (where applicable)

Unlike deterministic suites, this wave is opt-in and requires provider credentials.

## Test Location

- Folder: `/var/www/moodle/public/mod/booking/bookingextension/agent/tests/agent/real_llm/`
- Core smoke file: `agent_real_llm_test.php`
- Per-task conversation files include create/update/search/book/bulk/diagnose/multi-step cases.

## Activation

Set all required env vars before running:

```bash
export BOOKING_TEST_AI_KEY=sk-...
export BOOKING_TEST_AI_MODEL=gpt-4o
export BOOKING_TEST_AI_ENDPOINT=https://api.openai.com/v1/chat/completions
```

## Run Commands

Run all real LLM conversation tests:

```bash
cd /var/www/moodle
./vendor/bin/phpunit -c phpunit.xml public/mod/booking/bookingextension/agent/tests/agent/real_llm/
```

Run a single file:

```bash
cd /var/www/moodle
./vendor/bin/phpunit -c phpunit.xml public/mod/booking/bookingextension/agent/tests/agent/real_llm/create_option_real_llm_test.php
```

## What Is Verified

- Real provider availability in Moodle `core_ai` context
- Expected response families (`clarification`, `confirmation_request`, loop outcomes)
- Command extraction and execution path correctness
- Database side effects for mutating tasks
- Read-only loop auto-execution behavior and surfaced `results`

## Failure Investigation

When a real-LLM test fails:

1. Verify env vars: `BOOKING_TEST_AI_KEY`, `BOOKING_TEST_AI_MODEL`, `BOOKING_TEST_AI_ENDPOINT`
2. Confirm provider is reachable and endpoint is valid
3. Inspect returned response type and attached errors/details
4. Verify DB assertions after execution (`booking_options`, `booking_answers`, etc.)
5. Re-run the same file in isolation before broader suite runs

## Notes

- This wave is intentionally separate from deterministic suites (`permanent/`, `simulated_llm/`).
- Do not gate CI stability on real provider availability unless explicitly required.

**Last Updated:** 2026-05-14
