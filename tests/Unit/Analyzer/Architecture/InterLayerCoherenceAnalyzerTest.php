<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Architecture;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Architecture\InterLayerCoherenceAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class InterLayerCoherenceAnalyzerTest extends TestCase
{
    // ---------------------------------------------------------------
    // Gestion du repertoire temporaire
    // ---------------------------------------------------------------

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createAnalyzer(): InterLayerCoherenceAnalyzer
    {
        return new InterLayerCoherenceAnalyzer($this->tempDir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::ARCHITECTURE]);
    }

    /**
     * Cree un fichier PHP dans le dossier src/Entity.
     */
    private function writeEntityFile(string $filename, string $content): void
    {
        $dir = $this->tempDir . '/src/Entity';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . $filename, $content);
    }

    /**
     * Cree un fichier PHP dans le dossier src/Security.
     */
    private function writeSecurityFile(string $filename, string $content): void
    {
        $dir = $this->tempDir . '/src/Security';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . $filename, $content);
    }

    // ---------------------------------------------------------------
    // 1. ApiResource sans Voter => CRITICAL
    // ---------------------------------------------------------------

    public function testApiResourceWithoutVoterCreatesCritical(): void
    {
        $this->writeEntityFile('Product.php', <<<'PHP'
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
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('Product', $criticals[0]->getMessage());
        $this->assertStringContainsString('Voter', $criticals[0]->getMessage());
    }

    // ---------------------------------------------------------------
    // 2. ApiResource avec Voter => OK
    // ---------------------------------------------------------------

    public function testApiResourceWithVoterDoesNothing(): void
    {
        $this->writeEntityFile('Product.php', <<<'PHP'
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
}
PHP);

        $this->writeSecurityFile('ProductVoter.php', <<<'PHP'
<?php

namespace App\Security\Voter;

use App\Entity\Product;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class ProductVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Product;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        return false;
    }
}
PHP);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(0, $criticals);
    }

    // ---------------------------------------------------------------
    // 3. Pas de repertoire src/Entity => OK
    // ---------------------------------------------------------------

    public function testNoEntityDirDoesNothing(): void
    {
        // Le dossier src/Entity n'existe pas
        mkdir($this->tempDir, 0777, true);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 4. Verification des champs d'enrichissement
    // ---------------------------------------------------------------

    public function testEnrichmentFields(): void
    {
        // Entite exposee sans Voter pour generer un CRITICAL
        $this->writeEntityFile('Invoice.php', <<<'PHP'
<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ApiResource]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
}
PHP);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode(), 'fixCode ne doit pas etre null');
        $this->assertNotNull($issue->getDocUrl(), 'docUrl ne doit pas etre null');
        $this->assertNotNull($issue->getBusinessImpact(), 'businessImpact ne doit pas etre null');
        $this->assertNotNull($issue->getEstimatedFixMinutes(), 'estimatedFixMinutes ne doit pas etre null');
        $this->assertSame(30, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('voters', $issue->getDocUrl() ?? '');
    }

    // ---------------------------------------------------------------
    // 5. getName et getModule
    // ---------------------------------------------------------------

    public function testGetNameAndGetModule(): void
    {
        $analyzer = $this->createAnalyzer();

        $this->assertSame('Inter-Layer Coherence Analyzer', $analyzer->getName());
        $this->assertSame(Module::ARCHITECTURE, $analyzer->getModule());
    }

    // ---------------------------------------------------------------
    // 6. supports retourne toujours true
    // ---------------------------------------------------------------

    public function testSupportsAlwaysReturnsTrue(): void
    {
        $analyzer = $this->createAnalyzer();

        $context = new \PierreArthur\SfDoctor\Context\ProjectContext(
            projectPath: $this->tempDir,
            hasDoctrineOrm: false,
            hasMessenger: false,
            hasApiPlatform: false,
            hasTwig: false,
            hasSecurityBundle: false,
            hasWebProfilerBundle: false,
            hasMailer: false,
            hasNelmioCors: false,
            hasNelmioSecurity: false,
            hasJwtAuth: false,
            symfonyVersion: null,
        );

        $this->assertTrue($analyzer->supports($context));
    }
}
