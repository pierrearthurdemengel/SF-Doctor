<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Config;

/**
 * Resout les parametres Symfony (%param%) dans un tableau de configuration.
 *
 * Symfony permet de referencer des parametres du container dans les fichiers
 * de configuration via la syntaxe %nom_du_parametre%. Cette interface definit
 * le contrat pour remplacer ces references par leurs valeurs reelles avant
 * l'analyse.
 */
interface ParameterResolverInterface
{
    /**
     * Remplace recursivement tous les parametres Symfony dans le tableau donne.
     *
     * Les valeurs non-string et les strings sans %...% sont retournees
     * sans modification.
     *
     * @param array<mixed> $config
     * @return array<mixed>
     */
    public function resolveArray(array $config): array;

    /**
     * Remplace les parametres Symfony dans une chaine de caracteres.
     *
     * Retourne la chaine originale si aucun parametre n'est trouve
     * ou si la resolution echoue.
     */
    public function resolveString(string $value): string;
}