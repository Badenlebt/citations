# Requirements Document

## Introduction

Dieses Feature umfasst eine visuelle Überarbeitung (UI-Redesign) der bestehenden Citations-Anwendung (Vue 3 + TypeScript Frontend). Ziel ist es, das Erscheinungsbild der Anwendung an die Referenz-Website mhitzelberger.de anzulehnen – insbesondere hinsichtlich Farbgebung, Typografie, Platzierung der Oberflächenelemente und des allgemeinen Layouts/Look-and-feel.

Die konkreten visuellen Design-Tokens der Referenz-Website sind nunmehr bekannt. Sie wurden aus dem Quellcode des Schwesterprojekts myNewWebsite (Vue 3 + SCSS) ausgelesen (SCSS-Variablen und Komponenten) und werden als verbindlich behandelt. Die in diesem Dokument genannten konkreten Farb-, Typografie-, Abstands-, Breakpoint- und Layoutwerte sind damit die maßgeblichen Zielwerte der Umsetzung; eine gesonderte nachträgliche Abstimmung dieser Werte mit dem Operator ist nicht mehr erforderlich.

Die Überarbeitung betrifft ausschließlich die visuelle Gestaltung und das Layout. Die gesamte bestehende Funktionalität (Zitate, Quellen, Sprachen, Suche, Authentifizierung/Admin) bleibt unverändert erhalten und weiterhin bedienbar und barrierefrei zugänglich.

## Glossary

- **UI**: Die Benutzeroberfläche des Citations-Frontends (Vue-3-Anwendung).
- **Referenz-Website**: Die Website mhitzelberger.de bzw. deren Quellcode im Schwesterprojekt myNewWebsite (Vue 3 + SCSS), deren Look-and-feel als Gestaltungsvorlage dient.
- **Design-System**: Die Sammlung definierter visueller Design-Tokens (Farben, Typografie, Abstände, Radien, Schatten) und wiederverwendbarer Stilregeln der Anwendung.
- **Design-Token**: Ein benannter, zentral definierter Gestaltungswert (z. B. Primärfarbe, Basisschriftgröße), der an mehreren Stellen der UI verwendet wird. Die konkreten Zielwerte der Design-Tokens sind aus dem Quellcode der Referenz-Website bekannt und in diesem Dokument als verbindlich festgelegt.
- **Farbpalette**: Die Menge der im Design-System definierten Farbwerte (Primär-, Sekundär-, Hintergrund-, Text-, Status- und Akzentfarben).
- **Layout**: Die räumliche Anordnung und Platzierung der Oberflächenelemente auf einer Seite (Kopfbereich, Navigation, Inhaltsbereich, Fußbereich).
- **App-Header**: Der Kopfbereich der Anwendung, ausgeführt als fixierte Menüleiste am oberen Rand mit Brand-Bereich (Logo, Titel, Untertitel), Navigation und Sprachumschalter.
- **View**: Eine über den Router erreichbare Seite der Anwendung (Suche, Zitat-Detail, Quellen-Detail, Admin, Nicht-gefunden).
- **Operator**: Die administrierende Person, die über den Admin-Bereich Inhalte verwaltet.
- **Breakpoint**: Eine definierte Viewport-Breite, ab der das Layout sein Erscheinungsbild anpasst (responsives Verhalten).
- **WCAG_AA**: Die Konformitätsstufe AA der Web Content Accessibility Guidelines 2.1.

## Requirements

### Requirement 1: Zentrales Design-System

**User Story:** Als Operator möchte ich ein zentrales Design-System mit definierten Design-Tokens, damit das Erscheinungsbild der gesamten Anwendung einheitlich und an der Referenz-Website ausgerichtet ist.

#### Acceptance Criteria

1. THE UI SHALL visuelle Gestaltungswerte für Farben, Typografie, Abstände, Eckenradien und Schatten als zentrale Design-Tokens an einer einzigen Definitionsstelle in Form von SCSS-Variablen bzw. CSS-Custom-Properties als alleinige Quelle bereitstellen, wobei für jede der fünf Kategorien mindestens ein Token definiert ist.
2. THE Design-System SHALL exakt die folgenden konkreten Werte als Design-Tokens definieren: Farben Hintergrund #282D37, Textfarbe #fff, Primär-/Akzentfarbe #C15BEB, weitere Lila-Töne #39107B, #6C3483 und #e49eff; Typografie Schriftfamilie 'Nunito' (variable Schrift, Gewicht 200–1000) mit Fallback sans-serif, Basisschriftgröße 16px; Abstandsskala xs 0.25rem, sm 0.5rem, md 1rem, lg 1.5rem, xl 2rem und xxl 3rem.
3. WHEN ein Oberflächenelement eine Farbe, Schriftgröße, Schriftart, einen Abstand, einen Eckenradius oder einen Schatten darstellt, THE UI SHALL den Wert ausschließlich aus einem definierten Design-Token beziehen und keinen fest einkodierten Wert verwenden.
4. WHERE ein einzelner Design-Token an der zentralen Definitionsstelle geändert wird, THE UI SHALL den geänderten Wert ohne weitere Codeänderung auf allen Oberflächenelementen anwenden, die diesen Token verwenden.
5. IF ein Oberflächenelement auf einen Design-Token verweist, der an der zentralen Definitionsstelle nicht definiert ist, THEN THE UI SHALL dies beim Build als Fehler melden und den Build abbrechen.

### Requirement 2: Farbpalette der Referenz-Website

**User Story:** Als Betrachter der Anwendung möchte ich eine Farbgebung, die der Referenz-Website entspricht, damit die Anwendung ein konsistentes dunkles Erscheinungsbild mit Lila-Akzenten wie mhitzelberger.de hat.

#### Acceptance Criteria

1. THE Design-System SHALL eine Farbpalette mit den konkreten Werten Primär-/Akzentfarbe #C15BEB, Hintergrundfarbe #282D37 (Gunmetal), Textfarbe #fff und Sekundärfarbe #6C3483 sowie den weiteren Lila-Tönen #39107B (purple-dark) und #e49eff (purple-bright) definieren, wobei jeder Farbwert als eindeutiger, maschinell auslesbarer Hex-Wert festgelegt ist.
2. THE UI SHALL die in der Farbpalette definierten Farbwerte auf alle sichtbaren App-Header, Inhaltsbereiche, Schaltflächen, Verknüpfungen und Formularelemente anwenden, sodass kein sichtbares Element dieser Kategorien einen Farbwert außerhalb der definierten Palette verwendet.
3. THE UI SHALL für jede Kombination aus Text- und Hintergrundfarbe ein Kontrastverhältnis von mindestens 4,5:1 für normalen Text (Schriftgröße kleiner als 18 pt bzw. kleiner als 14 pt bei Fettschrift) und mindestens 3:1 für großen Text (Schriftgröße ab 18 pt bzw. ab 14 pt bei Fettschrift) gemäß WCAG 2.1 Level AA einhalten.
4. THE Design-System SHALL genau eine Statusfarbe für Erfolgsmeldungen, genau eine Statusfarbe für Fehlermeldungen und genau eine Statusfarbe für Hinweismeldungen definieren, wobei diese Statusfarben neu festgelegt werden dürfen, da die Referenz-Website keine expliziten Statusfarben vorgibt, und wobei jede Statusfarbe von den übrigen Statusfarben unterscheidbar ist, mit der Lila-Hintergrund-Palette harmoniert und das Kontrastverhältnis nach Kriterium 3 einhält.
5. IF ein angeforderter Farbwert in der definierten Farbpalette nicht vorhanden ist, THEN THE UI SHALL die definierte Hintergrundfarbe #282D37 und die definierte Textfarbe #fff als Rückfallwert anwenden und das betroffene Element sichtbar darstellen, ohne die Darstellung abzubrechen.

### Requirement 3: Typografie

**User Story:** Als Betrachter der Anwendung möchte ich eine Typografie, die der Referenz-Website entspricht, damit Texte einheitlich und gut lesbar dargestellt werden.

#### Acceptance Criteria

1. THE Design-System SHALL die Schriftfamilie 'Nunito' mit der Ersatzschriftart sans-serif für Fließtext und Überschriften definieren.
2. THE Design-System SHALL eine Basisschriftgröße von 16px für Fließtext und eine Zeilenhöhe von 1.6 für Fließtext definieren.
3. THE Design-System SHALL die folgenden Überschriftengrößen definieren: H1 2.5rem, H2 2rem, H3 1.75rem, H4 1.5rem, H5 1.25rem und H6 1rem, jeweils mit einem Schriftgewicht von 700 und einer Zeilenhöhe von 1.2.
4. WHEN die UI ein Textelement rendert, THE UI SHALL die für dessen Typ (Überschriftenebene H1 bis H6 oder Fließtext) definierte Schriftfamilie, Schriftgröße, Zeilenhöhe und Schriftgewicht anwenden.
5. IF die Web-Schriftart 'Nunito' nicht innerhalb von 3 Sekunden geladen werden kann, THEN THE UI SHALL die Ersatzschriftart sans-serif anwenden und die Textdarstellung ohne Abbruch fortsetzen.

### Requirement 4: Layout und Platzierung der Oberflächenelemente

**User Story:** Als Betrachter der Anwendung möchte ich eine Anordnung der Oberflächenelemente, die dem Layout der Referenz-Website entspricht, damit die Anwendung das gleiche Look-and-feel vermittelt.

#### Acceptance Criteria

1. THE UI SHALL den App-Header als am oberen Rand fixierte Menüleiste (position fixed, z-index 1000) mit Hintergrundfarbe #282D37 und Schatten darstellen, wobei der Brand-Bereich (Logo, Titel, Untertitel) linksbündig und Navigation sowie Sprachumschalter (DE/EN) rechtsbündig angeordnet sind.
2. WHEN die Seite vertikal gescrollt wird, THE UI SHALL die fixierte Menüleiste in einer kompakteren Darstellung anzeigen.
3. THE UI SHALL den Inhaltsbereich mit einem oberen Abstand (padding-top) von 96px unterhalb der fixierten Menüleiste beginnen lassen, sodass kein Inhalt von der fixierten Menüleiste verdeckt wird.
4. THE UI SHALL den Inhaltsbereich auf eine maximale Breite von 1200px begrenzen, bei verfügbarer Breite größer als 1200px horizontal zentrieren und mit einem seitlichen Padding von 1rem (Token md) sowie ab einer Viewport-Breite von 768px von 1.5rem (Token lg) versehen.
5. THE UI SHALL am unteren Rand einen mehrspaltigen Fußbereich darstellen, der ab einer Viewport-Breite von 768px bis zu vier Spalten und unterhalb von 768px zwei Spalten anzeigt.
6. THE UI SHALL die Abstände zwischen Oberflächenelementen ausschließlich aus den definierten Abstands-Tokens (xs, sm, md, lg, xl, xxl) beziehen, sodass kein Abstandswert außerhalb der Design-Tokens verwendet wird.

### Requirement 5: Konsistente Gestaltung aller Views

**User Story:** Als Betrachter der Anwendung möchte ich, dass alle Seiten das überarbeitete Design verwenden, damit die Anwendung durchgängig einheitlich wirkt.

#### Acceptance Criteria

1. THE UI SHALL ein einziges gemeinsames Designsystem (überarbeitetes Design) bereitstellen, das für jede View dieselbe Seitenstruktur mit Kopf- und Navigationsbereich, dasselbe Farbschema, dieselbe Typografie (Schriftfamilien und Schriftgrößenhierarchie) und dieselbe Gestaltung interaktiver Elemente (Schaltflächen, Links, Eingabefelder) definiert.
2. WHEN die Such-View gerendert wird, THE UI SHALL ausschließlich die gemäß Kriterium 1 definierten Struktur-, Farb-, Typografie- und Interaktionselemente des überarbeiteten Designs verwenden und keine abweichenden Gestaltungselemente anzeigen.
3. WHEN die Zitat-Detail-View gerendert wird, THE UI SHALL ausschließlich die gemäß Kriterium 1 definierten Struktur-, Farb-, Typografie- und Interaktionselemente des überarbeiteten Designs verwenden und keine abweichenden Gestaltungselemente anzeigen.
4. WHEN die Quellen-Detail-View gerendert wird, THE UI SHALL ausschließlich die gemäß Kriterium 1 definierten Struktur-, Farb-, Typografie- und Interaktionselemente des überarbeiteten Designs verwenden und keine abweichenden Gestaltungselemente anzeigen.
5. WHEN eine Admin-View gerendert wird, einschließlich Anmeldung sowie Zitat-, Quellen- und Sprachverwaltung, THE UI SHALL ausschließlich die gemäß Kriterium 1 definierten Struktur-, Farb-, Typografie- und Interaktionselemente des überarbeiteten Designs verwenden und keine abweichenden Gestaltungselemente anzeigen.
6. WHEN die Nicht-gefunden-View gerendert wird, THE UI SHALL ausschließlich die gemäß Kriterium 1 definierten Struktur-, Farb-, Typografie- und Interaktionselemente des überarbeiteten Designs verwenden, einschließlich desselben Kopf- und Navigationsbereichs wie die übrigen Views, und keine abweichenden Gestaltungselemente anzeigen.

### Requirement 6: Gestaltung interaktiver Elemente

**User Story:** Als Betrachter der Anwendung möchte ich einheitlich gestaltete Schaltflächen, Verknüpfungen und Formularelemente, damit Interaktionen klar erkennbar und konsistent sind.

#### Acceptance Criteria

1. THE UI SHALL Schaltflächen, Verknüpfungen, Eingabefelder und Auswahlfelder mit den im Design-System definierten Werten für Typografie, Abstände, Rahmen und Farben darstellen, sodass Elemente desselben Typs identische Gestaltungswerte aufweisen.
2. WHEN der Zeiger über einem interaktiven Element oder einer Verknüpfung liegt, THE UI SHALL die Hover-Farbe #C15BEB anwenden und den Übergang mit einer Transition von 0.3s ease darstellen.
3. WHILE eine Route in der Navigation aktiv ist, THE UI SHALL den zugehörigen Navigationseintrag mit einem Unterstrich-Indikator von 2px in der Farbe #C15BEB kennzeichnen.
4. WHEN ein interaktives Element den Fokus erhält, THE UI SHALL eine sichtbare Fokusanzeige mit einem Kontrastverhältnis von mindestens 3:1 zur Umgebung darstellen.
5. WHILE ein interaktives Element deaktiviert ist, THE UI SHALL einen vom aktivierten Zustand visuell unterscheidbaren Deaktiviert-Zustand darstellen und das Element gegen Zeiger- und Tastatureingaben sperren.
6. IF ein Pflicht-Eingabefeld oder Auswahlfeld einen ungültigen Wert enthält, THEN THE UI SHALL einen vom Normalzustand visuell unterscheidbaren Fehlerzustand mit einem Kontrastverhältnis von mindestens 3:1 sowie eine textuelle Fehlermeldung darstellen, die den Grund der Ablehnung angibt.

### Requirement 7: Responsives Verhalten

**User Story:** Als Betrachter der Anwendung möchte ich, dass das überarbeitete Design auf unterschiedlichen Bildschirmgrößen funktioniert, damit die Anwendung auf Mobilgeräten und Desktops nutzbar bleibt.

#### Acceptance Criteria

1. THE UI SHALL die Breakpoints sm 576px, md 768px, lg 992px und xl 1200px für die Layout-Anpassung bereitstellen.
2. WHILE die Viewport-Breite unter 768px (Breakpoint md) liegt, THE UI SHALL das Layout einspaltig darstellen und die Navigation als Hamburger-Menü mit Slide-in anzeigen.
3. WHILE die Viewport-Breite mindestens 768px (Breakpoint md) beträgt, THE UI SHALL die Navigation als horizontale Navigationsleiste in der fixierten Menüleiste anzeigen.
4. WHILE die Viewport-Breite im Bereich von 320px bis unter 768px liegt, THE UI SHALL alle interaktiven Elemente vollständig innerhalb der Viewport-Breite und ohne horizontales Scrollen bedienbar halten.
5. THE UI SHALL den Inhaltsbereich auf eine maximale Breite von 1200px begrenzen und bei verfügbarer Breite größer als 1200px horizontal innerhalb des Viewports zentrieren.

### Requirement 8: Erhalt der bestehenden Funktionalität

**User Story:** Als Nutzer der Anwendung möchte ich, dass alle bestehenden Funktionen nach dem Redesign unverändert verfügbar sind, damit das Redesign keine Funktionalität beeinträchtigt.

#### Acceptance Criteria

1. WHEN das Redesign abgeschlossen ist, THE UI SHALL dieselbe Menge an Routen und zugehörigen Views bereitstellen wie vor dem Redesign, sodass jede vor dem Redesign erreichbare Route nach dem Redesign unter identischem Pfad erreichbar ist und die jeweils zugeordnete View rendert.
2. THE UI SHALL die bestehenden Funktionen für Zitate (Anlegen, Anzeigen, Bearbeiten, Löschen, Auflisten), Quellen (Anlegen, Anzeigen, Bearbeiten, Löschen, Auflisten), Sprachen (Anlegen, Bearbeiten, Löschen, Auflisten, Umschalten), Suche und Authentifizierung (Anmelden, Abmelden) mit identischen Eingaben und identischen beobachtbaren Ergebnissen wie vor dem Redesign bereitstellen.
3. THE UI SHALL für sämtliche angezeigten Texte ausschließlich die bereits vor dem Redesign vorhandenen Internationalisierungs-Textschlüssel verwenden, ohne bestehende Schlüssel zu entfernen oder umzubenennen.
4. WHEN ein Nutzer den Sprachumschalter betätigt, THE UI SHALL die Oberflächensprache mit identischem beobachtbarem Verhalten wie vor dem Redesign wechseln.
5. WHEN die bestehende automatisierte Testsuite nach Abschluss des Redesigns ausgeführt wird, THE UI SHALL dieselbe Menge bestandener Tests aufweisen wie vor dem Redesign, abgesehen von Tests, die ausschließlich visuelle Momentaufnahmen (Snapshots) prüfen.
6. IF ein Test der bestehenden automatisierten Testsuite ausschließlich aufgrund einer durch das Redesign geänderten visuellen Momentaufnahme (Snapshot) fehlschlägt, THEN THE UI SHALL als weiterhin funktional korrekt gelten, sofern die zugrunde liegende Funktion ein unverändertes beobachtbares Ergebnis liefert.

### Requirement 9: Barrierefreiheit

**User Story:** Als Nutzer mit Assistenztechnologie möchte ich, dass das überarbeitete Design barrierefrei bleibt, damit ich die Anwendung weiterhin uneingeschränkt bedienen kann.

#### Acceptance Criteria

1. THE UI SHALL für Textinhalte ein Farbkontrastverhältnis von mindestens 4,5:1 und für Großtext (ab 18 pt bzw. 14 pt fett) sowie grafische Bedienelemente und Fokusindikatoren von mindestens 3:1 gemäß WCAG 2.1 Stufe AA einhalten, einschließlich der Kombination weißer Text #fff auf dem Gunmetal-Hintergrund #282D37 sowie der Verknüpfungen in #C15BEB.
2. THE UI SHALL die bestehende semantische HTML-Struktur und die vorhandenen ARIA-Attribute aller Oberflächenelemente unverändert erhalten, sodass vor und nach der Überarbeitung dieselben Rollen, Namen und Zustände über die Accessibility-Tree-Ausgabe abrufbar sind.
3. WHEN ein Nutzer ausschließlich die Tastatur verwendet, THE UI SHALL alle interaktiven Elemente in einer der visuellen Anordnung entsprechenden Reihenfolge per Tabulator erreichbar, mit einem sichtbaren Fokusindikator versehen und per Tastatur auslösbar halten.
4. WHILE ein interaktives Element den Tastaturfokus besitzt, THE UI SHALL einen Fokusindikator mit einem Kontrastverhältnis von mindestens 3:1 gegenüber dem angrenzenden Hintergrund darstellen.
5. WHEN die aktive Oberflächensprache gewechselt wird, THE UI SHALL das lang-Attribut des Dokuments innerhalb von 1 Sekunde auf den ISO-639-1-Code der neu aktiven Sprache aktualisieren.
6. IF ein interaktives Element nicht per Tastatur erreichbar oder nicht in logischer Reihenfolge fokussierbar ist, THEN THE UI SHALL dies als Barrierefreiheitsverletzung behandeln und der automatisierte Barrierefreiheitstest SHALL fehlschlagen.
