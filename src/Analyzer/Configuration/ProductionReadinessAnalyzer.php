<?php

// src/Analyzer/Configuration/ProductionReadinessAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Configuration;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Verifie que le projet est pret pour un deploiement en production.
 *
 * Controles effectues :
 * 1. Presence de composer.lock (build reproductible)
 * 2. Presence de config/preload.php (preloading OPcache)
 *
 * Note : les controles APP_ENV et APP_DEBUG sont deja couverts par DebugModeAnalyzer.
 */
final class ProductionReadinessAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $this->checkComposerLock($report);
        $this->checkPreloadConfig($report);
    }

    public function getName(): string
    {
        return 'Production Readiness Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(ProjectContext $context): bool
    {
        return file_exists($context->getProjectPath() . '/composer.json');
    }

    /**
     * Verifie la presence de composer.lock.
     * Sans ce fichier, composer install installe les dernieres versions compatibles,
     * ce qui peut introduire des regressions entre deux deploiements.
     */
    private function checkComposerLock(AuditReport $report): void
    {
        $lockFile = $this->projectPath . '/composer.lock';

        if (file_exists($lockFile)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: 'composer.lock absent ou non commite',
            detail: 'Sans composer.lock, chaque composer install peut installer des versions '
                . 'differentes des dependances. Le build n\'est pas reproductible : '
                . 'un deploiement peut fonctionner en staging et echouer en production.',
            suggestion: 'Executer composer install pour generer composer.lock, '
                . 'puis le commiter dans le depot Git.',
            file: 'composer.lock',
            fixCode: "composer install\ngit add composer.lock\ngit commit -m 'Add composer.lock for reproducible builds'",
            docUrl: 'https://getcomposer.org/doc/01-basic-usage.md#commit-your-composer-lock-file-to-version-control',
            businessImpact: 'Un deploiement peut installer une version differente d\'une dependance, '
                . 'introduisant des bugs ou des failles de securite non testees en staging.',
            estimatedFixMinutes: 5,
        ));
    }

    /**
     * Verifie la presence de config/preload.php.
     * Le preloading OPcache charge les classes en memoire au demarrage de PHP,
     * evitant la compilation a chaque requete.
     */
    private function checkPreloadConfig(AuditReport $report): void
    {
        $preloadFile = $this->projectPath . '/config/preload.php';

        if (file_exists($preloadFile)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: 'config/preload.php absent - preloading OPcache desactive',
            detail: 'Le fichier config/preload.php permet a PHP de precharger les classes '
                . 'du framework en memoire au demarrage. Sans preloading, chaque requete '
                . 'doit recompiler ou relire le cache OPcache pour ces classes.',
            suggestion: 'Creer config/preload.php et configurer opcache.preload dans php.ini. '
                . 'Symfony le genere automatiquement avec le recipe flex.',
            file: 'config/preload.php',
            fixCode: "<?php\n// config/preload.php\nif (file_exists(dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php')) {\n    require dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php';\n}",
            docUrl: 'https://symfony.com/doc/current/performance.html#use-the-opcache-class-preloading',
            businessImpact: 'Sans preloading, le temps de reponse est legerement plus eleve sur chaque requete. '
                . 'Sur un projet a fort trafic, le gain du preloading peut atteindre 10 a 15%.',
            estimatedFixMinutes: 15,
        ));
    }
}
