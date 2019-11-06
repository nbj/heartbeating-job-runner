<?php

use Illuminate\Console\Command;

class LoopingCommand extends Command
{
    /**
     * Create a new looping command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @param bool $runOnce
     *
     * @return void
     *
     * @throws Exception
     */
    public function handle($runOnce = false)
    {

    }
}
