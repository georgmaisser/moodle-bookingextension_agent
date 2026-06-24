# History-Window-Cap: „letzte N + erste User-Message" (2026-06-23)

**Entscheidung Georg (2026-06-23):** Cap wieder einführen, aber nicht als naiver Tail-Cap, sondern
**letzte N Konversations-Messages PLUS die erste User-Message** (die ursprüngliche Anfrage).

## Ausgangslage (verifiziert)
- `phase_prompt_bundle_builder::build_prompt` schnitt mit
  `array_slice($messages, -get_history_limit_for_phase($phase))`, und die Methode gab **`PHP_INT_MAX`**
  zurück → faktisch **kein Cap**. Die ehemalige Konstante `orchestrator::MAX_HISTORY_MESSAGES = 12`
  war ungenutzt (No-op, vgl. Audit §5.F).
- `$messages` = **alle** Nicht-`step`-Messages des Threads (`conversation_store::get_messages()`),
  in **allen** Planner-Phasen + Synchronizer. → Ein langer Thread schickte seine **komplette**
  Historie bei **jedem** LLM-Call.
- **Wichtig:** Tool-Ergebnisse/Entscheidungskontext hängen **nicht** an `$messages` — Observations
  und Planner-Traces werden in `build_prompt` separat (nach den Messages) angehängt. Das Windowing
  betrifft also nur **alte Gesprächsturns**, nicht die jüngsten Tool-Resultate.

## Warum nicht naiver Tail-Cap
Lange Threads sind hier oft **Clarification-Ketten**, in denen die **erste** User-Nachricht (die
eigentliche Anfrage) das Wichtigste ist. Ein reiner `-N`-Tail würde genau die zuerst wegschneiden.
Deshalb: erste User-Message immer erhalten.

## Ziel-Verhalten
Gegeben die geordnete (alt→neu) Liste der Nicht-`step`-Messages und Tail-Größe `N`:
1. `count(messages) <= N` → alle Messages (unverändert).
2. sonst → **erste User-Message** (niedrigster Index mit `role === 'user'`) **+** die **letzten N**.
3. Liegt die erste User-Message ohnehin schon im Tail-Fenster → nur die letzten N (keine Dopplung).
4. Keine User-Message vorhanden → nur die letzten N.
Maximale Größe also `N + 1`.

## Umsetzung (eine zentrale Stelle)
- `orchestrator_prompt_profile_service`:
  - neue Konstante `HISTORY_TAIL_LIMIT = 14` (~7 Turns à user/assistant; leicht justierbar).
  - `get_history_limit_for_phase()` liefert wieder ein **echtes** Limit (`HISTORY_TAIL_LIMIT`),
    bleibt der Seam für ein evtl. künftiges per-Phase-Tuning.
  - neu `select_history_messages(array $messages, string $phase): array` mit obiger Logik.
- `phase_prompt_bundle_builder::build_prompt`: `array_slice(...)` → `select_history_messages(...)`.
- `orchestrator.php` (3× `$historycount`-Telemetrie für Discovery/Selection/Construction): auf
  `count(select_history_messages(...))` umgestellt, damit die Debug-Zahl die real injizierte Menge
  widerspiegelt.

## Bewusst NICHT geändert
- Keine Summarization/Token-Budget-Variante (separates, größeres Thema).
- Kein Admin-Setting — `HISTORY_TAIL_LIMIT` ist eine Konstante an **einer** Stelle.
- `get_messages()` im Store bleibt; das Windowing passiert beim Prompt-Bau (eine Quelle), nicht im
  Store (der auch für andere Zwecke die volle Historie liefert).

## Tests
- `orchestrator_prompt_profile_service_test`: Limit-Assertion von `PHP_INT_MAX` auf
  `HISTORY_TAIL_LIMIT` aktualisiert; neue Fälle für `select_history_messages`:
  kurzer Thread (passthrough), langer Thread (erste User-Message + letzte N, dedup, max N+1),
  erste User-Message bereits im Tail, kein User-Message-Fall.

## Caching-Anmerkung
Die History sitzt zwischen `[SYSTEM_RUNTIME]` (stabil) und `[SYSTEM_RUNTIME_STATE]` (volatil). Sie war
ohnehin pro Turn variabel; das Windowing verkürzt nur den variablen Mittelteil und ändert die
Caching-Block-Architektur nicht.

---
*Erstellt 2026-06-23. Begleitend zu `audit_followup_worklog_2026-06-23.md` (§8) und `full_audit_2026-06-23.md` (§5.F).*
