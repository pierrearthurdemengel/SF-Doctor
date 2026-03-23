<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

// La classe de test étend TestCase — la classe de base de PHPUnit.
// C'est elle qui fournit les méthodes assert*().
class IssueTest extends TestCase
{
    // Chaque méthode "test*" est un scénario de test indépendant.
    // PHPUnit les découvre automatiquement grâce au préfixe "test".

    public function testCreateIssueWithAllFields(): void
    {
        // --- ARRANGE : on prépare les données ---
        // On crée un Issue avec TOUS les paramètres, y compris file et line.
        $issue = new Issue(
            severity: Severity::CRITICAL,
            module: Module::SECURITY,
            analyzer: 'FirewallAnalyzer',
            message: 'Firewall sans authentification',
            detail: 'Le firewall main est actif mais aucun authenticator configuré.',
            suggestion: 'Ajouter form_login ou json_login.',
            file: 'config/packages/security.yaml',
            line: 42,
        );

        // --- ASSERT : on vérifie que chaque getter retourne la bonne valeur ---
        // assertSame() vérifie l'égalité avec === (type + valeur).
        $this->assertSame(Severity::CRITICAL, $issue->getSeverity());
        $this->assertSame(Module::SECURITY, $issue->getModule());
        $this->assertSame('FirewallAnalyzer', $issue->getAnalyzer());
        $this->assertSame('Firewall sans authentification', $issue->getMessage());
        $this->assertSame('Le firewall main est actif mais aucun authenticator configuré.', $issue->getDetail());
        $this->assertSame('Ajouter form_login ou json_login.', $issue->getSuggestion());
        $this->assertSame('config/packages/security.yaml', $issue->getFile());
        $this->assertSame(42, $issue->getLine());
    }

    public function testCreateIssueWithoutOptionalFields(): void
    {
        // On crée un Issue SANS file ni line (les paramètres optionnels).
        // Ça simule un problème global, pas lié à un fichier précis.
        // Ex: "aucun fichier security.yaml trouvé"
        $issue = new Issue(
            severity: Severity::WARNING,
            module: Module::ARCHITECTURE,
            analyzer: 'ServiceInjectionAnalyzer',
            message: 'Injection du ContainerInterface détectée',
            detail: 'Injecter le container entier est un anti-pattern.',
            suggestion: 'Injecter les services spécifiques à la place.',
        );

        // Les champs optionnels doivent être null
        $this->assertNull($issue->getFile());
        $this->assertNull($issue->getLine());

        // Les champs obligatoires sont bien là
        $this->assertSame(Severity::WARNING, $issue->getSeverity());
        $this->assertSame(Module::ARCHITECTURE, $issue->getModule());
    }

    public function testEachSeverityLevelIsAccepted(): void
    {
        // On vérifie que les 4 niveaux de gravité fonctionnent.
        // C'est un test de "couverture des cas" : on s'assure
        // qu'aucun niveau n'est accidentellement refusé.
        foreach (Severity::cases() as $severity) {
            $issue = new Issue(
                severity: $severity,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Test message',
                detail: 'Test detail',
                suggestion: 'Test suggestion',
            );

            $this->assertSame($severity, $issue->getSeverity());
        }
    }

    public function testEachModuleIsAccepted(): void
    {
        // Même logique pour les modules.
        foreach (Module::cases() as $module) {
            $issue = new Issue(
                severity: Severity::OK,
                module: $module,
                analyzer: 'TestAnalyzer',
                message: 'Test message',
                detail: 'Test detail',
                suggestion: 'Test suggestion',
            );

            $this->assertSame($module, $issue->getModule());
        }
    }
}