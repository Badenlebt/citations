# Implementation Plan: random-quote-homepage

## Overview

Die Umsetzung erfolgt in PHP (API, `api/`) und TypeScript/Vue 3 (Frontend, `frontend/`), passend zum Design. Zuerst entstehen die Backend-Bausteine (Schema, Repositories, Sprachauswahl, Zuordnungs-API), danach die Frontend-Grundlagen (API-Client, i18n, Router), die Startseite mit Composable und schließlich die Zuordnung in der Sprachverwaltung. Jede Stufe wird direkt in die vorherige verdrahtet.

Hinweis: Property-Tests liegen je Property in einer eigenen Datei (Abweichung von der Dateizuordnung im Design), damit parallele Aufgaben nicht dieselbe Datei beschreiben.

## Tasks

- [x] 1. Backend-Grundlagen: Sprachlogik, Schema und Repositories
  - [x] 1.1 `UiLanguage`, `UiLanguageResolver` und neue Fehlercodes anlegen
    - `api/src/UiLanguage.php` (Enum `de`/`en`, `tryFromInput()` mit `trim`, sonst `null`)
    - `api/src/UiLanguageResolver.php` (rein, `candidateLanguageIds()` ohne `null` und Duplikate)
    - `ErrorCode` um `INVALID_UI_LANGUAGE` (400) und `LANGUAGE_ALREADY_MAPPED` (409) erweitern
    - _Requirements: 6.7, 6.8, 6.9, 9.5, 9.6_

  - [x]* 1.2 Unit-Tests für Resolver, Enum und ErrorCode schreiben
    - `UiLanguageResolverTest`, `ErrorCodeTest` (neue Codes und Statuswerte)
    - _Requirements: 6.7, 6.8, 6.9_

  - [x] 1.3 Schema, Migration und Testinfrastruktur ergänzen
    - Tabelle `ui_language_mapping` in `db/schema.sql` (PK, UNIQUE, CHECK, FK mit CASCADE)
    - `db/migrations/001_ui_language_mapping.sql` mit `CREATE TABLE IF NOT EXISTS`, Verweis in `db/README.md`
    - `IntegrationTestCase::resetDatabase()` um `ui_language_mapping` ergänzen
    - _Requirements: 9.1, 9.2_

  - [x] 1.4 Repository für Sprachzuordnungen implementieren
    - `UiLanguageMappingRepositoryInterface` mit `findAll`, `findUiLanguageByLanguageId`, `assign`, `remove`
    - `PdoUiLanguageMappingRepository` (`ON DUPLICATE KEY UPDATE`, SQLSTATE 23000 ⇒ `false`), `LazyUiLanguageMappingRepository`, `InMemoryUiLanguageMappingRepository` (`api/tests/Fake`, mit 1:1-Prüfung)
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.7_

  - [x] 1.5 `QuoteRepositoryInterface` um Sprach-Teilbestand erweitern
    - `countByLanguage()` und `findByIndexInLanguage()` in `PdoQuoteRepository`, `LazyQuoteRepository`, `InMemoryQuoteRepository` (Sortierung `id ASC`)
    - _Requirements: 6.8, 6.9_

  - [x]* 1.6 Integrationstests für Mapping- und Quote-Repository schreiben
    - `UiLanguageMappingIntegrationTest`: 1:1-Eindeutigkeit, Ersetzen, Entfernen, `ON DELETE CASCADE`
    - `countByLanguage`/`findByIndexInLanguage` gegen MariaDB (übersprungen ohne Test-DB)
    - _Requirements: 9.1, 9.2, 9.13_

- [x] 2. Backend: Zufallsauswahl und Sprachzuordnungs-API
  - [x] 2.1 `RandomQuoteSelector::pick(?UiLanguage)` erweitern
    - Konstruktor um `UiLanguageMappingRepositoryInterface` ergänzen
    - Ein RNG-Zug, Kandidaten aus Resolver, Rückfall auf Gesamtbestand; ohne `$ui` Verhalten unverändert
    - _Requirements: 6.7, 6.8, 6.9, 6.10, 8.2_

  - [x]* 2.2 Property-Test für ungültigen `uiLanguage`
    - **Property 1: Ungültiger uiLanguage verändert die Auswahl nicht**
    - Datei `api/tests/Property/RandomQuoteInvalidUiLanguagePropertyTest.php`
    - **Validates: Requirements 6.7, 8.2**

  - [x]* 2.3 Property-Test für die Rückfallkette
    - **Property 2: Rückfallkette der Sprachauswahl**
    - Datei `api/tests/Property/RandomQuoteFallbackChainPropertyTest.php`
    - **Validates: Requirements 6.8, 6.9, 6.10**

  - [x]* 2.4 Property-Test für `NO_QUOTES`
    - **Property 3: NO_QUOTES genau bei leerem Bestand**
    - Datei `api/tests/Property/RandomQuoteEmptyStorePropertyTest.php`
    - **Validates: Requirements 6.10**

  - [x] 2.5 `SearchHandler::handleRandom()` um `uiLanguage` erweitern
    - Parameter aus `$request->query` lesen, über `UiLanguage::tryFromInput()` umwandeln, `pick($ui)` aufrufen
    - Antwortformat und Fehlercodes unverändert lassen
    - _Requirements: 6.7, 8.2_

  - [x]* 2.6 Property-Test für das Antwortformat
    - **Property 4: Antwortformat der Zufalls-API bleibt unverändert**
    - Datei `api/tests/Property/RandomResponseShapePropertyTest.php`
    - **Validates: Requirements 8.2**

  - [x]* 2.7 Unit-Tests für Selector und SearchHandler erweitern
    - `RandomQuoteSelectorTest`: zugeordnete Sprache, Englisch-Rückfall, Gesamtrückfall
    - `SearchHandlerTest`: `uiLanguage` fehlend, ungültig, gültig
    - _Requirements: 6.7, 6.8, 6.9, 6.10, 9.13_

  - [x] 2.8 `LanguageMappingService` und Ergebnisklasse implementieren
    - `assign()` und `remove()` mit Prüfreihenfolge `INVALID_UI_LANGUAGE`, `INVALID_LANGUAGE`, `LANGUAGE_ALREADY_MAPPED`
    - Idempotentes erneutes Zuordnen und Entfernen, Zustand bei Fehlern unverändert
    - _Requirements: 9.4, 9.5, 9.6, 9.7_

  - [x]* 2.9 Property-Test für Zuordnungs-Invarianten
    - **Property 5: Zuordnungs-Invarianten und Modelltreue unter beliebigen Operationsfolgen**
    - Datei `api/tests/Property/LanguageMappingModelPropertyTest.php`
    - **Validates: Requirements 9.1, 9.2, 9.3, 9.4, 9.5, 9.7**

  - [x]* 2.10 Property-Test für ungültige Eingaben
    - **Property 6: Ungültige Eingaben werden abgelehnt ohne Zustandsänderung**
    - Datei `api/tests/Property/LanguageMappingInvalidInputPropertyTest.php`
    - **Validates: Requirements 9.6**

  - [x] 2.11 `LanguageMappingHandler`, Dispatcher-Routen und Verdrahtung ergänzen
    - `api/src/Http/LanguageMappingHandler.php`: `GET`, `PUT /{uiLanguage}`, `DELETE /{uiLanguage}`
    - `Dispatcher`: Konstante `LANGUAGE_MAPPINGS`, Routen-Matching, `isWriteRoute()` für `PUT`/`DELETE`; `$pathParams` in `Handler` auf `int|string` erweitern
    - `api/index.php`: `LazyUiLanguageMappingRepository`, `RandomQuoteSelector` mit Mapping-Repository, neuer Handler
    - _Requirements: 9.3, 9.4, 9.5, 9.6, 9.7, 9.8_

  - [x]* 2.12 Property-Test für Session-Schutz erweitern
    - **Property 7: Schreibzugriffe ohne Session werden vor der Validierung abgelehnt**
    - `SessionGuardWriteProtectionPropertyTest.php` um `/language-mappings/{Segment}` ergänzen
    - **Validates: Requirements 9.3, 9.8**

  - [x]* 2.13 Unit-Tests für Handler und Dispatcher schreiben
    - `LanguageMappingHandlerTest`: GET öffentlich, PUT ersetzen, 409, 400-Fälle, DELETE idempotent, 401
    - `DispatcherTest`: neue Routen und `isWriteRoute`
    - _Requirements: 9.3, 9.4, 9.5, 9.6, 9.7, 9.8, 9.13_

- [x] 3. Checkpoint - Backend prüfen
  - Ensure all tests pass, ask the user if questions arise.

- [x] 4. Frontend-Grundlagen: API-Client, i18n und Router
  - [x] 4.1 API-Client, Typen und Fehlercodes erweitern
    - `random(signal?, uiLanguage?)` abwärtskompatibel, `getLanguageMappings`, `setLanguageMapping`, `removeLanguageMapping`
    - Typen `LanguageMappings`, `LanguageMapping`; `ERROR_CODES` und `TRANSLATED_CODES` um die neuen Codes ergänzen
    - _Requirements: 6.11, 9.3, 9.4, 9.7, 9.11_

  - [x]* 4.2 Unit-Tests für den API-Client schreiben
    - `uiLanguage` im Query, neue Mapping-Methoden
    - _Requirements: 6.11, 9.10_

  - [x] 4.3 i18n-Schlüssel und Rückfall ergänzen
    - Neue Schlüssel `home.*`, `nav.home`, `admin.languageMappings*`, `errors.INVALID_UI_LANGUAGE`, `errors.LANGUAGE_ALREADY_MAPPED` in `de` und `en`
    - `useI18n.lookup()`: fehlend oder leer ⇒ andere Sprache, dann Schlüsselname; bestehende Schlüssel unverändert
    - _Requirements: 6.1, 6.5, 6.6, 9.12_

  - [x]* 4.4 Property-Test für den i18n-Rückfall
    - **Property 14: i18n-Rückfall auf die andere Sprache**
    - Datei `frontend/src/i18n/fallback.property.test.ts`
    - **Validates: Requirements 6.5**

  - [x]* 4.5 Unit-Test für neue Nachrichtenschlüssel schreiben
    - `i18n/messages.test.ts`: neue Schlüssel in `de` und `en` nicht leer
    - _Requirements: 6.1, 9.12_

  - [x] 4.6 Router, Header und Footer anpassen
    - `RouteName.Home` (`/`, `HomeView` als Platzhalter-Import auf die später angelegte View), `RouteName.Search` auf `/search`
    - `AppHeader.vue`: genau zwei Einträge Start und Suche; `AppFooter.vue`: `search.randomQuote` verweist auf `RouteName.Home`
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.8, 8.1_

  - [x]* 4.7 Property-Test für Routenauflösung
    - **Property 15: Suchverknüpfungen erhalten die Query-Parameter, die Startseite ignoriert sie**
    - Datei `frontend/src/router/homeSearchRoutes.property.test.ts`
    - **Validates: Requirements 5.8, 5.10**

  - [x] 4.8 Bestehende Tests auf `/search` bzw. Startseite anpassen
    - `router/index.test.ts`, `SearchView.test.ts`, `AppHeader.test.ts`, ggf. `routeSet.property.test.ts`, `KeyboardReachability.property.test.ts`
    - Tests nicht löschen, überspringen oder abschwächen
    - _Requirements: 5.9, 8.1, 8.3, 8.5_

  - [x]* 4.9 Neue Tests für Router, Header und Footer schreiben
    - `/` ⇒ Home, `/search` ⇒ Search, Aktiv-Markierung genau eines Eintrags, Footer-Ziel `/`
    - _Requirements: 5.2, 5.3, 5.4, 5.5, 5.6, 5.10_

- [x] 5. Startseite mit zufälligem Zitat
  - [x] 5.1 Composable `useRandomQuote` implementieren
    - `frontend/src/composables/useRandomQuote.ts` mit Zustand, `reload()`, `dispose()`
    - Eine Anfrage gleichzeitig, `AbortController` mit 10-s-Timeout, laufende Nummer gegen veraltete Antworten, Locale pro Aufruf frisch lesen
    - `NO_QUOTES` ⇒ Leerzustand, sonstige Fehler ⇒ Fehlerzustand, Abbruch durch Unmount ohne Meldung
    - _Requirements: 1.2, 1.7, 1.8, 2.2, 2.3, 2.5, 3.4, 3.5, 3.6, 4.1, 4.2, 4.4, 4.5, 4.6, 6.11, 6.12_

  - [x]* 5.2 Property-Test für den Zustandsautomaten
    - **Property 8: Zustandsautomat der Startseite**
    - Datei `frontend/src/composables/useRandomQuote.state.property.test.ts`
    - **Validates: Requirements 2.3, 2.5, 3.3, 3.4, 4.4, 4.5, 4.6**

  - [x]* 5.3 Property-Test für das angezeigte Zitat
    - **Property 9: Das angezeigte Zitat stammt aus der letzten Antwort**
    - Datei `frontend/src/composables/useRandomQuote.latest.property.test.ts`
    - **Validates: Requirements 1.7**

  - [x]* 5.4 Property-Test für Fehleranzeige
    - **Property 11: Jeder Fehler außer NO_QUOTES führt zur Fehleranzeige mit Wiederholungsmöglichkeit**
    - Datei `frontend/src/views/HomeView.errors.property.test.ts`
    - **Validates: Requirements 4.2, 4.3**

  - [x]* 5.5 Property-Test für die übergebene Oberflächensprache
    - **Property 13: Die Anfrage trägt die aktuelle Oberflächensprache**
    - Datei `frontend/src/composables/useRandomQuote.locale.property.test.ts`
    - **Validates: Requirements 6.11, 6.12**

  - [x] 5.6 `HomeView.vue` implementieren und verdrahten
    - `<h1>`, immer gerenderte Live-Region (`aria-live="polite"`), `figure`/`blockquote`/`figcaption`, Links auf `/sources/{id}` und `/quotes/{id}`
    - Ladezustand (`role="status"`), Leerzustand, Fehler (`role="alert"`), Nachladen-Schaltfläche (deaktiviert bei `pending`), genau ein Link auf `RouteName.Search`
    - SCSS nur über Design-Tokens, Fokusindikator ≥ 2 px, `overflow-wrap: anywhere`; Router-Eintrag aus 4.6 auf die View zeigen lassen
    - _Requirements: 1.1, 1.3, 1.4, 1.5, 1.6, 1.9, 2.1, 2.4, 3.2, 3.3, 4.1, 4.3, 5.7, 6.1, 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.8, 7.9_

  - [x]* 5.7 Property-Test für die Zitat-Darstellung
    - **Property 10: Vollständige Darstellung und Verknüpfungen eines Zitats**
    - Datei `frontend/src/views/HomeView.render.property.test.ts`
    - **Validates: Requirements 1.4, 1.5, 1.6**

  - [x]* 5.8 Property-Test für den Sprachwechsel
    - **Property 12: Der Sprachwechsel verändert den Zustand der Startseite nicht**
    - Datei `frontend/src/views/HomeView.locale.property.test.ts`
    - **Validates: Requirements 6.2, 6.3, 6.4**

  - [x]* 5.9 Beispieltests für `HomeView` schreiben
    - `HomeView.test.ts` mit Test-Double des Clients: Erfolg, Laden, `NO_QUOTES`, Fehler, Nachladen, Timeout (Fake-Timer), Abbruch bei Unmount
    - Struktur: ein `<h1>`, `aria-live`, `role="status"`/`role="alert"`, genau eine Such-Verknüpfung
    - _Requirements: 1.2, 1.3, 1.8, 1.9, 2.2, 3.5, 3.6, 8.4_

  - [x]* 5.10 Statische Stiltests um `HomeView.vue` erweitern
    - `tokenConsistency`, `elementConsistency`, `contrast`, `focusContrast`, `MobileOverflow`
    - _Requirements: 7.1, 7.6, 7.7, 7.8, 7.9_

- [x] 6. Zuordnung in der Sprachverwaltung
  - [x] 6.1 `LanguageManager.vue` und `AdminView.vue` erweitern
    - `AdminView` lädt `getLanguageMappings()` mit den Sprachen und reicht `mappings` weiter
    - Je Oberflächensprache ein `<select>` mit „keine Zuordnung“ und allen Sprachen, gespeicherte Zuordnung vorausgewählt
    - Bei `change` speichern oder entfernen; Erfolg ⇒ Bestätigung (`role="status"`) und Neuladen; Fehler ⇒ `role="alert"` und Auswahl zurücksetzen; `UNAUTHORIZED` ⇒ `unauthorized`
    - _Requirements: 9.9, 9.10, 9.11, 9.12_

  - [x]* 6.2 Property-Test für die Auswahl der Sprachverwaltung
    - **Property 16: Auswahl der Sprachverwaltung**
    - Datei `frontend/src/components/admin/LanguageManager.mapping.property.test.ts`
    - **Validates: Requirements 9.9**

  - [x]* 6.3 Unit-Tests für `LanguageManager` schreiben
    - Speichern, Bestätigung, Fehler mit Zurücksetzen, Entfernen
    - _Requirements: 9.10, 9.11_

- [x] 7. Abschluss-Checkpoint
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Mit `*` markierte Sub-Tasks sind optional und können für ein schnelleres MVP übersprungen werden.
- Jede Aufgabe verweist auf konkrete Anforderungen zur Nachverfolgbarkeit.
- Property-Tests prüfen universelle Eigenschaften, Unit-Tests konkrete Beispiele und Randfälle.
- Ausführung: `cd api && composer test`, `cd frontend && npm test` und `npm run typecheck`.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.3", "4.1", "4.3"] },
    { "id": 1, "tasks": ["1.2", "1.4", "1.5", "4.2", "4.4", "4.5", "4.6"] },
    { "id": 2, "tasks": ["1.6", "2.1", "2.8", "4.7", "4.8", "5.1", "6.1"] },
    { "id": 3, "tasks": ["2.2", "2.3", "2.4", "2.5", "2.9", "2.10", "2.11", "4.9", "5.2", "5.3", "5.4", "5.5", "5.6", "6.2", "6.3"] },
    { "id": 4, "tasks": ["2.6", "2.7", "2.12", "2.13", "5.7", "5.8", "5.9", "5.10"] }
  ]
}
```
