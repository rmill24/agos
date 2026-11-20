<h2>{{ str($report['type'])->replace('_', ' ')->headline() }}</h2>
<p>Hypothetical comparison based on the inputs below.</p>
<dl>@foreach ($report['inputs'] as $label => $value)
    <dt>{{ str($label)->headline() }}</dt><dd>{{ $value }}</dd>
@endforeach</dl>
@foreach ($report['results'] as $result)
    <h3>{{ $result['label'] }} · {{ $result['periodEnd'] }}</h3>
    @if ($result['affordability'])
        <p>{{ $result['affordability']['reason'] }}</p>
        <p>Purchase: PHP {{ $result['inputs']['amount'] }} on {{ $result['inputs']['hypotheticalDate'] }}.</p>
    @endif
    <div class="table-wrap" role="region" tabindex="0" aria-label="{{ $result['label'] }} comparison"><table>
        <thead><tr><th>Measure</th><th>Baseline</th><th>Hypothetical</th></tr></thead>
        <tbody><tr><th scope="row">Ending cash</th><td>{{ $result['baseline'] ?? 'Unknown' }}</td><td>{{ $result['scenario'] ?? 'Unknown' }}</td></tr>
        <tr><th scope="row">Cash gap</th><td>{{ $result['impact']['status'] === 'calculated' && $result['impact']['threshold'] !== null ? $result['baselineGap'] : 'Unknown' }}</td><td>{{ $result['impact']['status'] === 'calculated' && $result['impact']['threshold'] !== null ? $result['scenarioGap'] : 'Unknown' }}</td></tr>
        <tr><th scope="row">First shortfall</th><td>{{ $result['impact']['earliestShortfall']['baseline'] ?? 'None established' }}</td><td>{{ $result['impact']['earliestShortfall']['scenario'] ?? 'None established' }}</td></tr></tbody>
    </table></div>
@endforeach
