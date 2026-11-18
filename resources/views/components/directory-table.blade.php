@props(['records', 'caption'])
<div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
    <table class="w-full table-fixed text-left text-sm">
        <caption class="sr-only">{{ $caption }}</caption>
        <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
            <tr>{{ $head }}</tr>
        </thead>
        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">{{ $slot }}</tbody>
    </table>
    <x-table-pagination :records="$records" />
</div>
