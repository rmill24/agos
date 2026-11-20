<h2>Confirmed cash as of {{ $report['asOf'] }}</h2>
<dl>@foreach (['Opening balance' => $report['openingBalance'], 'Confirmed inflow' => $report['confirmed']['inflow'], 'Confirmed outflow' => $report['confirmed']['outflow'], 'Net movement' => $report['confirmed']['netMovement'], 'Current cash' => $report['currentCash']] as $label => $amount)
    <dt>{{ $label }}</dt><dd>{{ $amount === null ? 'Unknown' : 'PHP '.$amount }}</dd>
@endforeach</dl>
<h2>Forecast through {{ $report['periodEnd'] }}</h2>
<p>Earliest threshold shortfall: {{ $report['earliestShortfallDate'] ?? 'None established' }}</p>
<div class="table-wrap" tabindex="0" role="region" aria-label="Daily forecast"><table>
    <thead><tr><th>Date</th><th>Net movement</th><th>Projected cash</th></tr></thead>
    <tbody>@foreach ($report['daily'] as $day)
        <tr><th scope="row">{{ $day['date'] }}</th><td>{{ $day['netMovement'] }}</td><td>{{ $day['projectedCash'] ?? 'Unknown' }}</td></tr>
    @endforeach</tbody>
</table></div>
