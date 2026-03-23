<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\CsrfAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Config\NullParameterResolver;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class CsrfAnalyzerTest extends TestCase
{
    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param array<mixed>|null $frameworkConfig Ce que read() retournera pour framework.yaml
     */
    private function createAnalyzer(
        ?array $frameworkConfig,
        string $projectPath = '/fake/project',
    ): CsrfAnalyzer {
        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn($frameworkConfig);

        return new CsrfAnalyzer($projectPath, $configReader, new NullParameterResolver());
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::SECURITY]);
    }

    // ---------------------------------------------------------------
    // 1. Check global : framework.yaml
    // ---------------------------------------------------------------

    public function testGlobalCsrfDisabledUnderFormCreatesCritical(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'form' => ['csrf_protection' => false],
            ],
        ]);

        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('globalement', $criticals[0]->getMessage());
        $this->assertSame('config/packages/framework.yaml', $criticals[0]->getFile());    }

    public function testGlobalCsrfDisabledDirectlyUnderFrameworkCreatesCritical(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'csrf_protection' => false,
            ],
        ]);

        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
    }

    public function testGlobalCsrfEnabledProducesNoCritical(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'form' => ['csrf_protection' => true],
            ],
        ]);

        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::CRITICAL));
    }

    public function testMissingFrameworkYamlProducesNoCritical(): void
    {
        $analyzer = $this->createAnalyzer(null);

        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::CRITICAL));
    }

    public function testFrameworkYamlWithoutCsrfKeyProducesNoCritical(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => ['cache' => ['app' => 'cache.adapter.filesystem']],
        ]);

        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::CRITICAL));
    }

    // ---------------------------------------------------------------
    // 2. Check fichiers : src/Form/
    // ---------------------------------------------------------------

    public function testMissingFormDirProducesNoWarning(): void
    {
        // /fake/project/src/Form n'existe pas sur le disque.
        $analyzer = $this->createAnalyzer(null, '/fake/project');

        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));
    }

    public function testFormTypeWithCsrfDisabledSingleQuotesCreatesWarning(): void
    {
        $projectPath = $this->createTempProjectWithFormType(
            <<<'PHP'
            <?php
            class TestType extends AbstractType {
                public function configureOptions(OptionsResolver $resolver): void {
                    $resolver->setDefaults(['csrf_protection' => false]);
                }
            }
            PHP,
        );

        $analyzer = $this->createAnalyzer(null, $projectPath);

        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('TestType.php', $warnings[0]->getMessage());
        $this->cleanTempProject($projectPath);
    }

    public function testFormTypeWithCsrfDisabledDoubleQuotesCreatesWarning(): void
    {
        $projectPath = $this->createTempProjectWithFormType(
            <<<'PHP'
            <?php
            class TestType extends AbstractType {
                public function configureOptions(OptionsResolver $resolver): void {
                    $resolver->setDefaults(["csrf_protection" => false]);
                }
            }
            PHP,
        );

        $analyzer = $this->createAnalyzer(null, $projectPath);

        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(1, $report->getIssuesBySeverity(Severity::WARNING));

        $this->cleanTempProject($projectPath);
    }

    public function testFormTypeWithCsrfEnabledProducesNoWarning(): void
    {
        $projectPath = $this->createTempProjectWithFormType(
            <<<'PHP'
            <?php
            class TestType extends AbstractType {
                public function configureOptions(OptionsResolver $resolver): void {
                    $resolver->setDefaults(['csrf_protection' => true]);
                }
            }
            PHP,
        );

        $analyzer = $this->createAnalyzer(null, $projectPath);

        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));

        $this->cleanTempProject($projectPath);
    }

    public function testFormTypeWithoutCsrfKeyProducesNoWarning(): void
    {
        $projectPath = $this->createTempProjectWithFormType(
            <<<'PHP'
            <?php
            class TestType extends AbstractType {
                public function configureOptions(OptionsResolver $resolver): void {
                    $resolver->setDefaults(['data_class' => User::class]);
                }
            }
            PHP,
        );

        $analyzer = $this->createAnalyzer(null, $projectPath);

        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));

        $this->cleanTempProject($projectPath);
    }

    // ---------------------------------------------------------------
    // 3. Metadata
    // ---------------------------------------------------------------

    public function testGetModuleReturnsSecurity(): void
    {
        $this->assertSame(Module::SECURITY, $this->createAnalyzer(null)->getModule());
    }

    public function testGetNameReturnsReadableName(): void
    {
        $this->assertSame('CSRF Analyzer', $this->createAnalyzer(null)->getName());
    }

    // ---------------------------------------------------------------
    // Helpers filesystem
    // ---------------------------------------------------------------

    /**
     * Cree un projet temporaire avec un FormType PHP dans src/Form/.
     */
    private function createTempProjectWithFormType(string $phpContent): string
    {
        $projectPath = sys_get_temp_dir() . '/sf_doctor_csrf_test_' . uniqid();
        $formDir = $projectPath . '/src/Form';

        mkdir($formDir, 0777, true);
        file_put_contents($formDir . '/TestType.php', $phpContent);

        return $projectPath;
    }

    /**
     * Supprime le projet temporaire apres le test.
     */
    private function cleanTempProject(string $projectPath): void
    {
        $formDir = $projectPath . '/src/Form';

        foreach (glob($formDir . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($formDir);
        rmdir($projectPath . '/src');
        rmdir($projectPath);
    }
}