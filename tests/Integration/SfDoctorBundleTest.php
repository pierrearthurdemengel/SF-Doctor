<?php

namespace PierreArthur\SfDoctor\Tests\Integration;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Analyzer\Security\AccessControlAnalyzer;
use PierreArthur\SfDoctor\Analyzer\Security\FirewallAnalyzer;
use PierreArthur\SfDoctor\Command\AuditCommand;
use PierreArthur\SfDoctor\Config\YamlConfigReader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Test d'integration du bundle SF-Doctor.
 *
 * Demarre un vrai Kernel Symfony avec le bundle charge
 * et verifie que le cablage (services, tags, commande) fonctionne.
 */
final class SfDoctorBundleTest extends KernelTestCase
{
    // ---------------------------------------------------------------
    // 1. Le container compile sans erreur
    // ---------------------------------------------------------------
    public function testContainerCompiles(): void
    {
        // Si le bundle a un probleme (Extension cassee, CompilerPass
        // qui explose, services.yaml invalide), bootKernel() leve
        // une exception. Si on arrive ici, tout est bon.
        self::bootKernel();

        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // 2. Le YamlConfigReader est enregistre dans le container
    // ---------------------------------------------------------------
    public function testYamlConfigReaderIsRegistered(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $service = $container->get(YamlConfigReader::class);

        $this->assertInstanceOf(YamlConfigReader::class, $service);
    }

    // ---------------------------------------------------------------
    // 3. Les analyzers sont enregistres dans le container
    // ---------------------------------------------------------------
    public function testAnalyzersAreRegistered(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $firewall = $container->get(FirewallAnalyzer::class);
        $accessControl = $container->get(AccessControlAnalyzer::class);

        $this->assertInstanceOf(AnalyzerInterface::class, $firewall);
        $this->assertInstanceOf(AnalyzerInterface::class, $accessControl);
    }

    // ---------------------------------------------------------------
    // 4. La commande est enregistree et executable
    // ---------------------------------------------------------------
    public function testAuditCommandIsAvailable(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // Recupere la commande depuis le container
        $command = $container->get(AuditCommand::class);
        $this->assertInstanceOf(AuditCommand::class, $command);

        // Verifie qu'elle est executable via CommandTester
        $application = new Application('SF Doctor', 'test');
        $application->add($command);

        $tester = new CommandTester($application->find('sf-doctor:audit'));
        $tester->execute(['--security' => true]);

        // La commande s'execute sans crash.
        // Les analyzers ne trouveront rien car supports() retourne false
        // (le SecurityBundle n'est pas installé dans le projet de test).
        // Mais l'important est que le cablage fonctionne.
        $this->assertStringContainsString('Audit', $tester->getDisplay());
    }

    // ---------------------------------------------------------------
    // 5. L'autoconfigure tague bien les analyzers
    // ---------------------------------------------------------------
    public function testAnalyzersAreAutoconfigured(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // La commande recoit un iterable d'analyzers via !tagged_iterator.
        // Si l'autoconfigure fonctionne, les analyzers sont dedans.
        // On verifie indirectement : la commande existe et ne plante pas.
        $command = $container->get(AuditCommand::class);
        $this->assertInstanceOf(AuditCommand::class, $command);

        // Verification plus directe : les deux analyzers sont
        // des services valides dans le container.
        $this->assertTrue($container->has(FirewallAnalyzer::class));
        $this->assertTrue($container->has(AccessControlAnalyzer::class));
    }
}