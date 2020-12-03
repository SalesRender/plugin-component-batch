<?php
/**
 * Created for plugin-component-batch
 * Datetime: 03.07.2018 14:41
 * @author Timur Kasumov aka XAKEPEHOK
 */

namespace Leadvertex\Plugin\Components\Batch\Commands;


use Leadvertex\Plugin\Components\Access\Token\GraphqlInputToken;
use Leadvertex\Plugin\Components\Batch\Batch;
use Leadvertex\Plugin\Components\Batch\BatchHandler;
use Leadvertex\Plugin\Components\Db\Components\Connector;
use Leadvertex\Plugin\Components\Process\Components\Error;
use Leadvertex\Plugin\Components\Process\Process;
use Leadvertex\Plugin\Components\Translations\Translator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class BatchHandleCommand extends Command
{

    public function __construct()
    {
        parent::__construct("batch:handle");
    }

    protected function configure()
    {
        $this
            ->setDescription('Run handle operation in background')
            ->addArgument('id', InputArgument::REQUIRED);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void|null
     * @throws Throwable
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $batch = Batch::findById($input->getArgument('id'));
        if (is_null($batch)) {
            return 0;
        }

        GraphqlInputToken::setInstance($batch->getToken());

        Connector::setReference($batch->getToken()->getPluginReference());
        Translator::setLang(str_replace('-', '_', $batch->getLang()));

        $process = Process::findById($batch->getId());

        try {
            BatchHandler::getInstance()($process, $batch);
        } catch (Throwable $exception) {
            $error = new Error('Fatal plugin error. Please contact plugin developer.');
            $process->terminate($error);
            $process->save();
            throw $exception;
        }

        return 0;
    }

}