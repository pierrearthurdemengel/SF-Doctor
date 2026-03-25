<?php

// src/Analyzer/Messenger/MessengerTransportAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Messenger;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Analyse la configuration des transports Messenger.
 *
 * Verifie les points critiques :
 * 1. Messages routes vers le transport 'sync' (defait le but de l'asynchrone)
 * 2. Absence de transport 'failed' (les messages en echec sont perdus)
 * 3. Absence de retry_strategy sur les transports asynchrones
 */
final class MessengerTransportAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly ConfigReaderInterface $configReader,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $config = $this->configReader->read('config/packages/messenger.yaml');

        if ($config === null) {
            return;
        }

        $messengerConfig = $config['framework']['messenger'] ?? $config['messenger'] ?? null;

        if ($messengerConfig === null) {
            return;
        }

        $this->checkSyncRouting($report, $messengerConfig);
        $this->checkFailedTransport($report, $messengerConfig);
        $this->checkRetryStrategy($report, $messengerConfig);
    }

    public function getName(): string
    {
        return 'Messenger Transport Analyzer';
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
     * Detecte les messages routes vers le transport 'sync'.
     * Le transport synchrone execute le handler immediatement dans la meme requete HTTP,
     * ce qui defait completement l'interet du composant Messenger.
     *
     * @param array<mixed> $messengerConfig
     */
    private function checkSyncRouting(AuditReport $report, array $messengerConfig): void
    {
        $routing = $messengerConfig['routing'] ?? [];

        if (!is_array($routing)) {
            return;
        }

        foreach ($routing as $messageClass => $transport) {
            $transportName = is_array($transport) ? ($transport['senders'][0] ?? $transport[0] ?? null) : $transport;

            if ($transportName === 'sync') {
                $report->addIssue(new Issue(
                    severity: Severity::WARNING,
                    module: Module::MESSENGER,
                    analyzer: $this->getName(),
                    message: "Message '{$messageClass}' route vers le transport 'sync'",
                    detail: "Le message '{$messageClass}' est configure pour etre traite de maniere synchrone. "
                        . "Le handler s'execute dans la meme requete HTTP, bloquant la reponse. "
                        . "Cela defait l'interet d'utiliser Messenger pour le traitement asynchrone.",
                    suggestion: "Router ce message vers un transport asynchrone (async, doctrine, amqp, redis) "
                        . "pour liberer la requete HTTP et traiter le message en arriere-plan.",
                    file: 'config/packages/messenger.yaml',
                    fixCode: "# config/packages/messenger.yaml\nframework:\n    messenger:\n        routing:\n"
                        . "            '{$messageClass}': async",
                    docUrl: 'https://symfony.com/doc/current/messenger.html#transports-async-queued-messages',
                    businessImpact: 'Les messages synchrones bloquent la requete HTTP. '
                        . 'Le temps de reponse augmente pour l\'utilisateur '
                        . 'et les traitements lourds ralentissent l\'application entiere.',
                    estimatedFixMinutes: 10,
                ));
            }
        }
    }

    /**
     * Verifie qu'un transport 'failed' est configure.
     * Sans transport d'echec, les messages qui echouent apres toutes les tentatives
     * de retry sont definitivement perdus.
     *
     * @param array<mixed> $messengerConfig
     */
    private function checkFailedTransport(AuditReport $report, array $messengerConfig): void
    {
        $failureTransport = $messengerConfig['failure_transport'] ?? null;

        if ($failureTransport !== null) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::MESSENGER,
            analyzer: $this->getName(),
            message: "Aucun transport 'failed' configure pour Messenger",
            detail: "La configuration Messenger ne definit pas de 'failure_transport'. "
                . "Les messages qui echouent apres toutes les tentatives de retry "
                . "sont definitivement perdus, sans possibilite de les reinjecter.",
            suggestion: "Configurer un failure_transport pour stocker les messages en echec "
                . "et pouvoir les reinjecter avec messenger:failed:retry.",
            file: 'config/packages/messenger.yaml',
            fixCode: "# config/packages/messenger.yaml\nframework:\n    messenger:\n"
                . "        failure_transport: failed\n"
                . "        transports:\n"
                . "            failed: 'doctrine://default?queue_name=failed'",
            docUrl: 'https://symfony.com/doc/current/messenger.html#saving-retrying-failed-messages',
            businessImpact: 'Les messages en echec sont definitivement perdus. '
                . 'Les actions critiques (envoi d\'email, traitement de paiement, synchronisation) '
                . 'ne peuvent pas etre reessayees manuellement.',
            estimatedFixMinutes: 15,
        ));
    }

    /**
     * Verifie que les transports asynchrones ont une retry_strategy configuree.
     * Sans retry, un message qui echoue une fois est immediatement perdu
     * ou envoye au failure_transport sans nouvelle tentative.
     *
     * @param array<mixed> $messengerConfig
     */
    private function checkRetryStrategy(AuditReport $report, array $messengerConfig): void
    {
        $transports = $messengerConfig['transports'] ?? [];

        if (!is_array($transports)) {
            return;
        }

        // Transports qui ne necessitent pas de retry_strategy.
        $excludedTransports = ['sync', 'failed'];

        foreach ($transports as $transportName => $transportConfig) {
            if (in_array($transportName, $excludedTransports, true)) {
                continue;
            }

            // Le transport peut etre une simple string (DSN) ou un tableau de config.
            if (is_string($transportConfig)) {
                // Un DSN simple sans retry_strategy configuree.
                $report->addIssue(new Issue(
                    severity: Severity::SUGGESTION,
                    module: Module::MESSENGER,
                    analyzer: $this->getName(),
                    message: "Pas de retry_strategy configuree pour le transport '{$transportName}'",
                    detail: "Le transport '{$transportName}' n'a pas de retry_strategy explicite. "
                        . "Symfony utilise les valeurs par defaut (3 tentatives, delai de 1s). "
                        . "Pour un traitement en production, il est recommande de configurer "
                        . "une strategie de retry adaptee au contexte metier.",
                    suggestion: "Ajouter une retry_strategy avec un nombre de tentatives "
                        . "et un delai adaptes au type de message traite par ce transport.",
                    file: 'config/packages/messenger.yaml',
                    fixCode: "# config/packages/messenger.yaml\nframework:\n    messenger:\n"
                        . "        transports:\n"
                        . "            {$transportName}:\n"
                        . "                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'\n"
                        . "                retry_strategy:\n"
                        . "                    max_retries: 3\n"
                        . "                    delay: 1000\n"
                        . "                    multiplier: 2",
                    docUrl: 'https://symfony.com/doc/current/messenger.html#retries-failures',
                    businessImpact: 'Sans strategie de retry explicite, les messages en echec temporaire '
                        . '(timeout reseau, base de donnees indisponible) ne seront retentes '
                        . 'qu\'avec les parametres par defaut, potentiellement inadaptes.',
                    estimatedFixMinutes: 10,
                ));
                continue;
            }

            if (!is_array($transportConfig)) {
                continue;
            }

            if (!isset($transportConfig['retry_strategy'])) {
                $report->addIssue(new Issue(
                    severity: Severity::SUGGESTION,
                    module: Module::MESSENGER,
                    analyzer: $this->getName(),
                    message: "Pas de retry_strategy configuree pour le transport '{$transportName}'",
                    detail: "Le transport '{$transportName}' est configure en detail "
                        . "mais n'a pas de retry_strategy explicite. "
                        . "Symfony utilise les valeurs par defaut (3 tentatives, delai de 1s).",
                    suggestion: "Ajouter une retry_strategy avec un nombre de tentatives "
                        . "et un delai adaptes au type de message traite par ce transport.",
                    file: 'config/packages/messenger.yaml',
                    fixCode: "# config/packages/messenger.yaml\nframework:\n    messenger:\n"
                        . "        transports:\n"
                        . "            {$transportName}:\n"
                        . "                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'\n"
                        . "                retry_strategy:\n"
                        . "                    max_retries: 3\n"
                        . "                    delay: 1000\n"
                        . "                    multiplier: 2",
                    docUrl: 'https://symfony.com/doc/current/messenger.html#retries-failures',
                    businessImpact: 'Sans strategie de retry explicite, les messages en echec temporaire '
                        . '(timeout reseau, base de donnees indisponible) ne seront retentes '
                        . 'qu\'avec les parametres par defaut, potentiellement inadaptes.',
                    estimatedFixMinutes: 10,
                ));
            }
        }
    }
}
