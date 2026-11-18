<?php

namespace App\Data;

final readonly class LoyverseReceiptIssue
{
    public function __construct(public ?string $receiptNumber, public string $reason) {}
}
