# Product Architecture Contracts

Stand: 2026-05-25
Scope: mod/booking/bookingextension/agent

## C1 - Contextid Authority
- Interne Agent-Vertraege verwenden contextid als primaeren Scope-Key.
- activity-spezifische IDs (z.B. cmid) sind nur Adapter fuer Domain-APIs und UI-Rueckbezuge.
- Pending-Intent, Queue, Preflight, Executor und Run-Idempotency fuehren contextid explizit.

Akzeptanzkriterien:
- Jeder interne Mutations-Run hat contextid in Queue-/Guard-/Run-Metadaten.
- Runtime/Decision/Executor greifen fuer Scope-Checks auf contextid zurueck.

## C2 - Third-Party DX (Provider-First + Fallback)
- Neue Tasks werden bevorzugt ueber task_provider_interface eingebunden, ohne Framework-Codepatch.
- Wenn kein Provider existiert, werden Tasks direkt aus classes/local/wbagent/*/tasks entdeckt.
- Wenn ein Provider existiert, wird kein zusaetzlicher Direct-Task-Fallback ausgefuehrt.
- Registry-Build bleibt robust: fehlerhafte Provider isoliert, funktionsfaehige Provider bleiben aktiv.
- Task-Kontrakte sind explizit (Schema, Prompt-Guidance, Capabilities, Version).

Akzeptanzkriterien:
- Demo-Provider kann registriert werden, ohne agent_runtime/agent_decision_service zu aendern.
- Demo-Task ohne Provider wird ueber Direct-Task-Discovery gefunden.
- Fehler in einem Provider verhindern nicht die Registrierung anderer Provider.

## C3 - Language Fidelity
- Antwortsprache folgt zentraler Authority in language_policy_service.
- Prioritaet: letzte User-Sprache (user_input_lang) > modellseitiges user_lang > modellseitiges lang > technischer Fallback.
- Deterministische Framework-Texte verwenden lokalisierte String-IDs.

Akzeptanzkriterien:
- Runtime setzt lang/user_lang konsistent aus language_policy_service.
- Deterministische Retry-/Fallback-/Next-Step-Texte sind lokalisiert.

## C4 - Confirmation by Default
- Mutationen sind per Default confirmation_request.
- confirm_pending fuehrt nur vorbereitete Queue-Commands aus.
- Session-Autoconfirm ist an userid + contextid gebunden.
- Wenn ai_confirm_run nach einer erfolgreichen Mutation ein neues pending_intent fuer ein verbleibendes mutating Queue-Item setzt, muss die Antwort deterministisch wieder confirmation_request sein, unabhaengig vom vorherigen response_type.

Akzeptanzkriterien:
- Readonly-Kommandos werden direkt ausgefuehrt; mutierende Kommandos nie ohne Confirmation-Flow.
- Confirmation-Allowance wirkt nur innerhalb des gleichen userid+contextid-Scope.
- Ein Follow-up-pending_intent fuer weitere mutierende Queue-Items kann nicht als sufficient/execution_result an die UI auslaufen; der Continuation-Vertrag bleibt confirmation_request.

## C5 - Capability Matrix
- Freischaltung ist mehrstufig: Runtime aktiviert, Task aktiv, Context gueltig, Capability vorhanden.
- Capability-Checks laufen ueber Moodle-Context API.

Akzeptanzkriterien:
- Evaluator liefert deterministische Deny-Reasons (inactive/context/capability).
- Fehlende Capability blockiert Task-Ausfuehrung vor execute().

## C6 - Complex Scenario Readiness
- Framework unterstuetzt deterministische Multi-Step-/Spawn-Ketten mit Artefaktbindung.
- Abhaengige Child-Steps werden spaet preflighted (nach Parent-Erfolg und Binding).

Akzeptanzkriterien:
- Spawn-/depends_on-Ketten sind im Queue-Modell abbildbar.
- Parent-Output kann als Input-Binding in Child-Tasks uebergeben werden.
