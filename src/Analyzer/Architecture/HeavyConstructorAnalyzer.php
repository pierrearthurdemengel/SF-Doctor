<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Architecture;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les constructeurs trop charges dans les services, listeners et subscribers.
 *
 * Un constructeur avec trop de dependances revele un service qui a trop de responsabilites.
 * Un constructeur qui execute du travail (requetes, appels externes) ralentit
 * l'instanciation du container et viole le principe de lazy loading.
 */
final class HeavyConstructorAnalyzer implements AnalyzerInterface
{
    // Seuil au-dela duquel un constructeur est considere comme trop charge.
    private const MAX_DEPENDENCIES = 8;

    // Repertoires a scanner pour detecter les services concernes.
    private const SCAN_DIRS = [
        'src/Service',
        'src/EventListener',
        'src/EventSubscriber',
    ];

    // Patterns indiquant du travail effectue dans le constructeur.
    private const WORK_PATTERNS = [
        '$this->',
        '->find(',
        '->findBy(',
        '->findOneBy(',
    ];

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        foreach (self::SCAN_DIRS as $dir) {
            $fullPath = $this->projectPath . '/' . $dir;

            if (!is_dir($fullPath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullPath, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->isDir()) {
                    continue;
                }

                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = file_get_contents($file->getRealPath());

                if ($content === false) {
                    continue;
                }

                $realFullPath = realpath($fullPath);
                if ($realFullPath === false) {
                    continue;
                }

                $relativePath = $dir . '/' . ltrim(
                    str_replace('\\', '/', substr($file->getRealPath(), strlen($realFullPath))),
                    '/',
                );

                $this->checkDependencyCount($report, $content, $file->getFilename(), $relativePath);
                $this->checkWorkInConstructor($report, $content, $file->getFilename(), $relativePath);
            }
        }
    }

    public function getModule(): Module
    {
        return Module::ARCHITECTURE;
    }

    public function getName(): string
    {
        return 'Heavy Constructor Analyzer';
    }

    public function supports(ProjectContext $context): bool
    {
        return true;
    }

    /**
     * Detecte les constructeurs avec trop de dependances injectees.
     * Compte les parametres "private readonly" ou "private" dans __construct.
     */
    private function checkDependencyCount(
        AuditReport $report,
        string $content,
        string $filename,
        string $relativePath,
    ): void {
        // Extraire le bloc __construct avec ses parametres.
        if (!preg_match('/__construct\s*\((.*?)\)/s', $content, $match)) {
            return;
        }

        $constructorParams = $match[1];

        // Compter les parametres avec "private readonly" ou "private".
        $count = preg_match_all('/\b(?:private\s+readonly|private)\s+\S+\s+\$/', $constructorParams);

        if ($count <= self::MAX_DEPENDENCIES) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::ARCHITECTURE,
            analyzer: $this->getName(),
            message: "Constructeur surcharge dans {$filename} : {$count} dependances",
            detail: "Le fichier '{$filename}' injecte {$count} dependances dans son constructeur "
                . "(seuil recommande : " . self::MAX_DEPENDENCIES . "). "
                . "Cela indique que le service a trop de responsabilites et devrait etre decoupe.",
            suggestion: "Appliquer le principe de responsabilite unique (SRP). "
                . "Extraire des sous-services dedies pour regrouper les dependances liees.",
            file: $relativePath,
            fixCode: "// Avant (trop de dependances) :\npublic function __construct(\n    private readonly ServiceA \$a,\n    private readonly ServiceB \$b,\n    // ... 8+ dependances\n) {}\n\n// Apres (decoupage en sous-services) :\npublic function __construct(\n    private readonly UserNotifier \$notifier,\n    private readonly OrderProcessor \$processor,\n) {}",
            docUrl: 'https://symfony.com/doc/current/best_practices.html#services',
            businessImpact: 'Un service surcharge est difficile a maintenir et a tester. '
                . 'Chaque modification risque d\'introduire des regressions '
                . 'car les responsabilites sont melangees.',
            estimatedFixMinutes: 60,
        ));
    }

    /**
     * Detecte les appels de methodes (travail reel) dans le corps du constructeur.
     * Un constructeur ne devrait que stocker les dependances, pas executer de logique.
     */
    private function checkWorkInConstructor(
        AuditReport $report,
        string $content,
        string $filename,
        string $relativePath,
    ): void {
        // Extraire le corps du constructeur (entre les accolades apres __construct).
        if (!preg_match('/__construct\s*\([^)]*\)\s*\{(.*?)\n\s*\}/s', $content, $match)) {
            return;
        }

        $constructorBody = $match[1];

        // Verifier si le corps contient des appels de type find/findBy.
        $foundPatterns = [];
        foreach (self::WORK_PATTERNS as $pattern) {
            if (str_contains($constructorBody, $pattern)) {
                // Verifier plus specifiquement pour $this-> qu'il s'agit d'un appel de methode avec find.
                if ($pattern === '$this->') {
                    if (preg_match('/\$this->\w+->(find|findBy|findOneBy)\s*\(/', $constructorBody)) {
                        $foundPatterns[] = $pattern;
                    }
                } else {
                    $foundPatterns[] = $pattern;
                }
            }
        }

        if (empty($foundPatterns)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::ARCHITECTURE,
            analyzer: $this->getName(),
            message: "Travail dans le constructeur de {$filename}",
            detail: "Le fichier '{$filename}' execute des requetes ou de la logique metier "
                . "dans son constructeur. Un constructeur ne devrait que recevoir et stocker "
                . "les dependances injectees. Le travail reel doit etre fait dans les methodes.",
            suggestion: "Deplacer la logique du constructeur dans une methode dediee. "
                . "Si un pre-chargement est necessaire, utiliser un event listener "
                . "ou le pattern lazy loading.",
            file: $relativePath,
            fixCode: "// Avant (travail dans le constructeur) :\npublic function __construct(\n    private readonly UserRepository \$repo,\n) {\n    \$this->users = \$this->repo->findBy(['active' => true]);\n}\n\n// Apres (chargement lazy) :\npublic function getActiveUsers(): array\n{\n    return \$this->repo->findBy(['active' => true]);\n}",
            docUrl: 'https://symfony.com/doc/current/service_container/lazy_services.html',
            businessImpact: 'Le travail dans le constructeur est execute a chaque instanciation du service, '
                . 'meme si le service n\'est jamais utilise. Cela ralentit le demarrage '
                . 'de l\'application et gaspille des ressources (requetes SQL inutiles).',
            estimatedFixMinutes: 25,
        ));
    }
}
