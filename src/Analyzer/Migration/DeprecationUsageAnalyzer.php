<?php

// src/Analyzer/Migration/DeprecationUsageAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Migration;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les usages de code deprecie dans le projet Symfony.
 *
 * Scanne les fichiers PHP du dossier src/ pour reperer les patterns
 * qui seront supprimes dans les prochaines versions majeures.
 */
final class DeprecationUsageAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $srcDir = $this->projectPath . '/src';

        if (!is_dir($srcDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                continue;
            }

            // Chemin relatif pour l'affichage.
            $relativePath = str_replace(
                $this->projectPath . '/',
                '',
                str_replace('\\', '/', $file->getRealPath()),
            );

            $this->checkGetDoctrineUsage($report, $content, $relativePath, $file->getFilename());
            $this->checkGetExtendedTypeUsage($report, $content, $relativePath, $file->getFilename());
        }
    }

    public function getName(): string
    {
        return 'Deprecation Usage Analyzer';
    }

    public function getModule(): Module
    {
        return Module::MIGRATION;
    }

    public function supports(ProjectContext $context): bool
    {
        return true;
    }

    /**
     * Detecte l'usage de getDoctrine() dans les controllers.
     *
     * getDoctrine() est deprecie depuis Symfony 6.4. Il faut injecter
     * EntityManagerInterface ou le Repository directement dans le constructeur
     * ou la methode d'action.
     */
    private function checkGetDoctrineUsage(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        if (!str_contains($content, 'getDoctrine()')) {
            return;
        }

        // Compter le nombre d'occurrences pour le message.
        $count = substr_count($content, 'getDoctrine()');

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::MIGRATION,
            analyzer: $this->getName(),
            message: sprintf(
                'Usage de getDoctrine() deprecie dans %s (%d occurrence%s)',
                $filename,
                $count,
                $count > 1 ? 's' : '',
            ),
            detail: 'La methode getDoctrine() heritee de AbstractController est depreciee depuis '
                . 'Symfony 6.4 et sera supprimee dans Symfony 8.0. '
                . 'Ce raccourci masque la dependance reelle du controller et complique les tests unitaires.',
            suggestion: 'Injecter EntityManagerInterface ou le Repository directement dans le constructeur '
                . 'ou la signature de la methode d\'action.',
            file: $relativePath,
            fixCode: "// Avant (deprecie) :\n\$em = \$this->getDoctrine()->getManager();\n\n"
                . "// Apres (injection dans le constructeur) :\npublic function __construct(\n"
                . "    private readonly EntityManagerInterface \$entityManager,\n) {}\n\n"
                . "// Ou injection dans la methode d'action :\npublic function index(EntityManagerInterface \$entityManager): Response\n{\n"
                . "    // ...\n}",
            docUrl: 'https://symfony.com/doc/current/doctrine.html#fetching-objects-from-the-database',
            businessImpact: 'Le code utilisant getDoctrine() cessera de fonctionner lors de la migration '
                . 'vers Symfony 8. Plus le nombre d\'occurrences est eleve, plus la migration sera couteuse.',
            estimatedFixMinutes: 10,
        ));
    }

    /**
     * Detecte les FormTypeExtension qui definissent getExtendedType() sans getExtendedTypes().
     *
     * Depuis Symfony 4.2, getExtendedTypes() (au pluriel) remplace getExtendedType().
     * getExtendedType() est deprecie et sera supprime dans une future version majeure.
     */
    private function checkGetExtendedTypeUsage(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Verifie si le fichier declare getExtendedType() sans getExtendedTypes().
        $hasOldMethod = (bool) preg_match('/function\s+getExtendedType\s*\(/', $content);
        $hasNewMethod = (bool) preg_match('/function\s+getExtendedTypes\s*\(/', $content);

        if (!$hasOldMethod || $hasNewMethod) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::MIGRATION,
            analyzer: $this->getName(),
            message: sprintf(
                'getExtendedType() sans getExtendedTypes() dans %s',
                $filename,
            ),
            detail: 'La methode getExtendedType() de FormTypeExtension est depreciee. '
                . 'Elle doit etre remplacee par getExtendedTypes() (au pluriel) qui retourne '
                . 'un iterable de classes de FormType. L\'ancienne methode sera supprimee '
                . 'dans une prochaine version majeure de Symfony.',
            suggestion: 'Remplacer getExtendedType() par la methode statique getExtendedTypes() '
                . 'qui retourne un iterable.',
            file: $relativePath,
            fixCode: "// Avant (deprecie) :\npublic function getExtendedType(): string\n{\n"
                . "    return TextType::class;\n}\n\n"
                . "// Apres :\npublic static function getExtendedTypes(): iterable\n{\n"
                . "    return [TextType::class];\n}",
            docUrl: 'https://symfony.com/doc/current/form/create_form_type_extension.html',
            businessImpact: 'Le formulaire cessera de fonctionner lors de la prochaine migration majeure '
                . 'si getExtendedTypes() n\'est pas implemente.',
            estimatedFixMinutes: 10,
        ));
    }
}
