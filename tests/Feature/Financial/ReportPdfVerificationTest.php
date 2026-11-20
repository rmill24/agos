<?php

use App\Jobs\GenerateReportPdf;
use App\Services\ReportPdfService;
use App\Services\ReportPdfVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->actor, $this->team] = p2Business();
    $data = require base_path('tests/Fixtures/report-pdf.php');
    $this->snapshot = $this->team->reportSnapshots()->create(['kind' => $data['kind'], 'data' => $data,
        'created_at' => now(), 'html' => view('reports.document', $data)->render()]);
    $this->bytes = file_get_contents(base_path('tests/Fixtures/report-receivables.pdf'));
    config(['services.gotenberg.base_url' => 'https://pdf.example.test', 'services.gotenberg.username' => 'fixture', 'services.gotenberg.password' => 'fixture']);
    Http::preventStrayRequests();
});

it('verifies the printed content including every row and decimal total', function (string $original, string $replacement) {
    $changed = str_replace($original, $replacement, $this->snapshot->html);
    expect($changed)->not->toBe($this->snapshot->html);
    expect(fn () => app(ReportPdfVerifier::class)->verify($this->bytes, $changed, 10))->toThrow(RuntimeException::class);
})->with([['562.80', '562.81'], ['INV-28', 'INV-82'], ['10.15', '10.05'], ['Sample Business', 'Other Business']]);

it('defers a transient response once, honors Retry-After and uses the same snapshot', function (int $status, int $seconds) {
    Http::fakeSequence()->push('', $status, ['Retry-After' => (string) $seconds])->push($this->bytes, 200, ['Content-Type' => 'application/pdf']);
    $pdf = app(ReportPdfService::class)->request($this->actor, $this->team, $this->snapshot->id);
    $start = time();
    $this->artisan('queue:work database --queue=pdf --once')->assertSuccessful();
    expect($pdf->fresh()->status)->toBe('retry_queued');
    $job = DB::table('jobs')->where('queue', 'pdf')->sole();
    expect($job->available_at)->toBeGreaterThanOrEqual($start + $seconds)
        ->toBeLessThanOrEqual(time() + $seconds);
    DB::table('jobs')->where('id', $job->id)->update(['available_at' => time()]);
    $this->artisan('queue:work database --queue=pdf --once')->assertSuccessful();
    expect($pdf->fresh()->status)->toBe('verified')->and(DB::table('jobs')->count())->toBe(0);
    Http::assertSentCount(2);
})->with([[429, 45], [503, 0]]);

it('stops after two transient responses', function () {
    Http::fakeSequence()->push('', 503, ['Retry-After' => '0'])->push('', 429);
    $pdf = app(ReportPdfService::class)->request($this->actor, $this->team, $this->snapshot->id);
    foreach ([1, 2] as $attempt) {
        $this->artisan('queue:work database --queue=pdf --once')->assertSuccessful();
    }
    expect($pdf->fresh()->status)->toBe('failed')->and(DB::table('jobs')->count())->toBe(0);
    Http::assertSentCount(2);
});

it('leaves a manual retry available when the provider requests a delay beyond the deadline', function () {
    Http::fakeSequence()->push('', 429, ['Retry-After' => '120']);
    $pdf = app(ReportPdfService::class)->request($this->actor, $this->team, $this->snapshot->id);
    $this->artisan('queue:work database --queue=pdf --once')->assertSuccessful();
    expect($pdf->fresh()->status)->toBe('failed')->and(DB::table('jobs')->count())->toBe(0);
    Http::assertSentCount(1);
});

it('fails closed on permanent provider errors, invalid files or unexpected output', function (int $status, string $mime, string $body) {
    Http::fake(['*' => Http::response($body, $status, ['Content-Type' => $mime])]);
    $pdf = app(ReportPdfService::class)->request($this->actor, $this->team, $this->snapshot->id);
    $this->artisan('queue:work database --queue=pdf --once')->assertSuccessful();
    expect($pdf->fresh()->status)->toBe('failed')->and($pdf->fresh()->content_base64)->toBeNull()->and(DB::table('jobs')->count())->toBe(0);
    $this->actingAs($this->actor)->get(route('reports.pdf.download', [$this->team, $this->snapshot->id]))->assertNotFound();
    Http::assertSentCount(1);
})->with([[401, 'text/plain', 'denied'], [200, 'text/html', 'wrong mime'], [200, 'application/pdf', '%PDF-fake %%EOF']]);

it('verifies existing converted bytes without contacting the provider', function () {
    Http::fake();
    $pdf = $this->snapshot->pdf()->create(['requested_by' => $this->actor->id, 'status' => 'converted', 'content_base64' => base64_encode($this->bytes)]);
    $this->actingAs($this->actor)->get(route('reports.pdf.download', [$this->team, $this->snapshot->id]))->assertNotFound();
    app(ReportPdfService::class)->request($this->actor, $this->team, $this->snapshot->id);
    $this->artisan('queue:work database --queue=pdf --once')->assertSuccessful();
    expect($pdf->fresh()->status)->toBe('verified');
    Http::assertNothingSent();
});

it('refuses a retry beyond its original job deadline', function () {
    Http::fake();
    $pdf = $this->snapshot->pdf()->create(['requested_by' => $this->actor->id, 'status' => 'retry_queued']);
    app()->call([new GenerateReportPdf($this->team->id, $pdf->id, time() - 1), 'handle']);
    expect($pdf->fresh()->status)->toBe('failed');
    Http::assertNothingSent();
});

it('recovers from an outage with one authorized manual retry of the original snapshot', function () {
    Http::fakeSequence()->pushFailedConnection()->push($this->bytes, 200, ['Content-Type' => 'application/pdf']);
    $request = route('reports.pdf.request', [$this->team, $this->snapshot->id]);
    $snapshot = $this->snapshot->fresh()->getRawOriginal();
    $ledger = $this->team->transactions()->get()->toArray();
    $this->actingAs($this->actor)->post($request)->assertRedirect();
    $this->artisan('queue:work database --queue=pdf --once')->assertSuccessful();
    $pdf = $this->snapshot->pdf()->sole();
    expect($pdf->status)->toBe('failed')->and(DB::table('jobs')->count())->toBe(0);
    $this->get(route('reports.index', $this->team))->assertSee('Retry PDF');
    expect($this->get(route('reports.show', [$this->team, $this->snapshot->id]))->getContent())->toBe($this->snapshot->html);
    [$outsider] = p2Business();
    $this->actingAs($outsider)->post($request, ['retry' => 1])->assertForbidden();
    $this->actingAs($this->actor)->post($request, ['retry' => 1])->assertRedirect();
    $this->post($request, ['retry' => 1])->assertRedirect();
    expect(DB::table('jobs')->count())->toBe(1);
    $oldDelivery = new GenerateReportPdf($this->team->id, $pdf->id);
    $oldDelivery->failed(null);
    app()->call([$oldDelivery, 'handle']);
    expect($pdf->fresh()->status)->toBe('queued')->and((int) $pdf->fresh()->generation)->toBe(2);
    $this->artisan('queue:work database --queue=pdf --once')->assertSuccessful();
    expect($pdf->fresh()->status)->toBe('verified')->and($this->snapshot->fresh()->getRawOriginal())->toBe($snapshot)
        ->and($this->team->transactions()->get()->toArray())->toBe($ledger);
    $events = $this->team->auditEvents()->orderBy('id')->get();
    expect($events->pluck('event_type')->all())->toBe(['report.pdf_requested', 'report.pdf_failed', 'report.pdf_retried', 'report.pdf_verified'])
        ->and($events->toJson())->not->toContain('Authorization', 'fixture', '562.80', '%PDF', '<html');
    Http::assertSentCount(2);
});
