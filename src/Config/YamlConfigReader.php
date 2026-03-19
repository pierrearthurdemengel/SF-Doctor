<?php

namespace SfDoctor\Config;

// Le composant Yaml de Symfony. C'est lui qui sait transformer
// une string YAML en tableau PHP
use Symfony\Component\Yaml\Yaml;

// "implements ConfigReaderInterface" = cette classe SIGNE LE CONTRAT.
// PHP vérifie immédiatement que les méthodes read() et exists()
// sont présentes avec les bonnes signatures.
// oublies une méthode → Fatal Error à la compilation.
// changes un type de retour → Fatal Error aussi.
final class YamlConfigReader implements ConfigReaderInterface
{
    // Le chemin absolu vers la racine du projet audité.
    // Tous les chemins relatifs (comme "config/packages/security.yaml")
    // sont résolus à partir de ce chemin.
    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function read(string $relativePath): ?array
    {
        // 1. Construire le chemin absolu
        $absolutePath = $this->resolvePath($relativePath);

        // 2. Si le fichier n'existe pas, retourner null.
        //    Pas d'exception : l'absence d'un fichier est une info, pas une erreur.
        if (!file_exists($absolutePath)) {
            return null;
        }

        // 3. Parser le YAML en tableau PHP.
        //    Yaml::parseFile() lit le fichier et le transforme.
        //
        //    Le flag Yaml::PARSE_CONSTANT permet de résoudre les constantes PHP
        //    dans les fichiers YAML (certains projets Symfony en utilisent).
        $parsed = Yaml::parseFile($absolutePath, Yaml::PARSE_CONSTANT);

        // 4. Yaml::parseFile peut retourner null si le fichier est vide,
        //    ou un string/int si le YAML ne contient qu'une valeur scalaire.
        //    On ne veut que des tableaux (une config est toujours un tableau).
        if (!is_array($parsed)) {
            return null;
        }

        return $parsed;
    }

    public function exists(string $relativePath): bool
    {
        // Simple vérification d'existence + c'est bien un fichier (pas un dossier).
        return is_file($this->resolvePath($relativePath));
    }

    // Méthode privée : visible uniquement dans cette classe.
    // Elle centralise la construction du chemin absolu.
    private function resolvePath(string $relativePath): string
    {
        // rtrim enlève le "/" final du projectPath s'il y en a un.
        // Ça évite les doubles slashes : "/home/pierre//config/..."
        //
        // DIRECTORY_SEPARATOR est une constante PHP qui vaut "/" sur Linux/Mac
        // et "\" sur Windows. On l'utilise pour être compatible partout.
        return rtrim($this->projectPath, \DIRECTORY_SEPARATOR)
            . \DIRECTORY_SEPARATOR
            . $relativePath;
    }
}