# Inventory (Stage 8)

Stock is kept as two things at once: what is on the shelf, and what it is worth.
Stage 8 builds both halves in one place — a movement changes the quantity, and
the same movement posts the ledger entry that carries its value.

Stage 8 is standalone. Invoices and bills post exactly as they did in Stage 6;
nothing here reads or writes them.

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
new method. That write path is the remaining wiring for this rule.

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
| Receipt | The product's inventory account | The counter account named by the caller |
| Issue | The product's cost of sales account | The product's inventory account |
| Adjustment, shortfall | The counter account named by the caller | The product's inventory account |
| Adjustment, surplus | The product's inventory account | The counter account named by the caller |
| Transfer out / transfer in | — nothing posted — | — nothing posted — |
| Reversal | — the original entry reversed in full — | |

Two of these are worth spelling out.

**The receipt's counter account is the caller's**, because it depends on why the
goods came rather than on what they are: a purchase is met by what the supplier
is owed, a customer return by cost of sales, an owner's contribution by capital.
The inventory side, by contrast, is always the product's own account, so the same
item always reaches the same place.

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

Two things cannot be reversed:

- **A reversal itself.** Undoing the undo is not a correction; post a new
  movement instead.
- **Half of a transfer.** Only one side is in front of you, and undoing it alone
  would leave the same stock counted in both warehouses.

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

Stage 8B wires this module to Stage 6. A sale will issue stock and a purchase
will receive it, so that the inventory account moves when goods do rather than
only when a stock movement is entered by hand.

The governing rule for that work:

> **A bill for a stock item must not create a second receipt.**

A bill records that the company has been invoiced. The goods were already
received, and the receipt already opened the layer that prices every future
issue. If posting the bill also received the stock, the quantity would be counted
twice and the layer would be duplicated, so the same units would be valued twice
against the inventory account. Stage 6 must therefore be changed to tell the two
events apart: the receipt moves the stock, and the bill settles what is owed for
it.

Until then, invoices and bills post as they do today, and stock moves only
through the routes above.
