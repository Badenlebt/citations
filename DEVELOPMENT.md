# Lokale Entwicklung

So bringst du die komplette Zitate-Anwendung (MariaDB + PHP-API + Vue-Frontend)
lokal zum Laufen.

## Voraussetzungen

- Docker (für die MariaDB)
- PHP >= 8.1 mit `pdo_mysql`, Composer
- Node.js + npm

## Schnellstart

```bash
./dev.sh
```

Das Skript:

1. startet MariaDB via `docker-compose.dev.yml` und lädt beim ersten Start
   automatisch `db/schema.sql` und `db/seed.sql`,
2. installiert bei Bedarf `api/vendor` (composer) und `frontend/node_modules`,
3. startet die PHP-REST-API auf <http://localhost:8000>,
4. startet den Vite-Dev-Server auf <http://localhost:5173> (proxyt `/api` → :8000).

Dann im Browser <http://localhost:5173> öffnen.

- **Admin-Login:** Benutzername `admin`, Passwort `admin`
- **API direkt testen:** <http://localhost:8000/api/random>

Mit `Ctrl-C` werden PHP und Vite gestoppt. Die Datenbank läuft im Hintergrund
weiter.

## Alles stoppen

Nach `Ctrl-C` auf `./dev.sh` laufen die Server normalerweise nicht mehr, aber die
Datenbank läuft weiter. `./stop.sh` räumt alles restlos auf (auch übrig
gebliebene PHP-/Vite-Prozesse, falls `dev.sh` abgestürzt ist):

```bash
./stop.sh          # Server + DB stoppen, Daten behalten
./stop.sh --wipe   # zusätzlich die DB-Daten löschen (nächster Start: frisch)
```

## Datenbank stoppen / zurücksetzen

```bash
docker compose -f docker-compose.dev.yml down      # stoppen, Daten behalten
docker compose -f docker-compose.dev.yml down -v   # stoppen + Daten löschen
```

Nach `down -v` wird beim nächsten `./dev.sh` das Schema + der Seed frisch geladen.

## config.php

`config.php` liegt im Repo-Wurzelverzeichnis (oberhalb des `api/`-Web-Roots) und
ist per `.gitignore` ausgeschlossen. Sie enthält DB-Zugangsdaten und den
initialen Betreiber-Account. Die Werte passen zu `docker-compose.dev.yml`.

Einen neuen Passwort-Hash erzeugen:

```bash
printf '%s' 'DEIN_PASSWORT' | php api/bin/hash-password --stdin
```

Den ausgegebenen Hash unter `initialOperator.passwordHash` in `config.php`
eintragen. Der initiale Account wird nur angelegt, wenn noch kein Betreiber in
der DB existiert (Anf. 17.3) — ggf. `docker compose ... down -v` zum Zurücksetzen.

## Manuell starten (ohne dev.sh)

```bash
# 1) DB
docker compose -f docker-compose.dev.yml up -d

# 2) API (Repo-Wurzel als Startverzeichnis; index.php ist der Router)
php -S localhost:8000 api/index.php

# 3) Frontend
cd frontend && npm run dev
```
