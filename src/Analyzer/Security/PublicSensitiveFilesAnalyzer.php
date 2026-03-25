<?php

// src/Analyzer/Security/PublicSensitiveFilesAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Security;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les fichiers sensibles exposes dans le repertoire public/.
 *
 * Le repertoire public/ est le document root du serveur web : tout fichier
 * present dans ce repertoire est directement accessible via une URL.
 * Certains fichiers ne doivent jamais s'y trouver (.env, phpinfo.php,
 * composer.json, etc.) car ils exposent des informations sensibles.
 */
final class PublicSensitiveFilesAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {
    }

    public function analyze(AuditReport $report): void
    {
        $publicDir = $this->projectPath . '/public';

        if (!is_dir($publicDir)) {
            return;
        }

        $this->checkDotEnv($report, $publicDir);
        $this->checkPhpinfo($report, $publicDir);
        $this->checkComposerFiles($report, $publicDir);
        $this->checkDangerousScripts($report, $publicDir);
    }

    public function getName(): string
    {
        return 'Public Sensitive Files Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(ProjectContext $context): bool
    {
        $publicDir = $context->getProjectPath() . '/public';

        return is_dir($publicDir);
    }

    /**
     * Detecte la presence de public/.env.
     * Ce fichier contient les secrets de l'application (cles API, mots de passe BDD).
     */
    private function checkDotEnv(AuditReport $report, string $publicDir): void
    {
        if (!file_exists($publicDir . '/.env')) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: 'Fichier .env expose dans le repertoire public/',
            detail: "Le fichier public/.env est directement accessible via le navigateur. "
                . "Ce fichier contient les variables d'environnement de l'application : "
                . "secrets, cles API, identifiants de base de donnees, tokens de services tiers. "
                . "N'importe qui peut le telecharger en accedant a https://votre-site.com/.env.",
            suggestion: "Supprimer immediatement le fichier public/.env. "
                . "Le fichier .env doit se trouver uniquement a la racine du projet, "
                . "jamais dans le repertoire public/.",
            file: 'public/.env',
            fixCode: "# Supprimer le fichier expose :\nrm public/.env\n\n"
                . "# Verifier que .env est bien a la racine du projet :\nls -la .env",
            docUrl: 'https://symfony.com/doc/current/configuration.html#configuring-environment-variables-in-env-files',
            businessImpact: "Tous les secrets de l'application sont accessibles publiquement : "
                . "mots de passe de base de donnees, cles API, tokens d'authentification. "
                . "Un attaquant peut prendre le controle complet de l'application et de ses donnees.",
            estimatedFixMinutes: 2,
        ));
    }

    /**
     * Detecte la presence de public/phpinfo.php.
     * phpinfo() expose la configuration complete de PHP, les extensions installees,
     * les variables d'environnement et les chemins du serveur.
     */
    private function checkPhpinfo(AuditReport $report, string $publicDir): void
    {
        if (!file_exists($publicDir . '/phpinfo.php')) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: 'Fichier phpinfo.php expose dans le repertoire public/',
            detail: "Le fichier public/phpinfo.php est directement accessible via le navigateur. "
                . "La fonction phpinfo() expose la configuration complete de PHP : "
                . "version, extensions, variables d'environnement (dont les secrets), "
                . "chemins du serveur et parametres de compilation.",
            suggestion: "Supprimer immediatement le fichier public/phpinfo.php. "
                . "Utiliser la commande CLI 'php -i' pour consulter la configuration PHP "
                . "sans l'exposer publiquement.",
            file: 'public/phpinfo.php',
            fixCode: "# Supprimer le fichier expose :\nrm public/phpinfo.php",
            docUrl: 'https://www.php.net/manual/fr/function.phpinfo.php',
            businessImpact: "Les informations exposees par phpinfo() permettent a un attaquant "
                . "de connaitre les versions exactes de PHP et de ses extensions, "
                . "les variables d'environnement (secrets) et les chemins du serveur. "
                . "Ces informations facilitent l'exploitation de vulnerabilites connues.",
            estimatedFixMinutes: 2,
        ));
    }

    /**
     * Detecte la presence de composer.json ou composer.lock dans public/.
     * Ces fichiers revelent toutes les dependances du projet et leurs versions exactes.
     */
    private function checkComposerFiles(AuditReport $report, string $publicDir): void
    {
        $composerFiles = ['composer.json', 'composer.lock'];

        foreach ($composerFiles as $file) {
            if (!file_exists($publicDir . '/' . $file)) {
                continue;
            }

            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: "Fichier {$file} expose dans le repertoire public/",
                detail: "Le fichier public/{$file} est directement accessible via le navigateur. "
                    . "Ce fichier revele toutes les dependances du projet et leurs versions exactes. "
                    . "Un attaquant peut identifier les bibliotheques utilisees et rechercher "
                    . "des vulnerabilites connues (CVE) pour ces versions specifiques.",
                suggestion: "Supprimer le fichier public/{$file}. "
                    . "Les fichiers Composer doivent se trouver uniquement a la racine du projet.",
                file: "public/{$file}",
                fixCode: "# Supprimer le fichier expose :\nrm public/{$file}",
                docUrl: 'https://getcomposer.org/doc/01-basic-usage.md',
                businessImpact: "Un attaquant peut identifier les versions exactes de toutes les "
                    . "dependances du projet et cibler les vulnerabilites connues "
                    . "pour ces versions specifiques.",
                estimatedFixMinutes: 2,
            ));
        }
    }

    /**
     * Detecte la presence de scripts dangereux dans public/ : info.php et test.php.
     * Ces fichiers sont souvent oublies apres le developpement et peuvent contenir
     * du code de debug ou des informations sensibles.
     */
    private function checkDangerousScripts(AuditReport $report, string $publicDir): void
    {
        $dangerousFiles = [
            'info.php' => "un script d'information serveur",
            'test.php' => 'un script de test',
        ];

        foreach ($dangerousFiles as $file => $description) {
            if (!file_exists($publicDir . '/' . $file)) {
                continue;
            }

            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: "Fichier {$file} expose dans le repertoire public/",
                detail: "Le fichier public/{$file} ({$description}) est directement accessible "
                    . "via le navigateur. Ce type de script est generalement un reste "
                    . "de la phase de developpement et peut contenir du code de debug, "
                    . "des appels a phpinfo(), des acces directs a la base de donnees "
                    . "ou d'autres informations sensibles.",
                suggestion: "Supprimer immediatement le fichier public/{$file}. "
                    . "Les scripts de test et de debug ne doivent jamais se trouver "
                    . "dans le repertoire public/ en production.",
                file: "public/{$file}",
                fixCode: "# Supprimer le fichier expose :\nrm public/{$file}",
                docUrl: 'https://symfony.com/doc/current/setup/web_server_configuration.html',
                businessImpact: "Un script de debug ou de test expose dans public/ peut "
                    . "reveler des informations sensibles sur le serveur, "
                    . "la base de donnees ou la configuration de l'application. "
                    . "Il peut aussi etre exploite pour executer du code arbitraire.",
                estimatedFixMinutes: 2,
            ));
        }
    }
}
