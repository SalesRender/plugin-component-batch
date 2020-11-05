<?php
/**
 * Created for plugin-core.
 * Datetime: 03.07.2018 14:41
 * @author Timur Kasumov aka XAKEPEHOK
 */

namespace Leadvertex\Plugin\Components\Batch\Commands;


use Leadvertex\Plugin\Components\Batch\Batch;
use Leadvertex\Plugin\Components\Batch\BatchHandlerInterface;
use Leadvertex\Plugin\Components\Db\Components\Connector;
use Leadvertex\Plugin\Components\Process\Components\Error;
use Leadvertex\Plugin\Components\Process\Process;
use Leadvertex\Plugin\Components\Translations\Translator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class BackgroundCommand extends Command
{

    /** @var BatchHandlerInterface */
    private $handler;

    public function __construct(string $name, BatchHandlerInterface $handler)
    {
        parent::__construct("batch:{$name}");
        $this->handler = $handler;
    }

    protected function configure()
    {
        $this
            ->setDescription('Run handle operation in background')
            ->addArgument('id', InputArgument::REQUIRED)
            ->addArgument('companyId', InputArgument::REQUIRED);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void|null
     * @throws Throwable
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        Connector::setCompanyId($input->getArgument('companyId'));
        $batch = Batch::findById($input->getArgument('id'));

        Translator::setLang(str_replace('-', '_', $batch->lang));

        $process = Process::findById($batch->getId());

        try {
            $handler = $this->handler;
            $handler($process, $batch);
        } catch (Throwable $exception) {
            $error = new Error('Fatal plugin error. Please contact plugin developer.');
            $process->terminate($error);
            $process->save();
            throw $exception;
        }

        return 0;
    }

}