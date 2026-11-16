<?php
declare(strict_types=1);
namespace Citations\Tests\Integration;
use Citations\Repository\PdoUiLanguageMappingRepository;
/**
 * Integration tests for the 1:1 UI-language mapping against a real MariaDB
 * (task 1.6; Requirements 9.1, 9.2, 9.13). Skipped without a test DB.
 */
final class UiLanguageMappingIntegrationTest extends IntegrationTestCase
{
    public function testAssignStoresMapping(): void
    {
        $pdo = $this->requireDatabase();
        $repo = new PdoUiLanguageMappingRepository($pdo);
        $de = $this->seedLanguage($pdo, 'Deutsch', 'deutsch');
        $this->assertTrue($repo->assign('de', $de));
        $this->assertSame(['de' => $de], $repo->findAll());
        $this->assertSame('de', $repo->findUiLanguageByLanguageId($de));
    }
    public function testAssignReturnsFalseWhenLanguageAlreadyMappedToOtherUiLanguage(): void
    {
        $pdo = $this->requireDatabase();
        $repo = new PdoUiLanguageMappingRepository($pdo);
        $de = $this->seedLanguage($pdo, 'Deutsch', 'deutsch');
        $this->assertTrue($repo->assign('de', $de));
        $this->assertFalse($repo->assign('en', $de));
        $this->assertSame(['de' => $de], $repo->findAll());
    }
    public function testAssignReplacesExistingMappingOfSameUiLanguage(): void
    {
        $pdo = $this->requireDatabase();
        $repo = new PdoUiLanguageMappingRepository($pdo);
        $a = $this->seedLanguage($pdo, 'Deutsch', 'deutsch');
        $b = $this->seedLanguage($pdo, 'Latein', 'latein');
        $this->assertTrue($repo->assign('de', $a));
        $this->assertTrue($repo->assign('de', $b));
        $this->assertSame(['de' => $b], $repo->findAll());
        $this->assertNull($repo->findUiLanguageByLanguageId($a));
    }
    public function testRemoveDeletesMappingAndIsIdempotent(): void
    {
        $pdo = $this->requireDatabase();
        $repo = new PdoUiLanguageMappingRepository($pdo);
        $a = $this->seedLanguage($pdo, 'Deutsch', 'deutsch');
        $repo->assign('de', $a);
        $repo->remove('de');
        $this->assertSame([], $repo->findAll());
        $repo->remove('de');
        $this->assertSame([], $repo->findAll());
        // The language is free again and can be assigned to another UI language.
        $this->assertTrue($repo->assign('en', $a));
    }
    public function testDeletingLanguageCascadesToMapping(): void
    {
        $pdo = $this->requireDatabase();
        $repo = new PdoUiLanguageMappingRepository($pdo);
        $a = $this->seedLanguage($pdo, 'Deutsch', 'deutsch');
        $b = $this->seedLanguage($pdo, 'English', 'english');
        $repo->assign('de', $a);
        $repo->assign('en', $b);
        $pdo->prepare('DELETE FROM language WHERE id = :id')->execute([':id' => $a]);
        $this->assertSame(['en' => $b], $repo->findAll());
    }
}
