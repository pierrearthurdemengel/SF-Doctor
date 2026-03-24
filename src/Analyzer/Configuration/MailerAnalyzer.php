<?php

// src/Analyzer/Configuration/MailerAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Configuration;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Verifie que le transport Mailer n'est pas configuré en mode null en production.
 */
class MailerAnalyzer implements AnalyzerInterface
{
    // Transports qui absorbent les emails sans les envoyer.
    private const NULL_TRANSPORTS = [
        'null://',
        'null://null',
    ];

    public function __construct(private readonly ConfigReaderInterface $configReader)
    {
    }

    public function analyze(AuditReport $report): void
    {
        $config = $this->configReader->read('config/packages/mailer.yaml');

        if ($config === null) {
            return;
        }

        $dsn = $config['framework']['mailer']['dsn']
            ?? $config['mailer']['dsn']
            ?? null;

        if ($dsn === null) {
            // DSN absent mais mailer.yaml présent : peut être configuré via variable d'env.
            // Pas d'issue - on ne peut pas savoir sans résoudre les paramètres.
            return;
        }

        // Résout les variables d'env simples du type %env(MAILER_DSN)%.
        // Si le DSN est une variable non résolue, on ne peut pas conclure.
        if (str_starts_with($dsn, '%env(') || str_starts_with($dsn, '$')) {
            return;
        }

        $dsnLower = strtolower(trim($dsn));

        foreach (self::NULL_TRANSPORTS as $nullTransport) {
            if (str_starts_with($dsnLower, $nullTransport)) {
                $report->addIssue(new Issue(
                    severity: Severity::CRITICAL,
                    module: Module::SECURITY,
                    analyzer: $this->getName(),
                    message: 'Le transport Mailer est configuré en "null" : les emails sont silencieusement perdus',
                    detail: 'Le DSN "' . $dsn . '" absorbe tous les emails sans les envoyer et sans lever d\'erreur.',
                    suggestion: 'Configurer un vrai transport en production : MAILER_DSN=smtp://user:pass@host:port',
                    file: 'config/packages/mailer.yaml',
                    businessImpact: 'Les emails transactionnels (confirmation, mot de passe oublié, factures) ne sont jamais délivrés.',
                    fixCode: "MAILER_DSN=smtp://user:password@smtp.example.com:587",
                    docUrl: 'https://symfony.com/doc/current/mailer.html',
                    estimatedFixMinutes: 15,
                ));
                return;
            }
        }

        // Détecte aussi le DSN "smtp://null" qui est un piège courant.
        if ($dsnLower === 'smtp://null' || $dsnLower === 'smtp://localhost' && str_contains($dsn, 'null')) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'Le transport Mailer pointe vers un hôte suspect : "' . $dsn . '"',
                detail: 'Ce DSN ne correspond pas à un serveur SMTP de production standard.',
                suggestion: 'Vérifier que MAILER_DSN pointe vers un vrai serveur SMTP en production.',
                file: 'config/packages/mailer.yaml',
                businessImpact: 'Les emails risquent de ne pas être délivrés en production.',
                fixCode: "MAILER_DSN=smtp://user:password@smtp.example.com:587",
                docUrl: 'https://symfony.com/doc/current/mailer.html',
                estimatedFixMinutes: 15,
            ));
        }
    }

    public function getName(): string
    {
        return 'Mailer Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(): bool
    {
        return class_exists(\Symfony\Component\Mailer\Mailer::class);
    }
}