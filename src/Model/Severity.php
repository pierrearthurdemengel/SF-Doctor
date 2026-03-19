<?php

// Le namespace correspond au dossier, grâce à la règle PSR-4
// qu'on a définie dans composer.json :
// "SfDoctor\\" → "src/"
// Donc SfDoctor\Model → src/Model/
namespace SfDoctor\Model;

// "enum" = mot-clé introduit en PHP 8.1
// "Severity" = le nom du type (comme un nom de classe)
// ": string" = chaque cas a une valeur string associée (on appelle ça un "backed enum")
enum Severity: string
{
    // Chaque "case" est une valeur possible de ce type.
    // À gauche : le nom PHP (Severity::CRITICAL)
    // À droite : la valeur string ('critical') utile pour le JSON, la BDD, les logs

    case CRITICAL = 'critical';       // Faille de sécurité, crash en prod
    case WARNING = 'warning';         // Anti-pattern, dette technique
    case SUGGESTION = 'suggestion';   // Amélioration possible
    case OK = 'ok';                   // Check passé, tout va bien
}