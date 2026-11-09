# Citations API (PHP / PDO / MariaDB)

Schlanke PHP-REST-API für das Zitate-System.

## Voraussetzungen

- PHP >= 8.1 mit den Extensions `pdo`, `mbstring`, `intl`
- Composer

## Einrichtung

```bash
composer install
```

## Tests ausführen

```bash
composer test            # alle Tests (Unit + Property)
composer test:property   # nur Property-Tests
vendor/bin/phpunit --testsuite unit   # nur Unit-Tests
```

## Property-Tests

Property-basierte Tests nutzen die Bibliothek `giorgiosironi/eris` und erben von
`Citations\Tests\Property\PropertyTestCase`. Diese Basisklasse erzwingt die
projektweite Konvention von **mindestens 100 Iterationen** pro Property
(`$this->iterations()`, Default via `ERIS_ITERATIONS` in `phpunit.xml`, nie < 100).

Jeder Property-Test referenziert genau eine Property aus `design.md` und trägt den
Tag `Feature: citations-management, Property N: ...`.

## Konfiguration / Credentials

Zugangsdaten werden aus einer Quelle **oberhalb des Web-Roots** oder aus
Umgebungsvariablen bezogen (siehe `config.php.example`). Die reale `config.php`
ist per `.gitignore` ausgeschlossen (Req 15.1, 15.4).
