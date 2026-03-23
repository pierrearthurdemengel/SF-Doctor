<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Config\NullParameterResolver;

final class NullParameterResolverTest extends TestCase
{
    private NullParameterResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new NullParameterResolver();
    }

    public function testResolveStringReturnsSameValue(): void
    {
        $this->assertSame('%some.param%', $this->resolver->resolveString('%some.param%'));
    }

    public function testResolveStringWithPlainStringReturnsSameValue(): void
    {
        $this->assertSame('/admin', $this->resolver->resolveString('/admin'));
    }

    public function testResolveArrayReturnsSameArray(): void
    {
        $config = [
            'security' => [
                'access_control' => [
                    ['path' => '%sylius.security.admin_regex%', 'roles' => 'ROLE_ADMIN'],
                ],
            ],
        ];

        $this->assertSame($config, $this->resolver->resolveArray($config));
    }

    public function testResolveArrayWithEmptyArrayReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->resolver->resolveArray([]));
    }
}