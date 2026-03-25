<?php

// src/Analyzer/Tests/TestCoverageAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Tests;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Verifie la presence et la couverture minimale des tests dans le projet.
 *
 * Detecte l'absence de phpunit.xml, l'absence du repertoire tests/,
 * ou un repertoire tests/ avec tres peu de fichiers de test.
 */
final class TestCoverageAnalyzer implements AnalyzerInterface
{
    // Nombre minimum de fichiers de test attendus pour eviter un WARNING.
    private const MIN_TEST_FILES = 3;

    public function __construct(
        private readonly string $projectPath,
    ) {
    }

    public function analyze(AuditReport $report): void
    {
        $this->checkPhpunitConfig($report);
        $this->checkTestsDirectory($report);
    }

    public function getName(): string
    {
        return 'Test Coverage Analyzer';
    }

    public function getModule(): Module
    {
        return Module::TESTS;
    }

    public function supports(ProjectContext $context): bool
    {
        return true;
    }

    /**
     * Verifie la presence d'un fichier de configuration PHPUnit.
     * Sans phpunit.xml ni phpunit.xml.dist, les tests ne peuvent pas etre executes
     * de maniere standardisee.
     */
    private function checkPhpunitConfig(AuditReport $report): void
    {
        $hasPhpunitXml = file_exists($this->projectPath . '/phpunit.xml');
        $hasPhpunitXmlDist = file_exists($this->projectPath . '/phpunit.xml.dist');

        if ($hasPhpunitXml || $hasPhpunitXmlDist) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::TESTS,
            analyzer: $this->getName(),
            message: 'Aucun fichier phpunit.xml ou phpunit.xml.dist detecte',
            detail: "Le projet ne contient ni phpunit.xml ni phpunit.xml.dist a la racine. "
                . "Sans fichier de configuration PHPUnit, les tests ne peuvent pas etre "
                . "executes de maniere standardisee et la CI ne peut pas les lancer automatiquement.",
            suggestion: "Creer un fichier phpunit.xml.dist a la racine du projet. "
                . "Utiliser la commande : composer require --dev phpunit/phpunit puis generer "
                . "la configuration avec : vendor/bin/phpunit --generate-configuration.",
            fixCode: "# Installer PHPUnit et generer la configuration :\n"
                . "composer require --dev phpunit/phpunit\n"
                . "vendor/bin/phpunit --generate-configuration",
            docUrl: 'https://symfony.com/doc/current/testing.html#the-phpunit-testing-framework',
            businessImpact: "Sans configuration PHPUnit, les tests ne sont pas executes en CI. "
                . "Les regressions ne sont pas detectees avant la mise en production.",
            estimatedFixMinutes: 15,
        ));
    }

    /**
     * Verifie la presence et le contenu du repertoire tests/.
     * Un projet sans tests est un risque majeur. Un repertoire tests/
     * avec moins de 3 fichiers suggere une couverture insuffisante.
     */
    private function checkTestsDirectory(AuditReport $report): void
    {
        $testsDir = $this->projectPath . '/tests';

        if (!is_dir($testsDir)) {
            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::TESTS,
                analyzer: $this->getName(),
                message: 'Aucun repertoire tests/ detecte - le projet n\'a aucun test',
                detail: "Le repertoire tests/ n'existe pas a la racine du projet. "
                    . "Un projet Symfony sans tests est extremement risque : "
                    . "chaque modification de code peut introduire des regressions silencieuses "
                    . "qui ne seront decouvertes qu'en production.",
                suggestion: "Creer le repertoire tests/ et ajouter au minimum des tests "
                    . "fonctionnels pour les routes critiques (login, paiement, administration). "
                    . "Commencer par : mkdir tests && composer require --dev symfony/test-pack.",
                fixCode: "# Installer le pack de tests Symfony :\n"
                    . "mkdir tests\n"
                    . "composer require --dev symfony/test-pack",
                docUrl: 'https://symfony.com/doc/current/testing.html',
                businessImpact: "Le projet n'a aucun filet de securite contre les regressions. "
                    . "Chaque deploiement est un risque : une modification dans un service "
                    . "peut casser silencieusement un processus metier critique.",
                estimatedFixMinutes: 120,
            ));

            return;
        }

        // Le repertoire existe, compter les fichiers de test.
        $testFileCount = $this->countTestFiles($testsDir);

        if ($testFileCount < self::MIN_TEST_FILES) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::TESTS,
                analyzer: $this->getName(),
                message: "Repertoire tests/ detecte mais seulement {$testFileCount} fichier(s) de test",
                detail: "Le repertoire tests/ existe mais ne contient que {$testFileCount} fichier(s) *Test.php. "
                    . "Avec moins de " . self::MIN_TEST_FILES . " fichiers de test, la couverture est "
                    . "probablement insuffisante pour detecter les regressions.",
                suggestion: "Ajouter des tests fonctionnels pour les routes critiques "
                    . "et des tests unitaires pour la logique metier. "
                    . "Prioriser les controllers d'authentification, les Voters et les services "
                    . "contenant de la logique metier.",
                fixCode: "# Exemple de test fonctionnel Symfony :\n"
                    . "namespace App\\Tests\\Controller;\n\n"
                    . "use Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase;\n\n"
                    . "class HomeControllerTest extends WebTestCase\n"
                    . "{\n"
                    . "    public function testHomepage(): void\n"
                    . "    {\n"
                    . "        \$client = static::createClient();\n"
                    . "        \$client->request('GET', '/');\n"
                    . "        \$this->assertResponseIsSuccessful();\n"
                    . "    }\n"
                    . "}",
                docUrl: 'https://symfony.com/doc/current/testing.html#functional-tests',
                businessImpact: "La couverture de tests est insuffisante. "
                    . "Les processus metier critiques ne sont probablement pas testes, "
                    . "ce qui augmente le risque de regressions en production.",
                estimatedFixMinutes: 60,
            ));
        }
    }

    /**
     * Compte les fichiers *Test.php dans le repertoire tests/ et ses sous-repertoires.
     */
    private function countTestFiles(string $testsDir): int
    {
        $count = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->isDir()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Convention PHPUnit : les fichiers de test se terminent par Test.php.
            if (str_ends_with($file->getFilename(), 'Test.php')) {
                $count++;
            }
        }

        return $count;
    }
}
