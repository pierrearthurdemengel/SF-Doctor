<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;

/**
 * Test bidon pour vérifier que PHPUnit est correctement configuré.
 * On le supprimera dès qu'on aura de vrais tests.
 */
class SmokeTest extends TestCase
{
    public function testPhpUnitWorks(): void
    {
        // assertTrue vérifie que la valeur passée est true.
        // Si c'est le cas → vert. Sinon → rouge.
        $this->assertTrue(true);
    }
}