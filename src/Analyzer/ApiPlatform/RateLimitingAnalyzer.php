<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\ApiPlatform;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les operations API Platform publiques sensibles sans rate limiting.
 *
 * Les endpoints d'authentification, d'inscription, de reset password et
 * d'envoi de messages sont des cibles privilegiees pour le brute force,
 * le credential stuffing et le spam. Sans throttling, un attaquant peut
 * envoyer des milliers de requetes par seconde.
 */
final class RateLimitingAnalyzer implements AnalyzerInterface
{
    /**
     * Patterns de noms de classes ou de routes sensibles.
     * Ces ressources gerent des operations critiques qui necessitent du throttling.
     *
     * @var list<string>
     */
    private const SENSITIVE_PATTERNS = [
        'Login',
        'Auth',
        'Register',
        'Registration',
        'Signup',
        'SignUp',
        'ResetPassword',
        'ForgotPassword',
        'PasswordReset',
        'Token',
        'Otp',
        'VerifyEmail',
        'Contact',
    ];

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $directories = [
            $this->projectPath . '/src/Entity' => 'src/Entity/',
            $this->projectPath . '/src/ApiResource' => 'src/ApiResource/',
        ];

        foreach ($directories as $dir => $relativePrefix) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $content = file_get_contents($file->getRealPath());

                if ($content === false || !str_contains($content, '#[ApiResource')) {
                    continue;
                }

                $realPath = str_replace('\\', '/', $file->getRealPath());
                $normalizedDir = str_replace('\\', '/', $dir);
                $relativePath = $relativePrefix . ltrim(
                    str_replace($normalizedDir, '', $realPath),
                    '/',
                );

                $this->checkSensitiveEndpointWithoutRateLimit($report, $content, $relativePath, $file->getFilename());
                $this->checkPublicPostWithoutRateLimit($report, $content, $relativePath, $file->getFilename());
            }
        }
    }

    public function getName(): string
    {
        return 'Rate Limiting Analyzer';
    }

    public function getModule(): Module
    {
        return Module::API_PLATFORM;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasApiPlatform();
    }

    /**
     * Detecte les ressources dont le nom correspond a un pattern sensible
     * (login, register, reset password) sans mention de rate limiting.
     */
    private function checkSensitiveEndpointWithoutRateLimit(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Si le fichier mentionne deja du rate limiting, pas de probleme.
        if ($this->hasRateLimitingIndicator($content)) {
            return;
        }

        $className = $this->extractClassName($content);
        $matchedPattern = $this->matchesSensitivePattern($className);

        if ($matchedPattern === null) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "Endpoint sensible '{$className}' sans rate limiting dans {$filename}",
            detail: "La ressource '{$className}' correspond au pattern '{$matchedPattern}' "
                . "qui designe un endpoint sensible (authentification, inscription, "
                . "reset de mot de passe). Sans rate limiting, un attaquant peut effectuer "
                . "du brute force ou du credential stuffing a haut debit.",
            suggestion: "Ajouter du rate limiting via Symfony RateLimiter ou un middleware "
                . "custom sur cet endpoint.",
            file: $relativePath,
            fixCode: "// config/packages/rate_limiter.yaml\n"
                . "framework:\n"
                . "    rate_limiter:\n"
                . "        " . strtolower($className) . ":\n"
                . "            policy: sliding_window\n"
                . "            limit: 5\n"
                . "            interval: '1 minute'\n\n"
                . "// Dans le processor ou controller :\n"
                . "use Symfony\\Component\\RateLimiter\\RateLimiterFactory;\n\n"
                . "\$limiter = \$this->limiterFactory->create(\$request->getClientIp());\n"
                . "if (!\$limiter->consume()->isAccepted()) {\n"
                . "    throw new TooManyRequestsHttpException();\n"
                . "}",
            docUrl: 'https://symfony.com/doc/current/rate_limiter.html',
            businessImpact: "Brute force sur l'authentification, credential stuffing sur "
                . "les inscriptions, spam via les formulaires de contact. "
                . "Risque de compromission de comptes utilisateurs.",
            estimatedFixMinutes: 30,
        ));
    }

    /**
     * Detecte les operations POST publiques (sans security:) qui ne mentionnent
     * pas de rate limiting. Un POST public sans throttle est un vecteur de spam.
     */
    private function checkPublicPostWithoutRateLimit(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        if ($this->hasRateLimitingIndicator($content)) {
            return;
        }

        // Ne signale que si un Post explicite est present.
        if (!str_contains($content, '#[Post')) {
            return;
        }

        // Verifie si la ressource est publique (pas de security: global).
        $resourceBlock = $this->extractApiResourceBlock($content);

        if ($resourceBlock !== null && str_contains($resourceBlock, 'security')) {
            return;
        }

        // Verifie si le Post a sa propre security.
        $lines = explode("\n", $content);

        foreach ($lines as $i => $line) {
            if (!str_contains($line, '#[Post')) {
                continue;
            }

            // Regarde le contexte autour du Post pour trouver security.
            $end = min(count($lines) - 1, $i + 5);
            $block = implode("\n", array_slice($lines, $i, $end - $i + 1));

            if (str_contains($block, 'security')) {
                continue;
            }

            $className = $this->extractClassName($content);

            // Ne signale pas si deja signale via le check sensible.
            if ($this->matchesSensitivePattern($className) !== null) {
                return;
            }

            $report->addIssue(new Issue(
                severity: Severity::SUGGESTION,
                module: Module::API_PLATFORM,
                analyzer: $this->getName(),
                message: "POST public sans rate limiting dans {$filename}",
                detail: "La ressource '{$className}' a une operation POST publique (sans security:) "
                    . "et sans indication de rate limiting. Un endpoint POST public peut etre "
                    . "exploite pour du spam ou des attaques par epuisement de ressources.",
                suggestion: "Ajouter security: pour restreindre l'acces ou configurer "
                    . "un rate limiter Symfony sur cet endpoint.",
                file: $relativePath,
                fixCode: "#[Post(\n"
                    . "    security: \"is_granted('PUBLIC_ACCESS')\",\n"
                    . "    // Ajouter rate limiting via un processor ou event listener\n"
                    . ")]",
                docUrl: 'https://symfony.com/doc/current/rate_limiter.html',
                businessImpact: "Spam, epuisement de ressources serveur, creation massive "
                    . "de donnees parasites en base.",
                estimatedFixMinutes: 20,
            ));

            return;
        }
    }

    /**
     * Verifie si le fichier contient des indicateurs de rate limiting.
     */
    private function hasRateLimitingIndicator(string $content): bool
    {
        return str_contains($content, 'RateLimiter')
            || str_contains($content, 'rate_limiter')
            || str_contains($content, 'Throttle')
            || str_contains($content, 'throttle')
            || str_contains($content, 'TooManyRequestsHttpException');
    }

    private function matchesSensitivePattern(string $className): ?string
    {
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (stripos($className, $pattern) !== false) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Extrait le bloc #[ApiResource(...)] en gerant les crochets imbriques.
     */
    private function extractApiResourceBlock(string $content): ?string
    {
        $start = strpos($content, '#[ApiResource');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($content);

        for ($i = $start; $i < $length; $i++) {
            if ($content[$i] === '[') {
                $depth++;
            } elseif ($content[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function extractClassName(string $content): string
    {
        if (preg_match('/\bclass\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }

        return 'Resource';
    }
}
