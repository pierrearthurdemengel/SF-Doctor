<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Workflow;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

/**
 * Definition du workflow d'audit SF-Doctor.
 *
 * Etats possibles : pending, running, completed, failed.
 * Transitions :
 *   - start    : pending  -> running
 *   - complete : running  -> completed
 *   - fail     : running  -> failed
 */
final class AuditWorkflow
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    public const TRANSITION_START    = 'start';
    public const TRANSITION_COMPLETE = 'complete';
    public const TRANSITION_FAIL     = 'fail';

    /**
     * Construit et retourne une instance de StateMachine configuree
     * pour piloter un AuditContext.
     */
    public static function create(): StateMachine
    {
        $definition = new Definition(
            places: [
                self::STATUS_PENDING,
                self::STATUS_RUNNING,
                self::STATUS_COMPLETED,
                self::STATUS_FAILED,
            ],
            transitions: [
                new Transition(self::TRANSITION_START,    self::STATUS_PENDING, self::STATUS_RUNNING),
                new Transition(self::TRANSITION_COMPLETE, self::STATUS_RUNNING, self::STATUS_COMPLETED),
                new Transition(self::TRANSITION_FAIL,     self::STATUS_RUNNING, self::STATUS_FAILED),
            ],
            initialPlaces: [self::STATUS_PENDING]
        );

        // MethodMarkingStore lit/ecrit l'etat via getStatus()/setStatus().
        // Le second argument "true" indique le mode single state (StateMachine).
        $markingStore = new MethodMarkingStore(singleState: true, property: 'status');

        return new StateMachine(
            definition: $definition,
            markingStore: $markingStore,
            name: 'sf_doctor_audit'
        );
    }
}