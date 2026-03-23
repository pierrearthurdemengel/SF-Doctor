<?php

namespace PierreArthur\SfDoctor\Model;

// "final" = personne ne peut hériter de cette classe.
// Parce qu'un Issue est un objet de données simple et complet.
// Bonne pratique Symfony : toute classe qui n'est pas
// explicitement conçue pour être étendue devrait être final.
final class Issue
{
    // Ceci est un "constructeur promu" (promoted constructor) — PHP 8.0
    // Combiné avec "readonly" — PHP 8.1
    public function __construct(
        // "private" → accessible uniquement depuis cette classe
        // "readonly" → une fois définie, la valeur ne peut plus changer
        // "Severity" → le type (enum)
        // "$severity" → le nom de la propriété
        private readonly Severity $severity,
        private readonly Module $module,
        private readonly string $analyzer,
        private readonly string $message,
        private readonly string $detail,
        private readonly string $suggestion,

        // Le "?" = "nullable" : cette valeur peut être null.
        // Un issue ne concerne pas toujours un fichier précis
        // (ex: "aucun fichier security.yaml trouvé" → pas de fichier à pointer).
        // "= null" → valeur par défaut si on ne passe rien.
        private readonly ?string $file = null,
        private readonly ?int $line = null,
    ) {}

    // Getters : la seule façon de lire les valeurs depuis l'extérieur.
    // Les propriétés sont "private", donc on passe par des méthodes.

    public function getSeverity(): Severity
    {
        return $this->severity;
    }

    public function getModule(): Module
    {
        return $this->module;
    }

    public function getAnalyzer(): string
    {
        return $this->analyzer;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }

    public function getSuggestion(): string
    {
        return $this->suggestion;
    }

    public function getFile(): ?string
    {
        return $this->file;
    }

    public function getLine(): ?int
    {
        return $this->line;
    }
}