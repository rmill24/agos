# P3-T01 — Loyverse receipt import contract

Verified 19 November 2026 against the published Loyverse v1.0 OpenAPI schema. The provider fields below are documented. The import decisions implement AGOS FR-LYV-01 through FR-LYV-10 and §5.5. Live account access, currency and financial reconciliation remain unverified. [Official schema](https://developer.loyverse.com/docs/API-Reference__v1.0.yaml) · [AGOS financial rules](../AGOS_Functional_Requirements_Specification.md#36-loyverse-pos-integration)

## Reading receipts for one business

Use HTTPS with a server-side bearer token against `https://api.loyverse.com/v1.0`. OAuth documents `RECEIPTS_READ` for receipts and `MERCHANT_READ` for merchant information. A Back Office personal token grants account-wide API access. AGOS must restrict its adapter to the following GET operations with either credential type. [API reference](https://developer.loyverse.com/docs/) · [Back Office token permissions](https://help.loyverse.com/help/loyverse-api)

| GET path | Purpose and authorization boundary |
| --- | --- |
| `/merchant/` | Read the merchant ID and ISO 4217 `currency.code`. Bind that evidence to the authorized Team's merchant and store mapping. Confirm cash only when PHP applies to the receipt. |
| `/receipts` | Request the mapped `store_id`. Independently check the store on each returned receipt before accepting it for the Team. |
| `/receipts/{receipt_number}` | Retrieve one receipt by its unique number. Apply the same ownership check to the response because this lookup uses only the receipt number. |

For an incremental run, hold the store and update window fixed. Use ISO 8601 `updated_at_min` and `updated_at_max` to capture edits to older receipts. Loyverse describes these bounds as “after” and “before”. Overlap successive windows and deduplicate to protect boundary timestamps. The list is ordered by `created_at` descending, so neither the first record nor its timestamp establishes a completed window. [Receipt list and pagination](https://developer.loyverse.com/docs/)

Start without a cursor. The documented page size is 1–250, default 50. Follow each response's opaque `cursor` on the same list request with the original filters until the response omits it. Stage completed pages with the window and resume position. A failed page or invalid cursor leaves the run incomplete. Replay the window if necessary. Publish the reconciled result and advance the successful checkpoint only after all pages are accounted for. Keep the last successful result visible during an interruption.

## Financial interpretation

| Source evidence | AGOS decision |
| --- | --- |
| `receipt_number`, `store_id`, `updated_at` | Identify the external record by receipt number within the authorized Team and Loyverse source. Persist `source_system = loyverse`, the external ID, provider update time and AGOS sync time. Replay an identical version once in totals. Replace the normalized result on a verified newer revision and guard against stale replays. |
| `receipt_date`, `created_at` | Use the effective receipt date for the sale or refund. Loyverse allows it to predate creation. Preserve timestamps in UTC and present business dates in Asia/Manila. |
| `receipt_type`, `refund_for`, `cancelled_at` | The documented types are `SALE` and `REFUND`. A completed refund has its own identity and effective date. Link the original by `refund_for` when available within the Team and record the returned amount as negative exactly once. A populated cancellation timestamp excludes the cancelled receipt from confirmed totals, including on revision. Cancellation alone does not establish a refund payment. |
| `payments[].payment_type_id`, `name`, `type`, `money_amount`, `paid_at` | Preserve payment methods and individual amounts. The payment schema requires the ID and amount. Its timestamp describes payment creation. It supplies no generic paid, pending or settled field. Require an active receipt, a verified collected or returned meaning for the store's payment method, and payment amounts that reconcile to the receipt. A sale label, an order identifier or a timestamp alone is insufficient. |
| `total_money`, `total_discount`, `total_discounts`, `total_tax`, `total_taxes[].type`, `tip`, `surcharge` | Reconcile the payment sum to the final paid or returned amount. The total already reflects discounts, taxes, tips and surcharges. Preserve the breakdown, including `INCLUDED` versus `ADDED` taxes. Payment amounts already include applicable tips and surcharges. Count each actual payment once and use the receipt total as a check. |
| Merchant `currency.code` | The receipt and payment schemas omit currency. Require matching merchant evidence of PHP for the mapped store and applicable period. Country, decimal places and the AGOS default cannot establish currency. Reject non-PHP for review without conversion. |

Read monetary JSON numbers without conversion through PHP floats. Use exact decimal strings and compare at the FRS tolerance of PHP 0.01. Quarantine missing identity, required dates, unknown currency, unclear payment meaning, conflicting signs or discrepancies above tolerance, recording the reason. Exclude quarantined amounts from confirmed cash. Known unpaid, void or pending amounts contribute zero. A receipt absent from the feed supplies no evidence of an expected collection. [Receipt, Payment and MerchantProfile schemas](https://developer.loyverse.com/docs/API-Reference__v1.0.yaml) · [AGOS reconciliation rules](../AGOS_Functional_Requirements_Specification.md#55-loyverse-reconciliation-rules)

## Synthetic examples for later tests

Assume an authorized store and verified PHP and payment methods except where a row changes that evidence. Figures are pesos.

| Case | Input | Expected AGOS result |
| --- | --- | --- |
| Discount and included tax | Gross 112.00, discount 11.20, final total 100.80 including 10.80 tax, collected 100.80 | Confirm +100.80. |
| Split payment | Cash 60.00 and card 40.00, total 100.00 | Retain both payments. Confirm +100.00 across them. |
| Completed refund | Sale A on Nov 10, refund B linked to A on Nov 12, verified returned magnitude 25.00 | Keep A. Record −25.00 on Nov 12 under B. Replaying B contributes zero additional cash. |
| Cancellation | A previously imported +80.00 sale acquires a cancellation timestamp | Its revised contribution is zero. Do not invent an additional refund. |
| Unclear collection | Sale total 80.00 with no payments, or an unmapped deferred-payment method | Quarantine with reason. Confirm zero. A separately verified unpaid order also contributes zero. |
| Amount conflict | Payments sum to 99.98 on a 100.00 sale | Quarantine the 0.02 discrepancy. |
| Currency | Otherwise valid sale, merchant currency missing or USD | Missing currency is quarantined. USD is rejected for review. Both contribute zero. |
| Repeated receipt | Same Team, receipt number and version on two pages or runs | One normalized receipt. Confirm its payments once. |
| Older receipt updated later | A Nov 1 sale first contributed 75.00. A Nov 15 revision reconciles to 70.00 and appears on a later page | Finish the update window and replace 75.00 with 70.00. A page failure leaves the previous published result and checkpoint in place. |

## Evidence still needed from a live store

| Open question | Evidence for live verification |
| --- | --- |
| Can the configured credential read the intended store and merchant? Is PHP applicable? | Authorized GET responses for the mapped merchant and store receipts. Match merchant ID, store ID and currency code to the Team. Confirm the currency covered the imported period if historical settings are unclear. |
| Which tender types mean money was collected? How are pending or unpaid orders represented, if exposed? | Reconcile redacted receipts for known paid and split-tender transactions against Back Office payment details. Compare a known unpaid or pending example with what the feed returns before enabling that mapping. |
| How are refund signs, dates and cancellations represented for this store? | Compare an original sale, a completed refund and a cancelled receipt to dated Back Office records. Check payment and total signs, the original reference where available, and net cash. |
| Do incremental reads recover changes across page and time boundaries? | Read a bounded window with a small page size and an older updated receipt. Compare the complete result and an overlapping replay against the store's known records. |
