<?php

// src/Analyzer/Messenger/UnhandledMessageAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Messenger;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les messages Messenger qui n'ont aucun handler associe.
 *
 * Scanne src/Message/ pour trouver les classes de messages,
 * puis verifie que chaque message a au moins un handler
 * (via #[AsMessageHandler] ou implements MessageHandlerInterface).
 */
final class UnhandledMessageAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $messageDir = $this->projectPath . '/src/Message';

        if (!is_dir($messageDir)) {
            return;
        }

        $messageClasses = $this->findMessageClasses($messageDir);

        if ($messageClasses === []) {
            return;
        }

        $handledMessages = $this->findHandledMessages();

        foreach ($messageClasses as $messageClass => $relativePath) {
            if (in_array($messageClass, $handledMessages, true)) {
                continue;
            }

            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::MESSENGER,
                analyzer: $this->getName(),
                message: "Message '{$messageClass}' sans handler",
                detail: "La classe de message '{$messageClass}' n'a aucun handler associe. "
                    . "Quand ce message sera dispatche, Symfony levera une exception "
                    . "NoHandlerForMessageException et le message sera perdu.",
                suggestion: "Creer un handler pour ce message dans src/MessageHandler/ "
                    . "avec l'attribut #[AsMessageHandler] ou en implementant MessageHandlerInterface.",
                file: $relativePath,
                fixCode: "// src/MessageHandler/{$messageClass}Handler.php\n"
                    . "use Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler;\n\n"
                    . "#[AsMessageHandler]\n"
                    . "final class {$messageClass}Handler\n"
                    . "{\n"
                    . "    public function __invoke({$messageClass} \$message): void\n"
                    . "    {\n"
                    . "        // Traitement du message\n"
                    . "    }\n"
                    . "}",
                docUrl: 'https://symfony.com/doc/current/messenger.html#creating-a-message-handler',
                businessImpact: 'Le message dispatche ne sera jamais traite. '
                    . 'Les actions associees (envoi d\'email, notification, traitement asynchrone) '
                    . 'ne se declencheront pas, provoquant une perte silencieuse de fonctionnalite.',
                estimatedFixMinutes: 20,
            ));
        }
    }

    public function getName(): string
    {
        return 'Unhandled Message Analyzer';
    }

    public function getModule(): Module
    {
        return Module::MESSENGER;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasMessenger();
    }

    /**
     * Trouve toutes les classes de messages dans src/Message/.
     *
     * @return array<string, string> Nom de classe => chemin relatif
     */
    private function findMessageClasses(string $messageDir): array
    {
        $classes = [];

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

            // Verifie que le fichier contient une declaration de classe.
            if (!preg_match('/\bclass\s+(\w+)/', $content, $matches)) {
                continue;
            }

            $className = $matches[1];
            $relativePath = 'src/Message/' . ltrim(
                str_replace($messageDir, '', $file->getRealPath()),
                '/\\',
            );

            $classes[$className] = $relativePath;
        }

        return $classes;
    }

    /**
     * Parcourt src/ pour trouver les messages geres par un handler.
     * Detecte les handlers via #[AsMessageHandler] ou implements MessageHandlerInterface.
     *
     * @return list<string> Noms des classes de messages gerees
     */
    private function findHandledMessages(): array
    {
        $srcDir = $this->projectPath . '/src';

        if (!is_dir($srcDir)) {
            return [];
        }

        $handledMessages = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());

            if ($content === false) {
                continue;
            }

            $isHandler = str_contains($content, '#[AsMessageHandler')
                || str_contains($content, 'implements MessageHandlerInterface');

            if (!$isHandler) {
                continue;
            }

            // Extrait le type du message depuis la signature __invoke ou le type-hint du handler.
            if (preg_match('/function\s+__invoke\s*\(\s*(\w+)\s+\$/', $content, $matches)) {
                $handledMessages[] = $matches[1];
            }

            // Detecte aussi les handlers avec methode handle().
            if (preg_match('/function\s+handle\s*\(\s*(\w+)\s+\$/', $content, $matches)) {
                $handledMessages[] = $matches[1];
            }
        }

        return $handledMessages;
    }
}
