<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class BusinessContactService
{
    public function __construct(private AuditEventService $auditEvents) {}

    public function records(User $actor, Team $team, bool $suppliers): HasMany
    {
        // Reload onboarding state for callers holding an older Team instance.
        $team = Team::query()->findOrFail($team->id);
        Gate::forUser($actor)->authorize('manageContacts', $team);

        return $suppliers ? $team->suppliers() : $team->customers();
    }

    public function save(User $actor, Team $team, bool $suppliers, ?int $id, array $input): void
    {
        $records = $this->records($actor, $team, $suppliers);
        $attributes = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ])->validate();

        DB::transaction(function () use ($actor, $team, $records, $id, $attributes): void {
            /** @var Customer|Supplier $contact */
            $contact = $id === null ? $records->make() : $records->lockForUpdate()->findOrFail($id);
            $contact->fill($attributes)->save();
            $this->auditEvents->record(
                $actor, $team, $this->event($contact, $id === null ? 'created' : 'updated'), $contact,
            );
        });
    }

    public function archive(User $actor, Team $team, bool $suppliers, int $id): string
    {
        $records = $this->records($actor, $team, $suppliers);

        return DB::transaction(function () use ($actor, $team, $records, $id): string {
            $contact = $records->lockForUpdate()->findOrFail($id);
            $contact->delete();
            $this->auditEvents->record($actor, $team, $this->event($contact, 'archived'), $contact);

            return $contact->name;
        });
    }

    private function event(Customer|Supplier $contact, string $action): string
    {
        return ($contact instanceof Supplier ? 'supplier' : 'customer').'.'.$action;
    }
}
