# Suivi SF-Doctor - Avancement du projet

## V1.8.0 PUBLIEE - Tag v1.8.0 sur Packagist

## En cours : V1.9.0 - Mode developpement continu

## Prochain objectif prioritaire : V2.6.0 - Module API Platform Analyzer

> Raison : killer feature CV. Aucun outil open source n'analyse la configuration API Platform.
> Ce module seul justifie l'adoption de SF-Doctor par toute agence qui fait de l'API.
> Voir Phase 25 dans la roadmap.

---

## SETUP OBLIGATOIRE - Hook Git anti-co-auteur IA

A installer sur toute machine travaillant sur ce projet, et a reinstaller apres chaque `git clone` :

```bash
cat > .git/hooks/prepare-commit-msg << 'EOF'
#!/bin/sh
grep -viE '^Co-authored-by:.*(claude|anthropic|copilot|openai|chatgpt|gemini|cursor)' "$1" > "$1.tmp" && mv "$1.tmp" "$1"
EOF
chmod +x .git/hooks/prepare-commit-msg
```

Verifier avant chaque session : `cat .git/hooks/prepare-commit-msg`

---

## Phase 0 - Setup du projet [TERMINEE]

- [x] 0.1 Creer le projet Composer (composer.json)
- [x] 0.2 Structure de dossiers
- [x] 0.3 Configurer PHPUnit (phpunit.xml.dist)
- [x] 0.4 Configurer PHPStan (phpstan.neon, level 8)
- [x] 0.5 Premier commit Git + .gitignore

## Phase 1 - Les fondations (Modeles) [TERMINEE]

- [x] 1.1 Enum Severity (src/Model/Severity.php)
- [x] 1.2 Enum Module (src/Model/Module.php)
- [x] 1.3 Classe Issue (src/Model/Issue.php) - readonly, constructeur promu
- [x] 1.4 Classe AuditReport (src/Model/AuditReport.php) - score, filtres, cycle de vie
- [x] 1.5a Tests IssueTest (tests/Unit/Model/IssueTest.php)
- [x] 1.5b Tests AuditReportTest (tests/Unit/Model/AuditReportTest.php)

## Phase 2 - Lecture de configuration [TERMINEE]

- [x] 2.1 ConfigReaderInterface (src/Config/ConfigReaderInterface.php)
- [x] 2.2 YamlConfigReader (src/Config/YamlConfigReader.php)
- [x] 2.3 Tests YamlConfigReaderTest (tests/Unit/Config/YamlConfigReaderTest.php)
  - Fixtures creees : valid-project/config/packages/security.yaml, empty.yaml, truly-empty.yaml

## Phase 3 - Les Analyzers [TERMINEE]

- [x] 3.1 AnalyzerInterface (src/Analyzer/AnalyzerInterface.php)
- [x] 3.2 FirewallAnalyzer (src/Analyzer/Security/FirewallAnalyzer.php)
  - 3 checks : authenticator, access_control, lazy mode
- [x] 3.3 Tests FirewallAnalyzerTest (tests/Unit/Analyzer/Security/FirewallAnalyzerTest.php)
- [x] 3.4 AccessControlAnalyzer (src/Analyzer/Security/AccessControlAnalyzer.php)
  - 4 checks : roles manquants, roles deprecies, ordre catch-all, chemins sensibles
- [x] 3.5 Tests AccessControlAnalyzerTest (tests/Unit/Analyzer/Security/AccessControlAnalyzerTest.php)

## Phase 4 - Le Reporter [TERMINEE]

- [x] 4.1 ReporterInterface (src/Report/ReporterInterface.php)
- [x] 4.2 ConsoleReporter (src/Report/ConsoleReporter.php) - SymfonyStyle, tableaux, couleurs
- [x] 4.3 Tests ConsoleReporterTest (tests/Unit/Report/ConsoleReporterTest.php) - BufferedOutput

## Phase 5 - La commande CLI [TERMINEE]

- [x] 5.1 AuditCommand (src/Command/AuditCommand.php) - #[AsCommand], configure, execute
- [x] 5.2 Cablage manuel (bin/sf-doctor) - script executeur avec injection manuelle
- [x] 5.3 Tests AuditCommandTest (tests/Unit/Command/AuditCommandTest.php) - 7 tests, CommandTester

## Phase 6 - Le Bundle Symfony [TERMINEE]

- [x] 6.1 SfDoctorBundle.php (src/SfDoctorBundle.php) - extends Bundle
- [x] 6.2 SfDoctorExtension.php (src/DependencyInjection/SfDoctorExtension.php) - charge services.yaml
- [x] 6.3 services.yaml du bundle (config/services.yaml) - services public, tagged_iterator
- [x] 6.4 Autoconfigure pour AnalyzerInterface (dans SfDoctorBundle::build)
- [x] 6.5 AnalyzerCompilerPass (src/DependencyInjection/Compiler/AnalyzerCompilerPass.php)
- [x] 6.6 Tests d'integration avec KernelTestCase
  - TestKernel (tests/Integration/TestKernel.php) - MicroKernelTrait
  - SfDoctorBundleTest (tests/Integration/SfDoctorBundleTest.php) - 5 tests

## Phase 7 - Finalisation V0.1 [TERMINEE]

- [x] 7.1 README.md - badges, installation, exemple de sortie, architecture, roadmap
- [x] 7.2 CI GitHub Actions (.github/workflows/ci.yaml) - matrice PHP 8.2/8.3 x Symfony 6.4/7.1
- [x] 7.3 Publication Packagist (pierre-arthur/sf-doctor)
- [x] 7.4 Tag v0.1.0 - paquet installable via `composer require`

---

## Phase 8 - V0.2 : Resolution des parametres + nouveaux analyzers [TERMINEE]

### ParameterResolver [TERMINE]

- [x] 8.1 ParameterResolverInterface (src/Config/ParameterResolverInterface.php)
- [x] 8.2 ContainerParameterResolver (src/Config/ContainerParameterResolver.php)
  - Recoit ParameterBagInterface du container via "@parameter_bag"
  - Methode resolveArray(array $config): array - remplace tous les %param% recursivement
  - Methode resolveString(string $value): string
  - NullParameterResolver (src/Config/NullParameterResolver.php) - no-op pour mode standalone
- [x] 8.3 Injecter le ParameterResolver dans AuditCommand
  - En mode bundle : ContainerParameterResolver via services.yaml
  - En mode standalone : NullParameterResolver injecte manuellement
- [x] 8.4 Modifier les analyzers pour utiliser la config resolue
  - FirewallAnalyzer et AccessControlAnalyzer resolvent la config avant analyse
- [x] 8.5 Tests ParameterResolverTest
  - NullParameterResolverTest (4 tests)
  - ContainerParameterResolverTest (8 tests) - willReturnMap, ParameterNotFoundException

### Nouveaux analyzers [TERMINE]

- [x] 8.6 CsrfAnalyzer (src/Analyzer/Security/CsrfAnalyzer.php)
  - Check 1 : csrf_protection: false global dans framework.yaml (CRITICAL)
  - Check 2 : 'csrf_protection' => false dans les FormType PHP de src/Form/ (WARNING)
  - supports() verifie class_exists(AbstractType::class)
  - Tests : CsrfAnalyzerTest (13 tests) - filesystem temporaire avec sys_get_temp_dir()
- [x] 8.7 ControllerAnalyzer (src/Analyzer/Architecture/ControllerAnalyzer.php)
  - Check 1 : createQueryBuilder() et createQuery() dans src/Controller/ (CRITICAL)
  - Check 2 : methodes non-acceptables de l'EntityManager ($this->em->, $this->entityManager->) (WARNING)
  - Methodes tolerees : persist, flush, remove, find, getReference, clear
  - supports() verifie class_exists(EntityManagerInterface::class)
  - Tests : ControllerAnalyzerTest (8 tests) - filesystem temporaire
- [x] 8.8 DebugModeAnalyzer (src/Analyzer/Configuration/DebugModeAnalyzer.php)
  - Check 1 : APP_ENV absent ou different de "prod" (CRITICAL/WARNING)
  - Check 2 : APP_DEBUG=true ou APP_DEBUG=1 (CRITICAL)
  - Lit .env.prod en priorite, fallback sur .env
  - supports() verifie la presence des fichiers .env / .env.prod
  - Tests : DebugModeAnalyzerTest (12 tests) - filesystem temporaire
- [x] 8.9 JsonReporter (src/Report/JsonReporter.php)
  - Implemente ReporterInterface (generate, getFormat)
  - Structure : meta (generated_at, project_path), summary (score, status, issues_count), issues
  - Status base sur la presence de CRITICAL (pas sur le score numerique)
  - Tests : JsonReporterTest (11 tests) - BufferedOutput + json_decode

### Finalisation V0.2 [TERMINE]

- [x] 8.10 Mettre a jour le README avec les nouvelles features
  - Nouveaux analyzers documentes
  - Exemple de sortie JSON avec snippet CI GitHub Actions
  - Namespace corrige : PierreArthur\SfDoctor
- [x] 8.11 Migration namespace SfDoctor -> PierreArthur\SfDoctor
  - composer.json autoload mis a jour
  - 35 fichiers PHP corriges via sed
  - config/services.yaml corrige
  - phpunit.xml.dist KERNEL_CLASS corrige
- [x] 8.12 Tag v0.2.0

---

## Phase 9 - V0.3 : Ouverture aux contributions [A FAIRE]

- [x] 9.1 CONTRIBUTING.md - guide pour contribuer au projet
- [x] 9.2 docs/analyzers.md - documentation pour creer un analyzer custom
- [ ] 9.3 Tester sur des projets reels (Sylius, projets custom)
- [x] 9.4 Tag v0.3.0

---

## Roadmap produit

### V0.1 - PUBLIEE

Le `FirewallAnalyzer` et le `AccessControlAnalyzer`. Deux classes, une commande.
Premiere version fonctionnelle, publiee sur Packagist.

Retour terrain : teste sur 2 projets Sylius (1.x et 2.x), score 100/100 sur les deux.
Cause identifiee : les parametres Symfony (%param%) ne sont pas resolus.
Correction faite en V0.2 via le ParameterResolver.

### V0.2 - PUBLIEE

Resolution des parametres Symfony (ParameterResolver) pour supporter les vrais projets.
Ajout du `ControllerAnalyzer`, du `CsrfAnalyzer`, du `DebugModeAnalyzer`.
Output JSON pour CI/CD.
Migration namespace vers PierreArthur\SfDoctor.

### V0.3 - Ouverture aux contributions

CONTRIBUTING.md, doc/analyzers.md, guide pour creer un analyzer custom.
D'autres devs ajoutent leurs propres analyzers via le systeme de tags.
La communaute enrichit l'outil.

Prerequis pour communiquer publiquement :
- SF-Doctor detecte de vrais problemes sur de vrais projets
- Screenshots concrets avec des resultats non-triviaux
- Teste sur au moins 2-3 projets differents (Sylius, projets custom)

### Phase 10 - V1.0 - Lancement public + tier payant [TERMINEE]

- [x] 10.1 NplusOneAnalyzer (src/Analyzer/Performance/NplusOneAnalyzer.php)
  - Detection des acces a deux niveaux dans les boucles Twig
  - 9 tests, 12 assertions
- [x] 10.2 Tests NplusOneAnalyzerTest
- [x] 10.3 PdfReporter (src/Report/PdfReporter.php) - dompdf
  - 5 tests, 6 assertions
- [x] 10.4 Tests PdfReporterTest
- [x] 10.5 UpgradeAnalyzer (repo separe : pierre-arthur/sf-doctor-upgrade)
  - Migration Symfony64To70Migration - 8 regles de detection
  - 10 tests, 17 assertions
- [x] 10.6 Tests UpgradeAnalyzerTest
- [x] 10.7 README sf-doctor-upgrade
- [x] 10.8 Tag v1.0.0 (sf-doctor + sf-doctor-upgrade)

Module Upgrade (analyse de migration entre versions Symfony).
`NplusOneAnalyzer`, `PdfReporter`, dashboard web.
Licence commerciale pour les features payantes.

## Phase 11 - V1.1 : Corrections terrain [TERMINEE]

### Bugs identifies lors des tests sur Sylius 1.x et 2.x

- [x] fix: interface_exists au lieu de class_exists dans ControllerAnalyzer
- [x] fix: exclure *.vars.* dans NplusOneAnalyzer (faux positifs FormView)
- [x] fix: exclure routes publiques dans AccessControlAnalyzer (login, register, forgotten-password)
- [x] test: valider le format JSON sur Sylius 1.x et 2.x
- [x] test: valider le format PDF sur Sylius 1.x et 2.x
- [x] tag: v1.1.0

---

## Phase 12 - Events + Cache [TERMINEE]

- [x] 12.1 AnalysisStartedEvent (src/Event/AnalysisStartedEvent.php)
- [x] 12.2 AnalysisCompletedEvent (src/Event/AnalysisCompletedEvent.php)
- [x] 12.3 IssueFoundEvent (src/Event/IssueFoundEvent.php)
- [x] 12.4 ModuleCompletedEvent (src/Event/ModuleCompletedEvent.php)
- [x] 12.5 ProgressSubscriber (src/EventSubscriber/ProgressSubscriber.php)
  - Ecoute AnalysisStartedEvent, ModuleCompletedEvent, AnalysisCompletedEvent
  - Injecte OutputInterface au moment de execute() (pas au boot du container)
  - Tests : ProgressSubscriberTest (5 tests, 11 assertions)
- [x] 12.6 Dispatcher les events dans AuditCommand
  - groupAnalyzersByModule() - materialise l'iterable, groupe par module
  - IssueFoundEvent dispatche apres chaque analyzer via array_slice
  - ModuleCompletedEvent dispatche apres chaque groupe de module
  - AnalysisStartedEvent et AnalysisCompletedEvent aux extremites
- [x] 12.7 Mettre a jour AuditCommandTest (mock EventDispatcherInterface)
- [x] 12.8 ResultCacheInterface (src/Cache/ResultCacheInterface.php)
- [x] 12.9 ResultCache (src/Cache/ResultCache.php)
  - computeHash() : SHA256 du contenu de tous les YAML de config/
  - sort() des fichiers pour un hash stable cross-OS
  - serialize/deserialize manuel en JSON (pas serialize() PHP)
- [x] 12.10 CacheSubscriber (src/EventSubscriber/CacheSubscriber.php)
  - Ecoute AnalysisCompletedEvent, appelle ResultCache::save()
- [x] 12.11 Brancher le cache dans AuditCommand
  - Verification du cache avant l'analyse (court-circuit si cache valide)
  - CacheSubscriber enregistre apres l'analyse via AnalysisCompletedEvent

## Phase 13 - Configuration du bundle (TreeBuilder) [TERMINEE]

- [x] 13.1 Configuration.php (src/DependencyInjection/Configuration.php)
  - TreeBuilder avec score_threshold (int, 0-100, defaut 0)
  - Noeud analyzers : security, architecture, performance (bool, defaut true)
  - addDefaultsIfNotSet() pour garantir les valeurs par defaut si le noeud est absent
- [x] 13.2 SfDoctorExtension mis a jour
  - processConfiguration() - fusionne et valide la config utilisateur
  - setParameter() - expose sf_doctor.score_threshold, sf_doctor.analyzers.*
- [x] 13.3 AnalyzerCompilerPass mis a jour
  - Lit l'attribut "module" sur chaque tag sf_doctor.analyzer
  - removeDefinition() si le module est desactive dans la config
- [x] 13.4 services.yaml mis a jour
  - Tous les analyzers declares explicitement avec { name: sf_doctor.analyzer, module: ... }
- [x] 13.5 TestKernelWithConfig (tests/Integration/TestKernelWithConfig.php)
  - loadFromExtension() pour simuler une config utilisateur
  - getCacheDir() unique par config via md5(serialize($config))
- [x] 13.6 ConfigurationTest (tests/Integration/DependencyInjection/ConfigurationTest.php)
  - 6 tests, 8 assertions
  - Verifie valeurs par defaut, parametres container, suppression services par module
- [x] 13.7 Tag v1.2.0

---

## Phase 14 - Serializer (AuditReportNormalizer) [TERMINEE]

- [x] 14.1 Ajouter symfony/serializer et symfony/property-access
- [x] 14.2 IssueNormalizer (src/Serializer/IssueNormalizer.php)
  - supportsNormalization(), normalize(), getSupportedTypes()
  - severity et module en strtolower(->name)
  - Tests : IssueNormalizerTest (8 tests, 12 assertions)
- [x] 14.3 AuditReportNormalizer (src/Serializer/AuditReportNormalizer.php)
  - NormalizerAwareInterface + NormalizerAwareTrait
  - Delègue la normalisation des Issue au Serializer central
  - Structure : meta, summary (score, status, issues_count), issues
  - Tests : AuditReportNormalizerTest (11 tests, 25 assertions)
- [x] 14.4 Enregistrer les normalizers dans services.yaml avec tag serializer.normalizer
- [x] 14.5 Refactoriser ReporterInterface et les reporters
  - ReporterInterface::generate() recoit OutputInterface en second argument
  - ConsoleReporter : plus de constructeur, OutputInterface recu dans generate()
  - JsonReporter : AuditReportNormalizer dans le constructeur, OutputInterface dans generate()
  - PdfReporter : OutputInterface recu dans generate() mais non utilise
  - SfDoctorBundle : autoconfigure pour ReporterInterface -> tag sf_doctor.reporter
  - services.yaml : ConsoleReporter et JsonReporter declares comme services
  - AuditCommand : findReporter() remplace les boucles with $reported flag
- [x] 14.6 Tests unitaires IssueNormalizerTest et AuditReportNormalizerTest
- [x] 14.7 Tag v1.3.0

---

---

## Phase 15 - Workflow (state machine) [TERMINEE]

- [x] 15.1 Ajouter symfony/workflow
- [x] 15.2 AuditContext (src/Workflow/AuditContext.php) - porte l'etat courant
- [x] 15.3 AuditWorkflow (src/Workflow/AuditWorkflow.php) - StateMachine, 4 etats, 3 transitions
- [x] 15.4 Tests AuditWorkflowTest (tests/Unit/Workflow/AuditWorkflowTest.php) - 9 tests
- [x] 15.5 Brancher le workflow dans AuditCommand (try/catch, transitions start/complete/fail)
- [x] 15.6 Tests AuditCommandTest mis a jour - 9 tests, 16 assertions
- [x] 15.7 Tag v1.4.0

---

## Phase 16 - Messenger (mode --async) [TERMINEE]

- [x] 16.1 Ajouter symfony/messenger
- [x] 16.2 RunAnalyzerMessage (src/Message/RunAnalyzerMessage.php)
- [x] 16.3 RunAnalyzerMessageHandler (src/Message/RunAnalyzerMessageHandler.php)
- [x] 16.4 Option --async dans AuditCommand
- [x] 16.5 Tests RunAnalyzerMessageHandlerTest + AuditCommandTest mis a jour (11 tests, 20 assertions)
- [x] 16.6 Tag v1.5.0

## Phase 17 - Issue enrichment : SF-Doctor devient un mentor [EN COURS]

- [x] 17.1 Etendre Issue (src/Model/Issue.php)
  - fixCode, docUrl, businessImpact, estimatedFixMinutes (nullable, valeur par défaut null)
  - Getters ajoutés, tests mis à jour (6 tests, 28 assertions)
- [x] 17.2 Mettre à jour IssueNormalizer
  - 4 nouvelles clés snake_case : fix_code, doc_url, business_impact, estimated_fix_minutes
  - Tests mis à jour (11 tests)
- [x] 17.3 Mettre à jour AuditReportNormalizer
  - Aucune modification du source (délégation via IssueNormalizer)
  - Test de passage ajouté (12 tests, 23 assertions)
- [x] 17.4 Enrichir ConsoleReporter
  - businessImpact, fixCode, docUrl, estimatedFixMinutes affichés sous chaque issue
  - Temps total estimé en pied de rapport
  - Mode --brief via $context['brief'] (tableau seul, sans enrichissement)
  - ReporterInterface::generate() étendu avec array $context = []
  - JsonReporter et PdfReporter mis à jour (paramètre ignoré)
  - AuditCommand : option --brief ajoutée, contexte passé aux deux appels generate()
  - Tests : 23 tests ConsoleReporter, 12 tests AuditCommand
- [x] 17.5 Enrichir les analyzers existants avec les nouveaux champs
  - FirewallAnalyzer : 3 issues enrichies (fixCode, docUrl, businessImpact, estimatedFixMinutes)
  - AccessControlAnalyzer : 4 issues enrichies
  - CsrfAnalyzer : 2 issues enrichies
  - DebugModeAnalyzer : 3 issues enrichies
  - ControllerAnalyzer : 2 issues enrichies
  - NplusOneAnalyzer : 1 issue enrichie (file et line déjà présents depuis V1.0)
- [x] 17.6 Tag v1.6.0

## Phase 18 - Secrets & Config prod [TERMINEE]

- [x] 18.1 SecretsAnalyzer (src/Analyzer/Security/SecretsAnalyzer.php)
  - Check 1 : APP_SECRET absent (CRITICAL)
  - Check 2 : APP_SECRET = valeur par defaut connue (CRITICAL) - liste de 5 valeurs
  - Check 3 : APP_SECRET < 32 caracteres (WARNING)
  - Lit .env.prod en priorite, fallback sur .env
  - supports() : verifie la presence de .env ou .env.prod
  - Tests : SecretsAnalyzerTest (11 tests, 17 assertions)
- [x] 18.2 ProfilerAnalyzer (src/Analyzer/Configuration/ProfilerAnalyzer.php)
  - Check 1 : web_profiler.toolbar: true dans packages/ global (CRITICAL)
  - Check 2 : web_profiler.intercept_redirects: true global (WARNING)
  - Check 3 : framework.profiler.enabled ou collect: true global (CRITICAL)
  - Tests : ProfilerAnalyzerTest (7 tests)
- [x] 18.3 MailerAnalyzer (src/Analyzer/Configuration/MailerAnalyzer.php)
  - Check 1 : DSN null:// ou null://null (CRITICAL)
  - Check 2 : DSN sous framework.mailer.dsn et mailer.dsn (ancienne syntaxe)
  - Skip si DSN est une variable d'env non resolue
  - Tests : MailerAnalyzerTest (8 tests)
- [x] 18.4 HttpHeadersAnalyzer (src/Analyzer/Configuration/HttpHeadersAnalyzer.php)
  - Check 1 : X-Frame-Options absent (WARNING)
  - Check 2 : X-Content-Type-Options absent (WARNING)
  - Check 3 : Content-Security-Policy absent (SUGGESTION)
  - Recherche insensible a la casse via findHeader()
  - Tests : HttpHeadersAnalyzerTest (8 tests)
- [x] Fix : alias ConfigReaderInterface -> YamlConfigReader dans services.yaml
- [x] Tag v1.7.0

---

## Positionnement concurrentiel

### Seul concurrent direct : SymfonyInsight (SensioLabs)

| Critere | SymfonyInsight | SF-Doctor |
|---|---|---|
| Prix | 19-39 EUR/mois | Gratuit (core open source) |
| Hebergement | Cloud SensioLabs (code envoye) | Local, code ne quitte pas la machine |
| Extensible | Non (boite noire) | Oui (systeme de tags, ecosystem Packagist) |
| Analyse dynamique | Oui (boot l'app) | Non (statique uniquement) |
| Integration bundle | Non | Oui (acces au container compile) |
| Parametres resolus | Non | Oui (ContainerParameterResolver) |
| API Platform | Non | Oui (Phase 25) |
| Messenger checks | Non | Oui (Phase 28) |
| Module Migration | Non | Oui (Phase 26) |
| Mode fix interactif | Non | Oui (Phase 28) |
| Output SARIF (GitHub) | Non | Oui (Phase 28) |

### Ce que SF-Doctor fait que SymfonyInsight ne peut pas faire

1. **Local et prive** : le code ne quitte jamais la machine. Indispensable pour les projets clients confidentiels, les administrations, les banques.
2. **Extensible par la communaute** : n'importe quel dev publie son analyzer sur Packagist. Modele ESLint, pas SonarQube.
3. **Acces au container compile** : en mode bundle, SF-Doctor voit les vrais services resolus, les vrais parametres. SymfonyInsight voit le code source.
4. **Checks semantiques Symfony** : firewall, access_control, CSRF, Messenger, API Platform. SymfonyInsight fait du PHP generique + quelques checks Symfony basiques.
5. **Gratuit et CI-native** : un `composer require`, une ligne dans le pipeline. Zero friction.

---

## Sources de veille intégrées à la roadmap

Recherches terrain effectuées en mars 2026 :
- Blog officiel Symfony (CVE, security hardening, best practices)
- Stack Overflow Developer Survey 2024-2025 : dette technique = frustration n°1 (62% des devs)
- Blackfire benchmarks : APP_DEBUG=true en prod degrade les temps de reponse jusqu'a 70%
- Audits terrain MoldStud 2024 : optimisation Doctrine reduit la charge BDD de 45 a 70%
- CVE Symfony novembre 2024 : 8 vulnerabilites publiees en une seule release
- Blog Symfony janvier 2026 "Hardening Symfony" : signature Messenger, URL parsing, HTTP method override
- CVE-2025-64500 : bypass access_control via PATH_INFO mal parse

---

## Roadmap produit - Ce qui reste a construire

| Version | Phase | Objectif |
|---|---|---|
| v1.6 | 17 | Issue enrichment - SF-Doctor devient un mentor (fichier, ligne, fixCode, docUrl) |
| v1.7 | 18 | Secrets & Config prod (APP_SECRET, mailer null, profiler actif, headers HTTP) |
| v1.8 | 19 | Auto-detection du contexte (zero config, detecte doctrine/messenger/api-platform) |
| v1.9 | 20 | Mode dev continu (--diff, --watch, hook pre-commit) |
| v2.0 | 21 | Garde-fou deploy + Security complet (CVE, CORS, rate limiter, HTTPS) |
| v2.1 | 22 | Architecture complet (constructeur lourd, service injection, voters) |
| v2.2 | 23 | Module Doctrine (N+1, index manquants, cascade, eager loading) |
| v2.3 | 24 | Module Messenger securise (signing 7.4, transports, messages non serialisables) |
| v2.4 | 25 | Module API Platform (killer feature - aucun concurrent ne le fait) |
| v2.5 | 26 | Module Migration Symfony 7.x -> 8.0 (timing parfait) |
| v2.6 | 27 | Module Twig (XSS, srcdoc, logique metier dans templates) |
| v2.7 | 28 | Score par couche (securite/archi/perf/maintenabilite/prod-readiness/tests) |
| v2.8 | 29 | Deployabilite (migrations, assets, variables d'env manquantes, logs) |
| v2.9 | 30 | Analyse des tests (couverture, tests securite manquants, coherence inter-couches) |
| v3.0 | 31 | Surface d'attaque invisible (routes tiers, endpoints debug, fichiers sensibles publics) |
| v3.1 | 32 | Rapport "Projet inconnu" - le rapport PDF qu'une agence facture 2000 EUR |
| v3.2 | 33 | DX : mode fix + GitHub Action + SARIF + plugin IDE VSCode/PhpStorm |
| v3.3 | 34 | Dashboard web multi-projets pour agences |
| v3.4 | 35 | AI-powered suggestions + baseline publique anonymisee |

---

- [ ] 18.5 MailerConfigAnalyzer (src/Analyzer/Configuration/MailerConfigAnalyzer.php)
  - Check 1 : MAILER_DSN = "null://null" en prod (CRITICAL - emails perdus silencieusement)
  - Check 2 : MAILER_DSN = "smtp://localhost" sans auth en prod (WARNING)
  - fixCode : exemples DSN Mailgun, Sendgrid, SES
  - docUrl : https://symfony.com/doc/current/mailer.html
  - supports() : verifie la presence de .env.prod ou mailer.yaml

- [ ] 18.6 Tests MailerConfigAnalyzerTest

- [ ] 18.7 SessionStorageAnalyzer (src/Analyzer/Configuration/SessionStorageAnalyzer.php)
  - Check 1 : session sur filesystem en prod (WARNING - perte de session multi-serveur)
  - Check 2 : session sans TTL explicite (WARNING - accumulation de fichiers)
  - fixCode : config Redis pour les sessions
  - docUrl : https://symfony.com/doc/current/session.html
  - supports() : verifie la presence de config/packages/framework.yaml

- [ ] 18.8 Tests SessionStorageAnalyzerTest

- [ ] 18.9 CacheAdapterAnalyzer (src/Analyzer/Configuration/CacheAdapterAnalyzer.php)
  - Check 1 : adapter "cache.adapter.array" en prod (CRITICAL - cache reinitialiase chaque requete)
  - Check 2 : adapter "cache.adapter.filesystem" en prod multi-serveur (WARNING)
  - Check 3 : aucun pool de cache configure (SUGGESTION)
  - fixCode : config Redis pool recommandee
  - docUrl : https://symfony.com/doc/current/cache.html
  - supports() : verifie la presence de config/packages/cache.yaml

- [ ] 18.10 Tests CacheAdapterAnalyzerTest

- [ ] 18.11 MonologProdAnalyzer (src/Analyzer/Configuration/MonologProdAnalyzer.php)
  - Check 1 : level "debug" sur le channel main en prod (WARNING - sature le disque)
  - Check 2 : handler stream sans rotation en prod (WARNING)
  - Check 3 : web_profiler toolbar: true en prod (CRITICAL - fuite d'info + impact perf)
  - fixCode : config Monolog prod recommandee + snippet web_profiler.yaml
  - docUrl : https://symfony.com/doc/current/logging.html
  - supports() : verifie la presence de monolog.yaml

- [ ] 18.12 Tests MonologProdAnalyzerTest

- [ ] 18.13 HttpHeadersAnalyzer (src/Analyzer/Security/HttpHeadersAnalyzer.php)
  - Check 1 : absence de Content-Security-Policy (WARNING)
  - Check 2 : absence de X-Frame-Options (WARNING)
  - Check 3 : absence de X-Content-Type-Options (WARNING)
  - Check 4 : absence de Referrer-Policy (SUGGESTION)
  - Si NelmioSecurityBundle detecte : analyser sa configuration
  - fixCode : snippet NelmioSecurityBundle ou middleware Symfony
  - docUrl : https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html
  - supports() : toujours true

- [ ] 18.14 Tests HttpHeadersAnalyzerTest

- [ ] 18.15 Tag v1.7.0

---

## Phase 19 - Auto-detection du contexte [TERMINEE]

- [x] 19.1 ProjectContext (src/Context/ProjectContext.php)
  - Value Object immutable : 11 flags de detection + symfonyVersion + isSymfonyAtLeast()
- [x] 19.2 ProjectContextDetector (src/Context/ProjectContextDetector.php)
  - Lit composer.json (require + require-dev) via PACKAGE_MAP
  - Lit composer.lock pour la version Symfony exacte (ltrim 'v')
  - Retourne un contexte vide si composer.json absent
  - Tests : ProjectContextDetectorTest (11 tests)
- [x] 19.3 AnalyzerInterface mis a jour : supports(ProjectContext $context): bool
  - Tous les analyzers migres (10 fichiers)
  - AuditCommand : ProjectContextDetector instancie au debut de execute()
  - RunAnalyzerMessageHandler : ProjectContextDetector instancie dans __invoke()
  - Tests mis a jour : makeContext() helper dans DebugModeAnalyzerTest,
    SecretsAnalyzerTest, NplusOneAnalyzerTest
- [ ] 19.4 Option --no-auto-detect (reporte)
- [ ] 19.5 Tests integration auto-detection (reporte)
- [x] 19.6 Tag v1.8.0

---

## Phase 20 - Mode developpement continu [TERMINEE]

SF-Doctor utilise ponctuellement = un audit annuel.
SF-Doctor integre au workflow quotidien = outil indispensable.

- [x] 20.1 Mode --diff (comparaison entre deux analyses)
  - AuditReportDiff (src/Diff/AuditReportDiff.php) - empreinte module|severity|message
  - BaselineStorage (src/Diff/BaselineStorage.php) - persistance JSON des rapports de reference
  - Option --diff=<chemin> dans AuditCommand - charge la baseline, affiche [+]/[-]
  - Option --save-baseline=<chemin> dans AuditCommand - sauvegarde le rapport
  - Exit code en mode diff base uniquement sur les CRITICALs introduits
  - handlePostAnalysis() factorise la logique post-analyse (cache + normal)
  - Tests : AuditReportDiffTest (8 tests, 20 assertions)
  - Tests : BaselineStorageTest (7 tests, 15 assertions)
  - Tests : AuditCommandTest mis a jour (18 tests, 6 nouveaux tests diff/baseline)

- [x] 20.2 Tests DiffModeTest

- [x] 20.3 Mode --watch (surveillance en temps reel pendant le dev)
  - FileWatcher (src/Watch/FileWatcher.php) - polling 500ms, snapshot mtime
  - Surveille src/, config/, templates/ avec filtrage par extension
  - Detecte fichiers modifies, ajoutes, supprimes
  - Relance l'audit complet a chaque changement et affiche le diff
  - Cache ignore en mode watch (audit frais a chaque iteration)
  - Option --watch (-w) dans AuditCommand - boucle infinie, Ctrl+C pour arreter
  - Tests : FileWatcherTest (8 tests, 8 assertions)

- [x] 20.4 Tests WatchModeTest

- [x] 20.5 Hook pre-commit Git (src/Git/PreCommitHook.php)
  - PreCommitHook : generation, installation, desinstallation du hook
  - Marqueur # sf-doctor:pre-commit pour identifier les hooks SF-Doctor
  - Refuse d'ecraser un hook pre-commit existant non-SF-Doctor
  - Mise a jour d'un hook SF-Doctor existant autorisee
  - InstallHooksCommand (src/Command/InstallHooksCommand.php)
    - sf-doctor:install-hooks : installe le hook
    - sf-doctor:install-hooks --uninstall : desinstalle le hook
  - Tests : PreCommitHookTest (11 tests, 11 assertions)
  - Tests : InstallHooksCommandTest (5 tests, 9 assertions)

- [x] 20.6 Tests PreCommitHookTest

- [ ] 20.7 Tag v1.9.0

---

## Phase 21 - Garde-fou deploy + Security module complet [A FAIRE]

Source : Blackfire benchmarks, CVE Symfony nov. 2024, blog "Hardening Symfony" jan. 2026.
C'est le module qu'on lance avant chaque mise en prod.

### ProductionReadinessAnalyzer - la checklist du deploy automatisee

- [ ] 17.1 ProductionReadinessAnalyzer (src/Analyzer/Configuration/ProductionReadinessAnalyzer.php)
  - Check 1 : APP_ENV absent ou different de "prod" dans .env.prod (CRITICAL)
    Source terrain : APP_DEBUG=true en prod degrade les performances jusqu'a 70% (Blackfire)
  - Check 2 : APP_DEBUG=true ou APP_DEBUG=1 en prod (CRITICAL)
  - Check 3 : `opcache.validate_timestamps=1` detecte dans php.ini ou commentaires de config (WARNING)
    En prod, les timestamps doivent etre desactives : le code ne change pas entre deux deploys
  - Check 4 : absence de `config/preload.php` (SUGGESTION - preloading PHP desactive)
  - Check 5 : `composer.lock` non commite ou absent (WARNING - build non reproductible)
  - supports() : verifie la presence des fichiers .env / .env.prod

- [ ] 17.2 Tests ProductionReadinessAnalyzerTest

### SymfonyVersionAnalyzer - detecte les CVE sur la version installee

- [ ] 17.3 SymfonyVersionAnalyzer (src/Analyzer/Security/SymfonyVersionAnalyzer.php)
  - Lit la version Symfony depuis composer.lock
  - Check 1 : version Symfony avec CVE connu non patche (CRITICAL)
    Reference : CVE-2025-64500 bypass access_control via PATH_INFO, CVE remember-me auth bypass
  - Check 2 : version Symfony en end-of-life (WARNING)
    Source : Symfony 5.4 bug fixes termines en novembre 2024
  - Check 3 : version mineure non a jour (SUGGESTION - patches de securite manques)
  - supports() : verifie la presence de composer.lock

- [ ] 17.4 Tests SymfonyVersionAnalyzerTest

### Analyzers security - parite SymfonyInsight

- [ ] 17.5 RememberMeAnalyzer (src/Analyzer/Security/RememberMeAnalyzer.php)
  - Check 1 : remember_me sans `secure: true` (WARNING)
  - Check 2 : remember_me sans `httponly: true` (WARNING)
  - Check 3 : `lifetime` superieur a 30 jours - 2592000 secondes (SUGGESTION)
  - Note CVE : CVE nov. 2024 - bypass d'auth via remember_me cookie sans verification username
  - supports() : verifie la presence de `remember_me` dans security.yaml

- [ ] 17.6 Tests RememberMeAnalyzerTest

- [ ] 17.7 SensitiveDataAnalyzer (src/Analyzer/Security/SensitiveDataAnalyzer.php)
  - Parcourt src/Entity/ et src/Form/ avec le Finder
  - Check 1 : propriete nommee `password`, `token`, `secret`, `apiKey` sans `#[Ignore]`
    ni groupe de serialisation restrictif (WARNING)
  - Check 2 : FormType avec champ `password` sans `PasswordType` (WARNING)
  - supports() : verifie class_exists(AbstractType::class)

- [ ] 17.8 Tests SensitiveDataAnalyzerTest

### Analyzers security - au-dela de SymfonyInsight

- [ ] 17.9 RateLimiterAnalyzer (src/Analyzer/Security/RateLimiterAnalyzer.php)
  - Verifie la presence de `framework.rate_limiter` dans framework.yaml
  - Check 1 : route de login sans rate limiter reference (WARNING - brute force possible)
  - Check 2 : route d'API publique sans rate limiter (SUGGESTION)
  - supports() : verifie la presence de routes /login, /api dans les controllers

- [ ] 17.10 Tests RateLimiterAnalyzerTest

- [ ] 17.11 HttpsAnalyzer (src/Analyzer/Security/HttpsAnalyzer.php)
  - Check 1 : `requires_channel: https` absent sur les firewalls (WARNING)
  - Check 2 : cookies de session sans `cookie_secure: true` (WARNING)
  - Check 3 : absence de header HSTS dans la config (SUGGESTION)
  - supports() : verifie la presence de security.yaml

- [ ] 17.12 Tests HttpsAnalyzerTest

- [ ] 17.13 MassAssignmentAnalyzer (src/Analyzer/Security/MassAssignmentAnalyzer.php)
  - Source : pattern reel documente sur les API Symfony en 2024
  - Parcourt src/Form/ avec le Finder
  - Check 1 : FormType avec `$form->submit($request->request->all())` sans liste blanche (CRITICAL)
  - Check 2 : FormType sans `allowExtraFields: false` explicite sur les champs sensibles (WARNING)
  - supports() : verifie class_exists(AbstractType::class)

- [ ] 17.14 Tests MassAssignmentAnalyzerTest

- [ ] 17.15 CorsAnalyzer (src/Analyzer/Security/CorsAnalyzer.php)
  - Source : misconfiguration CORS documentee dans les vulns API Symfony 2024
  - Lit config/packages/nelmio_cors.yaml si present
  - Check 1 : `allow_origin: ['*']` avec `allow_credentials: true` (CRITICAL - CORS misconfiguration)
  - Check 2 : `allow_origin: ['*']` sur des routes non publiques (WARNING)
  - Check 3 : absence totale de config CORS sur un projet avec routes /api (SUGGESTION)
  - supports() : verifie la presence de nelmio_cors.yaml ou du bundle NelmioCors

- [ ] 17.16 Tests CorsAnalyzerTest

- [ ] 17.17 HttpMethodOverrideAnalyzer (src/Analyzer/Security/HttpMethodOverrideAnalyzer.php)
  - Source : blog "Hardening Symfony" jan. 2026 - HTTP verb tunneling renforce en Symfony 7.4
  - Check 1 : `framework.http_method_override: true` avec absence de protection CSRF sur PUT/DELETE (WARNING)
  - Check 2 : version Symfony < 7.4 avec http_method_override actif (SUGGESTION - upgrade recommande)
  - supports() : verifie la presence de framework.yaml

- [ ] 17.18 Tests HttpMethodOverrideAnalyzerTest

- [ ] 17.19 Tag v1.6.0

---

## Phase 22 - Architecture module complet [A FAIRE]

Source : Tideways blog, audits terrain Symfony 2024.
Detecter les anti-patterns qu'un dev senior corrige en code review - mais pas tout le monde a un senior.

- [ ] 18.1 ServiceInjectionAnalyzer (src/Analyzer/Architecture/ServiceInjectionAnalyzer.php)
  - Parcourt src/ avec le Finder, cherche `ContainerInterface $container` en injection (CRITICAL)
  - Cherche `$this->container->get(` dans les methodes (CRITICAL)
  - Exception : les classes qui implementent `ContainerAwareInterface` (pattern legacy tolere)
  - supports() : toujours true

- [ ] 18.2 Tests ServiceInjectionAnalyzerTest

- [ ] 18.3 HeavyConstructorAnalyzer (src/Analyzer/Architecture/HeavyConstructorAnalyzer.php)
  - Source : Tideways - "Don't perform work in the Constructor" est critique pour Symfony.
    Tout ce qui est fait dans un constructeur de service ralentit CHAQUE requete.
  - Parcourt src/Service/, src/Repository/, src/EventListener/, src/EventSubscriber/
  - Check 1 : appel a une methode de repository dans un constructeur (CRITICAL)
  - Check 2 : appel a `$this->entityManager->find/findBy` dans un constructeur (CRITICAL)
  - Check 3 : constructeur avec plus de 8 dependances injectees (WARNING - service trop couple)
  - Check 4 : injection de `TemplatingInterface` ou `Environment` dans un EventListener (WARNING)
    Charge Twig sur chaque requete meme pour les reponses JSON
  - supports() : toujours true

- [ ] 18.4 Tests HeavyConstructorAnalyzerTest

- [ ] 18.5 VoterUsageAnalyzer (src/Analyzer/Architecture/VoterUsageAnalyzer.php)
  - Parcourt src/Controller/ avec le Finder
  - Check 1 : `in_array('ROLE_`, `hasRole(` dans les controllers sans Voter correspondant
    dans src/Security/ (WARNING)
  - Check 2 : `denyAccessUnlessGranted` avec string hardcodee sans constante (SUGGESTION)
  - supports() : verifie la presence de src/Controller/

- [ ] 18.6 Tests VoterUsageAnalyzerTest

- [ ] 18.7 PublicServiceAnalyzer (src/Analyzer/Architecture/PublicServiceAnalyzer.php)
  - Parcourt config/services.yaml et config/packages/
  - Detecte les services declares `public: true` hors bundle SF-Doctor lui-meme (WARNING)
  - Exception : services explicitement destines a etre publics (commandes, controllers)
  - supports() : verifie la presence de config/services.yaml

- [ ] 18.8 Tests PublicServiceAnalyzerTest

- [ ] 18.9 EventSubscriberAnalyzer (src/Analyzer/Architecture/EventSubscriberAnalyzer.php)
  - Parcourt src/EventSubscriber/ avec le Finder
  - Check 1 : methode d'ecoute avec plus de 50 lignes (WARNING - logique metier dans un subscriber)
  - Check 2 : appel direct a EntityManager dans un subscriber (WARNING - couplage fort)
  - supports() : verifie la presence de src/EventSubscriber/

- [ ] 18.10 Tests EventSubscriberAnalyzerTest

- [ ] 18.11 Tag v1.7.0

---

## Phase 23 - Module Doctrine [A FAIRE]

Nouveau module. Aucun outil open source ne fait ca.
Source terrain : optimisation Doctrine = reduction de la charge BDD de 45 a 70% dans les audits reels (MoldStud 2024).
Un profil avec plus de 30 requetes par requete HTTP signale un probleme de fetching strategy.

- [ ] 19.1 Ajouter Module::DOCTRINE dans l'enum Module
  - Ajouter l'option `--doctrine` dans AuditCommand
  - Ajouter le module dans la config TreeBuilder

- [ ] 19.2 EagerLoadingAnalyzer (src/Analyzer/Doctrine/EagerLoadingAnalyzer.php)
  - Parcourt src/Entity/ avec le Finder
  - Check 1 : relation `fetch: EAGER` sur une collection OneToMany/ManyToMany (CRITICAL)
    Charge toute la collection en memoire a chaque acces a l'entite parente
  - Check 2 : relation `OneToMany` sans `fetch: LAZY` explicite (SUGGESTION)
  - supports() : verifie interface_exists(EntityManagerInterface::class)

- [ ] 19.3 Tests EagerLoadingAnalyzerTest

- [ ] 19.4 MissingIndexAnalyzer (src/Analyzer/Doctrine/MissingIndexAnalyzer.php)
  - Parcourt src/Entity/ avec le Finder
  - Detecte les proprietes avec `#[ORM\Column]` utilisees dans `findBy()`, `orderBy()`
    sans `#[ORM\Index]` correspondant (WARNING)
  - Analyse les repositories pour identifier les champs filtres (findBy, findOneBy, where())
  - supports() : verifie interface_exists(EntityManagerInterface::class)

- [ ] 19.5 Tests MissingIndexAnalyzerTest

- [ ] 19.6 CascadeRiskAnalyzer (src/Analyzer/Doctrine/CascadeRiskAnalyzer.php)
  - Check 1 : `cascade: ['all']` sur une relation (WARNING - suppression en cascade non intentionnelle)
  - Check 2 : `cascade: ['remove']` sans `orphanRemoval: true` (SUGGESTION - incoherence frequente)
  - Check 3 : relation bidirectionnelle sans `mappedBy`/`inversedBy` (WARNING)
  - supports() : verifie interface_exists(EntityManagerInterface::class)

- [ ] 19.7 Tests CascadeRiskAnalyzerTest

- [ ] 19.8 RepositoryPatternAnalyzer (src/Analyzer/Doctrine/RepositoryPatternAnalyzer.php)
  - Parcourt src/ hors src/Repository/ avec le Finder
  - Detecte les appels a `createQueryBuilder()` et `createQuery()` hors des repositories (CRITICAL)
  - Detecte les appels directs a EntityManager hors de Command, Repository, DataFixture (WARNING)
  - supports() : verifie interface_exists(EntityManagerInterface::class)

- [ ] 19.9 Tests RepositoryPatternAnalyzerTest

- [ ] 19.10 LazyGhostObjectAnalyzer (src/Analyzer/Doctrine/LazyGhostObjectAnalyzer.php)
  - Source : issue Doctrine ORM #11087 - enable_lazy_ghost_objects degrade les perfs de 4x
    sur certaines configurations (Doctrine ORM 2.17+)
  - Check 1 : `enable_lazy_ghost_objects: true` avec Doctrine ORM < 3.0 sans benchmark (SUGGESTION)
  - Check 2 : version Doctrine ORM 2.15-2.17 avec lazy_ghost actif (WARNING)
  - supports() : verifie la presence de config/packages/doctrine.yaml

- [ ] 19.11 Tests LazyGhostObjectAnalyzerTest

- [ ] 19.12 Tag v1.8.0

---

## Phase 24 - Module Messenger securise [A FAIRE]

Nouveau module. Les bugs Messenger sont silencieux : un message sans handler est ingere sans erreur.
Source : blog "Hardening Symfony" janvier 2026 - signature des messages ajoutee en Symfony 7.4.
Sans signature, un attaquant qui injecte un payload forge dans la file peut declencher
RunProcessHandler ou RunCommandHandler. Aucun outil ne verifie ca aujourd'hui.

- [ ] 20.1 Ajouter Module::MESSENGER dans l'enum Module
  - Ajouter l'option `--messenger` dans AuditCommand

- [ ] 20.2 UnhandledMessageAnalyzer (src/Analyzer/Messenger/UnhandledMessageAnalyzer.php)
  - Parcourt src/Message/ avec le Finder pour lister les classes de messages
  - Parcourt src/ pour trouver les handlers (#[AsMessageHandler])
  - Check 1 : message sans handler correspondant (CRITICAL - message silencieusement ignore)
  - supports() : verifie class_exists(MessageBusInterface::class)

- [ ] 20.3 Tests UnhandledMessageAnalyzerTest

- [ ] 20.4 UnserializableMessageAnalyzer (src/Analyzer/Messenger/UnserializableMessageAnalyzer.php)
  - Parcourt src/Message/ avec le Finder
  - Check 1 : message avec une propriete Closure ou resource (CRITICAL - non serializable)
  - Check 2 : message sans proprietes publiques ni getters (WARNING - deserialisation impossible)
  - supports() : verifie class_exists(MessageBusInterface::class)

- [ ] 20.5 Tests UnserializableMessageAnalyzerTest

- [ ] 20.6 MessengerTransportAnalyzer (src/Analyzer/Messenger/MessengerTransportAnalyzer.php)
  - Lit config/packages/messenger.yaml
  - Check 1 : messages routes vers `sync` (WARNING - annule le benefice de l'async)
  - Check 2 : absence de transport `failed` configure (WARNING - messages en echec perdus)
  - Check 3 : `retry_strategy` absente sur les transports async (SUGGESTION)
  - supports() : verifie la presence de config/packages/messenger.yaml

- [ ] 20.7 Tests MessengerTransportAnalyzerTest

- [ ] 20.8 MessengerSigningAnalyzer (src/Analyzer/Messenger/MessengerSigningAnalyzer.php)
  - Source : blog Symfony jan. 2026 - Symfony 7.4 ajoute la signature des messages.
    Sans signature, injection de payload forge possible dans la file.
    RunProcessHandler et RunCommandHandler sont dangereux sans signing.
  - Check 1 : Symfony >= 7.4 avec RunProcessHandler ou RunCommandHandler sans `sign: true` (CRITICAL)
  - Check 2 : Symfony >= 7.4 avec transport AMQP/Redis sans signing configure (WARNING)
  - Check 3 : Symfony < 7.4 avec handlers sensibles (RunProcessHandler, RunCommandHandler) (WARNING)
    Recommande upgrade vers 7.4 pour beneficier du signing
  - supports() : verifie la presence de config/packages/messenger.yaml

- [ ] 20.9 Tests MessengerSigningAnalyzerTest

- [ ] 20.10 Tag v1.9.0

---

## Phase 25 - Module API Platform [PRIORITE SUIVANTE - apres V1.9.0]

> Ce module est le prochain objectif prioritaire. Il constitue la killer feature du profil CV :
> aucun outil open source ne fait ca. Tout projet avec de l'API Platform souffre de mauvaises
> configurations (ressources publiques non intentionnelles, serialisation exposant des champs
> sensibles, pagination desactivee). SF-Doctor est le seul outil qui detecte ca localement.

Killer feature. API Platform est le composant Symfony le plus complexe et le plus mal configure. Aucun outil open source n'analyse sa configuration. Ce module seul justifie l'adoption de SF-Doctor par toute agence qui fait de l'API.

- [ ] 21.1 Ajouter Module::API_PLATFORM dans l'enum Module
  - Ajouter l'option `--api-platform` dans AuditCommand

- [ ] 21.2 OperationSecurityAnalyzer (src/Analyzer/ApiPlatform/OperationSecurityAnalyzer.php)
  - Parcourt src/Entity/ et src/ApiResource/ avec le Finder
  - Check 1 : operation GET/POST/PUT/DELETE sans attribut `security` (CRITICAL - resource publique non intentionnelle)
  - Check 2 : `security` avec `is_granted('PUBLIC_ACCESS')` explicite sur une resource de donnees sensibles (WARNING)
  - supports() : verifie class_exists(ApiResource::class)

- [ ] 21.3 Tests OperationSecurityAnalyzerTest

- [ ] 21.4 SerializationGroupAnalyzer (src/Analyzer/ApiPlatform/SerializationGroupAnalyzer.php)
  - Parcourt src/Entity/ avec le Finder
  - Check 1 : resource API Platform sans groupes de serialisation sur les proprietes (CRITICAL - toutes les proprietes exposees)
  - Check 2 : propriete nommee `password`, `token`, `secret` sans `#[Ignore]` ni groupe restrictif (CRITICAL)
  - Check 3 : aucun groupe `write` ou `input` defini (WARNING - modification de toutes les proprietes possible)
  - supports() : verifie class_exists(ApiResource::class)

- [ ] 21.5 Tests SerializationGroupAnalyzerTest

- [ ] 21.6 PaginationAnalyzer (src/Analyzer/ApiPlatform/PaginationAnalyzer.php)
  - Check 1 : pagination desactivee globalement (`defaults.pagination_enabled: false`) (WARNING - retourne toutes les donnees)
  - Check 2 : `pagination_client_enabled: true` sans `pagination_maximum_items_per_page` (WARNING - DoS possible)
  - Check 3 : `itemsPerPage` superieur a 100 (SUGGESTION)
  - supports() : verifie class_exists(ApiResource::class)

- [ ] 21.7 Tests PaginationAnalyzerTest

- [ ] 21.8 ValidationAnalyzer (src/Analyzer/ApiPlatform/ValidationAnalyzer.php)
  - Parcourt src/Entity/ avec le Finder
  - Check 1 : resource avec operation POST/PUT sans contraintes de validation (#[Assert\*]) (WARNING)
  - Check 2 : champ obligatoire (NOT NULL en base) sans #[Assert\NotBlank] (WARNING)
  - supports() : verifie class_exists(ApiResource::class)

- [ ] 21.9 Tests ValidationAnalyzerTest

- [ ] 21.10 Tag v2.0.0

---

## Phase 26 - Module Migration Symfony 7.x vers 8.0 [A FAIRE]

Timing parfait. Source : blog Symfony "Preparing for Symfony 7.4 and 8.0" (novembre 2025).
Symfony 8.0 = Symfony 7.4 sans les deprecations. Si ton projet utilise des features
depreciees, il est bloque sur 7.x. SF-Doctor detecte ce qui bloque la migration.
C'est le module Upgrade etendu avec les checks de migration specifiques a 7.x → 8.0.

- [ ] 22.1 Ajouter Module::MIGRATION dans l'enum Module
  - Ajouter l'option `--migration` dans AuditCommand

- [ ] 22.2 DeprecationUsageAnalyzer (src/Analyzer/Migration/DeprecationUsageAnalyzer.php)
  - Lit les deprecations loguees dans var/log/dev.log (si present)
  - Parcourt src/ pour detecter les patterns de code deprecies connus en Symfony 7.x
  - Check 1 : usage de `AbstractController::getDoctrine()` (deprecie en 6.4) (WARNING)
  - Check 2 : usage de `UserInterface::getRoles()` retournant array sans type-hint (WARNING)
  - Check 3 : services taggues avec l'ancienne syntaxe array (WARNING)
  - Check 4 : `AbstractType::getExtendedType()` non remplace par `getExtendedTypes()` (WARNING)
  - supports() : toujours true

- [ ] 22.3 Tests DeprecationUsageAnalyzerTest

- [ ] 22.4 BundleDependencyAnalyzer (src/Analyzer/Migration/BundleDependencyAnalyzer.php)
  - Lit composer.json pour identifier les bundles tiers
  - Check 1 : bundles connus comme incompatibles avec Symfony 7.x/8.0 (CRITICAL)
    Liste maintenue : FOSUserBundle (abandonne), SonataUserBundle < 5.0, etc.
  - Check 2 : bundles sans contrainte de version couvrant Symfony 7.x (WARNING)
  - Check 3 : bundles en end-of-life sans alternative connue (WARNING)
  - supports() : verifie la presence de composer.json

- [ ] 22.5 Tests BundleDependencyAnalyzerTest

- [ ] 22.6 PhpVersionAnalyzer (src/Analyzer/Migration/PhpVersionAnalyzer.php)
  - Lit composer.json (require.php)
  - Check 1 : version PHP < 8.2 (Symfony 8.0 requiert PHP 8.2 minimum) (CRITICAL)
  - Check 2 : version PHP < 8.3 (SUGGESTION - PHP 8.3 recommande pour Symfony 8.0)
  - Check 3 : contrainte PHP en ^8.1 ou ^8.0 bloquante (WARNING)
  - supports() : verifie la presence de composer.json

- [ ] 22.7 Tests PhpVersionAnalyzerTest

- [ ] 22.8 Tag v2.1.0

---

## Phase 27 - Module Twig [A FAIRE]

Source : blog Symfony "Hardening Symfony" jan. 2026 - HtmlSanitizer renforce, srcdoc retire.
Les failles XSS passent souvent par Twig. Et la logique metier dans les templates
est le pire anti-pattern Symfony - impossible a tester, impossible a maintenir.

- [ ] 23.1 Ajouter Module::TWIG dans l'enum Module

- [ ] 23.2 TwigRawFilterAnalyzer (src/Analyzer/Twig/TwigRawFilterAnalyzer.php)
  - Parcourt templates/ avec le Finder
  - Check 1 : usage de `| raw` sur une variable provenant d'un formulaire ou entite (CRITICAL - XSS)
  - Check 2 : usage de `| raw` sans commentaire justificatif (WARNING)
  - supports() : verifie la presence du dossier templates/

- [ ] 23.3 Tests TwigRawFilterAnalyzerTest

- [ ] 23.4 TwigSrcdocAnalyzer (src/Analyzer/Twig/TwigSrcdocAnalyzer.php)
  - Source : Hardening Symfony jan. 2026 - srcdoc retire des attributs autorises par defaut
  - Check 1 : usage de l'attribut `srcdoc` sur un `<iframe>` sans `sandbox` force (CRITICAL)
  - Check 2 : HtmlSanitizer configure avec srcdoc autorise sans sandbox (WARNING)
  - supports() : verifie la presence de templates/ ou config/packages/html_sanitizer.yaml

- [ ] 23.5 Tests TwigSrcdocAnalyzerTest

- [ ] 23.6 BusinessLogicInTwigAnalyzer (src/Analyzer/Twig/BusinessLogicInTwigAnalyzer.php)
  - Parcourt templates/ avec le Finder
  - Check 1 : blocs `{% set %}` avec logique complexe (plus de 3 conditions) (WARNING)
  - Check 2 : appels de methode en chaine de plus de 3 niveaux dans un template (SUGGESTION)
  - Check 3 : methodes de repository appelees directement dans Twig (CRITICAL)
  - supports() : verifie la presence du dossier templates/

- [ ] 23.7 Tests BusinessLogicInTwigAnalyzerTest

- [ ] 23.8 Tag v2.2.0

---

## Phase 28 - DX : mode fix + GitHub Action + SARIF [A FAIRE]

Ce qui transforme SF-Doctor d'un outil de detection en outil de productivite.

- [ ] 24.1 Output SARIF (src/Report/SarifReporter.php)
  - SARIF = format standard GitHub Code Scanning
  - Permet d'afficher les issues SF-Doctor directement dans l'onglet "Security" de GitHub
  - Chaque issue apparait comme une annotation sur la ligne de code concernee
  - `--format=sarif` genere un fichier `sf-doctor.sarif`

- [ ] 24.2 Tests SarifReporterTest

- [ ] 24.3 GitHub Action officielle (.github/action.yml)
  - Action publiee sur le GitHub Marketplace
  - Usage : `uses: pierre-arthur/sf-doctor-action@v1`
  - Upload automatique du rapport SARIF vers GitHub Code Scanning
  - Badge de score dans le README auto-genere

- [ ] 24.4 Mode --fix interactif (src/Command/FixCommand.php)
  - Nouvelle commande `sf-doctor:fix`
  - Pour chaque issue CRITICAL ou WARNING : propose le fix et demande confirmation
  - Corrections automatiques implementees en priorite :
    - Ajout de `secure: true` sur les cookies remember_me
    - Ajout de `httponly: true` sur les sessions
    - Suppression de `public: true` inutiles dans services.yaml
    - Ajout de `APP_ENV=prod` manquant dans .env.prod
    - Ajout de la config OPcache recommandee
  - Mode `--dry-run` pour voir les corrections sans les appliquer

- [ ] 24.5 Tests FixCommandTest

- [ ] 24.6 Tag v2.3.0

---

## Phase 29 - Dashboard web [A FAIRE]

Repository separe : `pierre-arthur/sf-doctor-dashboard`

Application Symfony standalone qui expose un dashboard de suivi de la qualite.

- [ ] 29.1 API REST pour recevoir les rapports JSON de SF-Doctor
- [ ] 29.2 Stockage des rapports en base (historique par projet)
- [ ] 29.3 Dashboard avec evolution du score dans le temps (Chart.js)
- [ ] 29.4 Comparaison entre deux analyses (diff d'issues)
- [ ] 29.5 Alertes par email/Slack quand le score baisse
- [ ] 29.6 Multi-projets (un dashboard pour toute une agence)
- [ ] 29.7 Authentification (API key par projet)
- [ ] 29.8 Tag v3.3.0 (sf-doctor) + v1.0.0 (sf-doctor-dashboard)

---

## Phase 30 - AI-powered suggestions [A FAIRE]

Ce qui rend SF-Doctor unique et justifie un modele payant premium.

- [ ] 30.1 FixSuggestionInterface (src/AI/FixSuggestionInterface.php)
  - Contrat : prend une Issue, retourne une suggestion de fix en langage naturel + un diff de code

- [ ] 30.2 LlmFixSuggestion (src/AI/LlmFixSuggestion.php)
  - Envoie le contexte de l'issue (fichier, ligne, code) a un LLM (OpenAI ou Anthropic)
  - Retourne : explication de la faille + code corrige + lien vers doc Symfony officielle

- [ ] 30.3 Option `--explain` dans AuditCommand
  - Pour chaque issue du rapport, affiche la suggestion AI
  - Necessite une cle API configuree dans sf_doctor.yaml (`ai.api_key`)

- [ ] 30.4 Mode `--explain-issue=ISSUE_ID`
  - Explique une seule issue en detail (pour les juniors qui ne comprennent pas le probleme)

- [ ] 30.5 BaselinePublisher (src/Baseline/BaselinePublisher.php)
  - Envoie les scores anonymises vers une API publique SF-Doctor (opt-in explicite)
  - Aucune donnee de code n'est envoyee : uniquement les scores par module et les types d'issues
  - Permet de generer les statistiques publiques : "38% des projets Symfony ont une session sur filesystem"

- [ ] 30.6 Commande `sf-doctor:benchmark`
  - Affiche la position du projet par rapport a la base anonymisee
  - "Votre score securite (45/100) est inferieur a 67% des projets Symfony"
  - "Votre score architecture (82/100) vous place dans le top 20%"

- [ ] 30.7 Tag v3.4.0

---

## Phase 31 - Score par couche [A FAIRE]

Aujourd'hui SF-Doctor retourne un score global 0-100.
Un CTO a besoin de savoir OU investir son prochain sprint, pas juste "c'est moyen".

- [ ] 31.1 ScoreEngine (src/Score/ScoreEngine.php)
  - Calcule un score distinct par dimension a partir des issues du rapport :
    - Securite : issues des analyzers Security
    - Architecture : issues des analyzers Architecture
    - Performance : issues des analyzers Performance + Doctrine
    - Maintenabilite : services publics inutiles, constructeurs lourds, logique dans Twig
    - Prod-readiness : DebugMode, secrets, mailer, cache, session, Monolog
    - Tests : couverture, tests manquants (Phase 30)
  - Chaque dimension : score 0-100, statut (critique/a-ameliorer/bon/excellent)

- [ ] 31.2 Enrichir ConsoleReporter avec le tableau de bord par dimension
  ```
  Securite        :  45/100  [████░░░░░░]  CRITIQUE - 3 CRITICAL
  Architecture    :  82/100  [████████░░]  BON
  Performance     :  91/100  [█████████░]  EXCELLENT
  Maintenabilite  :  60/100  [██████░░░░]  A AMELIORER
  Prod-readiness  :  38/100  [███░░░░░░░]  CRITIQUE - deploy bloque
  Tests           :  55/100  [█████░░░░░]  A AMELIORER
  ─────────────────────────────────────────
  Score global    :  62/100
  ```

- [ ] 31.3 Enrichir JsonReporter : ajouter `scores_by_dimension` dans summary
- [ ] 31.4 Enrichir PdfReporter : graphique radar par dimension
- [ ] 31.5 Tests ScoreEngineTest
- [ ] 31.6 Tag v2.7.0

---

## Phase 32 - Deployabilite [A FAIRE]

SF-Doctor verifie la config. Mais il ne verifie pas si le projet EST deployable.
Ce sont les erreurs qui font planter la prod 5 minutes apres le deploy.

- [ ] 32.1 MigrationStatusAnalyzer (src/Analyzer/Deployment/MigrationStatusAnalyzer.php)
  - Execute `doctrine:migrations:status` en mode dry-run sur le projet audite
  - Check 1 : migrations non jouees detectees (CRITICAL - schema BDD desynchronise)
    La premiere insertion ou requete sur une nouvelle colonne va planter.
  - Check 2 : migrations en attente depuis plus de 7 jours (WARNING)
  - fixCode : `bin/console doctrine:migrations:migrate --no-interaction`
  - docUrl : https://www.doctrine-project.org/projects/doctrine-migrations
  - supports() : verifie interface_exists(EntityManagerInterface::class)

- [ ] 32.2 Tests MigrationStatusAnalyzerTest

- [ ] 32.3 RequiredEnvVarsAnalyzer (src/Analyzer/Deployment/RequiredEnvVarsAnalyzer.php)
  - Lit .env ou .env.dist pour lister les variables requises
  - Verifie leur presence dans .env.prod ou les variables d'environnement systeme
  - Check 1 : variable definie dans .env sans valeur dans .env.prod (CRITICAL)
    Exemple : DATABASE_URL, MAILER_DSN, APP_SECRET vides ou absents en prod
  - Check 2 : variable avec valeur de placeholder ("changeme", "todo", "xxx") (CRITICAL)
  - fixCode : liste des variables manquantes a renseigner
  - supports() : verifie la presence de .env

- [ ] 32.4 Tests RequiredEnvVarsAnalyzerTest

- [ ] 32.5 AssetsAnalyzer (src/Analyzer/Deployment/AssetsAnalyzer.php)
  - Check 1 : dossier public/build/ absent ou vide (WARNING - assets non compiles)
  - Check 2 : manifest.json absent dans public/build/ (WARNING - versioning desactive)
  - Check 3 : package.json present sans node_modules/ (SUGGESTION - npm install non joue)
  - fixCode : `npm run build` ou `yarn build` selon le gestionnaire detecte
  - supports() : verifie la presence de package.json ou webpack.config.js

- [ ] 32.6 Tests AssetsAnalyzerTest

- [ ] 32.7 LogAnalyzer (src/Analyzer/Deployment/LogAnalyzer.php)
  - Lit var/log/prod.log si present (les 500 derniers Ko)
  - Check 1 : erreurs 500 recurrentes (plus de 10 occurrences d'une meme erreur) (CRITICAL)
    Affiche le message d'erreur + le nombre d'occurrences
  - Check 2 : deprecations actives (plus de 100 occurrences d'une deprecation) (WARNING)
    Source : var/log/dev.log
  - Check 3 : requetes Doctrine > 500ms detectees dans les logs Symfony profiler (WARNING)
  - supports() : verifie la presence de var/log/

- [ ] 32.8 Tests LogAnalyzerTest

- [ ] 32.9 Tag v2.8.0

---

## Phase 33 - Analyse des tests [A FAIRE]

Un projet "top du top" a une couverture de tests.
SF-Doctor verifie non seulement si les tests existent, mais s'ils couvrent
les parties CRITIQUES du projet : la securite et la logique metier.

- [ ] 33.1 Ajouter Module::TESTS dans l'enum Module

- [ ] 33.2 TestCoverageAnalyzer (src/Analyzer/Tests/TestCoverageAnalyzer.php)
  - Execute PHPUnit avec --coverage-xml en mode dry-run sur le projet audite
  - Check 1 : couverture globale < 40% sur src/ (CRITICAL)
  - Check 2 : couverture globale < 60% sur src/ (WARNING)
  - Check 3 : couverture < 80% sur src/Security/ (CRITICAL - la logique de securite non testee)
  - Check 4 : couverture < 70% sur src/Service/ (WARNING)
  - fixCode : guide pour lancer la couverture et les seuils recommandes
  - supports() : verifie la presence de phpunit.xml ou phpunit.xml.dist

- [ ] 33.3 Tests TestCoverageAnalyzerTest

- [ ] 33.4 SecurityTestAnalyzer (src/Analyzer/Tests/SecurityTestAnalyzer.php)
  - Parcourt src/Security/ avec le Finder pour lister les Voters
  - Parcourt tests/ pour chercher les tests correspondants
  - Check 1 : Voter sans test correspondant (CRITICAL - logique d'acces non testee)
  - Check 2 : Controller avec #[IsGranted] sans test d'acces refuse (WARNING)
  - Check 3 : FormType sans test de validation (WARNING)
  - supports() : verifie la presence de src/Security/ et tests/

- [ ] 33.5 Tests SecurityTestAnalyzerTest

- [ ] 33.6 TestFixtureAnalyzer (src/Analyzer/Tests/TestFixtureAnalyzer.php)
  - Parcourt tests/fixtures/ ou src/DataFixtures/ avec le Finder
  - Check 1 : fixture avec mot de passe en clair non hashe (CRITICAL)
    Pattern : `setPassword('password')`, `setPassword('123456')` sans hasher
  - Check 2 : fixture avec email de production reelle (WARNING)
    Pattern : emails non-example.com dans les fixtures
  - fixCode : utilisation de PasswordHasherInterface dans les fixtures
  - supports() : verifie la presence de tests/fixtures/ ou src/DataFixtures/

- [ ] 33.7 Tests TestFixtureAnalyzerTest

- [ ] 33.8 Tag v2.9.0

---

## Phase 34 - Surface d'attaque invisible [A FAIRE]

SF-Doctor verifie security.yaml. Mais la surface d'attaque reelle est plus large.
Ce sont les acces non intentionnels que personne ne voit jusqu'au pentest.

- [ ] 34.1 ExposedDebugEndpointsAnalyzer (src/Analyzer/Security/ExposedDebugEndpointsAnalyzer.php)
  - Check 1 : route /_profiler accessible sans firewall (CRITICAL)
  - Check 2 : route /_wdt accessible sans firewall (CRITICAL)
  - Check 3 : route /api/docs ou /api sans restriction (WARNING - expose le schema de l'API)
  - fixCode : regles access_control pour bloquer ces routes en prod
  - supports() : verifie la presence de security.yaml

- [ ] 34.2 Tests ExposedDebugEndpointsAnalyzerTest

- [ ] 34.3 PublicSensitiveFilesAnalyzer (src/Analyzer/Security/PublicSensitiveFilesAnalyzer.php)
  - Verifie la presence de fichiers dangereux dans public/
  - Check 1 : public/.env present (CRITICAL - expose tous les secrets)
  - Check 2 : public/composer.json ou public/composer.lock (WARNING - expose les dependances)
  - Check 3 : public/phpinfo.php present (CRITICAL)
  - Check 4 : public/info.php ou public/test.php presents (CRITICAL)
  - fixCode : commandes pour supprimer ces fichiers + regles .htaccess/nginx
  - supports() : verifie la presence du dossier public/

- [ ] 34.4 Tests PublicSensitiveFilesAnalyzerTest

- [ ] 34.5 BundleRouteExposureAnalyzer (src/Analyzer/Security/BundleRouteExposureAnalyzer.php)
  - Detecte les routes exposees par les bundles tiers sans protection
  - Check 1 : EasyAdminBundle installe sans firewall couvrant /admin (CRITICAL)
  - Check 2 : SonataAdminBundle installe sans firewall couvrant /admin (CRITICAL)
  - Check 3 : routes /api/docs d'API Platform sans restriction (WARNING)
  - fixCode : config firewall pour chaque bundle detecte
  - supports() : verifie la presence des bundles concernes dans composer.json

- [ ] 34.6 Tests BundleRouteExposureAnalyzerTest

- [ ] 34.7 SequentialIdAnalyzer (src/Analyzer/Security/SequentialIdAnalyzer.php)
  - Parcourt src/Entity/ avec le Finder
  - Check 1 : entite exposee en API avec ID auto-increment au lieu d'UUID (WARNING)
    Un ID sequentiel expose le volume de donnees et facilite l'enumeration
  - Check 2 : route avec {id} sans check que l'entite appartient a l'utilisateur courant (WARNING)
  - fixCode : migration vers UUID + exemple de Voter pour verifier l'appartenance
  - supports() : verifie class_exists(ApiResource::class) ou presence de src/Entity/

- [ ] 34.8 Tests SequentialIdAnalyzerTest

- [ ] 34.9 InterLayerCoherenceAnalyzer (src/Analyzer/Architecture/InterLayerCoherenceAnalyzer.php)
  - Verifie la coherence entre les couches du projet
  - Check 1 : entite #[ApiResource] sans Voter correspondant dans src/Security/ (CRITICAL)
    La resource est exposee sans logique d'autorisation
  - Check 2 : message Messenger sans entree dans messenger.yaml (CRITICAL)
    Le message sera ingere silencieusement par le transport par defaut
  - Check 3 : FormType mappant une entite sans #[Assert\*] sur les champs obligatoires (WARNING)
  - fixCode : template de Voter + config messenger.yaml + exemple Assert
  - supports() : toujours true (checks actives selon ce qui est detecte)

- [ ] 34.10 Tests InterLayerCoherenceAnalyzerTest

- [ ] 34.11 Tag v3.0.0

---

## Phase 35 - Rapport "Projet inconnu" [A FAIRE]

Le cas d'usage commercial le plus fort : une agence reprend un projet existant.
Elle veut savoir en 2 minutes si c'est une bombe ou un projet sain.
C'est le rapport qu'une agence facture 2000 EUR a son client.
SF-Doctor le genere en 30 secondes.

- [ ] 35.1 Commande `sf-doctor:full-audit` (src/Command/FullAuditCommand.php)
  - Lance TOUS les analyzers actives (tous modules confondus)
  - Auto-detecte le contexte (Phase 19)
  - Affiche une barre de progression detaillee
  - Genere automatiquement un rapport PDF complet

- [ ] 35.2 ProjectHealthSummary (src/Report/ProjectHealthSummary.php)
  - Analyse les metadonnees du projet via `git log`
  - Nombre de commits, contributeurs, age du projet, derniere activite
  - Verifie la presence de README.md, CHANGELOG.md, CONTRIBUTING.md
  - Verifie la presence et la qualite de la CI (GitHub Actions, GitLab CI)

- [ ] 35.3 Enrichir PdfReporter pour le rapport "Projet inconnu"
  - Page 1 : Executive summary (score par dimension, verdict global)
    Verdict : "Projet sain" / "Dette technique significative" / "Refonte recommandee"
  - Page 2 : Top 5 des CRITICAL a corriger en priorite (avec estimation temps)
  - Page 3-4 : Detail des issues par module avec le code de fix
  - Page 5 : Dette technique totale en heures (somme des estimatedFixMinutes)
  - Page 6 : Risques de securite avec niveau CVSSv3 estime
  - Page 7 : Sante du projet (git stats, couverture, CI)
  - Page 8 : Recommandation finale et roadmap de remediation

- [ ] 35.4 TechnicalDebtCalculator (src/Score/TechnicalDebtCalculator.php)
  - Calcule la dette technique totale en heures a partir des estimatedFixMinutes
  - Categorise par module et par priorite
  - Estime le cout en jours/homme selon un TJM configurable

- [ ] 35.5 Tests FullAuditCommandTest
- [ ] 35.6 Tests TechnicalDebtCalculatorTest

- [ ] 35.7 Tag v3.1.0

---

## Phase 36 - DX : mode fix + GitHub Action + SARIF + plugin IDE [A FAIRE]

Ce qui transforme SF-Doctor d'un outil en ecosysteme.
ESLint est indispensable non pas a cause du CLI, mais du retour immediat dans l'editeur.

- [ ] 36.1 Output SARIF (src/Report/SarifReporter.php)
  - SARIF = format standard GitHub Code Scanning
  - Affiche les issues directement dans l'onglet "Security" de GitHub
  - Chaque issue apparait comme annotation sur la ligne de code concernee
  - `--format=sarif` genere sf-doctor.sarif

- [ ] 36.2 Tests SarifReporterTest

- [ ] 36.3 GitHub Action officielle (.github/action.yml)
  - Publiee sur le GitHub Marketplace : `uses: pierre-arthur/sf-doctor-action@v1`
  - Upload automatique du SARIF vers GitHub Code Scanning
  - Badge de score dans le README auto-genere
  - Bloque la PR si un CRITICAL est introduit (via --diff)

- [ ] 36.4 Mode --fix interactif (src/Command/FixCommand.php)
  - `sf-doctor:fix` : propose le fix pour chaque CRITICAL/WARNING avec confirmation
  - Corrections automatiques :
    - Ajout de `secure: true` sur les cookies remember_me
    - Ajout de `httponly: true` sur les sessions
    - Suppression de `public: true` inutiles dans services.yaml
    - Correction APP_ENV/APP_DEBUG dans .env.prod
    - Ajout des headers HTTP manquants via NelmioSecurityBundle
  - Mode `--dry-run` pour voir les corrections sans les appliquer

- [ ] 36.5 Tests FixCommandTest

- [ ] 36.6 Language Server Protocol (src/Lsp/SfDoctorLanguageServer.php)
  - Implemente le protocole LSP pour l'integration IDE
  - Reponses aux requetes diagnostics, hover, code actions
  - Compatible VSCode, PhpStorm, Neovim via LSP

- [ ] 36.7 Extension VSCode (repository separe : pierre-arthur/sf-doctor-vscode)
  - Souligne les issues directement dans l'editeur
  - Tooltip au survol : message + businessImpact + lien doc
  - Code Action "Apply fix" : applique le fixCode automatiquement
  - Badge dans la barre de statut : "SF-Doctor : 3 CRITICAL | 7 WARNING"
  - Relance automatique a la sauvegarde (mode --watch integre)

- [ ] 36.8 Tag v3.2.0

---

## Etat du projet

- **Version** : v3.2.0
- **Tests** : 735 tests, 1549 assertions, tous verts
- **PHPStan** : level 8, zero erreur
- **CI** : GitHub Actions, 4 combinaisons (PHP 8.2/8.3 x Symfony 6.4/7.1), toutes vertes
- **PHP** : 8.3.29
- **PHPUnit** : 10.5.63
- **Packagist** : https://packagist.org/packages/pierre-arthur/sf-doctor
- **GitHub Action** : `.github/action.yml` pour CI/CD avec upload SARIF

### Versions publiees

| Version | Phase | Description |
|---------|-------|-------------|
| v0.1.0 | 1-7 | Setup initial, modeles, premiers analyzers, bundle Symfony |
| v0.2.0 | 8 | CsrfAnalyzer, ControllerAnalyzer, DebugModeAnalyzer, JsonReporter |
| v1.0.0 | 9 | NplusOneAnalyzer, ameliorations terrain |
| v1.1.0 | 10 | Corrections faux positifs, robustesse |
| v1.2.0 | 11 | Cache, events, enrichissement |
| v1.3.0 | 12 | ResultCache, ProgressSubscriber |
| v1.4.0 | 13 | Configuration TreeBuilder, CompilerPass filtre |
| v1.5.0 | 14 | Serializer (IssueNormalizer, AuditReportNormalizer) |
| v1.6.0 | 15 | Workflow (AuditContext, AuditWorkflow, StateMachine) |
| v1.7.0 | 16 | Messenger async mode |
| v1.8.0 | 17 | Mode --brief, --diff, --save-baseline, --watch |
| v1.9.0 | 18 | SecretsAnalyzer, ProfilerAnalyzer, MailerAnalyzer, HttpHeadersAnalyzer |
| v2.0.0 | 19 | ProjectContext + ProjectContextDetector, supports() context |
| v2.1.0 | 20 | InstallHooksCommand, mode git hooks |
| v2.2.0 | 21 | Security module complet + ProductionReadinessAnalyzer |
| v2.3.0 | 22 | Architecture module complet (ServiceInjection, HeavyConstructor, Voter, PublicService, EventSubscriber) |
| v2.4.0 | 23 | Doctrine module complet (EagerLoading, CascadeRisk, MissingIndex, RepositoryPattern, LazyGhostObject) |
| v2.5.0 | 24 | Messenger module complet (Transport, UnhandledMessage, UnserializableMessage, Signing) |
| v2.6.0 | 25 | API Platform module complet (OperationSecurity, SerializationGroup, Pagination, Validation) |
| v2.7.0 | 26 | Migration module (BundleDependency, DeprecationUsage, PhpVersion) |
| v2.8.0 | 28 | Score par dimension (ScoreEngine) + SARIF reporter |
| v2.9.0 | 30 | Module Tests complet (TestCoverage, SecurityTest, TestFixture) |
| v3.0.0 | 31 | Surface d'attaque invisible (BundleRouteExposure, SequentialId, InterLayerCoherence) |
| v3.1.0 | 32 | TechnicalDebtCalculator + FullAuditCommand rapport "Projet inconnu" |
| v3.2.0 | 33 | FixCommand mode fix interactif + GitHub Action officielle |

### Phases restantes (hors scope bundle)

| Version | Description | Notes |
|---------|-------------|-------|
| v3.3.0 | Dashboard web multi-projets | Repository separe : sf-doctor-dashboard |
| v3.4.0 | AI-powered suggestions | Necessite services externes (LLM API) |
| - | Extension VSCode | Repository separe : sf-doctor-vscode |
| - | Language Server Protocol | Necessite un serveur LSP PHP dedie |

## Historique Git

```
5400860 feat: initial project setup (composer, phpunit, phpstan)
xxxxxxx feat: add models (Severity, Module, Issue, AuditReport) with unit tests
xxxxxxx feat: add ConfigReaderInterface and YamlConfigReader with tests
xxxxxxx feat: add AnalyzerInterface and FirewallAnalyzer with tests
xxxxxxx feat: add AccessControlAnalyzer with tests
9c027b8 feat: add ReporterInterface and ConsoleReporter with tests
0cf9eca feat: add AuditCommand with manual wiring (bin/sf-doctor)
xxxxxxx feat: add AuditCommandTest with CommandTester (7 tests)
xxxxxxx feat: add SfDoctorBundle, Extension, services.yaml, autoconfigure, CompilerPass
xxxxxxx feat: add integration tests with TestKernel and KernelTestCase
xxxxxxx feat: add README.md
xxxxxxx ci: add GitHub Actions workflow
xxxxxxx fix: various CI fixes (composer update, missing files, version constraints)
facc468 tag: v0.1.0
xxxxxxx feat: add ParameterResolver to resolve Symfony parameters in config
xxxxxxx test: add unit tests for NullParameterResolver and ContainerParameterResolver
xxxxxxx feat: add CsrfAnalyzer (global config + FormType scan)
xxxxxxx feat: add ControllerAnalyzer (QueryBuilder and EntityManager in controllers)
xxxxxxx feat: add DebugModeAnalyzer (APP_ENV and APP_DEBUG checks in .env files)
d0d5a03 feat: add JsonReporter + fix namespaces PierreArthur\SfDoctor
xxxxxxx docs: update README for V0.2
xxxxxxx tag: v0.2.0
```

## Notes techniques

- Les analyzers utilisent `supports()` qui verifie `class_exists(SecurityBundle::class)`.
  Comme le SecurityBundle n'est pas installe dans le projet sf-doctor lui-meme,
  les analyzers sont ignores quand on lance `bin/sf-doctor` depuis la racine du projet.
  C'est le comportement attendu : SF-Doctor est un OUTIL qui audite d'AUTRES projets.
  Les tests unitaires fonctionnent car ils utilisent des mocks du ConfigReaderInterface.

- Le ConsoleReporter est cree en fallback dans AuditCommand::execute() pour la V0.1.
  En Phase 6, le container injectera tous les reporters via TaggedIterator.

- Le fichier `phpunit.xml.dist` utilise la balise `<source>` (PHPUnit 10+)
  au lieu de l'ancienne `<coverage>` pour eviter le deprecation warning.

- Les services du bundle sont declares `public: true` dans config/services.yaml.
  Sans ca, Symfony inline les services prives a la compilation et ils disparaissent
  du container. Les bundles rendent leurs services principaux publics pour que
  les developpeurs puissent y acceder (injection, decoration, remplacement).

- L'autoconfigure est enregistre dans SfDoctorBundle::build() :
  toute classe implementant AnalyzerInterface recoit automatiquement
  le tag "sf_doctor.analyzer". Plus besoin de le declarer dans services.yaml.

- Le AnalyzerCompilerPass verifie a la compilation que tous les services
  tagges "sf_doctor.analyzer" implementent bien AnalyzerInterface.
  Erreur de config detectee au deploy, pas en production.

- Le TestKernel (tests/Integration/TestKernel.php) utilise MicroKernelTrait
  et charge uniquement FrameworkBundle + SfDoctorBundle. Cache et logs
  rediriges vers sys_get_temp_dir() pour ne pas polluer le projet.

- La variable KERNEL_CLASS est definie dans phpunit.xml.dist pour que
  KernelTestCase sache quel kernel utiliser.

- Les contraintes Symfony sont en `^6.4 || ^7.0` pour supporter
  a la fois la LTS (6.4) et la derniere version stable (7.x).
  La CI verifie les deux branches.

- ParameterResolver (V0.2) : le mode standalone (bin/sf-doctor) n'a pas acces
  au container, donc il utilise NullParameterResolver (no-op).
  Le ContainerParameterResolver fonctionne uniquement en mode bundle
  (bin/console sf-doctor:audit) via l'alias "@parameter_bag" de Symfony.
  C'est un compromis acceptable pour la V0.2.

- CsrfAnalyzer (V0.2) : deux niveaux d'analyse. La desactivation globale dans
  framework.yaml est CRITICAL (une ligne desactive tout). La desactivation
  fichier par fichier dans un FormType est WARNING (peut etre justifie pour une API).
  Le Finder de Symfony est utilise pour parcourir src/Form/*.php.

- ControllerAnalyzer (V0.2) : le pattern regex couvre $this->em->, $this->entityManager->
  et $entityManager-> (avec ou sans $this->). Les methodes persist/flush/remove/find
  sont tolerees car elles font partie du cycle de vie des entites, pas des requetes metier.

- DebugModeAnalyzer (V0.2) : lit .env.prod en priorite sur .env car c'est le fichier
  charge par Symfony en production. Le parsing est manuel (pas de putenv/getenv) pour
  ne pas polluer l'environnement du process sf-doctor lui-meme. Gere les valeurs
  entre guillemets simples ou doubles, et ignore les commentaires (#).
  Place dans src/Analyzer/Configuration/ car le check concerne l'environnement
  d'execution, pas la securite applicative au sens strict (meme si les consequences
  sont securitaires).

- JsonReporter (V0.2) : le statut global est determine par la presence d'issues CRITICAL,
  pas par le score numerique. Un seul CRITICAL suffit a passer le statut en "critical",
  independamment du score. C'est le comportement attendu par les pipelines CI/CD.
  Utilise JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE pour un JSON lisible et correct
  avec les caracteres accentues.

- Migration namespace (V0.2) : le namespace racine est passe de SfDoctor\ a
  PierreArthur\SfDoctor\ pour respecter la convention Packagist (vendor/package).
  Migration faite via sed sur 35 fichiers PHP + config/services.yaml + phpunit.xml.dist.

- Les tests des analyzers filesystem (CsrfAnalyzer, ControllerAnalyzer) creent de vrais
  repertoires temporaires avec sys_get_temp_dir() + uniqid(). Le Finder a besoin de
  vrais fichiers sur le disque - pas de mock possible pour cette partie.

- ControllerAnalyzer (V1.1) : supports() utilisait class_exists() pour detecter
  EntityManagerInterface. Or c'est une INTERFACE, pas une classe. PHP distingue
  les deux : class_exists() retourne false pour une interface, il faut interface_exists().
  Bug detecte lors des tests terrain sur Sylius 1.x et 2.x.

- NplusOneAnalyzer (V1.1) : les acces Twig contenant `.vars.` sont exclus
  du check N+1. Ce sont des acces memoire Symfony (form.vars.value, field.vars.errors),
  pas des relations Doctrine. Detectes comme faux positifs sur Sylius 1.x et 2.x.

- AccessControlAnalyzer (V1.1) : le check "roles absent" a ete supprime.
  En Symfony, `roles` absent et `roles: []` sont semantiquement identiques.
  On ne peut pas distinguer un oubli d'une intention - le check generait
  trop de faux positifs sur les projets avec des routes publiques (Sylius).
  checkSensitivePaths est suspendu si des parametres non resolus (%param%)
  sont detectes dans les regles, pour eviter les faux positifs en mode standalone.

- Events (Phase 12) : le ProgressSubscriber et le CacheSubscriber sont instancies
  dans AuditCommand::execute() et non dans le container, car ils dependent de
  OutputInterface qui n'existe qu'au moment de l'execution. addSubscriber() les
  enregistre dynamiquement sur le dispatcher central.

- ResultCache (Phase 12) : le hash SHA256 est calcule sur le contenu concatene
  de tous les fichiers YAML du dossier config/. Les fichiers sont tries avant
  concatenation pour garantir un hash stable independamment de l'OS.
  ResultCacheInterface est extraite pour permettre le mock dans les tests unitaires
  (les classes final ne peuvent pas etre mockees par PHPUnit).

- Configuration (Phase 13) : le TreeBuilder valide la config utilisateur au boot du container.
  processConfiguration() fusionne les configs multi-sources (sf_doctor.yaml, sf_doctor_test.yaml, etc.)
  et applique les valeurs par defaut. Les parametres sont exposes via setParameter() pour etre
  accessibles dans les CompilerPass et les services via %sf_doctor.*%.
  L'attribut "module" sur les tags sf_doctor.analyzer permet au CompilerPass de filtrer
  les analyzers par module selon la config. Les analyzers sans attribut "module" sont
  toujours inclus - comportement attendu pour les analyzers tiers.

- Serializer (Phase 14) : IssueNormalizer et AuditReportNormalizer implementent NormalizerInterface.
  AuditReportNormalizer utilise NormalizerAwareTrait pour deleguer la normalisation des Issue
  au Serializer central, qui route automatiquement vers IssueNormalizer via getSupportedTypes().
  ReporterInterface::generate() recoit desormais OutputInterface en second argument - separation
  propre entre dependance structurelle (injectee au boot) et contexte d'execution (disponible
  uniquement au moment de la commande). ConsoleReporter n'a plus de constructeur et peut etre
  instancie sans arguments. JsonReporter est un vrai service avec AuditReportNormalizer injecte.
  AuditCommand utilise findReporter() qui retourne ?ReporterInterface - plus propre que le
  pattern $reported + flag booleen.

- Workflow (Phase 15) : AuditWorkflow::create() retourne une StateMachine configuree
  avec MethodMarkingStore(singleState: true). Le sujet AuditContext porte l'etat via
  getStatus()/setStatus(). Le try/catch dans AuditCommand::execute() garantit que
  la transition "fail" est appliquee meme si un analyzer leve une exception inattendue.

## Dependances

- symfony/console
- symfony/yaml
- symfony/finder
- symfony/dependency-injection
- symfony/http-kernel
- symfony/config
- symfony/framework-bundle (dev)
- phpunit/phpunit (dev)
- phpstan/phpstan (dev)
- symfony/serializer
- symfony/property-access