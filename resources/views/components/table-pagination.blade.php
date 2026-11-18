@props(['records'])
@if ($records->total() > 0)
    <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-3 text-sm text-slate-600">
        <span>{{ trans_choice('{0} 0 records|{1} 1 record|[2,*] :first–:last of :count records', $records->total(), ['first' => $records->firstItem(), 'last' => $records->lastItem()]) }}</span>
        <nav aria-label="{{ __('Directory pagination') }}" class="flex flex-wrap items-center gap-3">
            <flux:button size="sm" wire:click="previousPage('{{ $records->getPageName() }}')" :disabled="$records->onFirstPage()">{{ __('Previous') }}</flux:button>
            <span>{{ __('Page :page of :last', ['page' => $records->currentPage(), 'last' => $records->lastPage()]) }}</span>
            <flux:button size="sm" wire:click="nextPage('{{ $records->getPageName() }}')" :disabled="! $records->hasMorePages()">{{ __('Next') }}</flux:button>
        </nav>
    </footer>
@endif
