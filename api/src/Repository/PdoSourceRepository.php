<?php

declare(strict_types=1);

namespace Citations\Repository;

use Citations\TextNormalizer;
use PDO;

/**
 * PDO/MariaDB implementation of {@see SourceRepositoryInterface} (Autoren-API,
 * Requirements 5, 10, 11, 13; design PDO data layer, Requirement 16.2).
 *
 * All access uses prepared statements with bound parameters (16.2). Optional
 * fields (`birthDate`, `deathDate`, `description`, `linkUrl`) are stored and
 * returned as null when unset (5.2). Reads project onto the public
 * SourceRecord shape; the integer id and boolean `isUnknown` are cast
 * regardless of the driver's string/int fetch behaviour.
 *
 * {@see self::findUnknown()} returns the single always-present Unbekannt-Quelle
 * (the `is_unknown = 1` row, 13.1); the DB enforces its uniqueness via the
 * generated `is_unknown_flag` unique key. {@see self::existsByNormalizedName()}
 * compares the caller-supplied normalized name against each source name's
 * application-side normal form — the `source` table carries no normalized
 * column, so the normalization mirrors the in-memory double exactly and backs
 * the reserved-"unbekannt" check (13.2). Ordinary inserts/updates always write
 * `is_unknown = 0`; the generated flag column is never written directly.
 */
final class PdoSourceRepository implements SourceRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, birth_date, death_date, description, link_url, is_unknown'
            . ' FROM source WHERE id = :id',
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::toRecord($row);
    }

    public function findUnknown(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, birth_date, death_date, description, link_url, is_unknown'
            . ' FROM source WHERE is_unknown = 1 LIMIT 1',
        );
        $row = $stmt->fetch();

        return self::toRecord($row);
    }

    public function existsByNormalizedName(string $normalizedName): bool
    {
        $stmt = $this->pdo->query('SELECT name FROM source');
        foreach ($stmt as $row) {
            if (TextNormalizer::normalize((string) $row['name']) === $normalizedName) {
                return true;
            }
        }

        return false;
    }

    public function listAll(int $page, int $pageSize): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM source')->fetchColumn();

        // Floor a defensively negative offset to 0 so the bound OFFSET stays in
        // the non-negative range MariaDB accepts; the handler clamps page >= 1
        // and 1 <= pageSize <= 100, so this is a guard, not the normal path.
        $offset = max(0, ($page - 1) * $pageSize);

        // ORDER BY id ASC + LIMIT/OFFSET gives the stable, repeatable paging the
        // list view relies on (19.3); the trimmed list shape carries only id,
        // name and the is_unknown flag (19.2). Emulated prepares are off
        // (PdoConnectionFactory), so LIMIT/OFFSET are bound as integers.
        $stmt = $this->pdo->prepare(
            'SELECT id, name, is_unknown FROM source'
            . ' ORDER BY id ASC'
            . ' LIMIT :limit OFFSET :offset',
        );
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'isUnknown' => (bool) (int) $row['is_unknown'],
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    public function insert(string $name, array $optional = []): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO source'
            . ' (name, birth_date, death_date, description, link_url, is_unknown)'
            . ' VALUES (:name, :birth, :death, :description, :link, 0)',
        );
        $stmt->execute(self::bindings($name, $optional));

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, array $optional = []): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE source SET'
            . ' name = :name, birth_date = :birth, death_date = :death,'
            . ' description = :description, link_url = :link'
            . ' WHERE id = :id',
        );
        $stmt->execute(self::bindings($name, $optional) + [':id' => $id]);

        return $stmt->rowCount() > 0 || $this->findById($id) !== null;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM source WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Build the shared parameter set for insert/update from the trimmed name
     * and the optional fields, defaulting each absent optional field to null.
     *
     * @param array{birthDate?: string|null, deathDate?: string|null, description?: string|null, linkUrl?: string|null} $optional
     *
     * @return array<string, string|null>
     */
    private static function bindings(string $name, array $optional): array
    {
        return [
            ':name' => $name,
            ':birth' => $optional['birthDate'] ?? null,
            ':death' => $optional['deathDate'] ?? null,
            ':description' => $optional['description'] ?? null,
            ':link' => $optional['linkUrl'] ?? null,
        ];
    }

    /**
     * Project a raw DB row onto the public SourceRecord shape, casting id to
     * int and the `is_unknown` flag to bool regardless of fetch typing.
     *
     * @param array<string, mixed> $row
     *
     * @return array{id: int, name: string, birthDate: string|null, deathDate: string|null, description: string|null, linkUrl: string|null, isUnknown: bool}
     */
    private static function toRecord(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'birthDate' => $row['birth_date'] === null ? null : (string) $row['birth_date'],
            'deathDate' => $row['death_date'] === null ? null : (string) $row['death_date'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'linkUrl' => $row['link_url'] === null ? null : (string) $row['link_url'],
            'isUnknown' => (bool) (int) $row['is_unknown'],
        ];
    }
}
