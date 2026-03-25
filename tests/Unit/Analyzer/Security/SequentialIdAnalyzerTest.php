<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\SequentialIdAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class SequentialIdAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Cree un repertoire temporaire unique pour chaque test.
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
        mkdir($this->tempDir . '/src/Entity', 0777, true);
    }

    protected function tearDown(): void
    {
        // Nettoyage du repertoire temporaire apres chaque test.
        $this->deleteDirectory($this->tempDir);
    }

    // --- Helper : creer un analyzer pointe sur le repertoire temporaire ---

    private function createAnalyzer(): SequentialIdAnalyzer
    {
        return new SequentialIdAnalyzer($this->tempDir);
    }

    // Helper : creer un rapport vide pour chaque test.
    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::SECURITY]);
    }

    // Helper : supprimer un repertoire recursivement.
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    // =============================================
    // Test 1 : Entity avec ApiResource + auto-increment => WARNING
    // =============================================

    public function testApiResourceWithAutoIncrementCreatesWarning(): void
    {
        // Arrange : une entite exposee via ApiResource avec un ID auto-increment
        file_put_contents($this->tempDir . '/src/Entity/Product.php', <<<'PHP'
<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ApiResource]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;
}
PHP);
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : un WARNING pour l'ID sequentiel
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('auto-increment', $warnings[0]->getMessage());
        $this->assertStringContainsString('Product.php', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 2 : Entity avec ApiResource + UUID => OK
    // =============================================

    public function testApiResourceWithUuidDoesNothing(): void
    {
        // Arrange : une entite exposee via ApiResource avec un UUID
        file_put_contents($this->tempDir . '/src/Entity/Order.php', <<<'PHP'
<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ApiResource]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;
}
PHP);
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 3 : Entity sans ApiResource => OK
    // =============================================

    public function testEntityWithoutApiResourceDoesNothing(): void
    {
        // Arrange : une entite interne (pas exposee via API)
        file_put_contents($this->tempDir . '/src/Entity/AuditLog.php', <<<'PHP'
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
}
PHP);
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue (l'entite n'est pas exposee)
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 4 : Pas de repertoire src/Entity => OK
    // =============================================

    public function testNoEntityDirDoesNothing(): void
    {
        // Arrange : supprime le repertoire Entity pour simuler son absence
        $this->deleteDirectory($this->tempDir . '/src/Entity');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 5 : Verification des champs d'enrichissement
    // =============================================

    public function testEnrichmentFields(): void
    {
        // Arrange : entite exposee avec auto-increment pour generer un WARNING
        file_put_contents($this->tempDir . '/src/Entity/User.php', <<<'PHP'
<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ApiResource]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
}
PHP);
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : verification des champs d'enrichissement
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode(), 'fixCode ne doit pas etre null');
        $this->assertNotNull($issue->getDocUrl(), 'docUrl ne doit pas etre null');
        $this->assertNotNull($issue->getBusinessImpact(), 'businessImpact ne doit pas etre null');
        $this->assertNotNull($issue->getEstimatedFixMinutes(), 'estimatedFixMinutes ne doit pas etre null');
        $this->assertSame(30, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('uid', $issue->getDocUrl() ?? '');
    }

    // =============================================
    // Test 6 : getName et getModule
    // =============================================

    public function testGetNameAndGetModule(): void
    {
        $analyzer = $this->createAnalyzer();

        $this->assertSame('Sequential ID Analyzer', $analyzer->getName());
        $this->assertSame(Module::SECURITY, $analyzer->getModule());
    }

    // =============================================
    // Test 7 : supports avec ApiPlatform
    // =============================================

    public function testSupportsWithApiPlatform(): void
    {
        $analyzer = $this->createAnalyzer();

        $contextWith = new ProjectContext(
            projectPath: $this->tempDir,
            hasDoctrineOrm: true,
            hasMessenger: false,
            hasApiPlatform: true,
            hasTwig: false,
            hasSecurityBundle: false,
            hasWebProfilerBundle: false,
            hasMailer: false,
            hasNelmioCors: false,
            hasNelmioSecurity: false,
            hasJwtAuth: false,
            symfonyVersion: '6.4.14',
        );

        $this->assertTrue($analyzer->supports($contextWith));
    }

    // =============================================
    // Test 8 : supports sans ApiPlatform
    // =============================================

    public function testSupportsWithoutApiPlatform(): void
    {
        $analyzer = $this->createAnalyzer();

        $contextWithout = new ProjectContext(
            projectPath: $this->tempDir,
            hasDoctrineOrm: true,
            hasMessenger: false,
            hasApiPlatform: false,
            hasTwig: false,
            hasSecurityBundle: false,
            hasWebProfilerBundle: false,
            hasMailer: false,
            hasNelmioCors: false,
            hasNelmioSecurity: false,
            hasJwtAuth: false,
            symfonyVersion: '6.4.14',
        );

        $this->assertFalse($analyzer->supports($contextWithout));
    }
}
