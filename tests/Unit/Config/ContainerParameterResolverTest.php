<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Config\ContainerParameterResolver;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class ContainerParameterResolverTest extends TestCase
{
    // --- resolveString ---

    public function testResolveStringReplacesKnownParameter(): void
    {
        $bag = $this->createMock(ParameterBagInterface::class);
        $bag->method('get')->with('sylius.security.admin_regex')->willReturn('^/admin');

        $resolver = new ContainerParameterResolver($bag);

        $this->assertSame('^/admin', $resolver->resolveString('%sylius.security.admin_regex%'));
    }

    public function testResolveStringReturnOriginalWhenNoDelimiters(): void
    {
        $bag = $this->createMock(ParameterBagInterface::class);
        $bag->expects($this->never())->method('get');

        $resolver = new ContainerParameterResolver($bag);

        $this->assertSame('/admin', $resolver->resolveString('/admin'));
    }

    public function testResolveStringReturnOriginalWhenParameterNotFound(): void
    {
        $bag = $this->createMock(ParameterBagInterface::class);
        $bag->method('get')->willThrowException(new ParameterNotFoundException('unknown.param'));

        $resolver = new ContainerParameterResolver($bag);

        // Le parametre est inconnu : on retourne la reference originale.
        $this->assertSame('%unknown.param%', $resolver->resolveString('%unknown.param%'));
    }

    public function testResolveStringReturnOriginalWhenValueIsNotScalar(): void
    {
        $bag = $this->createMock(ParameterBagInterface::class);
        // Un parametre peut etre un tableau (ex: framework.mailer.transports).
        // On ne peut pas le convertir en string : on retourne la reference.
        $bag->method('get')->with('array.param')->willReturn(['a', 'b']);

        $resolver = new ContainerParameterResolver($bag);

        $this->assertSame('%array.param%', $resolver->resolveString('%array.param%'));
    }

    public function testResolveStringConvertsIntegerParameterToString(): void
    {
        $bag = $this->createMock(ParameterBagInterface::class);
        $bag->method('get')->with('app.timeout')->willReturn(30);

        $resolver = new ContainerParameterResolver($bag);

        $this->assertSame('30', $resolver->resolveString('%app.timeout%'));
    }

    // --- resolveArray ---

    public function testResolveArrayReplacesParametersRecursively(): void
    {
        $bag = $this->createMock(ParameterBagInterface::class);
        $bag->method('get')->willReturnMap([
            ['sylius.security.admin_regex', '^/admin'],
            ['sylius.security.api_regex', '^/api'],
        ]);

        $resolver = new ContainerParameterResolver($bag);

        $input = [
            'security' => [
                'access_control' => [
                    ['path' => '%sylius.security.admin_regex%', 'roles' => 'ROLE_ADMIN'],
                    ['path' => '%sylius.security.api_regex%', 'roles' => 'ROLE_API'],
                ],
            ],
        ];

        $expected = [
            'security' => [
                'access_control' => [
                    ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                    ['path' => '^/api', 'roles' => 'ROLE_API'],
                ],
            ],
        ];

        $this->assertSame($expected, $resolver->resolveArray($input));
    }

    public function testResolveArrayLeavesNonStringValuesUntouched(): void
    {
        $bag = $this->createMock(ParameterBagInterface::class);
        $bag->expects($this->never())->method('get');

        $resolver = new ContainerParameterResolver($bag);

        $input = ['enabled' => true, 'count' => 42, 'ratio' => 1.5];

        $this->assertSame($input, $resolver->resolveArray($input));
    }

    public function testResolveArrayWithEmptyArrayReturnsEmptyArray(): void
    {
        $bag = $this->createMock(ParameterBagInterface::class);
        $resolver = new ContainerParameterResolver($bag);

        $this->assertSame([], $resolver->resolveArray([]));
    }
}