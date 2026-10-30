<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Enums\ProductType;
use App\Domain\Inventory\Enums\StockItemStatus;
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The item catalogue.
 *
 * The account columns are not decoration: a receipt debits the product's
 * inventory account and an issue credits it, so a product is what decides
 * where its own stock sits in the books. They are therefore validated against
 * the caller's own chart rather than merely existing, which is the same trap
 * the customer list avoids: an item pointed at another tenant's account would
 * post every one of its movements into the wrong set of books.
 *
 * Goods are required to name both their inventory account and the expense
 * account their cost goes to, because without them the product cannot be
 * received or issued at all. A service names neither and holds no stock.
 *
 * There is no delete. A product that has moved carries the history of what it
 * cost, so withdrawing it means setting it inactive, and the model
 * soft-deletes for the same reason.
 */
class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(StockItemStatus::class)],
            'product_type' => ['sometimes', 'nullable', Rule::enum(ProductType::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()
            ->where('company_id', $this->companyId($request))
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->search($filters['search'])
            )
            ->when(
                filled($filters['status'] ?? null),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->when(
                filled($filters['product_type'] ?? null),
                fn ($query) => $query->where('product_type', $filters['product_type'])
            )
            ->orderBy('sku')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (Product $product): array => $this->present($product),
                $products->items()
            ),
            'meta' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $companyId = $this->companyId($request);
        $type = $this->requestedType($request, ProductType::Goods);

        $data = $request->validate($this->rules($companyId, $type));
        $type = ProductType::from($data['product_type'] ?? $type->value);

        $product = new Product([
            ...$data,
            'company_id' => $companyId,
            'product_type' => $type->value,
            'unit' => $data['unit'] ?? 'pcs',
            'status' => $data['status'] ?? StockItemStatus::Active->value,
        ]);

        $product->unit_cost = $data['unit_cost'] ?? '0.0000';
        $product->sale_price = $data['sale_price'] ?? '0.0000';

        // A product that holds stock has nowhere to put it without these; a
        // service has no accounts at all rather than an empty pair.
        $product->inventory_account_id = $type->holdsStock() ? $data['inventory_account_id'] : null;
        $product->expense_account_id = $type->holdsStock() ? $data['expense_account_id'] : null;

        $product->save();

        return response()->json(['data' => $this->present($product)], 201);
    }

    public function show(Request $request, string $product): JsonResponse
    {
        $record = $this->findByUlid($request, $product);

        $this->authorize('view', $record);

        return response()->json(['data' => $this->present($record)]);
    }

    public function update(Request $request, string $product): JsonResponse
    {
        $record = $this->findByUlid($request, $product);

        $this->authorize('update', $record);

        $companyId = $this->companyId($request);
        $type = $this->requestedType($request, $record->product_type);

        $data = $request->validate($this->rules($companyId, $type, $record));
        $type = ProductType::from($data['product_type'] ?? $type->value);

        // What a product is decides whether it has layers at all. Once it has
        // moved, letting the kind change would strand the cost of the stock
        // that went through it, so the movement history locks it.
        if ($type !== $record->product_type && $record->movements()->exists()) {
            throw StockStateException::productTypeLocked($record);
        }

        $record->fill([...$data, 'product_type' => $type->value]);

        if ($type->holdsStock()) {
            $record->inventory_account_id = $data['inventory_account_id'] ?? $record->inventory_account_id;
            $record->expense_account_id = $data['expense_account_id'] ?? $record->expense_account_id;
        } else {
            $record->inventory_account_id = null;
            $record->expense_account_id = null;
        }

        $record->save();

        return response()->json(['data' => $this->present($record)]);
    }

    /**
     * The type the request is aiming at, which is what decides whether the
     * account columns are required. It is read from the input rather than from
     * the rules, because a service being saved must not be told to name the
     * inventory account a goods item would need.
     */
    private function requestedType(Request $request, ProductType $fallback): ProductType
    {
        if (! $request->has('product_type')) {
            return $fallback;
        }

        // A value outside the enum falls back to the current type so that the
        // enum rule reports it; the accounts requirement stays consistent with
        // the type the product actually keeps.
        return ProductType::tryFrom((string) $request->input('product_type')) ?? $fallback;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(int $companyId, ProductType $type, ?Product $product = null): array
    {
        $uniqueSku = Rule::unique('products', 'sku')->where('company_id', $companyId);

        if ($product !== null) {
            $uniqueSku = $uniqueSku->ignore($product->getKey());
        }

        // Demanded only when the item is meant to hold stock and has not named
        // an account yet, so a partial update does not have to resend what the
        // record already carries.
        $needsInventory = $type->holdsStock() && $product?->inventory_account_id === null;
        $needsExpense = $type->holdsStock() && $product?->expense_account_id === null;

        return [
            'sku' => $product === null
                ? ['required', 'string', 'max:40', $uniqueSku]
                : ['sometimes', 'string', 'max:40', $uniqueSku],
            'name' => $product === null
                ? ['required', 'string', 'max:255']
                : ['sometimes', 'string', 'max:255'],
            'unit' => ['sometimes', 'string', 'max:16'],
            'product_type' => ['sometimes', Rule::enum(ProductType::class)],
            // No `sometimes` on these two: a sometimes rule skips the whole
            // attribute when it is absent from the request, which would let a
            // goods item through with no inventory account simply by omitting
            // the key. The requirement is expressed by requiredIf above
            // instead, and the other rules stay quiet on their own because
            // none of them is implicit.
            'inventory_account_id' => [
                Rule::requiredIf($needsInventory),
                'nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'expense_account_id' => [
                Rule::requiredIf($needsExpense),
                'nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'revenue_account_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'tax_code_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('tax_codes', 'id')->where('company_id', $companyId),
            ],
            'unit_cost' => ['sometimes', 'numeric', 'min:0'],
            'sale_price' => ['sometimes', 'numeric', 'min:0'],
            // The level at which the item wants reordering. Null means nobody is
            // watching it; a value the low-stock job compares the layers against.
            'reorder_level' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::enum(StockItemStatus::class)],
        ];
    }

    /**
     * Scoped to the caller's company in the same query that resolves the item,
     * so another tenant's product is a 404 rather than an existence oracle.
     */
    private function findByUlid(Request $request, string $ulid): Product
    {
        return Product::query()
            ->where('company_id', $this->companyId($request))
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Product $product): array
    {
        return [
            'id' => (int) $product->getKey(),
            'ulid' => $product->ulid,
            'company_id' => (int) $product->company_id,
            'sku' => $product->sku,
            'name' => $product->name,
            'unit' => $product->unit,
            'product_type' => $product->product_type->value,
            'holds_stock' => $product->holdsStock(),
            'inventory_account_id' => $product->inventory_account_id === null
                ? null
                : (int) $product->inventory_account_id,
            'expense_account_id' => $product->expense_account_id === null
                ? null
                : (int) $product->expense_account_id,
            'revenue_account_id' => $product->revenue_account_id === null
                ? null
                : (int) $product->revenue_account_id,
            'tax_code_id' => $product->tax_code_id === null ? null : (int) $product->tax_code_id,
            'unit_cost' => $product->unit_cost,
            'sale_price' => $product->sale_price,
            'reorder_level' => $product->reorder_level,
            'status' => $product->status->value,
        ];
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
