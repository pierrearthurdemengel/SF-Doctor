<?php

// src/Analyzer/Security/SensitiveDataAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Security;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Detecte les proprietes sensibles d'entites Doctrine exposees sans protection.
 *
 * Scanne les fichiers Entity/ pour reperer les proprietes nommees password, token,
 * secret, apiKey ou creditCard qui ne portent pas d'attribut #[Ignore] ni de
 * restriction de groupe de serialisation.
 */
final class SensitiveDataAnalyzer implements AnalyzerInterface
{
    // Noms de proprietes consideres comme sensibles.
    private const SENSITIVE_PROPERTIES = [
        'password',
        'token',
        'secret',
        'apiKey',
        'creditCard',
    ];

    public function __construct(private readonly string $projectPath)
    {
    }

    public function analyze(AuditReport $report): void
    {
        $entityDir = $this->projectPath . '/src/Entity';

        if (!is_dir($entityDir)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->name('*.php')->in($entityDir);

        if (!$finder->hasResults()) {
            return;
        }

        foreach ($finder as $file) {
            $content = $file->getContents();
            $relativePath = 'src/Entity/' . str_replace('\\', '/', $file->getRelativePathname());

            $this->checkSensitiveProperties($report, $content, $relativePath, $file->getFilename());
        }
    }

    public function getName(): string
    {
        return 'Sensitive Data Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasDoctrineOrm();
    }

    /**
     * Recherche les proprietes sensibles non protegees dans le contenu d'un fichier Entity.
     * Une propriete est consideree protegee si elle porte #[Ignore] ou #[Groups(...)].
     */
    private function checkSensitiveProperties(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        foreach (self::SENSITIVE_PROPERTIES as $propertyName) {
            // Recherche une declaration de propriete avec ce nom.
            $pattern = '/\$' . preg_quote($propertyName, '/') . '\b/';

            if (!preg_match($pattern, $content)) {
                continue;
            }

            // Verifie si la propriete est protegee par #[Ignore] ou #[Groups(...)].
            if ($this->isPropertyProtected($content, $propertyName)) {
                continue;
            }

            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: "Propriété sensible '\${$propertyName}' exposée dans {$filename}",
                detail: "L'entité '{$filename}' contient une propriété '\${$propertyName}' "
                    . "sans attribut #[Ignore] ni restriction de groupe de sérialisation (#[Groups(...)]). "
                    . "Si cette entité est sérialisée (API, JSON, session), cette donnée sensible "
                    . "peut etre exposée involontairement.",
                suggestion: "Ajouter #[Ignore] sur la propriété '\${$propertyName}' pour l'exclure "
                    . "de toute sérialisation, ou utiliser #[Groups(['internal'])] pour restreindre "
                    . "son exposition à un groupe de sérialisation controle.",
                file: $relativePath,
                businessImpact: "La propriété '{$propertyName}' peut etre exposée dans les réponses API, "
                    . "les exports JSON ou les sessions. Cela peut compromettre des données "
                    . "utilisateurs sensibles (mots de passe, tokens, clés API, données bancaires).",
                fixCode: "use Symfony\\Component\\Serializer\\Annotation\\Ignore;\n\n"
                    . "#[Ignore]\n"
                    . "private ?string \${$propertyName} = null;",
                docUrl: 'https://symfony.com/doc/current/serializer.html#ignoring-attributes',
                estimatedFixMinutes: 5,
            ));
        }
    }

    /**
     * Verifie si une propriete est protegee par un attribut #[Ignore] ou #[Groups(...)].
     *
     * Recherche les attributs dans les lignes precedant la declaration de la propriete.
     * Limite la recherche a 5 lignes au-dessus de la declaration pour eviter les faux positifs.
     */
    private function isPropertyProtected(string $content, string $propertyName): bool
    {
        $lines = explode("\n", $content);
        $propertyPattern = '/\$' . preg_quote($propertyName, '/') . '\b/';

        foreach ($lines as $lineNumber => $line) {
            if (!preg_match($propertyPattern, $line)) {
                continue;
            }

            // Regarde les 5 lignes precedentes pour trouver un attribut de protection.
            $lookback = max(0, $lineNumber - 5);
            $context = implode("\n", array_slice($lines, $lookback, $lineNumber - $lookback));

            // Detecte #[Ignore] ou #[Groups(...)].
            if (preg_match('/#\[Ignore\]/', $context)) {
                return true;
            }

            if (preg_match('/#\[Groups\(/', $context)) {
                return true;
            }
        }

        return false;
    }
}
