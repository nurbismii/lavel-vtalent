<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('portal:cleanup')->daily()->withoutOverlapping();
