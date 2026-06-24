

## 1. Plugin-Struktur & Metadaten

- [ ] Verzeichnisstruktur entspricht dem Plugin-Typ (z. B. `mod/`, `block/`, `local/`, `auth/`)
- [ ] `version.php` vorhanden und vollständig
- [ ] `$plugin->component` korrekt gesetzt (Frankenstyle-Name, exakt wie Verzeichnis)
- [ ] `$plugin->version` als Datumsformat `JJJJMMTTXX` und höher als die Vorgängerversion
- [ ] `$plugin->requires` auf die Mindest-Moodle-Version gesetzt
- [ ] `$plugin->maturity` korrekt (`MATURITY_ALPHA/BETA/RC/STABLE`)
- [ ] `$plugin->release` als menschenlesbare Versionsangabe (z. B. „1.2.0")
- [ ] `$plugin->dependencies` deklariert, falls andere Plugins benötigt werden
- [ ] Keine überflüssigen / temporären Dateien im Release (`.git`, IDE-Configs, `node_modules`)
- [ ] Komponentenname kollidiert nicht mit Core- oder bestehenden Drittanbieter-Plugins

## 2. Lizenz & rechtliche Grundlagen

- [ ] Lizenz ist GPL v3 oder kompatibel (Pflicht für Moodle-Plugins)
- [ ] Jede PHP-Datei enthält den GPL-Lizenzheader
- [ ] `@copyright`, `@license` und `@package` in den Datei-Headern korrekt
- [ ] Verwendete Bibliotheken / Assets haben kompatible Lizenzen und sind dokumentiert
- [ ] Keine fremden Markenrechte / urheberrechtlich geschützten Inhalte ohne Erlaubnis

## 3. Coding Standards & Code-Qualität

- [ ] Code folgt den offiziellen Moodle Coding Guidelines
- [ ] `local_codechecker` läuft ohne Fehler (möglichst ohne Warnungen)
- [ ] PHPDoc-Blöcke für alle Klassen, Methoden und Funktionen
- [ ] Keine veralteten (deprecated) Core-Funktionen verwendet
- [ ] Keine direkten Datenbankzugriffe an der DB-API vorbei
- [ ] Konsistente Einrückung, Namenskonventionen und Frankenstyle-Präfixe
- [ ] Keine auskommentierten Code-Leichen oder `var_dump`/`error_log`-Reste
- [ ] PHP-Linting ohne Syntaxfehler über alle unterstützten PHP-Versionen

## 4. Sicherheit (kritisch!)

- [ ] **SQL-Injection:** Ausschließlich `$DB`-API mit Platzhaltern, niemals String-Konkatenation
- [ ] **XSS:** Ausgaben mit `s()`, `format_string()`, `format_text()`, `clean_param()` bereinigt
- [ ] **CSRF:** Alle Formulare und Aktionen prüfen `sesskey()` / verwenden moodleform
- [ ] **Zugriffskontrolle:** `require_login()` bzw. `require_course_login()` an allen Einstiegspunkten
- [ ] **Kontextprüfung:** `require_capability()` / `has_capability()` mit korrektem Kontext
- [ ] Benutzereingaben durch `optional_param()` / `required_param()` mit passendem Typ validiert
- [ ] Datei-Zugriffe laufen über die Moodle File API (`pluginfile.php`, kein direkter Pfadzugriff)
- [ ] Keine Offenlegung sensibler Daten (Pfade, Stacktraces, DB-Strukturen) im Frontend
- [ ] Keine Ausführung von Benutzereingaben (`eval`, `system`, Datei-Includes aus Eingaben)

## 5. Datenbank (XMLDB & Upgrades)

- [ ] `db/install.xml` mit dem XMLDB-Editor erstellt (nicht von Hand)
- [ ] Tabellen-/Spaltennamen folgen Moodle-Konventionen (klein, ohne Plugin-Präfix in `install.xml`)
- [ ] Sinnvolle Indizes und Schlüssel definiert
- [ ] `db/upgrade.php` deckt alle Schemaänderungen ab
- [ ] Jeder Upgrade-Schritt setzt `upgrade_plugin_savepoint()` / `upgrade_mod_savepoint()`
- [ ] Upgrade von der ältesten unterstützten Version getestet (keine Datenverluste)
- [ ] Saubere Deinstallation (`db/uninstall.php` falls Aufräumarbeiten nötig)
- [ ] Funktioniert mit MySQL/MariaDB **und** PostgreSQL

## 6. Capabilities & Berechtigungen

- [ ] `db/access.php` definiert alle benötigten Capabilities
- [ ] Capability-Namen folgen dem Schema `plugintype/pluginname:aktion`
- [ ] Korrekte `riskbitmask`-Angaben (z. B. `RISK_XSS`, `RISK_PERSONAL`)
- [ ] Sinnvolle Standardrechte pro Rolle (`archetypes`)
- [ ] Sprachstrings für jede Capability in `lang/en/` vorhanden
- [ ] Berechtigungen werden im Code tatsächlich geprüft (nicht nur deklariert)

## 7. Datenschutz / DSGVO (Privacy API)

- [ ] Privacy API ist implementiert (Pflicht seit Moodle 3.5)
- [ ] Bei personenbezogenen Daten: `metadata\provider` beschreibt alle gespeicherten Daten
- [ ] Datenexport implementiert (`get_users_in_context`, `export_user_data`)
- [ ] Datenlöschung implementiert (`delete_data_for_user`, `delete_data_for_all_users_in_context`)
- [ ] Ohne personenbezogene Daten: `null_provider` mit Begründungs-String
- [ ] Privacy-Unit-Tests laufen erfolgreich
- [ ] Externe Datenübermittlungen (APIs) sind dokumentiert und einwilligungskonform

## 8. Sprache & Internationalisierung (i18n)

- [ ] Alle Strings in `lang/en/plugintype_pluginname.php`
- [ ] Keine fest codierten, sichtbaren Texte im PHP-/Template-Code
- [ ] Ausgaben über `get_string()` bzw. `lang_string`
- [ ] Platzhalter (`{$a}`) statt String-Konkatenation für variable Inhalte
- [ ] Pflichtstring `pluginname` vorhanden
- [ ] Mehrsprachigkeit (Multilang-Filter) funktioniert in Ausgaben
- [ ] Optional: Übersetzung über AMOS einreichbar / vorbereitet

## 9. Barrierefreiheit (Accessibility)

- [ ] Erfüllt WCAG 2.1 Level AA (Moodle-Zielstandard)
- [ ] Vollständige Tastaturbedienbarkeit (keine reinen Maus-Aktionen)
- [ ] Sinnvolle ARIA-Rollen / -Labels, wo nötig
- [ ] Ausreichender Farbkontrast; Information nicht nur über Farbe vermittelt
- [ ] Alle Bilder/Icons haben `alt`-Texte bzw. sind als dekorativ markiert
- [ ] Mit Screenreader (z. B. NVDA / VoiceOver) getestet
- [ ] Formularfelder korrekt mit Labels verknüpft

## 10. UI / UX & Templates

- [ ] Oberfläche nutzt Mustache-Templates statt HTML im PHP-Code
- [ ] `renderer.php` / Output-API für die Darstellung verwendet
- [ ] Responsive Darstellung (Desktop, Tablet, Mobil)
- [ ] Kompatibel mit dem Standard-Theme (Boost) und idealerweise Classic
- [ ] Folgt den Moodle-UI-Konventionen (Buttons, Navigation, Notifications)
- [ ] Keine Inline-Styles / kein fest verdrahtetes CSS, das Themes überschreibt
- [ ] JavaScript als AMD-Module umgesetzt (`amd/src`, gebaut nach `amd/build`)

## 11. Automatisierte Tests

- [ ] PHPUnit-Tests für die Kernlogik vorhanden (`tests/`)
- [ ] Behat-Tests für die zentralen Benutzer-Workflows (`tests/behat/`)
- [ ] Tests laufen lokal und in der CI ohne Fehler
- [ ] Sinnvolle Code-Coverage der Geschäftslogik
- [ ] Generators für Testdaten (`tests/generator/`) falls nötig
- [ ] Privacy-Provider-Tests enthalten

## 12. Backup, Restore & Reset

- [ ] Backup-Logik unter `backup/moodle2/` implementiert (für Aktivitätsmodule)
- [ ] Restore-Logik vorhanden und getestet (inkl. Cross-Course-Restore)
- [ ] Dateien, Bewertungen und Benutzerdaten werden korrekt übertragen
- [ ] „Kurs zurücksetzen" (Reset) wird unterstützt, falls relevant
- [ ] Backup/Restore zwischen unterschiedlichen Moodle-Versionen getestet

## 13. Events, Logging & Bewertungen

- [ ] Relevante Aktionen lösen Events über die Event API aus (`classes/event/`)
- [ ] Events erscheinen korrekt im Protokoll (Logs)
- [ ] Gradebook-Integration korrekt (falls Bewertungen anfallen)
- [ ] Geplante Aufgaben über die Task API (`db/tasks.php`), nicht über alte Cron-Funktion
- [ ] Kalender-/Completion-Integration funktioniert, falls relevant

## 14. Funktions- & Integrationstests

- [ ] Saubere Neuinstallation funktioniert fehlerfrei
- [ ] Upgrade von der Vorversion funktioniert fehlerfrei
- [ ] Vollständige Deinstallation hinterlässt keine Datenreste
- [ ] Alle Kernfunktionen verhalten sich wie spezifiziert
- [ ] Edge Cases getestet (leere Eingaben, Sonderzeichen, sehr große Datenmengen)
- [ ] Mehrere gleichzeitige Nutzer / Rollen (Lehrer, Student, Admin, Gast) geprüft
- [ ] Fehlermeldungen sind verständlich und nutzerfreundlich

## 15. Kompatibilität

- [ ] Getestet auf allen angegebenen Moodle-Versionen
- [ ] Getestet auf allen unterstützten PHP-Versionen
- [ ] Funktioniert mit den unterstützten Datenbank-Engines
- [ ] In aktuellen Browsern getestet (Chrome, Firefox, Safari, Edge)
- [ ] Funktioniert in der Moodle Mobile App, falls relevant (`db/mobile.php`)
- [ ] Verträgt sich mit gängigen anderen Plugins (keine Konflikte)

## 16. Performance & Skalierung

- [ ] Caching über die MUC (Moodle Universal Cache) genutzt, wo sinnvoll
- [ ] Keine N+1-Datenbankabfragen / Abfragen in Schleifen
- [ ] Effiziente Abfragen mit passenden Indizes
- [ ] Last-/Mengentest mit realistischer Datenmenge durchgeführt
- [ ] Keine spürbaren Performance-Einbrüche auf Standard-Seiten

## 17. Web Services / Externe APIs *(falls zutreffend)*

- [ ] Externe Funktionen in `db/services.php` / `classes/external/` definiert
- [ ] Strenge Parameter- und Rückgabe-Validierung über die External API
- [ ] Berechtigungen je Web-Service-Funktion geprüft
- [ ] Web-Service-Funktionen dokumentiert

## 18. Dokumentation

- [ ] `README.md` mit Beschreibung, Funktionsumfang und Voraussetzungen
- [ ] Installationsanleitung vorhanden
- [ ] `CHANGELOG.md` mit Versionshistorie
- [ ] Konfigurations- und Nutzungshinweise
- [ ] Bekannte Einschränkungen / Roadmap (optional)
- [ ] Kontakt- / Support-Information

## 19. CI/CD & Build

- [ ] `moodle-plugin-ci` eingerichtet (lokal und/oder in der Pipeline)
- [ ] CI-Pipeline (z. B. GitHub Actions / GitLab CI) prüft Linting, Codechecker, PHPUnit, Behat
- [ ] Build der AMD-/CSS-Assets reproduzierbar dokumentiert
- [ ] Pipeline läuft auf der Versionsmatrix (Moodle × PHP × DB) grün

## 20. Veröffentlichung im Plugin Directory *(falls geplant)*

- [ ] Erfüllt die Anforderungen des Moodle Plugin Directory
- [ ] Öffentliches Quellcode-Repository (z. B. GitHub) verlinkt
- [ ] Aussagekräftige Beschreibung, Screenshots und Logo vorbereitet
- [ ] Unterstützte Moodle-Versionen klar angegeben
- [ ] Plugin durchläuft den „Prechecks"-Report ohne kritische Befunde

---

## Abschluss-Bewertung

- [ ] Alle kritischen Punkte (Sicherheit, Datenschutz, Datenbank-Upgrade) bestanden
- [ ] Keine offenen Blocker
- [ ] Freigabe zum Release erteilt

**Gesamtergebnis:** ☐ Bestanden ☐ Mit Auflagen ☐ Nicht bestanden
**Bemerkungen:** _______________________________________________
**Freigabe durch / Datum:** _________________________