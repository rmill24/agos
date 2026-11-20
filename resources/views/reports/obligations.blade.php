@if ($kind === 'invoice')
    @php($invoice = $report['documents'][0])
    <h2>Invoice {{ $invoice['number'] }}</h2>
    <p>Bill to {{ $invoice['contact'] }} · Issued {{ $invoice['issued'] }} · Due {{ $invoice['due'] }}</p>
    <div class="table-wrap" role="region" tabindex="0" aria-label="Invoice lines"><table>
        <thead><tr><th>Description</th><th>Quantity</th><th>Unit price</th><th>Line total</th></tr></thead>
        <tbody>@forelse ($invoice['lines'] as $line)
            <tr><td>{{ $line['description'] }}</td><td>{{ $line['quantity'] }}</td><td>{{ $line['unit_price'] }}</td><td>{{ $line['line_total'] }}</td></tr>
        @empty <tr><td colspan="4">No line items recorded.</td></tr> @endforelse</tbody>
    </table></div>
    <p>Invoice total: PHP {{ $invoice['total'] }} · Paid: PHP {{ $invoice['paid'] }}</p>
@else
    <h2>{{ $kind === 'receivables' ? 'Customer balances' : 'Supplier balances' }}</h2>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Document balances"><table>
        <thead><tr><th>Document</th><th>Contact</th><th>Due</th><th>Total</th><th>Paid</th><th>Outstanding</th></tr></thead>
        <tbody>@forelse ($report['documents'] as $doc)
            <tr><th scope="row">{{ $doc['number'] }}</th><td>{{ $doc['contact'] }}</td><td>{{ $doc['due'] }}</td><td>{{ $doc['total'] }}</td><td>{{ $doc['paid'] }}</td><td>{{ $doc['balance'] }}</td></tr>
        @empty <tr><td colspan="6">No documents recorded.</td></tr> @endforelse</tbody>
    </table></div>
@endif
<p><strong>Outstanding: PHP {{ $report['outstanding'] }}</strong></p>
