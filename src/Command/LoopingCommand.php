<?php

namespace Nbj\Command;

use Carbon\Carbon;
use Nbj\Stopwatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

abstract class LoopingCommand extends Command
{
    /**
     * Holds the padding time in microseconds between each cycle
     *
     * The default value is 100.000 microseconds. This translates
     * to the loop checking if it is time to run a job 10 times
     * each second
     *
     * @var int $cycleTimePadding
     */
    protected $cycleTimePadding = 100000;

    /**
     * Determines the interval in which a job should be run.
     * Valid values for intervals are each:
     *  - second (default)
     *  - minute
     *  - hour
     *  - day
     *
     * @var string $scheduleInterval
     */
    protected $runInterval = 'second';

    /**
     * Holds the previous timestamp of the internal schedule
     *
     * @var Carbon $previousTimeStamp
     */
    protected $previousTimeStamp = null;

    /**
     * Tells the job to send ZMQ heartbeats
     *
     * @var bool $shouldSendHeartbeats
     */
    protected $shouldSendHeartbeats = false;

    /**
     * Create a new looping command instance.
     */
    public function __construct()
    {
        parent::__construct();

        if (method_exists($this, 'sendZMQHeartbeat')) {
            $this->shouldSendHeartbeats = true;
        }
    }

    /**
     * Execute the console command.
     *
     * @param bool $runOnce
     * @param bool $logProcessTime
     *
     * @return void
     *
     */
    public function handle($runOnce = false, $logProcessTime = false)
    {
        do {
            $processTime = null;

            try {
                $processTime = Stopwatch::time(function () {
                    $this->checkAndRunInternalSchedule();
                });
            } catch (Exception $exception) {
                $message = sprintf("Error running job [%s] ExceptionMessage: %s Trace: %s", get_class($this), $exception->getMessage(), $exception->getTraceAsString());

                Log::error($message);
            }

            // Sleep the remainder of the cycle
            $waitTime = $this->cycleTimePadding;

            if ($processTime != null) {
                if ($logProcessTime) {
                    error_log($processTime->millisecondsAsFloat());
                }

                $waitTime = $this->cycleTimePadding < $processTime->microseconds()
                    ? 0
                    : $this->cycleTimePadding - $processTime->microseconds();
            }

            usleep($waitTime);
        } while ($runOnce == false);
    }

    /**
     * Checks the internal schedule and runs the job if eligible
     */
    private function checkAndRunInternalSchedule()
    {
        $timeStamp = Carbon::now();

        // Check if we need to run process() each cycle
        if ($this->runInterval == 'everyTick') {
            $this->process();

            return;
        }

        // Bail out if a second has not passed since last run
        if ($this->previousTimeStamp && $timeStamp->diffInSeconds($this->previousTimeStamp) == 0) {
            return;
        }

        // Handle heartbeats every 5 seconds
        //
        // NOTE: This is only applicable as long as the inheriting class
        //       uses the SendsZMQHeartbeats trait. The sendZMQHeartbeat()
        //       method does not exist on this class by itself, hence the
        //       check that has been added to the constructor.
        if ($this->shouldSendHeartbeats && $timeStamp->second % 5 == 0) {
            $this->sendZMQHeartbeat();
        }

        // Run the process() method of the job based on its interval setting
        if ($this->runInterval == 'second') {
            $this->process();
        }

        if ($this->runInterval == 'minute' && $timeStamp->second == 0) {
            $this->process();
        }

        if ($this->runInterval == 'hour' && $timeStamp->minute == 0) {
            $this->process();
        }

        if ($this->runInterval == 'day' && $timeStamp->hour == 0) {
            $this->process();
        }

        $this->previousTimeStamp = $timeStamp;
    }

    /**
     * This method must be implemented by each job. This should
     * contain the actual code the job needs to perform in a loop
     */
    abstract public function process();
}
