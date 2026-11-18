<?php

namespace App\Services;

use App\Models\LoyverseReceipt;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DomainException;

class LoyverseReconciliation
{
    /**
     * @param  list<string>  $methods
     * @return array{business_date: string, movements: list<array{direction:string, amount:string}>}
     */
    public function evaluate(LoyverseReceipt $source, array $methods): array
    {
        $row = $source->receipt;
        if ($source->currency_code !== 'PHP') {
            throw new DomainException('Currency is unverified or differs from PHP.');
        }
        $type = $row['receipt_type'] ?? null;
        if (! in_array($type, ['SALE', 'REFUND'], true)) {
            throw new DomainException('Unknown receipt type.');
        }
        $date = $this->date($row['receipt_date'] ?? null);
        $result = ['business_date' => $date, 'movements' => []];
        if (isset($row['cancelled_at'])) {
            $this->date($row['cancelled_at']);

            return $result;
        }
        $total = $this->amount($row['total_money'] ?? null);
        if (! is_array($row['payments'] ?? null) || ! $row['payments']) {
            throw new DomainException('Payment evidence is missing.');
        }
        $sum = BigDecimal::zero();
        foreach ($row['payments'] as $payment) {
            if (! in_array($payment['payment_type_id'] ?? null, $methods, true)) {
                throw new DomainException('Payment method has no verified collection mapping.');
            }
            $amount = $this->amount($payment['money_amount'] ?? null);
            if (($type === 'SALE' && ($amount->isNegative() || $total->isNegative()))
                || (! $amount->isZero() && $amount->isNegative() !== $total->isNegative())) {
                throw new DomainException('Payment signs conflict with the receipt.');
            }
            $sum = $sum->plus($amount);
            if (! $amount->isZero()) {
                $result['movements'][] = ['direction' => $type === 'SALE' ? 'income' : 'expense', 'amount' => (string) $amount->abs()];
            }
        }
        if ($sum->minus($total)->abs()->isGreaterThan('0.01')) {
            throw new DomainException('Payments differ from the receipt total by more than PHP 0.01.');
        }

        return $result;
    }

    private function amount(mixed $value): BigDecimal
    {
        if (! is_string($value) || ! preg_match('/^-?\d{1,13}(?:\.\d{1,2}0*)?$/D', $value)) {
            throw new DomainException('A required peso amount is missing or invalid.');
        }

        return BigDecimal::of($value)->toScale(2);
    }

    private function date(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/D', $value)) {
            throw new DomainException('Missing or invalid effective date.');
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (\DateMalformedStringException) {
            throw new DomainException('Invalid effective date.');
        }
        if (DateTimeImmutable::getLastErrors() !== false) {
            throw new DomainException('Invalid effective date.');
        }

        return $date->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d');
    }
}
