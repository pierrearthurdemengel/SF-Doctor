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

    public function supports(): bool
    {
        return class_exists(\Symfony\Component\Form\AbstractType::class);
    }

    /**
     * Verifie si le CSRF est desactive globalement dans framework.yaml.
     *
     * Une desactivation globale est la plus dangereuse : elle s'applique
     * a tous les formulaires du projet sans exception.
     */
    private function checkGlobalCsrfConfig(AuditReport $report): void
    {
        $framework = $this->configReader->read('config/packages/framework.yaml');

        if ($framework === null) {
            return;
        }

        $framework = $this->parameterResolver->resolveArray($framework);

        // csrf_protection peut etre declare sous framework.form ou directement
        // sous framework selon la version de Symfony et la config du projet.
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
            ));
        }
    }

    /**
     * Scanne les FormType PHP pour detecter les desactivations individuelles du CSRF.
     */
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

            $relativePath = 'src/Form/' . $file->getRelativePathname();

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
            ));
        }
    }

    /**
     * Detecte la presence de 'csrf_protection' => false dans le contenu d'un fichier.
     *
     * Couvre les variantes avec guillemets simples, doubles, et espaces variables.
     */
    private function hasCsrfDisabled(string $content): bool
    {
        return (bool) preg_match(
            '/[\'"]csrf_protection[\'"]\s*=>\s*false/',
            $content,
        );
    }
}