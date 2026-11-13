# Implementation Plan: UI-Redesign

## Overview

Dieser Plan setzt das UI-Redesign des Citations-Frontends (Vue 3 + TypeScript + Vite, getestet mit Vitest + @vue/test-utils) inkrementell und testgetrieben um. Die Umsetzung baut von unten nach oben auf: zuerst die SCSS-Token-Schicht als alleinige Quelle aller Gestaltungswerte, dann die globale Basis/Typografie, dann die Layout-Komponenten (Header/Footer/ScrollToTop/LanguageSwitcher), dann das Restyling aller bestehenden Views ohne Markup-/Logik-/i18n-Änderungen, und zuletzt die eigenschafts-, beispiel- und integrationsbasierten Tests sowie die bewusste Neugenerierung der Snapshots und die Verifikation der Gesamt-Testsuite.

Alle Code-Beispiele und Tests werden in TypeScript/SCSS (Vue 3 SFC) umgesetzt. Property-Tests verwenden `fast-check` mit mindestens 100 Iterationen; jede Korrektheitseigenschaft wird durch genau einen Property-Test abgedeckt und mit dem Kommentar `Feature: ui-redesign, Property {n}: {Text}` annotiert.

## Tasks

- [x] 1. Werkzeug- und SCSS-Token-Schicht aufsetzen
  - [x] 1.1 Dev-Abhängigkeiten `sass` und `fast-check` ergänzen
    - `sass` und `fast-check` in `frontend/package.json` als devDependencies hinzufügen
    - Installation ausführen, damit Vite `.scss` mit `@use`/`@forward` kompiliert
    - _Requirements: 1.1_

  - [x] 1.2 Design-Tokens in `frontend/src/scss/_variables.scss` definieren
    - Alle Tokens als SCSS-Variablen definieren: Farben Hintergrund `#282D37`, Text `#fff`, primary/accent `#C15BEB`, purple-dark `#39107B`, purple-medium `#6C3483`, purple-bright `#e49eff`; Statusfarben success `#56D97E`, error `#FF6B6B`, info `#e49eff`
    - Typografie: `font-family` `'Nunito', sans-serif`, Basisgröße `16px`, body line-height `1.6`, heading weight `700`, heading line-height `1.2`, H1 `2.5rem`…H6 `1rem`
    - Abstandsskala xs `0.25rem` / sm `0.5rem` / md `1rem` / lg `1.5rem` / xl `2rem` / xxl `3rem`; Breakpoints sm `576` / md `768` / lg `992` / xl `1200`; je mind. ein `radius`- und `shadow`-Token
    - Alle Tokens zusätzlich auf `:root` als CSS-Custom-Properties spiegeln (z. B. `--color-primary: #{$color-primary};`)
    - _Requirements: 1.1, 1.2, 1.4, 2.1, 2.4, 3.1, 3.2, 3.3, 7.1_

  - [x] 1.3 Mixins in `frontend/src/scss/_mixins.scss` anlegen
    - `respond-to($breakpoint)` auf Basis der Breakpoint-Skala und `transition($props...)` mit einheitlichem `0.3s ease`
    - _Requirements: 6.2, 7.1_

  - [x] 1.4 Property-Test: Statusfarben sind unterscheidbar
    - **Property 4: Statusfarben sind unterscheidbar**
    - **Validates: Requirements 2.4**

  - [x] 1.5 Property-Test: Kontrastverhältnisse erfüllen WCAG AA
    - **Property 3: Kontrastverhältnisse erfüllen WCAG AA** (WCAG-Formel über relative Luminanz, Schwellen 4,5:1 / 3:1 für alle Paletten-/Statusfarbkombinationen inkl. #fff auf #282D37 und #C15BEB)
    - **Validates: Requirements 2.3, 2.4, 9.1**

- [x] 2. Globale Basis, Typografie und Utilities aufbauen
  - [x] 2.1 `frontend/src/scss/_base.scss` mit Font, Reset und globaler Typografie erstellen
    - `@font-face` für Nunito aus `frontend/public/fonts` mit `font-weight: 200 1000` und `font-display: swap`, `font-family`-Kette endet auf `sans-serif`
    - CSS-Reset, Fließtext (16px / line-height 1.6), H1 2.5rem…H6 1rem (weight 700, line-height 1.2), Link-Gestaltung mit Hover `#C15BEB` und `0.3s ease`, Divider
    - Fallback-Regeln für unbekannte Farbwerte via `var(--color-x, #fff)` / `var(--color-bg-x, #282D37)`
    - Werte ausschließlich über Tokens beziehen
    - _Requirements: 2.2, 2.5, 3.1, 3.2, 3.3, 3.4, 3.5, 6.2_

  - [x] 2.2 `frontend/src/scss/_utilities.scss` mit Container- und Abstands-Hilfsklassen erstellen
    - Container `max-width: 1200px`, zentriert, seitliches Padding md (1rem) / ab 768px lg (1.5rem)
    - Flex- und Abstands-Hilfsklassen ausschließlich aus der Abstandsskala
    - _Requirements: 4.4, 4.6, 7.5_

  - [x] 2.3 `frontend/src/scss/main.scss` als Aggregator anlegen und in `frontend/src/main.ts` einmalig importieren
    - `main.scss` bündelt `_variables`, `_mixins`, `_base`, `_utilities` per `@use`
    - Import genau einmal in `frontend/src/main.ts` neben dem bestehenden createApp/router/i18n-Setup
    - _Requirements: 1.1, 5.1_

  - [x] 2.4 Property-Test: Typografie wird typgerecht aufgelöst
    - **Property 5: Typografie wird typgerecht aufgelöst** (Generator über H1–H6/Body, Vergleich aufgelöster Schriftfamilie/-größe/-höhe/-gewicht mit Token-Vorgaben)
    - **Validates: Requirements 3.4**

  - [x] 2.5 Property-Test: Token-Änderung wirkt konsistent überall
    - **Property 2: Token-Änderung wirkt konsistent überall** (Generator über Token-Wertänderungen auf `:root`, Prüfung via `getComputedStyle`)
    - **Validates: Requirements 1.4**

- [x] 3. Checkpoint - Token-Schicht und Basis verifizieren
  - Sicherstellen, dass alle Tests bestehen; bei Fragen den Nutzer konsultieren.

- [x] 4. Layout-Komponenten erstellen und einbinden
  - [x] 4.1 `AppHeader`/MenuBar-Komponente erstellen
    - Neue Komponente unter `frontend/src/components/` anlegen: fixierte Leiste (`position: fixed`, `z-index: 1000`), Hintergrund `#282D37`, `box-shadow`
    - Brand-Bereich links mit `app.title`; Navigation (`nav.search` → Search, `nav.admin` → Admin) und LanguageSwitcher rechts
    - Aktive Route über `router-link-active` mit 2px-Unterstrich in `#C15BEB`
    - Alle Werte ausschließlich über Tokens
    - _Requirements: 4.1, 6.2, 6.3_

  - [x] 4.2 Compact-on-scroll und responsives Verhalten im `AppHeader` umsetzen
    - Scroll-Listener schaltet Modifikator-Klasse `app-header--compact` (reduziert Höhe/Padding)
    - Ab md (768px) horizontale Navigation; unterhalb md Hamburger-Menü mit Slide-in via `respond-to(md)`
    - _Requirements: 4.2, 7.2, 7.3_

  - [x] 4.3 `App.vue` auf neue Layout-Struktur umstellen
    - Bestehenden `<header class="app-header">` durch `AppHeader` ersetzen, `AppFooter` ergänzen, optional `ScrollToTop` einhängen
    - `main` mit `padding-top: 96px` unterhalb der fixierten Leiste; bestehenden lang/locale-Watcher unverändert belassen
    - `<main><RouterView/></main>`-Struktur und Routen unverändert
    - _Requirements: 4.1, 4.3, 5.1, 8.1, 9.5_

  - [x] 4.4 `AppFooter`-Komponente erstellen
    - Mehrspaltiger Footer via CSS-Grid: ab 768px bis zu 4 Spalten, unterhalb 768px 2 Spalten (`respond-to(md)`)
    - Nur Tokens für Farben/Abstände/Typografie; ausschließlich vorhandene i18n-Schlüssel, keine neuen Schlüssel
    - _Requirements: 4.5, 4.6, 8.3_

  - [x] 4.5 Optionale `ScrollToTop`-Komponente erstellen
    - Schaltfläche unten rechts, erscheint nach Scroll-Schwelle, sanftes Scrollen nach oben
    - Token-basiert, tastaturbedienbar mit sichtbarem Fokusindikator (Kontrast ≥ 3:1)
    - _Requirements: 6.4, 9.3_

  - [x] 4.6 `LanguageSwitcher.vue` token-basiert restylen
    - `<label>`+`<select>`-Semantik, i18n-Schlüssel (`language.label`/`language.de`/`language.en`) und `setLocale`-Logik unverändert; nur Styling über Tokens
    - _Requirements: 6.1, 8.3, 8.4, 9.2_

  - [x] 4.7 Beispieltest: fixiertes Header-Layout und Inhaltsabstand
    - `position: fixed`, `z-index: 1000` für Header; `padding-top: 96px` für `main`; aktiver Nav-Unterstrich 2px `#C15BEB`
    - _Requirements: 4.1, 4.3, 6.3_

  - [x] 4.8 Beispieltest: Footer-Spaltenzahl je Breakpoint und Container-Breite
    - 4 Spalten ab 768px / 2 Spalten unterhalb; Container `max-width: 1200px`, zentriert
    - _Requirements: 4.4, 4.5, 7.5_

  - [x] 4.9 Property-Test: lang-Attribut folgt der aktiven Locale
    - **Property 13: Lang-Attribut folgt der aktiven Locale** (Generator über Folgen von Locale-Wechseln, Prüfung `document.documentElement.lang === locale`)
    - **Validates: Requirements 9.5**

- [x] 5. Checkpoint - Layout-Komponenten verifizieren
  - Sicherstellen, dass alle Tests bestehen; bei Fragen den Nutzer konsultieren.

- [x] 6. Bestehende Views token-basiert restylen (ohne Markup-/Logik-/i18n-Änderung)
  - [x] 6.1 `SearchView.vue` restylen
    - `<style scoped>`-Werte auf Tokens umstellen; `#b00020` → `color-error`, Grautöne (`#666`/`#555`) → Text-/Sekundär-Token; Abstände aus der Abstandsskala
    - Markup, BEM-Klassen, Props, Logik, Routen und i18n-Schlüssel unverändert
    - _Requirements: 2.2, 4.6, 5.2, 6.1, 6.6, 8.1, 8.3_

  - [x] 6.2 `QuoteDetailView.vue` restylen
    - Detaildarstellung und Links auf Token-Farben; keine Markup-/Logik-/i18n-Änderung
    - _Requirements: 2.2, 4.6, 5.3, 6.1, 8.1, 8.3_

  - [x] 6.3 `SourceDetailView.vue` restylen
    - Metadaten und „Zitate dieser Quelle“-Verknüpfung token-basiert; keine Markup-/Logik-/i18n-Änderung
    - _Requirements: 2.2, 4.6, 5.4, 6.1, 8.1, 8.3_

  - [x] 6.4 `LengthLimitedInput.vue` restylen
    - Eingabefeld-Styling für Fokus- und Fehlerzustand über Tokens; Verhalten unverändert
    - _Requirements: 6.1, 6.4, 6.6_

  - [x] 6.5 `AdminView.vue` restylen
    - Formular-, Tab-, Tabellen-Styling token-basiert; Validierungs-/Fehlerzustände über Status-Tokens; keine Markup-/Logik-/i18n-Änderung
    - _Requirements: 2.2, 4.6, 5.5, 6.1, 6.5, 6.6, 8.1, 8.3_

  - [x] 6.6 Admin-Teilkomponenten restylen
    - `components/admin/`: AdminLogin, QuoteManager, SourceManager, LanguageManager, DeleteSourceDialog token-basiert (Formular, Tabelle, Dialog); hartkodierte Werte durch Tokens ersetzen
    - _Requirements: 2.2, 4.6, 5.5, 6.1, 6.5, 6.6, 8.3_

  - [x] 6.7 `NotFoundView.vue` restylen
    - Token-basiertes Styling; Kopf-/Navigationsbereich über globalen `AppHeader`; keine Markup-/Logik-/i18n-Änderung
    - _Requirements: 5.6, 8.1_

  - [x] 6.8 Property-Test: Alle Gestaltungswerte stammen aus Tokens
    - **Property 1: Alle Gestaltungswerte stammen aus Tokens** (aufgelöste Farb-/Abstands-/Radius-/Schattenwerte liegen in der Token-Menge; keine Hartkodierung)
    - **Validates: Requirements 1.3, 2.2, 4.6, 5.2, 5.3, 5.4, 5.5, 5.6**

  - [x] 6.9 Property-Test: Gleiche Elementtypen sind identisch gestaltet
    - **Property 6: Gleiche Elementtypen sind identisch gestaltet** (Paare desselben Typs — Schaltfläche/Link/Eingabe-/Auswahlfeld — haben identische aufgelöste Werte)
    - **Validates: Requirements 6.1**

  - [x] 6.10 Beispieltest: Interaktionszustände und Fehler-/Fallback-Verhalten
    - Hover `#C15BEB` mit `0.3s ease`, Deaktiviert-Zustand; ungültiges Pflichtfeld → Fehlerzustand + Meldung über bestehende `errors.*`-Schlüssel; unbekannte Farbe → Fallback sichtbar
    - _Requirements: 2.5, 6.2, 6.5, 6.6_

- [x] 7. Checkpoint - View-Restyling verifizieren
  - Sicherstellen, dass alle Tests bestehen; bei Fragen den Nutzer konsultieren.

- [x] 8. Barrierefreiheits- und Responsiv-Eigenschaften absichern
  - [x] 8.1 Property-Test: Fokusindikatoren erfüllen Kontrast
    - **Property 7: Fokusindikatoren erfüllen Kontrast** (jeder sichtbare Fokusindikator ≥ 3:1 zum angrenzenden Hintergrund)
    - **Validates: Requirements 6.4, 9.4**

  - [x] 8.2 Property-Test: Kein horizontaler Überlauf im Mobilbereich
    - **Property 8: Kein horizontaler Überlauf im Mobilbereich** (Viewport-Breiten 320..<768, `scrollWidth <= clientWidth`, interaktive Elemente innerhalb der Breite)
    - **Validates: Requirements 7.4**

  - [x] 8.3 Property-Test: Semantik und ARIA bleiben äquivalent
    - **Property 11: Semantik und ARIA bleiben äquivalent** (Rollen/Namen/Zustände im Accessibility-Tree vor/nach Redesign äquivalent)
    - **Validates: Requirements 9.2**

  - [x] 8.4 Property-Test: Tastatur-Erreichbarkeit aller interaktiven Elemente
    - **Property 12: Tastatur-Erreichbarkeit aller interaktiven Elemente** (Menge/Reihenfolge tabbarer Elemente je View; nicht erreichbares Element → Fehlschlag)
    - **Validates: Requirements 9.3, 9.6**

- [x] 9. Funktionserhalt-Invarianten und Build-/Font-Integration absichern
  - [x] 9.1 Property-Test: Routen- und View-Menge bleibt unverändert
    - **Property 9: Routen- und View-Menge bleibt unverändert** (Mengengleichheit der Routen/Views vor/nach Redesign aus der Router-Definition)
    - **Validates: Requirements 8.1**

  - [x] 9.2 Property-Test: i18n-Schlüssel bleiben erhalten
    - **Property 10: i18n-Schlüssel bleiben erhalten** (jeder Schlüssel existiert nach Redesign in jedem Sprachkatalog, keine Entfernung/Umbenennung)
    - **Validates: Requirements 8.3**

  - [x] 9.3 Integrationstest: Build bricht bei undefiniertem Token ab
    - SCSS mit undefinierter Variable kompilieren und Sass-Compile-Fehler ("Undefined variable") erwarten, der `vite build` abbricht
    - _Requirements: 1.5_

  - [x] 9.4 Integrationstest: Font-Konfiguration prüfen
    - `@font-face` enthält `font-display: swap` und `font-family`-Kette endet auf `sans-serif`
    - _Requirements: 3.5_

  - [x] 9.5 Verhaltenstest: LanguageSwitcher unverändert
    - `setLocale` wechselt und persistiert die Sprache mit identischem beobachtbarem Verhalten
    - _Requirements: 8.4_

- [x] 10. Snapshots regenerieren und Gesamt-Testsuite verifizieren
  - [x] 10.1 Vitest-Snapshots bewusst neu generieren
    - `vitest -u` ausführen, um die Snapshots unter `frontend/src/views/__snapshots__` an das neue Styling anzupassen
    - _Requirements: 8.5, 8.6_

  - [x] 10.2 Vollständige Testsuite ausführen und Funktionserhalt bestätigen
    - Gesamte Vitest-Suite laufen lassen; alle verhaltensbezogenen Tests müssen bestehen, nur Snapshot-Abweichungen sind erwartet
    - _Requirements: 8.2, 8.5, 8.6_

- [x] 11. Finaler Checkpoint - Gesamtergebnis verifizieren
  - Sicherstellen, dass alle Tests bestehen; bei Fragen den Nutzer konsultieren.

## Notes

- Alle Tests (Property-, Beispiel-, Integrations- und Verhaltenstests) sind verpflichtend und werden im Rahmen der Implementierung umgesetzt.
- Jede Aufgabe referenziert konkrete Anforderungsklauseln zur Nachverfolgbarkeit; Property-Tasks referenzieren zusätzlich die zugehörige Korrektheitseigenschaft aus dem Design.
- Property-Tests laufen mit mindestens 100 Iterationen und tragen den Kommentar `Feature: ui-redesign, Property {n}: {Text}`; jede Eigenschaft wird durch genau einen Test abgedeckt.
- Checkpoints sichern die inkrementelle Validierung ab.
- Es werden keine Markup-Strukturen, BEM-Klassennamen, Props, Logik, Routen oder i18n-Schlüssel verändert (Req 8).

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "1.3"] },
    { "id": 1, "tasks": ["1.4", "1.5", "2.1", "2.2"] },
    { "id": 2, "tasks": ["2.3", "2.4", "2.5"] },
    { "id": 3, "tasks": ["4.1", "4.4", "4.5", "4.6"] },
    { "id": 4, "tasks": ["4.2", "4.3"] },
    { "id": 5, "tasks": ["4.7", "4.8", "4.9"] },
    { "id": 6, "tasks": ["6.1", "6.2", "6.3", "6.4", "6.5", "6.6", "6.7"] },
    { "id": 7, "tasks": ["6.8", "6.9", "6.10", "8.1", "8.2", "8.3", "8.4", "9.1", "9.2", "9.3", "9.4", "9.5"] },
    { "id": 8, "tasks": ["10.1"] },
    { "id": 9, "tasks": ["10.2"] }
  ]
}
```
