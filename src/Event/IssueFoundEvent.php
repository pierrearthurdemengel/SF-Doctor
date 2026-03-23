<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Event;

use PierreArthur\SfDoctor\Model\Issue;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Evénement dispatché chaque fois qu'un analyzer détecte un problème.
 * Transporte l'issue trouvée et le nom de l'analyzer qui l'a détectée.
 */
final class IssueFoundEvent extends Event
{
    public const NAME = 'sf_doctor.issue_found';

    public function __construct(
        private readonly Issue $issue,
        private readonly string $analyzerClass,
    ) {
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    /**
     * Nom complet de la classe de l'analyzer (ex: FirewallAnalyzer::class).
     */
    public function getAnalyzerClass(): string
    {
        return $this->analyzerClass;
    }
}