<?php

// src/Analyzer/ApiPlatform/OperationSecurityAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\ApiPlatform;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les ressources API Platform exposees sans controle d'acces.
 *
 * Verifie que chaque #[ApiResource] et chaque operation (#[Get], #[Post], etc.)
 * possede un attribut security. Detecte aussi les entites avec des champs
 * sensibles exposees en PUBLIC_ACCESS.
 */
final class OperationSecurityAnalyzer implements AnalyzerInterface
{
    // Noms de proprietes considerees comme sensibles.
    private const SENSITIVE_FIELDS = [
        'password',
        'token',
        'secret',
        'apiKey',
        'creditCard',
        'ssn',
        'socialSecurity',
    ];

    // Operations HTTP API Platform.
    private const OPERATION_ATTRIBUTES = [
        'Get',
        'Post',
        'Put',
        'Delete',
        'Patch',
    ];

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $directories = [
            $this->projectPath . '/src/Entity' => 'src/Entity/',
            $this->projectPath . '/src/ApiResource' => 'src/ApiResource/',
        ];

        foreach ($directories as $dir => $relativePrefix) {
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

                if (!str_contains($content, '#[ApiResource')) {
                    continue;
                }

                $relativePath = $relativePrefix . ltrim(
                    str_replace($dir, '', $file->getRealPath()),
                    '/\\',
                );

                $this->checkOperationSecurity($report, $content, $relativePath, $file->getFilename());
                $this->checkPublicAccessOnSensitive($report, $content, $relativePath, $file->getFilename());
            }
        }
    }

    public function getName(): string
    {
        return 'Operation Security Analyzer';
    }

    public function getModule(): Module
    {
        return Module::API_PLATFORM;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasApiPlatform();
    }

    /**
     * Detecte les operations #[ApiResource], #[Get], #[Post], etc. sans attribut security.
     * Une operation sans security est accessible a tous les visiteurs anonymes.
     */
    private function checkOperationSecurity(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Verifie #[ApiResource] sans security.
        if (preg_match('/#\[ApiResource\b[^]]*\]/', $content, $matches)) {
            $apiResourceBlock = $matches[0];

            if (!str_contains($apiResourceBlock, 'security')) {
                $report->addIssue(new Issue(
                    severity: Severity::CRITICAL,
                    module: Module::API_PLATFORM,
                    analyzer: $this->getName(),
                    message: "#[ApiResource] sans attribut security dans {$filename}",
                    detail: "L'entite '{$filename}' expose une ressource API Platform "
                        . "sans aucun controle d'acces. Toutes les operations CRUD "
                        . "sont accessibles par n'importe quel visiteur anonyme.",
                    suggestion: "Ajouter un attribut security sur #[ApiResource] pour restreindre "
                        . "l'acces, ou definir security sur chaque operation individuellement.",
                    file: $relativePath,
                    fixCode: "#[ApiResource(\n"
                        . "    security: \"is_granted('ROLE_USER')\",\n"
                        . ")]",
                    docUrl: 'https://api-platform.com/docs/core/security/',
                    businessImpact: 'Toutes les donnees de cette ressource sont accessibles sans authentification. '
                        . 'Un attaquant peut lire, creer, modifier ou supprimer des enregistrements.',
                    estimatedFixMinutes: 15,
                ));
            }
        }

        // Verifie les operations individuelles sans security.
        foreach (self::OPERATION_ATTRIBUTES as $operation) {
            $pattern = '/#\[' . $operation . '\b[^]]*\]/';

            if (!preg_match_all($pattern, $content, $matches)) {
                continue;
            }

            foreach ($matches[0] as $operationBlock) {
                if (!str_contains($operationBlock, 'security')) {
                    $report->addIssue(new Issue(
                        severity: Severity::CRITICAL,
                        module: Module::API_PLATFORM,
                        analyzer: $this->getName(),
                        message: "#[{$operation}] sans attribut security dans {$filename}",
                        detail: "L'operation #{$operation} dans '{$filename}' n'a pas d'attribut security. "
                            . "Cette operation est accessible a tout visiteur anonyme, "
                            . "meme si #[ApiResource] a une restriction globale.",
                        suggestion: "Ajouter security sur l'operation #[{$operation}] "
                            . "ou verifier que la securite globale de #[ApiResource] couvre ce cas.",
                        file: $relativePath,
                        fixCode: "#[{$operation}(\n"
                            . "    security: \"is_granted('ROLE_USER')\",\n"
                            . ")]",
                        docUrl: 'https://api-platform.com/docs/core/security/',
                        businessImpact: "L'operation {$operation} est accessible sans authentification. "
                            . "Selon l'operation, un attaquant peut lire ou modifier des donnees.",
                        estimatedFixMinutes: 10,
                    ));
                }
            }
        }
    }

    /**
     * Detecte les entites avec PUBLIC_ACCESS qui contiennent des champs sensibles.
     * Exposer des champs comme password ou token en acces public est dangereux.
     */
    private function checkPublicAccessOnSensitive(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        if (!str_contains($content, 'PUBLIC_ACCESS')) {
            return;
        }

        foreach (self::SENSITIVE_FIELDS as $field) {
            $pattern = '/\$' . preg_quote($field, '/') . '\b/';

            if (!preg_match($pattern, $content)) {
                continue;
            }

            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::API_PLATFORM,
                analyzer: $this->getName(),
                message: "PUBLIC_ACCESS sur entite avec champ sensible '\${$field}' dans {$filename}",
                detail: "L'entite '{$filename}' utilise PUBLIC_ACCESS dans sa configuration de securite "
                    . "et contient un champ sensible '\${$field}'. "
                    . "Ce champ peut etre expose dans les reponses API accessibles a tous.",
                suggestion: "Restreindre l'acces avec un role specifique au lieu de PUBLIC_ACCESS, "
                    . "ou exclure le champ sensible de la serialisation avec #[Ignore] ou #[Groups].",
                file: $relativePath,
                fixCode: "use Symfony\\Component\\Serializer\\Annotation\\Ignore;\n\n"
                    . "#[Ignore]\n"
                    . "private ?string \${$field} = null;",
                docUrl: 'https://api-platform.com/docs/core/security/',
                businessImpact: "Le champ '{$field}' contient des donnees sensibles qui peuvent etre "
                    . "lues par n'importe quel visiteur anonyme via l'API publique.",
                estimatedFixMinutes: 15,
            ));
        }
    }
}
