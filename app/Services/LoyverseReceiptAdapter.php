<?php

namespace App\Services;

use App\Data\LoyverseReceiptIssue;
use App\Models\LoyverseReceipt;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use DateTimeInterface;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use stdClass;

/**
 * Stages source evidence. Only a reconciled, complete sync may publish cash.
 *
 * @phpstan-type Connection array{token: string, merchant: string, store: string}
 */
class LoyverseReceiptAdapter
{
    /**
     * @param  (Closure(LoyverseReceipt): void)|null  $accepted
     * @param  (Closure(LoyverseReceiptIssue): void)|null  $rejected
     */
    public function stagePage(User $actor, Team $team, DateTimeInterface $from, DateTimeInterface $until, ?string $cursor = null, ?Closure $accepted = null, ?Closure $rejected = null): ?string
    {
        $connection = $this->connection($actor, $team);
        if ($from >= $until) {
            throw new \InvalidArgumentException('The update window must end after its start.');
        }
        $currency = $this->currency($connection);
        $page = $this->get($connection, '/receipts', array_filter([
            'store_id' => $connection['store'],
            'updated_at_min' => CarbonImmutable::instance($from)->utc()->toISOString(),
            'updated_at_max' => CarbonImmutable::instance($until)->utc()->toISOString(),
            'cursor' => $cursor,
        ], fn ($value) => $value !== null));

        $rows = $page->receipts ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('Invalid Loyverse receipts page.');
        }
        if (property_exists($page, 'cursor') && (! is_string($page->cursor) || $page->cursor === '' || $page->cursor === $cursor)) {
            throw new DomainException('Invalid Loyverse continuation cursor.');
        }
        $this->stage($actor, $team, $connection, $currency, $rows, $accepted, $rejected);

        return $page->cursor ?? null;
    }

    public function stageReceipt(User $actor, Team $team, string $number): void
    {
        $connection = $this->connection($actor, $team);
        if (in_array($number, ['', '.', '..'], true) || str_contains($number, '/')) {
            throw new \InvalidArgumentException('Invalid receipt number.');
        }
        $currency = $this->currency($connection);
        $row = $this->get($connection, '/receipts/'.rawurlencode($number));
        if (($row->receipt_number ?? null) !== $number) {
            throw new RuntimeException('Loyverse returned a different receipt.');
        }
        $this->stage($actor, $team, $connection, $currency, [$row]);
    }

    /** @return Connection */
    private function connection(User $actor, Team $team): array
    {
        $this->authorize($actor, $team->fresh() ?? abort(404));
        $connection = config("loyverse.connections.{$team->id}");
        foreach (['merchant', 'store', 'token'] as $field) {
            if (! is_string($connection[$field] ?? null) || trim($connection[$field]) === '') {
                throw new RuntimeException('The business Loyverse connection is incomplete.');
            }
        }

        return $connection;
    }

    private function authorize(User $actor, Team $team): void
    {
        Gate::forUser($actor)->authorize('updateBusinessProfile', $team);
        abort_unless(! $team->is_personal && $team->isOnboardingComplete() && ! $team->trashed(), 403);
    }

    /** @param Connection $connection */
    private function currency(array $connection): ?string
    {
        $merchant = $this->get($connection, '/merchant/');
        $currency = $merchant->currency->code ?? null;
        if (($merchant->id ?? null) !== $connection['merchant']) {
            throw new RuntimeException('Cannot verify the mapped Loyverse merchant or its currency.');
        }

        return is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency) ? $currency : null;
    }

    /**
     * @param  Connection  $connection
     * @param  array<string,string>  $query
     */
    private function get(array $connection, string $path, array $query = []): stdClass
    {
        try {
            $response = Http::acceptJson()->withToken($connection['token'])->withoutRedirecting()
                ->timeout(8)->get('https://api.loyverse.com/v1.0'.$path, $query);
        } catch (ConnectionException) {
            throw new RuntimeException('Loyverse could not be reached.');
        }
        if ($response->status() === 400 && $response->json('errors.0.code') === 'INVALID_CURSOR') {
            throw new DomainException('Invalid Loyverse continuation cursor.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Loyverse request failed (HTTP '.$response->status().').');
        }
        $body = $response->body();
        if (! json_validate($body) || ! str_starts_with(ltrim($body), '{')) {
            throw new RuntimeException('Invalid Loyverse JSON response.');
        }
        // Quote monetary number tokens before decoding. Skip whole JSON strings,
        // including escaped quotes, so text inside a receipt cannot match a key.
        $exact = preg_replace_callback('/("(?:[^"\\\\]++|\\\\.)*+")(\s*:\s*)(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|"(?:[^"\\\\]++|\\\\.)*+"(*SKIP)(*F)/', static function (array $match): string {
            $key = json_decode($match[1], flags: JSON_THROW_ON_ERROR);

            return in_array($key, ['money_amount', 'total_money', 'total_discount', 'total_tax', 'tip', 'surcharge'], true)
                ? $match[1].$match[2].'"'.$match[3].'"'
                : $match[0];
        }, $body);
        if ($exact === null) {
            throw new RuntimeException('Could not decode Loyverse financial values.');
        }

        return json_decode($exact, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  Connection  $connection
     * @param  list<mixed>  $rows
     * @param  (Closure(LoyverseReceipt): void)|null  $accepted
     * @param  (Closure(LoyverseReceiptIssue): void)|null  $rejected
     */
    private function stage(User $actor, Team $team, array $connection, ?string $currency, array $rows, ?Closure $accepted = null, ?Closure $rejected = null): void
    {
        DB::transaction(function () use ($actor, $team, $connection, $currency, $rows, $accepted, $rejected) {
            $team = Team::query()->lockForUpdate()->findOrFail($team->id);
            $this->authorize($actor, $team);
            foreach ($rows as $row) {
                $row = $row instanceof stdClass ? get_object_vars($row) : [];
                if (isset($row['store_id']) && $row['store_id'] !== $connection['store']) {
                    throw new RuntimeException('Cannot verify Loyverse receipt identity or store.');
                }
                $number = $row['receipt_number'] ?? null;
                $number = is_string($number) && trim($number) !== '' && strlen($number) <= 255 ? $number : null;
                try {
                    if ($number === null || ! isset($row['store_id'])) {
                        throw new DomainException('Cannot verify Loyverse receipt identity or store.');
                    }
                    if ($currency === null) {
                        throw new DomainException('Merchant currency could not be verified.');
                    }
                    $record = $this->stageRevision($team, $connection, $currency, $number, $row, $accepted !== null);
                    if ($accepted !== null) {
                        $accepted($record);
                    }
                } catch (DomainException $error) {
                    if ($rejected === null) {
                        throw $error;
                    }
                    $rejected(new LoyverseReceiptIssue(isset($row['store_id']) ? $number : null, $error->getMessage()));
                }
            }
        });
    }

    /**
     * @param  Connection  $connection
     * @param  array<string,mixed>  $row
     */
    private function stageRevision(Team $team, array $connection, string $currency, string $number, array $row, bool $verifyReplay): LoyverseReceipt
    {
        $timestamp = $row['updated_at'] ?? null;
        if (! is_string($timestamp) || ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/D', $timestamp)) {
            throw new DomainException('Missing or invalid Loyverse receipt revision.');
        }
        try {
            $updated = CarbonImmutable::parse($timestamp)->utc();
        } catch (InvalidFormatException) {
            throw new DomainException('Invalid Loyverse receipt revision date.');
        }
        if (\DateTimeImmutable::getLastErrors() !== false) {
            throw new DomainException('Invalid Loyverse receipt revision date.');
        }

        $record = $team->loyverseReceipts()->firstOrNew(['receipt_number' => $number]);
        if ($record->exists) {
            if ($record->merchant_id !== $connection['merchant'] || $record->store_id !== $connection['store']) {
                throw new RuntimeException('The receipt belongs to a different source mapping.');
            }
            if ($record->source_updated_at > $updated || (! $verifyReplay && $record->source_updated_at == $updated)) {
                if ($verifyReplay && $record->currency_code !== $currency) {
                    throw new DomainException('Currency differs from previously staged evidence.');
                }

                return $record;
            }
        }
        try {
            $fields = $this->financialFields($row);
            if ($record->exists && $record->source_updated_at == $updated) {
                if ($record->currency_code !== $currency || $record->receipt !== $fields) {
                    throw new DomainException('Conflicting evidence for the same receipt revision.');
                }

                return $record;
            }
        } catch (DomainException $error) {
            // Preserve the invalid revision so an older replay cannot restore cash.
            $fields = [];
        }
        $record->fill([
            'merchant_id' => $connection['merchant'], 'store_id' => $connection['store'],
            'currency_code' => $currency, 'source_updated_at' => $updated, 'synced_at' => now(), 'receipt' => $fields,
        ])->save();
        if (isset($error)) {
            throw $error;
        }

        return $record;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function financialFields(array $row): array
    {
        $receipt = $this->scalarFields($row, ['receipt_number', 'receipt_type', 'refund_for', 'receipt_date', 'created_at', 'updated_at', 'cancelled_at', 'store_id', 'total_money', 'total_tax', 'total_discount', 'tip', 'surcharge']);
        foreach ([
            'payments' => ['payment_type_id', 'name', 'type', 'money_amount', 'paid_at'],
            'total_taxes' => ['id', 'type', 'name', 'money_amount'],
            'total_discounts' => ['id', 'type', 'name', 'money_amount'],
        ] as $key => $fields) {
            if (isset($row[$key])) {
                if (! is_array($row[$key]) || ! array_is_list($row[$key])) {
                    throw new DomainException('Invalid Loyverse financial breakdown.');
                }
                $entries = [];
                foreach ($row[$key] as $entry) {
                    if (! $entry instanceof stdClass) {
                        throw new DomainException('Invalid Loyverse financial entry.');
                    }
                    $entries[] = $this->scalarFields(get_object_vars($entry), $fields);
                }
                $receipt[$key] = $entries;
            }
        }

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  list<string>  $fields
     * @return array<string,string|null>
     */
    private function scalarFields(array $row, array $fields): array
    {
        $selected = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $row)) {
                continue;
            }
            $value = $row[$field];
            if ($value !== null && ! is_string($value)) {
                throw new DomainException('Invalid Loyverse financial field.');
            }
            $selected[$field] = $value;
        }

        return $selected;
    }
}
