<?php

namespace PierreArthur\SfDoctor\Analyzer;

use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;

// Chaque analyzer implémente ce contrat.
interface AnalyzerInterface
{
    /**
     * pattern "Collecting Parameter" : on passe un objet
     * que les méthodes remplissent au fur et à mesure.
     */
    public function analyze(AuditReport $report): void;

    /**
     * Module auquel appartient cet analyzer.
     *
     * Retourne Module::SECURITY, Module::ARCHITECTURE, etc.
     * Utilisé pour filtrer : quand l'utilisateur lance --security,
     * on n'exécute que les analyzers dont getModule() retourne SECURITY.
     */
    public function getModule(): Module;

    /**
     * Nom lisible de l'analyzer.
     */
    public function getName(): string;

    /**
     * Le runner appelle supports() AVANT analyze().
     * Si supports() retourne false, l'analyzer est ignoré silencieusement.
     *
     * Par défaut, la plupart des analyzers retourneront true.
     */
    public function supports(): bool;
}