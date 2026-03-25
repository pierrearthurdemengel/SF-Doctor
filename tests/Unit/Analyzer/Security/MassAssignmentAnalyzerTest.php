<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\MassAssignmentAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class MassAssignmentAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Cree un repertoire temporaire unique pour chaque test.
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
        mkdir($this->tempDir . '/src/Form', 0777, true);
    }

    protected function tearDown(): void
    {
        // Nettoyage du repertoire temporaire apres chaque test.
        $this->deleteDirectory($this->tempDir);
    }

    // --- Helper : creer un analyzer pointe sur le repertoire temporaire ---

    private function createAnalyzer(): MassAssignmentAnalyzer
    {
        return new MassAssignmentAnalyzer($this->tempDir);
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
    // Test 1 : Pas de repertoire src/Form
    // =============================================

    public function testNoFormDirDoesNothing(): void
    {
        // Arrange : supprime le repertoire Form pour simuler son absence
        $this->deleteDirectory($this->tempDir . '/src/Form');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 2 : Mass assignment detecte (CRITICAL)
    // =============================================

    public function testMassAssignmentDetectedCreatesCritical(): void
    {
        // Arrange : un fichier avec $request->request->all() et ->submit(
        file_put_contents($this->tempDir . '/src/Form/UserController.php', <<<'PHP'
<?php

namespace App\Form;

class UserController
{
    public function update(Request $request)
    {
        $form = $this->createForm(UserType::class, $user);
        $form->submit($request->request->all());

        if ($form->isValid()) {
            // ...
        }
    }
}
PHP);
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : une issue CRITICAL pour le mass assignment
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('Mass assignment', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 3 : handleRequest ne declenche pas d'alerte
    // =============================================

    public function testHandleRequestDoesNothing(): void
    {
        // Arrange : un fichier qui utilise handleRequest (bonne pratique)
        file_put_contents($this->tempDir . '/src/Form/UserController.php', <<<'PHP'
<?php

namespace App\Form;

class UserController
{
    public function update(Request $request)
    {
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // ...
        }
    }
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
    // Test 4 : allow_extra_fields active -> WARNING
    // =============================================

    public function testAllowExtraFieldsCreatesWarning(): void
    {
        // Arrange : un FormType avec allow_extra_fields => true
        file_put_contents($this->tempDir . '/src/Form/UserType.php', <<<'PHP'
<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'allow_extra_fields' => true,
        ]);
    }
}
PHP);
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : un WARNING pour allow_extra_fields
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('allow_extra_fields', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 5 : Verification des champs d'enrichissement
    // =============================================

    public function testEnrichmentFields(): void
    {
        // Arrange : config qui genere un CRITICAL (mass assignment)
        file_put_contents($this->tempDir . '/src/Form/UserController.php', <<<'PHP'
<?php

namespace App\Form;

class UserController
{
    public function update(Request $request)
    {
        $form = $this->createForm(UserType::class, $user);
        $form->submit($request->request->all());
    }
}
PHP);
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : verification des champs d'enrichissement
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('forms', $issue->getDocUrl() ?? '');
    }
}
