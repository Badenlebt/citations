<?php

declare(strict_types=1);

namespace Citations\Repository;

use PDO;
use PDOException;

/**
 * PDO/MariaDB implementation of {@see UiLanguageMappingRepositoryInterface}.
 *
 * Prepared statements with bound parameters only (Requirement 16.2). The 1:1
 * property is guaranteed by the schema: PRIMARY KEY (ui_language) and
 * UNIQUE KEY uq_ui_language_mapping_language (language_id).
 */
final class PdoUiLanguageMappingRepository implements UiLanguageMappingRepositoryInterface
{
    private const UNIQUE_LANGUAGE_KEY = 'uq_ui_language_mapping_language';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT ui_language, language_id FROM ui_language_mapping ORDER BY ui_language ASC',
        );

        $result = [];
        foreach ($stmt as $row) {
            $result[(string) $row['ui_language']] = (int) $row['language_id'];
        }

        return $result;
    }

    public function findUiLanguageByLanguageId(int $languageId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT ui_language FROM ui_language_mapping WHERE language_id = :languageId',
        );
        $stmt->execute([':languageId' => $languageId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function assign(string $uiLanguage, int $languageId): bool
    {
        // INSERT first. ON DUPLICATE KEY UPDATE is deliberately not used: it
        // fires on ANY unique key, so a language already mapped to another UI
        // language would silently update that other row instead of failing.
        $insert = $this->pdo->prepare(
            'INSERT INTO ui_language_mapping (ui_language, language_id) '
            . 'VALUES (:uiLanguage, :languageId)',
        );

        try {
            $insert->execute([
                ':uiLanguage' => $uiLanguage,
                ':languageId' => $languageId,
            ]);

            return true;
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            // Quote language already mapped to another UI language.
            if (str_contains($e->getMessage(), self::UNIQUE_LANGUAGE_KEY)) {
                return false;
            }
            // Otherwise the UI language already has an entry (primary key):
            // replace it below. Other integrity errors (e.g. FK) are rethrown.
            if (!str_contains($e->getMessage(), 'PRIMARY')) {
                throw $e;
            }
        }

        $update = $this->pdo->prepare(
            'UPDATE ui_language_mapping SET language_id = :languageId WHERE ui_language = :uiLanguage',
        );

        try {
            $update->execute([
                ':uiLanguage' => $uiLanguage,
                ':languageId' => $languageId,
            ]);
        } catch (PDOException $e) {
            if (
                (string) $e->getCode() === '23000'
                && str_contains($e->getMessage(), self::UNIQUE_LANGUAGE_KEY)
            ) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public function remove(string $uiLanguage): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ui_language_mapping WHERE ui_language = :uiLanguage',
        );
        $stmt->execute([':uiLanguage' => $uiLanguage]);
    }
}
