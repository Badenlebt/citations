# Requirements Document

## Introduction

Dieses Feature ergänzt die Citations-Anwendung um eine Startseite, die bei jedem Aufruf ein zufälliges Zitat ausgibt. Die Startseite ist unter dem Pfad `/` erreichbar und nutzt den bereits vorhandenen Endpunkt `GET /api/random` (Anforderung 3 der Spec citations-management) sowie den vorhandenen API-Client `random()` des Frontends. Auf API-Seite sind vorgesehen: ein rückwärtskompatibler, optionaler Query-Parameter `uiLanguage` für `GET /api/random`, mit dem die Startseite ein Zitat in der Sprache der Oberflächensprache bevorzugen kann (Anforderung 6), sowie eine neue Relation und Endpunkte, die jeder Oberflächensprache genau eine Zitat-Sprache zuordnen (Anforderung 9). Bisher besteht keine Kopplung zwischen den Sprachen der Zitate und der Oberflächensprache.

Bisher wird unter `/` die Such-Ansicht ausgeliefert. Damit die Startseite diesen Pfad belegen kann, wird die Such-Ansicht unter dem neuen Pfad `/search` erreichbar gemacht. Die Ansicht selbst und ihr Verhalten bleiben unverändert. Diese Pfadänderung ist eine bewusste Abweichung von Anforderung 8.1 der Spec ui-redesign (identische Pfade) und wird hier ausdrücklich festgelegt.

Die Startseite folgt dem Design-System der Spec ui-redesign (Design-Tokens, Typografie, Layout, Barrierefreiheit) und verwendet die vorhandene i18n-Infrastruktur (Deutsch und Englisch).

## Glossary

- **Frontend**: Die Vue-3-Anwendung (TypeScript) im Verzeichnis `frontend`.
- **Startseite**: Die neue View des Frontends unter dem Pfad `/`, die ein zufälliges Zitat anzeigt.
- **Such-Ansicht**: Die bestehende View `SearchView` mit Volltextsuche und Sprachfilter.
- **Zufalls-API**: Der bestehende Endpunkt `GET /api/random`, der genau ein zufälliges Zitat oder den Fehlercode `NO_QUOTES` liefert.
- **API-Client**: Das Frontend-Modul `api/client.ts` mit der Methode `random()`.
- **Zitat**: Ein Datensatz mit Zitattext, genau einer Quelle und genau einer Sprache.
- **Quelle**: Autor*in oder unbenannte Quelle eines Zitats.
- **Zitat-Anzeige**: Der Bereich der Startseite, der Zitattext, Quellenname und Sprache des aktuellen Zitats darstellt.
- **Nachladen-Schaltfläche**: Die Schaltfläche der Startseite, die ein neues zufälliges Zitat anfordert.
- **Navigation**: Die Navigationsleiste im App-Header und die Verknüpfungen im Fußbereich.
- **i18n**: Die Internationalisierung des Frontends mit den Sprachen Deutsch und Englisch.
- **Oberflächensprache**: Die im Frontend gewählte i18n-Sprache (Deutsch oder Englisch).
- **Zitat-Sprache**: Die Sprache eines Zitats gemäß der Sammlung (Datensatz `language` mit ID und Namen, z. B. „Deutsch“ oder „Englisch“).
- **Sprachzuordnung**: Ein Eintrag der neuen Relation, der genau einer Oberflächensprache (`de` oder `en`) genau eine Zitat-Sprache zuordnet. Die Zuordnung ist 1:1: Jede Oberflächensprache hat höchstens einen Eintrag, und jede Zitat-Sprache ist höchstens einer Oberflächensprache zugeordnet.
- **Betreiber**: Der authentifizierte Nutzer mit Verwaltungsrechten gemäß Spec citations-management.
- **Sprachverwaltung**: Der Bereich der Admin-Oberfläche des Frontends, in dem Sprachen angelegt werden.
- **Design-System**: Die in der Spec ui-redesign definierten Design-Tokens und Stilregeln.

## Requirements

### Requirement 1: Startseite mit zufälligem Zitat

**User Story:** Als Besucher*in möchte ich beim Aufruf der Anwendung sofort ein zufälliges Zitat sehen, damit ich die Sammlung ohne gezielte Suche entdecken kann.

#### Acceptance Criteria

1. WHEN ein Nutzer den Pfad `/` aufruft, THE Frontend SHALL die Startseite rendern.
2. WHEN die Startseite gerendert wird, THE Startseite SHALL pro Seitenaufruf genau eine Anfrage an die Zufalls-API über den API-Client senden.
3. WHILE die Anfrage an die Zufalls-API noch nicht beantwortet ist, THE Startseite SHALL einen Ladeindikator anzeigen und kein Zitat darstellen.
4. WHEN die Zufalls-API ein Zitat liefert, THE Zitat-Anzeige SHALL den Zitattext, den Namen der Quelle und den Namen der Sprache des Zitats darstellen und den Ladeindikator ausblenden.
5. WHEN die Zufalls-API ein Zitat liefert, THE Zitat-Anzeige SHALL den Quellennamen als Verknüpfung auf die Quellen-Detail-Ansicht (`/sources/{id}`) mit der ID der Quelle des angezeigten Zitats darstellen.
6. WHEN die Zufalls-API ein Zitat liefert, THE Zitat-Anzeige SHALL eine Verknüpfung auf die Zitat-Detail-Ansicht (`/quotes/{id}`) mit der ID des angezeigten Zitats bereitstellen.
7. WHEN der Nutzer die Startseite neu lädt und mindestens zwei Zitate vorhanden sind, THE Startseite SHALL das angezeigte Zitat bei jedem Neuladen ausschließlich aus der Antwort der Zufalls-API beziehen und kein Zitat im Frontend zwischenspeichern oder bevorzugen.
8. IF die Zufalls-API keine Antwort innerhalb von 10 Sekunden liefert oder mit einem Fehler antwortet, THEN THE Startseite SHALL den Ladeindikator ausblenden, eine Fehlermeldung anzeigen, die den Fehlschlag des Ladens angibt, und kein Zitat darstellen.
9. IF die Zufalls-API meldet, dass kein Zitat vorhanden ist, THEN THE Startseite SHALL einen Hinweis anzeigen, dass die Sammlung keine Zitate enthält, und keine Verknüpfungen auf Quellen- oder Zitat-Detail-Ansicht darstellen.

### Requirement 2: Weiteres zufälliges Zitat anfordern

**User Story:** Als Besucher*in möchte ich per Schaltfläche ein neues zufälliges Zitat anfordern, damit ich weitere Zitate entdecken kann, ohne die Seite neu zu laden.

#### Acceptance Criteria

1. WHILE die Startseite angezeigt wird, THE Startseite SHALL eine Nachladen-Schaltfläche mit einem i18n-Text in der aktuell gewählten Sprache darstellen, die per Tastatur (Tab-Fokus, Aktivierung mit Enter und Leertaste) bedienbar ist.
2. WHEN der Nutzer die Nachladen-Schaltfläche betätigt, THE Startseite SHALL genau eine neue Anfrage an die Zufalls-API senden und die Zitat-Anzeige nach erfolgreicher Antwort mit dem neuen Zitat ersetzen, ohne die Seite neu zu laden.
3. WHILE eine Anfrage an die Zufalls-API läuft, THE Startseite SHALL die Nachladen-Schaltfläche deaktivieren, sodass keine parallelen Anfragen entstehen, und die Schaltfläche innerhalb von 100 ms nach Abschluss der Anfrage (Erfolg oder Fehler) wieder aktivieren.
4. THE Zitat-Anzeige SHALL den Zitatbereich dauerhaft als Live-Region (`aria-live="polite"`) auszeichnen, sodass Hilfstechnologien jede Änderung des Zitats ankündigen.
5. IF die Anfrage nach einem neuen Zitat fehlschlägt oder nicht innerhalb von 10 Sekunden beantwortet wird, THEN THE Startseite SHALL die Anfrage abbrechen, das zuvor angezeigte Zitat unverändert weiter darstellen und eine Fehlermeldung gemäß Anforderung 4 anzeigen.

### Requirement 3: Ladezustand

**User Story:** Als Besucher*in möchte ich erkennen, dass ein Zitat geladen wird, damit ich die Seite nicht für defekt halte.

#### Acceptance Criteria

1. WHEN die Startseite aufgerufen wird, THE Startseite SHALL die erste Anfrage an die Zufalls-API auslösen und den Ladezustand spätestens 100 ms nach dem Rendern der Startseite darstellen.
2. WHILE die erste Anfrage an die Zufalls-API läuft, THE Startseite SHALL den i18n-Text für den Ladezustand (Schlüssel `common.loading`) in der aktuell gewählten Sprache in genau einem Bereich mit `role="status"` darstellen.
3. WHILE der Ladezustand dargestellt wird, THE Startseite SHALL kein Zitat und keine Fehlermeldung darstellen.
4. WHEN die Antwort der Zufalls-API eintrifft (erfolgreich oder mit Fehler), THE Startseite SHALL den Ladezustand innerhalb von 100 ms entfernen.
5. WHEN der Nutzer die Startseite verlässt, während eine Anfrage an die Zufalls-API läuft, THE Startseite SHALL die Anfrage über ein AbortSignal abbrechen und keine Zustandsänderung mehr vornehmen.
6. IF eine Anfrage an die Zufalls-API durch ein AbortSignal abgebrochen wurde, THEN THE Startseite SHALL keine Fehlermeldung darstellen.

### Requirement 4: Fehler- und Leerzustände

**User Story:** Als Besucher*in möchte ich verständliche Hinweise erhalten, wenn kein Zitat angezeigt werden kann, damit ich weiß, wie es weitergeht.

#### Acceptance Criteria

1. IF die Zufalls-API den Fehlercode `NO_QUOTES` liefert, THEN THE Startseite SHALL innerhalb von 1 Sekunde nach Eingang der Antwort einen i18n-Hinweis in der aktiven Sprache darstellen, dass noch keine Zitate vorhanden sind, und weder Zitat-Anzeige noch Nachladen-Schaltfläche darstellen.
2. IF die Anfrage an die Zufalls-API mit einem anderen Fehler als `NO_QUOTES`, mit einem Netzwerkfehler oder nach 10 Sekunden ohne Antwort (Timeout) fehlschlägt, THEN THE Startseite SHALL innerhalb von 1 Sekunde nach dem Fehler eine i18n-Fehlermeldung in der aktiven Sprache in einem Bereich mit `role="alert"` darstellen und kein Zitat darstellen.
3. IF die erste Anfrage an die Zufalls-API aus einem anderen Grund als `NO_QUOTES` fehlschlägt, THEN THE Startseite SHALL zusätzlich die Nachladen-Schaltfläche darstellen, sodass der Nutzer einen erneuten Versuch auslösen kann.
4. WHEN der Nutzer die Nachladen-Schaltfläche nach einem Fehler betätigt, THE Startseite SHALL die Fehlermeldung beibehalten, bis die erneute Anfrage abgeschlossen ist, und die Nachladen-Schaltfläche währenddessen deaktivieren, sodass höchstens eine Anfrage gleichzeitig läuft.
5. WHEN eine erneute Anfrage nach einem Fehler erfolgreich ist, THE Startseite SHALL die Fehlermeldung entfernen und das Zitat darstellen.
6. IF eine erneute Anfrage nach einem Fehler erneut fehlschlägt, THEN THE Startseite SHALL genau eine Fehlermeldung darstellen, die Nachladen-Schaltfläche aktiviert lassen und kein Zitat darstellen.

### Requirement 5: Zugang zur Suche und Navigation

**User Story:** Als Besucher*in möchte ich von der Startseite aus zur Suche gelangen, damit ich gezielt nach Zitaten suchen kann.

#### Acceptance Criteria

1. THE Frontend SHALL die Such-Ansicht unter dem Pfad `/search` bereitstellen.
2. THE Frontend SHALL den Pfad `/` ausschließlich der Startseite zuordnen, sodass ein Aufruf von `/` die Startseite und nicht die Such-Ansicht darstellt.
3. THE Navigation SHALL im App-Header genau zwei Einträge darstellen: einen Eintrag für die Startseite (Ziel `/`) und einen Eintrag für die Such-Ansicht (Ziel `/search`), jeweils mit einem i18n-Text, der in jeder unterstützten Sprache ein nicht leerer String ist.
4. WHILE die Route `/` aktiv ist, THE Navigation SHALL den Eintrag der Startseite gemäß Anforderung 6.3 der Spec ui-redesign als aktiv kennzeichnen, ohne dass der Eintrag der Such-Ansicht als aktiv gekennzeichnet ist.
5. WHILE die Route `/search` aktiv ist, THE Navigation SHALL den Eintrag der Such-Ansicht gemäß Anforderung 6.3 der Spec ui-redesign als aktiv kennzeichnen, ohne dass der Eintrag der Startseite als aktiv gekennzeichnet ist.
6. THE Navigation SHALL im Fußbereich die Verknüpfung „Zufälliges Zitat“ (Schlüssel `search.randomQuote`) mit dem Ziel `/` darstellen.
7. THE Startseite SHALL genau eine Verknüpfung zur Such-Ansicht (Ziel `/search`) mit einem i18n-Text darstellen, der in jeder unterstützten Sprache ein nicht leerer String ist.
8. WHEN eine bestehende Verknüpfung im Frontend auf die Such-Ansicht verweist, einschließlich „Zurück zur Suche“ in den Detail-Ansichten, THE Frontend SHALL als Ziel `/search` verwenden und die zuvor aktiven Query-Parameter (`q`, `languages`, `sourceId`, `page`) mit unveränderten Werten übernehmen.
9. WHEN ein Nutzer `/search` mit Query-Parametern aufruft, THE Such-Ansicht SHALL für dieselben Parameter dieselbe Ergebnisliste, dieselben Filter-Vorbelegungen, dieselbe Seitennummer und dieselben Fehlerzustände zeigen wie zuvor unter `/`.
10. WHEN ein Nutzer `/` mit Query-Parametern der Such-Ansicht (`q`, `languages`, `sourceId`, `page`) aufruft, THE Frontend SHALL die Startseite darstellen und die Parameter nicht als Suche auswerten.

### Requirement 6: Mehrsprachigkeit

**User Story:** Als Besucher*in möchte ich die Startseite in meiner gewählten Oberflächensprache sehen, damit ich alle Texte verstehe.

#### Acceptance Criteria

1. THE Startseite SHALL die folgenden sechs statischen Texte ausschließlich über i18n-Schlüssel darstellen: Überschrift, Nachladen-Schaltfläche, Ladezustand, Leerzustand, Fehlermeldung und Verknüpfung zur Suche. Für jeden dieser Schlüssel SHALL eine nicht leere Übersetzung in Deutsch und in Englisch vorhanden sein.
2. WHEN der Nutzer die Oberflächensprache umschaltet, THE Startseite SHALL innerhalb von 200 ms alle statischen Texte in der neuen Sprache darstellen, ohne die Seite neu zu laden und ohne eine neue Anfrage an die Zufalls-API zu senden.
3. WHEN der Nutzer die Oberflächensprache umschaltet, THE Startseite SHALL den Text, die Quelle und die Sprachangabe des angezeigten Zitats unverändert lassen, da der Zitattext in seiner gespeicherten Sprache angezeigt wird.
4. WHILE die Startseite den Ladezustand, den Leerzustand oder die Fehlermeldung anzeigt, WHEN der Nutzer die Oberflächensprache umschaltet, THE Startseite SHALL den aktuellen Zustand beibehalten und den zugehörigen Text in der neuen Sprache darstellen.
5. IF für einen Schlüssel der Startseite in der gewählten Oberflächensprache keine Übersetzung vorhanden ist, THEN THE Startseite SHALL die Übersetzung der jeweils anderen Sprache (Deutsch bzw. Englisch) anzeigen, statt eines leeren Textes oder des rohen Schlüsselnamens.
6. THE Frontend SHALL bestehende i18n-Schlüssel weder entfernen noch umbenennen.
7. THE Zufalls-API SHALL den optionalen Query-Parameter `uiLanguage` (Wert `de` oder `en`) akzeptieren; WHEN der Parameter fehlt, leer oder ungültig ist, THE Zufalls-API SHALL sich wie bisher verhalten und ein Zitat gleichverteilt aus allen Zitaten wählen.
8. WHEN `uiLanguage` einen gültigen Wert hat, dieser Oberflächensprache eine Zitat-Sprache zugeordnet ist und mindestens ein Zitat in dieser Zitat-Sprache existiert, THE Zufalls-API SHALL das Zitat gleichverteilt aus den Zitaten dieser Zitat-Sprache wählen.
9. IF für die Oberflächensprache aus `uiLanguage` keine Sprachzuordnung besteht oder keine Zitate in der zugeordneten Zitat-Sprache existieren, THEN THE Zufalls-API SHALL das Zitat gleichverteilt aus den Zitaten der Zitat-Sprache wählen, die der Oberflächensprache `en` zugeordnet ist.
10. IF auch diese Wahl nach Kriterium 9 kein Zitat ergibt, THEN THE Zufalls-API SHALL das Zitat gleichverteilt aus allen Zitaten wählen und `NO_QUOTES` ausschließlich liefern, wenn überhaupt kein Zitat existiert.
11. WHEN die Startseite die Zufalls-API aufruft (erstes Laden und Nachladen), THE Startseite SHALL `uiLanguage` mit der aktuellen Oberflächensprache übergeben und dafür keine zusätzliche Anfrage stellen.
12. WHEN der Nutzer die Oberflächensprache umschaltet, THE Startseite SHALL das angezeigte Zitat unverändert lassen; WHEN der Nutzer anschließend die Nachladen-Schaltfläche betätigt, THE Startseite SHALL `uiLanguage` gemäß der dann gewählten Oberflächensprache übergeben.

### Requirement 7: Gestaltung und Barrierefreiheit

**User Story:** Als Nutzer mit Assistenztechnologie möchte ich eine einheitlich gestaltete, barrierefreie Startseite, damit ich sie uneingeschränkt nutzen kann.

#### Acceptance Criteria

1. THE Startseite SHALL Farben, Typografie, Abstände und Schatten ausschließlich aus den Design-Tokens des Design-Systems beziehen, sodass im Stylesheet der Startseite kein Farb-, Schrift-, Abstands- oder Schattenwert als Literalwert (nicht als Token-Referenz) definiert ist.
2. THE Startseite SHALL genau eine Überschrift der Ebene H1 darstellen.
3. WHEN die Startseite ein Zitat darstellt, THE Startseite SHALL den Zitattext in einem `blockquote`-Element und die Quellenangabe in einem `figcaption`-Element darstellen, das demselben `figure`-Element wie das `blockquote` zugeordnet ist.
4. WHEN ein Nutzer ausschließlich die Tastatur verwendet, THE Startseite SHALL die Nachladen-Schaltfläche und alle Verknüpfungen in der visuellen Reihenfolge (von oben nach unten, von links nach rechts) per Tabulatortaste erreichbar machen.
5. WHEN ein Nutzer ein fokussiertes interaktives Element der Startseite per Eingabetaste (Verknüpfung, Schaltfläche) oder Leertaste (Schaltfläche) betätigt, THE Startseite SHALL dieselbe Aktion auslösen wie bei einem Mausklick auf dieses Element.
6. WHILE ein interaktives Element der Startseite den Fokus besitzt, THE Startseite SHALL einen sichtbaren Fokusindikator mit einer Breite von mindestens 2px und einem Kontrastverhältnis von mindestens 3:1 gegenüber den angrenzenden Farben darstellen.
7. THE Startseite SHALL für Text mit weniger als 24px (bzw. weniger als 18,66px bei fetter Schrift) ein Kontrastverhältnis von mindestens 4,5:1 und für größeren Text ein Kontrastverhältnis von mindestens 3:1 zwischen Text- und Hintergrundfarbe darstellen (WCAG 2.1 Level AA, Erfolgskriterium 1.4.3).
8. WHILE die Viewport-Breite im Bereich von 320px bis unter 768px liegt, THE Startseite SHALL alle Inhalte ohne horizontales Scrollen darstellen.
9. WHEN der Zitattext länger als eine Zeile ist, THE Zitat-Anzeige SHALL den Text innerhalb der Inhaltsbreite umbrechen, ohne Inhalt abzuschneiden oder zu überlagern.

### Requirement 8: Erhalt der bestehenden Funktionalität

**User Story:** Als Nutzer der Anwendung möchte ich, dass alle bestehenden Funktionen weiterhin funktionieren, damit die neue Startseite keine Regression verursacht.

#### Acceptance Criteria

1. THE Frontend SHALL alle bestehenden Routen außer `/` unter identischen URL-Pfaden und mit identischen Views bereitstellen, sodass jede dieser Routen nach der Änderung dieselbe View rendert wie vor der Änderung.
2. THE Frontend SHALL für die Zufalls-API weder bestehende Request-Parameter (Namen, Typen, Pflichtstatus) noch das Antwortformat (Feldnamen und Feldtypen) ändern; Änderungen am PHP-API-Code beschränken sich auf den optionalen Parameter `uiLanguage` gemäß Anforderung 6 und die Sprachzuordnungs-Endpunkte gemäß Anforderung 9.
3. WHEN die bestehende automatisierte Testsuite ausgeführt wird, THE Frontend SHALL alle Tests mit 0 fehlgeschlagenen Tests bestehen, mit Ausnahme von Tests, die ausschließlich den früheren Pfad `/` für die Such-Ansicht prüfen; diese Tests SHALL auf den Pfad `/search` bzw. auf die Startseite angepasst werden.
4. WHEN die Komponententests der neuen Startseite ausgeführt werden, THE Frontend SHALL mit einem Test-Double des API-Clients (ohne echte Netzwerkaufrufe) mindestens je einen bestandenen Test für den Erfolgs-, Lade-, `NO_QUOTES`-, Fehler- und Nachlade-Fall bereitstellen.
5. IF ein bestehender Test außerhalb der in Kriterium 3 genannten Ausnahme fehlschlägt, THEN THE Frontend SHALL die Änderung als Regression behandeln, sodass der Testlauf mit einem Fehlerstatus (Exit-Code ungleich 0) endet und der Test nicht gelöscht, übersprungen oder abgeschwächt wird.

### Requirement 9: Zuordnung von Oberflächensprachen zu Zitat-Sprachen

**User Story:** Als Betreiber möchte ich jeder Oberflächensprache genau eine Zitat-Sprache zuordnen können, damit die Startseite Zitate in der Sprache der Oberfläche bevorzugt anzeigt.

#### Acceptance Criteria

1. THE Zitate-System SHALL Sprachzuordnungen in einer eigenen Relation speichern, in der jede unterstützte Oberflächensprache (`de`, `en`) höchstens einen Eintrag hat.
2. THE Zitate-System SHALL sicherstellen, dass jede Zitat-Sprache höchstens einer Oberflächensprache zugeordnet ist.
3. WHEN eine Anfrage an die Sprachzuordnungs-API die Zuordnungen lesend abruft, THE Sprachzuordnungs-API SHALL für jede Oberflächensprache die ID der zugeordneten Zitat-Sprache oder einen Hinweis auf „keine Zuordnung“ zurückgeben, ohne einen Session-Nachweis zu verlangen.
4. WHEN der Betreiber mit gültiger Session eine vorhandene Zitat-Sprache einer unterstützten Oberflächensprache zuordnet, THE Sprachzuordnungs-API SHALL die Zuordnung speichern und eine bereits bestehende Zuordnung dieser Oberflächensprache ersetzen.
5. IF die gewählte Zitat-Sprache bereits einer anderen Oberflächensprache zugeordnet ist, THEN THE Sprachzuordnungs-API SHALL die Anfrage mit einem Konflikt-Fehler ablehnen und den Datenbestand unverändert lassen.
6. IF die angegebene Oberflächensprache nicht unterstützt ist oder die angegebene Zitat-Sprache nicht existiert, THEN THE Sprachzuordnungs-API SHALL die Anfrage mit einem Validierungsfehler ablehnen und den Datenbestand unverändert lassen.
7. WHEN der Betreiber mit gültiger Session die Zuordnung einer Oberflächensprache entfernt, THE Sprachzuordnungs-API SHALL den Eintrag löschen, sodass für diese Oberflächensprache keine Zuordnung besteht.
8. IF eine schreibende Anfrage an die Sprachzuordnungs-API ohne gültige Session übermittelt wird, THEN THE Sprachzuordnungs-API SHALL die Anfrage vor jeder inhaltlichen Validierung mit derselben Nicht-autorisiert-Antwort wie die übrigen schreibenden APIs ablehnen und den Datenbestand unverändert lassen.
9. THE Sprachverwaltung SHALL für jede Oberflächensprache eine Auswahl der vorhandenen Zitat-Sprachen einschließlich der Option „keine Zuordnung“ darstellen und die aktuell gespeicherte Zuordnung vorauswählen.
10. WHEN der Betreiber in der Sprachverwaltung eine Zuordnung ändert, THE Sprachverwaltung SHALL die Änderung über die Sprachzuordnungs-API speichern und bei Erfolg die gespeicherte Zuordnung bestätigen.
11. IF das Speichern einer Zuordnung mit einem Fehler abgelehnt wird, THEN THE Sprachverwaltung SHALL eine i18n-Fehlermeldung in einem Bereich mit `role="alert"` darstellen und die zuletzt gespeicherte Zuordnung anzeigen.
12. THE Sprachverwaltung SHALL alle neuen Texte über i18n-Schlüssel mit Übersetzungen in Deutsch und Englisch darstellen und dem Design-System sowie den Barrierefreiheitsanforderungen aus Anforderung 7 folgen.
13. WHEN die automatisierten Tests ausgeführt werden, THE Zitate-System SHALL mindestens je einen bestandenen Test für die 1:1-Eindeutigkeit, das Ersetzen und Entfernen einer Zuordnung, die Ablehnung ohne Session sowie die Auswahl der Zufalls-API gemäß Anforderung 6 (zugeordnete Sprache, Englisch-Rückfall, Gesamtrückfall) bereitstellen.
