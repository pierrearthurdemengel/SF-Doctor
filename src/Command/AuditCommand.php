<?php

namespace SfDoctor\Command;

use SfDoctor\Analyzer\AnalyzerInterface;
use SfDoctor\Model\AuditReport;
use SfDoctor\Model\Module;
use SfDoctor\Model\Severity;
use SfDoctor\Report\ConsoleReporter;
use SfDoctor\Report\ReporterInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// #[AsCommand] est un attribut PHP 8 (pas un commentaire, pas une annotation).
// Il remplace l'ancienne méthode où on mettait le nom dans le constructeur
// ou dans une propriété $defaultName.
//
// Symfony lit cet attribut pour enregistrer la commande automatiquement.
// C'est grâce à ça que "bin/console sf-doctor:audit" fonctionne
// sans aucune config dans services.yaml.
//
// "name" : le nom qu'on tape dans le terminal.
//   Convention Symfony : "vendor:action" (comme doctrine:migrations:migrate)
//
// "description" : apparaît quand on tape "bin/console list"
//   C'est la ligne de résumé à côté du nom de la commande.
#[AsCommand(
    name: 'sf-doctor:audit',
    description: 'Audite un projet Symfony (sécurité, architecture, performance)',
)]
final class AuditCommand extends Command
{

    /**
     * @param iterable<AnalyzerInterface> $analyzers
     * @param iterable<ReporterInterface> $reporters
     */
    public function __construct(
        // "iterable" accepte à la fois les tableaux et les objets Traversable.
        private readonly iterable $analyzers,
        private readonly iterable $reporters,

        // Le chemin du projet à auditer.
        private readonly string $projectPath,
    ) {
        // IMPORTANT : on doit appeler le constructeur parent.
        // Command::__construct() initialise le nom et d'autres propriétés internes.
        parent::__construct();
    }

    // configure() est appelé une seule fois, au démarrage.
    // C'est ici qu'on déclare les options et arguments de la commande.
    //
    // Option vs Argument :
    //   - Argument : positionnel, obligatoire ou pas. Ex: "commande monargument"
    //   - Option : nommé, avec -- devant. Ex: "commande --security --format=json"
    //
    // On n'utilise que des options ici, parce que tout est facultatif.
    // L'utilisateur peut lancer "sf-doctor:audit" sans rien → audit complet.
    protected function configure(): void
    {
        $this
            // addOption(nom, raccourci, mode, description, valeur par défaut)
            //
            // VALUE_NONE = c'est un flag booléen (présent ou absent).
            //   --security → true, rien → false
            //
            // VALUE_REQUIRED = il faut une valeur après le =
            //   --format=json → "json"
            ->addOption(
                'security',               // nom long : --security
                's',                       // raccourci : -s
                InputOption::VALUE_NONE,   // pas de valeur, c'est un flag
                'Audit sécurité uniquement',
            )
            ->addOption(
                'architecture',
                'a',
                InputOption::VALUE_NONE,
                'Audit architecture uniquement',
            )
            ->addOption(
                'performance',
                'p',
                InputOption::VALUE_NONE,
                'Audit performance uniquement',
            )
            ->addOption(
                'all',
                null,                      // pas de raccourci
                InputOption::VALUE_NONE,
                'Tous les modules (défaut si aucun module spécifié)',
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,  // il faut une valeur : --format=json
                'Format de sortie (console, json)',
                'console',                    // valeur par défaut
            )
        ;
    }

    // execute() est le cœur de la commande.
    // Appelé quand l'utilisateur lance la commande.
    //
    // $input : contient les options et arguments passés par l'utilisateur
    // $output : le pipe vers le terminal (pour écrire du texte)
    //
    // Retourne un int : le code de sortie.
    //   0 (Command::SUCCESS) → tout va bien
    //   1 (Command::FAILURE) → problème détecté (la CI doit échouer)
    //
    // Le code de sortie est crucial pour la CI :
    //   "bin/console sf-doctor:audit --security" dans GitHub Actions
    //   → si exit 1, le pipeline échoue et bloque le merge.
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SF Doctor — Audit en cours');

        // --- Étape 1 : Déterminer les modules à analyser ---
        $modules = $this->resolveModules($input);

        $io->text(sprintf(
            'Modules : <info>%s</info>',
            implode(', ', array_map(fn (Module $m): string => $m->value, $modules)),
        ));
        $io->newLine();

        // --- Étape 2 : Créer le rapport ---
        $report = new AuditReport(
            projectPath: $this->projectPath,
            modules: $modules,
        );

        // --- Étape 3 : Lancer les analyzers ---
        foreach ($this->analyzers as $analyzer) {
            // Vérifier que l'analyzer appartient à un module demandé
            if (!in_array($analyzer->getModule(), $modules, true)) {
                continue;
            }

            // Vérifier que l'analyzer peut s'exécuter
            if (!$analyzer->supports()) {
                $io->text(sprintf(
                    '  <comment>⏭</comment>  %s (ignoré — dépendance manquante)',
                    $analyzer->getName(),
                ));
                continue;
            }

            // Lancer l'analyse
            $io->text(sprintf('  <info>▶</info>  %s...', $analyzer->getName()));
            $analyzer->analyze($report);
        }

        // --- Étape 4 : Marquer l'audit comme terminé ---
        $report->complete();
        $io->newLine();

        // --- Étape 5 : Générer le rapport dans le format demandé ---
        $format = $input->getOption('format');
        $reported = false;

        // D'abord, chercher dans les reporters injectés
        foreach ($this->reporters as $reporter) {
            if ($reporter->getFormat() === $format) {
                $reporter->generate($report);
                $reported = true;
                break;
            }
        }

        // Fallback : si le format est "console" et qu'aucun reporter
        // n'a été injecté pour ce format, on en crée un à la volée.
        // C'est une solution temporaire pour la V0.1.
        if (!$reported && $format === 'console') {
            $consoleReporter = new ConsoleReporter($output);
            $consoleReporter->generate($report);
            $reported = true;
        }

        if (!$reported) {
            $io->error(sprintf('Format de rapport inconnu : "%s"', $format));
            return Command::FAILURE;
        }

        // --- Étape 6 : Code de sortie basé sur les issues critiques ---
        // S'il y a au moins un CRITICAL → la commande retourne FAILURE (exit 1).
        // C'est ce qui permet de bloquer un pipeline CI/CD.
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);

        if (count($criticals) > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Détermine les modules à analyser selon les options CLI.
     *
     * @return list<Module>
     */
    private function resolveModules(InputInterface $input): array
    {
        // Si un module spécifique est demandé, on ne retourne que celui-là.
        // L'ordre des if compte : si l'utilisateur passe --security --architecture,
        // seul --security sera pris en compte. C'est un choix de simplicité
        // pour la V0.1 — en V0.2 on pourra supporter les combinaisons.
        if ($input->getOption('security')) {
            return [Module::SECURITY];
        }
        if ($input->getOption('architecture')) {
            return [Module::ARCHITECTURE];
        }
        if ($input->getOption('performance')) {
            return [Module::PERFORMANCE];
        }

        // Par défaut (ou --all) : tous les modules sauf UPGRADE (payant)
        return [Module::SECURITY, Module::ARCHITECTURE, Module::PERFORMANCE];
    }
}