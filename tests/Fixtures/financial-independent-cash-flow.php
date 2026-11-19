<?php

/*
 * Independent P4-T05 fixture. Expected results are calculated here from the
 * ledger inputs, not copied from CashFlowService output:
 *
 * Opening 100.00 + confirmed inflow 20.10 - confirmed outflow 10.10 =
 * current cash 110.00. The remaining invoice is 20.00 and the remaining bill
 * is 10.00 after their partial payments. On 2026-12-02 the net forecast is
 * 0.20 - 10.00 = -9.80, leaving 100.20. On 2026-12-03 it is
 * 20.00 - 50.00 = -30.00, leaving 70.20. The configured 80.00 threshold
 * is first crossed on 2026-12-03, with a maximum cash gap of 9.80. The
 * 0.30 boundary-date inflow on 2026-12-07 leaves the final balance at 70.50.
 */
return [
    'as_of' => '2026-12-01',
    'days' => 7,
    'opening_balance' => '100.00',
    'threshold' => '80.00',
    'historical_transactions' => [
        ['direction' => 'income', 'amount' => '10.10', 'date' => '2026-11-30', 'description' => 'Prior confirmed sale'],
        ['direction' => 'income', 'amount' => '10.00', 'date' => '2026-11-30', 'description' => 'Partial invoice payment'],
        ['direction' => 'expense', 'amount' => '0.10', 'date' => '2026-12-01', 'description' => 'Small confirmed expense'],
        ['direction' => 'expense', 'amount' => '5.00', 'date' => '2026-12-01', 'description' => 'Customer refund'],
        ['direction' => 'expense', 'amount' => '5.00', 'date' => '2026-11-30', 'description' => 'Partial supplier payment'],
    ],
    'future_transactions' => [
        ['direction' => 'income', 'amount' => '0.20', 'date' => '2026-12-02', 'description' => 'Boundary precision sale'],
        ['direction' => 'expense', 'amount' => '50.00', 'date' => '2026-12-03', 'description' => 'Confirmed scheduled expense'],
        ['direction' => 'income', 'amount' => '0.30', 'date' => '2026-12-07', 'description' => 'Period-end confirmed sale'],
    ],
    'invoice' => ['total' => '30.00', 'paid' => '10.00', 'due_date' => '2026-12-03'],
    'bill' => ['total' => '15.00', 'paid' => '5.00', 'due_date' => '2026-12-02'],
    'expected' => [
        'confirmed_inflow' => '20.10',
        'confirmed_outflow' => '10.10',
        'confirmed_net' => '10.00',
        'current_cash' => '110.00',
        'invoice_balance' => '20.00',
        'bill_balance' => '10.00',
        'dec_2_projected_cash' => '100.20',
        'dec_3_projected_cash' => '70.20',
        'period_end_projected_cash' => '70.50',
        'earliest_shortfall' => '2026-12-03',
        'cash_gap' => '9.80',
    ],
];
