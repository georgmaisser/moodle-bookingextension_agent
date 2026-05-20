# Webservice Simulated LLM Tests

Deterministic whole-agent tests for the `ai_send_message` webservice entry point.

These tests keep the agent runtime, loop, executor, persistence, and debug logging real, but inject a scripted `core_ai\manager` so the LLM responses are fully controlled.

Run them with:

```bash
cd /var/www/moodle
./vendor/bin/phpunit -c phpunit.xml public/mod/booking/bookingextension/agent/tests/agent/simulated_llm/webservice
```
