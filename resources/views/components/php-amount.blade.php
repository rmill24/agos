@props(['amount' => null])
@php
    // Group the verified decimal string for display without converting to float.
    $display = 'Unavailable';
    if ($amount !== null) {
        [$whole, $fraction] = explode('.', $amount);
        $display = '₱'.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction;
    }
@endphp
<span {{ $attributes->class('wrap-anywhere tabular-nums') }}>{{ $display }}</span>
