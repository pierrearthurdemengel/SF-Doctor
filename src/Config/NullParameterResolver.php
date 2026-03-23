<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Config;

/**
 * Implementation sans operation du ParameterResolverInterface.
 *
 * Utilisee en mode standalone (bin/sf-doctor), quand le container Symfony
 * n'est pas disponible. Retourne la configuration sans modification.
 * Les references %param% ne sont pas resolues.
 */
class NullParameterResolver implements ParameterResolverInterface
{
    /**
     * {@inheritDoc}
     */
    public function resolveArray(array $config): array
    {
        return $config;
    }

    /**
     * {@inheritDoc}
     */
    public function resolveString(string $value): string
    {
        return $value;
    }
}