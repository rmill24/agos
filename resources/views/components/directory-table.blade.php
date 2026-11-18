@props(['records', 'caption'])
<div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
    <table class="w-full table-fixed text-left text-sm">
        <caption class="sr-only">{{ $caption }}</caption>
        <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
            <tr>{{ $head }}</tr>
        </thead>
        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">{{ $slot }}</tbody>
    </table>
    <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-3 text-sm text-slate-600">
        <span>{{ trans_choice('{0} 0 records|{1} 1 record|[2,*] :first–:last of :count records', $records->total(), ['first' => $records->firstItem(), 'last' => $records->lastItem()]) }}</span>
        <nav aria-label="{{ __('Directory pagination') }}" class="flex flex-wrap items-center gap-3">
            <flux:button size="sm" wire:click="previousPage" :disabled="$records->onFirstPage()">{{ __('Previous') }}</flux:button>
            <span>{{ __('Page :page of :last', ['page' => $records->currentPage(), 'last' => $records->lastPage()]) }}</span>
            <flux:button size="sm" wire:click="nextPage" :disabled="! $records->hasMorePages()">{{ __('Next') }}</flux:button>
        </nav>
    </footer>
</div>
