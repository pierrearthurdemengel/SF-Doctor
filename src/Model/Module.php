<?php

namespace SfDoctor\Model;

enum Module: string
{
    // Chaque module correspond à un type d'audit.
    // La valeur string sera utilisée dans la CLI (--security)
    // et dans les rapports JSON.

    case SECURITY = 'security';           // Firewalls, access control, CSRF, debug mode
    case ARCHITECTURE = 'architecture';   // Controllers propres, injection, voters, services
    case PERFORMANCE = 'performance';     // Eager loading, cache, messenger, N+1
    case UPGRADE = 'upgrade';             // Migration entre versions Symfony (payant)
}