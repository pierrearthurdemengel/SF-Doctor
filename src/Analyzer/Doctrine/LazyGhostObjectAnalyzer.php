<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Doctrine;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les configurations risquees de lazy ghost objects dans Doctrine ORM.
 *
 * Source : issue Doctrine ORM #11087 - enable_lazy_ghost_objects degrade les perfs
 * de 4x sur certaines configurations (Doctrine ORM 2.17+).
 */
final class LazyGhostObjectAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly ConfigReaderInterface $configReader,
        private readonly string $projectPath,
    ) {
    }

    public function analyze(AuditReport $report): void
    {
        $config = $this->configReader->read('config/packages/doctrine.yaml');

        if ($config === null) {
            return;
        }

        $lazyGhost = $config['doctrine']['orm']['enable_lazy_ghost_objects']
            ?? $config['doctrine']['orm']['lazy_ghost_objects']
            ?? null;

        if ($lazyGhost !== true) {
            return;
        }

        $doctrineVersion = $this->getDoctrineOrmVersion();

        // Check 1 : Doctrine ORM 2.15-2.17 avec lazy_ghost actif (WARNING)
        if ($doctrineVersion !== null && $this->isVersionBetween($doctrineVersion, '2.15.0', '3.0.0')) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::DOCTRINE,
                analyzer: $this->getName(),
                message: 'enable_lazy_ghost_objects actif sur Doctrine ORM ' . $doctrineVersion,
                detail: 'Les lazy ghost objects sur Doctrine ORM 2.15-2.17 peuvent degrader les performances de 4x sur certaines configurations (issue Doctrine ORM #11087). Ce mode est stable uniquement a partir de Doctrine ORM 3.0.',
                suggestion: 'Desactiver enable_lazy_ghost_objects ou migrer vers Doctrine ORM 3.x pour beneficier de cette fonctionnalite sans risque de regression de performance.',
                file: 'config/packages/doctrine.yaml',
                businessImpact: 'Degradation de performance pouvant atteindre 4x sur les requetes impliquant des proxies. Risque de timeout sur les pages a fort trafic.',
                fixCode: "# config/packages/doctrine.yaml\ndoctrine:\n    orm:\n        enable_lazy_ghost_objects: false",
                docUrl: 'https://github.com/doctrine/orm/issues/11087',
                estimatedFixMinutes: 5,
            ));

            return;
        }

        // Check 2 : lazy_ghost actif sans version Doctrine connue (SUGGESTION)
        if ($doctrineVersion === null) {
            $report->addIssue(new Issue(
                severity: Severity::SUGGESTION,
                module: Module::DOCTRINE,
                analyzer: $this->getName(),
                message: 'enable_lazy_ghost_objects actif sans version Doctrine verifiable',
                detail: 'Les lazy ghost objects sont actives mais la version de Doctrine ORM ne peut pas etre determinee (composer.lock absent ou illisible). Verifiez que votre version de Doctrine ORM est >= 3.0 pour eviter les regressions de performance.',
                suggestion: 'Verifier la version de Doctrine ORM avec `composer show doctrine/orm` et desactiver cette option si ORM < 3.0.',
                file: 'config/packages/doctrine.yaml',
                businessImpact: 'Risque de degradation de performance si la version de Doctrine ORM est < 3.0.',
                fixCode: "composer show doctrine/orm | grep version",
                docUrl: 'https://github.com/doctrine/orm/issues/11087',
                estimatedFixMinutes: 5,
            ));
        }
    }

    public function getName(): string
    {
        return 'Lazy Ghost Object Analyzer';
    }

    public function getModule(): Module
    {
        return Module::DOCTRINE;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasDoctrineOrm();
    }

    /**
     * Lit la version de doctrine/orm depuis composer.lock.
     */
    private function getDoctrineOrmVersion(): ?string
    {
        $lockFile = $this->projectPath . '/composer.lock';

        if (!file_exists($lockFile)) {
            return null;
        }

        $content = file_get_contents($lockFile);
        if ($content === false) {
            return null;
        }

        $lock = json_decode($content, true);
        if (!is_array($lock)) {
            return null;
        }

        $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);

        foreach ($packages as $package) {
            if (($package['name'] ?? '') === 'doctrine/orm') {
                return ltrim($package['version'] ?? '', 'v');
            }
        }

        return null;
    }

    private function isVersionBetween(string $version, string $min, string $max): bool
    {
        return version_compare($version, $min, '>=') && version_compare($version, $max, '<');
    }
}
