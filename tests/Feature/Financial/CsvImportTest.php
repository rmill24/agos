<?php

use App\Enums\GlobalRole;
use App\Enums\TeamRole;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CsvImportService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('commits reviewed selection once and reports invalid rows at their original lines', function () {
    [$user,$team] = p2Business();
    $this->actingAs($user);
    $csv = "date,type,amount,currency,description,payment_status,external_id\n"
        ."2026-11-01,income,0.01,PHP,\"multi\nline\",paid,A\n"
        ."01/02/2026,expense,5,PHP,Ambiguous,paid,B\n"
        ."2026-11-01,expense,-3,PHP,Negative,paid,C\n"
        ."2026-11-01,expense,4,USD,Dollars,paid,D\n"
        ."2026-11-01,expense,5,PHP,Partial,partially paid,E\n"
        ."2026-11-01,expense,3,PHP,Repeat,paid,F\n"
        ."2026-11-01,expense,3,PHP,Repeat,paid,F\n";
    $component = Livewire::test('pages::transactions.csv-import', ['current_team' => $team])
        ->assertDontSee('File uploaded')
        ->set('file', UploadedFile::fake()->createWithContent('ledger.csv', $csv))
        ->assertSee('File uploaded')->assertSee('ledger.csv is ready for column mapping.')->call('stageFile')
        ->call('review')->assertHasNoErrors()->assertSee('Duplicate external ID')->assertSee('Settlement unconfirmed')
        ->assertSet('selected', ['2'])->call('commitReviewed')->assertHasNoErrors()->assertSee('1 row imported')->assertSee('Incomplete history')->assertDispatched('transactions-imported');
    $batch = app(CsvImportService::class)->batch($user, $team, $component->get('batchId'));
    expect($batch->rows()->where('status', 'held')->pluck('row_number')->all())->toBe([4, 5, 6, 7, 8, 9]);
    $component->call('commitReviewed')->assertHasNoErrors()->call('download')->assertFileDownloaded('import-'.$batch->id.'-review.csv');
    expect(Transaction::sole()->amount)->toBe('0.01');
    $this->get(route('transactions.index', $team))->assertOk()->assertSee('Incomplete history');
});

it('requires renewed review for new duplicate candidates and preserves exact external ids', function () {
    [$user,$team] = p2Business();
    $s = app(CsvImportService::class);
    $csv = "date,type,amount,currency,source_reference,external_id\n01-11-2026,income,10.00,PHP,Receipt A,ID1";
    $b = $s->upload($user, $team, $csv);
    $map = array_flip($b->headers);
    $s->review($user, $team, $b->id, $map, 'd-m-Y', false);
    expect($b->rows()->sole()->reason)->toContain('Confirm');
    $review = $s->review($user, $team, $b->id, $map, 'd-m-Y', true);
    app(TransactionService::class)->create($user, $team, ['direction' => 'income', 'transaction_date' => '2026-11-01', 'amount' => '10', 'description' => 'receipt   a']);
    $stale = $s->commit($user, $team, $b->id, $review->revision, [2]);
    expect($stale->committed_at)->toBeNull()->and($b->rows()->sole()->status)->toBe('probable');
    expect(fn () => $s->commit($user, $team, $b->id, $review->revision, [2]))->toThrow(ValidationException::class);
    $s->commit($user, $team, $b->id, $stale->revision, [2]);
    $copy = $s->upload($user, $team, $csv);
    $s->review($user, $team, $copy->id, $map, 'd-m-Y', true);
    expect($copy->rows()->sole()->reason)->toContain('Duplicate external ID');
});

it('checks scope and role again for import actions and report download', function () {
    [$user,$team] = p2Business(TeamRole::Bookkeeper);
    [, $other] = p2Business();
    $this->actingAs($user);
    $component = Livewire::test('pages::transactions.csv-import', ['current_team' => $team]);
    $team->members()->detach($user);
    $component->call('download')->assertForbidden();
    $this->get(route('imports.index', $other))->assertForbidden();
    $admin = User::factory()->create(['global_role' => GlobalRole::SystemAdministrator]);
    $team->members()->attach($admin, ['role' => TeamRole::Manager]);
    $this->actingAs($admin)->get(route('imports.index', $team))->assertForbidden();
    Livewire::test('pages::obligations.index', ['current_team' => $team])->assertForbidden();
});

it('validates UTF-8, headers, contact mappings and duplicate rows within a file', function () {
    [$user,$team] = p2Business();
    $s = app(CsvImportService::class);
    foreach (["a,b\n\xff,2", "a,,b\n1,2,3", "a,a\n1,2"] as $bad) {
        expect(fn () => $s->upload($user, $team, $bad))->toThrow(ValidationException::class);
    }
    $csv = "date,type,amount,currency,description,category\n2026-11-01,income,2,PHP,Cash,Unknown\n2026-11-01,income,2,PHP,Cash,\n2026-11-01,income,2,PHP,cash,";
    $b = $s->upload($user, $team, $csv);
    $s->review($user, $team, $b->id, array_flip($b->headers), 'Y-m-d', true);
    expect($b->rows()->pluck('status')->all())->toBe(['held', 'ready', 'probable']);
});

it('exports only matching active transactions and keeps CSV actions on the ledger', function () {
    [$user, $team] = p2Business(TeamRole::Bookkeeper);
    [, $other] = p2Business();
    $other->members()->attach($user, ['role' => TeamRole::Bookkeeper]);
    Transaction::factory()->for($team)->create([
        'transaction_date' => '2026-11-01', 'amount' => '2.01',
        'description' => 'Market, sale', 'source_reference' => '=SUM(A1)',
    ]);
    Transaction::factory()->for($team)->expense()->create();
    Transaction::factory()->for($team)->create()->delete();
    Transaction::factory()->for($other)->create();

    $this->actingAs($user)->get(route('imports.index', $team))->assertRedirect(route('transactions.index', $team));
    $this->get(route('transactions.index', $team))->assertOk()
        ->assertSeeLivewire('pages::transactions.csv-import')->assertSee('Import CSV')->assertSee('Export CSV')
        ->assertDontSee('href="'.route('imports.index', $team).'"', false);

    $ledger = Livewire::test('pages::transactions.index', ['current_team' => $team])->set('directionFilter', 'income');
    $ledger->call('exportCsv')->assertFileDownloaded('transactions.csv',
        "date,type,amount,currency,description,source_reference,category,payment_status,external_id\n"
        ."2026-11-01,income,2.01,PHP,\"Market, sale\",'=SUM(A1),,paid,\n"
    );

    $team->members()->detach($user);
    $ledger->call('exportCsv')->assertForbidden();
});

it('rejects a non-CSV extension before creating an import draft', function () {
    [$user, $team] = p2Business();
    $this->actingAs($user);

    Livewire::test('pages::transactions.csv-import', ['current_team' => $team])
        ->call('startUpload')->assertSee('Browse files')
        ->set('file', UploadedFile::fake()->createWithContent('ledger.txt', "date,type\n2026-11-01,income"))
        ->assertDontSee('File uploaded')->call('stageFile')->assertHasErrors(['file' => 'extensions']);

    $this->assertDatabaseCount('import_batches', 0);
});
