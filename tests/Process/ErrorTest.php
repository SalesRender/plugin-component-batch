<?php

namespace Leadvertex\Plugin\Components\Batch\Process;

use PHPUnit\Framework\TestCase;

class ErrorTest extends TestCase
{

    public function testCreateError()
    {
        $error = new Error('Test', 1);
        $this->assertEquals('Test', $error->getMessage());
        $this->assertEquals('1', $error->getEntityId());
    }

    public function testCreateErrorWithNUllEntity()
    {
        $error = new Error('Test');
        $this->assertEquals('Test', $error->getMessage());
        $this->assertNull($error->getEntityId());
    }

}