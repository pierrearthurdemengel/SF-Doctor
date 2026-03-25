<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Messenger;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les messages Messenger contenant des proprietes non serialisables.
 *
 * Un message avec une propriete Closure ou resource ne peut pas etre serialise
 * pour un transport asynchrone (AMQP, Redis, Doctrine).
 */
final class UnserializableMessageAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {
    }

    public function analyze(AuditReport $report): void
    {
        $messageDir = $this->projectPath . '/src/Message';

        if (!is_dir($messageDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($messageDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                continue;
            }

            $relativePath = 'src/Message/' . $file->getFilename();

            if (!preg_match('/\bclass\s+(\w+)/', $content, $classMatch)) {
                continue;
            }

            $className = $classMatch[1];

            $this->checkClosureProperties($report, $content, $className, $relativePath);
            $this->checkResourceProperties($report, $content, $className, $relativePath);
            $this->checkNoPublicPropertiesOrGetters($report, $content, $className, $relativePath);
        }
    }

    public function getName(): string
    {
        return 'Unserializable Message Analyzer';
    }

    public function getModule(): Module
    {
        return Module::MESSENGER;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasMessenger();
    }

    private function checkClosureProperties(AuditReport $report, string $content, string $className, string $file): void
    {
        if (preg_match('/\\\\Closure\s+\$|Closure\s+\$/', $content)) {
            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::MESSENGER,
                analyzer: $this->getName(),
                message: "Message '{$className}' contient une propriete Closure",
                detail: "La classe de message '{$className}' contient une propriete de type Closure. "
                    . "Les closures ne sont pas serialisables et provoqueront une erreur "
                    . "lors de l'envoi sur un transport asynchrone.",
                suggestion: "Remplacer la Closure par une valeur serialisable (string, int, array) "
                    . "et reconstruire le callable dans le handler.",
                file: $file,
                businessImpact: "Le message echouera systematiquement a la serialisation "
                    . "sur tout transport asynchrone, bloquant le traitement.",
                fixCode: "// Remplacer :\nprivate \\Closure \$callback;\n\n// Par :\nprivate string \$callbackName;",
                docUrl: 'https://symfony.com/doc/current/messenger.html#serializing-messages',
                estimatedFixMinutes: 15,
            ));
        }
    }

    private function checkResourceProperties(AuditReport $report, string $content, string $className, string $file): void
    {
        // Detect type-hinted resource properties or @var resource annotations
        if (preg_match('/\$\w+\s*;\s*\/\/\s*resource|\@var\s+resource/', $content)) {
            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::MESSENGER,
                analyzer: $this->getName(),
                message: "Message '{$className}' contient une propriete resource",
                detail: "La classe de message '{$className}' contient une propriete de type resource. "
                    . "Les resources PHP ne sont pas serialisables.",
                suggestion: "Stocker le chemin du fichier ou l'URL au lieu de la resource elle-meme.",
                file: $file,
                businessImpact: "Le message echouera a la serialisation sur tout transport asynchrone.",
                fixCode: "// Remplacer la resource par un chemin de fichier :\nprivate string \$filePath;",
                docUrl: 'https://symfony.com/doc/current/messenger.html#serializing-messages',
                estimatedFixMinutes: 20,
            ));
        }
    }

    private function checkNoPublicPropertiesOrGetters(AuditReport $report, string $content, string $className, string $file): void
    {
        $hasPublicProperty = (bool) preg_match('/public\s+(?:readonly\s+)?(?:string|int|float|bool|array|\??\w+)\s+\$/', $content);
        $hasGetter = (bool) preg_match('/public\s+function\s+get\w+\s*\(/', $content);
        $hasPromotedProperty = (bool) preg_match('/public\s+function\s+__construct\s*\([^)]*public\s+/', $content, $m, 0);

        // Also check constructor promoted properties
        if (!$hasPromotedProperty && preg_match('/__construct\s*\(\s*(?:public|private|protected)\s+readonly/', $content)) {
            // Has promoted properties - check if any are public
            $hasPromotedProperty = (bool) preg_match('/__construct\s*\([^)]*\bpublic\s+/', $content);
        }

        if ($hasPublicProperty || $hasGetter || $hasPromotedProperty) {
            return;
        }

        // Check if class has any properties at all
        $hasAnyProperty = (bool) preg_match('/(?:private|protected)\s+(?:readonly\s+)?(?:string|int|float|bool|array|\??\w+)\s+\$/', $content);

        if (!$hasAnyProperty) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::MESSENGER,
            analyzer: $this->getName(),
            message: "Message '{$className}' sans proprietes publiques ni getters",
            detail: "La classe de message '{$className}' contient des proprietes privees/protegees "
                . "mais aucune propriete publique ni getter. Le serializer ne pourra pas "
                . "deserialiser correctement ce message.",
            suggestion: "Ajouter des getters publics ou utiliser des proprietes publiques (readonly recommande) "
                . "pour permettre la deserialisation.",
            file: $file,
            businessImpact: "Le message ne pourra pas etre deserialise correctement par le consumer, "
                . "provoquant des echecs de traitement.",
            fixCode: "// Utiliser des proprietes publiques readonly :\npublic function __construct(\n    public readonly string \$data,\n) {}",
            docUrl: 'https://symfony.com/doc/current/messenger.html#serializing-messages',
            estimatedFixMinutes: 10,
        ));
    }
}
