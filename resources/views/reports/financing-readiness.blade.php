<h2>Record checklist</h2>
<p>{{ $report['recent'] }} of {{ count($report['items']) }} record groups have recent evidence for {{ $report['since'] }} through {{ $report['asOf'] }}. Loan eligibility is outside this assessment.</p>
@foreach ($report['items'] as $item)
    <h3>{{ $item['label'] }} · {{ $item['status'] }}</h3>
    <p>{{ $item['detail'] }} @if ($item['date']) Latest evidence: {{ $item['date'] }}. @endif</p>
@endforeach
