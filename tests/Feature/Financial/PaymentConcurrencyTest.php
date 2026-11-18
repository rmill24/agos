<?php

use App\Models\Obligation;
use App\Models\Transaction;
use App\Services\ObligationService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

it('serializes simultaneous settlements on independent PostgreSQL connections', function (bool $sameSubmission) {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL row locking is required.');
    }

    [$actor, $team] = p2Business();
    $supplier = $team->suppliers()->create(['name' => 'Stationery']);
    $date = now('Asia/Manila')->toDateString();
    $bill = app(ObligationService::class)->create($actor, $team, 'bill', [
        'contact_id' => $supplier->id, 'description' => 'Supplies', 'total' => '100',
        'issue_date' => $date, 'due_date' => $date,
    ]);
    $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
DB::select("select set_config('application_name', ?, false)", [$argv[6]]);
try {
    app(App\Services\ObligationService::class)->pay(
        App\Models\User::findOrFail($argv[1]), App\Models\Team::findOrFail($argv[2]),
        'bill', (int) $argv[3], '75.00', $argv[4], $argv[5]
    );
    echo 'recorded';
} catch (Illuminate\Validation\ValidationException $e) {
    echo 'rejected';
}
PHP;
    $config = DB::connection()->getConfig();
    $environment = ['APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'pgsql'];
    foreach (['host', 'port', 'database', 'username', 'password'] as $name) {
        $environment['DB_'.strtoupper($name)] = (string) $config[$name];
    }
    $tag = 'agos-test-'.Str::uuid();
    $key = (string) Str::uuid();
    $workers = [];
    DB::beginTransaction();
    try {
        $team->newQuery()->whereKey($team->id)->lockForUpdate()->firstOrFail();
        foreach ([$key, $sameSubmission ? $key : (string) Str::uuid()] as $submission) {
            $process = new Process([PHP_BINARY, '-r', $worker, '--', (string) $actor->id, (string) $team->id, (string) $bill->id, $date, $submission, $tag], base_path(), $environment);
            $process->start();
            $workers[] = $process;
        }
        $deadline = microtime(true) + 15;
        do {
            $waiting = (int) DB::scalar("select count(*) from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'", [$tag]);
            // Refresh PostgreSQL's statistics snapshot inside the control transaction.
            DB::select('select pg_stat_clear_snapshot()');
            if ($waiting < 2) {
                usleep(20000);
            }
        } while ($waiting < 2 && microtime(true) < $deadline);
    } finally {
        DB::rollBack();
    }
    $outcomes = [];
    foreach ($workers as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $outcomes[] = trim($process->getOutput());
    }
    sort($outcomes);
    expect($waiting)->toBe(2)
        ->and($outcomes)->toBe($sameSubmission ? ['recorded', 'recorded'] : ['recorded', 'rejected'])
        ->and(Transaction::count())->toBe(1)
        ->and((string) Obligation::findOrFail($bill->id)->balance())->toBe('25.00');
})->with([true, false]);
