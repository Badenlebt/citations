<?php
declare(strict_types=1);
namespace Citations\Tests\Integration;
use Citations\Repository\PdoQuoteRepository;
use Citations\Repository\PdoSourceRepository;
/**
 * Integration tests for countByLanguage / findByIndexInLanguage against a real
 * MariaDB (task 1.6; Requirements 9.1, 9.2, 9.13). Skipped without a test DB.
 */
final class QuoteLanguageSubstockIntegrationTest extends IntegrationTestCase
{
    public function testCountAndIndexAccessAreRestrictedToOneLanguageInIdOrder(): void
    {
        $pdo = $this->requireDatabase();
        $quotes = new PdoQuoteRepository($pdo);
        $sourceId = (new PdoSourceRepository($pdo))->findUnknown()['id'];
        $de = $this->seedLanguage($pdo, 'Deutsch', 'deutsch');
        $en = $this->seedLanguage($pdo, 'English', 'english');
        $empty = $this->seedLanguage($pdo, 'Latein', 'latein');
        $d1 = $quotes->insert('Eins', $sourceId, $de);
        $e1 = $quotes->insert('One', $sourceId, $en);
        $d2 = $quotes->insert('Zwei', $sourceId, $de);
        $e2 = $quotes->insert('Two', $sourceId, $en);
        $d3 = $quotes->insert('Drei', $sourceId, $de);
        $this->assertSame(3, $quotes->countByLanguage($de));
        $this->assertSame(2, $quotes->countByLanguage($en));
        $this->assertSame(0, $quotes->countByLanguage($empty));
        $this->assertSame(0, $quotes->countByLanguage($empty + 1000));
        foreach ([$d1, $d2, $d3] as $i => $id) {
            $record = $quotes->findByIndexInLanguage($de, $i);
            $this->assertNotNull($record);
            $this->assertSame($id, $record['id']);
            $this->assertSame($de, $record['languageId']);
            $this->assertSame('Deutsch', $record['languageName']);
            $this->assertSame($sourceId, $record['source']['id']);
        }
        $this->assertSame($e1, $quotes->findByIndexInLanguage($en, 0)['id']);
        $this->assertSame($e2, $quotes->findByIndexInLanguage($en, 1)['id']);
    }
    public function testIndexOutOfRangeOrNegativeOrEmptyLanguageReturnsNull(): void
    {
        $pdo = $this->requireDatabase();
        $quotes = new PdoQuoteRepository($pdo);
        $sourceId = (new PdoSourceRepository($pdo))->findUnknown()['id'];
        $de = $this->seedLanguage($pdo, 'Deutsch', 'deutsch');
        $empty = $this->seedLanguage($pdo, 'Latein', 'latein');
        $quotes->insert('Eins', $sourceId, $de);
        $this->assertNull($quotes->findByIndexInLanguage($de, 1));
        $this->assertNull($quotes->findByIndexInLanguage($de, -1));
        $this->assertNull($quotes->findByIndexInLanguage($empty, 0));
    }
}
