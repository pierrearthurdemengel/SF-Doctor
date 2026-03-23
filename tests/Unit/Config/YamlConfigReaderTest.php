<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Config\YamlConfigReader;

class YamlConfigReaderTest extends TestCase
{
    // Le chemin vers notre faux projet.
    // On le calcule une fois et on le réutilise dans chaque test.
    private string $fixturesPath;

    // setUp() est une méthode spéciale de PHPUnit.
    // Elle s'exécute AVANT chaque méthode de test.
    // C'est l'endroit pour préparer ce qui est commun à tous les tests.
    //
    // PHPUnit gère le cycle de vie
    // des objets test d'une façon particulière. setUp() est la méthode pour initialisation.
    protected function setUp(): void
    {
        // __DIR__ = le dossier du fichier actuel (tests/Unit/Config/)
        // On remonte de 2 niveaux (../../) pour arriver à tests/
        // puis on descend dans Fixtures/valid-project/
        $this->fixturesPath = __DIR__ . '/../../Fixtures/valid-project';
    }

    // --- Tests de read() ---

    public function testReadValidYamlReturnsArray(): void
    {
        // Arrange : on crée un reader pointé vers notre faux projet
        $reader = new YamlConfigReader($this->fixturesPath);

        // Act : on lit le fichier security.yaml de la fixture
        $result = $reader->read('config/packages/security.yaml');

        // Assert : on vérifie que c'est bien un tableau
        $this->assertIsArray($result);

        // Et qu'il contient la clé "security" à la racine
        $this->assertArrayHasKey('security', $result);
    }

    public function testReadValidYamlParsesFirewalls(): void
    {
        $reader = new YamlConfigReader($this->fixturesPath);
        $result = $reader->read('config/packages/security.yaml');

        // On vérifie qu'on peut naviguer dans la structure parsée.
        // security → firewalls → main → lazy
        $this->assertIsArray($result);
        $this->assertArrayHasKey('firewalls', $result['security']);
        $this->assertArrayHasKey('main', $result['security']['firewalls']);
        $this->assertTrue($result['security']['firewalls']['main']['lazy']);
    }

    public function testReadValidYamlParsesAccessControl(): void
    {
        $reader = new YamlConfigReader($this->fixturesPath);
        $result = $reader->read('config/packages/security.yaml');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('access_control', $result['security']);

        // access_control est une liste de règles
        $this->assertCount(2, $result['security']['access_control']);

        // La première règle concerne /admin
        $this->assertSame('^/admin', $result['security']['access_control'][0]['path']);
    }

    public function testReadNonExistentFileReturnsNull(): void
    {
        $reader = new YamlConfigReader($this->fixturesPath);

        // Ce fichier n'existe pas dans nos fixtures
        $result = $reader->read('config/packages/je-nexiste-pas.yaml');

        $this->assertNull($result);
    }

    public function testReadEmptyFileReturnsNull(): void
    {
        $reader = new YamlConfigReader($this->fixturesPath);

        // Le fichier truly-empty.yaml est vide (0 octet).
        // Yaml::parseFile retourne null pour un fichier vide.
        // Le reader convertit ça en null (ce n'est pas un tableau).
        $result = $reader->read('config/packages/truly-empty.yaml');

        $this->assertNull($result);
    }

    public function testReadCommentOnlyFileReturnsNull(): void
    {
        $reader = new YamlConfigReader($this->fixturesPath);

        // empty.yaml ne contient que des commentaires.
        // Pour le parser YAML, c'est la même chose que vide.
        $result = $reader->read('config/packages/empty.yaml');

        $this->assertNull($result);
    }

    // --- Tests de exists() ---

    public function testExistsReturnsTrueForExistingFile(): void
    {
        $reader = new YamlConfigReader($this->fixturesPath);

        $this->assertTrue($reader->exists('config/packages/security.yaml'));
    }

    public function testExistsReturnsFalseForNonExistentFile(): void
    {
        $reader = new YamlConfigReader($this->fixturesPath);

        $this->assertFalse($reader->exists('config/packages/fantome.yaml'));
    }

    public function testExistsReturnsFalseForDirectory(): void
    {
        $reader = new YamlConfigReader($this->fixturesPath);

        // "config/packages" est un DOSSIER, pas un fichier.
        // exists() utilise is_file(), donc ça retourne false.
        $this->assertFalse($reader->exists('config/packages'));
    }

    // --- Test avec un chemin de projet ayant un slash final ---

    public function testWorksWithTrailingSlash(): void
    {
        // Le projectPath a un "/" à la fin.
        // Notre resolvePath() fait un rtrim, donc ça ne doit pas poser problème.
        $reader = new YamlConfigReader($this->fixturesPath . '/');

        $result = $reader->read('config/packages/security.yaml');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('security', $result);
    }
}