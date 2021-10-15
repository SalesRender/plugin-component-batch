<?php
/**
 * Created for plugin-component-batch
 * Datetime: 03.07.2018 14:41
 * @author Timur Kasumov aka XAKEPEHOK
 */

namespace Leadvertex\Plugin\Components\Batch\Commands;


use Leadvertex\Plugin\Components\Access\Token\GraphqlInputToken;
use Leadvertex\Plugin\Components\Batch\Batch;
use Leadvertex\Plugin\Components\Batch\BatchContainer;
use Leadvertex\Plugin\Components\Batch\Process\Error;
use Leadvertex\Plugin\Components\Batch\Process\Process;
use Leadvertex\Plugin\Components\Db\Components\Connector;
use Leadvertex\Plugin\Components\Queue\Commands\QueueHandleCommand;
use Leadvertex\Plugin\Components\Translations\Translator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class BatchHandleCommand extends QueueHandleCommand
{

    public function __construct()
    {
        parent::__construct("batch");
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Throwable
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var Batch $batch */
        $batch = Batch::findById($input->getArgument('id'));
        if (is_null($batch)) {
            return Command::SUCCESS;
        }

        GraphqlInputToken::setInstance($batch->getToken());

        Connector::setReference($batch->getToken()->getPluginReference());
        Translator::setLang(str_replace('-', '_', $batch->getLang()));

        /** @var Process $process */
        $process = Process::findById($batch->getId());

        try {
            $handler = BatchContainer::getHandler();
            $handler($process, $batch);
        } catch (Throwable $exception) {
            $error = new Error('Fatal plugin error. Please contact plugin developer.');
            $process->terminate($error);
            $process->save();
            throw $exception;
        }

        return Command::SUCCESS;
    }

}