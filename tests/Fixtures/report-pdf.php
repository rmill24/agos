<?php

// Data used to print report-receivables.pdf with Chromium and the AGOS report template.
$documents = [];
foreach (range(1, 56) as $index) {
    $documents[] = ['number' => 'INV-'.$index, 'contact' => 'Example customer', 'due' => '2026-11-30',
        'total' => '10.15', 'paid' => '0.10', 'balance' => '10.05'];
}

return ['business' => 'Sample Business', 'title' => 'Accounts receivable', 'kind' => 'receivables',
    'generated' => '2026-11-20 12:00:00', 'report' => ['documents' => $documents, 'outstanding' => '562.80']];
