@props(['contact'])
@php
    $colors = ['bg-blue-100 text-blue-900', 'bg-teal-100 text-teal-900', 'bg-violet-100 text-violet-900', 'bg-amber-100 text-amber-900'];
@endphp
<span aria-hidden="true" class="inline-grid size-10 shrink-0 place-items-center rounded-xl text-sm font-bold {{ $colors[$contact->id % count($colors)] }}">
    {{ mb_strtoupper(mb_substr(trim($contact->name), 0, 2)) }}
</span>
