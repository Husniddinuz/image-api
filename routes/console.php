<?php

use Illuminate\Support\Facades\Schedule;

// Safety net for lost prune jobs; the delete endpoint already queues its own.
Schedule::command('images:prune')->hourly()->withoutOverlapping();

// Sanctum tokens accumulate; drop the expired ones.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

Schedule::command('queue:prune-batches')->daily();
