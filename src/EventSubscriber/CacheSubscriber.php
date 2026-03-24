<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\EventSubscriber;

use PierreArthur\SfDoctor\Cache\ResultCache;
use PierreArthur\SfDoctor\Cache\ResultCacheInterface;
use PierreArthur\SfDoctor\Event\AnalysisCompletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


/**
 * Sauvegarde le rapport d'audit dans le cache à la fin de chaque analyse.
 * Le cache est indexé par le hash SHA256 des fichiers de configuration du projet.
 */
final class CacheSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ResultCacheInterface $cache,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AnalysisCompletedEvent::NAME => 'onAnalysisCompleted',
        ];
    }

    public function onAnalysisCompleted(AnalysisCompletedEvent $event): void
    {
        $report = $event->getReport();
        $hash = $this->cache->computeHash($report->getProjectPath());
        $this->cache->save($hash, $report);
    }
}