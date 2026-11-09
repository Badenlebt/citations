<?php

declare(strict_types=1);

namespace Citations\Tests\Fake;

use Citations\Repository\QuoteRepositoryInterface;

/**
 * Interface-identical in-memory {@see QuoteRepositoryInterface} test double
 * (task 2.3) for property-based and example tests of the quote mutation layer
 * without a real database.
 *
 * FK/reference behaviour modelled here:
 *  - A quote stores only its `sourceId` and `languageId`; the joined read shape
 *    (languageName, source name/linkUrl) is resolved against the current rows
 *    in the shared {@see InMemoryStore}, mirroring the SQL join so an edit to a
 *    source name or a reassign is immediately reflected in quote reads.
 *  - {@see self::reassignSource()} moves every quote off one source onto
 *    another, the operation the reassign delete strategy relies on
 *    (Requirement 11.3); {@see self::countBySource()} drives the has-quotes
 *    conflict decision (11.1 vs 11.2).
 *
 * The caller guarantees referential validity on insert/update (trimmed,
 * length-valid text; existing source and language), exactly as the real
 * interface states; this double does not re-validate.
 */
final class InMemoryQuoteRepository implements QuoteRepositoryInterface
{
    public function __construct(private InMemoryStore $store)
    {
    }

    public function findById(int $id): ?array
    {
        $row = $this->store->quotes[$id] ?? null;

        return $row === null ? null : $this->toRecord($row);
    }

    public function findByIndex(int $index): ?array
    {
        if ($index < 0) {
            return null;
        }

        // Mirror the PDO ORDER BY q.id ASC so the same index addresses the same
        // quote as the real repository. The store keys are quote ids; sorting
        // them yields the stable ascending-id ordering.
        $ids = array_keys($this->store->quotes);
        sort($ids);

        $id = $ids[$index] ?? null;

        return $id === null ? null : $this->toRecord($this->store->quotes[$id]);
    }

    public function findAllForSearch(?int $sourceId = null): array
    {
        // Mirror the PDO ORDER BY q.id ASC so the candidate set is in the same
        // stable ascending-id order as the real repository, which the search
        // tie-break (2.7) depends on. The optional source_id restriction models
        // the per-source search (5.3).
        $ids = array_keys($this->store->quotes);
        sort($ids);

        $records = [];
        foreach ($ids as $id) {
            $row = $this->store->quotes[$id];
            if ($sourceId !== null && $row['sourceId'] !== $sourceId) {
                continue;
            }
            $records[] = $this->toRecord($row);
        }

        return $records;
    }

    public function exists(int $id): bool
    {
        return isset($this->store->quotes[$id]);
    }

    public function count(): int
    {
        return count($this->store->quotes);
    }

    public function listAll(int $page, int $pageSize): array
    {
        $total = count($this->store->quotes);

        // Mirror the PDO ORDER BY q.id ASC so the same page addresses the same
        // quotes as the real repository (18.3). Store keys are quote ids;
        // sorting them yields the stable ascending-id ordering.
        $ids = array_keys($this->store->quotes);
        sort($ids);

        // Floor a defensively negative offset to 0; the handler clamps page >= 1
        // and 1 <= pageSize <= 100, so this is a guard, not the normal path.
        $offset = max(0, ($page - 1) * $pageSize);
        $pageIds = array_slice($ids, $offset, $pageSize);

        $items = [];
        foreach ($pageIds as $id) {
            $items[] = $this->toListItem($this->store->quotes[$id]);
        }

        return ['items' => $items, 'total' => $total];
    }

    public function insert(string $text, int $sourceId, int $languageId): int
    {
        $id = $this->store->allocateQuoteId();
        $this->store->quotes[$id] = [
            'id' => $id,
            'text' => $text,
            'sourceId' => $sourceId,
            'languageId' => $languageId,
        ];

        return $id;
    }

    public function update(int $id, string $text, int $sourceId, int $languageId): bool
    {
        if (!isset($this->store->quotes[$id])) {
            return false;
        }

        $this->store->quotes[$id] = [
            'id' => $id,
            'text' => $text,
            'sourceId' => $sourceId,
            'languageId' => $languageId,
        ];

        return true;
    }

    public function delete(int $id): bool
    {
        if (!isset($this->store->quotes[$id])) {
            return false;
        }

        unset($this->store->quotes[$id]);

        return true;
    }

    public function countBySource(int $sourceId): int
    {
        $count = 0;
        foreach ($this->store->quotes as $row) {
            if ($row['sourceId'] === $sourceId) {
                $count++;
            }
        }

        return $count;
    }

    public function reassignSource(int $fromSourceId, int $toSourceId): int
    {
        $moved = 0;
        foreach ($this->store->quotes as $id => $row) {
            if ($row['sourceId'] === $fromSourceId) {
                $this->store->quotes[$id]['sourceId'] = $toSourceId;
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * Resolve a stored quote row into the public joined QuoteRecord shape by
     * looking up its current source and language. Models the SQL join.
     *
     * @param array{id: int, text: string, sourceId: int, languageId: int} $row
     *
     * @return array{id: int, text: string, languageId: int, languageName: string, source: array{id: int, name: string, linkUrl: string|null}}
     */
    private function toRecord(array $row): array
    {
        $source = $this->store->sources[$row['sourceId']] ?? null;
        $language = $this->store->languages[$row['languageId']] ?? null;

        return [
            'id' => $row['id'],
            'text' => $row['text'],
            'languageId' => $row['languageId'],
            'languageName' => $language['name'] ?? '',
            'source' => [
                'id' => $row['sourceId'],
                'name' => $source['name'] ?? '',
                'linkUrl' => $source['linkUrl'] ?? null,
            ],
        ];
    }

    /**
     * Resolve a stored quote row into the trimmed QuoteListItem shape
     * (management list view, 18.2) by looking up its current source and
     * language. Unlike {@see self::toRecord()} it carries no source linkUrl.
     *
     * @param array{id: int, text: string, sourceId: int, languageId: int} $row
     *
     * @return array{id: int, text: string, languageId: int, languageName: string, source: array{id: int, name: string}}
     */
    private function toListItem(array $row): array
    {
        $source = $this->store->sources[$row['sourceId']] ?? null;
        $language = $this->store->languages[$row['languageId']] ?? null;

        return [
            'id' => $row['id'],
            'text' => $row['text'],
            'languageId' => $row['languageId'],
            'languageName' => $language['name'] ?? '',
            'source' => [
                'id' => $row['sourceId'],
                'name' => $source['name'] ?? '',
            ],
        ];
    }
}
