<?php

// src/Analyzer/Security/RememberMeAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Security;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Verifie la configuration remember_me dans le firewall de securite.
 *
 * Detecte les cookies remember_me non securises (absence de secure ou httponly)
 * et les durees de vie excessives qui augmentent la fenetre d'attaque.
 */
final class RememberMeAnalyzer implements AnalyzerInterface
{
    // Duree de vie maximale recommandee : 30 jours en secondes.
    private const MAX_RECOMMENDED_LIFETIME = 2592000;

    public function __construct(private readonly ConfigReaderInterface $configReader)
    {
    }

    public function analyze(AuditReport $report): void
    {
        $security = $this->configReader->read('config/packages/security.yaml');

        if ($security === null) {
            return;
        }

        $firewalls = $security['security']['firewalls'] ?? [];

        foreach ($firewalls as $name => $config) {
            if ($name === 'dev' || !is_array($config)) {
                continue;
            }

            $rememberMe = $config['remember_me'] ?? null;

            if ($rememberMe === null || !is_array($rememberMe)) {
                continue;
            }

            $this->checkSecureFlag($report, $name, $rememberMe);
            $this->checkHttpOnlyFlag($report, $name, $rememberMe);
            $this->checkLifetime($report, $name, $rememberMe);
        }
    }

    public function getName(): string
    {
        return 'Remember Me Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasSecurityBundle();
    }

    /**
     * Verifie que le cookie remember_me est marque secure: true.
     * Sans ce flag, le cookie est transmis en HTTP clair et peut etre intercepte.
     *
     * @param array<mixed> $rememberMe
     */
    private function checkSecureFlag(AuditReport $report, string $firewallName, array $rememberMe): void
    {
        $secure = $rememberMe['secure'] ?? null;

        if ($secure === true) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: "Firewall '{$firewallName}' : remember_me sans secure: true",
            detail: "Le cookie remember_me du firewall '{$firewallName}' n'a pas le flag 'secure' activé. "
                . "Sans ce flag, le cookie est envoyé en HTTP clair et peut etre intercepté "
                . "par un attaquant sur le réseau (attaque man-in-the-middle).",
            suggestion: "Ajouter 'secure: true' dans la section remember_me du firewall '{$firewallName}'.",
            file: 'config/packages/security.yaml',
            businessImpact: "Le cookie remember_me peut etre intercepté sur une connexion non chiffrée, "
                . "permettant à un attaquant de voler la session persistante de l'utilisateur.",
            fixCode: "security:\n    firewalls:\n        {$firewallName}:\n            remember_me:\n                secret: '%kernel.secret%'\n                secure: true",
            docUrl: 'https://symfony.com/doc/current/security/remember_me.html',
            estimatedFixMinutes: 5,
        ));
    }

    /**
     * Verifie que le cookie remember_me est marque httponly: true.
     * Sans ce flag, le cookie est accessible via JavaScript et vulnerable aux attaques XSS.
     *
     * @param array<mixed> $rememberMe
     */
    private function checkHttpOnlyFlag(AuditReport $report, string $firewallName, array $rememberMe): void
    {
        $httpOnly = $rememberMe['httponly'] ?? null;

        if ($httpOnly === true) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: "Firewall '{$firewallName}' : remember_me sans httponly: true",
            detail: "Le cookie remember_me du firewall '{$firewallName}' n'a pas le flag 'httponly' activé. "
                . "Sans ce flag, le cookie est lisible par du JavaScript cote client, "
                . "ce qui le rend vulnerable aux attaques XSS.",
            suggestion: "Ajouter 'httponly: true' dans la section remember_me du firewall '{$firewallName}'.",
            file: 'config/packages/security.yaml',
            businessImpact: "Un script malveillant injecté via XSS peut lire le cookie remember_me "
                . "et l'envoyer à un serveur tiers pour usurper l'identité de l'utilisateur.",
            fixCode: "security:\n    firewalls:\n        {$firewallName}:\n            remember_me:\n                secret: '%kernel.secret%'\n                httponly: true",
            docUrl: 'https://symfony.com/doc/current/security/remember_me.html',
            estimatedFixMinutes: 5,
        ));
    }

    /**
     * Verifie que la duree de vie du cookie remember_me ne depasse pas 30 jours.
     * Une duree excessive augmente la fenetre d'attaque en cas de vol du cookie.
     *
     * @param array<mixed> $rememberMe
     */
    private function checkLifetime(AuditReport $report, string $firewallName, array $rememberMe): void
    {
        $lifetime = $rememberMe['lifetime'] ?? null;

        if ($lifetime === null || !is_int($lifetime)) {
            return;
        }

        if ($lifetime <= self::MAX_RECOMMENDED_LIFETIME) {
            return;
        }

        $days = (int) round($lifetime / 86400);

        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: "Firewall '{$firewallName}' : remember_me avec une durée de vie de {$days} jours",
            detail: "Le cookie remember_me du firewall '{$firewallName}' a une durée de vie de {$lifetime} secondes "
                . "({$days} jours), ce qui dépasse les 30 jours recommandés. "
                . "Plus la durée de vie est longue, plus la fenetre d'attaque est grande en cas de vol du cookie.",
            suggestion: "Réduire la durée de vie à 30 jours maximum (2592000 secondes).",
            file: 'config/packages/security.yaml',
            businessImpact: "Un cookie remember_me volé reste valide pendant {$days} jours. "
                . "Réduire la durée limite l'impact d'un éventuel vol de cookie.",
            fixCode: "security:\n    firewalls:\n        {$firewallName}:\n            remember_me:\n                secret: '%kernel.secret%'\n                lifetime: 2592000",
            docUrl: 'https://symfony.com/doc/current/security/remember_me.html#customizing-remember-me',
            estimatedFixMinutes: 5,
        ));
    }
}
