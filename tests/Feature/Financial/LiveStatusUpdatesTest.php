<?php

use App\Enums\GlobalRole;
use App\Services\ReportSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('refreshes a queued report PDF status in place until its verified download is ready', function () {
    [$owner, $team] = p2Business();
    $snapshot = app(ReportSnapshotService::class)->create($owner, $team, 'cash-flow');
    $pdf = $snapshot->pdf()->create(['requested_by' => $owner->id, 'status' => 'queued']);

    $component = Livewire::actingAs($owner)
        ->test('reports.pdf-status-list', ['team' => $team])
        ->assertSee('PDF queued.')
        ->assertSeeHtml('wire:poll.2s');

    $pdf->update(['status' => 'verified']);

    $component->call('$refresh')
        ->assertSee('Download verified PDF')
        ->assertDontSeeHtml('wire:poll.2s');
});

it('queues PDF work from the live status list and stops polling after a failure', function () {
    Queue::fake();
    [$owner, $team] = p2Business();
    $snapshot = app(ReportSnapshotService::class)->create($owner, $team, 'cash-flow');

    $component = Livewire::actingAs($owner)
        ->test('reports.pdf-status-list', ['team' => $team])
        ->call('queuePdf', $snapshot->id)
        ->assertHasNoErrors()
        ->assertSee('PDF queued.')
        ->assertSeeHtml('wire:poll.2s');

    $snapshot->pdf()->update(['status' => 'failed']);

    $component->call('$refresh')
        ->assertSee('PDF generation failed. The saved preview is available.')
        ->assertDontSeeHtml('wire:poll.2s');
});

it('rechecks report access for live status refreshes and queue actions', function () {
    [$owner, $team] = p2Business();
    $snapshot = app(ReportSnapshotService::class)->create($owner, $team, 'cash-flow');
    $component = Livewire::actingAs($owner)->test('reports.pdf-status-list', ['team' => $team]);

    $owner->forceFill(['global_role' => GlobalRole::SystemAdministrator])->save();
    $component->call('queuePdf', $snapshot->id)->assertForbidden();

    [$other, $otherTeam] = p2Business();
    Livewire::actingAs($other)
        ->test('reports.pdf-status-list', ['team' => $otherTeam])
        ->call('queuePdf', $snapshot->id)
        ->assertNotFound();
});

it('keeps Loyverse polling active for delayed queued work and stops after completion', function () {
    [$owner, $team] = p2Business();
    config(["loyverse.connections.{$team->id}" => ['token' => 'fixture', 'merchant' => 'm1', 'store' => 's1', 'collected_methods' => ['cash']]]);
    $team->loyverseSyncs()->create([
        'user_id' => $owner->id,
        'status' => 'queued',
        'source_mapping' => 'fixture',
        'window_start' => now()->subHour(),
        'window_end' => now(),
        'attempt' => 1,
        'pages' => 0,
        'updated_at' => now()->subMinutes(4),
    ]);

    $component = Livewire::actingAs($owner)
        ->test('pages::loyverse.index', ['current_team' => $team])
        ->assertSee('Sync interrupted')
        ->assertSeeHtml('wire:poll.2s');

    $team->loyverseSyncs()->latest('id')->firstOrFail()->update(['status' => 'complete', 'completed_at' => now()]);

    $component->call('$refresh')
        ->assertSee('Sync complete')
        ->assertDontSeeHtml('wire:poll.2s');
});
