<?php

declare(strict_types=1);

namespace BluePsyduck\SymfonyProcessManager;

use Symfony\Component\Process\Process;

/**
 * The process manager for executing multiple processes in parallel.
 *
 * @author BluePsyduck <bluepsyduck@gmx.com>
 * @license http://opensource.org/licenses/GPL-3.0 GPL v3
 */
class ProcessManager implements ProcessManagerInterface
{
    /**
     * The number of processes to run in parallel.
     * @var int
     */
    protected $numberOfParallelProcesses;

    /**
     * The interval to wait between the polls of the processes, in milliseconds.
     * @var int
     */
    protected $pollInterval;

    /**
     * The time to delay the start of processes to space them out, in milliseconds.
     * @var int
     */
    protected $processStartDelay;

    /**
     * The processes currently waiting to be executed.
     * @var array
     */
    protected $pendingProcessData = [];

    /**
     * The processes currently running.
     * @var array|Process[]
     */
    protected $runningProcesses = [];

    /**
     * The callback for when a process is about to be started.
     * @var callable|null
     */
    protected $processStartCallback;

    /**
     * The callback for when a process has finished.
     * @var callable|null
     */
    protected $processFinishCallback;

    /**
     * ProcessManager constructor.
     * @param int $numberOfParallelProcesses The number of processes to run in parallel.
     * @param int $pollInterval The interval to wait between the polls of the processes, in milliseconds.
     * @param int $processStartDelay The time to delay the start of processes to space them out, in milliseconds.
     */
    public function __construct(
        int $numberOfParallelProcesses = 1,
        int $pollInterval = 100,
        int $processStartDelay = 0
    ) {
        $this->numberOfParallelProcesses = $numberOfParallelProcesses;
        $this->pollInterval = $pollInterval;
        $this->processStartDelay = $processStartDelay;
    }

    /**
     * Sets the number of processes to run in parallel.
     * @param int $numberOfParallelProcesses
     * @return $this
     */
    public function setNumberOfParallelProcesses(int $numberOfParallelProcesses)
    {
        $this->numberOfParallelProcesses = $numberOfParallelProcesses;
        $this->executeNextPendingProcess(); // Start new processes in case we increased the limit.
        return $this;
    }

    /**
     * Sets the interval to wait between the polls of the processes, in milliseconds.
     * @param int $pollInterval
     * @return $this
     */
    public function setPollInterval(int $pollInterval)
    {
        $this->pollInterval = $pollInterval;
        return $this;
    }

    /**
     * Sets the time to delay the start of processes to space them out, in milliseconds.
     * @param int $processStartDelay
     * @return $this
     */
    public function setProcessStartDelay(int $processStartDelay)
    {
        $this->processStartDelay = $processStartDelay;
        return $this;
    }

    /**
     * Sets the callback for when a process is about to be started.
     * @param callable|null $processStartCallback The callback, accepting a Process as only argument.
     * @return $this
     */
    public function setProcessStartCallback(?callable $processStartCallback)
    {
        $this->processStartCallback = $processStartCallback;
        return $this;
    }

    /**
     * Sets the callback for when a process has finished.
     * @param callable|null $processFinishCallback The callback, accepting a Process as only argument.
     * @return $this
     */
    public function setProcessFinishCallback(?callable $processFinishCallback)
    {
        $this->processFinishCallback = $processFinishCallback;
        return $this;
    }

    /**
     * Invokes the callback if it is an callable.
     * @param callable|null $callback
     * @param Process $process
     */
    protected function invokeCallback(?callable $callback, Process $process): void
    {
        if (is_callable($callback)) {
            $callback($process);
        }
    }

    /**
     * Adds a process to the manager.
     * @param Process $process
     * @param callable|null $callback
     * @param array $env
     * @return $this
     */
    public function addProcess(Process $process, callable $callback = null, array $env = [])
    {
        $this->pendingProcessData[] = [$process, $callback, $env];
        $this->executeNextPendingProcess();
        $this->checkRunningProcesses();
        return $this;
    }

    /**
     * Executes the next pending process, if the limit of parallel processes is not yet reached.
     */
    protected function executeNextPendingProcess(): void
    {
        if ($this->canExecuteNextPendingRequest()) {
            $this->sleep($this->processStartDelay);

            [$process, $callback, $env] = array_shift($this->pendingProcessData);
            /* @var Process $process */
            $this->invokeCallback($this->processStartCallback, $process);
            $process->start($callback, $env);

            $pid = $process->getPid();
            if ($pid === null) {
                // The process finished before we were able to check its process id.
                $this->checkRunningProcess($pid, $process);
            } else {
                $this->runningProcesses[$pid] = $process;
            }
        }
    }

    /**
     * Checks whether a pending request is available and can be executed.
     * @return bool
     */
    protected function canExecuteNextPendingRequest(): bool
    {
        return count($this->runningProcesses) < $this->numberOfParallelProcesses
            && count($this->pendingProcessData) > 0;
    }

    /**
     * Checks the running processes whether they have finished.
     */
    protected function checkRunningProcesses(): void
    {
        foreach ($this->runningProcesses as $pid => $process) {
            $this->checkRunningProcess($pid, $process);
        }
    }

    /**
     * Checks the process whether it has finished.
     * @param int|null $pid
     * @param Process $process
     */
    protected function checkRunningProcess(?int $pid, Process $process): void
    {
        $process->checkTimeout();
        if (!$process->isRunning()) {
            $this->invokeCallback($this->processFinishCallback, $process);

            if ($pid !== null) {
                unset($this->runningProcesses[$pid]);
            }
            $this->executeNextPendingProcess();
        }
    }

    /**
     * Waits for all processes to be finished.
     * @return $this
     */
    public function waitForAllProcesses()
    {
        while ($this->hasUnfinishedProcesses()) {
            $this->sleep($this->pollInterval);
            $this->checkRunningProcesses();
        }
        return $this;
    }

    /**
     * Sleeps for the specified number of milliseconds.
     * @param int $milliseconds
     */
    protected function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    /**
     * Returns whether the manager still has unfinished processes.
     * @return bool
     */
    public function hasUnfinishedProcesses(): bool
    {
        return count($this->pendingProcessData) > 0 || count($this->runningProcesses) > 0;
    }
}
