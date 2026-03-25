<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Performance;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use Symfony\Component\Finder\Finder;
use PierreArthur\SfDoctor\Context\ProjectContext;

/**
 * Detecte les acces potentiels a des relations Doctrine non chargees
 * dans les boucles Twig (pattern N+1).
 */
final class NplusOneAnalyzer implements AnalyzerInterface
{
    private const TEMPLATES_DIR = '/templates';

    /** @var list<string>|null */
    private ?array $preloadedRelations = null;

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function supports(ProjectContext $context): bool
    {
        return is_dir($context->getProjectPath() . '/templates');
    }

    public function analyze(AuditReport $report): void
    {
        $finder = new Finder();
        $finder->files()->name('*.twig')->in($this->projectPath . self::TEMPLATES_DIR);

        foreach ($finder as $file) {
            $this->analyzeFile($file->getRealPath(), str_replace('\\', '/', $file->getRelativePathname()), $report);
        }
    }

    public function getModule(): Module
    {
        return Module::PERFORMANCE;
    }

    public function getName(): string
    {
        return 'N+1 Query Analyzer';
    }

    private function analyzeFile(string $absolutePath, string $relativePath, AuditReport $report): void
    {
        $content = file_get_contents($absolutePath);

        if ($content === false) {
            return;
        }

        $lines = explode("\n", $content);
        $forStack = [];

        foreach ($lines as $lineNumber => $line) {
            if (preg_match('/\{%-?\s*for\s+(\w+)\s+in\s+\w+/', $line, $forMatch)) {
                $forStack[] = $forMatch[1];
                continue;
            }

            if (preg_match('/\{%-?\s*endfor\s*-?%\}/', $line)) {
                array_pop($forStack);
                continue;
            }

            if (empty($forStack)) {
                continue;
            }

            $loopVar = end($forStack);

            $pattern = '/\b' . preg_quote($loopVar, '/') . '\.\w+\.\w+/';

            // Support du commentaire {# sf-doctor:ignore #} sur la ligne precedente ou courante.
            $prevLine = $lines[$lineNumber - 1] ?? '';
            if (str_contains($prevLine, 'sf-doctor:ignore') || str_contains($line, 'sf-doctor:ignore')) {
                continue;
            }

            if (preg_match($pattern, $line, $accessMatch) && !str_contains($accessMatch[0], '.vars.')) {
                // Extraire le nom de la relation a partir de l'acces detecte.
                // Ex: "order.customer.name" -> relation = "customer"
                $parts = explode('.', $accessMatch[0]);
                $relation = $parts[1] ?? 'relation';

                // Verifier si la relation est deja pre-chargee via JOIN FETCH ou EAGER.
                if (in_array($relation, $this->getPreloadedRelations(), true)) {
                    continue;
                }

                $report->addIssue(new Issue(
                    severity: Severity::WARNING,
                    module: Module::PERFORMANCE,
                    analyzer: $this->getName(),
                    message: sprintf(
                        'Possible requete N+1 : "%s" accede dans une boucle Twig.',
                        $accessMatch[0],
                    ),
                    detail: sprintf(
                        'Fichier "%s", ligne %d. Chaque iteration de la boucle peut declencher '
                        . 'une requete SQL separee pour charger la relation.',
                        $relativePath,
                        $lineNumber + 1,
                    ),
                    suggestion: 'Utiliser un JOIN FETCH dans le repository pour pre-charger '
                        . 'la relation en une seule requete.',
                    file: 'templates/' . $relativePath,
                    line: $lineNumber + 1,
                    fixCode: sprintf(
                        "// Dans le Repository, pre-charger la relation '%s' :\npublic function findAllWith%s(): array\n{\n    return \$this->createQueryBuilder('entity')\n        ->leftJoin('entity.%s', '%s')\n        ->addSelect('%s')\n        ->getQuery()\n        ->getResult();\n}",
                        $relation,
                        ucfirst($relation),
                        $relation,
                        $relation,
                        $relation,
                    ),
                    docUrl: 'https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/dql-doctrine-query-language.html#joins',
                    businessImpact: '100 commandes affichees = 100 requetes SQL supplementaires. '
                        . 'Sur une liste de 1000 elements, cela peut degrader le temps de reponse '
                        . 'de quelques millisecondes a plusieurs secondes.',
                    estimatedFixMinutes: 20,
                ));
            }
        }
    }

    /**
     * Collecte les noms de relations pre-chargees via leftJoin/join dans les fichiers PHP
     * ou via fetch: EAGER dans les entites Doctrine.
     *
     * Detecte les patterns :
     *   ->leftJoin('alias.relation', 'joinAlias')
     *   ->join('alias.relation', 'joinAlias')
     * et en extrait le nom de la relation (ex: "product", "customer", "method").
     *
     * @return list<string>
     */
    private function getPreloadedRelations(): array
    {
        if ($this->preloadedRelations !== null) {
            return $this->preloadedRelations;
        }

        $this->preloadedRelations = [];

        // Scan de src/ pour les patterns leftJoin/join qui indiquent un pre-chargement.
        $srcDir = $this->projectPath . '/src';
        if (is_dir($srcDir)) {
            $srcFinder = new Finder();
            $srcFinder->files()->name('*.php')->in($srcDir);

            foreach ($srcFinder as $file) {
                $content = $file->getContents();

                // Match: ->leftJoin('alias.relation', ...) ou ->join('alias.relation', ...)
                if (preg_match_all('/(?:left)?[Jj]oin\s*\(\s*[\'"]\w+\.(\w+)[\'"]/', $content, $matches)) {
                    foreach ($matches[1] as $rel) {
                        $this->preloadedRelations[] = $rel;
                    }
                }
            }
        }

        // Scan des Entites pour fetch: 'EAGER'.
        $entityDir = $this->projectPath . '/src/Entity';
        if (is_dir($entityDir)) {
            $entityFinder = new Finder();
            $entityFinder->files()->name('*.php')->in($entityDir);

            foreach ($entityFinder as $file) {
                $content = $file->getContents();

                if (!preg_match("/fetch\s*[:=]\s*['\"]EAGER['\"]/i", $content)) {
                    continue;
                }

                $lines = explode("\n", $content);

                foreach ($lines as $i => $line) {
                    if (!preg_match('/(?:private|protected|public)\s+.*\$(\w+)/', $line, $propMatch)) {
                        continue;
                    }

                    $lookback = max(0, $i - 5);
                    $context = implode("\n", array_slice($lines, $lookback, $i - $lookback));

                    if (preg_match("/fetch\s*[:=]\s*['\"]EAGER['\"]/i", $context)) {
                        $this->preloadedRelations[] = $propMatch[1];
                    }
                }
            }
        }

        return $this->preloadedRelations;
    }
}