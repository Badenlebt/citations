# Implementation Plan: citations-management

## Overview

Dieser Plan setzt das Zitate-System inkrementell um: Datenbank-Schema und
Normalform-Grundlagen → Repository-Schnittstellen mit In-Memory-Test-Doubles →
seiteneffektfreie Logik (Normalisierung, Tokenisierung, Scoring, Validierung,
Pagination, Session-/Rate-Limit, Zufallsauswahl) → Mutationsoperationen →
HTTP-Endpunkte (Such-, Zitat-, Autoren-, Sprach-, Auth-API) → Session-Guard →
Vue-3-Frontend (mehrsprachig, längenbegrenzte Felder, Lösch-Entscheidungs-Dialog) →
`.htaccess`-Routing, sichere Credential-Ablage und Deployment-Build.

Die Logik- und Mutationsschicht wird property-basiert getestet (Properties 1–46 aus
`design.md`, PHP-PBT-Bibliothek, ≥ 100 Iterationen, Tag
`Feature: citations-management, Property N: ...`). I/O, Routing, UI und Betrieb werden
über Beispiel-, Integrations-, Komponenten- und Smoke-Tests abgedeckt.

Backend-Sprache: PHP (PDO/MariaDB). Frontend-Sprache: TypeScript/Vue 3. Die Sprachen
sind im Design festgelegt; es ist keine Sprachauswahl nötig.

## Tasks

- [ ] 1. Projektstruktur, Normalform und Fehlermodell
  - [ ] 1.1 Projekt- und Verzeichnisstruktur sowie Testframeworks aufsetzen
    - `api/` (PHP-Quellen: `src/`, `tests/`), `frontend/` (Vue-3-Projekt) und
      `db/` (Schema/Seed) anlegen
    - PHP: Composer-Projekt mit PHPUnit und einer Property-Testing-Bibliothek
      (z. B. `giorgiosironi/eris`) einrichten; Property-Tests laufen mit ≥ 100 Iterationen
    - Frontend: Vue-3-Projekt (Vite, TypeScript) mit Komponenten-Testrunner (Vitest)
    - _Requirements: 16.1, 16.2_

  - [ ] 1.2 Normalform-Funktion und Tokenisierung implementieren
    - Reine Funktion: Unicode-Dekomposition, Entfernen kombinierender diakritischer
      Zeichen, Kleinschreibung; identisch auf gespeicherte Werte und Sucheingabe anwendbar
    - Reine Funktion: Zerlegung einer Sucheingabe anhand von Leerzeichen in Suchbegriffe
    - _Requirements: 2.6_

  - [ ]* 1.3 Property-Test für Case-/Diakritika-Invarianz der Normalform
    - **Property 12: Scoring ist case- und diakritika-invariant** (Normalform-Anteil:
      gleiche Normalform ⇒ gleiche Tokens/Vergleichsbasis)
    - **Validates: Requirements 2.6**

  - [ ] 1.4 Fehlercode-Modell und Status-Mapping implementieren
    - Zentrale Definition aller Fehlercodes und ihrer HTTP-Status gemäß Design-Tabelle
      (`MISSING_QUERY`/400 … `INTERNAL_ERROR`/500), einheitliches JSON-Format
      `{ error, message }` ohne interne Details
    - _Requirements: 15.7_

- [ ] 2. Datenbank-Schema und Repository-Schnittstellen
  - [ ] 2.1 MariaDB-Schema und Seed für die Unbekannt-Quelle erstellen
    - Tabellen `source`, `language`, `quote`, `operator`, `session`, `login_attempt`
      mit Spalten, Typen, FKs und UNIQUE-Constraints gemäß Data Models
    - `source.is_unknown` sowie Seed genau einer Zeile `is_unknown = 1`, `name = 'unbekannt'`;
      `quote.text_normalized`, `language.name_normalized`
    - _Requirements: 12.1, 13.1, 16.2_

  - [ ] 2.2 Repository-Interfaces definieren
    - Interfaces für Quote-, Source-, Language-, Operator-, Session- und
      LoginAttempt-Repository (Lese- und Mutationsmethoden), sodass PDO- und
      In-Memory-Implementierung austauschbar sind
    - _Requirements: 8.1, 9.1, 10.1, 11.1, 12.3_

  - [ ] 2.3 In-Memory-Repository-Implementierungen als Test-Doubles erstellen
    - Interface-identische In-Memory-Repositories für property-basierte und
      Beispieltests der Mutationslogik ohne echte DB
    - Unbekannt-Quelle vorinitialisiert; FK-/Referenzverhalten nachgebildet
    - _Requirements: 13.1_

- [ ] 3. Reine Suchlogik: Matching, Scoring, Filter, Pagination
  - [ ] 3.1 Matching von Suchbegriffen gegen Zitattext und Quellenname implementieren
    - Reine Funktion: Ein Zitat ist Treffer, wenn normalisierter Zitattext ODER
      normalisierter Quellenname mindestens einen normalisierten Suchbegriff als
      Teilzeichenkette enthält; Geburts-/Sterbedatum und Kurzbeschreibung ausgeschlossen
    - _Requirements: 1.1, 1.2, 1.3, 1.4_

  - [ ]* 3.2 Property-Test für Teiltreffer-Fund
    - **Property 1: Teiltreffer in Zitattext oder Quellenname werden gefunden**
    - **Validates: Requirements 1.1, 1.2**

  - [ ]* 3.3 Property-Test für Feldausschluss beim Matching
    - **Property 2: Treffer entstehen ausschließlich durch Zitattext oder Quellenname**
    - **Validates: Requirements 1.3, 1.4**

  - [ ] 3.4 Sprachfilter in der Suchlogik implementieren
    - Nicht leerer Sprachfilter schränkt auf zugeordnete Sprachen ein; fehlender/leerer
      Filter ist sprachneutral; Filter ohne vorhandene Sprache ⇒ leere Treffermenge
    - _Requirements: 1.10, 1.11, 1.12_

  - [ ]* 3.5 Property-Test für Sprachfilter-Einschränkung
    - **Property 7: Sprachfilter schränkt Ergebnisse auf gewählte Sprachen ein**
    - **Validates: Requirements 1.10**

  - [ ]* 3.6 Property-Test für Sprachneutralität ohne Filter
    - **Property 8: Fehlender oder leerer Sprachfilter ist sprachneutral**
    - **Validates: Requirements 1.11**

  - [ ] 3.7 Relevanz-Scoring implementieren
    - Je Zitat genau ein Relevanzwert; Trefferart-Hierarchie (eigenständiges Wort >
      Wortanfang > Infix-Teiltreffer); mehr getroffene Suchwörter erhöhen den Wert;
      Text- und Quellenname-Treffer fließen gemeinsam ein; case-/diakritika-invariant
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6_

  - [ ]* 3.8 Property-Test für Trefferart-Hierarchie
    - **Property 9: Trefferart-Hierarchie beim Relevanz-Scoring**
    - **Validates: Requirements 2.2, 2.3**

  - [ ]* 3.9 Property-Test für Mehrwort-Treffer
    - **Property 10: Mehr getroffene Suchwörter erhöhen den Relevanzwert**
    - **Validates: Requirements 2.4**

  - [ ]* 3.10 Property-Test für gemeinsamen Beitrag von Text und Quellenname
    - **Property 11: Treffer in Text und Quellenname fließen gemeinsam ein**
    - **Validates: Requirements 2.5**

  - [ ]* 3.11 Property-Test für Case-/Diakritika-Invarianz des Scorings
    - **Property 12: Scoring ist case- und diakritika-invariant**
    - **Validates: Requirements 2.6**

  - [ ] 3.12 Deterministische Sortierung mit Tie-Break implementieren
    - Absteigend nach Relevanzwert; bei Gleichstand eindeutig und wiederholbar nach
      aufsteigender Zitat-ID
    - _Requirements: 1.5, 2.1, 2.7_

  - [ ]* 3.13 Property-Test für absteigende Sortierung
    - **Property 3: Ergebnisse sind absteigend nach Relevanzwert sortiert**
    - **Validates: Requirements 1.5, 2.1**

  - [ ]* 3.14 Property-Test für wiederholbare Tie-Break-Reihenfolge
    - **Property 13: Wiederholbare, eindeutige Reihenfolge bei Relevanzgleichstand**
    - **Validates: Requirements 2.7**

  - [ ] 3.15 Pagination-Berechnung implementieren
    - Seitengröße 1–100 (Standard 20), Seitennummer Standard 1; Ausschnitt der
      sortierten Gesamtliste; Gesamtanzahl gemeldet; Seite jenseits der letzten Seite
      ⇒ leere Liste bei unveränderter Gesamtanzahl
    - _Requirements: 1.6, 1.7, 1.8_

  - [ ]* 3.16 Property-Test für korrekten Seitenausschnitt
    - **Property 4: Pagination schneidet die sortierte Gesamtliste korrekt**
    - **Validates: Requirements 1.6**

  - [ ]* 3.17 Property-Test für Seiten jenseits der letzten Seite
    - **Property 5: Seiten jenseits der letzten Seite sind leer bei unveränderter Gesamtanzahl**
    - **Validates: Requirements 1.8**

  - [ ] 3.18 Sucheingabe-Validierung implementieren
    - Leere/reine-Whitespace-Sucheingabe ⇒ `MISSING_QUERY` ohne Suchausführung;
      Sucheingabe über 200 Zeichen ⇒ `QUERY_TOO_LONG` vor der Zerlegung
    - _Requirements: 1.9, 1.13_

  - [ ]* 3.19 Property-Test für Abweisung leerer Sucheingabe
    - **Property 6: Leere oder reine Whitespace-Sucheingabe wird abgewiesen**
    - **Validates: Requirements 1.9**

  - [ ]* 3.20 Property-Test für Abweisung überlanger Sucheingabe
    - **Property 45: Überlange Sucheingabe wird abgewiesen**
    - **Validates: Requirements 1.13**

- [ ] 4. Zufallsauswahl mit injizierbarem RNG
  - [ ] 4.1 Reinen Zufallsauswahl-Algorithmus implementieren
    - Auswahl eines Index aus [0, n) bei n Zitaten anhand eines injizierten Zufallswerts;
      jeder Index grundsätzlich erreichbar; leerer Bestand wird vom Endpunkt als
      `NO_QUOTES` behandelt
    - _Requirements: 3.2, 3.3_

  - [ ]* 4.2 Property-Test für gültigen Bereich und Vollständigkeit der Auswahl
    - **Property 14: Zufallsauswahl bleibt im gültigen Bereich und ist vollständig erreichbar**
    - **Validates: Requirements 3.2**

- [ ] 5. Checkpoint - Suchlogik verifizieren
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 6. Authentifizierungs- und Session-Logik (rein)
  - [ ] 6.1 Passwort-Hashing-Wrapper implementieren
    - `password_hash()` mit `PASSWORD_DEFAULT` und `password_verify()`; Klartext wird
      nicht gespeichert und nicht zurückgegeben
    - _Requirements: 6.7_

  - [ ]* 6.2 Property-Test für Passwort-Hash-Round-Trip
    - **Property 20: Passwort-Hash-Round-Trip**
    - **Validates: Requirements 6.7**

  - [ ] 6.3 Zugangsdaten-Validierung und generische Fehlerprüfung implementieren
    - Fehlende/Whitespace-Zugangsdaten ⇒ `MISSING_CREDENTIALS` ohne Passwortprüfung;
      ungültige Daten ⇒ generisches `INVALID_CREDENTIALS`; laufzeit-ausgeglichener
      Prüfpfad (Dummy-Hash-Vergleich bei unbekannter Benutzerkennung)
    - _Requirements: 6.2, 6.3, 6.10_

  - [ ]* 6.4 Property-Test für Abweisung fehlender Zugangsdaten
    - **Property 16: Fehlende oder Whitespace-Zugangsdaten werden ohne Passwortprüfung abgewiesen**
    - **Validates: Requirements 6.3**

  - [ ]* 6.5 Property-Test für generische, ununterscheidbare Fehlerantwort
    - **Property 15: Generische, ununterscheidbare Fehlerantwort bei ungültigen Zugangsdaten**
    - **Validates: Requirements 6.2**

  - [ ] 6.6 Session-Ablauf- und Rate-Limit-Logik implementieren
    - Session gültig genau dann, wenn Prüfzeitpunkt < (Erstellungszeitpunkt + Dauer),
      Dauer auf [1, 168] h begrenzt (Standard 24); Rate-Limit: 5 Fehlversuche je
      Benutzerkennung in 15 min ⇒ 15 min Sperre ab dem fünften Fehlversuch
    - _Requirements: 6.1, 6.4, 6.5_

  - [ ]* 6.7 Property-Test für Session-Gültigkeit nach Ablaufdauer
    - **Property 17: Session-Gültigkeit folgt der konfigurierten Ablaufdauer**
    - **Validates: Requirements 6.1, 6.4**

  - [ ]* 6.8 Property-Test für Rate-Limit-Sperre
    - **Property 18: Rate-Limit sperrt nach fünf Fehlversuchen für 15 Minuten**
    - **Validates: Requirements 6.5**

  - [ ] 6.9 Token-Klassifikation und Logout-Logik implementieren
    - Klassifikation gemäß 6.11 (fehlend/ungültig/nicht zuordenbar/abgelaufen/beendet
      ⇒ nicht authentifiziert); Logout entfernt die Session dauerhaft
    - _Requirements: 6.6, 6.11_

  - [ ]* 6.10 Property-Test für dauerhafte Ungültigkeit nach Logout
    - **Property 19: Logout macht die Session dauerhaft ungültig**
    - **Validates: Requirements 6.6**

- [ ] 7. Zitat-Mutationslogik (gegen Repository)
  - [ ] 7.1 Zitat-Anlegen implementieren
    - Getrimmter Text 1–5000 Zeichen, vorhandene Quelle, vorhandene Sprache; fehlende
      Quellenauswahl ⇒ Unbekannt-Quelle; keine neue Quelle; Rückgabe der Kennung
    - _Requirements: 8.1, 8.3, 8.4, 8.5, 13.3_

  - [ ]* 7.2 Property-Test für Zitat-Anlegen-Round-Trip
    - **Property 23: Zitat-Anlegen-Round-Trip**
    - **Validates: Requirements 8.1**

  - [ ]* 7.3 Property-Test für keine neue Quelle beim Anlegen
    - **Property 26: Zitat-Anlegen erzeugt keine neue Quelle**
    - **Validates: Requirements 8.5**

  - [ ]* 7.4 Property-Test für Unbekannt-Quelle ohne Quellenauswahl
    - **Property 44: Zitat ohne Quellenauswahl erhält die Unbekannt-Quelle**
    - **Validates: Requirements 13.3**

  - [ ] 7.5 Zitat-Bearbeiten und -Löschen implementieren
    - Bearbeiten speichert gültige Werte; Löschen entfernt genau das Zitat;
      nicht vorhandenes Zitat ⇒ Nicht-gefunden, kein Datenstand verändert
    - _Requirements: 9.1, 9.4, 9.5_

  - [ ]* 7.6 Property-Test für Zitat-Bearbeiten-Round-Trip
    - **Property 27: Zitat-Bearbeiten-Round-Trip**
    - **Validates: Requirements 9.1**

  - [ ]* 7.7 Property-Test für gezieltes Zitat-Löschen
    - **Property 28: Zitat-Löschen entfernt genau das Zitat**
    - **Validates: Requirements 9.4**

  - [ ]* 7.8 Property-Test für Operationen auf nicht vorhandenen Zitaten
    - **Property 29: Operationen auf nicht vorhandenen Zitaten ändern nichts**
    - **Validates: Requirements 9.5**

  - [ ] 7.9 Zitat-Eingabevalidierung (Text, Quelle, Sprache, Länge) implementieren
    - Trim-leerer Text ⇒ `MISSING_TEXT`; ungültige Quelle/Sprache ⇒
      `INVALID_SOURCE`/`INVALID_LANGUAGE`; Text nach Trim über 5000 ⇒ `INPUT_TOO_LONG`;
      jeweils ohne Zustandsänderung (Anlegen und Bearbeiten)
    - _Requirements: 8.2, 8.3, 8.4, 8.6, 9.2, 9.3, 9.6_

  - [ ]* 7.10 Property-Test für ungültigen Zitattext ohne Zustandsänderung
    - **Property 24: Ungültiger Zitattext verhindert jede Zustandsänderung**
    - **Validates: Requirements 8.2, 9.2**

  - [ ]* 7.11 Property-Test für ungültige Quelle/Sprache ohne Zustandsänderung
    - **Property 25: Ungültige Quelle oder Sprache verhindert Anlegen bzw. Änderung**
    - **Validates: Requirements 8.3, 8.4, 9.3**

- [ ] 8. Quellen-Mutationslogik und Konfliktbehandlung (gegen Repository)
  - [ ] 8.1 Quellen-Anlegen und -Bearbeiten implementieren
    - Getrimmter Name 1–200 Zeichen; optionale Freitextfelder 0–500; Link-URL 0–2000
      und, falls gesetzt, `http(s)://`-Präfix; Rückgabe der Kennung beim Anlegen
    - _Requirements: 10.1, 10.3, 10.4, 10.6_

  - [ ] 8.2 Quellen-Validierung (Name, Optionalfelder, Link-URL, Länge) implementieren
    - Trim-leerer Name ⇒ `MISSING_NAME`; Link-URL ohne `http(s)://` ⇒
      `INVALID_LINK_URL`; Überlänge je Feld ⇒ `INPUT_TOO_LONG`; jeweils ohne
      Zustandsänderung
    - _Requirements: 10.2, 10.3, 10.4, 10.5, 10.7_

  - [ ]* 8.3 Property-Test für Quellen-Anlegen/Bearbeiten-Round-Trip
    - **Property 30: Quellen-Anlegen- und Bearbeiten-Round-Trip**
    - **Validates: Requirements 10.1, 10.6**

  - [ ]* 8.4 Property-Test für ungültigen Quellennamen
    - **Property 31: Ungültiger Quellenname verhindert jede Zustandsänderung**
    - **Validates: Requirements 10.2**

  - [ ]* 8.5 Property-Test für Längengrenzen der Optionalfelder
    - **Property 32: Längengrenzen der optionalen Freitextfelder**
    - **Validates: Requirements 10.3**

  - [ ]* 8.6 Property-Test für Link-URL-Validierung
    - **Property 33: Validierung der Link-URL**
    - **Validates: Requirements 10.4, 10.5**

  - [ ] 8.7 Quellen-Löschen mit Strategie reassign/cancel implementieren
    - Ohne Zitate ⇒ entfernen; mit Zitaten ohne Strategie ⇒ `SOURCE_HAS_QUOTES`,
      nichts geändert; `reassign` ⇒ Zitate der Unbekannt-Quelle zuordnen, dann Quelle
      entfernen (eine Transaktion); `cancel` ⇒ nichts ändern
    - _Requirements: 11.1, 11.2, 11.3, 11.4_

  - [ ]* 8.8 Property-Test für Löschen einer Quelle ohne Zitate
    - **Property 34: Quelle ohne Zitate löschen entfernt die Quelle**
    - **Validates: Requirements 11.1**

  - [ ]* 8.9 Property-Test für angehaltenes Löschen ohne Strategie
    - **Property 35: Löschen einer Quelle mit Zitaten ohne Strategie ändert nichts**
    - **Validates: Requirements 11.2**

  - [ ]* 8.10 Property-Test für Umhängen auf die Unbekannt-Quelle
    - **Property 36: Umhängen bewahrt alle Zitate und entfernt die Quelle**
    - **Validates: Requirements 11.3**

  - [ ]* 8.11 Property-Test für Löschabbruch
    - **Property 37: Löschabbruch lässt alles unverändert**
    - **Validates: Requirements 11.4**

  - [ ] 8.12 Unbekannt-Quelle-Invarianten durchsetzen
    - Unbekannt-Quelle ist unlöschbar ⇒ `UNKNOWN_SOURCE_UNDELETABLE`; genau eine
      Unbekannt-Quelle bleibt über alle Operationen erhalten; Name "unbekannt"
      (case-insensitive) reserviert ⇒ `UNKNOWN_SOURCE_RESERVED`
    - _Requirements: 11.5, 13.1, 13.2_

  - [ ]* 8.13 Property-Test für Unlöschbarkeit der Unbekannt-Quelle
    - **Property 38: Die Unbekannt-Quelle ist unlöschbar**
    - **Validates: Requirements 11.5**

  - [ ]* 8.14 Property-Test für genau eine Unbekannt-Quelle
    - **Property 42: Genau eine Unbekannt-Quelle bleibt erhalten**
    - **Validates: Requirements 13.1**

  - [ ]* 8.15 Property-Test für Reservierung des Namens "unbekannt"
    - **Property 43: Der Name "unbekannt" ist reserviert**
    - **Validates: Requirements 13.2**

- [ ] 9. Sprach-Mutationslogik (gegen Repository)
  - [ ] 9.1 Sprach-Anlegen und -Validierung implementieren
    - Getrimmte Bezeichnung 1–100 Zeichen; trim-leer ⇒ `MISSING_LANGUAGE_NAME`;
      Normalform bereits vorhanden ⇒ `LANGUAGE_EXISTS`; über 100 Zeichen ⇒
      `INPUT_TOO_LONG`; nach Anlegen in verfügbarer Sprachmenge enthalten
    - _Requirements: 12.3, 12.4, 12.5, 12.6_

  - [ ]* 9.2 Property-Test für Sprach-Anlegen-Round-Trip
    - **Property 39: Sprach-Anlegen-Round-Trip**
    - **Validates: Requirements 12.3**

  - [ ]* 9.3 Property-Test für ungültige Sprachbezeichnung
    - **Property 40: Ungültige Sprachbezeichnung wird abgewiesen**
    - **Validates: Requirements 12.4**

  - [ ]* 9.4 Property-Test für case-insensitive Eindeutigkeit der Sprachen
    - **Property 41: Sprachen sind case-insensitiv eindeutig**
    - **Validates: Requirements 12.5**

  - [ ]* 9.5 Property-Test für feldübergreifende Längenbegrenzung
    - **Property 46: Überlange längenbegrenzte Eingaben werden ohne Zustandsänderung abgewiesen**
    - **Validates: Requirements 8.6, 9.6, 10.7, 12.6**

- [ ] 10. Checkpoint - Mutations- und Auth-Logik verifizieren
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 11. PDO-Repositories und Datenbankverbindung
  - [ ] 11.1 PDO-Verbindung mit sicherer Credential-Quelle implementieren
    - Zugangsdaten (Host/Adresse, Port, Datenbankname, Benutzername, Passwort) aus
      `config.php` oberhalb des Web-Roots bzw. aus Umgebungsvariablen; keine fest
      eingebetteten Zugangsdaten
    - Fehlt beim Anfragestart eines der Felder Host/Adresse, Datenbankname,
      Benutzername oder Passwort ⇒ kein Connect, Abbruch, generische
      `INTERNAL_ERROR`-Antwort ohne Zugangsdaten-Werte
    - Ist kein Port konfiguriert ⇒ Standard-Port der MariaDB-Datenbank verwenden
    - Fehlende/unlesbare Credential-Quelle ⇒ kein Connect, Abbruch, generische
      `INTERNAL_ERROR`-Antwort ohne Zugangsdaten-Werte und ohne Angabe von Pfad/Name
      der Quelle
    - _Requirements: 15.1, 15.2, 15.3, 15.6_

  - [ ] 11.2 PDO-Repository-Implementierungen erstellen
    - Interface-identische PDO-Repositories (Quote, Source, Language, Operator,
      Session, LoginAttempt) mit vorbereiteten Anweisungen; Normalform-Spalten
      beim Schreiben befüllt
    - _Requirements: 16.2_

  - [ ] 11.3 PDO-Zufallsauswahl an den reinen Algorithmus anbinden
    - Zufallszitat über injizierten RNG + reinen Auswahl-Algorithmus gegen den
      PDO-Bestand; genau ein Zitat mit Text, Sprache und Quelle
    - _Requirements: 3.1_

  - [ ]* 11.4 Integrationstests für PDO-I/O-Pfad
    - Zufallsauswahl liefert über viele Aufrufe verschiedene Zitate (3.2 end-to-end);
      PHP-API ↔ MariaDB; fehlendes Port-Feld ⇒ Standard-MariaDB-Port (15.3);
      fehlendes Zugangsdaten-Feld bzw. fehlende/unlesbare Credential-Quelle und
      erzwungener interner/DB-Fehler ⇒ generische 500 ohne Zugangsdaten/SQL/Stacktraces
      und ohne Änderung gespeicherter Daten
    - _Requirements: 3.2, 15.2, 15.3, 15.6, 15.7, 16.2_

  - [ ] 11.5 Initialen Betreiber-Account aus der Einrichtungskonfiguration seeden
    - Benutzername und Passwort-Hash ausschließlich aus der Einrichtungskonfiguration
      (oberhalb des Web-Roots bzw. Umgebungsvariablen) beziehen; kein Klartext-Passwort
      erwarten oder speichern
    - Existiert kein Betreiber-Account und enthält die Konfiguration Benutzername und
      Hash ⇒ genau einen Account mit diesen Werten anlegen; existiert bereits ein
      Account ⇒ keinen weiteren anlegen, vorhandenen unverändert lassen (idempotent)
    - Fehlt/leer Benutzername oder Hash bei leerer Account-Tabelle ⇒ keinen Account
      anlegen (Anmeldeversuch wird über den `INVALID_CREDENTIALS`-Pfad verweigert)
    - _Requirements: 17.1, 17.2, 17.3, 17.4, 17.5_

  - [ ]* 11.6 Beispiel-/Integrationstests für die initiale Account-Einrichtung
    - Idempotentes Seeden: Anlegen bei leerer Account-Tabelle (17.2) und
      Unverändertheit bei bereits vorhandenem Account (17.3)
    - Fehlender initialer Account ⇒ Anmeldeversuch verweigert (17.5, über
      `INVALID_CREDENTIALS`); Beispiel-/Integrationstests, keine Property-Tests
    - _Requirements: 17.2, 17.3, 17.5_

- [ ] 12. Front-Controller, Session-Guard und HTTP-Endpunkte
  - [ ] 12.1 Front-Controller und Request-Dispatch implementieren
    - `api/index.php` parst Methode/Pfad, dispatcht auf Such-, Zitat-, Autoren-,
      Sprach- und Auth-API; JSON-Serialisierung; zentrales Fehler-Mapping;
      unerwartete Fehler ⇒ generische `INTERNAL_ERROR` ohne Zugangsdaten/SQL/Stacktraces
    - _Requirements: 15.7, 16.3_

  - [ ] 12.2 Session-Guard-Middleware implementieren
    - Vor jeder inhaltlichen Validierung schreibender Endpunkte; Token-Klassifikation
      (6.11); nicht authentifiziert ⇒ `UNAUTHORIZED`, keine Datenänderung, Antwort
      unabhängig von Ressourcenexistenz; lesende Endpunkte ohne Session erreichbar
    - _Requirements: 7.1, 7.2, 7.3, 7.5, 7.6_

  - [ ]* 12.3 Property-Test für Schreibschutz ohne gültige Session
    - **Property 21: Schreibzugriff ohne gültige Session verändert keine Daten**
    - **Validates: Requirements 6.11, 7.1, 7.2**

  - [ ]* 12.4 Property-Test für öffentliche lesende Endpunkte
    - **Property 22: Lesende Endpunkte sind ohne Session erreichbar**
    - **Validates: Requirements 7.3, 12.7**

  - [ ] 12.5 Such-API-Endpunkte anbinden (GET /api/search, GET /api/random)
    - Suchlogik + Pagination + Sprachfilter + `sourceId`-Einschränkung; Antwortformat
      `{ items, total, page, pageSize }`; Zufallszitat bzw. `NO_QUOTES`
    - _Requirements: 1.1, 1.5, 1.6, 1.7, 1.8, 3.1, 3.3, 5.3_

  - [ ] 12.6 Zitat-API-Endpunkte anbinden (GET/POST/PUT/DELETE /api/quotes)
    - Einzelansicht-Mapping inkl. `linkUrl`; geschützte Mutationen über Guard +
      Mutationslogik; Statuscodes 200/201/400/401/404
    - _Requirements: 4.1, 4.5, 7.4, 8.1, 9.1, 9.4, 9.5_

  - [ ]* 12.7 Beispieltests für Zitat- und Zufallsantworten
    - Zitat-Einzelansicht-Mapping (4.1), Zufallszitat-Struktur (3.1), Schreibzugriff
      mit gültiger Session (7.4)
    - _Requirements: 3.1, 4.1, 7.4_

  - [ ] 12.8 Autoren-API-Endpunkte anbinden (GET/POST/PUT/DELETE /api/sources)
    - Einzelansicht-Mapping inkl. leerer Optionalfelder; geschützte Mutationen;
      Löschen mit `strategy`-Parameter (reassign/cancel) und Konflikt `SOURCE_HAS_QUOTES`;
      Statuscodes 200/201/400/401/404/409
    - _Requirements: 5.1, 5.2, 5.4, 10.1, 10.6, 11.1, 11.2, 11.3, 11.4, 11.5_

  - [ ]* 12.9 Beispieltests für Quellen-Antworten
    - Quellen-Einzelansicht inkl. leerer Optionalfelder (5.1, 5.2)
    - _Requirements: 5.1, 5.2_

  - [ ] 12.10 Sprach-API-Endpunkte anbinden (GET/POST /api/languages)
    - Öffentliche Sprachliste ohne Session (12.7); geschütztes Anlegen über Guard +
      Sprachlogik; dynamisch erweiterbarer Bestand
    - _Requirements: 12.1, 12.2, 12.3, 12.7_

  - [ ]* 12.11 Beispieltests für Sprachbestand
    - Genau eine Sprache je Zitat (12.1), dynamisch erweiterbarer Bestand (12.2)
    - _Requirements: 12.1, 12.2_

  - [ ] 12.12 Auth-API-Endpunkte anbinden (POST /api/auth/login, logout)
    - Login über Validierungsreihenfolge (6.3 → 6.5 → 6.2), Session-Aufbau mit
      HttpOnly-Cookie, konfigurierbare Dauer; Logout beendet Session; mehrere
      Betreiber-Accounts, initial einer
    - _Requirements: 6.1, 6.6, 6.8, 6.9_

  - [ ]* 12.13 Beispieltests für Login und mehrere Accounts
    - Erfolgreicher Login und Session-Aufbau (6.1); mehrere Betreiber-Accounts (6.8);
      strukturell laufzeit-ausgeglichener Prüfpfad bei unbekannter Benutzerkennung (6.10)
    - _Requirements: 6.1, 6.8, 6.10_

- [ ] 13. Checkpoint - API-Endpunkte verifizieren
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 14. Frontend: Grundgerüst, i18n und API-Client
  - [ ] 14.1 Vue-3-SPA-Grundgerüst und clientseitiges Routing aufsetzen
    - Routen für Suchseite, Zitat-Einzelansicht, Quellen-Einzelansicht und
      Admin-Bereich; API-Client für `/api`-Endpunkte inkl. Fehlercode-Auswertung
    - _Requirements: 16.1_

  - [ ] 14.2 Mehrsprachige Oberfläche (DE/EN) mit Browser-Default implementieren
    - DE-/EN-Textkataloge; Sprachumschaltung ändert nur Oberflächentexte, nicht die
      Zitatsprache; erster Aufruf ohne gespeicherte Wahl nutzt bevorzugte
      Browser-Sprache (DE/EN) mit Fallback Deutsch
    - _Requirements: 14.1, 14.2, 14.3, 14.4_

  - [ ]* 14.3 Komponententests für Oberflächensprache
    - Sprachwechsel rendert gewählte Sprache (14.2); Entkopplung UI-/Zitatsprache
      (14.4); Browser-Default mit Fallback Deutsch (14.3, Beispieltests mit
      fingierten Browser-Spracheinstellungen)
    - _Requirements: 14.2, 14.3, 14.4_

  - [ ] 14.4 Längenbegrenzte Eingabefelder mit Zeichenzähler implementieren
    - Eingabebegrenzung und sichtbare Anzeige von aktueller Zeichenanzahl und
      Maximallänge für Sucheingabe (200), Zitattext (5000), Quellenname (200),
      optionale Quellenfelder (500), Link-URL (2000), Sprachbezeichnung (100)
    - _Requirements: 14.5, 14.6_

  - [ ]* 14.5 Komponententests für Eingabebegrenzung und Zeichenzähler
    - Je längenbegrenztem Feld: Begrenzung auf Maximallänge und sichtbare
      Zähler-/Maximalanzeige
    - _Requirements: 14.5, 14.6_

- [ ] 15. Frontend: Ansichten und Lösch-Konflikt-Dialog
  - [ ] 15.1 Such-, Zitat- und Quellen-Ansichten implementieren
    - Suchseite mit Pagination und Sprachfilter; Zitat-Einzelansicht mit Quellenname
      als Verweis auf die Quellenseite und optionalem Link-URL-Verweis; Quellenseite
      mit Verweis auf die quellengefilterte, paginierte Suche
    - _Requirements: 4.2, 4.3, 4.4, 5.3_

  - [ ]* 15.2 Komponententests für Ansichts-Rendering
    - Quellenname als Verweis (4.2); Quellennennung mit/ohne Link-URL (4.3, 4.4);
      Verweis von Quellenseite auf gefilterte Suche (5.3); Snapshot-Tests fürs Layout
    - _Requirements: 4.2, 4.3, 4.4, 5.3_

  - [ ] 15.3 Admin-Oberfläche und Lösch-Entscheidungs-Dialog implementieren
    - Login-/Logout-UI; Anlegen/Bearbeiten/Löschen von Zitaten, Quellen und Sprachen;
      bei `SOURCE_HAS_QUOTES` Dialog mit genau den zwei Varianten Umhängen und
      Löschabbruch, der die gewählte `strategy` an die Autoren-API übermittelt
    - _Requirements: 11.6_

  - [ ]* 15.4 Komponententest für Lösch-Konflikt-UI
    - Genau zwei Varianten (Umhängen/Löschabbruch); korrekte `strategy`-Übermittlung
    - _Requirements: 11.6_

- [ ] 16. Routing, sichere Credentials und Deployment-Build
  - [ ] 16.1 .htaccess-Routing mit SPA-Fallback und Config-Schutz erstellen
    - `/api/*` → `api/index.php`; Nicht-API-Pfade → statische Dateien mit Fallback auf
      `index.html`; direkte Anfragen auf Config-`*.php`-Muster im API-Verzeichnis blocken
    - _Requirements: 15.5, 16.3, 16.4_

  - [ ]* 16.2 Integrationstests für Routing und Credential-Schutz
    - `/api/*` → Front-Controller (16.3); SPA-Fallback (16.4); öffentlicher Zugriff auf
      Credential-Datei liefert keinen Inhalt/keine Werte (15.5)
    - _Requirements: 15.5, 16.3, 16.4_

  - [ ] 16.3 Sichere Credential-Ablage und .gitignore einrichten
    - `config.php`/Credential-Quelle außerhalb des Web-Roots bzw. Umgebungsvariablen;
      `.gitignore` schließt jede Credential-Datei aus; keine Zugangsdaten im Quellcode
    - _Requirements: 15.1, 15.4_

  - [ ]* 16.4 Smoke-/Compliance-Tests für Betrieb und Setup
    - Statischer Build ohne Node.js-Laufzeit auf dem Server (16.1); vollständige
      DE-/EN-Textkataloge (14.1); keine Credential-Datei im Web-Root, keine Secrets im
      Repository, `.gitignore`-Abdeckung (15.1, 15.4)
    - _Requirements: 14.1, 15.1, 15.4, 16.1_

  - [ ] 16.5 CLI-Werkzeug zur Passwort-Hash-Erzeugung implementieren
    - Standalone-CLI-Skript, das aus einem Klartext-Passwort per `password_hash()`
      den für die Einrichtungskonfiguration benötigten Passwort-Hash erzeugt und
      ausgibt, ohne das Klartext-Passwort dauerhaft abzulegen
    - _Requirements: 17.6_

- [ ] 17. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Mit `*` markierte Sub-Tasks sind optional (Tests) und können für ein schnelleres MVP
  übersprungen werden; Kern-Implementierungstasks sind nie optional.
- Jeder Property-Test referenziert exakt eine Property aus `design.md`, läuft mit
  ≥ 100 Iterationen und trägt den Tag
  `Feature: citations-management, Property N: ...`.
- Property-Tests laufen gegen die reine Logik bzw. die Mutationslogik mit
  In-Memory-Repository; I/O, Routing, UI und Betrieb werden über Beispiel-,
  Integrations-, Komponenten- und Smoke-Tests abgedeckt.
- Properties 1–46 sind vollständig als Property-Test-Tasks abgebildet: 1 (3.2),
  2 (3.3), 3 (3.13), 4 (3.16), 5 (3.17), 6 (3.19), 7 (3.5), 8 (3.6), 9 (3.8),
  10 (3.9), 11 (3.10), 12 (1.3/3.11), 13 (3.14), 14 (4.2), 15 (6.5), 16 (6.4),
  17 (6.7), 18 (6.8), 19 (6.10), 20 (6.2), 21 (12.3), 22 (12.4), 23 (7.2),
  24 (7.10), 25 (7.11), 26 (7.3), 27 (7.6), 28 (7.7), 29 (7.8), 30 (8.3),
  31 (8.4), 32 (8.5), 33 (8.6), 34 (8.8), 35 (8.9), 36 (8.10), 37 (8.11),
  38 (8.13), 39 (9.2), 40 (9.3), 41 (9.4), 42 (8.14), 43 (8.15), 44 (7.4),
  45 (3.20), 46 (9.5).
- Jeder Checkpoint stellt inkrementelle Validierung sicher.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["1.2", "1.4"] },
    { "id": 2, "tasks": ["1.3", "2.1", "2.2", "3.1", "3.4", "3.7", "4.1", "6.1", "6.3", "6.6", "6.9"] },
    { "id": 3, "tasks": ["2.3", "3.2", "3.3", "3.5", "3.6", "3.8", "3.9", "3.10", "3.11", "3.12", "3.15", "3.18", "4.2", "6.2", "6.4", "6.5", "6.7", "6.8", "6.10", "16.5"] },
    { "id": 4, "tasks": ["3.13", "3.14", "3.16", "3.17", "3.19", "3.20", "7.1", "7.9", "8.1", "8.2", "8.7", "8.12", "9.1"] },
    { "id": 5, "tasks": ["7.2", "7.3", "7.4", "7.5", "7.10", "7.11", "8.3", "8.4", "8.5", "8.6", "8.8", "8.9", "8.10", "8.11", "8.13", "8.14", "8.15", "9.2", "9.3", "9.4", "9.5"] },
    { "id": 6, "tasks": ["7.6", "7.7", "7.8", "11.1"] },
    { "id": 7, "tasks": ["11.2", "11.3"] },
    { "id": 8, "tasks": ["11.4", "11.5", "12.1", "12.2"] },
    { "id": 9, "tasks": ["11.6", "12.3", "12.4", "12.5", "12.6", "12.8", "12.10", "12.12"] },
    { "id": 10, "tasks": ["12.7", "12.9", "12.11", "12.13", "14.1"] },
    { "id": 11, "tasks": ["14.2", "14.4", "15.1", "15.3", "16.1", "16.3"] },
    { "id": 12, "tasks": ["14.3", "14.5", "15.2", "15.4", "16.2", "16.4"] }
  ]
}
```
