<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Integration;

use PierreArthur\SfDoctor\SfDoctorBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

// Kernel de test avec une config sf_doctor personnalisee.
// Permet de tester le comportement du bundle selon differentes configs.
class TestKernelWithConfig extends Kernel
{
    // La config a injecter dans le bundle, sous forme de tableau.
    public function __construct(
        private readonly array $sfDoctorConfig = []
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new SfDoctorBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'test' => true,
                'secret' => 'test',
            ]);

            // Charge la config sf_doctor passee au constructeur.
            if (!empty($this->sfDoctorConfig)) {
                $container->loadFromExtension('sf_doctor', $this->sfDoctorConfig);
            }
        });
    }

    // Redirige le cache vers un repertoire temporaire unique par config.
    // Sans ca, deux kernels differents se marcheraient dessus.
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/sf_doctor_test_' . md5(serialize($this->sfDoctorConfig));
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/sf_doctor_logs';
    }
}