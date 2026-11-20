<?php

namespace App\Services;

use App\Models\Obligation;
use App\Models\ReportSnapshot;
use App\Models\Team;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReportSnapshotService
{
    public const TITLES = [
        'cash-flow' => 'Cash-flow summary',
        'receivables' => 'Accounts receivable',
        'payables' => 'Accounts payable',
        'financing-readiness' => 'Financing readiness',
        'recommendations' => 'Recommendation evidence',
        'scenario' => 'Hypothetical scenario',
        'invoice' => 'Customer invoice',
    ];

    /** @param array<string, mixed> $inputs */
    public function create(User $actor, Team $team, string $kind, array $inputs = []): ReportSnapshot
    {
        abort_unless(isset(self::TITLES[$kind]), 404);

        return DB::transaction(function () use ($actor, $team, $kind, $inputs) {
            // Capture all source reads from one PostgreSQL snapshot.
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $team = $team->fresh() ?? abort(404);
            $actor = $actor->fresh() ?? abort(403);
            Gate::forUser($actor)->authorize('viewReports', $team);
            $created = now('UTC')->toImmutable();
            $report = match ($kind) {
                'cash-flow' => app(CashFlowService::class)->forTeam($actor, $team),
                'financing-readiness' => app(FinancingReadinessService::class)->forTeam($actor, $team),
                'recommendations' => app(RecommendationService::class)->forTeam($actor, $team),
                'scenario' => $this->scenario($actor, $team, $inputs),
                'invoice', 'receivables', 'payables' => $this->obligations($team, $kind, (int) ($inputs['invoice_id'] ?? 0)),
            };
            $data = ['business' => $team->name, 'title' => self::TITLES[$kind], 'kind' => $kind,
                'generated' => $created->setTimezone('Asia/Manila')->format('Y-m-d H:i:s'), 'report' => $report];
            $snapshot = $team->reportSnapshots()->create(['kind' => $kind, 'created_at' => $created, 'data' => $data,
                'html' => view('reports.document', $data)->render()]);
            app(AuditEventService::class)->record($actor, $team, 'report.created', $snapshot);

            return $snapshot;
        }, 3);
    }

    public function preview(User $actor, Team $team, int $id): ReportSnapshot
    {
        $team = $team->fresh() ?? abort(404);
        Gate::forUser($actor->fresh() ?? abort(403))->authorize('viewReports', $team);

        return $team->reportSnapshots()->findOrFail($id);
    }

    /** @param array<string,mixed> $inputs
     * @return array<string,mixed>
     */
    private function scenario(User $actor, Team $team, array $inputs): array
    {
        Gate::forUser($actor)->authorize('runScenarios', $team);
        $type = $inputs['type'] ?? '';
        $service = app(ScenarioService::class);
        if ($type === 'purchase_affordability') {
            $analysis = $service->purchaseAffordability($actor, $team, $inputs);
            $inputs = $analysis['assumptions'];
            $cases = $analysis['alternatives'];
            $limitations = $analysis['limitations'];
        } else {
            $comparison = $service->compare($actor, $team, $type, $inputs);
            $cases = [['label' => 'Selected scenario', 'comparison' => $comparison]];
            $inputs = $comparison['inputs'];
            $limitations = $comparison['limitations'];
        }
        $results = [];
        foreach ($cases as $case) {
            $comparison = $case['comparison'];
            $baseline = $comparison['baseline'];
            $scenario = $comparison['scenario']['cashFlow'];
            $results[] = ['label' => $case['label'], 'inputs' => $comparison['inputs'], 'affordability' => $case['affordability'] ?? null,
                'periodEnd' => $baseline['periodEnd'], 'baseline' => last($baseline['daily'])['projectedCash'],
                'scenario' => last($scenario['daily'])['projectedCash'], 'impact' => $comparison['impact'],
                'baselineGap' => $baseline['cashGap'], 'scenarioGap' => $scenario['cashGap']];
            $limitations = array_merge($limitations, $comparison['limitations']);
        }

        return compact('type', 'inputs', 'results') + ['limitations' => array_values(array_unique($limitations))];
    }

    /** @return array<string,mixed> */
    private function obligations(Team $team, string $kind, int $invoiceId): array
    {
        $query = Obligation::whereBelongsTo($team)->where('kind', $kind === 'payables' ? 'bill' : 'invoice')
            ->with(['payments.transaction', 'customer', 'supplier'])->orderBy('due_date')->orderBy('id');
        $documents = $kind === 'invoice' ? [$query->with('lines')->findOrFail($invoiceId)] : $query->get();
        $outstanding = BigDecimal::of('0.00');
        $rows = [];
        foreach ($documents as $document) {
            $balance = $document->balance();
            $outstanding = $outstanding->plus($balance);
            $rows[] = ['id' => $document->id, 'number' => $document->number ?? $document->description ?? '#'.$document->id,
                'contact' => $document->kind === 'invoice' ? $document->customer->name : $document->supplier->name, 'issued' => $document->issue_date,
                'due' => $document->due_date, 'total' => $document->total, 'paid' => (string) BigDecimal::of($document->total)->minus($balance),
                'balance' => (string) $balance, 'lines' => $kind === 'invoice'
                    ? $document->lines->map->only(['description', 'quantity', 'unit_price', 'line_total'])->values()->all() : []];
        }

        return ['documents' => $rows, 'outstanding' => (string) $outstanding];
    }
}
