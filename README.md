# SF-Doctor

**Outil CLI d'audit automatise pour projets Symfony — 55+ checks, 11 modules, zero config.**

SF-Doctor analyse la securite, l'architecture, la performance et la configuration de vos
projets Symfony. Il detecte les failles, les anti-patterns et les oublis de configuration.
Un rapport clair avec des recommandations concretes, du code de correction, et une estimation
du temps de remediation — directement dans votre terminal, en JSON, PDF ou SARIF pour GitHub
Code Scanning.

[![CI](https://github.com/sf-doctor/sf-doctor/actions/workflows/ci.yaml/badge.svg)](https://github.com/sf-doctor/sf-doctor/actions/workflows/ci.yaml)
[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](https://phpstan.org/)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![Symfony 6.4|7.x](https://img.shields.io/badge/Symfony-6.4%20%7C%207.x-black.svg)](https://symfony.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

---

## Ce que SF-Doctor detecte

### Module Security (16 analyzers)

| Analyzer | Cible | Exemples de detection |
|---|---|---|
| **FirewallAnalyzer** | `security.yaml` | Firewalls sans authenticator, sans access_control, mode lazy |
| **AccessControlAnalyzer** | `security.yaml` | Roles manquants, roles deprecies, catch-all mal place, chemins sensibles non proteges |
| **CsrfAnalyzer** | `framework.yaml`, `src/Form/` | CSRF desactive globalement ou par FormType |
| **SecretsAnalyzer** | `.env`, `.env.prod` | APP_SECRET absent, valeur par defaut, trop court |
| **SensitiveDataAnalyzer** | `src/Entity/`, `src/Form/` | Champs password/token exposes sans `#[Ignore]` |
| **RememberMeAnalyzer** | `security.yaml` | Cookie sans `secure`, sans `httponly`, lifetime excessif |
| **SymfonyVersionAnalyzer** | `composer.lock` | Version avec CVE connu, end-of-life, mineure non a jour |
| **CorsAnalyzer** | `nelmio_cors.yaml` | `allow_origin: ['*']` avec credentials, CORS manquant sur /api |
| **HttpsAnalyzer** | `security.yaml` | Absence de `requires_channel: https`, cookies non securises, HSTS manquant |
| **HttpMethodOverrideAnalyzer** | `framework.yaml` | HTTP verb tunneling sans protection CSRF |
| **MassAssignmentAnalyzer** | `src/Form/` | `$form->submit($request->request->all())` sans liste blanche |
| **RateLimiterAnalyzer** | `framework.yaml` | Route de login sans rate limiter (brute force) |
| **BundleRouteExposureAnalyzer** | Routes | Routes de bundles tiers exposees en production |
| **ExposedDebugEndpointsAnalyzer** | Routes | Endpoints de debug accessibles en prod |
| **PublicSensitiveFilesAnalyzer** | `public/` | Fichiers sensibles accessibles (.env, .git, etc.) |
| **SequentialIdAnalyzer** | `src/Entity/` | IDs sequentiels previsibles sur des ressources sensibles |

### Module Architecture (7 analyzers)

| Analyzer | Cible | Exemples de detection |
|---|---|---|
| **ControllerAnalyzer** | `src/Controller/` | QueryBuilder/DQL dans les controllers, acces direct EntityManager |
| **ServiceInjectionAnalyzer** | `src/` | Injection de `ContainerInterface`, appels `$this->container->get()` |
| **HeavyConstructorAnalyzer** | `src/Service/`, `src/Repository/` | Appels BDD dans les constructeurs, >8 dependances injectees |
| **VoterUsageAnalyzer** | `src/Controller/` | Checks de roles manuels au lieu de Voters |
| **PublicServiceAnalyzer** | `config/services.yaml` | Services declares `public: true` inutilement |
| **EventSubscriberAnalyzer** | `src/EventSubscriber/` | Logique metier lourde dans les subscribers, couplage EntityManager |
| **InterLayerCoherenceAnalyzer** | `src/` | Incoherences entre couches (controller, service, repository) |

### Module Configuration (5 analyzers)

| Analyzer | Cible | Exemples de detection |
|---|---|---|
| **DebugModeAnalyzer** | `.env.prod`, `.env` | APP_ENV != prod, APP_DEBUG=true en production |
| **ProfilerAnalyzer** | `packages/` | Profiler/toolbar actif en production |
| **MailerAnalyzer** | `.env`, `mailer.yaml` | DSN `null://null` en prod (emails perdus) |
| **HttpHeadersAnalyzer** | Config | X-Frame-Options, X-Content-Type-Options, CSP manquants |
| **ProductionReadinessAnalyzer** | `.env.prod`, `php.ini` | opcache timestamps, preload absent, composer.lock non commite |

### Module Performance (1 analyzer)

| Analyzer | Cible | Exemples de detection |
|---|---|---|
| **NplusOneAnalyzer** | `templates/` | Acces a deux niveaux dans les boucles Twig (N+1 potentiel) |

### Module Doctrine (5 analyzers)

| Analyzer | Cible | Exemples de detection |
|---|---|---|
| **EagerLoadingAnalyzer** | `src/Entity/` | `fetch: EAGER` sur OneToMany/ManyToMany |
| **MissingIndexAnalyzer** | `src/Entity/`, `src/Repository/` | Colonnes filtrees sans `#[ORM\Index]` |
| **CascadeRiskAnalyzer** | `src/Entity/` | `cascade: ['all']`, remove sans orphanRemoval |
| **RepositoryPatternAnalyzer** | `src/` | QueryBuilder hors des repositories |
| **LazyGhostObjectAnalyzer** | `doctrine.yaml` | lazy_ghost_objects sur Doctrine ORM < 3.0 |

### Module Messenger (4 analyzers)

| Analyzer | Cible | Exemples de detection |
|---|---|---|
| **UnhandledMessageAnalyzer** | `src/Message/` | Messages sans handler (silencieusement ignores) |
| **UnserializableMessageAnalyzer** | `src/Message/` | Closures ou resources dans les messages |
| **MessengerTransportAnalyzer** | `messenger.yaml` | Transport sync, transport failed absent, retry manquant |
| **MessengerSigningAnalyzer** | `messenger.yaml` | Messages non signes (Symfony 7.4+) |

### Module API Platform (4 analyzers)

| Analyzer | Cible | Exemples de detection |
|---|---|---|
| **OperationSecurityAnalyzer** | `src/Entity/` | Operations sans `security` attribute |
| **SerializationGroupAnalyzer** | `src/Entity/` | Ressources sans groupes de serialisation |
| **PaginationAnalyzer** | `src/Entity/` | Pagination desactivee sur des collections |
| **ValidationAnalyzer** | `src/Entity/` | Ressources sans contraintes de validation |

### Module Twig (3 analyzers)

| Analyzer | Cible | Exemples de detection |
|---|---|---|
| **TwigRawFilterAnalyzer** | `templates/` | Filtre `\|raw` (risque XSS) |
| **TwigSrcdocAnalyzer** | `templates/` | Attribut `srcdoc` sans echappement |
| **BusinessLogicInTwigAnalyzer** | `templates/` | Logique metier dans les templates |

### Module Deployment (4 analyzers)

| Analyzer | Cible | Exemples de detection |
|---|---|---|
| **MigrationStatusAnalyzer** | `src/Migrations/` | Migrations non executees |
| **RequiredEnvVarsAnalyzer** | `.env` | Variables d'environnement requises manquantes |
| **AssetsAnalyzer** | `public/` | Assets non compiles ou obsoletes |
| **LogAnalyzer** | `monolog.yaml` | Level debug en prod, absence de rotation |

### Module Migration (3 analyzers)

| Analyzer | Cible | Exemples de detection |
|---|---|---|
| **DeprecationUsageAnalyzer** | `src/` | Usages de code deprecie |
| **BundleDependencyAnalyzer** | `composer.json` | Bundles abandonnes ou incompatibles |
| **PhpVersionAnalyzer** | `composer.json` | Version PHP non supportee |

### Module Tests (3 analyzers)

| Analyzer | Cible | Exemples de detection |
|---|---|---|
| **TestCoverageAnalyzer** | `tests/` | Couverture insuffisante |
| **SecurityTestAnalyzer** | `tests/` | Tests de securite manquants pour les routes protegees |
| **TestFixtureAnalyzer** | `tests/` | Fixtures absentes ou mal structurees |

---

## Prerequis

- PHP 8.2 ou superieur
- Un projet Symfony 6.4 ou 7.x

---

## Installation

```bash
composer require --dev pierre-arthur/sf-doctor
```

---

## Utilisation

### En tant que bundle Symfony (recommande)

Si votre projet utilise Symfony Flex, le bundle est enregistre automatiquement.

Sinon, ajoutez-le dans `config/bundles.php` :
```php
return [
    // ...
    PierreArthur\SfDoctor\SfDoctorBundle::class => ['dev' => true, 'test' => true],
];
```

### Commandes disponibles

#### `sf-doctor:audit` — Audit standard

```bash
# Audit complet (tous les modules)
bin/console sf-doctor:audit

# Audit d'un module specifique
bin/console sf-doctor:audit --security
bin/console sf-doctor:audit --architecture
bin/console sf-doctor:audit --doctrine
bin/console sf-doctor:audit --messenger
bin/console sf-doctor:audit --api-platform
bin/console sf-doctor:audit --twig
bin/console sf-doctor:audit --deployment
bin/console sf-doctor:audit --migration
bin/console sf-doctor:audit --tests

# Formats de sortie
bin/console sf-doctor:audit --format=json
bin/console sf-doctor:audit --format=console --brief

# Mode diff (comparer avec une baseline)
bin/console sf-doctor:audit --save-baseline=baseline.json
bin/console sf-doctor:audit --diff=baseline.json

# Surveillance en temps reel
bin/console sf-doctor:audit --watch

# Mode asynchrone via Messenger
bin/console sf-doctor:audit --async
```

#### `sf-doctor:full-audit` — Rapport complet avec dette technique

```bash
# Rapport detaille tous modules + estimation financiere
bin/console sf-doctor:full-audit

# Avec TJM personnalise (defaut : 500 EUR/jour)
bin/console sf-doctor:full-audit --tjm=600

# Export SARIF pour GitHub Code Scanning
bin/console sf-doctor:full-audit --format=sarif
```

#### `sf-doctor:fix` — Corrections interactives

```bash
# Mode interactif : propose et applique les corrections une par une
bin/console sf-doctor:fix

# Voir les corrections sans les appliquer
bin/console sf-doctor:fix --dry-run

# Appliquer toutes les corrections automatiquement
bin/console sf-doctor:fix --auto
```

#### `sf-doctor:install-hooks` — Hook pre-commit Git

```bash
# Installer le hook (lance SF-Doctor avant chaque commit)
bin/console sf-doctor:install-hooks

# Desinstaller le hook
bin/console sf-doctor:install-hooks --uninstall
```

### En mode standalone (sans bundle)

SF-Doctor peut aussi fonctionner en dehors d'un projet Symfony :
```bash
vendor/bin/sf-doctor /chemin/vers/le/projet/symfony
```

---

## Integration CI/CD

### GitHub Action officielle

```yaml
# .github/workflows/audit.yaml
name: SF-Doctor Audit

on: [push, pull_request]

jobs:
  audit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: sf-doctor/sf-doctor-action@v1
        with:
          format: sarif
          modules: --all
          fail-on-critical: true
          upload-sarif: true
```

L'action GitHub upload automatiquement le rapport SARIF vers GitHub Code Scanning, ce qui
affiche les issues directement dans l'onglet "Security" du repository.

Inputs disponibles :

| Input | Default | Description |
|---|---|---|
| `format` | `sarif` | Format de sortie (`console`, `json`, `sarif`) |
| `modules` | `--all` | Modules a auditer |
| `fail-on-critical` | `true` | Echoue si des issues CRITICAL |
| `upload-sarif` | `true` | Upload vers GitHub Code Scanning |
| `php-version` | `8.3` | Version PHP |
| `baseline` | | Chemin baseline pour mode diff |

### Integration manuelle

```yaml
# .github/workflows/ci.yaml
- name: Audit Symfony
  run: |
    OUTPUT=$(bin/console sf-doctor:audit --format=json)
    STATUS=$(echo $OUTPUT | jq -r '.summary.status')
    if [ "$STATUS" = "critical" ]; then exit 1; fi
```

---

## Exemple de sortie console

```
 SF-Doctor - Rapport d'audit
 ============================

 Projet : /var/www/mon-projet
 Issues trouvees : 3

 Module Security
 ----------------

 ---------- ------------------- --------------------------------------------- ---------------------------
  Severite   Analyzer            Message                                        Fichier
 ---------- ------------------- --------------------------------------------- ---------------------------
  CRITICAL   FirewallAnalyzer    No authenticator configured on firewall main   config/packages/security.yaml
  WARNING    CsrfAnalyzer        CSRF disabled on CheckoutType                  src/Form/CheckoutType.php
 ---------- ------------------- --------------------------------------------- ---------------------------

  > Impact metier : Un attaquant peut soumettre des formulaires au nom d'un utilisateur
  > Correction :    Retirer 'csrf_protection' => false du FormType
  > Documentation : https://symfony.com/doc/current/security/csrf.html
  > Temps estime :  15 min

 Module Configuration
 ---------------------

 ---------- -------------------- ----------------------- -----------
  Severite   Analyzer             Message                 Fichier
 ---------- -------------------- ----------------------- -----------
  CRITICAL   DebugModeAnalyzer    APP_DEBUG is true       .env.prod
 ---------- -------------------- ----------------------- -----------

 Score : 70/100
 Temps total de remediation estime : 2h30
```

## Exemple de sortie JSON

```bash
bin/console sf-doctor:audit --format=json
```
```json
{
    "meta": {
        "generated_at": "2026-03-25T10:30:00+00:00",
        "project_path": "/var/www/mon-projet"
    },
    "summary": {
        "score": 70,
        "status": "critical",
        "issues_count": {
            "total": 3,
            "critical": 2,
            "warning": 1,
            "suggestion": 0
        }
    },
    "issues": [
        {
            "severity": "critical",
            "module": "security",
            "analyzer": "FirewallAnalyzer",
            "message": "No authenticator configured on firewall main",
            "detail": "...",
            "suggestion": "Add form_login or custom_authenticator",
            "file": "config/packages/security.yaml",
            "line": null,
            "fix_code": "firewalls:\n    main:\n        form_login:\n            login_path: /login",
            "doc_url": "https://symfony.com/doc/current/security.html",
            "business_impact": "Un attaquant peut acceder aux pages protegees",
            "estimated_fix_minutes": 15
        }
    ]
}
```

---

## Features

### Auto-detection du contexte

SF-Doctor detecte automatiquement les composants installes (Doctrine, Messenger, API Platform,
Twig, etc.) via `composer.json` et `composer.lock`. Seuls les analyzers pertinents sont
executes — zero configuration necessaire.

### Score multi-dimensions

Le `ScoreEngine` calcule un score par dimension (securite, architecture, performance, doctrine,
messenger, etc.) avec des poids differencies. Chaque issue deduit des points selon sa severite :

| Severite | Penalite |
|---|---|
| CRITICAL | -10 points |
| WARNING | -3 points |
| SUGGESTION | -1 point |

### Dette technique

Le `TechnicalDebtCalculator` estime le temps de remediation en heures et le cout financier
selon un TJM configurable. Utilisable via `sf-doctor:full-audit --tjm=600`.

### Mode diff et baseline

Sauvegardez une baseline et comparez les analyses suivantes pour ne voir que les regressions :
```bash
bin/console sf-doctor:audit --save-baseline=baseline.json
# ... apres des modifications ...
bin/console sf-doctor:audit --diff=baseline.json
```

### Mode watch

Surveillez vos fichiers en temps reel pendant le developpement :
```bash
bin/console sf-doctor:audit --watch
```
SF-Doctor surveille `src/`, `config/` et `templates/`, et relance l'audit a chaque modification.

### Cache

Les resultats sont mis en cache par hash SHA256 des fichiers de configuration. Si rien n'a
change, l'audit est instantane.

### Events

SF-Doctor dispatche des events Symfony a chaque etape de l'analyse :
- `AnalysisStartedEvent`
- `IssueFoundEvent`
- `ModuleCompletedEvent`
- `AnalysisCompletedEvent`

### Workflow

Le cycle de vie de l'analyse suit une state machine Symfony :
`pending` → `running` → `completed` / `failed`

---

## Architecture

SF-Doctor est concu comme un bundle Symfony extensible. Chaque verification est un
**Analyzer** independant qui implemente `AnalyzerInterface`.

```
src/
├── Analyzer/
│   ├── AnalyzerInterface.php
│   ├── Security/               # 16 analyzers
│   ├── Architecture/           # 7 analyzers
│   ├── Configuration/          # 5 analyzers
│   ├── Performance/            # 1 analyzer
│   ├── Doctrine/               # 5 analyzers
│   ├── Messenger/              # 4 analyzers
│   ├── ApiPlatform/            # 4 analyzers
│   ├── Twig/                   # 3 analyzers
│   ├── Deployment/             # 4 analyzers
│   ├── Migration/              # 3 analyzers
│   └── Tests/                  # 3 analyzers
├── Cache/                      # ResultCache par hash SHA256
├── Command/                    # audit, fix, full-audit, install-hooks
├── Config/                     # YamlConfigReader, ParameterResolver
├── Context/                    # ProjectContext, auto-detection
├── DependencyInjection/        # Extension, CompilerPass, Configuration (TreeBuilder)
├── Diff/                       # AuditReportDiff, BaselineStorage
├── Event/                      # 4 events custom
├── EventSubscriber/            # ProgressSubscriber, CacheSubscriber
├── Git/                        # PreCommitHook
├── Message/                    # RunAnalyzerMessage (mode --async)
├── Model/                      # Issue, AuditReport, Severity, Module
├── Report/                     # Console, JSON, PDF, SARIF
├── Score/                      # ScoreEngine, TechnicalDebtCalculator
├── Serializer/                 # IssueNormalizer, AuditReportNormalizer
├── Watch/                      # FileWatcher (mode --watch)
└── Workflow/                   # AuditContext, AuditWorkflow (state machine)
```

### Creer un analyzer custom

Implementez `AnalyzerInterface` et le tag `sf_doctor.analyzer` est ajoute automatiquement
via autoconfigure :

```php
use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;

class MonAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private ConfigReaderInterface $configReader,
    ) {
    }

    public function getName(): string
    {
        return 'Mon Analyzer';
    }

    public function getModule(): Module
    {
        return Module::ARCHITECTURE;
    }

    public function supports(ProjectContext $context): bool
    {
        return true;
    }

    public function analyze(AuditReport $report): void
    {
        // Votre logique d'analyse ici
    }
}
```

Aucune configuration supplementaire necessaire. SF-Doctor detecte et execute automatiquement
tous les services qui implementent `AnalyzerInterface`.

### Configuration du bundle

SF-Doctor est configurable via `config/packages/sf_doctor.yaml` :

```yaml
sf_doctor:
    score_threshold: 0          # Score minimum (0-100), exit 1 si en dessous
    analyzers:
        security: true
        architecture: true
        performance: true
```

---

## Tests

```bash
# Lancer tous les tests (735+)
vendor/bin/phpunit

# Analyse statique (level 8)
vendor/bin/phpstan analyse src --level=8
```

---

## Licence

MIT. Voir le fichier [LICENSE](LICENSE).
