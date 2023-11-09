<?php
/**
 * Created for plugin-component-batch
 * Date: 05.11.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace SalesRender\Plugin\Components\Batch;


use SalesRender\Plugin\Components\Batch\Process\Process;

interface BatchHandlerInterface
{

    public function __invoke(Process $process, Batch $batch);

}