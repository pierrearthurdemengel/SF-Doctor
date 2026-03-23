<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Architecture;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Detecte le code metier (requetes Doctrine) dans les controllers Symfony.
 *
 * Un controller ne doit pas construire de requetes SQL/DQL directement.
 * Cette logique appartient aux Repositories ou aux Services.
 */
final class ControllerAnalyzer implements AnalyzerInterface
{
    // Patterns indiquant une construction de requete directe dans un controller.
    // Leur presence est une violation d'architecture (CRITICAL).
    private const QUERY_PATTERNS = [
        'createQueryBuilder(' => 'construction de QueryBuilder',
        'createQuery('        => 'requete DQL directe',
    ];

    // Methodes de l'EntityManager acceptables dans un controller.
    // persist, flush, remove et find sont des operations de cycle de vie,
    // pas des requetes metier. On les tolere.
    private const ALLOWED_EM_METHODS = [
        'persist',
        'flush',
        'remove',
        'find',
        'getReference',
        'clear',
    ];

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $controllerDir = $this->projectPath . '/src/Controller';

        if (!is_dir($controllerDir)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->name('*.php')->in($controllerDir);

        if (!$finder->hasResults()) {
            return;
        }

        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = 'src/Controller/' . $file->getRelativePathname();

            $this->checkQueryBuilderUsage($report, $content, $file->getFilename(), $relativePath);
            $this->checkEntityManagerUsage($report, $content, $file->getFilename(), $relativePath);
        }
    }

    public function getModule(): Module
    {
        return Module::ARCHITECTURE;
    }

    public function getName(): string
    {
        return 'Controller Analyzer';
    }

    public function supports(): bool
    {
        // Verifie que Doctrine ORM est installe.
        return class_exists(\Doctrine\ORM\EntityManagerInterface::class);
    }

    /**
     * Detecte les constructions de QueryBuilder et requetes DQL dans un controller.
     */
    private function checkQueryBuilderUsage(
        AuditReport $report,
        string $content,
        string $filename,
        string $relativePath,
    ): void {
        foreach (self::QUERY_PATTERNS as $pattern => $description) {
            if (!str_contains($content, $pattern)) {
                continue;
            }

            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::ARCHITECTURE,
                analyzer: $this->getName(),
                message: "Code metier dans {$filename} : {$description}",
                detail: "Le controller '{$filename}' contient une {$description}. "
                    . "Les requetes Doctrine appartiennent aux Repositories, "
                    . "pas aux controllers. Cela viole le principe de separation des responsabilites "
                    . "et rend le controller difficile a tester.",
                suggestion: "Deplacer cette logique dans un Repository "
                    . "(ex: UserRepository::findActiveUsers()) "
                    . "et injecter le Repository dans le controller.",
                file: $relativePath,
            ));
        }
    }

    /**
     * Detecte les usages de l'EntityManager non-acceptables dans un controller.
     *
     * Les appels persist/flush/remove/find sont toleres car ils font partie
     * du cycle de vie des entites. En revanche, utiliser l'EntityManager
     * pour construire des requetes indique que de la logique metier
     * a ete placee dans le mauvais endroit.
     */
    private function checkEntityManagerUsage(
        AuditReport $report,
        string $content,
        string $filename,
        string $relativePath,
    ): void {
        // Detecte les appels ->methode() sur une variable $entityManager ou $em.
        // Le pattern capture le nom de la methode appelee.
        preg_match_all(
            '/(?:\$this->|\$)(?:entityManager|em|doctrine)\s*->\s*([a-zA-Z]+)\s*\(/',
            $content,
            $matches,
        );

        if (empty($matches[1])) {
            return;
        }

        // Filtre les methodes non-acceptables.
        $problematicMethods = array_filter(
            $matches[1],
            fn (string $method): bool => !in_array($method, self::ALLOWED_EM_METHODS, true),
        );

        if (empty($problematicMethods)) {
            return;
        }

        $methodList = implode(', ', array_unique($problematicMethods));

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::ARCHITECTURE,
            analyzer: $this->getName(),
            message: "Usage avance de l'EntityManager dans {$filename} : {$methodList}",
            detail: "Le controller '{$filename}' utilise l'EntityManager pour des operations "
                . "qui devraient se trouver dans un Repository ou un Service : {$methodList}. "
                . "Seuls persist(), flush(), remove() et find() sont acceptables dans un controller.",
            suggestion: "Deplacer la logique de requete dans un Repository dedie. "
                . "Injecter le Repository directement plutot que l'EntityManager.",
            file: $relativePath,
        ));
    }
}