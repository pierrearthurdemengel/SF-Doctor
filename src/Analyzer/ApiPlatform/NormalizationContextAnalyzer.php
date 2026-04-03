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
 * Detecte les incoherences de normalization/denormalization context
 * dans les ressources API Platform.
 *
 * Les contexts de serialisation controlent quelles proprietes sont lisibles (output)
 * et modifiables (input). Une mauvaise configuration expose des proprietes
 * en ecriture qui ne devraient pas l'etre, ou autorise la modification
 * de champs calcules ou internes.
 */
final class NormalizationContextAnalyzer implements AnalyzerInterface
{
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

                if ($content === false || !str_contains($content, '#[ApiResource')) {
                    continue;
                }

                $realPath = str_replace('\\', '/', $file->getRealPath());
                $normalizedDir = str_replace('\\', '/', $dir);
                $relativePath = $relativePrefix . ltrim(
                    str_replace($normalizedDir, '', $realPath),
                    '/',
                );

                $this->checkMissingDenormalizationContext($report, $content, $relativePath, $file->getFilename());
                $this->checkIdenticalGroups($report, $content, $relativePath, $file->getFilename());
                $this->checkOrphanedGroups($report, $content, $relativePath, $file->getFilename());
            }
        }
    }

    public function getName(): string
    {
        return 'Normalization Context Analyzer';
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
     * Detecte les ressources avec normalizationContext mais sans denormalizationContext
     * qui possedent des operations d'ecriture (Post, Put, Patch).
     *
     * Sans denormalizationContext, toutes les proprietes sont modifiables par defaut.
     * Un attaquant peut modifier des champs calcules, internes ou proteges.
     */
    private function checkMissingDenormalizationContext(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        $apiResourceBlock = $this->extractApiResourceBlock($content);

        if ($apiResourceBlock === null) {
            return;
        }

        if (!str_contains($apiResourceBlock, 'normalizationContext')) {
            return;
        }

        if (str_contains($apiResourceBlock, 'denormalizationContext')) {
            return;
        }

        // Verifie la presence d'operations d'ecriture.
        $hasWriteOperation = str_contains($content, '#[Post')
            || str_contains($content, '#[Put')
            || str_contains($content, '#[Patch');

        // Si pas d'operations explicites, les operations CRUD par defaut incluent POST/PUT.
        if (!$hasWriteOperation && !preg_match('/operations\s*:\s*\[/', $content)) {
            $hasWriteOperation = true;
        }

        if (!$hasWriteOperation) {
            return;
        }

        $className = $this->extractClassName($content);

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "normalizationContext sans denormalizationContext dans {$filename}",
            detail: "La ressource '{$className}' definit un normalizationContext "
                . "(controle des proprietes en lecture) mais pas de denormalizationContext "
                . "(controle des proprietes en ecriture). Avec des operations d'ecriture "
                . "actives, toutes les proprietes sont modifiables par le client.",
            suggestion: "Ajouter un denormalizationContext avec un groupe 'write' dedie "
                . "pour restreindre les proprietes modifiables.",
            file: $relativePath,
            fixCode: "#[ApiResource(\n"
                . "    normalizationContext: ['groups' => ['read']],\n"
                . "    denormalizationContext: ['groups' => ['write']],\n"
                . ")]",
            docUrl: 'https://api-platform.com/docs/core/serialization/',
            businessImpact: "Un client peut modifier des proprietes qui devraient etre en lecture seule "
                . "(id, dates de creation, champs calcules, roles). Cela peut corrompre "
                . "les donnees ou escalader les privileges.",
            estimatedFixMinutes: 20,
        ));
    }

    /**
     * Detecte les ressources ou normalizationContext et denormalizationContext
     * utilisent exactement les memes groupes.
     *
     * Si read et write utilisent le meme groupe, toutes les proprietes lisibles
     * sont aussi modifiables. C'est rarement intentionnel et cree un risque IDOR.
     */
    private function checkIdenticalGroups(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        $apiResourceBlock = $this->extractApiResourceBlock($content);

        if ($apiResourceBlock === null) {
            return;
        }

        // Extrait les groupes de normalization et denormalization.
        $normGroups = $this->extractGroupNames($apiResourceBlock, 'normalizationContext');
        $denormGroups = $this->extractGroupNames($apiResourceBlock, 'denormalizationContext');

        if (empty($normGroups) || empty($denormGroups)) {
            return;
        }

        // Compare les deux listes de groupes.
        sort($normGroups);
        sort($denormGroups);

        if ($normGroups !== $denormGroups) {
            return;
        }

        $className = $this->extractClassName($content);
        $groupList = implode(', ', $normGroups);

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "Memes groupes en lecture et ecriture dans {$filename}",
            detail: "La ressource '{$className}' utilise les memes groupes de serialisation "
                . "[{$groupList}] pour normalizationContext et denormalizationContext. "
                . "Toutes les proprietes lisibles sont donc aussi modifiables par le client.",
            suggestion: "Utiliser des groupes distincts pour la lecture (ex: 'read') et l'ecriture "
                . "(ex: 'write'). Marquer les proprietes en lecture seule uniquement avec 'read'.",
            file: $relativePath,
            fixCode: "#[ApiResource(\n"
                . "    normalizationContext: ['groups' => ['read']],\n"
                . "    denormalizationContext: ['groups' => ['write']],\n"
                . ")]\n"
                . "class {$className} {\n"
                . "    #[Groups(['read'])]         // lecture seule\n"
                . "    private int \$id;\n\n"
                . "    #[Groups(['read', 'write'])] // lecture + ecriture\n"
                . "    private string \$name;\n"
                . "}",
            docUrl: 'https://api-platform.com/docs/core/serialization/',
            businessImpact: "Un client peut modifier des champs en lecture seule (id, dates systeme, "
                . "scores calcules). Cela peut corrompre l'integrite des donnees.",
            estimatedFixMinutes: 15,
        ));
    }

    /**
     * Detecte les groupes references dans le context mais absents des proprietes.
     * Un groupe orphelin signifie que la configuration est incoherente :
     * soit le groupe est mal nomme, soit les #[Groups] sur les proprietes sont manquants.
     */
    private function checkOrphanedGroups(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        $apiResourceBlock = $this->extractApiResourceBlock($content);

        if ($apiResourceBlock === null) {
            return;
        }

        // Extrait tous les groupes references dans le context.
        $contextGroups = array_unique(array_merge(
            $this->extractGroupNames($apiResourceBlock, 'normalizationContext'),
            $this->extractGroupNames($apiResourceBlock, 'denormalizationContext'),
        ));

        if (empty($contextGroups)) {
            return;
        }

        // Extrait les groupes declares sur les proprietes via #[Groups([...])].
        $propertyGroups = [];
        if (preg_match_all('/#\[Groups\s*\(\s*\[([^\]]*)\]\s*\)\s*\]/', $content, $groupMatches)) {
            foreach ($groupMatches[1] as $groupList) {
                if (preg_match_all('/[\'"](\w+)[\'"]/', $groupList, $names)) {
                    $propertyGroups = array_merge($propertyGroups, $names[1]);
                }
            }
        }

        $propertyGroups = array_unique($propertyGroups);

        foreach ($contextGroups as $group) {
            if (in_array($group, $propertyGroups, true)) {
                continue;
            }

            $className = $this->extractClassName($content);

            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::API_PLATFORM,
                analyzer: $this->getName(),
                message: "Groupe '{$group}' reference mais absent des proprietes dans {$filename}",
                detail: "Le contexte de serialisation de '{$className}' reference le groupe "
                    . "'{$group}' mais aucune propriete de la classe ne porte "
                    . "#[Groups(['{$group}'])]. L'API retournera un objet vide "
                    . "ou n'acceptera aucun champ en ecriture pour ce groupe.",
                suggestion: "Verifier le nom du groupe (faute de frappe ?) ou ajouter "
                    . "#[Groups(['{$group}'])] sur les proprietes concernees.",
                file: $relativePath,
                fixCode: "// Ajouter le groupe sur les proprietes\n"
                    . "#[Groups(['{$group}'])]\n"
                    . "private string \$name;",
                docUrl: 'https://api-platform.com/docs/core/serialization/',
                businessImpact: "L'API retourne un objet vide ou refuse toutes les donnees en ecriture. "
                    . "Les clients recoivent des reponses incoherentes sans message d'erreur.",
                estimatedFixMinutes: 10,
            ));
        }
    }

    /**
     * Extrait les noms de groupes depuis un bloc normalizationContext ou denormalizationContext.
     *
     * @return list<string>
     */
    private function extractGroupNames(string $block, string $contextKey): array
    {
        $pattern = '/' . preg_quote($contextKey, '/') . '\s*:\s*\[.*?[\'"]groups[\'"]\s*=>\s*\[([^\]]*)\]/s';

        if (!preg_match($pattern, $block, $match)) {
            return [];
        }

        $groups = [];
        if (preg_match_all('/[\'"](\w+)[\'"]/', $match[1], $names)) {
            $groups = $names[1];
        }

        return $groups;
    }

    /**
     * Extrait le bloc complet #[ApiResource(...)] en gerant les crochets imbriques.
     * La regex simple [^]]* ne fonctionne pas car le contenu contient
     * des tableaux PHP avec des crochets (ex: ['groups' => ['read']]).
     */
    private function extractApiResourceBlock(string $content): ?string
    {
        $start = strpos($content, '#[ApiResource');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($content);

        for ($i = $start; $i < $length; $i++) {
            if ($content[$i] === '[') {
                $depth++;
            } elseif ($content[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function extractClassName(string $content): string
    {
        if (preg_match('/\bclass\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }

        return 'Entity';
    }
}
