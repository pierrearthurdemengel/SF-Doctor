<?php

namespace PierreArthur\SfDoctor\Model;

enum Module: string
{
    // Chaque module correspond à un type d'audit.
    // La valeur string sera utilisée dans la CLI (--security)
    // et dans les rapports JSON.

    case SECURITY = 'security';           // Firewalls, access control, CSRF, debug mode
    case ARCHITECTURE = 'architecture';   // Controllers propres, injection, voters, services
    case PERFORMANCE = 'performance';     // Eager loading, cache, messenger, N+1
    case UPGRADE = 'upgrade';             // Migration entre versions Symfony (payant)
    case DOCTRINE = 'doctrine';           // Relations, index, cascade, repositories
    case MESSENGER = 'messenger';         // Messages, handlers, transports, signing
    case API_PLATFORM = 'api-platform';   // Operations, serialisation, pagination, validation
    case MIGRATION = 'migration';         // Deprecations, bundles, version PHP
    case TWIG = 'twig';                   // XSS, raw filter, logique metier dans les templates
    case DEPLOYMENT = 'deployment';       // Migrations BDD, variables d'env, assets, logs
    case TESTS = 'tests';                 // Couverture, tests securite, fixtures
}