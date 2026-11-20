<h2>Advisory actions</h2>
<p>{{ $report['as_of'] }} through {{ $report['period_end'] }}. {{ $report['message'] }}</p>
@foreach ($report['recommendations'] as $action)
    <h3>{{ $action['action'] }} · {{ $action['priority'] }} priority</h3>
    <p>{{ $action['reason'] }}</p>
    <p>{{ $action['document'] ?? 'Cash-flow projection' }} · {{ $action['due_date'] }} · PHP {{ $action['amount'] }}</p>
    <p class="meta">Source: {{ implode(', ', $action['evidence_references']) }}</p>
    <ul>@foreach ($action['amounts'] as $measure => $value)
        <li>{{ str($measure)->headline() }}: {{ $value === null ? 'Unknown' : 'PHP '.$value }}</li>
    @endforeach</ul>
@endforeach
