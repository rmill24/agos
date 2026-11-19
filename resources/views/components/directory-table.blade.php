@props(['records', 'caption'])
<div class="agos-table-panel">
    <table class="w-full table-fixed text-left text-sm">
        <caption class="sr-only">{{ $caption }}</caption>
        <thead>
            <tr>{{ $head }}</tr>
        </thead>
        <tbody>{{ $slot }}</tbody>
    </table>
    <x-table-pagination :records="$records" />
</div>
