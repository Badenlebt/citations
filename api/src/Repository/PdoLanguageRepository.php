<?php

declare(strict_types=1);

namespace Citations\Repository;

use PDO;

/**
 * PDO/MariaDB implementation of {@see LanguageRepositoryInterface} (Sprach-API,
 * Requirement 12; design "Datenbank – MariaDB, erreicht über PDO mit
 * vorbereiteten Anweisungen", Requirement 16.2).
 *
 * Every statement is a prepared statement with bound parameters (16.2); no
 * value is ever interpolated into SQL. The case-insensitive display name and
 * its normalized form are both persisted: `name` holds the trimmed display
 * value supplied by the caller and `name_normalized` its lowercased normal
 * form, which backs the duplicate check (12.5). Reads project onto the public
 * LanguageRecord shape (id, name) and never expose the normalized column.
 *
 * {@see self::findAll()} orders by id so iteration is stable and repeatable
 * (12.7, 1.10), mirroring the in-memory double.
 */
final class PdoLanguageRepository implements LanguageRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name FROM language WHERE id = :id',
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::toRecord($row);
    }

    public function exists(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM language WHERE id = :id',
        );
        $stmt->execute([':id' => $id]);

        return $stmt->fetchColumn() !== false;
    }

    public function existsByNormalizedName(string $normalizedName): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM language WHERE name_normalized = :name',
        );
        $stmt->execute([':name' => $normalizedName]);

        return $stmt->fetchColumn() !== false;
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, name FROM language ORDER BY id ASC');

        $result = [];
        foreach ($stmt as $row) {
            $result[] = self::toRecord($row);
        }

        return $result;
    }

    public function insert(string $name, string $normalizedName): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO language (name, name_normalized) VALUES (:name, :normalized)',
        );
        $stmt->execute([
            ':name' => $name,
            ':normalized' => $normalizedName,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Project a raw DB row onto the public LanguageRecord shape, casting the
     * integer id regardless of the driver's string/int fetch behaviour.
     *
     * @param array<string, mixed> $row
     *
     * @return array{id: int, name: string}
     */
    private static function toRecord(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ];
    }
}
