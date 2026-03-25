<?php

// src/Analyzer/Tests/SecurityTestAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Tests;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Verifie que chaque Voter de securite possede un test correspondant.
 *
 * Les Voters sont des composants critiques : ils controlent l'acces aux ressources.
 * Un Voter sans test peut contenir une erreur de logique qui accorde ou refuse
 * l'acces de maniere incorrecte, sans que personne ne s'en apercoive.
 */
final class SecurityTestAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {
    }

    public function analyze(AuditReport $report): void
    {
        $securityDir = $this->projectPath . '/src/Security';

        if (!is_dir($securityDir)) {
            return;
        }

        $voterFiles = $this->findVoterFiles($securityDir);

        foreach ($voterFiles as $voterFile) {
            $this->checkVoterHasTest($report, $voterFile);
        }
    }

    public function getName(): string
    {
        return 'Security Test Analyzer';
    }

    public function getModule(): Module
    {
        return Module::TESTS;
    }

    public function supports(ProjectContext $context): bool
    {
        $securityDir = $context->getProjectPath() . '/src/Security';

        return is_dir($securityDir);
    }

    /**
     * Recherche tous les fichiers Voter dans src/Security/ et ses sous-repertoires.
     * Un Voter est identifie par la presence de "Voter" dans le nom du fichier
     * ou par l'extension de AbstractVoter/Voter dans le contenu.
     *
     * @return list<\SplFileInfo>
     */
    private function findVoterFiles(string $securityDir): array
    {
        $voters = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($securityDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->isDir()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Detection par le nom du fichier (convention : *Voter.php).
            if (str_contains($file->getFilename(), 'Voter')) {
                $voters[] = $file;
                continue;
            }

            // Detection par le contenu : extends Voter ou extends AbstractVoter.
            $content = file_get_contents($file->getRealPath());

            if ($content === false) {
                continue;
            }

            if (preg_match('/extends\s+(Abstract)?Voter\b/', $content)) {
                $voters[] = $file;
            }
        }

        return $voters;
    }

    /**
     * Verifie qu'un fichier Voter possede un test correspondant dans tests/.
     * Recherche un fichier dont le nom correspond a <VoterName>Test.php
     * dans le repertoire tests/.
     */
    private function checkVoterHasTest(AuditReport $report, \SplFileInfo $voterFile): void
    {
        $voterName = $voterFile->getBasename('.php');
        $expectedTestName = $voterName . 'Test.php';
        $testsDir = $this->projectPath . '/tests';

        if (!is_dir($testsDir)) {
            $this->reportMissingTest($report, $voterFile, $voterName);
            return;
        }

        // Recherche recursive du fichier de test dans tests/.
        if ($this->testFileExists($testsDir, $expectedTestName)) {
            return;
        }

        $this->reportMissingTest($report, $voterFile, $voterName);
    }

    /**
     * Recherche un fichier de test par son nom dans le repertoire tests/ et ses sous-repertoires.
     */
    private function testFileExists(string $testsDir, string $testFileName): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->isDir()) {
                continue;
            }

            if ($file->getFilename() === $testFileName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ajoute une issue CRITICAL pour un Voter sans test correspondant.
     */
    private function reportMissingTest(AuditReport $report, \SplFileInfo $voterFile, string $voterName): void
    {
        $realSecurityDir = realpath($this->projectPath . '/src/Security');
        if ($realSecurityDir === false) {
            return;
        }

        $relativePath = 'src/Security/' . ltrim(
            str_replace('\\', '/', substr(
                $voterFile->getRealPath(),
                strlen($realSecurityDir),
            )),
            '/',
        );

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::TESTS,
            analyzer: $this->getName(),
            message: "Voter '{$voterName}' sans test correspondant",
            detail: "Le Voter '{$voterName}' dans {$relativePath} n'a pas de fichier de test "
                . "{$voterName}Test.php dans le repertoire tests/. "
                . "Les Voters controlent l'acces aux ressources : un Voter non teste "
                . "peut accorder ou refuser l'acces de maniere incorrecte sans que "
                . "personne ne s'en apercoive.",
            suggestion: "Creer le fichier tests/Security/{$voterName}Test.php "
                . "avec des cas de test couvrant les differents scenarios d'acces "
                . "(acces accorde, acces refuse, utilisateur anonyme, role insuffisant).",
            file: $relativePath,
            fixCode: "namespace App\\Tests\\Security;\n\n"
                . "use App\\Security\\{$voterName};\n"
                . "use PHPUnit\\Framework\\TestCase;\n"
                . "use Symfony\\Component\\Security\\Core\\Authentication\\Token\\TokenInterface;\n"
                . "use Symfony\\Component\\Security\\Core\\Authorization\\Voter\\VoterInterface;\n\n"
                . "class {$voterName}Test extends TestCase\n"
                . "{\n"
                . "    private {$voterName} \$voter;\n\n"
                . "    protected function setUp(): void\n"
                . "    {\n"
                . "        \$this->voter = new {$voterName}();\n"
                . "    }\n\n"
                . "    public function testVoteGranted(): void\n"
                . "    {\n"
                . "        // Configurer le token et le sujet\n"
                . "        \$this->assertSame(VoterInterface::ACCESS_GRANTED, ...);\n"
                . "    }\n"
                . "}",
            docUrl: 'https://symfony.com/doc/current/security/voters.html#testing-voters',
            businessImpact: "Un Voter non teste peut contenir une erreur de logique "
                . "qui accorde l'acces a des utilisateurs non autorises "
                . "ou bloque des utilisateurs legitimes. "
                . "Ce type de bug est silencieux et peut rester en production longtemps.",
            estimatedFixMinutes: 30,
        ));
    }
}
