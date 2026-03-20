<?php

namespace SfDoctor\Report;

use SfDoctor\Model\AuditReport;

interface ReporterInterface
{
    /**
     * Génère le rapport dans le format spécifique de cette implémentation.
     *
     * Le ConsoleReporter affiche dans le terminal.
     * Le JsonReporter écrit un fichier JSON.
     * Le PdfReporter génère un PDF.
     *
     * void pParce que chaque reporter a sa propre façon de "sortir"
     * le résultat : écrire dans la console, écrire un fichier, envoyer un email...
     * On ne peut pas unifier le type de retour. Donc chaque reporter gère
     * sa propre sortie en interne.
     */
    public function generate(AuditReport $report): void;

    /**
     * Retourne l'identifiant du format supporté par ce reporter.
     *
     * Ex: "console", "json", "pdf"
     *
     * Utilisé par la commande CLI pour router vers le bon reporter :
     * l'utilisateur tape --format=json, la commande cherche le reporter
     * dont getFormat() retourne "json".
     *
     * C'est un identifiant technique (lowercase, sans espaces),
     * pas un label pour l'affichage.
     */
    public function getFormat(): string;
}