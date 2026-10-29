<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DTOs\ComputedDocument;
use App\Domain\Accounting\Exceptions\DocumentLineException;
use App\Models\TaxCode;
use App\Support\Money;

/**
 * Prices a set of document lines, and the document they add up to.
 *
 * One calculator serves invoices and bills. The only difference between the
 * two is the *account* a line settles against — revenue on a sale, expense on
 * a purchase — and that is carried on the line itself rather than decided here.
 * The arithmetic is identical, so it lives in one place.
 *
 * The rounding rule is deliberate: every figure is quantised to the money
 * scale at each step, and the document totals are the sum of the rounded line
 * figures rather than a rounding of the sum. That way the lines always add up
 * to the header, which is what a journal posting depends on: if the header
 * were computed independently it could disagree with the lines by a cent and
 * the posting would be rejected as unbalanced.
 *
 * Tax rates are never hardcoded and never read from the request: the rate is
 * resolved from the dated `tax_rates` rows for the document date, so a later
 * rate change cannot rewrite what a historical document was taxed at.
 */
final class DocumentLineCalculator
{
    /**
     * @param  array<int, array<string, mixed>>  $lines  each with description, quantity,
     *                                                   unit_price and optionally discount_rate,
     *                                                   discount_amount, tax_code_id, product_id, account_id
     */
    public function compute(int $companyId, string $documentDate, array $lines): ComputedDocument
    {
        $priced = [];
        $subtotal = Money::zero();
        $discountTotal = Money::zero();
        $taxTotal = Money::zero();
        $total = Money::zero();

        foreach (array_values($lines) as $index => $line) {
            $pricedLine = $this->priceLine($companyId, $documentDate, $line, $index + 1);

            $priced[] = $pricedLine;

            $subtotal = $subtotal->plus(Money::of($pricedLine['line_subtotal']));
            $discountTotal = $discountTotal->plus(Money::of($pricedLine['discount_amount']));
            $taxTotal = $taxTotal->plus(Money::of($pricedLine['tax_amount']));
            $total = $total->plus(Money::of($pricedLine['line_total']));
        }

        return new ComputedDocument($priced, $subtotal, $discountTotal, $taxTotal, $total);
    }

    /**
     * Groups the tax carried by a set of priced lines by the tax code that
     * produced it, and names the ledger account that code posts to: a tax
     * code's output account on a sale, its input account on a purchase.
     *
     * The tax is grouped rather than listed line by line so a twenty-line
     * invoice carries one VAT posting rather than twenty of them, and so the
     * journal is balanced against the header tax_total by construction.
     *
     * It is grouped by *code* rather than by account because the account is not
     * a fine enough key to read the tax back from. Two codes may share one
     * account — a standard-rated and a zero-rated VAT code can both settle into
     * the same VAT control account — and a tax return has to tell them apart.
     * Grouping by code lets the posting name the code it came from, so the
     * ledger line says what it is, and two such codes produce two lines on the
     * same account rather than one anonymous line the return cannot split.
     *
     * A code with nothing due is skipped: a zero-rated code charges no tax, so
     * it has no posting to make. That skip happens before the account is
     * resolved, which is what lets a zero-rated purchase carry a code that
     * names no input account.
     *
     * @param  iterable<object>  $lines  priced lines exposing tax_code_id and tax_amount
     * @return array<int, array{tax_code_id: int, tax_code: string, account_id: int, amount: Money}>
     *                                   tax code id => what that code owes and where it goes
     */
    public function taxByCode(int $companyId, iterable $lines, bool $purchase): array
    {
        $grouped = [];

        foreach ($lines as $line) {
            $amount = Money::of($line->tax_amount);

            if (! $amount->isPositive()) {
                continue;
            }

            if ($line->tax_code_id === null) {
                throw new DocumentLineException(
                    'A line carries tax but names no tax code, so the tax has nowhere to post.'
                );
            }

            $taxCodeId = (int) $line->tax_code_id;

            if (! isset($grouped[$taxCodeId])) {
                $taxCode = TaxCode::query()
                    ->where('company_id', $companyId)
                    ->whereKey($taxCodeId)
                    ->first();

                if ($taxCode === null) {
                    throw DocumentLineException::unknownTaxCode($taxCodeId);
                }

                $accountId = $purchase ? $taxCode->input_account_id : $taxCode->output_account_id;

                if ($accountId === null) {
                    throw DocumentLineException::taxAccountMissing($taxCode->code);
                }

                $grouped[$taxCodeId] = [
                    'tax_code_id' => $taxCodeId,
                    'tax_code' => $taxCode->code,
                    'account_id' => (int) $accountId,
                    'amount' => Money::zero(),
                ];
            }

            $grouped[$taxCodeId]['amount'] = $grouped[$taxCodeId]['amount']->plus($amount);
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function priceLine(int $companyId, string $documentDate, array $line, int $lineNo): array
    {
        $quantity = Money::of($this->decimal($line['quantity'] ?? '0'));
        $unitPrice = Money::of($this->decimal($line['unit_price'] ?? '0'));

        $gross = $quantity->times($unitPrice->toDecimal());

        $discountRate = Money::of($this->decimal($line['discount_rate'] ?? '0'));
        $discount = array_key_exists('discount_amount', $line) && $line['discount_amount'] !== null
            ? Money::of($this->decimal($line['discount_amount']))
            : $gross->times($discountRate->toDecimal());

        $net = $gross->minus($discount);

        [$taxCode, $rate] = $this->resolveTax($companyId, $documentDate, $line);

        if (! $taxCode?->is_inclusive) {
            $lineSubtotal = $net;
            $taxAmount = $lineSubtotal->times($rate->toDecimal());
            $lineTotal = $lineSubtotal->plus($taxAmount);
        } else {
            // An inclusive code has the tax baked into the price, so the gross
            // figure is the line total and the net has to be taken back out.
            $lineTotal = $net;
            $lineSubtotal = $net->dividedBy(Money::of('1')->plus($rate)->toDecimal());
            $taxAmount = $lineTotal->minus($lineSubtotal);
        }

        return [
            'line_no' => $lineNo,
            'description' => (string) $line['description'],
            'quantity' => $quantity->toDecimal(),
            'unit_price' => $unitPrice->toDecimal(),
            'discount_rate' => $discountRate->toDecimal(),
            'discount_amount' => $discount->toDecimal(),
            'tax_code_id' => $line['tax_code_id'] ?? null,
            'tax_rate' => $rate->toDecimal(),
            'tax_amount' => $taxAmount->toDecimal(),
            'line_subtotal' => $lineSubtotal->toDecimal(),
            'line_total' => $lineTotal->toDecimal(),
            'product_id' => $line['product_id'] ?? null,
            'account_id' => $line['account_id'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{0: TaxCode|null, 1: Money}
     */
    private function resolveTax(int $companyId, string $documentDate, array $line): array
    {
        $taxCodeId = $line['tax_code_id'] ?? null;

        if ($taxCodeId === null) {
            return [null, Money::zero()];
        }

        $taxCode = TaxCode::query()
            ->where('company_id', $companyId)
            ->whereKey($taxCodeId)
            ->first();

        if ($taxCode === null) {
            throw DocumentLineException::unknownTaxCode((int) $taxCodeId);
        }

        $rate = $taxCode->rateOn($documentDate);

        if ($rate === null) {
            throw DocumentLineException::noRateInForce($taxCode->code, $documentDate);
        }

        return [$taxCode, Money::of($rate->rate)];
    }

    /**
     * Quantities and prices arrive from JSON, where a number may decode to an
     * int or a float. Both are turned into a plain decimal string here; money
     * never passes through a float accumulator.
     */
    private function decimal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value) && preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
            return $value;
        }

        if (is_float($value)) {
            // Reached only when the request layer has already validated the
            // shape; the string form is the shortest representation PHP can
            // round-trip, so no digits are invented.
            return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0';
        }

        throw new DocumentLineException(sprintf(
            'A document line expects a plain decimal quantity or price, got %s.',
            get_debug_type($value)
        ));
    }
}
