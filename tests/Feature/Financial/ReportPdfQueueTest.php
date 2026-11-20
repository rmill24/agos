<?php

use App\Enums\GlobalRole;
use App\Integrations\Gotenberg\GotenbergClient;
use App\Jobs\GenerateReportPdf;
use App\Models\ReportPdf;
use App\Services\ReportPdfService;
use App\Services\ReportSnapshotService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->actor, $this->team] = p2Business();
    $this->snapshot = app(ReportSnapshotService::class)->create($this->actor, $this->team, 'cash-flow');
    $this->queue = app(ReportPdfService::class);
    config(['services.gotenberg' => ['base_url' => 'https://render.example.test', 'username' => 'fixture', 'password' => 'synthetic', 'timeout' => 20]]);
    Http::preventStrayRequests();
});

it('admits one job per snapshot and converts its exact HTML with the dedicated database worker', function () {
    $data = require base_path('tests/Fixtures/report-pdf.php');
    $this->snapshot = $this->team->reportSnapshots()->create(['kind' => $data['kind'], 'data' => $data,
        'html' => view('reports.document', $data)->render(), 'created_at' => now()]);
    $bytes = file_get_contents(base_path('tests/Fixtures/report-receivables.pdf'));
    Http::fake(['render.example.test/*' => Http::response($bytes, 200, ['Content-Type' => 'application/pdf'])]);
    $route = route('reports.pdf.request', [$this->team, $this->snapshot->id]);
    $this->actingAs($this->actor)->post($route)->assertRedirect();
    $this->post($route)->assertRedirect();
    expect(DB::table('jobs')->where('queue', 'pdf')->count())->toBe(1);
    expect($this->team->auditEvents()->where('event_type', 'report.pdf_requested')->count())->toBe(1);
    $this->get(route('reports.index', $this->team))->assertSee('PDF queued');
    $this->artisan('queue:work database --queue=pdf --once --tries=1 --timeout=60')->assertSuccessful();
    $pdf = $this->snapshot->pdf()->sole();
    expect($pdf->status)->toBe('verified')->and(base64_decode($pdf->content_base64))->toBe($bytes)
        ->and($pdf->requester->is($this->actor))->toBeTrue()->and($pdf->snapshot->is($this->snapshot))->toBeTrue();
    $this->get(route('reports.index', $this->team))->assertSee('Download verified PDF');
    $download = route('reports.pdf.download', [$this->team, $this->snapshot->id]);
    expect($this->get($download)->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent())->toBe($bytes);
    [$outsider, $foreign] = p2Business();
    $this->actingAs($outsider)->get($download)->assertForbidden();
    $this->get(route('reports.pdf.download', [$foreign, $this->snapshot->id]))->assertNotFound();
    $this->actingAs($this->actor);
    $this->post($route)->assertRedirect();
    expect(DB::table('jobs')->count())->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://render.example.test/forms/chromium/convert/html'
        && $request->hasFile('files', $this->snapshot->html, 'index.html')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('fixture:synthetic')));
});

it('allows two waiting reports globally in addition to the active conversion', function () {
    [$other, $foreign] = p2Business();
    $snapshots = app(ReportSnapshotService::class);
    $rows = [];
    foreach (['payables', 'receivables', 'financing-readiness'] as $kind) {
        $rows[] = $snapshots->create($other, $foreign, $kind);
    }
    Http::fake(function () use ($other, $foreign, $rows) {
        expect($this->snapshot->pdf()->sole()->status)->toBe('processing');
        $this->queue->request($other, $foreign, $rows[0]->id);
        $this->queue->request($other, $foreign, $rows[1]->id);
        $this->actingAs($other)->post(route('reports.pdf.request', [$foreign, $rows[2]->id]))->assertSessionHasErrors('pdf');
        expect($rows[2]->pdf()->exists())->toBeFalse();

        return Http::response('Unavailable', 503);
    });
    $pdf = $this->queue->request($this->actor, $this->team, $this->snapshot->id);
    (new GenerateReportPdf($this->team->id, $pdf->id))->handle(app(GotenbergClient::class));
    expect($pdf->fresh()->status)->toBe('failed')->and(ReportPdf::where('status', 'queued')->count())->toBe(2);
});

it('defers a competing worker and avoids replaying an interrupted submission', function () {
    Http::fake();
    $pdf = $this->queue->request($this->actor, $this->team, $this->snapshot->id);
    $job = (new GenerateReportPdf($this->team->id, $pdf->id))->withFakeQueueInteractions();
    $lock = Cache::store('database')->lock('agos.pdf.conversion', 90);
    $lock->get();
    try {
        $job->handle(app(GotenbergClient::class));
        $job->assertReleased(5);
        expect($pdf->fresh()->status)->toBe('queued');
    } finally {
        $lock->release();
    }
    $pdf->update(['status' => 'processing']);
    $job->handle(app(GotenbergClient::class));
    expect($pdf->fresh()->status)->toBe('failed');
    Http::assertNothingSent();
});

it('rolls back admission if inserting the database job fails', function () {
    config(['queue.connections.database.table' => 'unavailable_pdf_test_queue']);
    expect(fn () => $this->queue->request($this->actor, $this->team, $this->snapshot->id))->toThrow(QueryException::class);
    expect(ReportPdf::count())->toBe(0);
});

it('enforces the snapshot foreign key and one PDF record per report', function () {
    $this->queue->request($this->actor, $this->team, $this->snapshot->id);
    foreach ([$this->snapshot->id, $this->snapshot->id + 1000000] as $snapshotId) {
        expect(fn () => DB::transaction(fn () => DB::table('report_pdfs')->insert([
            'report_snapshot_id' => $snapshotId, 'requested_by' => $this->actor->id, 'status' => 'queued',
        ])))->toThrow(QueryException::class);
    }
    expect(ReportPdf::count())->toBe(1);
});

it('denies cross-Team requests and rechecks authority before sending HTML', function (string $restriction) {
    Http::fake();
    [$other, $foreign] = p2Business();
    $this->actingAs($other)->post(route('reports.pdf.request', [$foreign, $this->snapshot->id]))->assertNotFound();
    $this->post(route('reports.pdf.request', [$this->team, $this->snapshot->id]))->assertForbidden();
    $pdf = $this->queue->request($this->actor, $this->team, $this->snapshot->id);
    (new GenerateReportPdf($foreign->id, $pdf->id))->handle(app(GotenbergClient::class));
    expect($pdf->fresh()->status)->toBe('queued');
    match ($restriction) {
        'onboarding' => $this->team->update(['onboarding_completed_at' => null]),
        'administrator' => $this->actor->forceFill(['global_role' => GlobalRole::SystemAdministrator])->save(),
        'membership' => $this->team->members()->detach($this->actor),
    };
    $result = $this->actingAs($this->actor)->post(route('reports.pdf.request', [$this->team, $this->snapshot->id]));
    $restriction === 'onboarding' ? $result->assertRedirect(route('teams.onboarding', $this->team)) : $result->assertForbidden();
    (new GenerateReportPdf($this->team->id, $pdf->id))->handle(app(GotenbergClient::class));
    expect($pdf->fresh()->status)->toBe('failed')->and($this->snapshot->fresh()->html)->toBe($this->snapshot->html);
    Http::assertNothingSent();
})->with(['onboarding', 'administrator', 'membership']);
