<?php
/**
 * Created for plugin-component-batch
 * Date: 05.11.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\Batch;


use Leadvertex\Plugin\Components\Process\Process;

interface BatchHandlerInterface
{

    public function __invoke(Process $process, Batch $batch);

}