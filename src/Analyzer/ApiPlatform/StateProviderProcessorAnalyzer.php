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
 * Detecte les operations API Platform avec processor: ou provider: referancant
 * des classes qui n'existent pas dans le projet.
 *
 * Une reference invalide provoque une erreur 500 au runtime sans aucun
 * message explicite, car le container Symfony echoue a resoudre le service.
 * Ce type d'erreur est invisible en CI si les tests d'integration sont absents.
 */
final class StateProviderProcessorAnalyzer implements AnalyzerInterface
{
    /** @var array<string, string> */
    private const CUSTOM_KEYS = [
        'processor' => 'StateProcessorInterface',
        'provider' => 'StateProviderInterface',
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

                if ($content === false || !str_contains($content, '#[ApiResource')) {
                    continue;
                }

                $realPath = str_replace('\\', '/', $file->getRealPath());
                $normalizedDir = str_replace('\\', '/', $dir);
                $relativePath = $relativePrefix . ltrim(
                    str_replace($normalizedDir, '', $realPath),
                    '/',
                );

                $this->checkCustomProviderProcessor($report, $content, $relativePath, $file->getFilename());
                $this->checkProcessorOnReadOnlyResource($report, $content, $relativePath, $file->getFilename());
            }
        }
    }

    public function getName(): string
    {
        return 'State Provider/Processor Analyzer';
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
     * Detecte les references a processor:/provider: avec une classe ::class
     * qui n'existe pas dans src/.
     */
    private function checkCustomProviderProcessor(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        foreach (self::CUSTOM_KEYS as $key => $interface) {
            // Capture les references processor: ClasseName::class dans les attributs.
            $pattern = '/' . $key . '\s*:\s*(\w+)::class/';

            if (!preg_match_all($pattern, $content, $matches)) {
                continue;
            }

            foreach ($matches[1] as $className) {
                // Resout le FQCN via les use statements du fichier.
                $fqcn = $this->resolveClassName($content, $className);

                if ($fqcn === null) {
                    continue;
                }

                // Verifie si la classe existe dans le projet.
                $classFile = $this->fqcnToFilePath($fqcn);

                if ($classFile !== null && file_exists($classFile)) {
                    continue;
                }

                $report->addIssue(new Issue(
                    severity: Severity::CRITICAL,
                    module: Module::API_PLATFORM,
                    analyzer: $this->getName(),
                    message: "{$key}: {$className}::class introuvable dans {$filename}",
                    detail: "L'operation reference {$key}: {$className}::class mais cette classe "
                        . "n'existe pas dans le projet. L'API retournera une erreur 500 "
                        . "au runtime car le container Symfony ne pourra pas resoudre le service.",
                    suggestion: "Creer la classe {$className} implementant {$interface} "
                        . "ou corriger la reference.",
                    file: $relativePath,
                    fixCode: "use ApiPlatform\\State\\{$interface};\n\n"
                        . "final class {$className} implements {$interface}\n"
                        . "{\n"
                        . "    public function " . ($key === 'processor' ? 'process' : 'provide') . "(...): mixed\n"
                        . "    {\n"
                        . "        // Implementation\n"
                        . "    }\n"
                        . "}",
                    docUrl: 'https://api-platform.com/docs/core/state-' . $key . 's/',
                    businessImpact: "Erreur 500 systematique sur cet endpoint en production. "
                        . "L'erreur n'est visible qu'au runtime, pas a la compilation.",
                    estimatedFixMinutes: 30,
                ));
            }
        }
    }

    /**
     * Detecte les ressources en lecture seule (uniquement Get/GetCollection)
     * qui declarent un processor: inutile.
     */
    private function checkProcessorOnReadOnlyResource(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Verifie si un processor est declare au niveau ressource.
        if (!preg_match('/#\[ApiResource\b[^]]*\bprocessor\s*:/', $content)) {
            return;
        }

        // Verifie qu'il n'y a aucune operation d'ecriture.
        $hasWriteOp = str_contains($content, '#[Post')
            || str_contains($content, '#[Put')
            || str_contains($content, '#[Patch')
            || str_contains($content, '#[Delete');

        if ($hasWriteOp) {
            return;
        }

        // Verifie que les operations sont explicitement limitees a la lecture.
        if (!preg_match('/operations\s*:\s*\[/', $content)) {
            return;
        }

        $className = $this->extractClassName($content);

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "processor: declare sur une ressource en lecture seule dans {$filename}",
            detail: "La ressource '{$className}' declare un processor: au niveau #[ApiResource] "
                . "mais n'a aucune operation d'ecriture (Post, Put, Patch, Delete). "
                . "Le processor ne sera jamais appele, c'est du code mort.",
            suggestion: "Retirer le processor: de #[ApiResource] ou ajouter les operations "
                . "d'ecriture manquantes.",
            file: $relativePath,
            fixCode: "#[ApiResource(\n"
                . "    operations: [new Get(), new GetCollection()],\n"
                . "    // Retirer processor: car aucune operation d'ecriture\n"
                . ")]",
            docUrl: 'https://api-platform.com/docs/core/state-processors/',
            businessImpact: "Code mort qui complique la maintenance. Le processor est instancie "
                . "par le container mais jamais utilise.",
            estimatedFixMinutes: 5,
        ));
    }

    /**
     * Resout un nom de classe court via les use statements du fichier.
     */
    private function resolveClassName(string $content, string $shortName): ?string
    {
        $pattern = '/^use\s+([\w\\\\]+\\\\' . preg_quote($shortName, '/') . ')\s*;/m';

        if (preg_match($pattern, $content, $match)) {
            return $match[1];
        }

        // Si pas de use, c'est peut-etre dans le meme namespace.
        if (preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $content, $nsMatch)) {
            return $nsMatch[1] . '\\' . $shortName;
        }

        return null;
    }

    /**
     * Convertit un FQCN en chemin fichier relatif au projet.
     */
    private function fqcnToFilePath(string $fqcn): ?string
    {
        // Convention PSR-4 : App\ -> src/
        $parts = explode('\\', $fqcn);

        if (count($parts) < 2) {
            return null;
        }

        // Retire le namespace racine (App, PierreArthur, etc.)
        array_shift($parts);

        return $this->projectPath . '/src/' . implode('/', $parts) . '.php';
    }

    private function extractClassName(string $content): string
    {
        if (preg_match('/\bclass\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }

        return 'Resource';
    }
}
