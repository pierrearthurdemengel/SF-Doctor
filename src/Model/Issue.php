<?php

namespace PierreArthur\SfDoctor\Model;

// "final" = personne ne peut hériter de cette classe.
// Parce qu'un Issue est un objet de données simple et complet.
// Bonne pratique Symfony : toute classe qui n'est pas
// explicitement conçue pour être étendue devrait être final.
final class Issue
{
    public function __construct(
        private readonly Severity $severity,
        private readonly Module $module,
        private readonly string $analyzer,
        private readonly string $message,
        private readonly string $detail,
        private readonly string $suggestion,
        private readonly ?string $file = null,
        private readonly ?int $line = null,

        // Extrait de code à appliquer pour corriger le problème.
        // Null si la correction n'est pas automatisable ou trop contextuelle.
        private readonly ?string $fixCode = null,

        // URL vers la documentation officielle Symfony décrivant la bonne pratique.
        private readonly ?string $docUrl = null,

        // Description de l'impact métier en langage non-technique.
        // Destinée aux rapports partagés avec des non-développeurs.
        private readonly ?string $businessImpact = null,

        // Estimation du temps nécessaire pour corriger ce problème, en minutes.
        // Null si l'estimation n'est pas applicable.
        private readonly ?int $estimatedFixMinutes = null,
    ) {}

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

    public function getFixCode(): ?string
    {
        return $this->fixCode;
    }

    public function getDocUrl(): ?string
    {
        return $this->docUrl;
    }

    public function getBusinessImpact(): ?string
    {
        return $this->businessImpact;
    }

    public function getEstimatedFixMinutes(): ?int
    {
        return $this->estimatedFixMinutes;
    }
}