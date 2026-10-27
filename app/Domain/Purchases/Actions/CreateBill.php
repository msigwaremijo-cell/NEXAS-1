<?php

namespace App\Domain\Purchases\Actions;

use App\Domain\Accounting\Enums\DocumentType;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Accounting\Services\DocumentLineCalculator;
use App\Domain\Accounting\Services\DocumentNumbering;
use App\Domain\Accounting\Services\FiscalCalendar;
use App\Domain\Purchases\Enums\BillStatus;
use App\Domain\Purchases\Exceptions\BillStateException;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes a draft supplier bill and prices its lines.
 *
 * The shape mirrors CreateInvoice closely — the arithmetic is shared through
 * DocumentLineCalculator — with two differences that follow from the schema:
 * a line settles against an expense or inventory account rather than revenue,
 * and the document starts its life behind an approval gate, so a draft bill is
 * worth nothing until somebody has approved it.
 */
final class CreateBill
{
    public function __construct(
        private readonly FiscalCalendar $calendar,
        private readonly DocumentNumbering $numbering,
        private readonly DocumentLineCalculator $calculator,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  bill_date, optional due_date, supplier_invoice_no,
     *                                            branch_id, currency_code, exchange_rate, reference,
     *                                            notes, lines
     */
    public function __invoke(int $companyId, int $supplierId, array $attributes, ?int $userId = null): Bill
    {
        $lines = $attributes['lines'] ?? [];

        if ($lines === []) {
            throw new BillStateException('A bill needs at least one line before it can be created.');
        }

        return DB::transaction(function () use ($companyId, $supplierId, $attributes, $lines, $userId): Bill {
            $supplier = Supplier::query()
                ->where('company_id', $companyId)
                ->whereKey($supplierId)
                ->firstOrFail();

            if (! $supplier->status->acceptsNewDocuments()) {
                throw BillStateException::partyBlocked($supplier);
            }

            $billDate = (string) $attributes['bill_date'];
            $period = $this->calendar->resolve($companyId, $billDate);
            $computed = $this->calculator->compute($companyId, $billDate, $lines);

            $bill = new Bill([
                'company_id' => $companyId,
                'supplier_id' => (int) $supplier->getKey(),
                'branch_id' => $attributes['branch_id'] ?? null,
                'bill_no' => $this->numbering->next(
                    $companyId,
                    DocumentType::Bill,
                    $period->fiscal_year_id
                ),
                'supplier_invoice_no' => $attributes['supplier_invoice_no'] ?? null,
                'document_type' => 'bill',
                'status' => BillStatus::Draft->value,
                'bill_date' => $billDate,
                'due_date' => $attributes['due_date']
                    ?? Carbon::parse($billDate)->addDays($supplier->payment_terms_days)->toDateString(),
                'currency_code' => $attributes['currency_code'] ?? $supplier->currency_code,
                'exchange_rate' => $attributes['exchange_rate'] ?? '1',
                'reference' => $attributes['reference'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'approval_status' => 'not_required',
                'created_by_user_id' => $userId,
            ]);

            $bill->subtotal = $computed->subtotal->toDecimal();
            $bill->discount_total = $computed->discountTotal->toDecimal();
            $bill->tax_total = $computed->taxTotal->toDecimal();
            $bill->total = $computed->total->toDecimal();
            $bill->amount_paid = '0.0000';

            $bill->save();

            foreach ($computed->lines as $priced) {
                $line = new BillLine([
                    'company_id' => $companyId,
                    'bill_id' => $bill->getKey(),
                    'line_no' => $priced['line_no'],
                    'product_id' => $priced['product_id'],
                    'description' => $priced['description'],
                    'quantity' => $priced['quantity'],
                    'unit_price' => $priced['unit_price'],
                    'discount_rate' => $priced['discount_rate'],
                    'tax_code_id' => $priced['tax_code_id'],
                    'expense_account_id' => $priced['account_id'],
                ]);

                $line->discount_amount = $priced['discount_amount'];
                $line->tax_rate = $priced['tax_rate'];
                $line->tax_amount = $priced['tax_amount'];
                $line->line_subtotal = $priced['line_subtotal'];
                $line->line_total = $priced['line_total'];

                $line->save();
            }

            $this->audit->record('bill.created', $bill, null, [
                'bill_no' => $bill->bill_no,
                'supplier_id' => (int) $supplier->getKey(),
                'total' => $bill->total,
                'line_count' => count($computed->lines),
            ]);

            return $bill->load('lines');
        });
    }
}
