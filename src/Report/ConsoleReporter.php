<?php

namespace SfDoctor\Report;

use SfDoctor\Model\AuditReport;
use SfDoctor\Model\Issue;
use SfDoctor\Model\Module;
use SfDoctor\Model\Severity;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\ArrayInput;

final class ConsoleReporter implements ReporterInterface
{
    // SymfonyStyle est un wrapper autour d'OutputInterface.
    // Il ajoute des méthodes de mise en forme : title(), table(), success(), error()...
    // On le construit à partir d'un InputInterface et d'un OutputInterface.
    private SymfonyStyle $io;

    public function __construct(OutputInterface $output)
    {
        // ArrayInput simule un input vide. SymfonyStyle en a besoin
        // pour fonctionner, mais on ne l'utilise pas ici.
        // C'est un détail d'implémentation de SymfonyStyle :
        // il hérite de OutputStyle qui hérite de Output,
        // et certaines méthodes consultent l'input pour le verbosity level.
        $this->io = new SymfonyStyle(new ArrayInput([]), $output);
    }

    public function generate(AuditReport $report): void
    {
        // --- En-tête ---
        $this->io->title('SF Doctor — Rapport d\'audit');
        $this->printSummary($report);

        // --- Détail par module ---
        foreach ($report->getModules() as $module) {
            $this->printModule($report, $module);
        }

        // --- Score final ---
        $this->printScore($report);
    }

    public function getFormat(): string
    {
        return 'console';
    }

    // --- Méthodes privées d'affichage ---

    private function printSummary(AuditReport $report): void
    {
        // section() affiche un sous-titre (plus petit que title()).
        $this->io->section('Résumé');

        // text() affiche du texte simple.
        $this->io->text([
            sprintf('Projet : <info>%s</info>', $report->getProjectPath()),
            sprintf('Modules : <info>%s</info>', implode(', ', array_map(
                // On utilise la valeur string de l'enum (ex: "security")
                fn (Module $m): string => $m->value,
                $report->getModules(),
            ))),
            sprintf('Issues trouvées : <info>%d</info>', count($report->getIssues())),
        ]);
    }

    private function printModule(AuditReport $report, Module $module): void
    {
        $issues = $report->getIssuesByModule($module);

        // Titre du module avec le nombre d'issues
        $this->io->section(sprintf(
            'Module %s (%d issue%s)',
            ucfirst($module->value),    // "security" → "Security"
            count($issues),
            count($issues) > 1 ? 's' : '',
        ));

        if (empty($issues)) {
            $this->io->success('Aucun problème détecté.');
            return;
        }

        // Afficher chaque issue dans un tableau.
        // table() crée un tableau formaté avec des colonnes alignées.
        // C'est la méthode la plus puissante de SymfonyStyle pour afficher
        // des données structurées dans le terminal.
        $this->io->table(
            // En-têtes des colonnes
            ['Sévérité', 'Analyzer', 'Message', 'Fichier'],
            // Lignes du tableau : une par issue
            array_map(
                fn (Issue $issue): array => [
                    $this->formatSeverity($issue->getSeverity()),
                    $issue->getAnalyzer(),
                    $issue->getMessage(),
                    $this->formatLocation($issue),
                ],
                $issues,
            ),
        );

        // Sous le tableau, afficher le détail et la suggestion
        // pour chaque issue non-OK.
        foreach ($issues as $issue) {
            if ($issue->getSeverity() === Severity::OK) {
                continue;
            }

            $this->io->text(sprintf(
                '  <comment>→</comment> %s',
                $issue->getSuggestion(),
            ));
        }
    }

    private function printScore(AuditReport $report): void
    {
        $score = $report->getScore();
        $this->io->newLine();

        // Coloriser le score selon sa valeur.
        if ($score >= 80) {
            // success() affiche un bloc vert avec une icône ✔
            $this->io->success(sprintf('Score : %d/100', $score));
        } elseif ($score >= 50) {
            // warning() affiche un bloc jaune avec une icône !
            $this->io->warning(sprintf('Score : %d/100', $score));
        } else {
            // error() affiche un bloc rouge avec une icône ✘
            $this->io->error(sprintf('Score : %d/100', $score));
        }
    }

    /**
     * Formate la sévérité avec des couleurs.
     *
     * Les tags <fg=couleur> sont des tags de formatage Symfony Console.
     * Ils ne fonctionnent que dans un terminal compatible (la quasi-totalité).
     */
    private function formatSeverity(Severity $severity): string
    {
        return match ($severity) {
            Severity::CRITICAL => '<fg=red;options=bold>CRITICAL</>',
            Severity::WARNING => '<fg=yellow>WARNING</>',
            Severity::SUGGESTION => '<fg=blue>SUGGESTION</>',
            Severity::OK => '<fg=green>OK</>',
        };
    }

    /**
     * Formate la localisation (fichier + ligne) d'une issue.
     */
    private function formatLocation(Issue $issue): string
    {
        if ($issue->getFile() === null) {
            return '-';
        }

        if ($issue->getLine() !== null) {
            return sprintf('%s:%d', $issue->getFile(), $issue->getLine());
        }

        return $issue->getFile();
    }
}