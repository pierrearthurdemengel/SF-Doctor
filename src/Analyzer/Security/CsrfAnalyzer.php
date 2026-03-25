<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Security;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Config\ParameterResolverInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use Symfony\Component\Finder\Finder;
use PierreArthur\SfDoctor\Context\ProjectContext;

/**
 * Detecte les configurations qui desactivent la protection CSRF.
 *
 * Deux niveaux d'analyse :
 * 1. Global : framework.yaml avec csrf_protection: false sous framework.form
 * 2. Fichier par fichier : FormType PHP avec 'csrf_protection' => false
 */
final class CsrfAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
        private readonly ConfigReaderInterface $configReader,
        private readonly ParameterResolverInterface $parameterResolver,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $this->checkGlobalCsrfConfig($report);
        $this->checkFormTypes($report);
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function getName(): string
    {
        return 'CSRF Analyzer';
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasSecurityBundle();
    }

    private function checkGlobalCsrfConfig(AuditReport $report): void
    {
        $framework = $this->configReader->read('config/packages/framework.yaml');

        if ($framework === null) {
            return;
        }

        $framework = $this->parameterResolver->resolveArray($framework);

        $csrfUnderForm = $framework['framework']['form']['csrf_protection'] ?? null;
        $csrfDirect = $framework['framework']['csrf_protection'] ?? null;

        if ($csrfUnderForm === false || $csrfDirect === false) {
            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'CSRF desactive globalement dans framework.yaml',
                detail: "La protection CSRF est desactivee pour tous les formulaires du projet. "
                    . "N'importe quel formulaire HTML peut etre soumis depuis un site tiers "
                    . "sans que Symfony le detecte.",
                suggestion: "Supprimer 'csrf_protection: false' de framework.yaml. "
                    . "Si le projet est une API stateless pure (sans session), "
                    . "documenter ce choix et s'assurer qu'aucun formulaire HTML n'est expose.",
                file: 'config/packages/framework.yaml',
                fixCode: "# Supprimer ou corriger dans config/packages/framework.yaml :\nframework:\n    form:\n        csrf_protection: true",
                docUrl: 'https://symfony.com/doc/current/security/csrf.html',
                businessImpact: 'Un attaquant peut forcer un utilisateur connecté à soumettre '
                    . 'un formulaire à son insu depuis un site tiers (virement, suppression de compte, '
                    . 'changement de mot de passe).',
                estimatedFixMinutes: 10,
            ));
        }
    }

    private function checkFormTypes(AuditReport $report): void
    {
        $formDir = $this->projectPath . '/src/Form';

        if (!is_dir($formDir)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->name('*.php')->in($formDir);

        if (!$finder->hasResults()) {
            return;
        }

        foreach ($finder as $file) {
            $content = $file->getContents();

            if (!$this->hasCsrfDisabled($content)) {
                continue;
            }

            $relativePath = 'src/Form/' . str_replace('\\', '/', $file->getRelativePathname());

            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: "CSRF desactive dans {$file->getFilename()}",
                detail: "Le FormType '{$file->getFilename()}' desactive la protection CSRF "
                    . "('csrf_protection' => false). Sur un formulaire HTML classique, "
                    . "cela expose l'application aux attaques Cross-Site Request Forgery.",
                suggestion: "Supprimer 'csrf_protection' => false ou le remplacer par true. "
                    . "Si ce formulaire est utilise exclusivement par une API stateless, "
                    . "documenter ce choix explicitement dans le code.",
                file: $relativePath,
                fixCode: "// Dans {$file->getFilename()}, supprimer ou corriger :\n\$resolver->setDefaults([\n    'csrf_protection' => true,\n]);",
                docUrl: 'https://symfony.com/doc/current/security/csrf.html#csrf-protection-in-symfony-forms',
                businessImpact: 'Ce formulaire est vulnérable aux attaques CSRF. '
                    . 'Un attaquant peut déclencher sa soumission depuis un site tiers '
                    . 'au nom d\'un utilisateur connecté.',
                estimatedFixMinutes: 5,
            ));
        }
    }

    private function hasCsrfDisabled(string $content): bool
    {
        return (bool) preg_match(
            '/[\'"]csrf_protection[\'"]\s*=>\s*false/',
            $content,
        );
    }
}