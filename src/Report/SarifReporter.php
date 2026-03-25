<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Report;

use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Severity;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Genere un rapport au format SARIF (Static Analysis Results Interchange Format).
 * Ce format est reconnu nativement par GitHub Code Scanning.
 * Chaque issue SF-Doctor apparait comme une annotation sur la ligne de code concernee.
 */
class SarifReporter implements ReporterInterface
{
    public function generate(AuditReport $report, OutputInterface $output, array $context = []): void
    {
        $sarif = [
            '$schema' => 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/main/sarif-2.1/schema/sarif-schema-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [
                [
                    'tool' => [
                        'driver' => [
                            'name' => 'SF-Doctor',
                            'informationUri' => 'https://github.com/pierre-arthur/sf-doctor',
                            'version' => '1.9.0',
                            'rules' => $this->buildRules($report),
                        ],
                    ],
                    'results' => $this->buildResults($report),
                ],
            ],
        ];

        $output->write((string) json_encode($sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function getFormat(): string
    {
        return 'sarif';
    }

    /**
     * Construit la liste des regles SARIF a partir des analyzers uniques.
     *
     * @return list<array<string, mixed>>
     */
    private function buildRules(AuditReport $report): array
    {
        $seen = [];
        $rules = [];

        foreach ($report->getIssues() as $issue) {
            $ruleId = $this->ruleId($issue);
            if (isset($seen[$ruleId])) {
                continue;
            }
            $seen[$ruleId] = true;

            $rules[] = [
                'id' => $ruleId,
                'shortDescription' => ['text' => $issue->getMessage()],
                'defaultConfiguration' => [
                    'level' => $this->sarifLevel($issue->getSeverity()),
                ],
            ];
        }

        return $rules;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildResults(AuditReport $report): array
    {
        $results = [];

        foreach ($report->getIssues() as $issue) {
            $result = [
                'ruleId' => $this->ruleId($issue),
                'level' => $this->sarifLevel($issue->getSeverity()),
                'message' => ['text' => $issue->getDetail()],
            ];

            if ($issue->getFile() !== null) {
                $location = [
                    'physicalLocation' => [
                        'artifactLocation' => [
                            'uri' => $issue->getFile(),
                        ],
                    ],
                ];

                if ($issue->getLine() !== null) {
                    $location['physicalLocation']['region'] = [
                        'startLine' => $issue->getLine(),
                    ];
                }

                $result['locations'] = [$location];
            }

            if ($issue->getSuggestion() !== '') {
                $result['fixes'] = [
                    [
                        'description' => ['text' => $issue->getSuggestion()],
                    ],
                ];
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Genere un identifiant de regle stable a partir du module et de l'analyzer.
     */
    private function ruleId(Issue $issue): string
    {
        return $issue->getModule()->value . '/' . str_replace(' ', '-', strtolower($issue->getAnalyzer()));
    }

    /**
     * Convertit la severite SF-Doctor en niveau SARIF.
     */
    private function sarifLevel(Severity $severity): string
    {
        return match ($severity) {
            Severity::CRITICAL => 'error',
            Severity::WARNING => 'warning',
            Severity::SUGGESTION => 'note',
            Severity::OK => 'none',
        };
    }
}
