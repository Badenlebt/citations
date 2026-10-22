# Requirements Document

## Introduction

Dieses Dokument beschreibt die Anforderungen an eine Web-Anwendung zur Verwaltung
einer Zitate-Sammlung. Die Sammlung wird von einer bisher papierbasierten Ablage
in ein digitales System überführt, in dem Zitate abgelegt, verwaltet und abgerufen
werden können.

Die Anwendung besteht aus öffentlich zugänglichen Seiten (Suche und Einzelansichten,
ohne Login) und passwortgeschützten Verwaltungsseiten (Anlegen, Bearbeiten, Löschen
von Zitaten und Autor*innen/Quellen). Das Frontend wird als statische Vue-3-Anwendung
gebaut; die Server-Logik stellt eine schlanke PHP-REST-API bereit, die mit einer
MariaDB-Datenbank kommuniziert. Das Hosting erfolgt auf IONOS Webhosting Pro unter
einer Subdomain von mhitzelberger.de.

## Glossary

- **Zitate-System**: Die Gesamtanwendung aus Frontend, REST-API und Datenbank.
- **Such-API**: Der Teil der REST-API, der Volltextsuche und Zufallsabruf von Zitaten bereitstellt.
- **Zitat-API**: Der Teil der REST-API, der Zitate liest, anlegt, ändert und löscht.
- **Autoren-API**: Der Teil der REST-API, der Autor*innen/Quellen liest, anlegt, ändert und löscht.
- **Sprach-API**: Der Teil der REST-API, der Sprachen liest und anlegt.
- **Auth-API**: Der Teil der REST-API, der Login, Session-Prüfung und Logout bereitstellt.
- **Frontend**: Die statisch ausgelieferte Vue-3-Anwendung im Web-Root.
- **Zitat**: Ein Datensatz mit Zitattext, genau einer zugeordneten Quelle und genau einer Sprache.
- **Quelle**: Gemeinsame Entität für Autor*in oder unbenannte Quelle (z. B. "Arabisches Sprichwort"). Wird synonym als "Autor*in/Quelle" verwendet.
- **Unbekannt-Quelle**: Eine stets vorhandene, nicht löschbare Quelle mit dem Namen "unbekannt", der Zitate ohne bekannte Quelle zugeordnet werden.
- **Sprache**: Ein dynamisch erweiterbarer Eintrag, der die Sprache eines Zitattexts kennzeichnet.
- **Betreiber**: Der authentifizierte Nutzer mit Verwaltungsrechten (zunächst genau ein Account).
- **Einrichtungskonfiguration**: Eine Konfigurationsquelle außerhalb des Web-Roots (Datei oder Prozess-Umgebungsvariablen), die die Datenbank-Zugangsdaten sowie Benutzername und Passwort-Hash des initialen Betreiber-Accounts enthält.
- **Session**: Ein zeitlich begrenzter, nach Login gültiger Authentifizierungszustand (Token/Cookie).
- **Suchbegriff**: Ein einzelnes Wort (Teilwort) der Sucheingabe. Die Sucheingabe wird anhand von Leerzeichen in ein oder mehrere Suchbegriffe zerlegt.
- **Sucheingabe**: Die vollständige, von der Besucher*in eingegebene Zeichenkette, die in einen oder mehrere Suchbegriffe zerlegt wird.
- **Sprachfilter**: Eine bei der Suche optional übergebene Auswahl von einer oder mehreren Sprachen, auf die die Suchergebnisse eingeschränkt werden.
- **Relevanzwert**: Ein numerischer Wert, nach dem Suchergebnisse absteigend sortiert werden.

## Requirements

### Anforderung 1: Volltextsuche nach Zitaten

**User Story:** Als Besucher*in möchte ich Zitate per Volltextsuche finden und die Suche auf eine oder mehrere Sprachen einschränken können, damit ich passende Zitate zu einem Stichwort in den gewünschten Sprachen entdecke.

#### Akzeptanzkriterien

1. WENN eine Sucheingabe mit 1 bis 200 Zeichen übermittelt und in einen oder mehrere Suchbegriffe zerlegt wird, DANN SOLL die Such-API alle Zitate zurückgeben, deren Zitattext mindestens einen dieser Suchbegriffe als Teiltreffer ohne Beachtung der Groß-/Kleinschreibung enthält.
2. WENN eine Sucheingabe übermittelt wird, DANN SOLL die Such-API zusätzlich alle Zitate zurückgeben, deren zugeordnete Quelle mindestens einen der Suchbegriffe als Teiltreffer ohne Beachtung der Groß-/Kleinschreibung im Namen enthält.
3. DIE Such-API SOLL bei der Suche ausschließlich den Zitattext und den Namen der Quelle berücksichtigen.
4. DIE Such-API SOLL die Felder Geburtsdatum, Sterbedatum und Kurzbeschreibung einer Quelle von der Suche ausschließen.
5. DIE Such-API SOLL Suchergebnisse absteigend nach Relevanzwert zurückgeben.
6. DIE Such-API SOLL Suchergebnisse seitenweise (Pagination) mit einer Seitengröße zwischen 1 und 100 (Standardwert 20, wenn keine Seitengröße angefordert wird) und einer anforderbaren Seitennummer (Standardwert 1, wenn keine Seitennummer angefordert wird) zurückgeben.
7. WENN kein Zitat auf die Sucheingabe passt, DANN SOLL die Such-API eine leere Ergebnisliste mit Gesamtanzahl 0 zurückgeben.
8. WENN eine angeforderte Seitennummer größer ist als die Anzahl der vorhandenen Ergebnisseiten, DANN SOLL die Such-API eine leere Ergebnisliste zusammen mit der unveränderten Gesamtanzahl der Treffer zurückgeben.
9. WENN eine Sucheingabe leer ist oder ausschließlich aus Leerzeichen besteht, DANN SOLL die Such-API die Anfrage ablehnen und eine Fehlermeldung zur fehlenden Sucheingabe zurückgeben, ohne eine Suche auszuführen.
10. WENN eine Suche mit einem Sprachfilter übermittelt wird, der eine oder mehrere Sprachen umfasst, DANN SOLL die Such-API ausschließlich Zitate zurückgeben, deren zugeordnete Sprache zu einer der im Sprachfilter ausgewählten Sprachen gehört.
11. WENN eine Suche ohne Sprachfilter oder mit leerem Sprachfilter übermittelt wird, DANN SOLL die Such-API Zitate unabhängig von ihrer zugeordneten Sprache berücksichtigen.
12. IF ein übergebener Sprachfilter ausschließlich Sprachen enthält, die zu keiner vorhandenen Sprache gehören, THEN SOLL die Such-API eine leere Ergebnisliste mit Gesamtanzahl 0 zurückgeben.
13. IF eine Sucheingabe mit mehr als 200 Zeichen übermittelt wird, THEN SOLL die Such-API die Anfrage ablehnen und eine Fehlermeldung zur Überschreitung der zulässigen Länge zurückgeben, ohne eine Suche auszuführen.

### Anforderung 2: Relevanzgewichtung der Suchergebnisse

**User Story:** Als Besucher*in möchte ich die relevantesten Treffer zuerst sehen, damit ich das gesuchte Zitat schnell finde.

#### Akzeptanzkriterien

1. DIE Such-API SOLL je Zitat genau einen Relevanzwert berechnen, der alle Treffer dieses Zitats zusammenfasst und nach dem die Ergebnisse gemäß Anforderung 1 absteigend sortiert werden.
2. DIE Such-API SOLL einem Treffer, bei dem ein Suchbegriff als eigenständiges Wort vorkommt, einen höheren Relevanzwert zuweisen als einem Treffer, bei dem derselbe Suchbegriff nur am Wortanfang eines Wortes vorkommt.
3. DIE Such-API SOLL einem Treffer, bei dem ein Suchbegriff am Wortanfang eines Wortes vorkommt, einen höheren Relevanzwert zuweisen als einem Treffer, bei dem derselbe Suchbegriff nur als Teiltreffer innerhalb eines Wortes (nicht am Wortanfang) vorkommt.
4. WENN ein Suchbegriff mehrere Wörter enthält, DANN SOLL die Such-API den Relevanzwert eines Zitats so bilden, dass jedes zusätzliche der Suchwörter, zu dem das Zitat einen Treffer hat, den Relevanzwert erhöht, sodass ein Zitat mit Treffern zu mehr dieser Wörter einen höheren Relevanzwert erhält als ein Zitat mit Treffern zu weniger dieser Wörter.
5. DIE Such-API SOLL Treffer im Zitattext und Treffer im Namen der zugeordneten Quelle beide in den Relevanzwert desselben Zitats einrechnen.
6. DIE Such-API SOLL den Treffervergleich für die Relevanzwertung unabhängig von Groß-/Kleinschreibung und unabhängig von diakritischen Zeichen durchführen.
7. WENN zwei oder mehr Zitate denselben Relevanzwert aufweisen, DANN SOLL die Such-API diese Zitate in einer bei gleicher Datenlage und gleichem Suchbegriff wiederholbaren, eindeutigen Reihenfolge zurückgeben.

### Anforderung 3: Zufälliges Zitat

**User Story:** Als Besucher*in möchte ich mir ein zufälliges Zitat anzeigen lassen, damit ich die Sammlung ohne gezielte Suche entdecken kann.

#### Akzeptanzkriterien

1. WENN eine Anfrage nach einem zufälligen Zitat übermittelt wird und mindestens ein Zitat vorhanden ist, DANN SOLL die Such-API genau ein Zitat mit Zitattext, zugeordneter Sprache und zugeordneter Quelle zurückgeben.
2. WENN eine Anfrage nach einem zufälligen Zitat übermittelt wird und mindestens zwei Zitate vorhanden sind, DANN SOLL die Such-API das zurückzugebende Zitat so auswählen, dass jedes vorhandene Zitat grundsätzlich auswählbar ist und über viele Anfragen hinweg keine feste, von der Datenlage unabhängige Reihenfolge entsteht.
3. IF keine Zitate vorhanden sind, THEN SOLL die Such-API kein Zitat zurückgeben und eine Antwort mit einem Hinweis auf das Fehlen von Zitaten zurückgeben.

### Anforderung 4: Einzelansicht eines Zitats

**User Story:** Als Besucher*in möchte ich ein einzelnes Zitat samt Quellennennung ansehen, damit ich Herkunft und Kontext nachvollziehen kann.

#### Akzeptanzkriterien

1. WENN eine Einzelansicht für ein vorhandenes Zitat anhand seiner Kennung angefordert wird, DANN SOLL die Zitat-API genau dieses Zitat mit seiner Kennung, dem Zitattext, der zugeordneten Sprache und dem Namen der zugeordneten Quelle zurückgeben.
2. WENN eine Einzelansicht für ein vorhandenes Zitat dargestellt wird, DANN SOLL die Einzelansicht den Namen der zugeordneten Quelle als Verweis auf die Einzelansicht genau dieser Quelle darstellen.
3. WHERE die zugeordnete Quelle eine nicht leere Link-URL besitzt, SOLL die Einzelansicht die Quellennennung zusätzlich als Verweis auf diese Link-URL darstellen.
4. WHERE die zugeordnete Quelle keine Link-URL besitzt, SOLL die Einzelansicht die Quellennennung ohne Verweis auf eine Link-URL darstellen.
5. IF ein Zitat mit der angeforderten Kennung nicht existiert, THEN SOLL die Zitat-API kein Zitat zurückgeben und eine Nicht-gefunden-Antwort zurückgeben.

### Anforderung 5: Einzelansicht einer Autor*in/Quelle

**User Story:** Als Besucher*in möchte ich die Detailseite einer Autor*in/Quelle ansehen, damit ich mehr über sie erfahre und alle zugehörigen Zitate finde.

#### Akzeptanzkriterien

1. WENN eine Einzelansicht für eine vorhandene Quelle anhand ihrer Kennung angefordert wird, DANN SOLL die Autoren-API die Kennung und den Namen der Quelle sowie die Felder Geburtsdatum, Sterbedatum, Kurzbeschreibung und Link-URL zurückgeben.
2. WENN eine Einzelansicht für eine vorhandene Quelle angefordert wird und eines der optionalen Felder Geburtsdatum, Sterbedatum, Kurzbeschreibung oder Link-URL nicht gesetzt ist, DANN SOLL die Autoren-API das jeweilige Feld als leer kennzeichnen zurückgeben.
3. DIE Einzelansicht einer Quelle SOLL einen Verweis auf eine Suchseite bereitstellen, die ausschließlich die dieser Quelle zugeordneten Zitate seitenweise (Pagination) mit einer Seitengröße zwischen 1 und 100 (Standardwert 20, wenn keine Seitengröße angefordert wird) und einer anforderbaren Seitennummer (Standardwert 1, wenn keine Seitennummer angefordert wird) auflistet.
4. IF eine Quelle mit der angeforderten Kennung nicht existiert, THEN SOLL die Autoren-API keine Quellendaten zurückgeben und eine Nicht-gefunden-Antwort zurückgeben.

### Anforderung 6: Authentifizierung per Login-Formular

**User Story:** Als Betreiber möchte ich mich über ein Login-Formular anmelden, damit nur ich die Verwaltungsseiten nutzen kann.

#### Akzeptanzkriterien

1. WENN gültige Zugangsdaten über das Login-Formular übermittelt werden, DANN SOLL die Auth-API eine Session einrichten und ein zeitlich begrenztes Token bzw. Cookie zurückgeben, dessen Gültigkeitsdauer der in Kriterium 4 festgelegten Dauer entspricht.
2. IF ungültige Zugangsdaten übermittelt werden, THEN SOLL die Auth-API den Zugang verweigern, keine Session einrichten und eine Fehlermeldung zurückgeben, die nicht erkennen lässt, ob Benutzerkennung oder Passwort falsch war.
3. IF beim Login die Benutzerkennung oder das Passwort fehlt oder ausschließlich aus Leerzeichen besteht, THEN SOLL die Auth-API den Zugang verweigern und eine Fehlermeldung zu fehlenden Zugangsdaten zurückgeben, ohne eine Passwortprüfung durchzuführen.
4. WENN seit der Einrichtung einer Session eine Gültigkeitsdauer von konfigurierbar 1 bis 168 Stunden (Standardwert 24 Stunden) überschritten ist, DANN SOLL die Auth-API die Session als ungültig behandeln und bei einer Anfrage mit dieser Session den Zugang verweigern.
5. IF innerhalb von 15 Minuten 5 aufeinanderfolgende Login-Versuche für dieselbe Benutzerkennung mit ungültigen Zugangsdaten übermittelt werden, THEN SOLL die Auth-API weitere Login-Versuche für diese Benutzerkennung für 15 Minuten ablehnen, auch wenn danach gültige Zugangsdaten übermittelt werden.
6. WENN der Betreiber ein Logout auslöst, DANN SOLL die Auth-API die zugehörige Session beenden, sodass das zuvor ausgegebene Token bzw. Cookie keinen Zugang mehr gewährt.
7. DIE Auth-API SOLL die Zugangsdaten so prüfen, dass das Passwort ausschließlich als kryptografischer Hash gespeichert wird und der Klartext des Passworts weder dauerhaft abgelegt noch in einer Antwort zurückgegeben wird.
8. DIE Auth-API SOLL mehrere Betreiber-Accounts unterstützen, wobei zunächst genau ein Account eingerichtet ist.
9. WENN ein Login-Versuch übermittelt wird, DANN SOLL die Auth-API zuerst die Vollständigkeit der Zugangsdaten (Kriterium 3), danach die Rate-Limit-Sperre (Kriterium 5) und erst danach die Gültigkeit der Zugangsdaten (Kriterium 2) prüfen.
10. DIE Auth-API SOLL die Prüfung ungültiger Zugangsdaten so durchführen, dass sich der Fall "unbekannte Benutzerkennung" und der Fall "bekannte Benutzerkennung mit falschem Passwort" nicht durch einen erkennbaren Zeitunterschied unterscheiden lassen, der Rückschlüsse auf die Existenz einer Benutzerkennung zulässt.
11. IF eine Anfrage mit einem fehlenden, inhaltlich ungültigen oder keiner Session zuordenbaren Token übermittelt wird, THEN SOLL die Auth-API die Anfrage als nicht authentifiziert behandeln und den Zugang verweigern.

### Anforderung 7: Zugriffsschutz der Verwaltungsfunktionen

**User Story:** Als Betreiber möchte ich, dass Verwaltungsfunktionen ohne gültige Session gesperrt sind, damit niemand ohne Anmeldung Daten ändern kann.

#### Akzeptanzkriterien

1. IF eine schreibende Anfrage (Anlegen, Ändern oder Löschen) an die Zitat-API, Autoren-API oder Sprach-API mit einer gemäß Anforderung 6.11 nicht authentifizierten Session (fehlendes, inhaltlich ungültiges oder keiner Session zuordenbares Token) übermittelt wird, THEN SOLL das Zitate-System die Anfrage ablehnen, keine Daten im Datenbestand anlegen, ändern oder löschen und eine Nicht-autorisiert-Antwort zurückgeben.
2. IF eine schreibende Anfrage an die Zitat-API, Autoren-API oder Sprach-API mit einer gemäß Anforderung 6.11 ungültigen Session (abgelaufen gemäß Anforderung 6.4, beendet oder nicht zuordenbar) übermittelt wird, THEN SOLL das Zitate-System die Anfrage ablehnen, keine Daten im Datenbestand anlegen, ändern oder löschen und eine Nicht-autorisiert-Antwort zurückgeben.
3. WENN eine lesende Anfrage an die Such-API oder an eine lesende Einzelansicht ohne Session übermittelt wird, DANN SOLL das Zitate-System die Anfrage ausführen und das angefragte Ergebnis zurückgeben, ohne einen Session-Nachweis zu verlangen.
4. WENN eine schreibende Anfrage mit gültiger Session übermittelt wird und die inhaltliche Validierung der Anfragedaten erfolgreich ist, DANN SOLL das Zitate-System die angeforderte schreibende Operation ausführen und eine Erfolgsantwort zurückgeben, die das Ergebnis der Operation bestätigt.
5. WENN eine schreibende Anfrage an die Zitat-API, Autoren-API oder Sprach-API übermittelt wird, DANN SOLL das Zitate-System die Gültigkeit der Session prüfen, bevor es eine inhaltliche Validierung der Anfragedaten durchführt.
6. IF eine schreibende Anfrage ohne gültige Session abgelehnt wird, THEN SOLL das Zitate-System eine Nicht-autorisiert-Antwort zurückgeben, die unabhängig davon identisch ist, ob die adressierte Ressource existiert, sodass keine Rückschlüsse auf den Datenbestand möglich sind.

### Anforderung 8: Zitat anlegen

**User Story:** Als Betreiber möchte ich ein Zitat anlegen, damit ich die Sammlung erweitern kann.

#### Akzeptanzkriterien

1. WENN der Betreiber ein Zitat mit einem Zitattext von 1 bis 5000 Zeichen (nach Entfernen umschließender Leerzeichen), einer ausgewählten vorhandenen Quelle und einer ausgewählten vorhandenen Sprache anlegt, DANN SOLL die Zitat-API das Zitat speichern und die Kennung des gespeicherten Zitats zurückgeben.
2. IF beim Anlegen eines Zitats der Zitattext fehlt, leer ist oder nach Entfernen umschließender Leerzeichen leer ist, THEN SOLL die Zitat-API das Speichern ablehnen, kein Zitat anlegen und eine Fehlermeldung zum fehlenden Zitattext zurückgeben.
3. IF beim Anlegen eines Zitats keine Quelle ausgewählt ist oder die ausgewählte Quellenkennung zu keiner vorhandenen Quelle gehört, THEN SOLL die Zitat-API das Speichern ablehnen, kein Zitat anlegen und eine Fehlermeldung zur fehlenden oder ungültigen Quelle zurückgeben.
4. IF beim Anlegen eines Zitats keine Sprache ausgewählt ist oder die ausgewählte Sprache zu keiner vorhandenen Sprache gehört, THEN SOLL die Zitat-API das Speichern ablehnen, kein Zitat anlegen und eine Fehlermeldung zur fehlenden oder ungültigen Sprache zurückgeben.
5. DIE Zitat-API SOLL beim Anlegen eines Zitats ausschließlich die Auswahl einer bereits vorhandenen Quelle zulassen und keine neue Quelle anlegen.
6. IF beim Anlegen eines Zitats der Zitattext nach Entfernen umschließender Leerzeichen mehr als 5000 Zeichen umfasst, THEN SOLL die Zitat-API das Speichern ablehnen, kein Zitat anlegen und eine Fehlermeldung zur Überschreitung der zulässigen Länge zurückgeben.

### Anforderung 9: Zitat bearbeiten und löschen

**User Story:** Als Betreiber möchte ich ein Zitat bearbeiten und löschen, damit ich Fehler korrigieren und veraltete Einträge entfernen kann.

#### Akzeptanzkriterien

1. WENN der Betreiber ein vorhandenes Zitat mit einem Zitattext von 1 bis 5000 Zeichen (nach Entfernen umschließender Leerzeichen), einer vorhandenen Quelle und einer vorhandenen Sprache bearbeitet, DANN SOLL die Zitat-API die geänderten Werte speichern.
2. IF beim Bearbeiten eines Zitats der Zitattext fehlt, leer ist oder nach Entfernen umschließender Leerzeichen leer ist, THEN SOLL die Zitat-API das Speichern ablehnen und das Zitat unverändert lassen und eine Fehlermeldung zum fehlenden Zitattext zurückgeben.
3. IF beim Bearbeiten eines Zitats die ausgewählte Quelle oder Sprache zu keiner vorhandenen Quelle bzw. Sprache gehört, THEN SOLL die Zitat-API das Speichern ablehnen und das Zitat unverändert lassen und eine Fehlermeldung zur ungültigen Quelle bzw. Sprache zurückgeben.
4. WENN der Betreiber ein vorhandenes Zitat löscht, DANN SOLL die Zitat-API das Zitat entfernen und das erfolgreiche Entfernen bestätigen.
5. IF der Betreiber ein nicht vorhandenes Zitat bearbeiten oder löschen will, THEN SOLL die Zitat-API keine Daten ändern und eine Nicht-gefunden-Antwort zurückgeben.
6. IF beim Bearbeiten eines Zitats der Zitattext nach Entfernen umschließender Leerzeichen mehr als 5000 Zeichen umfasst, THEN SOLL die Zitat-API das Speichern ablehnen, das Zitat unverändert lassen und eine Fehlermeldung zur Überschreitung der zulässigen Länge zurückgeben.

### Anforderung 10: Autor*in/Quelle anlegen und bearbeiten

**User Story:** Als Betreiber möchte ich Autor*innen/Quellen anlegen und bearbeiten, damit ich Zitaten korrekte Herkunftsangaben zuordnen kann.

#### Akzeptanzkriterien

1. WENN der Betreiber eine Quelle mit einem Namen von 1 bis 200 Zeichen (nach Entfernen umschließender Leerzeichen) anlegt, DANN SOLL die Autoren-API die Quelle speichern und deren Kennung zurückgeben.
2. IF beim Anlegen oder Bearbeiten einer Quelle der Name fehlt, leer ist oder nach Entfernen umschließender Leerzeichen leer ist, THEN SOLL die Autoren-API das Speichern ablehnen, keine Daten ändern und eine Fehlermeldung zum fehlenden Namen zurückgeben.
3. DIE Autoren-API SOLL die Felder Geburtsdatum, Sterbedatum und Kurzbeschreibung als optionale Freitextfelder mit jeweils 0 bis 500 Zeichen speichern.
4. DIE Autoren-API SOLL je Quelle genau eine optionale Link-URL mit 0 bis 2000 Zeichen speichern und, sofern die Link-URL gesetzt ist, nur Werte mit dem Präfix "http://" oder "https://" zulassen.
5. IF beim Anlegen oder Bearbeiten einer Quelle eine gesetzte Link-URL weder mit "http://" noch mit "https://" beginnt, THEN SOLL die Autoren-API das Speichern ablehnen, keine Daten ändern und eine Fehlermeldung zur ungültigen Link-URL zurückgeben.
6. WENN der Betreiber eine vorhandene Quelle mit gültigen Werten bearbeitet, DANN SOLL die Autoren-API die geänderten Werte speichern.
7. IF beim Anlegen oder Bearbeiten einer Quelle der Name nach Entfernen umschließender Leerzeichen mehr als 200 Zeichen, eines der Felder Geburtsdatum, Sterbedatum oder Kurzbeschreibung mehr als 500 Zeichen, oder die Link-URL mehr als 2000 Zeichen umfasst, THEN SOLL die Autoren-API das Speichern ablehnen, keine Daten ändern und eine Fehlermeldung zur Überschreitung der zulässigen Länge des jeweiligen Feldes zurückgeben.

### Anforderung 11: Autor*in/Quelle löschen mit Konfliktbehandlung

**User Story:** Als Betreiber möchte ich beim Löschen einer Quelle mit zugeordneten Zitaten über das weitere Vorgehen entscheiden, damit keine Zitate ungewollt verloren gehen.

#### Akzeptanzkriterien

1. WENN der Betreiber eine Quelle löscht, der kein Zitat zugeordnet ist, DANN SOLL die Autoren-API die Quelle entfernen und das erfolgreiche Entfernen bestätigen.
2. IF der Betreiber eine Quelle löschen will, der mindestens ein Zitat zugeordnet ist, THEN SOLL die Autoren-API den Löschvorgang anhalten, die Quelle und alle zugeordneten Zitate unverändert lassen und eine Entscheidung zwischen genau den zwei Varianten Umhängen und Löschabbruch anfordern.
3. WENN der Betreiber beim Löschen einer Quelle mit mindestens einem zugeordneten Zitat die Variante Umhängen wählt, DANN SOLL die Autoren-API alle zugeordneten Zitate der Unbekannt-Quelle zuordnen und anschließend die Quelle entfernen.
4. WENN der Betreiber beim Löschen einer Quelle mit mindestens einem zugeordneten Zitat die Variante Löschabbruch wählt, DANN SOLL die Autoren-API den Vorgang beenden, ohne die Quelle oder eines der zugeordneten Zitate zu verändern.
5. IF der Betreiber die Unbekannt-Quelle löschen will, unabhängig davon, ob dabei die Variante Umhängen, die Variante Löschabbruch oder keine Variante angegeben wird, THEN SOLL die Autoren-API das Löschen ablehnen, die Unbekannt-Quelle und alle ihr zugeordneten Zitate unverändert lassen und eine Fehlermeldung zur Unlöschbarkeit dieser Quelle zurückgeben.
6. WENN das Löschen einer Quelle angehalten wird, weil ihr mindestens ein Zitat zugeordnet ist, DANN SOLL das Frontend der Betreiber*in die Auswahl zwischen genau den zwei Varianten Umhängen und Löschabbruch anzeigen und die gewählte Variante an die Autoren-API übermitteln, die sie ausführt.

### Anforderung 12: Sprachen verwalten

**User Story:** Als Betreiber möchte ich die verfügbaren Zitatsprachen erweitern, damit ich Zitate in beliebigen Sprachen erfassen kann.

#### Akzeptanzkriterien

1. DIE Zitat-API SOLL je Zitat genau eine Sprache zuordnen.
2. DIE Zitat-API SOLL die Menge der verfügbaren Sprachen aus einem dynamisch erweiterbaren Bestand beziehen, der nicht fest auf Deutsch und Englisch begrenzt ist.
3. WENN der Betreiber eine neue Sprache mit einer Bezeichnung von 1 bis 100 Zeichen (nach Entfernen umschließender Leerzeichen) hinzufügt, DANN SOLL die Sprach-API die Sprache speichern und sie anschließend in der Menge der für die Zuordnung zu Zitaten verfügbaren Sprachen zurückgeben.
4. IF der Betreiber eine neue Sprache mit fehlender, leerer oder nur aus Leerzeichen bestehender Bezeichnung hinzufügen will, THEN SOLL die Sprach-API das Hinzufügen ablehnen und eine Fehlermeldung zur fehlenden Bezeichnung zurückgeben.
5. IF der Betreiber eine Sprache hinzufügen will, deren Bezeichnung ohne Beachtung der Groß-/Kleinschreibung bereits im Bestand vorhanden ist, THEN SOLL die Sprach-API das Hinzufügen ablehnen und eine Fehlermeldung zur bereits vorhandenen Sprache zurückgeben.
6. IF der Betreiber eine Sprache mit einer Bezeichnung hinzufügen will, die nach Entfernen umschließender Leerzeichen mehr als 100 Zeichen umfasst, THEN SOLL die Sprach-API das Hinzufügen ablehnen und eine Fehlermeldung zur Überschreitung der zulässigen Länge zurückgeben.
7. DIE Sprach-API SOLL die Liste aller vorhandenen Sprachen ohne gültige Session bereitstellen.

### Anforderung 13: Stets vorhandene Unbekannt-Quelle

**User Story:** Als Betreiber möchte ich, dass immer eine Quelle "unbekannt" existiert, damit Zitate ohne bekannte Herkunft zugeordnet werden können.

#### Akzeptanzkriterien

1. DIE Autoren-API SOLL sicherstellen, dass zu jedem Zeitpunkt genau eine Unbekannt-Quelle mit dem Namen "unbekannt" existiert.
2. IF der Betreiber eine Quelle mit dem Namen "unbekannt" anlegen will, während bereits eine Unbekannt-Quelle existiert, THEN SOLL die Autoren-API das Anlegen ablehnen und eine Fehlermeldung zur Reservierung dieses Namens zurückgeben.
3. WENN ein Zitat angelegt wird, ohne dass eine andere Quelle ausgewählt wird, DANN SOLL die Zitat-API das Zitat der Unbekannt-Quelle zuordnen und speichern.

### Anforderung 14: Mehrsprachige Benutzeroberfläche

**User Story:** Als Besucher*in möchte ich die Oberfläche auf Deutsch oder Englisch nutzen, damit ich die Anwendung in meiner bevorzugten Sprache bedienen kann.

#### Akzeptanzkriterien

1. DAS Frontend SOLL die Oberflächentexte in Deutsch und Englisch bereitstellen.
2. WENN eine Besucher*in die Oberflächensprache auf eine bereitgestellte Sprache wechselt, DANN SOLL das Frontend die angezeigten Oberflächentexte in der gewählten Sprache darstellen.
3. WENN eine Besucher*in die Anwendung erstmals ohne zuvor gewählte Oberflächensprache aufruft, DANN SOLL das Frontend die von den Browser-Spracheinstellungen bevorzugte bereitgestellte Sprache (Deutsch oder Englisch) verwenden und, falls keine der bereitgestellten Sprachen bevorzugt wird, auf Deutsch zurückfallen.
4. DAS Frontend SOLL die Oberflächensprache unabhängig von der Sprache einzelner Zitate behandeln, sodass ein Wechsel der Oberflächensprache die Sprache der angezeigten Zitate nicht verändert.
5. DAS Frontend SOLL in Eingabefeldern mit Längenbeschränkung (Sucheingabe, Zitattext, Quellenname, optionale Quellenfelder, Link-URL, Sprachbezeichnung) die Eingabe auf die jeweils zulässige Maximallänge begrenzen.
6. DAS Frontend SOLL in diesen Eingabefeldern die aktuelle Zeichenanzahl und die zulässige Maximallänge sichtbar anzeigen.

### Anforderung 15: Sichere Ablage der Datenbank-Zugangsdaten

**User Story:** Als Betreiber möchte ich, dass die Datenbank-Zugangsdaten sicher abgelegt sind, damit sie nicht öffentlich zugänglich oder im Repository einsehbar sind.

#### Akzeptanzkriterien

1. DAS Zitate-System SOLL die Datenbank-Zugangsdaten, bestehend aus Host bzw. Adresse, Port, Datenbankname, Benutzername und Passwort, ausschließlich aus einer Quelle beziehen, die außerhalb des öffentlich erreichbaren Web-Roots liegt, oder aus Prozess-Umgebungsvariablen, und SOLL keine Zugangsdaten im Anwendungs-Quellcode fest einbetten.
2. IF beim Start einer Anfrageverarbeitung eines der Zugangsdaten-Felder Host bzw. Adresse, Datenbankname, Benutzername oder Passwort fehlt, THEN SOLL das Zitate-System keine Datenbankverbindung aufbauen, die Verarbeitung abbrechen und eine allgemeine Fehlerantwort zurückgeben, die keine Zugangsdaten-Werte enthält.
3. WHERE kein Port in der Einrichtungskonfiguration angegeben ist, SOLL das Zitate-System den Standard-Port der MariaDB-Datenbank verwenden.
4. DAS Zitate-System SOLL sicherstellen, dass keine Datei, die Datenbank-Zugangsdaten enthält, Teil des versionierten Git-Repository-Inhalts ist (d. h. jede solche Datei ist per Ignore-Regel ausgeschlossen oder liegt außerhalb des Repository-Verzeichnisses).
5. IF eine Datei, die Datenbank-Zugangsdaten enthält, über eine öffentliche URL angefragt wird, THEN SOLL das Zitate-System die Anfrage ablehnen und eine Fehlerantwort zurückgeben, die weder den Dateiinhalt noch einzelne Zugangsdaten-Werte enthält.
6. IF die Quelle der Datenbank-Zugangsdaten fehlt oder nicht lesbar ist, THEN SOLL das Zitate-System keine Datenbankverbindung aufbauen, die Verarbeitung der Anfrage abbrechen und eine allgemeine Fehlerantwort zurückgeben, die keine Zugangsdaten-Werte und keine Angaben zu Pfad oder Name der Zugangsdaten-Quelle enthält.
7. IF beim Verarbeiten einer Anfrage ein unerwarteter interner Fehler oder ein Datenbank-Verbindungs- bzw. Abfragefehler auftritt, THEN SOLL das Zitate-System eine allgemeine Fehlerantwort zurückgeben, die keine Zugangsdaten-Werte, keine SQL-Anweisungen und keine Programmablaufspuren (Stacktraces) enthält, und SOLL keine bereits gespeicherten Daten verändern.

### Anforderung 16: Bereitstellung und Betrieb

**User Story:** Als Betreiber möchte ich die Anwendung auf IONOS Webhosting Pro betreiben, damit sie unter meiner Subdomain erreichbar ist.

#### Akzeptanzkriterien

1. DAS Frontend SOLL als Satz statischer Dateien ausgeliefert werden, die ohne Node.js-Laufzeit auf dem Server auskommen.
2. DAS Zitate-System SOLL die serverseitige Logik als PHP-REST-API bereitstellen, die mit einer MariaDB-Datenbank kommuniziert.
3. WENN eine Anfrage an einen Pfad unterhalb des API-Pfads (z. B. /api) gerichtet ist, DANN SOLL das Zitate-System die Anfrage von der PHP-REST-API beantworten lassen.
4. WENN eine Anfrage an einen Pfad außerhalb des API-Pfads gerichtet ist, DANN SOLL das Zitate-System die statischen Dateien des Frontends ausliefern.
### Anforderung 17: Einrichtung des initialen Betreiber-Accounts

**User Story:** Als Betreiber möchte ich den ersten Verwaltungs-Account über die Einrichtungskonfiguration anlegen, damit ich mich anmelden kann, ohne dass es eine öffentliche Registrierung gibt.

#### Akzeptanzkriterien

1. DAS Zitate-System SOLL Benutzername und Passwort-Hash des initialen Betreiber-Accounts aus der Einrichtungskonfiguration (außerhalb des Web-Roots oder aus Umgebungsvariablen) beziehen.
2. WENN noch kein Betreiber-Account in der Datenbank existiert und die Einrichtungskonfiguration einen Benutzername und einen Passwort-Hash enthält, DANN SOLL das Zitate-System einen Betreiber-Account mit diesen Werten anlegen.
3. WENN bereits ein Betreiber-Account in der Datenbank existiert, DANN SOLL das Zitate-System keinen weiteren Account aus der Einrichtungskonfiguration anlegen und den vorhandenen Account unverändert lassen.
4. DAS Zitate-System SOLL in der Einrichtungskonfiguration ausschließlich einen Passwort-Hash erwarten und zu keinem Zeitpunkt ein Klartext-Passwort für den initialen Account erfordern oder speichern.
5. IF in der Einrichtungskonfiguration der Benutzername oder der Passwort-Hash des initialen Accounts fehlt oder leer ist, während noch kein Betreiber-Account existiert, THEN SOLL das Zitate-System keinen Account anlegen und bei einem Anmeldeversuch den Zugang verweigern.
6. DAS Zitate-System SOLL ein Werkzeug bereitstellen, mit dem der Betreiber aus einem Klartext-Passwort den für die Einrichtungskonfiguration benötigten Passwort-Hash erzeugen kann, ohne dass das Klartext-Passwort dauerhaft abgelegt wird.
