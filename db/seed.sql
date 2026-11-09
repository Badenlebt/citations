-- Seed data for the citations management system.
--
-- Seeds exactly one Unbekannt-Quelle: is_unknown = 1, name = 'unbekannt'
-- (Anf. 13.1). The UNIQUE key uq_source_is_unknown on the generated
-- is_unknown_flag column guarantees no second is_unknown = 1 row can be added.

SET NAMES utf8mb4;

INSERT INTO source (name, is_unknown)
VALUES ('unbekannt', 1);
