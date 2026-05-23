# Task Authoring Guide

## Zweck

Leitfaden fuer neue oder ueberarbeitete Tasks im Zielbild ohne Legacy-Migrationspfade.

## Pflicht fuer mutating Tasks

1. get_name und get_schema
2. check_structure ohne I/O
3. preflight read-only mit prepared_input Ergebnis
4. execute nur mit prepared_input
5. eindeutige version im Task-Contract

## Pflicht fuer readonly Tasks

1. get_name und get_schema
2. check_structure explizit
3. preflight explizit (kann trivial sein, aber kein Legacy-Shim)
4. execute ohne verdeckte Mutation

## Verbotene Muster

- validate-only Taskpfade
- textbasierte Steuerlogik fuer Runtime-Entscheidungen
- bypass am preflight_pipeline fuer mutating tasks

## Ziel fuer Task-Versionierung

- version Pflichtfeld im Contract
- unsupported version -> TASK_VERSION_UNSUPPORTED
- deprecated version -> TASK_VERSION_DEPRECATED

## Referenzen

- CONTRACT_TASK_METADATA
- PREFLIGHT_FINAL_TARGET_FOR_REVIEW
- PREFLIGHT_IMPLEMENTATION_AGENT_RUNBOOK
