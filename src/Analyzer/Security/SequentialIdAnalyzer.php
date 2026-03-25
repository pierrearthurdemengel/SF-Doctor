<?php

// src/Analyzer/Security/SequentialIdAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Security;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les entites exposees via API Platform avec des IDs auto-increment
 * au lieu d'UUIDs.
 *
 * Les IDs sequentiels exposes dans une API permettent a un attaquant de :
 * 1. Enumerer toutes les ressources (IDOR)
 * 2. Estimer le volume de donnees (nombre de clients, commandes, etc.)
 * 3. Deviner les identifiants de ressources non autorisees
 */
final class SequentialIdAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $entityDir = $this->projectPath . '/src/Entity';

        if (!is_dir($entityDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($entityDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());

            if ($content === false) {
                continue;
            }

            $realPath = str_replace('\\', '/', $file->getRealPath());
            $normalizedDir = str_replace('\\', '/', $entityDir);
            $relativePath = 'src/Entity/' . ltrim(
                str_replace($normalizedDir, '', $realPath),
                '/',
            );

            $this->checkSequentialId($report, $content, $relativePath, $file->getFilename());
        }
    }

    public function getName(): string
    {
        return 'Sequential ID Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasApiPlatform();
    }

    /**
     * Detecte une entite avec #[ApiResource] utilisant un ID auto-increment
     * au lieu d'un UUID.
     *
     * Un ID auto-increment est detecte par la presence de #[ORM\GeneratedValue]
     * ou strategy="AUTO" / strategy="IDENTITY" sans utilisation de Uuid.
     */
    private function checkSequentialId(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // L'entite doit etre exposee via ApiResource.
        if (!str_contains($content, '#[ApiResource') && !str_contains($content, '@ApiResource')) {
            return;
        }

        // Detecter les IDs auto-increment.
        $hasGeneratedValue = (bool) preg_match('/#\[ORM\\\\GeneratedValue/', $content);
        $hasAutoIncrement = (bool) preg_match('/strategy\s*[=:]\s*[\'"](?:AUTO|IDENTITY)[\'"]/', $content);

        if (!$hasGeneratedValue && !$hasAutoIncrement) {
            return;
        }

        // Verifier si l'entite utilise deja un UUID.
        $hasUuid = str_contains($content, 'Uuid')
            || str_contains($content, 'UuidType')
            || str_contains($content, 'Ulid')
            || (bool) preg_match('/type\s*[=:]\s*[\'"]uuid[\'"]/', $content);

        if ($hasUuid) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: "ID auto-increment expose via API dans {$filename}",
            detail: "L'entite '{$filename}' est exposee via #[ApiResource] avec un ID auto-increment. "
                . "Les IDs sequentiels permettent a un attaquant d'enumerer toutes les ressources "
                . "(IDOR), d'estimer le volume de donnees et de deviner les identifiants de "
                . "ressources non autorisees.",
            suggestion: "Remplacer l'ID auto-increment par un UUID. Utiliser le composant "
                . "Symfony Uid (symfony/uid) avec le type Doctrine 'uuid'.",
            file: $relativePath,
            fixCode: "// Migration vers UUID pour {$filename} :\n"
                . "// 1. Installer le composant Uid :\n"
                . "// composer require symfony/uid\n\n"
                . "// 2. Remplacer l'ID dans l'entite :\n"
                . "use Symfony\\Component\\Uid\\Uuid;\n\n"
                . "#[ORM\\Id]\n"
                . "#[ORM\\Column(type: 'uuid', unique: true)]\n"
                . "#[ORM\\GeneratedValue(strategy: 'CUSTOM')]\n"
                . "#[ORM\\CustomIdGenerator(class: 'doctrine.uuid_generator')]\n"
                . "private ?Uuid \$id = null;",
            docUrl: 'https://symfony.com/doc/current/components/uid.html',
            businessImpact: 'Les IDs sequentiels exposes dans l\'API permettent a un attaquant '
                . 'd\'enumerer toutes les ressources (clients, commandes, factures) '
                . 'et d\'estimer le volume d\'activite de l\'entreprise.',
            estimatedFixMinutes: 30,
        ));
    }
}
