<?php

declare(strict_types=1);

namespace Citations\Repository;

use Citations\TextNormalizer;
use PDO;

/**
 * PDO/MariaDB implementation of {@see QuoteRepositoryInterface} (Zitat-API,
 * Requirements 8, 9, 13.3; design PDO data layer, Requirement 16.2).
 *
 * All access uses prepared statements with bound parameters (16.2). The caller
 * supplies the already-trimmed text; the `text_normalized` column (design Data
 * Models, 2.6) is derived here on every write via {@see TextNormalizer}, never
 * supplied by callers. {@see self::findById()} joins the quote with its source
 * and language to produce the public joined QuoteRecord shape, mirroring the
 * in-memory double so an edited source name or a reassign is reflected
 * immediately in quote reads.
 *
 * {@see self::reassignSource()} moves every quote off one source onto another
 * and returns the number of rows moved — the operation the reassign delete
 * strategy relies on (11.3); {@see self::countBySource()} drives the has-quotes
 * conflict decision (11.1 vs 11.2).
 */
final class PdoQuoteRepository implements QuoteRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT q.id, q.text, q.language_id,'
            . ' l.name AS language_name,'
            . ' s.id AS source_id, s.name AS source_name, s.link_url AS source_link_url'
            . ' FROM quote q'
            . ' JOIN language l ON l.id = q.language_id'
            . ' JOIN source s ON s.id = q.source_id'
            . ' WHERE q.id = :id',
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::toRecord($row);
    }

    public function findByIndex(int $index): ?array
    {
        // A negative position can never address a row; short-circuit so the
        // bound OFFSET stays within the non-negative range MariaDB accepts.
        if ($index < 0) {
            return null;
        }

        // Stable ORDER BY id ASC + LIMIT 1 OFFSET makes the same index always
        // map to the same quote for a given stock (3.1), the DB counterpart to
        // the pure index chosen by RandomSelector. Emulated prepares are off
        // (PdoConnectionFactory), so the OFFSET is bound as an integer rather
        // than inlined.
        $stmt = $this->pdo->prepare(
            'SELECT q.id, q.text, q.language_id,'
            . ' l.name AS language_name,'
            . ' s.id AS source_id, s.name AS source_name, s.link_url AS source_link_url'
            . ' FROM quote q'
            . ' JOIN language l ON l.id = q.language_id'
            . ' JOIN source s ON s.id = q.source_id'
            . ' ORDER BY q.id ASC'
            . ' LIMIT 1 OFFSET :offset',
        );
        $stmt->bindValue(':offset', $index, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : self::toRecord($row);
    }

    public function findAllForSearch(?int $sourceId = null): array
    {
        // The whole candidate set is read joined with language and source so the
        // application-layer search can match, score and shape each hit without a
        // second lookup (design "normalisierter Vergleich in der
        // Anwendungsschicht"). ORDER BY q.id ASC gives the stable, repeatable
        // ordering the tie-break relies on (2.7). An optional source_id filter
        // backs the per-source search restriction (5.3); it is bound as a
        // parameter, never inlined.
        $sql = 'SELECT q.id, q.text, q.language_id,'
            . ' l.name AS language_name,'
            . ' s.id AS source_id, s.name AS source_name, s.link_url AS source_link_url'
            . ' FROM quote q'
            . ' JOIN language l ON l.id = q.language_id'
            . ' JOIN source s ON s.id = q.source_id';

        if ($sourceId !== null) {
            $sql .= ' WHERE q.source_id = :source_id';
        }

        $sql .= ' ORDER BY q.id ASC';

        $stmt = $this->pdo->prepare($sql);
        if ($sourceId !== null) {
            $stmt->bindValue(':source_id', $sourceId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $records = [];
        foreach ($stmt->fetchAll() as $row) {
            $records[] = self::toRecord($row);
        }

        return $records;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM quote WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->fetchColumn() !== false;
    }

    public function count(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM quote');

        return (int) $stmt->fetchColumn();
    }

    public function listAll(int $page, int $pageSize): array
    {
        $total = $this->count();

        // Floor a defensively negative offset to 0 so the bound OFFSET stays in
        // the non-negative range MariaDB accepts; the handler clamps page >= 1
        // and 1 <= pageSize <= 100, so this is a guard, not the normal path.
        $offset = max(0, ($page - 1) * $pageSize);

        // ORDER BY q.id ASC + LIMIT/OFFSET gives the stable, repeatable paging
        // the list view relies on (18.3); the trimmed list shape drops the
        // single view's source link_url (18.2). Emulated prepares are off
        // (PdoConnectionFactory), so LIMIT/OFFSET are bound as integers.
        $stmt = $this->pdo->prepare(
            'SELECT q.id, q.text, q.language_id,'
            . ' l.name AS language_name,'
            . ' s.id AS source_id, s.name AS source_name'
            . ' FROM quote q'
            . ' JOIN language l ON l.id = q.language_id'
            . ' JOIN source s ON s.id = q.source_id'
            . ' ORDER BY q.id ASC'
            . ' LIMIT :limit OFFSET :offset',
        );
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = self::toListItem($row);
        }

        return ['items' => $items, 'total' => $total];
    }

    public function insert(string $text, int $sourceId, int $languageId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO quote (text, text_normalized, source_id, language_id)'
            . ' VALUES (:text, :normalized, :source_id, :language_id)',
        );
        $stmt->execute([
            ':text' => $text,
            ':normalized' => TextNormalizer::normalize($text),
            ':source_id' => $sourceId,
            ':language_id' => $languageId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $text, int $sourceId, int $languageId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE quote SET'
            . ' text = :text, text_normalized = :normalized,'
            . ' source_id = :source_id, language_id = :language_id'
            . ' WHERE id = :id',
        );
        $stmt->execute([
            ':text' => $text,
            ':normalized' => TextNormalizer::normalize($text),
            ':source_id' => $sourceId,
            ':language_id' => $languageId,
            ':id' => $id,
        ]);

        // rowCount() is 0 when the row matched but no column value changed; an
        // existence probe disambiguates that from a truly missing id (9.5).
        return $stmt->rowCount() > 0 || $this->exists($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM quote WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function countBySource(int $sourceId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM quote WHERE source_id = :source_id',
        );
        $stmt->execute([':source_id' => $sourceId]);

        return (int) $stmt->fetchColumn();
    }

    public function reassignSource(int $fromSourceId, int $toSourceId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE quote SET source_id = :to WHERE source_id = :from',
        );
        $stmt->execute([
            ':to' => $toSourceId,
            ':from' => $fromSourceId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Project a joined DB row onto the public QuoteRecord shape, casting the
     * integer ids regardless of the driver's string/int fetch behaviour.
     *
     * @param array<string, mixed> $row
     *
     * @return array{id: int, text: string, languageId: int, languageName: string, source: array{id: int, name: string, linkUrl: string|null}}
     */
    private static function toRecord(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'text' => (string) $row['text'],
            'languageId' => (int) $row['language_id'],
            'languageName' => (string) $row['language_name'],
            'source' => [
                'id' => (int) $row['source_id'],
                'name' => (string) $row['source_name'],
                'linkUrl' => $row['source_link_url'] === null
                    ? null
                    : (string) $row['source_link_url'],
            ],
        ];
    }

    /**
     * Project a joined DB row onto the trimmed QuoteListItem shape (management
     * list view, 18.2), casting the integer ids regardless of the driver's
     * fetch typing. Unlike {@see self::toRecord()} it carries no source
     * `linkUrl`.
     *
     * @param array<string, mixed> $row
     *
     * @return array{id: int, text: string, languageId: int, languageName: string, source: array{id: int, name: string}}
     */
    private static function toListItem(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'text' => (string) $row['text'],
            'languageId' => (int) $row['language_id'],
            'languageName' => (string) $row['language_name'],
            'source' => [
                'id' => (int) $row['source_id'],
                'name' => (string) $row['source_name'],
            ],
        ];
    }
}
