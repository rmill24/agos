<?php

use App\Models\ReportPdf;
use App\Services\ReportPdfService;
use App\Services\ReportSnapshotService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

it('admits only one of two simultaneous Teams competing for the final waiting slot', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Independent PostgreSQL connections are required.');
    }
    [$owner, $team] = p2Business();
    $snapshots = app(ReportSnapshotService::class);
    app(ReportPdfService::class)->request($owner, $team, $snapshots->create($owner, $team, 'payables')->id);
    $contenders = [];
    foreach ([1, 2] as $index) {
        [$actor, $business] = p2Business();
        $contenders[] = [$actor->id, $business->id, $snapshots->create($actor, $business, 'receivables')->id];
    }
    $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
DB::select("select set_config('application_name', ?, false)", [$argv[1]]);
try {
    app(App\Services\ReportPdfService::class)->request(App\Models\User::findOrFail($argv[2]), App\Models\Team::findOrFail($argv[3]), (int) $argv[4]);
    echo 'queued';
} catch (Illuminate\Validation\ValidationException $error) {
    echo 'full';
}
PHP;
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => ''];
    foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
        $environment['DB_'.strtoupper($key)] = (string) DB::connection()->getConfig($key);
    }
    $tag = 'pdf-test-'.Str::uuid();
    $workers = [];
    DB::beginTransaction();
    try {
        DB::selectOne("select pg_advisory_xact_lock(hashtext('agos.pdf.admission'))");
        foreach ($contenders as $ids) {
            $process = new Process([PHP_BINARY, '-r', $script, '--', $tag, ...array_map('strval', $ids)], base_path(), $environment);
            $process->start();
            $workers[] = $process;
        }
        $deadline = microtime(true) + 15;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::scalar("select count(*) from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'", [$tag]);
            if ($waiting < 2) {
                usleep(20000);
            }
        } while ($waiting < 2 && microtime(true) < $deadline);
    } finally {
        DB::rollBack();
    }
    $results = [];
    foreach ($workers as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $results[] = trim($process->getOutput());
    }
    sort($results);
    expect((int) $waiting)->toBe(2)->and($results)->toBe(['full', 'queued'])
        ->and(ReportPdf::where('status', 'queued')->count())->toBe(2)->and(DB::table('jobs')->where('queue', 'pdf')->count())->toBe(2);
});
