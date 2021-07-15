<?php
/**
 * Created for plugin-component-batch
 * Date: 19.03.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\Batch\Commands;


use Khill\Duration\Duration;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Leadvertex\Plugin\Components\Process\Process as ProcessModel;
use XAKEPEHOK\Path\Path;

class BatchQueueCommand extends Command
{

    const MAX_MEMORY = 25 * 1024 * 1024;

    private int $started;

    private int $limit;

    /** @var Process[] */
    private array $processes = [];

    private int $handed = 0;

    private array $failed = [];

    public function __construct()
    {
        parent::__construct("batch:queue");
        $this->limit = $_ENV['LV_PLUGIN_QUEUE_LIMIT'] ?? 0;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $mutex = fopen((string) Path::root()->down('runtime')->down('batch.mutex'), 'c');
        $this->started = time();
        if (!flock($mutex, LOCK_EX|LOCK_NB)) {
            fclose($mutex);
            throw new RuntimeException('Queue already running');
        }

        $this->writeUsedMemory($output);

        $lastTime = time();
        do {

            if ((time() - 5 ) > $lastTime) {
                $this->writeUsedMemory($output);
                $lastTime = time();
            }

            foreach ($this->processes as $key => $process) {
                if (!$process->isTerminated()) {
                    continue;
                }

                if ($process->isSuccessful()) {
                    $output->writeln("<fg=green>[FINISHED]</> Process id '{$key}' was finished.");
                } else {
                    $output->writeln("<fg=red>[FAILED]</> Process id '{$key}' with code '{$process->getExitCode()}' and message '{$process->getExitCodeText()}'.");
                    $this->failed[$key] = true;
                }

                unset($this->processes[$key]);
            }

            /** @var ProcessModel[] $processes */
            $processes = ProcessModel::findByCondition([
                'state' => ProcessModel::STATE_SCHEDULED,
                'id[!]' => array_keys($this->failed),
                "ORDER" => ["createdAt" => "ASC"],
                'LIMIT' => $this->limit
            ]);

            foreach ($processes as $process) {
                if ($this->handleQueue($process)) {
                    $output->writeln("<info>[STARTED]</info> Process id '{$process->getId()}' for company #{$process->getCompanyId()} & plugin #{$process->getPluginId()}.");
                }
            }

            sleep(1);

        } while (memory_get_usage(true) < self::MAX_MEMORY);

        $output->writeln('<info> -- High memory usage. Stopped -- </info>');

        flock($mutex, LOCK_UN);
        fclose($mutex);

        return 0;
    }

    private function handleQueue(ProcessModel $processModel): bool
    {
        $this->processes = array_filter($this->processes, function (Process $process) {
            return $process->isRunning();
        });

        if ($this->limit > 0 && count($this->processes) >= $this->limit) {
            return false;
        }

        if (isset($this->processes[$processModel->getId()])) {
            return false;
        }

        $this->processes[$processModel->getId()] = new Process([
            $_ENV['LV_PLUGIN_PHP_BINARY'],
            (string) Path::root()->down('console.php'),
            'batch:handle',
            $processModel->getId(),
        ]);

        $this->processes[$processModel->getId()]->start();

        $this->handed++;

        return true;
    }

    private function writeUsedMemory(OutputInterface $output)
    {
        $used = round(memory_get_usage(true) / 1024 / 1024, 2);
        $max = round(self::MAX_MEMORY / 1024 / 1024, 2);
        $uptime = (new Duration(max(time() - $this->started, 1)))->humanize();
        $output->writeln("<info> -- Handed: {$this->handed}; Used {$used} MB of {$max} MB; Uptime: {$uptime} -- </info>");
    }

}