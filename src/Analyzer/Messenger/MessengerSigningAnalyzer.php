<?php

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
 * Verifie que la signature des messages Messenger est activee.
 *
 * Source : blog Symfony jan. 2026 - Symfony 7.4 ajoute la signature des messages.
 * Sans signature, un attaquant qui injecte un payload forge dans la file peut
 * declencher RunProcessHandler ou RunCommandHandler.
 */
final class MessengerSigningAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly ConfigReaderInterface $configReader,
        private readonly string $projectPath,
    ) {
    }

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

        $symfonyVersion = $this->getSymfonyVersion();
        $hasDangerousHandlers = $this->hasDangerousHandlers();
        $transports = $messengerConfig['transports'] ?? [];

        if (!is_array($transports)) {
            return;
        }

        // Check 1 : Symfony >= 7.4 avec handlers dangereux sans signing
        if ($symfonyVersion !== null && version_compare($symfonyVersion, '7.4.0', '>=') && $hasDangerousHandlers) {
            $hasUnsignedTransport = false;

            foreach ($transports as $transportName => $transportConfig) {
                if ($transportName === 'failed' || $transportName === 'sync') {
                    continue;
                }

                $isSigned = false;
                if (is_array($transportConfig)) {
                    $isSigned = ($transportConfig['sign'] ?? false) === true
                        || isset($transportConfig['serializer']['sign']);
                }

                if (!$isSigned) {
                    $hasUnsignedTransport = true;

                    $report->addIssue(new Issue(
                        severity: Severity::CRITICAL,
                        module: Module::MESSENGER,
                        analyzer: $this->getName(),
                        message: "Transport '{$transportName}' sans signature avec handlers dangereux",
                        detail: "Le transport '{$transportName}' n'a pas de signature activee (sign: true) "
                            . "alors que des handlers sensibles (RunProcessHandler, RunCommandHandler) sont presents. "
                            . "Un attaquant qui injecte un payload forge dans la file peut executer des commandes arbitraires.",
                        suggestion: "Activer la signature des messages sur ce transport avec sign: true.",
                        file: 'config/packages/messenger.yaml',
                        fixCode: "# config/packages/messenger.yaml\nframework:\n    messenger:\n"
                            . "        transports:\n"
                            . "            {$transportName}:\n"
                            . "                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'\n"
                            . "                sign: true",
                        docUrl: 'https://symfony.com/doc/current/messenger.html#signing-messages',
                        businessImpact: "Risque d'execution de commandes arbitraires via injection de messages forges. "
                            . "Un attaquant avec acces a la file de messages peut prendre le controle du serveur.",
                        estimatedFixMinutes: 10,
                    ));
                }
            }

            return;
        }

        // Check 2 : Symfony >= 7.4 avec transports AMQP/Redis sans signing
        if ($symfonyVersion !== null && version_compare($symfonyVersion, '7.4.0', '>=')) {
            foreach ($transports as $transportName => $transportConfig) {
                if ($transportName === 'failed' || $transportName === 'sync') {
                    continue;
                }

                $dsn = is_string($transportConfig) ? $transportConfig : ($transportConfig['dsn'] ?? '');
                $isNetworkTransport = str_contains($dsn, 'amqp://') || str_contains($dsn, 'redis://');

                $isSigned = false;
                if (is_array($transportConfig)) {
                    $isSigned = ($transportConfig['sign'] ?? false) === true;
                }

                if ($isNetworkTransport && !$isSigned) {
                    $report->addIssue(new Issue(
                        severity: Severity::WARNING,
                        module: Module::MESSENGER,
                        analyzer: $this->getName(),
                        message: "Transport reseau '{$transportName}' sans signature",
                        detail: "Le transport '{$transportName}' utilise un DSN reseau (AMQP/Redis) "
                            . "sans signature activee. Les messages transitant sur le reseau "
                            . "peuvent etre falsifies par un attaquant.",
                        suggestion: "Activer la signature des messages avec sign: true.",
                        file: 'config/packages/messenger.yaml',
                        fixCode: "# Ajouter sign: true au transport\n{$transportName}:\n    dsn: '{$dsn}'\n    sign: true",
                        docUrl: 'https://symfony.com/doc/current/messenger.html#signing-messages',
                        businessImpact: "Les messages non signes sur un transport reseau peuvent etre falsifies, "
                            . "permettant l'injection de payloads malveillants.",
                        estimatedFixMinutes: 5,
                    ));
                }
            }

            return;
        }

        // Check 3 : Symfony < 7.4 avec handlers dangereux
        if ($hasDangerousHandlers) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::MESSENGER,
                analyzer: $this->getName(),
                message: 'Handlers sensibles detectes sans signature de messages disponible',
                detail: "Des handlers sensibles (RunProcessHandler, RunCommandHandler) sont utilises "
                    . "mais la version de Symfony ne supporte pas la signature de messages (< 7.4). "
                    . "Un attaquant avec acces a la file peut injecter des payloads dangereux.",
                suggestion: "Migrer vers Symfony 7.4+ pour beneficier de la signature de messages, "
                    . "ou mettre en place une verification manuelle des messages dans les handlers.",
                file: 'config/packages/messenger.yaml',
                businessImpact: "Les handlers RunProcessHandler et RunCommandHandler executent "
                    . "des commandes systeme. Sans signature, un message forge peut executer "
                    . "du code arbitraire.",
                fixCode: "composer require symfony/messenger:^7.4",
                docUrl: 'https://symfony.com/doc/current/messenger.html#signing-messages',
                estimatedFixMinutes: 60,
            ));
        }
    }

    public function getName(): string
    {
        return 'Messenger Signing Analyzer';
    }

    public function getModule(): Module
    {
        return Module::MESSENGER;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasMessenger();
    }

    private function getSymfonyVersion(): ?string
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
            if (($package['name'] ?? '') === 'symfony/messenger') {
                return ltrim($package['version'] ?? '', 'v');
            }
        }

        return null;
    }

    private function hasDangerousHandlers(): bool
    {
        $srcDir = $this->projectPath . '/src';
        if (!is_dir($srcDir)) {
            return false;
        }

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

            if (str_contains($content, 'RunProcessHandler')
                || str_contains($content, 'RunCommandHandler')
                || str_contains($content, 'RunProcessMessage')
                || str_contains($content, 'RunCommandMessage')) {
                return true;
            }
        }

        return false;
    }
}
