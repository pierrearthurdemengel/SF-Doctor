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
 * Detecte les EventSubscribers trop volumineux ou trop couples a Doctrine.
 *
 * Un subscriber devrait etre un point d'entree leger qui delegue le travail
 * a un service dedie. Un subscriber qui contient de la logique metier
 * ou qui manipule l'EntityManager directement est un signe de couplage fort.
 */
final class EventSubscriberAnalyzer implements AnalyzerInterface
{
    // Seuil de lignes au-dela duquel un subscriber est considere trop volumineux.
    private const MAX_LINES = 80;

    // Patterns indiquant un couplage fort avec Doctrine.
    private const DOCTRINE_PATTERNS = [
        'EntityManager',
        '->persist(',
        '->flush(',
    ];

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $subscriberDir = $this->projectPath . '/src/EventSubscriber';

        if (!is_dir($subscriberDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($subscriberDir, \FilesystemIterator::SKIP_DOTS),
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

            $realSubscriberDir = realpath($subscriberDir);
            if ($realSubscriberDir === false) {
                continue;
            }

            $relativePath = 'src/EventSubscriber/' . ltrim(
                str_replace('\\', '/', substr($file->getRealPath(), strlen($realSubscriberDir))),
                '/',
            );

            $this->checkFileLength($report, $content, $file->getFilename(), $relativePath);
            $this->checkDoctrineCoupling($report, $content, $file->getFilename(), $relativePath);
        }
    }

    public function getModule(): Module
    {
        return Module::ARCHITECTURE;
    }

    public function getName(): string
    {
        return 'EventSubscriber Analyzer';
    }

    public function supports(ProjectContext $context): bool
    {
        $subscriberDir = $context->getProjectPath() . '/src/EventSubscriber';

        return is_dir($subscriberDir);
    }

    /**
     * Detecte les subscribers avec trop de lignes de code.
     * Un subscriber volumineux contient probablement de la logique metier
     * qui devrait etre deleguee a un service dedie.
     */
    private function checkFileLength(
        AuditReport $report,
        string $content,
        string $filename,
        string $relativePath,
    ): void {
        $lineCount = substr_count($content, "\n") + 1;

        if ($lineCount <= self::MAX_LINES) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::ARCHITECTURE,
            analyzer: $this->getName(),
            message: "EventSubscriber trop volumineux : {$filename} ({$lineCount} lignes)",
            detail: "Le fichier '{$filename}' contient {$lineCount} lignes "
                . "(seuil recommande : " . self::MAX_LINES . "). "
                . "Un subscriber ne devrait etre qu'un point d'entree leger qui delegue "
                . "le travail a un service dedie.",
            suggestion: "Extraire la logique metier dans un service dedie "
                . "et n'appeler que ce service depuis le subscriber.",
            file: $relativePath,
            fixCode: "// Avant (logique dans le subscriber) :\nclass OrderSubscriber implements EventSubscriberInterface\n{\n    public function onOrderCreated(OrderEvent \$event): void\n    {\n        // 50 lignes de logique metier...\n    }\n}\n\n// Apres (delegation a un service) :\nclass OrderSubscriber implements EventSubscriberInterface\n{\n    public function __construct(\n        private readonly OrderProcessor \$processor,\n    ) {}\n\n    public function onOrderCreated(OrderEvent \$event): void\n    {\n        \$this->processor->handleNewOrder(\$event->getOrder());\n    }\n}",
            docUrl: 'https://symfony.com/doc/current/event_dispatcher.html#creating-an-event-subscriber',
            businessImpact: 'Un subscriber volumineux est difficile a tester unitairement '
                . 'et melange la logique d\'ecoute d\'evenements avec la logique metier. '
                . 'Les bugs sont plus difficiles a isoler et a corriger.',
            estimatedFixMinutes: 45,
        ));
    }

    /**
     * Detecte l'utilisation de l'EntityManager dans les subscribers.
     * Un subscriber qui persiste ou flush directement est fortement couple a Doctrine.
     */
    private function checkDoctrineCoupling(
        AuditReport $report,
        string $content,
        string $filename,
        string $relativePath,
    ): void {
        $foundPatterns = [];

        foreach (self::DOCTRINE_PATTERNS as $pattern) {
            if (str_contains($content, $pattern)) {
                $foundPatterns[] = $pattern;
            }
        }

        if (empty($foundPatterns)) {
            return;
        }

        $patternList = implode(', ', $foundPatterns);

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::ARCHITECTURE,
            analyzer: $this->getName(),
            message: "Couplage Doctrine dans {$filename} : {$patternList}",
            detail: "Le fichier '{$filename}' utilise directement l'EntityManager ou des methodes "
                . "Doctrine (persist, flush). Un subscriber ne devrait pas manipuler "
                . "la couche de persistance directement.",
            suggestion: "Deleguer les operations Doctrine a un service ou un repository dedie. "
                . "Le subscriber ne devrait que dispatcher le travail.",
            file: $relativePath,
            fixCode: "// Avant (couplage Doctrine dans le subscriber) :\npublic function onUserRegistered(UserEvent \$event): void\n{\n    \$log = new AuditLog(\$event->getUser());\n    \$this->entityManager->persist(\$log);\n    \$this->entityManager->flush();\n}\n\n// Apres (delegation au service) :\npublic function onUserRegistered(UserEvent \$event): void\n{\n    \$this->auditLogger->logRegistration(\$event->getUser());\n}",
            docUrl: 'https://symfony.com/doc/current/doctrine/events.html',
            businessImpact: 'Le couplage direct avec Doctrine dans un subscriber rend le code '
                . 'fragile face aux changements de schema. Un flush() inattendu peut '
                . 'persister des entites en etat intermediaire, causant des incoherences en base.',
            estimatedFixMinutes: 30,
        ));
    }
}
