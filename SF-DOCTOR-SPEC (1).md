# SF-DOCTOR — Spécification Technique & Roadmap

> Outil CLI d'audit automatisé pour projets Symfony.
> Analyse la sécurité, l'architecture, la performance et la compatibilité de migration.

---

## 1. Vision Produit

### Le problème

Les outils existants (PHPStan, Psalm, PHP CS Fixer) analysent le **code PHP générique**. SymfonyInsight (SensioLabs) est **payant et cloud-only**. Aucun outil open source n'audite spécifiquement :

- La **configuration Symfony** (security.yaml, services.yaml, doctrine.yaml)
- Les **patterns architecturaux** propres à Symfony (code métier dans les controllers, injection du container, absence de Voters)
- La **surface de sécurité** Symfony (firewalls troués, routes sans contrôle d'accès, CSRF désactivé)
- La **compatibilité de migration** entre versions (configs dépréciées, bundles abandonnés)

### La cible

| Segment | Besoin | Pricing |
|---|---|---|
| Freelances / petites agences | Rapport d'audit à livrer au client | Gratuit (CLI) → Payant (PDF) |
| Lead devs / CTOs | Qualité continue en CI/CD | Gratuit (JSON) → Payant (dashboard) |
| Entreprises qui héritent d'un projet | Évaluer un projet inconnu | Payant (module Upgrade) |

### Le modèle économique

- **Open source gratuit** : modules Security, Architecture, Performance (basique), output console + JSON
- **Payant (licence ~10-30€/mois/projet)** : module Upgrade, Performance avancé, rapports PDF, dashboard web, intégration CI/CD avancée

---

## 2. Installation cible

```bash
composer require --dev sf-doctor/sf-doctor
bin/console sf-doctor:audit
bin/console sf-doctor:audit --security
bin/console sf-doctor:audit --architecture
bin/console sf-doctor:audit --performance
bin/console sf-doctor:audit --all --format=json
bin/console sf-doctor:upgrade --target=7.0
```

---

## 3. Architecture

### 3.1 Structure des dossiers

```
sf-doctor/
├── src/
│   ├── SfDoctorBundle.php                      # Point d'entrée du bundle
│   │
│   ├── Analyzer/                               # Cœur métier : les analyseurs
│   │   ├── AnalyzerInterface.php               # Contrat commun
│   │   ├── AbstractAnalyzer.php                # Helpers partagés (lecture config, parsing)
│   │   │
│   │   ├── Security/                           # Module Security
│   │   │   ├── FirewallAnalyzer.php            # Parse security.yaml, détecte les firewalls vides
│   │   │   ├── AccessControlAnalyzer.php       # Croise routes ↔ access_control ↔ #[IsGranted]
│   │   │   ├── CsrfAnalyzer.php               # Vérifie la config CSRF sur chaque FormType
│   │   │   ├── SensitiveDataAnalyzer.php       # Détecte les champs sensibles exposés en sérialisation
│   │   │   ├── DebugModeAnalyzer.php           # Vérifie que debug=false en prod
│   │   │   └── RememberMeAnalyzer.php          # Vérifie les flags secure/httpOnly du cookie
│   │   │
│   │   ├── Architecture/                       # Module Architecture
│   │   │   ├── ControllerAnalyzer.php          # Détecte QueryBuilder/DQL dans les controllers
│   │   │   ├── ServiceInjectionAnalyzer.php    # Détecte l'injection de ContainerInterface
│   │   │   ├── RepositoryPatternAnalyzer.php   # Vérifie que la logique DB est dans les repos
│   │   │   ├── VoterUsageAnalyzer.php          # Détecte les checks de rôles manuels vs Voters
│   │   │   └── PublicServiceAnalyzer.php       # Détecte les services public: true inutiles
│   │   │
│   │   └── Performance/                        # Module Performance
│   │       ├── EagerLoadingAnalyzer.php         # Détecte les EAGER sur les relations lourdes
│   │       ├── CacheUsageAnalyzer.php           # Détecte l'absence de cache sur les queries lourdes
│   │       ├── MessengerUsageAnalyzer.php       # Suggère Messenger pour les opérations longues
│   │       └── NplusOneAnalyzer.php             # Détecte les patterns N+1 potentiels (payant)
│   │
│   ├── Model/                                  # Modèles de données
│   │   ├── Issue.php                           # Un problème détecté (severity, message, file, line, fix)
│   │   ├── AuditReport.php                     # Collection d'Issues + métadonnées (date, durée, score)
│   │   ├── Severity.php                        # Enum : CRITICAL, WARNING, SUGGESTION, OK
│   │   └── Module.php                          # Enum : SECURITY, ARCHITECTURE, PERFORMANCE, UPGRADE
│   │
│   ├── Report/                                 # Génération des rapports (Strategy pattern)
│   │   ├── ReporterInterface.php
│   │   ├── ConsoleReporter.php                 # Output terminal coloré (SymfonyStyle)
│   │   ├── JsonReporter.php                    # Pour CI/CD et intégrations
│   │   └── PdfReporter.php                     # Pour les livrables clients (payant)
│   │
│   ├── Config/                                 # Lecture et parsing de la config Symfony
│   │   ├── ConfigReaderInterface.php
│   │   ├── YamlConfigReader.php                # Lit et parse les fichiers YAML du projet audité
│   │   ├── RouteCollector.php                  # Collecte toutes les routes (via le Router)
│   │   └── EntityCollector.php                 # Collecte les entités et leurs mappings Doctrine
│   │
│   ├── Workflow/                               # Cycle de vie d'une analyse
│   │   └── AuditWorkflow.php                   # Définition : pending → running → done / failed
│   │
│   ├── Event/                                  # Événements custom
│   │   ├── AnalysisStartedEvent.php
│   │   ├── ModuleCompletedEvent.php
│   │   ├── IssueFoundEvent.php
│   │   └── AnalysisCompletedEvent.php
│   │
│   ├── EventSubscriber/
│   │   ├── ProgressSubscriber.php              # Affiche la progression en console
│   │   └── CacheSubscriber.php                 # Met en cache les résultats par fichier hash
│   │
│   ├── Command/                                # Commandes CLI
│   │   ├── AuditCommand.php                    # sf-doctor:audit (commande principale)
│   │   └── UpgradeCommand.php                  # sf-doctor:upgrade (payant)
│   │
│   ├── Message/                                # Messenger (mode async)
│   │   ├── RunAnalyzerMessage.php              # Message pour lancer un analyzer
│   │   └── RunAnalyzerMessageHandler.php       # Handler
│   │
│   ├── DependencyInjection/
│   │   ├── SfDoctorExtension.php               # Charge la config du bundle
│   │   ├── Configuration.php                   # TreeBuilder pour sf_doctor.yaml
│   │   └── Compiler/
│   │       └── AnalyzerCompilerPass.php        # Collecte les services tagués "sf_doctor.analyzer"
│   │
│   └── Serializer/
│       └── AuditReportNormalizer.php           # Normalizer custom pour l'export
│
├── config/
│   ├── services.yaml                           # Déclaration des services du bundle
│   └── workflow.yaml                           # Définition du workflow d'analyse
│
├── tests/
│   ├── Unit/
│   │   ├── Analyzer/Security/
│   │   │   ├── FirewallAnalyzerTest.php
│   │   │   └── AccessControlAnalyzerTest.php
│   │   ├── Model/
│   │   │   └── IssueTest.php
│   │   └── Report/
│   │       └── JsonReporterTest.php
│   ├── Integration/
│   │   ├── DependencyInjection/
│   │   │   └── SfDoctorExtensionTest.php       # KernelTestCase
│   │   └── Command/
│   │       └── AuditCommandTest.php            # WebTestCase (fonctionnel)
│   └── Fixtures/
│       ├── valid-project/                      # Projet Symfony valide pour les tests
│       ├── insecure-project/                   # Projet avec des failles volontaires
│       └── messy-project/                      # Projet avec des anti-patterns
│
├── composer.json
├── LICENSE                                     # MIT
├── README.md
├── CHANGELOG.md
├── CONTRIBUTING.md
├── Makefile                                    # make test, make lint, make audit
├── .github/
│   └── workflows/
│       ├── ci.yaml                             # Tests + PHPStan + CS Fixer
│       └── release.yaml                        # Tag → Packagist
└── docs/
    ├── installation.md
    ├── analyzers.md                            # Comment créer un analyzer custom
    └── ci-integration.md                       # GitHub Actions, GitLab CI
```

### 3.2 Composants Symfony utilisés (= programme certification)

| Composant | Utilisation dans sf-doctor | Sujet certif |
|---|---|---|
| **Console** | AuditCommand, options, ProgressBar, SymfonyStyle | Console |
| **DependencyInjection** | Extension, CompilerPass, tagged iterator, autoconfigure | DI |
| **HttpKernel** | Bundle, Extension, événements kernel | Architecture |
| **EventDispatcher** | Events custom (AnalysisStarted, IssueFound, etc.) | Events |
| **Messenger** | Mode async : RunAnalyzerMessage dispatché par analyzer | Messenger |
| **Workflow** | Cycle de vie de l'analyse (pending→running→done/failed) | Workflow |
| **Serializer** | Export JSON/XML du rapport, Normalizer custom | Serializer |
| **Validator** | Validation des options de config du bundle | Validation |
| **Cache** | Cache des résultats par hash de fichier | Cache |
| **Yaml** | Parsing des fichiers config du projet audité | Config |
| **Finder** | Recherche des fichiers PHP, YAML, entités | Filesystem |
| **Filesystem** | Écriture des rapports | Filesystem |
| **Config** | TreeBuilder pour la config du bundle | Config |
| **Security** | Analyse du firewall, voters (on parse la config sécu) | Security |
| **Routing** | Collecte et analyse des routes | Routing |
| **Form** | Analyse de la config CSRF des FormTypes | Forms |
| **Doctrine ORM** | Analyse des mappings, relations, loading strategy | Doctrine |

---

## 4. Modèles de données

### 4.1 Issue.php

```php
<?php

namespace SfDoctor\Model;

final class Issue
{
    public function __construct(
        private Severity $severity,
        private Module $module,
        private string $analyzer,       // ex: "FirewallAnalyzer"
        private string $message,        // ex: "Firewall 'main' has no access_control rules"
        private string $detail,         // Explication détaillée
        private string $suggestion,     // ex: "Add access_control rules in security.yaml"
        private ?string $file = null,   // ex: "config/packages/security.yaml"
        private ?int $line = null,
    ) {}

    // Getters...
}
```

### 4.2 Severity.php

```php
<?php

namespace SfDoctor\Model;

enum Severity: string
{
    case CRITICAL = 'critical';     // Faille de sécurité, crash en prod
    case WARNING = 'warning';       // Anti-pattern, dette technique
    case SUGGESTION = 'suggestion'; // Amélioration possible
    case OK = 'ok';                 // Check passé
}
```

### 4.3 AuditReport.php

```php
<?php

namespace SfDoctor\Model;

final class AuditReport
{
    /** @var list<Issue> */
    private array $issues = [];
    private \DateTimeImmutable $startedAt;
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        private string $projectPath,
        private array $modules,     // Modules analysés
    ) {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function addIssue(Issue $issue): void
    {
        $this->issues[] = $issue;
    }

    public function getScore(): int
    {
        // Score sur 100 : 100 = parfait, -10 par critical, -3 par warning, -1 par suggestion
        $score = 100;
        foreach ($this->issues as $issue) {
            $score -= match ($issue->getSeverity()) {
                Severity::CRITICAL => 10,
                Severity::WARNING => 3,
                Severity::SUGGESTION => 1,
                Severity::OK => 0,
            };
        }
        return max(0, $score);
    }

    public function complete(): void
    {
        $this->completedAt = new \DateTimeImmutable();
    }

    // Getters, filtres par module/severity...
}
```

---

## 5. Interfaces clés

### 5.1 AnalyzerInterface.php

```php
<?php

namespace SfDoctor\Analyzer;

use SfDoctor\Model\AuditReport;
use SfDoctor\Model\Module;

interface AnalyzerInterface
{
    /**
     * Exécute l'analyse et ajoute les issues au rapport.
     */
    public function analyze(AuditReport $report): void;

    /**
     * Module auquel appartient cet analyzer.
     */
    public function getModule(): Module;

    /**
     * Nom lisible de l'analyzer (pour les logs/progression).
     */
    public function getName(): string;

    /**
     * Cet analyzer est-il activable dans le contexte courant ?
     * Ex: DoctrineAnalyzer retourne false si Doctrine n'est pas installé.
     */
    public function supports(): bool;
}
```

### 5.2 ReporterInterface.php

```php
<?php

namespace SfDoctor\Report;

use SfDoctor\Model\AuditReport;

interface ReporterInterface
{
    public function generate(AuditReport $report): void;

    /**
     * Format supporté : "console", "json", "pdf"
     */
    public function getFormat(): string;
}
```

---

## 6. Le Compiler Pass — collecte des analyzers

```php
<?php

namespace SfDoctor\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class AnalyzerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has('sf_doctor.audit_runner')) {
            return;
        }

        $definition = $container->findDefinition('sf_doctor.audit_runner');

        // Collecte tous les services tagués "sf_doctor.analyzer"
        $taggedServices = $container->findTaggedServiceIds('sf_doctor.analyzer');

        foreach ($taggedServices as $id => $tags) {
            // Injecte chaque analyzer dans le runner
            $definition->addMethodCall('addAnalyzer', [new Reference($id)]);
        }
    }
}
```

Grâce à **autoconfigure**, chaque classe implémentant `AnalyzerInterface` reçoit automatiquement le tag `sf_doctor.analyzer`. On peut aussi utiliser l'approche moderne avec `#[TaggedIterator]` :

```php
// Alternative moderne sans compiler pass explicite (Symfony 6.1+)
class AuditRunner
{
    public function __construct(
        #[TaggedIterator('sf_doctor.analyzer')]
        private iterable $analyzers,
    ) {}
}
```

> **Les deux approches sont implémentées** : le compiler pass dans le bundle (pour la compatibilité et pour montrer le concept), et le TaggedIterator dans le runner (pour la version moderne). La certification couvre les deux.

---

## 7. La commande principale

```php
<?php

namespace SfDoctor\Command;

use SfDoctor\Model\Module;
use SfDoctor\Model\AuditReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sf-doctor:audit',
    description: 'Audite un projet Symfony (sécurité, architecture, performance)',
)]
class AuditCommand extends Command
{
    public function __construct(
        private AuditRunner $runner,
        private iterable $reporters,    // TaggedIterator des reporters
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('security', 's', InputOption::VALUE_NONE, 'Audit sécurité uniquement')
            ->addOption('architecture', 'a', InputOption::VALUE_NONE, 'Audit architecture uniquement')
            ->addOption('performance', 'p', InputOption::VALUE_NONE, 'Audit performance uniquement')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Tous les modules (défaut)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format de sortie', 'console')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Exécuter les analyzers via Messenger')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SF Doctor — Audit en cours');

        // Détermine les modules à analyser
        $modules = $this->resolveModules($input);

        // Crée le rapport
        $report = new AuditReport(
            projectPath: $this->runner->getProjectDir(),
            modules: $modules,
        );

        // Lance l'analyse
        $this->runner->run($report, $modules, $input->getOption('async'));

        // Génère le rapport dans le format demandé
        $format = $input->getOption('format');
        foreach ($this->reporters as $reporter) {
            if ($reporter->getFormat() === $format) {
                $reporter->generate($report);
                break;
            }
        }

        // Exit code basé sur la sévérité
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        if (count($criticals) > 0) {
            return Command::FAILURE; // Exit 1 → bloque la CI
        }

        return Command::SUCCESS;
    }

    private function resolveModules(InputInterface $input): array
    {
        if ($input->getOption('security')) return [Module::SECURITY];
        if ($input->getOption('architecture')) return [Module::ARCHITECTURE];
        if ($input->getOption('performance')) return [Module::PERFORMANCE];
        return Module::cases(); // --all par défaut
    }
}
```

---

## 8. Exemple d'Analyzer concret — FirewallAnalyzer

```php
<?php

namespace SfDoctor\Analyzer\Security;

use SfDoctor\Analyzer\AnalyzerInterface;
use SfDoctor\Config\YamlConfigReader;
use SfDoctor\Model\AuditReport;
use SfDoctor\Model\Issue;
use SfDoctor\Model\Module;
use SfDoctor\Model\Severity;

class FirewallAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private YamlConfigReader $configReader,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $security = $this->configReader->read('config/packages/security.yaml');

        if ($security === null) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'Fichier security.yaml introuvable',
                detail: 'Aucune configuration de sécurité détectée dans le projet.',
                suggestion: 'Exécuter: composer require symfony/security-bundle',
            ));
            return;
        }

        $firewalls = $security['security']['firewalls'] ?? [];
        $accessControl = $security['security']['access_control'] ?? [];

        foreach ($firewalls as $name => $config) {
            // Ignorer le firewall "dev" (c'est normal qu'il soit ouvert)
            if ($name === 'dev') {
                continue;
            }

            // Check 1 : Firewall sans aucun authenticator
            if (!isset($config['custom_authenticator'])
                && !isset($config['form_login'])
                && !isset($config['http_basic'])
                && !isset($config['json_login'])
                && !isset($config['access_token'])
                && $config['security'] !== false
            ) {
                $report->addIssue(new Issue(
                    severity: Severity::WARNING,
                    module: Module::SECURITY,
                    analyzer: $this->getName(),
                    message: "Firewall '{$name}' n'a aucun mécanisme d'authentification",
                    detail: "Le firewall est actif mais aucun authenticator n'est configuré.",
                    suggestion: "Ajouter form_login, json_login, access_token ou un custom_authenticator.",
                    file: 'config/packages/security.yaml',
                ));
            }

            // Check 2 : Firewall principal sans access_control
            if ($name === 'main' && empty($accessControl)) {
                $report->addIssue(new Issue(
                    severity: Severity::CRITICAL,
                    module: Module::SECURITY,
                    analyzer: $this->getName(),
                    message: "Firewall 'main' n'a aucune règle access_control",
                    detail: "Tout utilisateur authentifié peut accéder à toutes les routes sous ce firewall.",
                    suggestion: "Ajouter des règles access_control dans security.yaml.",
                    file: 'config/packages/security.yaml',
                ));
            }

            // Check 3 : Firewall avec lazy: true sans token_storage
            if (isset($config['lazy']) && $config['lazy'] === true) {
                $report->addIssue(new Issue(
                    severity: Severity::OK,
                    module: Module::SECURITY,
                    analyzer: $this->getName(),
                    message: "Firewall '{$name}' utilise le mode lazy (bonne pratique)",
                    detail: "Le token de sécurité n'est chargé que quand c'est nécessaire.",
                    suggestion: '',
                ));
            }
        }
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function getName(): string
    {
        return 'Firewall Analyzer';
    }

    public function supports(): bool
    {
        return class_exists(\Symfony\Bundle\SecurityBundle\SecurityBundle::class);
    }
}
```

---

## 9. Workflow d'analyse

```yaml
# config/workflow.yaml
framework:
    workflows:
        audit:
            type: state_machine
            audit_store: method    # Stocké dans l'objet AuditReport
            marking_store:
                type: method
                property: status
            supports:
                - SfDoctor\Model\AuditReport
            initial_marking: pending
            places:
                - pending
                - running
                - completed
                - failed
            transitions:
                start:
                    from: pending
                    to: running
                finish:
                    from: running
                    to: completed
                fail:
                    from: running
                    to: failed
                retry:
                    from: failed
                    to: pending
```

---

## 10. Messenger — mode async

```php
<?php
// src/Message/RunAnalyzerMessage.php
namespace SfDoctor\Message;

class RunAnalyzerMessage
{
    public function __construct(
        public readonly string $analyzerClass,
        public readonly string $reportId,
    ) {}
}
```

```php
<?php
// src/Message/RunAnalyzerMessageHandler.php
namespace SfDoctor\Message;

use Symfony\Component\DependencyInjection\Attribute\TaggedLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunAnalyzerMessageHandler
{
    public function __construct(
        #[TaggedLocator('sf_doctor.analyzer')]
        private ServiceLocator $analyzers,
        private ReportStore $reportStore,
    ) {}

    public function __invoke(RunAnalyzerMessage $message): void
    {
        $analyzer = $this->analyzers->get($message->analyzerClass);
        $report = $this->reportStore->get($message->reportId);

        $analyzer->analyze($report);

        $this->reportStore->save($report);
    }
}
```

---

## 11. Tests

### 11.1 Test unitaire — FirewallAnalyzerTest

```php
<?php

namespace SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use SfDoctor\Analyzer\Security\FirewallAnalyzer;
use SfDoctor\Config\YamlConfigReader;
use SfDoctor\Model\AuditReport;
use SfDoctor\Model\Module;
use SfDoctor\Model\Severity;

class FirewallAnalyzerTest extends TestCase
{
    public function testDetectsFirewallWithoutAccessControl(): void
    {
        // Arrange : mock du config reader
        $configReader = $this->createMock(YamlConfigReader::class);
        $configReader->method('read')->willReturn([
            'security' => [
                'firewalls' => [
                    'main' => [
                        'lazy' => true,
                        'form_login' => ['login_path' => '/login'],
                    ],
                ],
                // PAS d'access_control → doit trigger un CRITICAL
            ],
        ]);

        $analyzer = new FirewallAnalyzer($configReader);
        $report = new AuditReport('/fake/path', [Module::SECURITY]);

        // Act
        $analyzer->analyze($report);

        // Assert
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('access_control', $criticals[0]->getMessage());
    }

    public function testPassesWithProperConfig(): void
    {
        $configReader = $this->createMock(YamlConfigReader::class);
        $configReader->method('read')->willReturn([
            'security' => [
                'firewalls' => [
                    'main' => [
                        'lazy' => true,
                        'form_login' => ['login_path' => '/login'],
                    ],
                ],
                'access_control' => [
                    ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                ],
            ],
        ]);

        $analyzer = new FirewallAnalyzer($configReader);
        $report = new AuditReport('/fake/path', [Module::SECURITY]);
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(0, $criticals);
    }
}
```

### 11.2 Test fonctionnel — AuditCommandTest

```php
<?php

namespace SfDoctor\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class AuditCommandTest extends KernelTestCase
{
    public function testAuditCommandReturnsSuccessOnCleanProject(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('sf-doctor:audit');
        $tester = new CommandTester($command);

        $tester->execute(['--security' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Audit', $tester->getDisplay());
    }
}
```

---

## 12. CI/CD — GitHub Actions

```yaml
# .github/workflows/ci.yaml
name: CI

on: [push, pull_request]

jobs:
  tests:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.2', '8.3']
        symfony: ['6.4.*', '7.0.*', '7.1.*']

    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: xdebug

      - name: Install dependencies
        run: |
          composer require symfony/framework-bundle:${{ matrix.symfony }} --no-update
          composer install --prefer-dist --no-progress

      - name: PHPStan
        run: vendor/bin/phpstan analyse src --level=8

      - name: PHP CS Fixer
        run: vendor/bin/php-cs-fixer fix --dry-run --diff

      - name: Tests
        run: vendor/bin/phpunit --coverage-text

  # Action réutilisable que d'autres projets peuvent utiliser
  # pour auditer LEUR projet avec sf-doctor
  audit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - run: composer install
      - run: bin/console sf-doctor:audit --all --format=json > audit-report.json
      - uses: actions/upload-artifact@v4
        with:
          name: sf-doctor-report
          path: audit-report.json
```

---

## 13. Roadmap de développement

### V0.1 — MVP (2 semaines)

Le minimum qui apporte déjà de la valeur.

- [ ] Initialiser le projet (`composer init`, structure des dossiers)
- [ ] `AnalyzerInterface` + `AbstractAnalyzer`
- [ ] Modèles : `Issue`, `Severity`, `Module`, `AuditReport`
- [ ] `YamlConfigReader` (parse les fichiers YAML du projet cible)
- [ ] `FirewallAnalyzer` (parse security.yaml)
- [ ] `AccessControlAnalyzer` (croise routes ↔ access_control)
- [ ] `AuditCommand` (commande basique avec `--security`)
- [ ] `ConsoleReporter` (output coloré avec SymfonyStyle)
- [ ] Tests unitaires pour les 2 analyzers
- [ ] README avec installation + screenshot du output
- [ ] CI GitHub Actions (tests + PHPStan)
- [ ] Publication sur Packagist

**Résultat** : `composer require --dev sf-doctor/sf-doctor && bin/console sf-doctor:audit --security` fonctionne.

### V0.2 — Module Security complet (2 semaines)

- [ ] `CsrfAnalyzer` (scanne les FormTypes pour csrf_protection: false)
- [ ] `SensitiveDataAnalyzer` (détecte les champs password/token sans #[Ignore])
- [ ] `DebugModeAnalyzer` (vérifie .env / .env.prod / .env.local)
- [ ] `RememberMeAnalyzer` (flags secure/httpOnly)
- [ ] `JsonReporter` (output JSON pour CI/CD)
- [ ] GitHub Action réutilisable (marketplace)
- [ ] Score global du projet (0-100)
- [ ] Tests pour chaque analyzer

### V0.3 — Module Architecture (2 semaines)

- [ ] `ControllerAnalyzer` (QueryBuilder dans les controllers via AST parsing)
- [ ] `ServiceInjectionAnalyzer` (détecte injection de ContainerInterface)
- [ ] `RepositoryPatternAnalyzer` (logique DB hors des repos)
- [ ] `VoterUsageAnalyzer` (in_array ROLE_* vs Voter)
- [ ] `PublicServiceAnalyzer` (services public: true)
- [ ] Autoconfigure : tout AnalyzerInterface est auto-tagué
- [ ] Compiler pass pour la collecte des analyzers
- [ ] CONTRIBUTING.md (comment ajouter un analyzer custom)

### V0.4 — Module Performance + Events + Cache (2 semaines)

- [ ] `EagerLoadingAnalyzer`
- [ ] `CacheUsageAnalyzer`
- [ ] `MessengerUsageAnalyzer`
- [ ] Events custom (AnalysisStarted, IssueFound, ModuleCompleted)
- [ ] ProgressSubscriber (barre de progression en console)
- [ ] Cache des résultats par hash SHA256 du fichier analysé
- [ ] Option `--async` via Messenger

### V0.5 — Extensibilité + Communauté (2 semaines)

- [ ] Workflow (state machine pour le cycle de vie de l'analyse)
- [ ] Serializer : export du rapport en JSON/XML via le Serializer Symfony
- [ ] Config du bundle via sf_doctor.yaml + TreeBuilder
- [ ] Système de "règles" activables/désactivables dans la config
- [ ] doc/analyzers.md : guide pour créer un analyzer custom
- [ ] Appel aux contributions

### V1.0 — Lancement public (stabilisation)

- [ ] Couverture de tests > 80%
- [ ] PHPStan level 8
- [ ] README complet avec badges, screenshots, exemples
- [ ] Packagist stable tag
- [ ] Post LinkedIn / Twitter pour lancer l'adoption
- [ ] Soumission au SymfonyLive (CFP) ou article blog Symfony

### V1.x — Monétisation (post-lancement)

- [ ] Module Upgrade (analyse de migration entre versions)
- [ ] `NplusOneAnalyzer` (détection des requêtes N+1)
- [ ] `PdfReporter` (rapports PDF livrables)
- [ ] Dashboard web (Symfony app séparée)
- [ ] Licence commerciale pour les features payantes

---

## 14. Commandes utiles pour démarrer

```bash
# Créer le projet
mkdir sf-doctor && cd sf-doctor
composer init --name="sf-doctor/sf-doctor" --type="symfony-bundle" --license="MIT"

# Hook Git obligatoire - a installer immediatement apres git init ou git clone
# Empeche tout outil IA d'apparaitre comme co-auteur dans l'historique Git
cat > .git/hooks/prepare-commit-msg << 'EOF'
#!/bin/sh
grep -viE '^Co-authored-by:.*(claude|anthropic|copilot|openai|chatgpt|gemini|cursor)' "$1" > "$1.tmp" && mv "$1.tmp" "$1"
EOF
chmod +x .git/hooks/prepare-commit-msg

# Dépendances
composer require symfony/console symfony/yaml symfony/finder symfony/dependency-injection
composer require --dev phpunit/phpunit phpstan/phpstan friendsofphp/php-cs-fixer

# Structure
mkdir -p src/{Analyzer/Security,Analyzer/Architecture,Analyzer/Performance}
mkdir -p src/{Model,Report,Config,Command,Event,EventSubscriber,Message}
mkdir -p src/DependencyInjection/Compiler
mkdir -p tests/{Unit/Analyzer/Security,Integration/Command,Fixtures}
mkdir -p config .github/workflows docs

# Lancer les tests
vendor/bin/phpunit
vendor/bin/phpstan analyse src --level=8
```

---

## 15. Récap : ce que ce projet prouve

Quand tu te présentes en entretien avec ce projet sur GitHub :

| Question d'entretien | Tu réponds en montrant |
|---|---|
| "C'est quoi l'autowiring ?" | Tes analyzers sont auto-injectés via type-hints |
| "C'est quoi autoconfigure ?" | Tes AnalyzerInterface sont auto-tagués |
| "C'est quoi un compiler pass ?" | `AnalyzerCompilerPass.php` — tu l'as écrit |
| "Expliquer le cycle de vie HTTP ?" | Pas directement, mais tu connais les events kernel |
| "Messenger ?" | Mode `--async` avec `RunAnalyzerMessage` |
| "Workflow ?" | State machine de l'analyse |
| "Serializer ?" | Export JSON/XML du rapport |
| "Tests ?" | Unit + Integration, fixtures, 80%+ coverage |
| "Cache ?" | Cache par hash de fichier |
| "Security ?" | Tu l'**analyses**, tu connais tout le modèle |
| "Forms ?" | Tu analyses la config CSRF des FormTypes |
| "Routing ?" | Tu collectes et analyses les routes |
| "DI container ?" | Extension, CompilerPass, TaggedIterator, ServiceLocator |

**Le code EST la preuve. Pas besoin de l'expliquer avec des mots — il suffit de le montrer.**
