<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Architecture;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte l'injection du ContainerInterface dans les services.
 *
 * Injecter le container entier est un anti-pattern majeur en Symfony.
 * Les dependances doivent etre explicitement declarees dans le constructeur
 * pour que l'auto-wiring et le compilateur puissent les analyser.
 */
final class ServiceInjectionAnalyzer implements AnalyzerInterface
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
            if (!$file instanceof \SplFileInfo || $file->isDir()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());

            if ($content === false) {
                continue;
            }

            // Exclure les classes implementant ContainerAwareInterface (legacy accepte).
            if (str_contains($content, 'ContainerAwareInterface')) {
                continue;
            }

            $realSrcDir = realpath($srcDir);
            if ($realSrcDir === false) {
                continue;
            }

            $relativePath = 'src/' . ltrim(
                str_replace('\\', '/', substr($file->getRealPath(), strlen($realSrcDir))),
                '/',
            );

            $this->checkContainerInjection($report, $content, $file->getFilename(), $relativePath);
            $this->checkContainerGet($report, $content, $file->getFilename(), $relativePath);
        }
    }

    public function getModule(): Module
    {
        return Module::ARCHITECTURE;
    }

    public function getName(): string
    {
        return 'Service Injection Analyzer';
    }

    public function supports(ProjectContext $context): bool
    {
        return true;
    }

    /**
     * Detecte l'injection de ContainerInterface dans le constructeur.
     */
    private function checkContainerInjection(
        AuditReport $report,
        string $content,
        string $filename,
        string $relativePath,
    ): void {
        if (!preg_match('/ContainerInterface\s+\$container/', $content)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::ARCHITECTURE,
            analyzer: $this->getName(),
            message: "Injection du ContainerInterface dans {$filename}",
            detail: "Le fichier '{$filename}' injecte le ContainerInterface dans son constructeur. "
                . "Cela masque les dependances reelles du service et empeche le compilateur Symfony "
                . "de detecter les erreurs de configuration. C'est un anti-pattern connu "
                . "depuis Symfony 4.",
            suggestion: "Remplacer l'injection du ContainerInterface par l'injection explicite "
                . "de chaque dependance necessaire dans le constructeur.",
            file: $relativePath,
            fixCode: "// Avant (anti-pattern) :\npublic function __construct(\n    private readonly ContainerInterface \$container,\n) {}\n\n// Apres (bonne pratique) :\npublic function __construct(\n    private readonly UserRepository \$userRepository,\n    private readonly MailerInterface \$mailer,\n) {}",
            docUrl: 'https://symfony.com/doc/current/service_container.html#injecting-services-into-a-service',
            businessImpact: 'Les dependances cachees rendent le service impossible a tester unitairement '
                . 'et empechent le compilateur Symfony de valider la configuration. '
                . 'Le risque d\'erreur en production augmente significativement.',
            estimatedFixMinutes: 30,
        ));
    }

    /**
     * Detecte l'utilisation de $this->container->get() (service locator).
     */
    private function checkContainerGet(
        AuditReport $report,
        string $content,
        string $filename,
        string $relativePath,
    ): void {
        if (!str_contains($content, '$this->container->get(')) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::ARCHITECTURE,
            analyzer: $this->getName(),
            message: "Usage de \$this->container->get() dans {$filename}",
            detail: "Le fichier '{$filename}' recupere des services via \$this->container->get(). "
                . "Ce pattern (service locator) viole le principe d'inversion de dependances "
                . "et rend le code impossible a analyser statiquement.",
            suggestion: "Injecter directement le service necessaire dans le constructeur "
                . "au lieu de le recuperer depuis le container.",
            file: $relativePath,
            fixCode: "// Avant (service locator) :\n\$mailer = \$this->container->get('mailer');\n\n// Apres (injection) :\npublic function __construct(\n    private readonly MailerInterface \$mailer,\n) {}",
            docUrl: 'https://symfony.com/doc/current/service_container.html#injecting-services-into-a-service',
            businessImpact: 'Le service locator masque les dependances, rendant les tests unitaires '
                . 'impossibles sans bootstrapper tout le container Symfony. '
                . 'Les erreurs de configuration ne sont detectees qu\'a l\'execution.',
            estimatedFixMinutes: 20,
        ));
    }
}
