-- Migration 001: Zuordnung Oberflächensprache -> Zitat-Sprache (Anf. 9.1, 9.2).
-- Für bestehende Datenbanken; idempotent durch IF NOT EXISTS.
-- Neue Datenbanken erhalten die Tabelle bereits über db/schema.sql.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ui_language_mapping (
    ui_language CHAR(2) NOT NULL,                 -- 'de' | 'en'
    language_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (ui_language),                    -- höchstens ein Eintrag je Oberflächensprache
    UNIQUE KEY uq_ui_language_mapping_language (language_id),  -- jede Zitat-Sprache höchstens einmal
    CONSTRAINT chk_ui_language_mapping_ui CHECK (ui_language IN ('de', 'en')),
    CONSTRAINT fk_ui_language_mapping_language
        FOREIGN KEY (language_id) REFERENCES language (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
