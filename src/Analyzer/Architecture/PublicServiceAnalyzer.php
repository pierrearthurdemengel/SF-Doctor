<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Architecture;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les services declares comme publics dans config/services.yaml.
 *
 * Depuis Symfony 4, les services sont prives par defaut. Un service public
 * est accessible via $container->get(), ce qui casse l'encapsulation
 * et empeche les optimisations du compilateur (suppression des services inutilises).
 * Les commandes et controllers sont des exceptions acceptables.
 */
final class PublicServiceAnalyzer implements AnalyzerInterface
{
    // Patterns de noms de classes exclus de la detection (usage public legitime).
    // Payum/Gateway : les actions de paiement sont resolues via $container->get() par le ServiceRegistry.
    private const EXCLUDED_PATTERNS = [
        'Command',
        'Controller',
        'Payum',
        'Gateway',
    ];

    public function __construct(
        private readonly ConfigReaderInterface $configReader,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $config = $this->configReader->read('config/services.yaml');

        if ($config === null) {
            return;
        }

        $services = $config['services'] ?? [];

        foreach ($services as $serviceId => $serviceConfig) {
            if (!is_array($serviceConfig)) {
                continue;
            }

            // Verifier si le service est declare public.
            if (!isset($serviceConfig['public']) || $serviceConfig['public'] !== true) {
                continue;
            }

            // Exclure les commandes et controllers (usage public legitime).
            if ($this->isExcluded((string) $serviceId)) {
                continue;
            }

            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::ARCHITECTURE,
                analyzer: $this->getName(),
                message: "Service public detecte : {$serviceId}",
                detail: "Le service '{$serviceId}' est declare avec 'public: true' dans services.yaml. "
                    . "Un service public est accessible via \$container->get(), ce qui casse "
                    . "l'encapsulation du container et empeche la suppression des services inutilises.",
                suggestion: "Retirer 'public: true' et injecter le service via le constructeur "
                    . "des classes qui en ont besoin. Symfony auto-wire les dependances.",
                file: 'config/services.yaml',
                fixCode: "# Avant :\nservices:\n    App\\Service\\MyService:\n        public: true\n\n# Apres :\nservices:\n    App\\Service\\MyService:\n        # pas de 'public: true' - le service est prive par defaut\n        # Symfony l'injecte automatiquement la ou il est type-hinte.",
                docUrl: 'https://symfony.com/doc/current/service_container.html#public-versus-private-services',
                businessImpact: 'Les services publics empechent le compilateur Symfony d\'optimiser '
                    . 'le container. Ils encouragent l\'usage du service locator '
                    . 'et rendent le code plus difficile a refactoriser.',
                estimatedFixMinutes: 10,
            ));
        }
    }

    public function getModule(): Module
    {
        return Module::ARCHITECTURE;
    }

    public function getName(): string
    {
        return 'Public Service Analyzer';
    }

    public function supports(ProjectContext $context): bool
    {
        return true;
    }

    /**
     * Verifie si un identifiant de service correspond a un pattern exclu.
     * Les commandes et controllers sont legitimement publics.
     */
    private function isExcluded(string $serviceId): bool
    {
        foreach (self::EXCLUDED_PATTERNS as $pattern) {
            if (str_contains($serviceId, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
