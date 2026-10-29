# Inventory (Stage 8)

Stock is kept as two things at once: what is on the shelf, and what it is worth.
Stage 8 builds both halves in one place — a movement changes the quantity, and
the same movement posts the ledger entry that carries its value.

Stage 8 built the module standalone; Stage 8B, at the end of this document,
connects it to Stage 6 so that a sale issues stock and a purchase receives it.

## Costing

Costing is FIFO, and it is held in `stock_layers` rather than recomputed on
demand. A receipt opens a layer holding the quantity it brought in, the unit
price it arrived at, and how much of it is still unconsumed. An issue draws on
those layers oldest first, at each layer's own price, so what stock leaving is
worth is what the company actually paid for those particular units rather than
an average of everything on the shelf.

The method is a company policy, not a per-movement decision. `config/nexas.php`
carries the application default (`NEXAS_INVENTORY_COSTING`, `fifo`) and a company
may override it in `companies.settings`. Two movements of the same item valued by
different rules would make the inventory account stop meaning anything, so the
choice is made once, for the tenant.

Only FIFO is built. `InventoryCosting` also names weighted average, and a company
set to it is refused out loud rather than quietly costed by the one method the
system does know.

**The costing method needs confirmation from an accountant before it is
changed.** It is an accounting policy, not a preference.

### The lock

Once any stock movement exists, the method may not be changed.
`StockPolicy::assertCostingMayChangeTo()` refuses it. The reason is that the
layers already written were priced by the method in force at the time, so
switching now would value what is left by a rule the stock was never bought
under, and the difference would land nowhere in particular.

Reversed movements count. A movement that has been reversed still happened, and
the layers it touched were priced under the old method.

The guard is only worth having if the settings screen calls it before writing a
new method, so `PATCH /api/v1/company/inventory-settings` calls it inside a
`lockForUpdate()` transaction, on the row it is about to write. Two changes
racing each other therefore cannot both see empty shelves and both be allowed.

### Negative stock

Whether stock may go below zero is also a company policy
(`NEXAS_ALLOW_NEGATIVE_STOCK`, off by default; overridable per company). When it
is off, an issue or a transfer that the shelves cannot fund is refused outright.
When it is on, the unfunded units are carried at no cost, and nothing is posted
for them — there is no layer they came out of and therefore no value to move.

## The entry each movement posts

Every movement that carries value posts one balanced journal of type `stock`:
one line to the inventory account, one to a counter account. Which counter
account is named is the point of the table below.

A movement worth nothing posts nothing. Stock that arrived free has no value to
add; unfunded units under a negative-stock policy have no value to remove. A
journal of two zeroes would say no more than the movement row already does.

| Movement | Debit | Credit |
| --- | --- | --- |
| Receipt | The product's inventory account | Goods received not invoiced (a purchase), or the counter account named by the caller (anything else) |
| Issue | The product's cost of sales account | The product's inventory account |
| Adjustment, shortfall | The counter account named by the caller | The product's inventory account |
| Adjustment, surplus | The product's inventory account | The counter account named by the caller |
| Transfer out / transfer in | — nothing posted — | — nothing posted — |
| Reversal | — the original entry reversed in full — | |

Three of these are worth spelling out.

**The receipt's counter account is the caller's**, because it depends on why the
goods came rather than on what they are: a customer return is met by cost of
sales, an owner's contribution by capital, a write-on by the account the stock
was written off to. The inventory side, by contrast, is always the product's own
account, so the same item always reaches the same place.

A purchase is the exception, and it is not the caller's to name. When a receipt
names a supplier it is a purchase receipt, and it credits the company's own
goods received not invoiced account whatever the caller asked for. The reason is
in Stage 8B below: the bill that follows needs a debt it can clear, and it can
only find one if the receipt always put it in the same place.

**A transfer posts no entry at all.** The inventory account is one account for
the whole company, and goods crossing a yard do not change what that account
holds. The cost travels with the stock: the layers that funded the move are read
once, and the destination opens a layer of its own at the price those layers
charged. The two movements are written inside one transaction, so a half-finished
transfer is never visible.

**An adjustment is the one movement whose direction comes from its own sign.**
A shortfall is stock leaving, so it is priced from the layers oldest first and
written off at exactly what it had cost. A surplus is stock arriving from nowhere,
so the caller has to say what those units are worth, and they open a new layer at
that price.

Because a product names the accounts its own stock posts through, an item carried
as stock cannot be created without an inventory account, and an item held as a
service cannot be moved through the warehouse at all.

## Corrections: immutability and reversal

Posted stock movements are immutable. They are not edited and not deleted, and a
mistake is answered by an opposite movement. This is the same rule the ledger
follows, and for the same reason: a history that can be rewritten is not a
history.

The database enforces it rather than trusting the application.

- A posted movement cannot be updated. The single permitted transition is
  `posted -> reversed`.
- A movement cannot be deleted.
- A layer's receipt side is frozen. Only `quantity_remaining` may move, and it
  only moves downwards, so what a layer cost when it arrived cannot be restated
  after the fact to change what an issue is worth.
- `stock_layer_consumptions` is append-only.

### Reversal

A reversal is a second movement whose quantity carries the other sign and whose
`reverses_movement_id` names the first. A unique index on that column is what
makes "at most once" true even if the same reversal is asked for twice at once,
and the original is locked for the length of the transaction so both requests
find it in the same state.

Which way the reversal goes is decided by the original's own sign, not by a rule
per type. A movement that put stock in is answered by one that takes it out, and
one that took stock out is answered by one that puts it back — which covers the
adjustment, the type that can go either way, without a special case.

The two halves are corrected in the way each allows:

- Stock going back in opens a **new** layer at the price the stock left at. It
  does not go back into the layers that funded the original issue, because
  consumptions are append-only and a layer only ever moves down. The value is the
  same; the layer holding it is new.
- Stock coming back out is drawn from the layers the original opened, so
  reversing a receipt takes back the stock that receipt brought in rather than
  whatever happens to be oldest.
- The entry is reversed **as a whole**, through the same `ReverseJournal` the
  rest of the books use, so the two entries net to nothing account by account.

Three things cannot be reversed:

- **A reversal itself.** Undoing the undo is not a correction; post a new
  movement instead.
- **Half of a transfer.** Only one side is in front of you, and undoing it alone
  would leave the same stock counted in both warehouses.
- **A receipt a bill has already cleared.** The clearing is a fact about what the
  bill settled, and it names the receipt it drew on. Undoing the receipt would
  leave the bill having settled goods that had never arrived, and the allocation
  row cannot simply be dropped. The bill is reversed first, and the receipt only
  after it.

## What is on the shelves, and what it is worth

`GET /api/v1/reports/stock-valuation` reports the stock on hand per item and
warehouse and what the layers say it is worth, alongside the inventory accounts
it is meant to agree with. It returns a `reconciled` flag: the report and the
ledger are compared rather than assumed to match, so a silent divergence fails
loudly instead of being reported as a number.

Balances are never stored in a column. Quantity on hand is a single `SUM` over
the signed quantities in `stock_movements`, and value is read from the layers, so
there is one source of truth for each.

## Stage 8B — connecting sales and purchases

Stage 8B wires this module to Stage 6, so the inventory account moves when goods
do rather than only when a movement is entered by hand. A purchase receives the
stock, a sale issues it, and the two events a purchase used to be one of are told
apart.

The governing rule for that work:

> **A bill for a stock item must not create a second receipt.**

A bill records that the company has been invoiced. The goods were already
received, and the receipt already opened the layer that prices every future
issue. If posting the bill also received the stock, the quantity would be counted
twice and the layer would be duplicated, so the same units would be valued twice
against the inventory account. Posting a bill for a stock line therefore writes
**no** stock movement and opens **no** layer: it clears what the receipt put up.

### Purchases: the three-way match

Three documents say three different things, and the books have to keep them
apart: the goods arrived (the receipt), the supplier says what they cost (the
bill), and what actually left the bank is a third question answered by payments.
Stage 8B builds the first two.

The purchase accounts are company settings — `grni_account_id` and
`purchase_variance_account_id`, on the inventory settings alongside the costing
method. Both are validated on write: each must belong to the company, must be
active, and the GRNI account must be a liability, because it stands for goods
received and not yet invoiced. Changing either writes an `audit_logs` entry. A
receipt or a bill with either unset is refused with the account named, rather
than posting into an account the books happened to pick.

**The receipt.** A receipt that names a supplier is a purchase receipt. It posts
`Dr Inventory / Cr Goods received not invoiced`, and the caller's counter account
is ignored — see the receipt table above. The supplier is recorded on the
movement, and on nothing else: an issue, an adjustment and a transfer leave
`stock_movements.supplier_id` null, and `(company_id, supplier_id, product_id)`
is indexed for what reads it.

**The bill.** A bill line that names a stock item posts `Dr Goods received not
invoiced / Cr Payables`, and the difference between what the receipt was booked
at and what the bill says goes to purchase price variance. A dearer bill debits
variance; a cheaper one credits it. The line's debit side is the cleared value
adjusted by the variance, so the entry balances to the line's own net.

**The cap.** A bill never clears more than was received and not already billed.
What a receipt still has open is its quantity minus the quantity already
allocated, so partial receipts and partial bills are simply additions: two bills
can settle one receipt, and one bill can draw on several. A bill for stock that
was never received, and a bill asking for more than is open, are both refused.

**The order.** A bill clears the **oldest open receipts first**, for the same
supplier and the same product. Oldest first for the same reason an issue draws on
the oldest layers: the stock that has been sitting longest is the stock being
paid for. Same supplier and same product because a receipt records who the goods
came from, and one supplier's delivery cannot settle another supplier's invoice.

Each draw is written to `grni_allocations` as a row rather than recomputed: how
much of that receipt the line took, the price the receipt was booked at, and what
that came to. It is a fact about what the bill did, and replaying it later
against receipts that have since moved on would give a different answer.

**Corrections.** Posted receipts are immutable as every other movement is. The
bill is the record that a clearing happened, so reversing the receipt would leave
that clearing naming stock that had never arrived; the guard described under
reversal above refuses until the bill is reversed first. A reversed receipt is
not offered to a later bill.

Purchase orders are a later stage. They are a fourth document — what the company
said it would buy — and they commit nothing to the books, so nothing here
depends on them.

### Sales: the company default warehouse

An invoice for a stock item issues the goods as the sale is recognised. The
goods go out of the company's **default warehouse**, and the issue is priced from
the layers there, so the cost of sales posted is what those units cost rather
than what they sold for.

If any line names a stock item and the company has no default warehouse, the
whole invoice is refused with that as the error — not silently issued from
somewhere else, and not posted with the goods still on the shelf. The default is
resolved only once a goods line is found, so an invoice made only of services is
never refused for naming no warehouse.

The issue sits inside the same transaction as the journal, and the journal is
posted before the goods move: a sale that cannot be funded — no default
warehouse, or no stock when the company forbids negatives — is refused as a
whole rather than half-posted, leaving the invoice draft with nothing in the
ledger and nothing off the shelf.
