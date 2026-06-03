# Params-Call Example Checklist

Status: draft
Date: 2026-06-03

## Prompt-Ebene

- [x] Generisches Command-Envelope-Beispiel im System-Prompt ergänzen.
- [x] Klarstellen, welcher Key fuer Parameter im Construction-Call verbindlich ist (z. B. `parameters`).
- [x] Explizit dokumentieren, dass `params` kein kanonischer Key ist.
- [x] Output-Contract um erlaubte Command-Level-Keys erweitern (z. B. `task`, `version`, `parameters`).

## Phasen-Trennung schaerfen (Selector vs Constructor)

### Constructor-only Auftrag

- [x] Constructor-Systemprompt um selector/discovery-Routinglogik bereinigen.
- [x] Explizit festhalten: Constructor darf nicht neu routen, nur Parameter fuer den bereits selektierten Task bauen.

### Harte Handoff-Bindung

- [x] Explizite Regel: Jeder command.task muss exakt selected_task entsprechen.
- [x] Explizite Regel: Kein Task-Wechsel und keine zusaetzlichen Tasks in der Constructor-Phase.

### Canonical Command-Envelope

- [x] Einen kanonischen Parameter-Key festlegen (empfohlen: `parameters`).
- [x] Erlaubte Command-Level-Keys explizit auflisten und vertraglich fixieren.
- [x] Nicht-kanonische Envelope-Keys (`params`, `command_id`, `id`, `cid`) als ungueltig markieren.

### Beispielstrategie

- [x] Generisches Envelope-Beispiel im Constructor-Systemprompt einbauen.
- [x] Pro Task im TASK_CATALOG ein konkretes `example_parameters`-Objekt hinterlegen (nicht nur Key-Listen).

### Prompt-Reduktion im Constructor

- [x] Full-Schema fuer Constructor auf selected_task begrenzen (kein globales Full-Schema aller Tasks).
- [x] Docs-Answer-Policy im Constructor entfernen oder nur fuer Docs-Tasks aktivieren.
- [x] Ueberlappende Regeln zwischen SYSTEM und OUTPUT_CONTRACT konsolidieren, damit keine Doppelvorgaben bleiben.

## Task-Katalog: Beispiel fuer Params-Call pruefen

Hinweis: Pro Task abhaken, sobald ein konkretes, valides Beispiel fuer den Parameter-Call dokumentiert ist.

### core (bookingextension)

- [x] core.get_current_user
- [x] core.list_actions
- [x] core.recall_memory
- [x] core.recreate_task_catalog
- [x] core.search_courses
- [x] core.search_users

### mod_booking

- [x] mod_booking.add_price_category
- [x] mod_booking.analyze_rules
- [x] mod_booking.book_users
- [x] mod_booking.bulk_update_options
- [x] mod_booking.configure_booking_instance
- [x] mod_booking.create_option
- [x] mod_booking.create_rule_from_template
- [x] mod_booking.create_selflearning_option
- [x] mod_booking.create_slotbooking_option
- [x] mod_booking.diagnose_booking_issue
- [x] mod_booking.diagnose_cancellation_issue
- [x] mod_booking.explain_docs_topic
- [x] mod_booking.get_option_details
- [x] mod_booking.list_option_properties
- [x] mod_booking.search_options
- [x] mod_booking.update_option
- [x] mod_booking.update_rule_from_template

### local_entities

- [x] entities.create_entity
- [x] entities.list_all_entities
- [x] entities.search

### local_shopping_cart

- [x] Task-Liste fuer local_shopping_cart ermitteln und in diese Checkliste aufnehmen.
- [ ] Keine registrierten local_shopping_cart Tasks gefunden (Stand 2026-06-03) - bei Bedarf Provider/Task-Discovery pruefen.

## Notizen

- Fokus dieser Liste: Parameter-Call-Beispiele fuer den Constructor-LLM-Call.
- Empfehlung: Ein generisches Envelope-Beispiel zentral im System-Prompt, task-spezifische Parameter-Beispiele im TASK_CATALOG pro Task.
- Checkbox-Status wurde teilweise per Registry-Audit gesetzt (Kriterium: Prompt-Contract liefert non-empty example_input).
