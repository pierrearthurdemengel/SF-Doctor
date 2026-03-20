<?php

namespace SfDoctor\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use SfDoctor\Model\AuditReport;
use SfDoctor\Model\Issue;
use SfDoctor\Model\Module;
use SfDoctor\Model\Severity;
use SfDoctor\Report\ConsoleReporter;
use Symfony\Component\Console\Output\BufferedOutput;

class ConsoleReporterTest extends TestCase
{
    // --- Helpers ---

    /**
     * Crée un ConsoleReporter qui écrit dans un BufferedOutput.
     *
     * BufferedOutput est une classe du composant Console.
     * Au lieu d'écrire dans le terminal (comme StreamOutput fait),
     * elle stocke tout le texte dans une variable interne.
     * On récupère le contenu avec $output->fetch().
     *
     * C'est l'outil parfait pour tester du code qui écrit dans la console.
     */
    private function createReporterAndOutput(): array
    {
        // Le deuxième paramètre (true) active le "decorated" mode.
        // Ça signifie que les tags de formatage (<info>, <fg=red>, etc.)
        // sont gardés dans la sortie. Sans ça, SymfonyStyle les supprimerait
        // en pensant que le terminal ne supporte pas les couleurs.
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        $reporter = new ConsoleReporter($output);

        return [$reporter, $output];
    }

    private function createIssue(
        Severity $severity = Severity::WARNING,
        Module $module = Module::SECURITY,
        string $analyzer = 'TestAnalyzer',
        string $message = 'Test message',
        ?string $file = null,
        ?int $line = null,
    ): Issue {
        return new Issue(
            severity: $severity,
            module: $module,
            analyzer: $analyzer,
            message: $message,
            detail: 'Test detail',
            suggestion: 'Test suggestion',
            file: $file,
            line: $line,
        );
    }

    // =============================================
    // Test du format
    // =============================================

    public function testGetFormatReturnsConsole(): void
    {
        [$reporter] = $this->createReporterAndOutput();

        $this->assertSame('console', $reporter->getFormat());
    }

    // =============================================
    // Test de l'en-tête
    // =============================================

    public function testOutputContainsTitle(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake/project', [Module::SECURITY]);
        $reporter->generate($report);

        // fetch() retourne TOUT le texte qui a été écrit dans le BufferedOutput.
        $content = $output->fetch();

        $this->assertStringContainsString('SF Doctor', $content);
    }

    public function testOutputContainsProjectPath(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/home/pierre/mon-projet', [Module::SECURITY]);
        $reporter->generate($report);

        $content = $output->fetch();

        $this->assertStringContainsString('/home/pierre/mon-projet', $content);
    }

    public function testOutputContainsModuleNames(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY, Module::ARCHITECTURE]);
        $reporter->generate($report);

        $content = $output->fetch();

        $this->assertStringContainsString('security', $content);
        $this->assertStringContainsString('architecture', $content);
    }

    // =============================================
    // Test des issues dans le rapport
    // =============================================

    public function testOutputContainsIssueMessage(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(
            message: 'Firewall sans authentification',
        ));
        $reporter->generate($report);

        $content = $output->fetch();

        $this->assertStringContainsString('Firewall sans authentification', $content);
    }

    public function testOutputContainsAnalyzerName(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(
            analyzer: 'Firewall Analyzer',
        ));
        $reporter->generate($report);

        $content = $output->fetch();

        $this->assertStringContainsString('Firewall Analyzer', $content);
    }

    public function testOutputContainsSuggestion(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: 'TestAnalyzer',
            message: 'Problème détecté',
            detail: 'Détail du problème',
            suggestion: 'Voici comment corriger',
        ));
        $reporter->generate($report);

        $content = $output->fetch();

        $this->assertStringContainsString('Voici comment corriger', $content);
    }

    public function testOutputContainsFileLocation(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(
            file: 'config/packages/security.yaml',
            line: 42,
        ));
        $reporter->generate($report);

        $content = $output->fetch();

        // Le format est "fichier:ligne"
        $this->assertStringContainsString('security.yaml:42', $content);
    }

    public function testOutputShowsDashWhenNoFile(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue()); // pas de file ni line
        $reporter->generate($report);

        $content = $output->fetch();

        // Le tableau doit contenir un "-" pour la colonne fichier
        $this->assertStringContainsString('-', $content);
    }

    // =============================================
    // Test du score
    // =============================================

    public function testPerfectScoreShowsSuccess(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        // Rapport vide = score 100
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $reporter->generate($report);

        $content = $output->fetch();

        // success() dans SymfonyStyle produit un bloc avec "OK" ou "[OK]"
        // et le texte du message. On vérifie que le score 100 apparaît.
        $this->assertStringContainsString('100/100', $content);
    }

    public function testLowScoreShowsError(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);

        // 6 CRITICAL = -60, score = 40 (< 50 → error)
        for ($i = 0; $i < 6; $i++) {
            $report->addIssue($this->createIssue(Severity::CRITICAL));
        }

        $reporter->generate($report);
        $content = $output->fetch();

        $this->assertStringContainsString('40/100', $content);
    }

    public function testMediumScoreShowsWarning(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);

        // 4 CRITICAL = -40, score = 60 (>= 50 et < 80 → warning)
        for ($i = 0; $i < 4; $i++) {
            $report->addIssue($this->createIssue(Severity::CRITICAL));
        }

        $reporter->generate($report);
        $content = $output->fetch();

        $this->assertStringContainsString('60/100', $content);
    }

    // =============================================
    // Test du rapport vide
    // =============================================

    public function testEmptyModuleShowsSuccessMessage(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        // Rapport sans issues
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $reporter->generate($report);

        $content = $output->fetch();

        // Le module Security sans issues doit afficher un message positif
        $this->assertStringContainsString('Aucun problème', $content);
    }

    // =============================================
    // Test de l'issue count
    // =============================================

    public function testOutputContainsIssueCount(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue());
        $report->addIssue($this->createIssue());
        $report->addIssue($this->createIssue());
        $reporter->generate($report);

        $content = $output->fetch();

        // Le résumé doit indiquer 3 issues
        $this->assertStringContainsString('3', $content);
    }

    // =============================================
    // Test des sévérités dans le tableau
    // =============================================

    public function testCriticalSeverityDisplayed(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(Severity::CRITICAL));
        $reporter->generate($report);

        $content = $output->fetch();

        $this->assertStringContainsString('CRITICAL', $content);
    }

    public function testOkIssuesAreDisplayed(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(Severity::OK));
        $reporter->generate($report);

        $content = $output->fetch();

        // Les OK sont affichés dans le tableau
        $this->assertStringContainsString('OK', $content);
    }

    public function testOkIssuesSuggestionNotShown(): void
    {
        /** @var ConsoleReporter $reporter */
        /** @var BufferedOutput $output */
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue(new Issue(
            severity: Severity::OK,
            module: Module::SECURITY,
            analyzer: 'TestAnalyzer',
            message: 'Check passé',
            detail: 'Tout va bien',
            suggestion: 'Suggestion invisible',
        ));
        $reporter->generate($report);

        $content = $output->fetch();

        // Les issues OK n'affichent pas leur suggestion
        // (pas de flèche → devant la suggestion)
        $this->assertStringNotContainsString('Suggestion invisible', $content);
    }
}