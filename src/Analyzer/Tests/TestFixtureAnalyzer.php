<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Tests;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Analyse les fixtures de donnees pour detecter les problemes de securite.
 *
 * Detecte les mots de passe en clair et les emails de production
 * dans les fixtures de test.
 */
final class TestFixtureAnalyzer implements AnalyzerInterface
{
    // Mots de passe triviaux souvent utilises dans les fixtures.
    private const TRIVIAL_PASSWORDS = [
        'password',
        '123456',
        'admin',
        'test',
        'secret',
        'changeme',
        'azerty',
        'qwerty',
    ];

    public function __construct(
        private readonly string $projectPath,
    ) {
    }

    public function analyze(AuditReport $report): void
    {
        $dirs = [
            $this->projectPath . '/src/DataFixtures',
            $this->projectPath . '/tests/fixtures',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $content = file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                $relativePath = str_replace('\\', '/', str_replace(
                    str_replace('\\', '/', $this->projectPath) . '/',
                    '',
                    str_replace('\\', '/', $file->getRealPath()),
                ));

                $this->checkPlainTextPasswords($report, $content, $relativePath);
                $this->checkProductionEmails($report, $content, $relativePath);
            }
        }
    }

    public function getName(): string
    {
        return 'Test Fixture Analyzer';
    }

    public function getModule(): Module
    {
        return Module::TESTS;
    }

    public function supports(ProjectContext $context): bool
    {
        return true;
    }

    private function checkPlainTextPasswords(AuditReport $report, string $content, string $file): void
    {
        foreach (self::TRIVIAL_PASSWORDS as $password) {
            // Match setPassword('password'), setPassword("password"), etc.
            $pattern = '/setPassword\s*\(\s*[\'"]' . preg_quote($password, '/') . '[\'"]\s*\)/i';

            if (preg_match($pattern, $content)) {
                $report->addIssue(new Issue(
                    severity: Severity::CRITICAL,
                    module: Module::TESTS,
                    analyzer: $this->getName(),
                    message: sprintf("Mot de passe en clair '%s' dans %s", $password, basename($file)),
                    detail: "La fixture utilise setPassword('{$password}') avec un mot de passe en clair non hashe. "
                        . "Si ces fixtures sont chargees en production par erreur, les mots de passe "
                        . "seront stockes en clair dans la base de donnees.",
                    suggestion: "Utiliser le PasswordHasherInterface pour hasher le mot de passe dans la fixture.",
                    file: $file,
                    fixCode: "use Symfony\\Component\\PasswordHasher\\Hasher\\UserPasswordHasherInterface;\n\n"
                        . "\$user->setPassword(\n"
                        . "    \$this->hasher->hashPassword(\$user, '{$password}')\n"
                        . ");",
                    docUrl: 'https://symfony.com/doc/current/security/passwords.html',
                    businessImpact: "Si les fixtures sont chargees en production, les comptes utilisateurs "
                        . "auront des mots de passe triviaux stockes en clair.",
                    estimatedFixMinutes: 10,
                ));

                return; // One issue per file is enough
            }
        }
    }

    private function checkProductionEmails(AuditReport $report, string $content, string $file): void
    {
        // Find email strings in the fixture
        if (!preg_match_all('/[\'"]([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})[\'"]/', $content, $matches)) {
            return;
        }

        foreach ($matches[1] as $email) {
            $domain = strtolower(substr($email, strpos($email, '@') + 1));

            // Safe domains for fixtures
            $safeDomains = ['example.com', 'example.org', 'example.net', 'test.com', 'localhost', 'fixture.local'];

            if (in_array($domain, $safeDomains, true)) {
                continue;
            }

            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::TESTS,
                analyzer: $this->getName(),
                message: sprintf("Email potentiellement reel '%s' dans %s", $email, basename($file)),
                detail: "La fixture contient l'adresse email '{$email}' qui utilise un domaine reel. "
                    . "Si les fixtures sont chargees et que des emails sont envoyes, "
                    . "ces personnes recevront des messages non sollicites.",
                suggestion: "Utiliser des adresses en @example.com ou @test.com dans les fixtures.",
                file: $file,
                fixCode: str_replace($domain, 'example.com', "'{$email}'"),
                docUrl: 'https://www.rfc-editor.org/rfc/rfc2606',
                businessImpact: "Les fixtures avec des emails reels peuvent provoquer l'envoi "
                    . "de messages non sollicites a de vraies personnes, avec risque RGPD.",
                estimatedFixMinutes: 5,
            ));

            return; // One issue per file is enough
        }
    }
}
