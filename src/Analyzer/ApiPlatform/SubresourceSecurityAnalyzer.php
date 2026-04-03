<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\ApiPlatform;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les sous-ressources API Platform (uriTemplate avec parametres)
 * sans controle de securite adequat.
 *
 * Les sous-ressources (ex: /users/{userId}/orders) permettent de traverser
 * les relations entre entites. Sans securite dediee, un attaquant peut
 * acceder aux donnees d'un autre utilisateur en changeant le parametre {userId}.
 * C'est une faille IDOR (Insecure Direct Object Reference) classique.
 */
final class SubresourceSecurityAnalyzer implements AnalyzerInterface
{
    // Profondeur de nesting au-dela de laquelle la complexite d'autorisation
    // devient un risque majeur.
    private const MAX_SAFE_NESTING_DEPTH = 2;

    // Operations API Platform qui utilisent uriTemplate.
    private const URI_TEMPLATE_OPERATIONS = [
        'Get',
        'GetCollection',
        'Post',
        'Put',
        'Patch',
        'Delete',
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

                if ($content === false || !str_contains($content, 'uriTemplate')) {
                    continue;
                }

                $realPath = str_replace('\\', '/', $file->getRealPath());
                $normalizedDir = str_replace('\\', '/', $dir);
                $relativePath = $relativePrefix . ltrim(
                    str_replace($normalizedDir, '', $realPath),
                    '/',
                );

                $this->checkSubresourceSecurity($report, $content, $relativePath, $file->getFilename());
                $this->checkDeepNesting($report, $content, $relativePath, $file->getFilename());
            }
        }
    }

    public function getName(): string
    {
        return 'Subresource Security Analyzer';
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
     * Detecte les operations avec uriTemplate contenant des parametres de chemin
     * sans attribut security explicite.
     * Un uriTemplate comme /users/{userId}/orders necessite une verification
     * que l'utilisateur authentifie a le droit d'acceder aux orders de {userId}.
     */
    private function checkSubresourceSecurity(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        foreach (self::URI_TEMPLATE_OPERATIONS as $operation) {
            $pattern = '/#\[' . $operation . '\s*\([^]]*uriTemplate\s*:\s*[\'"]([^\'"]+)[\'"][^]]*\]/s';

            if (!preg_match_all($pattern, $content, $matches, \PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $operationBlock = $match[0];
                $uriTemplate = $match[1];

                // Compte les parametres de chemin ({param}).
                $paramCount = preg_match_all('/\{(\w+)\}/', $uriTemplate, $paramMatches);

                if ($paramCount < 2) {
                    // Un seul parametre (ex: /items/{id}) n'est pas une sous-ressource.
                    continue;
                }

                if (str_contains($operationBlock, 'security')) {
                    continue;
                }

                // Verifie si #[ApiResource] a une securite globale.
                if (preg_match('/#\[ApiResource\b[^]]*security[^]]*\]/s', $content)) {
                    continue;
                }

                $params = implode(', ', $paramMatches[1]);

                $report->addIssue(new Issue(
                    severity: Severity::WARNING,
                    module: Module::API_PLATFORM,
                    analyzer: $this->getName(),
                    message: "Sous-ressource '{$uriTemplate}' sans securite dans {$filename}",
                    detail: "L'operation {$operation} avec uriTemplate '{$uriTemplate}' "
                        . "contient les parametres [{$params}] mais n'a pas d'attribut security. "
                        . "Un attaquant peut acceder aux donnees d'un autre utilisateur "
                        . "en modifiant les parametres de l'URL (faille IDOR).",
                    suggestion: "Ajouter un attribut security qui verifie que l'utilisateur authentifie "
                        . "a le droit d'acceder aux ressources designees par les parametres.",
                    file: $relativePath,
                    fixCode: "#[{$operation}(\n"
                        . "    uriTemplate: '{$uriTemplate}',\n"
                        . "    security: \"is_granted('ROLE_USER') and object.getOwner() == user\",\n"
                        . ")]",
                    docUrl: 'https://api-platform.com/docs/core/subresources/',
                    businessImpact: "Un utilisateur authentifie peut acceder aux donnees d'autres utilisateurs "
                        . "en modifiant les identifiants dans l'URL. C'est la faille n1 du classement "
                        . "OWASP API Top 10 (Broken Object Level Authorization).",
                    estimatedFixMinutes: 20,
                ));
            }
        }
    }

    /**
     * Detecte les uriTemplate avec 3+ niveaux de nesting.
     * Chaque niveau ajoute une dimension de verification d'autorisation.
     * Ex: /companies/{companyId}/departments/{deptId}/employees/{empId}
     * necessite de verifier 3 niveaux de propriete.
     */
    private function checkDeepNesting(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        foreach (self::URI_TEMPLATE_OPERATIONS as $operation) {
            $pattern = '/#\[' . $operation . '\s*\([^]]*uriTemplate\s*:\s*[\'"]([^\'"]+)[\'"][^]]*\]/s';

            if (!preg_match_all($pattern, $content, $matches, \PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $uriTemplate = $match[1];
                $paramCount = preg_match_all('/\{(\w+)\}/', $uriTemplate);

                if ($paramCount <= self::MAX_SAFE_NESTING_DEPTH) {
                    continue;
                }

                $report->addIssue(new Issue(
                    severity: Severity::WARNING,
                    module: Module::API_PLATFORM,
                    analyzer: $this->getName(),
                    message: "Sous-ressource profonde ({$paramCount} niveaux) dans {$filename}",
                    detail: "L'uriTemplate '{$uriTemplate}' contient {$paramCount} parametres de chemin. "
                        . "Chaque niveau de nesting ajoute une dimension de verification d'autorisation "
                        . "qui est facile a oublier. Au-dela de " . self::MAX_SAFE_NESTING_DEPTH . " niveaux, "
                        . "la complexite d'autorisation devient un risque.",
                    suggestion: "Aplatir la structure en utilisant des filtres au lieu de sous-ressources. "
                        . "Ex: /employees?department={deptId} au lieu de "
                        . "/companies/{companyId}/departments/{deptId}/employees.",
                    file: $relativePath,
                    fixCode: "// Au lieu de nesting profond :\n"
                        . "// /companies/{companyId}/departments/{deptId}/employees\n\n"
                        . "// Utiliser des filtres :\n"
                        . "#[GetCollection(\n"
                        . "    uriTemplate: '/employees',\n"
                        . "    security: \"is_granted('ROLE_USER')\",\n"
                        . ")]\n"
                        . "#[ApiFilter(SearchFilter::class, properties: [\n"
                        . "    'department' => 'exact',\n"
                        . "    'department.company' => 'exact',\n"
                        . "])]",
                    docUrl: 'https://api-platform.com/docs/core/subresources/',
                    businessImpact: "Plus le nesting est profond, plus le risque de faille IDOR augmente. "
                        . "La verification d'autorisation a chaque niveau est souvent oubliee.",
                    estimatedFixMinutes: 30,
                ));
            }
        }
    }
}
