<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Performance;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Detecte les acces potentiels a des relations Doctrine non chargees
 * dans les boucles Twig (pattern N+1).
 */
final class NplusOneAnalyzer implements AnalyzerInterface
{
    private const TEMPLATES_DIR = '/templates';

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function supports(): bool
    {
        return is_dir($this->projectPath . self::TEMPLATES_DIR);
    }

    public function analyze(AuditReport $report): void
    {
        $finder = new Finder();
        $finder->files()->name('*.twig')->in($this->projectPath . self::TEMPLATES_DIR);

        foreach ($finder as $file) {
            $this->analyzeFile($file->getRealPath(), $file->getRelativePathname(), $report);
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
            // Detection d'une ouverture de boucle : {% for item in collection %}
            if (preg_match('/\{%-?\s*for\s+(\w+)\s+in\s+\w+/', $line, $forMatch)) {
                $forStack[] = $forMatch[1];
                continue;
            }

            // Detection de la fermeture de la boucle la plus recente.
            if (preg_match('/\{%-?\s*endfor\s*-?%\}/', $line)) {
                array_pop($forStack);
                continue;
            }

            if (empty($forStack)) {
                continue;
            }

            $loopVar = end($forStack);

            // Detection d'un acces a deux niveaux de profondeur sur la variable de boucle :
            // {{ loopVar.relation.property }} - signe d'un lazy-load potentiel.
            $pattern = '/\b' . preg_quote($loopVar, '/') . '\.\w+\.\w+/';

            if (preg_match($pattern, $line, $accessMatch)) {
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
                ));
            }
        }
    }
}