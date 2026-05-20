# Booking Agent Test Suite - Complete Overview

## Executive Summary

**Status**: Active and maintained. Use folder-based runs below for the current ground truth.

This document provides a complete overview of the three-wave test architecture for `mod_booking`'s AI agent (`wbagent`) subsystem. The test suite validates:
- Architecture contracts and interface stability
- Privacy mode implementation (MODE_OFF, MODE_SOFT, MODE_STRICT)
- LLM simulation with realistic response parsing
- Task parameter validation matrix
- End-to-end executor workflows
- Real LLM integration (Wave 3, opt-in)

## Conversation Test Folders (Real vs Simulated)

In addition to the wave grouping, conversation-style agent tests are now organized in two parallel folders:

- `real_llm/`
  - Uses a real provider via `BOOKING_TEST_AI_KEY`, `BOOKING_TEST_AI_MODEL`, `BOOKING_TEST_AI_ENDPOINT`.
  - Validates real provider behavior and end-to-end orchestration with live LLM output.

- `simulated_llm/`
  - Uses deterministic scripted orchestrator responses (`task_call`, `confirmation_request`, `clarification`).
  - Runs without network and without a configured LLM provider.
  - Executes commands against the real executor + test DB for stable integration coverage.

- `webservice_mock_llm/`
  - Uses `ai_send_message` as the entry point and injects a scripted `core_ai\manager`.
  - Keeps the runtime, loop, executor, persistence, and `local_wbagent_ai_llm_debug` logging real.
  - Covers the whole agent stack without a live provider.

This gives a clear structural counterpart to `real_llm/`: same scenario family, but deterministic execution.

## Test Wave Breakdown

### Wave 1: Permanent Architecture Contracts (25 tests, 225 assertions)

**Purpose**: Establish stable interfaces and prevent architecture drift

| Test File | Tests | Coverage |
|-----------|-------|----------|
| `permanent/contracts/agent_architecture_contract_test.php` | 5 | Response types, task registry baseline, message triggers, conversation lifecycle |
| `permanent/contracts/agent_inventory_contract_test.php` | 3 | 9 critical test files present, Behat feature exists, directories non-empty |
| `permanent/llm_sim/interpreter_realistic_llm_matrix_test.php` | 6 | 6 realistic LLM payloads (German, Markdown, unknown type, confirm, missing fields, disallowed task) |
| `permanent/tasks/task_validation_matrix_test.php` | 11 | 26 scenarios across 10 booking tasks (create, update, bulk_update, search, etc.) |

**Key Validations**:
- All 10 core booking tasks present in registry
- Interpreter correctly handles response types (clarification, error, confirm_pending, executed)
- Task schema validation enforces mandatory fields
- Privacy anonymizer callable with correct interfaces
- Conversation store lifecycle preserves pending intents

### Wave 2: Pragmatic Execution Tests (17 tests, 68 assertions)

**Purpose**: Validate actual task execution and privacy behavior

#### 2A: Task Execution (7 tests)
- Registry contains all 10 core tasks
- Executor initializes correctly
- Task structures (create, search, list, get_current_user) correct

**File**: `agent_task_execution_test.php`

#### 2B: Privacy Mode Validation (5 tests)
- Soft-mode anonymizes names (name → anon_1, anon_2, etc.)
- Email handling conditional on mode
- Task registry and message triggers functional
- Input validation matrix applied

**File**: `agent_privacy_mode_test.php`

#### 2C: End-to-End Scenarios (5 tests)
- Create → Search → Update flow with DB verification
- Filtered bulk_update on matching options only
- Read-only tasks don't mutate state
- Student capability boundaries enforced
- Error recovery flows

**File**: `agent_e2e_scenarios_test.php`
**Pattern**: Direct executor calls with full DB assertion chains

### Wave 3: Real LLM Integration (folder-based)

**Purpose**: Full pipeline orchestrator→LLM→interpreter→executor with real provider responses.

**Activation**: `BOOKING_TEST_AI_KEY` + `BOOKING_TEST_AI_MODEL` + `BOOKING_TEST_AI_ENDPOINT`

**Folder**: `real_llm/`

**Coverage examples**:
- create/update/search conversations
- booking/bulk update conversations
- diagnose issue/cancellation conversations
- multi-step loop conversations

**Key Features**:
- Graceful skip when LLM API unavailable (no test failure)
- Full parameter verification using existing DB helpers
- Gründliche Fehlersuche wenn Tests fehlschlagen (siehe WAVE_3_README.md)

## Running Tests

### All Tests (Repository Root)
```bash
cd /var/www/moodle
./vendor/bin/phpunit -c phpunit.xml \
  public/mod/booking/bookingextension/agent/tests/agent/permanent/contracts/*.php \
  public/mod/booking/bookingextension/agent/tests/agent/permanent/llm_sim/*.php \
  public/mod/booking/bookingextension/agent/tests/agent/permanent/tasks/*.php \
  public/mod/booking/bookingextension/agent/tests/agent/agent_task_execution_test.php \
  public/mod/booking/bookingextension/agent/tests/agent/agent_privacy_mode_test.php \
  public/mod/booking/bookingextension/agent/tests/agent/agent_e2e_scenarios_test.php \
  public/mod/booking/bookingextension/agent/tests/agent/real_llm/ \
  public/mod/booking/bookingextension/agent/tests/agent/simulated_llm/
```

### Wave 1 Only (Architecture Baseline)
```bash
./vendor/bin/phpunit -c phpunit.xml public/mod/booking/bookingextension/agent/tests/agent/permanent/
```

### Wave 2 Only (Pragmatic Execution)
```bash
./vendor/bin/phpunit -c phpunit.xml \
  public/mod/booking/bookingextension/agent/tests/agent/agent_task_execution_test.php \
  public/mod/booking/bookingextension/agent/tests/agent/agent_privacy_mode_test.php \
  public/mod/booking/bookingextension/agent/tests/agent/agent_e2e_scenarios_test.php
```

### Wave 3 Only (Real LLM - Opt-in)
```bash
BOOKING_TEST_AI_KEY=... \
BOOKING_TEST_AI_MODEL=... \
BOOKING_TEST_AI_ENDPOINT=... \
./vendor/bin/phpunit -c phpunit.xml \
  public/mod/booking/bookingextension/agent/tests/agent/real_llm/
```

### Conversation Suite Only (Real LLM, Opt-in)
```bash
BOOKING_TEST_AI_KEY=... \
BOOKING_TEST_AI_MODEL=... \
BOOKING_TEST_AI_ENDPOINT=... \
./vendor/bin/phpunit -c phpunit.xml public/mod/booking/bookingextension/agent/tests/agent/real_llm/
```

### Conversation Suite Only (Simulated LLM, Deterministic)
```bash
./vendor/bin/phpunit -c phpunit.xml public/mod/booking/bookingextension/agent/tests/agent/simulated_llm/
```

### Conversation Suite Only (Webservice Mock LLM)
```bash
./vendor/bin/phpunit -c phpunit.xml public/mod/booking/bookingextension/agent/tests/agent/webservice_mock_llm/
```

## Parameter Verification Pattern (Used Across All Waves)

### Basic Pattern (Wave 2 E2E Scenarios)
```php
// 1. Execute
$result = $this->exec_command('booking.create_option', [
    'text' => 'Yoga Class',
    'maxanswers' => 15,
    'coursestarttime' => '2045-06-20T14:00:00',
    'duration' => 120,
    'teacherquery' => 'current',
]);

// 2. Retrieve from DB
$option = $this->get_option_from_db($optionid);

// 3. Verify field-by-field
$this->assertEquals(15, (int)$option->maxanswers);
$this->assertEquals('Yoga Class', $option->text);

// 4. Verify WbTable output (optional)
$rows = $this->gen->create_table_for_one_option($optionid);
$row = reset($rows);
$this->assertStringContainsString('Yoga', $row->text);
```

### LLM Response Validation (Wave 3)
```php
// Parse LLM response
$interpreter = new interpreter($store, new task_registry());
$parsed = $interpreter->parse_llm_response($llm_response);

if ($parsed['response_type'] === 'confirm_pending') {
    // Extract parameters from LLM
    $params = $parsed['params'];

    // Execute
    $result = $this->make_executor()->execute_commands([...]);

    // Verify in database (same pattern as Wave 2)
    $option = $this->get_option_from_db($result['resultid']);
    $this->assertEquals($params['maxanswers'], (int)$option->maxanswers);
}
```

## Test Data & Fixtures

### Booking Instance Setup
- Course: Created via PHPUnit generator
- Booking: Created with default settings (booking type=standard)
- Teacher user: Full mod/booking:addinstance capability
- Student user: Limited to view/book capability

### Option Creation Helpers
```php
// Wave 2 E2E Scenarios use create_payload helper
private function create_payload(string $text, array $overrides = []): array {
    return array_merge([
        'text' => $text,
        'maxanswers' => 10,
        'coursestarttime' => '2045-03-15T09:00:00',
        'duration' => 8,
        'teacherquery' => 'current',
    ], $overrides);
}
```

### Test Methods (abstract_agent_testcase.php)
```php
// Direct executor invocation
exec_command($taskname, $input, $cmid, $userid)

// Database verification
get_option_from_db($optionid)
get_all_options()

// Executor setup
make_executor()

// WbTable output
$this->gen->create_table_for_one_option($optionid)
```

## Architecture Components Tested

| Component | Test Coverage | Status |
|-----------|---|---|
| **orchestrator.php** | Wave 3 (LLM message flow) | ✅ |
| **interpreter.php** | Wave 1 (response types), Wave 3 (JSON parsing) | ✅ |
| **executor.php** | Wave 2 (task execution), Wave 3 (param flow) | ✅ |
| **privacy_anonymizer.php** | Wave 2 (soft-mode), Wave 1 (interface) | ✅ |
| **conversation_store.php** | Wave 1 (lifecycle), Wave 3 (thread state) | ✅ |
| **task_registry.php** | Wave 1 (10 tasks present), Wave 2 (validation) | ✅ |
| **10 Booking Tasks** | Wave 1 (all present), Wave 2 (create/search/update), Wave 3 (real flow) | ✅ |

## Key Test Assertions

### Wave 1 Architecture
- ✅ 10 core tasks registered (create_option, update_option, bulk_update_options, search_options, search_users, search_courses, list_actions, list_option_properties, get_current_user, add_price_category)
- ✅ 3 response types valid (clarification, error, confirm_pending, executed)
- ✅ Privacy anonymizer callable with mode parameter
- ✅ Task schema includes text, maxanswers, coursestarttime, duration, teacherquery

### Wave 2 Pragmatic Execution
- ✅ Executor processes create_option → DB record created
- ✅ Privacy soft-mode anonymizes names (name → anon_X)
- ✅ Search results filtered by parameters
- ✅ Bulk_update applies only to matching options
- ✅ Student cannot create options (capability check)
- ✅ Parameter values match DB after execute

### Wave 3 Real LLM
- ✅ LLM response parsed correctly by interpreter
- ✅ Extracted parameters passed to executor
- ✅ Booking option created in DB with correct fields
- ✅ Multi-step workflows (create→update) work correctly
- ✅ Tests skip gracefully when LLM unavailable

## CI/CD Integration

### GitHub Actions Example
```yaml
- name: Run Booking Agent Tests
  run: |
    cd /var/www/moodle
    ./vendor/bin/phpunit -c phpunit.xml \
      public/mod/booking/bookingextension/agent/tests/agent/permanent/contracts/*.php \
      public/mod/booking/bookingextension/agent/tests/agent/permanent/llm_sim/*.php \
      public/mod/booking/bookingextension/agent/tests/agent/permanent/tasks/*.php \
      public/mod/booking/bookingextension/agent/tests/agent/agent_*.php \
      public/mod/booking/bookingextension/agent/tests/agent/simulated_llm/*.php
  env:
    BOOKING_TEST_AI_KEY: ''
    BOOKING_TEST_AI_MODEL: ''
    BOOKING_TEST_AI_ENDPOINT: ''
```

### Success Criteria
- Local run exits 0 for deterministic suites (`permanent/`, Wave 2 files, `simulated_llm/`)
- Real LLM suite is opt-in and only executed when `BOOKING_TEST_AI_*` variables are set
- Exit code 0

## Debugging Failed Tests

### Problem: Assertion on maxanswers mismatch
```
Expected: 20
Actual: 10
File: agent_e2e_scenarios_test.php:81
```

**Investigation**:
1. Check `exec_command` input (was 20 passed?)
2. Check database directly: `SELECT maxanswers FROM mdl_booking_options WHERE id=123`
3. Check executor parameter processing
4. Review privacy_anonymizer if in soft-mode

**Solution**: See Wave 2 README - trace parameter flow through layers

### Problem: LLM test skipped unexpectedly
```
1 test skipped (expected)
```

**Investigation**:
1. Are `BOOKING_TEST_AI_KEY`, `BOOKING_TEST_AI_MODEL`, `BOOKING_TEST_AI_ENDPOINT` set?
2. Is LLM API accessible? Check `core_ai` admin settings
3. Check network connectivity to LLM provider

**Solution**: Set `BOOKING_TEST_AI_KEY`, `BOOKING_TEST_AI_MODEL`, `BOOKING_TEST_AI_ENDPOINT` and ensure LLM API access

## File Structure

```
/var/www/moodle/public/mod/booking/bookingextension/agent/tests/agent/
├── abstract_agent_testcase.php          # Base test class
├── MASTER_README.md                     # This file
├── AGENT_CONVERSATIONS.md               # Conversation matrix and mapping
├── WAVE_3_README.md                     # Wave 3 details
├── real_llm/                            # Conversation tests with live provider (opt-in)
├── simulated_llm/                       # Conversation tests with scripted LLM responses
│
├── agent_task_execution_test.php        # Wave 2A (7 tests)
├── agent_privacy_mode_test.php          # Wave 2B (5 tests)
├── agent_e2e_scenarios_test.php         # Wave 2C (5 tests)
│
└── permanent/
  ├── WAVE_2_README.md                 # Wave 2 details
    ├── contracts/
    │   ├── agent_architecture_contract_test.php  # Wave 1A (5 tests)
    │   └── agent_inventory_contract_test.php     # Wave 1B (3 tests)
    │
    ├── llm_sim/
    │   └── interpreter_realistic_llm_matrix_test.php  # Wave 1C (6 tests)
    │
    └── tasks/
        └── task_validation_matrix_test.php   # Wave 1D (11 tests)
```

## Performance Metrics

Execution time and memory depend on the current test selection and environment.
Use the folder-based commands above as the source of truth and capture metrics from your latest CI run.

## Quality Gates

✅ **Automated Checks**:
- Deterministic suites pass (`permanent/`, Wave 2 files, `simulated_llm/`)
- Real-LLM suites are opt-in and isolated in `real_llm/`
- Code coverage: Executor, interpreter, privacy_anonymizer paths covered
- PHPUnit 11.5.46 compatible
- No fatal errors or exceptions

✅ **Manual Validation** (Post-Implementation):
- Parameter values verified in DB
- Privacy mode behavior matches specification
- Full booking workflow functional
- Multi-step scenarios work correctly

## Recent Updates

- **2026-05-14**: Added `simulated_llm/` as deterministic counterpart to `real_llm/`.
- **2026-05-14**: Removed stale file/path references and legacy env flag usage.
- **2026-05-14**: Updated run commands to folder-based execution and `./vendor/bin/phpunit`.

## Links & References

- [Wave 1 Architecture Contracts](permanent/contracts/)
- [Wave 2 Pragmatic Execution](agent_e2e_scenarios_test.php)
- [Wave 3 Real LLM Integration](WAVE_3_README.md)
- [Abstract Test Case Base](abstract_agent_testcase.php)
- [Moodle AI Service Documentation](https://docs.moodle.org/en/AI_service)

## Contact & Support

For test failures or enhancements, consult:
1. **Specific README** (`MASTER_README.md`, `WAVE_3_README.md`, `permanent/WAVE_2_README.md`)
2. **Test file comments** (each test includes detailed assertions)
3. **abstract_agent_testcase.php** (test helpers documentation)
4. **Privacy anonymizer logic** (see privacy_anonymizer.php comments)

---

**Total Test Coverage**: Use the current CI job output for exact counts.
**Last Updated**: 2026-05-14
**Moodle Version**: 5.1.1+
**PHPUnit**: 11.5.46+
