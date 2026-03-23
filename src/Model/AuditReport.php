<?php

namespace PierreArthur\SfDoctor\Model;

// final : c'est un objet de données, personne n'a besoin d'en hériter.
final class AuditReport
{
    // Cette propriété n'est PAS dans le constructeur.
    // Elle se remplit au fil de l'audit, issue par issue.
    // C'est un tableau d'objets Issue, initialisé vide.

    // Le commentaire PHPDoc "@var list<Issue>" dit à PHPStan :
    // "ce tableau contient uniquement des objets Issue, indexé par des entiers séquentiels".
    // "list" est plus strict que "array" : les clés sont 0, 1, 2... (pas de trous).
    /** @var list<Issue> */
    private array $issues = [];

    // Le moment où l'audit a démarré.
    // On utilise DateTimeImmutable (pas DateTime) : comme readonly,
    // une fois créé, l'objet date ne peut pas être modifié.
    // DateTime a des méthodes comme ->modify('+1 day') qui changent l'objet.
    // DateTimeImmutable retourne un NOUVEL objet à chaque modification.
    private \DateTimeImmutable $startedAt;

    // Nullable : l'audit n'est pas terminé tant qu'on n'a pas appelé complete().
    private ?\DateTimeImmutable $completedAt = null;

    /**
     * @param list<Module> $modules Les modules qu'on audite
     */
    public function __construct(
        private readonly string $projectPath,

        // Les modules demandés par l'utilisateur (ex: [Module::SECURITY])
        // La liste des modules ne change pas en cours d'audit.
        /** @var list<Module> */
        private readonly array $modules,
    ) {
        // Le chrono démarre dès la création du rapport.
        $this->startedAt = new \DateTimeImmutable();
    }

    // --- Ajout d'issues ---

    public function addIssue(Issue $issue): void
    {
        // On empile l'issue dans le tableau.
        $this->issues[] = $issue;
    }

    // --- Lecture ---

    /**
     * @return list<Issue>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * Filtre les issues par niveau de gravité.
     * Utilisé par la commande CLI pour décider du code de sortie :
     * s'il y a des CRITICAL → exit 1 (la CI échoue).
     *
     * @return list<Issue>
     */
    public function getIssuesBySeverity(Severity $severity): array
    {
        // array_values() remet les clés à 0, 1, 2...
        // Sans ça, array_filter garde les clés d'origine (ex: [2 => ..., 5 => ...])
        return array_values(
            array_filter(
                $this->issues,
                fn (Issue $issue): bool => $issue->getSeverity() === $severity,
            )
        );
    }

    /**
     * Filtre les issues par module.
     *
     * @return list<Issue>
     */
    public function getIssuesByModule(Module $module): array
    {
        return array_values(
            array_filter(
                $this->issues,
                fn (Issue $issue): bool => $issue->getModule() === $module,
            )
        );
    }

    // --- Score ---

    /**
     * Score sur 100 : 100 = projet parfait.
     * Chaque issue fait baisser le score selon sa gravité.
     *
     * Barème :
     *   CRITICAL   → -10 points (faille grave)
     *   WARNING    → -3 points  (anti-pattern)
     *   SUGGESTION → -1 point   (amélioration possible)
     *   OK         → 0 point    (check passé)
     */
    public function getScore(): int
    {
        $score = 100;

        foreach ($this->issues as $issue) {
            // "match" est comme un switch, mais :
            // 1. Il retourne une valeur (on peut l'assigner)
            // 2. Il utilise === (comparaison stricte)
            // 3. Il lève une erreur si un cas n'est pas couvert
            //    (PHPStan le vérifie aussi grâce à l'enum)
            $score -= match ($issue->getSeverity()) {
                Severity::CRITICAL => 10,
                Severity::WARNING => 3,
                Severity::SUGGESTION => 1,
                Severity::OK => 0,
            };
        }

        // max(0, ...) empêche le score de devenir négatif.
        // Un projet catastrophique a 0, pas -47.
        return max(0, $score);
    }

    // --- Cycle de vie ---

    /**
     * Marque l'audit comme terminé. Arrête le chrono.
     */
    public function complete(): void
    {
        $this->completedAt = new \DateTimeImmutable();
    }

    /**
     * Durée de l'audit en secondes.
     * Retourne null si l'audit n'est pas encore terminé.
     */
    public function getDuration(): ?float
    {
        if ($this->completedAt === null) {
            return null;
        }

        // La différence entre deux DateTimeImmutable donne un DateInterval.
        // On convertit en secondes avec le timestamp.
        return (float) $this->completedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }

    // --- Getters simples ---

    public function getProjectPath(): string
    {
        return $this->projectPath;
    }

    /**
     * @return list<Module>
     */
    public function getModules(): array
    {
        return $this->modules;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }
}