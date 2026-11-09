# Datenbank (MariaDB)

Dieses Verzeichnis enthält das Datenbank-Schema und die Seed-Daten für das
Zitate-System.

- `schema.sql` — Tabellen `source`, `language`, `quote`, `operator`, `session`,
  `login_attempt` (wird in Task 2.1 ausgefüllt).
- `seed.sql` — Seed für genau eine Unbekannt-Quelle
  (`is_unknown = 1`, `name = 'unbekannt'`) sowie ggf. Basisdaten (Task 2.1).

## Integrationstests gegen eine echte MariaDB

Die PDO-/MariaDB-Integrationstests (`api/tests/Integration`, Task 11.4) laufen
nur gegen eine **echte, wegwerfbare** MariaDB. Ohne konfigurierte Datenbank
überspringen sie sich sauber (`markTestSkipped`), sodass `composer test` auch
ohne DB grün bleibt; die reinen Konfig-/Fehlerpfad-Tests (15.2, 15.6, 15.3-Pure,
erzwungener Connect-Fehler 15.7) laufen dagegen überall.

Die Verbindung wird aus einem **eigenen** Satz Umgebungsvariablen gelesen
(getrennt von den produktiven `CITATIONS_DB_*`), damit ein Testlauf niemals
versehentlich eine echte Datenbank anfasst:

| Variable                   | Pflicht | Bedeutung                                              |
|----------------------------|---------|--------------------------------------------------------|
| `CITATIONS_IT_DB_HOST`     | ja      | Host/Adresse der Test-MariaDB                          |
| `CITATIONS_IT_DB_PORT`     | nein    | Port; weglassen ⇒ MariaDB-Standardport (3306, Req 15.3) |
| `CITATIONS_IT_DB_NAME`     | ja      | Name eines **wegwerfbaren** Test-Schemas               |
| `CITATIONS_IT_DB_USER`     | ja      | Benutzer mit vollen Rechten auf diesem Schema          |
| `CITATIONS_IT_DB_PASSWORD` | ja      | Passwort dieses Benutzers                              |

> Warnung: Die Tests **droppen und legen alle Tabellen neu an** und leeren sie
> zwischen den Testfällen. Niemals auf eine produktive Datenbank zeigen lassen.

Beispiel (lokale MariaDB in Docker):

```bash
docker run --rm -d --name citations-it -p 3306:3306 \
  -e MARIADB_ROOT_PASSWORD=root \
  -e MARIADB_DATABASE=citations_it \
  -e MARIADB_USER=citations_it -e MARIADB_PASSWORD=secret \
  mariadb:11

export CITATIONS_IT_DB_HOST=127.0.0.1
export CITATIONS_IT_DB_NAME=citations_it
export CITATIONS_IT_DB_USER=citations_it
export CITATIONS_IT_DB_PASSWORD=secret
# CITATIONS_IT_DB_PORT weglassen => Standardport 3306 (deckt Req 15.3 live ab)

cd api && ./vendor/bin/phpunit --testsuite integration
# oder: composer test:integration
```

Schema (`schema.sql`) und Seed (`seed.sql`) werden von den Tests automatisch in
das konfigurierte Schema eingespielt; eine manuelle Einrichtung ist nicht nötig.

Läuft die Test-DB auf einem **anderen** Port als 3306, wird nur die spezielle
Live-Standardport-Prüfung (15.3) übersprungen; alle übrigen Integrationstests
laufen normal.
