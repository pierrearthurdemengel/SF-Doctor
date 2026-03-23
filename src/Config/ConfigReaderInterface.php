<?php

namespace PierreArthur\SfDoctor\Config;

// Une interface ne contient AUCUN code concret.
// Pas de propriétés, pas de logique, pas de constructeur.
// Juste des signatures de méthodes : le NOM, les PARAMÈTRES, le type de RETOUR.
//
// C'est un CONTRAT : toute classe qui "implements ConfigReaderInterface"
// s'engage à fournir ces méthodes, exactement avec ces signatures.
// Si elle oublie une méthode ou change un type → PHP refuse de compiler.
interface ConfigReaderInterface
{
    /**
     * Lit un fichier de configuration et retourne son contenu sous forme de tableau PHP.
     *
     * @param string $relativePath Chemin relatif au projet audité.
     *                              Ex: "config/packages/security.yaml"
     *
     * @return array<mixed>|null Le contenu parsé, ou null si le fichier n'existe pas.
     *                           "array<mixed>" parce que le contenu d'un YAML peut être
     *                           n'importe quoi : strings, entiers, tableaux imbriqués...
     *                           On ne peut pas être plus précis à ce stade.
     */
    public function read(string $relativePath): ?array;

    /**
     * Vérifie si un fichier de configuration existe dans le projet audité.
     *
     * Utile pour les analyzers qui veulent vérifier la PRÉSENCE d'un fichier
     * avant de le lire. Ex: "Le fichier security.yaml existe-t-il ?"
     *
     * @param string $relativePath Chemin relatif au projet audité.
     */
    public function exists(string $relativePath): bool;
}