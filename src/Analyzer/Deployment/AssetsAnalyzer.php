<?php

// src/Analyzer/Deployment/AssetsAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Deployment;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Verifie que les assets front-end sont correctement compiles et deployes.
 *
 * Controles effectues :
 * 1. Presence et contenu du dossier public/build/
 * 2. Presence du fichier manifest.json dans public/build/
 * 3. Coherence entre package.json et node_modules/
 */
final class AssetsAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $this->checkBuildDirectory($report);
        $this->checkManifest($report);
        $this->checkNodeModules($report);
    }

    public function getName(): string
    {
        return 'Assets Analyzer';
    }

    public function getModule(): Module
    {
        return Module::DEPLOYMENT;
    }

    public function supports(ProjectContext $context): bool
    {
        $projectPath = $context->getProjectPath();

        return file_exists($projectPath . '/package.json')
            || file_exists($projectPath . '/webpack.config.js');
    }

    /**
     * Verifie que le dossier public/build/ existe et n'est pas vide.
     *
     * Un dossier build absent ou vide signifie que les assets n'ont pas ete compiles.
     * Les fichiers CSS et JS ne seront pas disponibles en production.
     */
    private function checkBuildDirectory(AuditReport $report): void
    {
        $buildDir = $this->projectPath . '/public/build';

        if (!is_dir($buildDir)) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::DEPLOYMENT,
                analyzer: $this->getName(),
                message: 'Dossier public/build/ absent',
                detail: 'Le dossier public/build/ n\'existe pas. Les assets front-end (CSS, JS) '
                    . 'ne sont pas compiles. Si le projet utilise Webpack Encore ou Vite, '
                    . 'les fichiers compiles doivent etre generes avant le deploiement.',
                suggestion: 'Executer la commande de build des assets avant le deploiement. '
                    . 'Webpack Encore : npx encore production. '
                    . 'Vite : npx vite build.',
                file: 'public/build/',
                fixCode: "# Webpack Encore :\nnpx encore production\n\n# Ou Vite :\nnpx vite build",
                docUrl: 'https://symfony.com/doc/current/frontend/encore/simple-example.html',
                businessImpact: 'Sans assets compiles, le site sera affiche sans style CSS ni JavaScript. '
                    . 'L\'experience utilisateur sera degradee ou le site sera inutilisable.',
                estimatedFixMinutes: 10,
            ));
            return;
        }

        // Verifier si le dossier est vide.
        $files = scandir($buildDir);
        if ($files === false || count($files) <= 2) {
            // scandir retourne toujours ['.', '..'] pour un dossier vide.
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::DEPLOYMENT,
                analyzer: $this->getName(),
                message: 'Dossier public/build/ vide',
                detail: 'Le dossier public/build/ existe mais ne contient aucun fichier. '
                    . 'La commande de build des assets n\'a probablement pas ete executee '
                    . 'ou a echoue silencieusement.',
                suggestion: 'Relancer la commande de build et verifier la sortie pour des erreurs. '
                    . 'Webpack Encore : npx encore production. '
                    . 'Vite : npx vite build.',
                file: 'public/build/',
                fixCode: "# Webpack Encore :\nnpx encore production\n\n# Ou Vite :\nnpx vite build",
                docUrl: 'https://symfony.com/doc/current/frontend/encore/simple-example.html',
                businessImpact: 'Le site sera affiche sans style CSS ni JavaScript. '
                    . 'Les fonctionnalites interactives seront indisponibles.',
                estimatedFixMinutes: 10,
            ));
        }
    }

    /**
     * Verifie la presence de manifest.json dans public/build/.
     *
     * Le fichier manifest.json est genere par Webpack Encore ou Vite.
     * Il mappe les noms de fichiers d'assets vers leurs versions hashees.
     * Sans ce fichier, les helpers Twig asset() et encore_entry_*() ne fonctionnent pas.
     */
    private function checkManifest(AuditReport $report): void
    {
        $buildDir = $this->projectPath . '/public/build';

        if (!is_dir($buildDir)) {
            // Deja signale par checkBuildDirectory().
            return;
        }

        // Chercher manifest.json recursivement (multi-build Encore : shop/, admin/, etc.)
        $finder = new Finder();
        $finder->files()->name('manifest.json')->in($buildDir);

        if ($finder->hasResults()) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::DEPLOYMENT,
            analyzer: $this->getName(),
            message: 'manifest.json absent dans public/build/',
            detail: 'Le fichier public/build/manifest.json est manquant. '
                . 'Ce fichier est genere par Webpack Encore (ou Vite) et contient '
                . 'le mapping entre les noms logiques des assets et leurs fichiers physiques '
                . '(avec hash de versionnement). Sans lui, les fonctions Twig '
                . 'encore_entry_link_tags() et encore_entry_script_tags() echouent.',
            suggestion: 'Recompiler les assets avec Webpack Encore ou Vite. '
                . 'S\'assurer que le fichier manifest.json est inclus dans le deploiement.',
            file: 'public/build/manifest.json',
            fixCode: "# Webpack Encore :\nnpx encore production\n\n"
                . "# Verifier que manifest.json est genere :\nls public/build/manifest.json",
            docUrl: 'https://symfony.com/doc/current/frontend/encore/versioning.html',
            businessImpact: 'Les pages Twig qui referent les assets via encore_entry_*() '
                . 'lanceront une exception 500. Le site sera indisponible pour les visiteurs.',
            estimatedFixMinutes: 10,
        ));
    }

    /**
     * Verifie la coherence entre package.json et node_modules/.
     *
     * Si package.json existe mais node_modules/ est absent,
     * les dependances front-end n'ont pas ete installees.
     */
    private function checkNodeModules(AuditReport $report): void
    {
        $packageFile = $this->projectPath . '/package.json';
        $nodeModulesDir = $this->projectPath . '/node_modules';

        if (!file_exists($packageFile)) {
            return;
        }

        if (is_dir($nodeModulesDir)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::DEPLOYMENT,
            analyzer: $this->getName(),
            message: 'package.json present sans node_modules/',
            detail: 'Le fichier package.json declare des dependances front-end, '
                . 'mais le dossier node_modules/ est absent. Cela signifie que '
                . '"npm install" ou "yarn install" n\'a pas ete execute. '
                . 'Les commandes de build des assets echoueront.',
            suggestion: 'Executer "npm install" ou "yarn install" pour installer les dependances. '
                . 'Note : node_modules/ est normalement dans .gitignore et doit etre installe '
                . 'a chaque clone ou deploiement.',
            file: 'package.json',
            fixCode: "# Installer les dependances :\nnpm install\n# Ou :\nyarn install\n\n"
                . "# Puis compiler les assets :\nnpx encore production",
            docUrl: 'https://symfony.com/doc/current/frontend/encore/installation.html',
            businessImpact: 'Sans les dependances front-end, la compilation des assets est impossible. '
                . 'Le pipeline de deploiement doit inclure npm install avant la compilation.',
            estimatedFixMinutes: 5,
        ));
    }
}
