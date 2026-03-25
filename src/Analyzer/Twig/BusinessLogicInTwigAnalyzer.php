<?php

// src/Analyzer/Twig/BusinessLogicInTwigAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Twig;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte la logique metier placee dans les templates Twig.
 *
 * Les templates doivent se limiter a l'affichage. Les requetes en base,
 * les conditions complexes et les calculs metier appartiennent aux services
 * ou aux controllers.
 */
final class BusinessLogicInTwigAnalyzer implements AnalyzerInterface
{
    // Patterns d'appels de repository dans Twig.
    private const REPOSITORY_PATTERNS = [
        '.findBy(',
        '.findAll(',
        '.findOneBy(',
        '.findBy (',
        '.findAll (',
        '.findOneBy (',
    ];

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $templateDir = $this->projectPath . '/templates';

        if (!is_dir($templateDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templateDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                continue;
            }

            $relativePath = str_replace(
                $this->projectPath . '/',
                '',
                str_replace('\\', '/', $file->getRealPath()),
            );

            $this->checkComplexSetBlocks($report, $content, $relativePath, $file->getFilename());
            $this->checkRepositoryCalls($report, $content, $relativePath, $file->getFilename());
        }
    }

    public function getName(): string
    {
        return 'Business Logic In Twig Analyzer';
    }

    public function getModule(): Module
    {
        return Module::TWIG;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasTwig();
    }

    /**
     * Detecte les blocs {% set %} contenant plus de 3 conditions.
     *
     * Un bloc set avec de nombreuses conditions indique de la logique metier
     * qui devrait etre dans un service ou un Twig Extension.
     */
    private function checkComplexSetBlocks(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Rechercher les blocs {% set ... %} multi-lignes ou avec des conditions.
        // Pattern : {% set variable = expression %}
        preg_match_all('/\{%\s*set\s+\w+\s*=\s*(.*?)%\}/s', $content, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[1])) {
            return;
        }

        foreach ($matches[1] as $match) {
            $expression = $match[0];

            // Compter les operateurs de condition dans l'expression.
            $conditionCount = 0;
            $conditionKeywords = ['and', 'or', 'not', '?', 'is defined', 'is not', 'is empty', 'is same as'];

            foreach ($conditionKeywords as $keyword) {
                $conditionCount += substr_count(strtolower($expression), $keyword);
            }

            if ($conditionCount <= 3) {
                continue;
            }

            // Calculer le numero de ligne.
            $offset = $match[1];
            $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;

            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::TWIG,
                analyzer: $this->getName(),
                message: sprintf(
                    'Bloc {%% set %%} complexe dans %s (ligne %d, %d conditions)',
                    $filename,
                    $lineNumber,
                    $conditionCount,
                ),
                detail: sprintf(
                    'Le template "%s" contient un bloc {%% set %%} avec %d conditions. '
                    . 'Cette complexite dans un template rend la logique difficile a tester, '
                    . 'a debugger et a maintenir. Le template devrait uniquement afficher '
                    . 'des donnees preparees par le controller ou un service.',
                    $filename,
                    $conditionCount,
                ),
                suggestion: 'Deplacer cette logique dans un Twig Extension (TwigFunction ou TwigFilter) '
                    . 'ou preparer la valeur dans le controller avant de la passer au template.',
                file: $relativePath,
                line: $lineNumber,
                fixCode: "// Dans src/Twig/AppExtension.php :\nuse Twig\\Extension\\AbstractExtension;\n"
                    . "use Twig\\TwigFunction;\n\n"
                    . "class AppExtension extends AbstractExtension\n{\n"
                    . "    public function getFunctions(): array\n    {\n"
                    . "        return [\n"
                    . "            new TwigFunction('compute_value', [\$this, 'computeValue']),\n"
                    . "        ];\n    }\n\n"
                    . "    public function computeValue(/* params */): mixed\n    {\n"
                    . "        // Logique metier ici\n    }\n}",
                docUrl: 'https://symfony.com/doc/current/templating/twig_extension.html',
                businessImpact: 'La logique metier dans les templates est invisible aux tests unitaires. '
                    . 'Les bugs de calcul ne sont detectes qu\'en production ou lors de tests manuels.',
                estimatedFixMinutes: 30,
            ));
        }
    }

    /**
     * Detecte les appels de methodes de repository dans les templates Twig.
     *
     * Les appels comme entity.findBy() ou repository.findAll() dans un template
     * indiquent une violation grave de la separation des responsabilites.
     * Les requetes en base doivent etre executees dans le controller ou un service.
     */
    private function checkRepositoryCalls(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        $lines = explode("\n", $content);
        $problematicLines = [];

        foreach ($lines as $lineNumber => $line) {
            foreach (self::REPOSITORY_PATTERNS as $pattern) {
                if (str_contains($line, $pattern)) {
                    $problematicLines[] = $lineNumber + 1;
                    break;
                }
            }
        }

        if (count($problematicLines) === 0) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::TWIG,
            analyzer: $this->getName(),
            message: sprintf(
                'Appel de repository dans le template %s (ligne%s %s)',
                $filename,
                count($problematicLines) > 1 ? 's' : '',
                implode(', ', $problematicLines),
            ),
            detail: sprintf(
                'Le template "%s" contient des appels directs a des methodes de repository '
                . '(findBy, findAll, findOneBy). Cela execute des requetes SQL depuis la couche '
                . 'de presentation, violant la separation des responsabilites. '
                . 'Les templates ne doivent jamais acceder directement a la base de donnees.',
                $filename,
            ),
            suggestion: 'Executer la requete dans le controller et passer le resultat au template '
                . 'via la methode render(). Ou utiliser un Twig Extension qui injecte le repository.',
            file: $relativePath,
            fixCode: "{# Avant (dans le template) : #}\n{% set users = repository.findAll() %}\n\n"
                . "{# Apres (dans le controller) : #}\n"
                . "// Controller :\npublic function index(UserRepository \$userRepository): Response\n{\n"
                . "    return \$this->render('page.html.twig', [\n"
                . "        'users' => \$userRepository->findAll(),\n"
                . "    ]);\n}",
            docUrl: 'https://symfony.com/doc/current/templates.html#template-variables',
            businessImpact: 'Les requetes dans les templates creent des problemes N+1 invisibles, '
                . 'degradent les performances et rendent impossible le cache HTTP. '
                . 'Le debugging est complexe car l\'origine de la requete est masquee.',
            estimatedFixMinutes: 30,
        ));
    }
}
