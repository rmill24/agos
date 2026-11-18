<?php

use App\Models\Team;
use App\Services\BusinessContactService;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public Team $team;

    #[Locked]
    public bool $suppliers = false;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $profileId = null;

    public string $search = '';

    public string $status = 'active';

    public string $sort = 'name_asc';

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function openProfile(int $id, BusinessContactService $contacts): void
    {
        $this->profileId = $contacts->records(auth()->user(), $this->team, $this->suppliers)
            ->withTrashed()->findOrFail($id)->id;
        Flux::modal('contact-profile')->show();
    }

    public string $name = '';

    public ?string $email = null;

    public ?string $phone = null;

    #[Locked]
    public string $archiveNotice = '';

    public function mount(Team $current_team): void
    {
        Gate::authorize('manageContacts', $current_team);
        $this->team = $current_team;
        $this->suppliers = request()->routeIs('suppliers.index');
    }

    public function startCreate(): void
    {
        $this->cancelEdit();
        Flux::modal('contact-form')->show();
    }

    public function edit(int $id, BusinessContactService $contacts): void
    {
        $contact = $contacts->records(auth()->user(), $this->team, $this->suppliers)->findOrFail($id);
        $this->resetValidation();
        $this->editingId = $contact->id;
        $this->name = $contact->name;
        $this->email = $contact->email;
        $this->phone = $contact->phone;
        Flux::modal('contact-form')->show();
    }

    public function save(BusinessContactService $contacts): void
    {
        $contacts->save(auth()->user(), $this->team, $this->suppliers, $this->editingId, [
            'name' => trim($this->name),
            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
        ]);
        $this->cancelEdit();
        Flux::modal('contact-form')->close();
        $this->archiveNotice = '';
        $this->resetPage();
        Flux::toast(variant: 'success', text: __('Contact saved.'));
    }

    public function archive(int $id, BusinessContactService $contacts): void
    {
        $name = $contacts->archive(auth()->user(), $this->team, $this->suppliers, $id);

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }

        $this->resetPage();
        Flux::modal('archive-contact-'.$id)->close();
        Flux::modal('contact-profile')->close();
        $this->profileId = null;
        $this->archiveNotice = __(':name was archived.', ['name' => $name]);
    }

    public function cancelEdit(): void
    {
        Gate::authorize('manageContacts', $this->team);
        $this->reset('editingId', 'name', 'email', 'phone');
        $this->resetValidation();
    }

    public function render()
    {
        $title = $this->suppliers ? __('Suppliers') : __('Customers');
        $records = app(BusinessContactService::class)->records(auth()->user(), $this->team, $this->suppliers);
        $profile = $this->profileId ? (clone $records)->withTrashed()->find($this->profileId) : null;
        if ($this->status === 'archived') {
            $records->onlyTrashed();
        }
        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $records->where(fn ($query) => $query->whereLike('name', $term)->orWhereLike('email', $term)->orWhereLike('phone', $term));
        }
        [$column, $direction] = match ($this->sort) {
            'name_desc' => ['name', 'desc'],
            'newest' => ['created_at', 'desc'],
            default => ['name', 'asc'],
        };
        $contacts = $records->orderBy($column, $direction)->orderBy('id', $direction)->paginate(10);

        return $this->view(compact('title', 'contacts', 'profile'))->title($title);
    }
}; ?>

<section class="agos-page">
    <header class="agos-page-header">
        <div>
            <flux:heading level="1" size="xl">{{ $title }}</flux:heading>
            <flux:subheading>{{ $suppliers ? __('Manage the suppliers your business buys from.') : __('Manage the customers who buy from your business.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" wire:click="startCreate">{{ $suppliers ? __('New supplier') : __('New customer') }}</flux:button>
    </header>

    <flux:modal name="contact-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-4">
            <flux:heading level="2">
                @if ($editingId)
                    {{ $suppliers ? __('Edit supplier') : __('Edit customer') }}
                @else
                    {{ $suppliers ? __('Add supplier') : __('Add customer') }}
                @endif
            </flux:heading>
            <flux:input wire:model="name" :label="__('Name')" required maxlength="255" />
            <flux:input wire:model="email" :label="__('Email (optional)')" type="email" maxlength="255" />
            <flux:input wire:model="phone" :label="__('Phone (optional)')" type="tel" maxlength="50" />
            <div class="flex flex-wrap gap-2">
                <flux:modal.close><flux:button type="button">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ $editingId ? __('Save changes') : __('Add contact') }}</flux:button>
            </div>
            <p wire:loading wire:target="save" role="status" class="text-sm">{{ __('Saving contact…') }}</p>
        </form>
    </flux:modal>

    <div role="status" aria-live="polite">
        @if ($archiveNotice)
            <p class="agos-card mb-4 p-4">{{ $archiveNotice }}</p>
        @endif
    </div>
    <div class="mb-5 grid items-end gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 md:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <flux:input wire:model.live.debounce.300ms="search" type="search" icon="magnifying-glass"
                :label="__('Search')" :placeholder="__('Name, email or phone')" />
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:select wire:model.live="sort" :label="__('Sort by')">
                    <option value="name_asc">{{ __('Name (A–Z)') }}</option>
                    <option value="name_desc">{{ __('Name (Z–A)') }}</option>
                    <option value="newest">{{ __('Newest first') }}</option>
                </flux:select>
                <flux:select wire:model.live="status" :label="__('Status')">
                    <option value="active">{{ __('Active') }}</option>
                    <option value="archived">{{ __('Archived') }}</option>
                </flux:select>
            </div>
    </div>
    <x-directory-table :records="$contacts" :caption="$title">
        <x-slot:head>
            <th scope="col" class="px-5 py-3">{{ __('Name') }}</th>
            <th scope="col" class="hidden px-3 py-3 md:table-cell">{{ __('Email') }}</th>
            <th scope="col" class="hidden px-3 py-3 lg:table-cell">{{ __('Phone') }}</th>
            <th scope="col" class="w-24 px-3 py-3 text-right font-medium">{{ __('Actions') }}</th>
        </x-slot:head>
        @forelse ($contacts as $contact)
            <tr wire:key="contact-{{ $contact->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800">
                <th scope="row" class="px-5 py-4">
                    <button type="button" wire:click="openProfile({{ $contact->id }})" class="flex max-w-full items-center gap-3 text-left">
                        <x-contact-avatar :contact="$contact" />
                        <span class="min-w-0 wrap-break-word font-semibold">{{ $contact->name }}</span>
                    </button>
                </th>
                <td class="hidden wrap-break-word px-3 py-4 md:table-cell">{{ $contact->email ?: '—' }}</td>
                <td class="hidden wrap-break-word px-3 py-4 lg:table-cell">{{ $contact->phone ?: '—' }}</td>
                <td class="px-3 py-4 text-right">
                    <flux:button size="sm" variant="outline" wire:click="openProfile({{ $contact->id }})" :aria-label="__('View details for :name', ['name' => $contact->name])">{{ __('Details') }}</flux:button>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="p-8 text-center text-slate-600">
                {{ trim($search) !== '' ? __('No matching contacts.') : ($status === 'archived' ? __('No archived contacts.') : ($suppliers ? __('No suppliers yet.') : __('No customers yet.'))) }}
            </td></tr>
        @endforelse
    </x-directory-table>

    <flux:modal name="contact-profile" variant="flyout" class="w-full sm:max-w-md">
        @if ($profile)
            <div class="space-y-6">
                <p class="agos-eyebrow">{{ $suppliers ? __('Supplier profile') : __('Customer profile') }}</p>
                <div class="flex items-center gap-3">
                    <x-contact-avatar :contact="$profile" />
                    <flux:heading size="xl" class="min-w-0 wrap-break-word">{{ $profile->name }}</flux:heading>
                </div>
                <dl class="space-y-4 border-y border-slate-200 py-5 text-sm">
                    @foreach ([__('Business') => $team->name, __('Status') => $profile->trashed() ? __('Archived') : __('Active'), __('Email') => $profile->email ?: __('No email added'), __('Phone') => $profile->phone ?: __('No phone added')] as $label => $detail)
                        <div><dt class="font-medium text-slate-600">{{ $label }}</dt><dd class="mt-1 wrap-break-word">{{ $detail }}</dd></div>
                    @endforeach
                </dl>
                @unless ($profile->trashed())
                    <div class="flex flex-wrap gap-2">
                        <flux:button variant="primary" wire:click="edit({{ $profile->id }})">{{ __('Edit details') }}</flux:button>
                        <flux:modal.trigger :name="'archive-contact-'.$profile->id"><flux:button>{{ __('Archive') }}</flux:button></flux:modal.trigger>
                    </div>
                @endunless
            </div>
        @endif
    </flux:modal>
    @if ($profile && ! $profile->trashed())
        <flux:modal :name="'archive-contact-'.$profile->id" class="w-full max-w-md">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Archive :name?', ['name' => $profile->name]) }}</flux:heading>
                <flux:text>{{ __('This contact will move from Active to Archived. You can still view its profile.') }}</flux:text>
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:modal.close><flux:button autofocus>{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="archive({{ $profile->id }})">{{ $suppliers ? __('Archive supplier') : __('Archive customer') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</section>
