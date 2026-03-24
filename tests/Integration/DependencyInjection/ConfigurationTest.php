<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Integration\DependencyInjection;

use PierreArthur\SfDoctor\Analyzer\Security\FirewallAnalyzer;
use PierreArthur\SfDoctor\Analyzer\Performance\NplusOneAnalyzer;
use PierreArthur\SfDoctor\Tests\Integration\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use PierreArthur\SfDoctor\Tests\Integration\TestKernelWithConfig;


class ConfigurationTest extends KernelTestCase
{
    // Verifie que le score_threshold par defaut est 0.
    public function testDefaultScoreThreshold(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->assertSame(0, $container->getParameter('sf_doctor.score_threshold'));
    }

    // Verifie que tous les modules sont actives par defaut.
    public function testDefaultAnalyzersConfig(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->assertTrue($container->getParameter('sf_doctor.analyzers.security'));
        $this->assertTrue($container->getParameter('sf_doctor.analyzers.architecture'));
        $this->assertTrue($container->getParameter('sf_doctor.analyzers.performance'));
    }

    // Verifie que le FirewallAnalyzer est present dans le container par defaut.
    public function testSecurityAnalyzerRegisteredByDefault(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->assertTrue($container->has(FirewallAnalyzer::class));
    }

    // Verifie que le NplusOneAnalyzer est present dans le container par defaut.
    public function testPerformanceAnalyzerRegisteredByDefault(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->assertTrue($container->has(NplusOneAnalyzer::class));
    }

    // Verifie que les analyzers Security sont absents quand security: false.
    public function testSecurityAnalyzersRemovedWhenModuleDisabled(): void
    {
        $kernel = new TestKernelWithConfig([
            'analyzers' => ['security' => false],
        ]);
        $kernel->boot();
        $container = $kernel->getContainer();

        $this->assertFalse($container->has(FirewallAnalyzer::class));
    }

    // Verifie que les analyzers Performance sont absents quand performance: false.
    public function testPerformanceAnalyzersRemovedWhenModuleDisabled(): void
    {
        $kernel = new TestKernelWithConfig([
            'analyzers' => ['performance' => false],
        ]);
        $kernel->boot();
        $container = $kernel->getContainer();

        $this->assertFalse($container->has(NplusOneAnalyzer::class));
    }
    
}