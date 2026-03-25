<?php

// src/Diff/AuditReportDiff.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Diff;

use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;

/**
 * Compare deux rapports d'audit et produit la liste des differences.
 *
 * Une issue est identifiee par son module, sa severite et son message.
 * Cette empreinte est stable entre deux analyses du meme projet.
 */
final class AuditReportDiff
{
    /**
     * Issues presentes dans le rapport courant mais absentes du rapport precedent.
     *
     * @var Issue[]
     */
    private array $introduced = [];

    /**
     * Issues presentes dans le rapport precedent mais absentes du rapport courant.
     *
     * @var Issue[]
     */
    private array $fixed = [];

    public function __construct(AuditReport $previous, AuditReport $current)
    {
        $previousFingerprints = $this->buildFingerprintMap($previous->getIssues());
        $currentFingerprints  = $this->buildFingerprintMap($current->getIssues());

        foreach ($current->getIssues() as $issue) {
            $fp = $this->fingerprint($issue);
            if (!isset($previousFingerprints[$fp])) {
                $this->introduced[] = $issue;
            }
        }

        foreach ($previous->getIssues() as $issue) {
            $fp = $this->fingerprint($issue);
            if (!isset($currentFingerprints[$fp])) {
                $this->fixed[] = $issue;
            }
        }
    }

    /**
     * @return Issue[]
     */
    public function getIntroduced(): array
    {
        return $this->introduced;
    }

    /**
     * @return Issue[]
     */
    public function getFixed(): array
    {
        return $this->fixed;
    }

    public function hasRegressions(): bool
    {
        return count($this->introduced) > 0;
    }

    public function isEmpty(): bool
    {
        return count($this->introduced) === 0 && count($this->fixed) === 0;
    }

    /**
     * Calcule une empreinte stable pour une issue.
     * Le chemin de fichier et la ligne sont exclus : une meme issue peut bouger
     * de ligne sans changer de nature apres un refactoring.
     */
    private function fingerprint(Issue $issue): string
    {
        return $issue->getModule()->name . '|' . $issue->getSeverity()->name . '|' . $issue->getMessage();
    }

    /**
     * Construit une map empreinte -> true pour une recherche en O(1).
     *
     * @param Issue[] $issues
     * @return array<string, true>
     */
    private function buildFingerprintMap(array $issues): array
    {
        $map = [];
        foreach ($issues as $issue) {
            $map[$this->fingerprint($issue)] = true;
        }

        return $map;
    }
}