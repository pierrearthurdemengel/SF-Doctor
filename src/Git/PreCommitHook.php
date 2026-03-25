<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Git;

/**
 * Genere et installe un hook pre-commit Git qui lance SF-Doctor
 * sur les fichiers modifies avant chaque commit.
 *
 * Le hook bloque le commit si une issue CRITICAL est introduite.
 */
final class PreCommitHook
{
    private const HOOK_MARKER = '# sf-doctor:pre-commit';

    /**
     * Contenu du script pre-commit.
     * Le hook lance SF-Doctor en mode --security sur les fichiers stages.
     * Si des CRITICALs sont detectes, le commit est bloque.
     */
    public function generate(): string
    {
        return <<<'BASH'
#!/bin/sh
# sf-doctor:pre-commit
# Hook installe par SF-Doctor. Lance un audit de securite avant chaque commit.
# Pour desactiver temporairement : git commit --no-verify

# Verifier que la commande sf-doctor:audit existe.
if ! php bin/console list --raw 2>/dev/null | grep -q "sf-doctor:audit"; then
    # Essayer avec le binaire standalone.
    if [ ! -f vendor/bin/sf-doctor ]; then
        echo "[SF-Doctor] Commande introuvable, hook ignore."
        exit 0
    fi
    SF_DOCTOR="vendor/bin/sf-doctor"
else
    SF_DOCTOR="php bin/console sf-doctor:audit"
fi

echo "[SF-Doctor] Audit pre-commit en cours..."

# Lancer l'audit en mode brief (sortie condensee).
$SF_DOCTOR --security --brief --format=console

EXIT_CODE=$?

if [ $EXIT_CODE -ne 0 ]; then
    echo ""
    echo "[SF-Doctor] CRITICAL detecte. Commit bloque."
    echo "[SF-Doctor] Corrigez les issues ou utilisez --no-verify pour passer outre."
    exit 1
fi

echo "[SF-Doctor] Aucun probleme critique. Commit autorise."
exit 0
BASH;
    }

    /**
     * Installe le hook dans le repertoire .git/hooks/ du projet.
     * Retourne le chemin du fichier cree, ou null si l'installation echoue.
     */
    public function install(string $projectPath): ?string
    {
        $gitDir = $projectPath . '/.git';
        if (!is_dir($gitDir)) {
            return null;
        }

        $hooksDir = $gitDir . '/hooks';
        if (!is_dir($hooksDir)) {
            mkdir($hooksDir, 0755, true);
        }

        $hookPath = $hooksDir . '/pre-commit';

        // Si un hook existe deja et n'est pas de SF-Doctor, ne pas l'ecraser.
        if (file_exists($hookPath) && !$this->isSfDoctorHook($hookPath)) {
            return null;
        }

        $content = $this->generate();
        file_put_contents($hookPath, $content);
        chmod($hookPath, 0755);

        return $hookPath;
    }

    /**
     * Desinstalle le hook s'il a ete installe par SF-Doctor.
     */
    public function uninstall(string $projectPath): bool
    {
        $hookPath = $projectPath . '/.git/hooks/pre-commit';

        if (!file_exists($hookPath)) {
            return false;
        }

        if (!$this->isSfDoctorHook($hookPath)) {
            return false;
        }

        return unlink($hookPath);
    }

    /**
     * Verifie si le hook pre-commit existant a ete installe par SF-Doctor.
     */
    public function isSfDoctorHook(string $hookPath): bool
    {
        if (!file_exists($hookPath)) {
            return false;
        }

        $content = file_get_contents($hookPath);
        if ($content === false) {
            return false;
        }

        return str_contains($content, self::HOOK_MARKER);
    }
}
