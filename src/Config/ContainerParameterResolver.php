<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Config;

use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Resout les parametres Symfony en utilisant le ParameterBag du container.
 *
 * Disponible uniquement en mode bundle, quand le container Symfony est
 * accessible. Les references %param% sont remplacees par leurs valeurs
 * reelles avant que les analyzers traitent la configuration.
 */
class ContainerParameterResolver implements ParameterResolverInterface
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function resolveArray(array $config): array
    {
        $resolved = [];

        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $resolved[$key] = $this->resolveArray($value);
            } elseif (is_string($value)) {
                $resolved[$key] = $this->resolveString($value);
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * {@inheritDoc}
     */
    public function resolveString(string $value): string
    {
        // Extrait le nom du parametre entre les deux symboles %.
        // Exemple : "%sylius.security.admin_regex%" -> "sylius.security.admin_regex"
        if (!preg_match('/^%([^%]+)%$/', $value, $matches)) {
            return $value;
        }

        $paramName = $matches[1];

        try {
            $resolved = $this->parameterBag->get($paramName);
        } catch (ParameterNotFoundException) {
            // Le parametre n'existe pas dans le container.
            // Retourner la reference originale permet aux analyzers
            // de signaler une configuration potentiellement invalide.
            return $value;
        }

        // Un parametre peut être de n'importe quel type scalaire.
        // Seules les valeurs convertibles en string sont retournees comme string.
        if (!is_scalar($resolved)) {
            return $value;
        }

        return (string) $resolved;
    }
}