<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\SensitiveDataAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class SensitiveDataAnalyzerTest extends TestCase
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

    private function createAnalyzer(): SensitiveDataAnalyzer
    {
        return new SensitiveDataAnalyzer($this->tempDir);
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
    // Test 1 : Pas de repertoire src/Entity
    // =============================================

    public function testNoEntityDirDoesNothing(): void
    {
        // Arrange : supprime le repertoire Entity pour simuler son absence
        $this->deleteDirectory($this->tempDir . '/src/Entity');
        $this->deleteDirectory($this->tempDir . '/src');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 2 : Entite avec propriete $password non protegee
    // =============================================

    public function testEntityWithPasswordPropertyCreatesWarning(): void
    {
        // Arrange : une entite avec $password sans protection
        file_put_contents($this->tempDir . '/src/Entity/User.php', <<<'PHP'
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?string $email = null;

    #[ORM\Column]
    private ?string $password = null;
}
PHP);
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : un WARNING pour la propriete password exposee
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('password', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 3 : Entite avec #[Ignore] sur $password
    // =============================================

    public function testEntityWithIgnoredPasswordDoesNothing(): void
    {
        // Arrange : une entite avec $password protegee par #[Ignore]
        file_put_contents($this->tempDir . '/src/Entity/User.php', <<<'PHP'
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Ignore;

#[ORM\Entity]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    #[Ignore]
    private ?string $password = null;
}
PHP);
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue, la propriete est protegee
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 4 : Entite avec #[Groups(['internal'])] sur $password
    // =============================================

    public function testEntityWithGroupsDoesNothing(): void
    {
        // Arrange : une entite avec $password protegee par #[Groups(...)]
        file_put_contents($this->tempDir . '/src/Entity/User.php', <<<'PHP'
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    #[Groups(['internal'])]
    private ?string $password = null;
}
PHP);
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue, la propriete est protegee par un groupe
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 5 : Plusieurs proprietes sensibles non protegees
    // =============================================

    public function testMultipleSensitivePropertiesCreatesMultipleWarnings(): void
    {
        // Arrange : une entite avec $password, $token et $apiKey non proteges
        file_put_contents($this->tempDir . '/src/Entity/User.php', <<<'PHP'
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column]
    private ?string $token = null;

    #[ORM\Column]
    private ?string $apiKey = null;
}
PHP);
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : 3 WARNINGs pour les 3 proprietes sensibles
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(3, $warnings);
    }

    // =============================================
    // Test 6 : Verification des champs d'enrichissement
    // =============================================

    public function testEnrichmentFields(): void
    {
        // Arrange : une entite avec $password non protegee
        file_put_contents($this->tempDir . '/src/Entity/User.php', <<<'PHP'
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class User
{
    #[ORM\Column]
    private ?string $password = null;
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
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('serializer', $issue->getDocUrl() ?? '');
    }
}
