<?php
/**
 * Created for plugin-component-batch
 * Date: 19.03.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\Batch\Commands;


use Leadvertex\Plugin\Components\Db\ModelInterface;
use Leadvertex\Plugin\Components\Queue\QueueCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Leadvertex\Plugin\Components\Process\Process as ProcessModel;

class BatchQueueCommand extends QueueCommand
{


    private array $failed = [];

    public function __construct()
    {
        parent::__construct("batch", $_ENV['LV_PLUGIN_QUEUE_LIMIT']);
    }

    protected function findModels(): array
    {
        ProcessModel::freeUpMemory();
        return ProcessModel::findByCondition([
            'state' => ProcessModel::STATE_SCHEDULED,
            'id[!]' => array_keys($this->processes),
            "ORDER" => ["createdAt" => "ASC"],
            'LIMIT' => $this->limit
        ]);
    }

    /**
     * @param ProcessModel|ModelInterface $model
     * @param OutputInterface $output
     */
    protected function startedLog(ModelInterface $model, OutputInterface $output): void
    {
        $output->writeln("<info>[STARTED]</info> Process id '{$model->getId()}' for company #{$model->getCompanyId()} & plugin #{$model->getPluginId()}.");
    }
}