<!doctype html>
<html lang="en"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ $business }}</title>
    <style>
        * { box-sizing:border-box } body { margin:0; background:#f8fafc; color:#172338; font:1rem/1.5 system-ui,sans-serif }
        main { max-width:64rem; margin:auto; padding:clamp(1rem,4vw,3rem); background:white; overflow-wrap:anywhere }
        a { color:#174da1 } :focus-visible { outline:3px solid #2563eb; outline-offset:2px }
        header { border-bottom:2px solid #263a54; margin:1.5rem 0; padding-bottom:1rem }
        h1,h2,h3,p { margin:.5rem 0 } h1 { font-size:clamp(1.65rem,4vw,2.4rem) } h2 { margin-top:1.5rem; font-size:1.25rem }
        .meta { color:#475569 } .table-wrap { overflow:auto } table { width:100%; border-collapse:collapse; font-variant-numeric:tabular-nums }
        th,td { padding:.55rem; border-bottom:1px solid #cbd5e1; text-align:left; vertical-align:top; overflow-wrap:normal }
        thead { background:#edf2f7 } @media screen and (max-width:42rem) { table { min-width:32rem } }
        dt { font-weight:600 } dd { margin:0 0 .6rem } @media print { nav { display:none } body,main { background:white; padding:0 } tr { break-inside:avoid } .table-wrap { overflow:visible } }
    </style>
</head><body><main>
    <nav aria-label="Report navigation"><a href="../reports">Back to saved reports</a></nav>
    <header><p>{{ $business }}</p><h1>{{ $title }}</h1><p class="meta">Saved {{ $generated }} PHT · Currency: PHP</p></header>
    @include('reports.'.(in_array($kind, ['invoice','receivables','payables'], true) ? 'obligations' : $kind))
    @if (! empty($report['limitations']))
        <h2>Limitations</h2><ul>@foreach ($report['limitations'] as $limitation)<li>{{ $limitation }}</li>@endforeach</ul>
    @endif
</main></body></html>
