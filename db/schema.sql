-- MariaDB schema for the citations management system.
--
-- Data Models per design.md (Anf. 5, 6, 8-13, 16.2). All tables use the
-- utf8mb4 character set with the utf8mb4_unicode_ci collation so that the
-- DB layer provides a diacritics-/case-insensitive candidate prefilter,
-- while the final relevance scoring happens on the normalized columns in PHP.
--
-- Normalized columns (text_normalized, name_normalized) hold the lowercased,
-- diacritics-free normal form written by the application layer (Anf. 2.6).

SET NAMES utf8mb4;

-- Quelle (Autor*in / unbenannte Quelle). Exactly one row is the Unbekannt-Quelle
-- (is_unknown = 1, name = 'unbekannt'); seeded in seed.sql (Anf. 13.1).
CREATE TABLE source (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(200) NOT NULL,              -- 1..200 nach Trim
    birth_date  VARCHAR(500) NULL DEFAULT NULL,     -- optional, 0..500
    death_date  VARCHAR(500) NULL DEFAULT NULL,     -- optional, 0..500
    description VARCHAR(500) NULL DEFAULT NULL,      -- optional, 0..500
    link_url    VARCHAR(2000) NULL DEFAULT NULL,     -- optional, 0..2000; falls gesetzt http(s)://
    is_unknown  TINYINT(1) NOT NULL DEFAULT 0,       -- genau eine Zeile = 1 (Anf. 13.1)
    -- Generated helper for the single-Unbekannt-Quelle invariant: it is 1 for the
    -- Unbekannt-Quelle and NULL otherwise. A UNIQUE key over it rejects a second
    -- is_unknown = 1 row (MySQL/MariaDB allow repeated NULLs in a unique index),
    -- so at most one Unbekannt-Quelle can exist (Anf. 13.1).
    is_unknown_flag TINYINT(1)
        AS (CASE WHEN is_unknown = 1 THEN 1 ELSE NULL END) VIRTUAL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_source_is_unknown (is_unknown_flag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sprache (Anf. 12). name_normalized is the lowercased normal form used for
-- case-insensitive uniqueness (Anf. 12.5).
CREATE TABLE language (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(100) NOT NULL,          -- 1..100 nach Trim
    name_normalized VARCHAR(100) NOT NULL,          -- kleingeschriebene Normalform
    PRIMARY KEY (id),
    UNIQUE KEY uq_language_name_normalized (name_normalized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zitat (Anf. 1, 4, 8, 9, 12). Each quote references exactly one source and
-- exactly one language (Anf. 12.1). text_normalized feeds prefilter + scoring
-- (Anf. 2.6). FKs prevent orphaned quotes; deleting a source with quotes is only
-- possible via reassign to the Unbekannt-Quelle in the app layer (Anf. 11.3).
CREATE TABLE quote (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    text            TEXT NOT NULL,                  -- 1..5000 nach Trim
    text_normalized TEXT NOT NULL,                  -- kleingeschrieben, diakritika-frei
    source_id       INT UNSIGNED NOT NULL,
    language_id     INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_quote_source_id (source_id),
    KEY idx_quote_language_id (language_id),
    CONSTRAINT fk_quote_source
        FOREIGN KEY (source_id) REFERENCES source (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_quote_language
        FOREIGN KEY (language_id) REFERENCES language (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Betreiber (Anf. 6, 17). Only the password hash is stored (Anf. 6.7).
-- Multiple accounts are supported, initially one (Anf. 6.8).
CREATE TABLE operator (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_operator_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Session (Anf. 6, 7). token = hex of random_bytes(32) => 64 chars.
-- Valid while now < expires_at and the row exists; logout deletes the row
-- (Anf. 6.6). expires_at = created_at + Session-Dauer (Anf. 6.4).
CREATE TABLE session (
    token       CHAR(64) NOT NULL,
    operator_id INT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL,
    expires_at  DATETIME NOT NULL,
    PRIMARY KEY (token),
    KEY idx_session_operator_id (operator_id),
    KEY idx_session_expires_at (expires_at),
    CONSTRAINT fk_session_operator
        FOREIGN KEY (operator_id) REFERENCES operator (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login-Versuche (Anf. 6.5). Records attempts per username for the rate-limit
-- window (5 failures / 15 min => 15 min lockout).
CREATE TABLE login_attempt (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username     VARCHAR(255) NOT NULL,
    attempted_at DATETIME NOT NULL,
    success      TINYINT(1) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_attempt_window (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
