<?php
/**
 * Created for plugin-core
 * Date: 19.03.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\Batch\Commands;


use Khill\Duration\Duration;
use Leadvertex\Plugin\Components\Db\Components\Connector;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use XAKEPEHOK\Path\Path;

class QueueCommand extends Command
{

    const MAX_MEMORY = 25 * 1024 * 1024;

    /** @var int */
    private $started;

    /** @var int */
    private $limit;

    /** @var resource */
    private $mutex;

    /** @var Process[] */
    private $processes = [];

    /** @var int */
    private $handed = 0;

    /** @var array */
    private $failed = [];

    public function __construct(string $name)
    {
        parent::__construct("queue:{$name}");
        $this->limit = $_ENV['LV_PLUGIN_QUEUE_LIMIT'] ?? 0;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->mutex = fopen((string) Path::root()->down('mutex.lock'), 'c');
        $this->started = time();
        if (!flock($this->mutex, LOCK_EX|LOCK_NB)) {
            fclose($this->mutex);
            throw new RuntimeException('Queue already running');
        }

        $db = Connector::db();
        $table = \Leadvertex\Plugin\Components\Process\Process::tableName();
        $lastTime = time();

        $this->writeUsedMemory($output);

        do {

            if ((time() - 5 ) > $lastTime) {
                $this->writeUsedMemory($output);
                $lastTime = time();
            }

            $data = $db->select(
                $table,
                ['companyId', 'id'],
                [
                    'tag_1' => \Leadvertex\Plugin\Components\Process\Process::STATE_SCHEDULED,
                    'id[!]' => array_keys($this->failed),
                    "ORDER" => ["createdAt" => "ASC"],
                    'LIMIT' => $this->limit
                ]
            );

            foreach ($this->processes as $key => $process) {
                if (!$process->isTerminated()) {
                    continue;
                }

                if ($process->isSuccessful()) {
                    $output->writeln("<fg=green>[FINISHED]</> Process id '{$key}' was finished.");
                } else {
                    $output->writeln("<fg=red>[FAILED]</> Process id '{$key}' with code '{$process->getExitCode()}' and message '{$process->getExitCodeText()}'.");
                    $ids = explode('_', $key);
                    $this->failed[$ids[1]] = true;
                }

                unset($this->processes[$key]);
            }

            foreach ($data as $ids) {
                if ($this->handleQueue($ids['companyId'], $ids['id'])) {
                    $output->writeln("<info>[STARTED]</info> Process id '{$ids['companyId']}_{$ids['id']}'.");
                }
            }

        } while (memory_get_usage(true) < self::MAX_MEMORY);

        $output->writeln('<info> -- High memory usage. Stopped -- </info>');

        flock($this->mutex, LOCK_UN);
        fclose($this->mutex);

        return 0;
    }

    private function handleQueue(string $companyId, string $id): bool
    {
        $this->processes = array_filter($this->processes, function (Process $process) {
            return $process->isRunning();
        });

        if ($this->limit > 0 && count($this->processes) >= $this->limit) {
            return false;
        }

        $key = "{$companyId}_{$id}";

        if (isset($this->processes[$key])) {
            return false;
        }

        $this->processes[$key] = new Process([
            $_ENV['LV_PLUGIN_PHP_BINARY'],
            (string) Path::root()->down('console.php'),
            str_replace('queue:', 'batch:', $this->getName()),
            $id,
            $companyId
        ]);

        $this->processes[$key]->start();

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