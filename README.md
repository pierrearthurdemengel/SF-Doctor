# SF-Doctor

**Outil CLI d'audit automatise pour projets Symfony.**

SF-Doctor analyse la configuration de vos projets Symfony et detecte les failles,
les mauvaises pratiques et les oublis de configuration. Un rapport clair, des
recommandations concretes, directement dans votre terminal ou au format JSON pour
votre pipeline CI/CD.

[![CI](https://github.com/sf-doctor/sf-doctor/actions/workflows/ci.yaml/badge.svg)](https://github.com/sf-doctor/sf-doctor/actions/workflows/ci.yaml)
[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](https://phpstan.org/)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

---

## Ce que SF-Doctor detecte

### Module Security

**FirewallAnalyzer** (`security.yaml`)
- Firewalls sans systeme d'authentification configure
- Firewalls sans regles d'access_control
- Firewalls en mode lazy sans authentication requise

**AccessControlAnalyzer** (`security.yaml`)
- Regles sans role defini (acces ouvert a tous)
- Utilisation de roles deprecies (`IS_AUTHENTICATED_ANONYMOUSLY`)
- Regles catch-all (`^/`) placees trop tot (bloquent les regles suivantes)
- Chemins sensibles (`/admin`, `/api`) sans restriction d'acces

**CsrfAnalyzer** (`framework.yaml`, `src/Form/`)
- Protection CSRF desactivee globalement dans `framework.yaml` (CRITICAL)
- Protection CSRF desactivee sur des FormType individuels (WARNING)

### Module Architecture

**ControllerAnalyzer** (`src/Controller/`)
- Requetes Doctrine (`createQueryBuilder`, `createQuery`) dans les controllers (CRITICAL)
- Acces direct a l'EntityManager pour des operations metier dans les controllers (WARNING)

### Module Configuration

**DebugModeAnalyzer** (`.env.prod`, `.env`)
- `APP_ENV` absent ou different de `prod` en production (CRITICAL/WARNING)
- `APP_DEBUG=true` active en production (CRITICAL)

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

Lancez l'audit :
```bash
bin/console sf-doctor:audit
```

Options disponibles :
```bash
# Auditer un chemin specifique
bin/console sf-doctor:audit /chemin/vers/le/projet

# Sortie JSON pour CI/CD
bin/console sf-doctor:audit --format=json
```

### En mode standalone (sans bundle)

SF-Doctor peut aussi fonctionner en dehors d'un projet Symfony :
```bash
vendor/bin/sf-doctor /chemin/vers/le/projet/symfony
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

 Module Configuration
 ---------------------

 ---------- -------------------- ----------------------- -----------
  Severite   Analyzer             Message                 Fichier
 ---------- -------------------- ----------------------- -----------
  CRITICAL   DebugModeAnalyzer    APP_DEBUG is true       .env.prod
 ---------- -------------------- ----------------------- -----------

 Score : 70/100
```

## Exemple de sortie JSON
```bash
bin/console sf-doctor:audit --format=json
```
```json
{
    "meta": {
        "generated_at": "2024-01-15T10:30:00+00:00",
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
            "line": null
        }
    ]
}
```

Bloquer la CI si le statut est `critical` :
```yaml
# .github/workflows/ci.yaml
- name: Audit Symfony
  run: |
    OUTPUT=$(bin/console sf-doctor:audit --format=json)
    STATUS=$(echo $OUTPUT | jq -r '.summary.status')
    if [ "$STATUS" = "critical" ]; then exit 1; fi
```

---

## Architecture

SF-Doctor est concu comme un bundle Symfony extensible. Chaque verification est un
**Analyzer** independant qui implemente `AnalyzerInterface`.
```
src/
├── Analyzer/
│   ├── Architecture/           # ControllerAnalyzer
│   ├── Configuration/          # DebugModeAnalyzer
│   └── Security/               # FirewallAnalyzer, AccessControlAnalyzer, CsrfAnalyzer
├── Command/                    # Commande CLI (sf-doctor:audit)
├── Config/                     # Lecture YAML + resolution des parametres Symfony
├── Model/                      # Issue, AuditReport, Severity, Module
├── Report/                     # ConsoleReporter, JsonReporter
└── DependencyInjection/        # Integration au container Symfony
```

### Creer un analyzer custom

Implementez `AnalyzerInterface` et le tag `sf_doctor.analyzer` est ajoute automatiquement
via autoconfigure :
```php
use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;

class MonAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private ConfigReaderInterface $configReader,
    ) {
    }

    public function supports(): bool
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

---

## Tests
```bash
# Lancer tous les tests
vendor/bin/phpunit

# Analyse statique (level 8)
vendor/bin/phpstan analyse src --level=8
```

---

## Roadmap

- **V0.1** (publiee) - Module Security : firewalls, access_control, commande CLI, rapport console
- **V0.2** (publiee) - ParameterResolver, CsrfAnalyzer, ControllerAnalyzer, DebugModeAnalyzer, JsonReporter
- **V0.3** - Ouverture aux contributions : CONTRIBUTING.md, guide pour creer un analyzer custom
- **V1.0** - Module Upgrade (migration entre versions Symfony), NplusOneAnalyzer, PdfReporter

---

## Licence

MIT. Voir le fichier [LICENSE](LICENSE).