# SF-Doctor

**Outil CLI d'audit automatise pour projets Symfony.**

SF-Doctor analyse la configuration de securite de vos projets Symfony et detecte les failles, les mauvaises pratiques et les oublis de configuration. Un rapport clair, des recommandations concretes, directement dans votre terminal.

[![CI](https://github.com/sf-doctor/sf-doctor/actions/workflows/ci.yaml/badge.svg)](https://github.com/sf-doctor/sf-doctor/actions/workflows/ci.yaml)
[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](https://phpstan.org/)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

---

## Ce que SF-Doctor detecte (V0.1 - Module Security)

**Analyse des firewalls** (`security.yaml`)
- Firewalls sans systeme d'authentification configure
- Firewalls sans regles d'access_control
- Firewalls en mode lazy sans authentication requise

**Analyse de l'access_control**
- Regles sans role defini (acces ouvert a tous)
- Utilisation de roles deprecies (`IS_AUTHENTICATED_ANONYMOUSLY`)
- Regles catch-all (`^/`) placees trop tot (bloquent les regles suivantes)
- Chemins sensibles (`/admin`, `/api`) sans restriction d'acces

---

## Prerequis

- PHP 8.2 ou superieur
- Un projet Symfony 6.4 ou 7.x

---

## Installation

```bash
composer require --dev sf-doctor/sf-doctor
```

---

## Utilisation

### En tant que bundle Symfony (recommande)

Si votre projet utilise Symfony Flex, le bundle est enregistre automatiquement.

Sinon, ajoutez-le dans `config/bundles.php` :

```php
return [
    // ...
    SfDoctor\SfDoctorBundle::class => ['dev' => true, 'test' => true],
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

# Auditer uniquement le module securite
bin/console sf-doctor:audit --security
```

### En mode standalone (sans bundle)

SF-Doctor peut aussi fonctionner en dehors d'un projet Symfony, via son script integre :

```bash
vendor/bin/sf-doctor /chemin/vers/le/projet/symfony
```

---

## Exemple de sortie

```
 SF-Doctor - Audit de securite
 ==============================

 Analyse du projet : /var/www/mon-projet

 SECURITE - Analyse des firewalls
 ---------------------------------

 ---------- ---------- ---------------------------------------------------
  Severite   Fichier    Description
 ---------- ---------- ---------------------------------------------------
  CRITICAL   security   Le firewall "main" n'a aucun authenticator configure
  WARNING    security   Le firewall "main" n'a pas de regle access_control
 ---------- ---------- ---------------------------------------------------

 SECURITE - Analyse de l'access_control
 ----------------------------------------

 ---------- ---------- ---------------------------------------------------
  Severite   Fichier    Description
 ---------- ---------- ---------------------------------------------------
  CRITICAL   security   La route "/admin" n'a aucune restriction d'acces
  WARNING    security   Le role IS_AUTHENTICATED_ANONYMOUSLY est deprecie
 ---------- ---------- ---------------------------------------------------

 Score global : 35/100

 2 problemes critiques, 2 avertissements, 0 suggestions
```

---

## Architecture

SF-Doctor est concu comme un bundle Symfony extensible. Chaque verification est un **Analyzer** independant qui implemente `AnalyzerInterface`.

```
src/
├── Analyzer/           # Les analyseurs (coeur metier)
│   └── Security/       # Module securite
│       ├── FirewallAnalyzer.php
│       └── AccessControlAnalyzer.php
├── Command/            # Commande CLI (sf-doctor:audit)
├── Config/             # Lecture des fichiers YAML du projet audite
├── Model/              # Issue, AuditReport, Severity, Module
├── Report/             # Generateurs de rapports (console, JSON, PDF)
└── DependencyInjection/  # Integration au container Symfony
```

### Creer un analyzer custom

Implementez `AnalyzerInterface` et le tag `sf_doctor.analyzer` est ajoute automatiquement via autoconfigure :

```php
use SfDoctor\Analyzer\AnalyzerInterface;
use SfDoctor\Config\ConfigReaderInterface;
use SfDoctor\Model\AuditReport;

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

Aucune configuration supplementaire necessaire. SF-Doctor detecte et execute automatiquement tous les services qui implementent `AnalyzerInterface`.

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

- **V0.1** (actuelle) - Module Security : firewalls, access_control, commande CLI, rapport console
- **V0.2** - Module Security complet : CSRF, donnees sensibles, mode debug, remember_me, rapport JSON
- **V0.3** - Module Architecture : controllers, injection, repositories, voters, services publics
- **V0.4** - Module Performance : eager loading, cache, Messenger, mode async
- **V0.5** - Extensibilite : workflow, serializer, configuration avancee, guide de contribution

---

## Contribuer

Les contributions sont les bienvenues. Consultez le guide de contribution (a venir) pour savoir comment ajouter un analyzer custom ou ameliorer les analyzers existants.

---

## Licence

MIT. Voir le fichier [LICENSE](LICENSE).
