<?php

namespace SalesRender\Plugin\Components\Batch\Process;

use InvalidArgumentException;
use SalesRender\Plugin\Components\Db\Components\PluginReference;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProcessTest extends TestCase
{

    private PluginReference $reference;

    private Process $process;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reference = new PluginReference(1, 'plugin', 2);
        $this->process = new Process($this->reference, 10, 'Description here');
    }

    public function testCreateProcess()
    {
        $this->assertEquals(1, $this->process->getCompanyId());
        $this->assertEquals(2, $this->process->getPluginId());
        $this->assertEquals(10, $this->process->getId());
        $this->assertEquals(Process::STATE_SCHEDULED, $this->process->getState());
        $this->process->initialize(100);
        $this->assertEquals('Description here', $this->process->getDescription());
        $this->assertTrue($this->process->isInitialized());
        $this->assertNull($this->process->getResult());
        $this->assertEmpty($this->process->getLastErrors());
        $this->assertEquals(Process::STATE_PROCESSING, $this->process->getState());
    }

    public function testSetDescription()
    {
        $this->process->setDescription('New description');
        $this->assertEquals('New description', $this->process->getDescription());
        $this->process->setDescription(null);
        $this->assertNull($this->process->getDescription());
    }

    public function testDoubleInitProcess()
    {
        $this->expectException(RuntimeException::class);
        $this->process->initialize(100);
        $this->process->initialize(100);
    }

    public function testSetProcessState()
    {
        $this->process->initialize(100);
        $this->process->setState(Process::STATE_POST_PROCESSING);
        $this->assertEquals(Process::STATE_POST_PROCESSING, $this->process->getState());
    }

    public function testSetInvalidProcessState()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->process->initialize(100);
        $this->process->setState('TestState');
    }

    public function testProcessActionWithoutInit()
    {
        $this->expectException(RuntimeException::class);
        $this->assertFalse($this->process->isInitialized());
        $this->process->handle();
    }

    public function testProcessHandle()
    {
        $this->process->initialize(100);
        $this->assertEquals(0, $this->process->getHandledCount());
        $this->process->handle();
        $this->assertEquals(1, $this->process->getHandledCount());
    }

    public function testProcessSkip()
    {
        $this->process->initialize(100);
        $this->assertEquals(0, $this->process->getSkippedCount());
        $this->process->skip();
        $this->assertEquals(1, $this->process->getSkippedCount());
    }

    public function testProcessAddError()
    {
        $this->process->initialize(100);
        $this->assertEquals(0, $this->process->getFailedCount());
        $this->process->addError(new Error('Test error', 1));
        $this->assertEquals(1, $this->process->getFailedCount());
        $this->assertInstanceOf(Error::class, $this->process->getLastErrors()[0]);
    }

    public function testProcessAddManyErrors()
    {
        $threshold = 20;
        $this->process->initialize($threshold + 2);
        for ($i = 0; $i < $threshold + 2; $i++) {
            $this->process->addError(new Error('TestError', $i));
        }
        $this->assertCount($threshold, $this->process->getLastErrors());
    }

    public function testProcessTerminate()
    {
        $this->process->initialize(100);
        $this->process->terminate(new Error('Test fatal error', 2));
        $this->assertEquals(Process::STATE_ENDED, $this->process->getState());
        $this->assertEquals(100, $this->process->getFailedCount());
        $this->assertCount(1,  $this->process->getLastErrors());
    }

    public function testProcessTerminateWithNullInit()
    {
        $this->process->initialize(null);
        $this->process->terminate(new Error('Test fatal error', 2));
        $this->assertEquals(1, $this->process->getFailedCount());
        $this->assertCount(1,  $this->process->getLastErrors());
    }

    public function testProcessTerminateWithoutInitialization()
    {
        $this->process->terminate(new Error('Test fatal error', 2));
        $this->assertEquals(1, $this->process->getFailedCount());
        $this->assertCount(1,  $this->process->getLastErrors());
    }

    public function testProcessFinish()
    {
        $process = new Process($this->reference, 1);
        $process->initialize(100);
        $process->finish(1);
        $this->assertEquals(1, $process->getResult());
        $this->assertEquals(Process::STATE_ENDED, $process->getState());

        $process = new Process($this->reference, 1);
        $process->initialize(100);
        $process->finish('Test');
        $this->assertEquals('Test', $process->getResult());
        $this->assertEquals(Process::STATE_ENDED, $process->getState());

        $process = new Process($this->reference, 1);
        $process->initialize(100);
        $process->finish(false);
        $this->assertEquals(false, $process->getResult());
        $this->assertEquals(Process::STATE_ENDED, $process->getState());
    }

    public function testProcessHandleAfterFinish()
    {
        $this->expectException(LogicException::class);
        $this->process->initialize(100);
        $this->process->finish(true);
        $this->process->handle();
    }

    public function testProcessFinishInvalidArgumentType()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->process->initialize(100);
        $this->process->finish(null);
    }

    public function testProcessJsonSerialize()
    {
        $this->process->initialize(100);
        $this->process->addError(new Error( 'Test error'));
        $this->process->handle();
        $this->process->handle();
        $this->process->skip();
        $this->process->skip();
        $this->process->skip();
        $this->process->finish(true);

        $this->processInfoArray = $this->process->jsonSerialize();

        $this->assertIsArray($this->processInfoArray);

        $this->assertEquals('1', $this->processInfoArray['companyId']);
        $this->assertEquals('2', $this->processInfoArray['pluginId']);
        $this->assertEquals('Description here', $this->processInfoArray['description']);

        $this->assertArrayHasKey('initialized', $this->processInfoArray);
        $this->assertArrayHasKey('timestamp', $this->processInfoArray['initialized']);
        $this->assertEquals($this->process->getInitializedAt(), $this->processInfoArray['initialized']['timestamp']);
        $this->assertArrayHasKey('value', $this->processInfoArray['initialized']);
        $this->assertEquals(100, $this->processInfoArray['initialized']['value']);

        $this->assertArrayHasKey('handled', $this->processInfoArray);
        $this->assertEquals(2, $this->processInfoArray['handled']);

        $this->assertArrayHasKey('skipped', $this->processInfoArray);
        $this->assertEquals(97, $this->processInfoArray['skipped']);

        $this->assertArrayHasKey('failed', $this->processInfoArray);
        $this->assertArrayHasKey('count', $this->processInfoArray['failed']);
        $this->assertEquals(1, $this->processInfoArray['failed']['count']);
        $this->assertArrayHasKey('last', $this->processInfoArray['failed']);
        $this->assertIsArray($this->processInfoArray['failed']['last']);
        $this->assertCount(1, $this->processInfoArray['failed']['last']);

        $this->assertArrayHasKey('result', $this->processInfoArray);
        $this->assertArrayHasKey('timestamp', $this->processInfoArray['result']);
        $this->assertEquals($this->process->getUpdatedAt(), $this->processInfoArray['result']['timestamp']);
        $this->assertArrayHasKey('value', $this->processInfoArray['result']);
        $this->assertEquals(true, $this->processInfoArray['result']['value']);
    }

    public function testProcessJsonSerializeWithoutInitAndResult()
    {
        $this->processInfoArray = $this->process->jsonSerialize();
        $this->assertArrayHasKey('initialized', $this->processInfoArray);
        $this->assertNull($this->processInfoArray['initialized']);

        $this->assertArrayHasKey('result', $this->processInfoArray);
        $this->assertNull($this->processInfoArray['result']);
    }
}