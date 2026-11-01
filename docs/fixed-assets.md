# Fixed assets (operator guide, Stage 12B)

This is the guide for the fixed-asset register: entering what the company
holds, depreciating it a period at a time, and taking it out of the books when
it goes. It makes no tax or compliance claim of any kind — see
[What is not verified here](#what-is-not-verified-here). The method's own note,
returned beside the schedule, says the same thing.

## The register

The register is everything the company holds and what each item is worth in the
books. Read it with:

```
GET /api/v1/assets/register
```

It reports, in total and split by category:

| Figure | Meaning |
| --- | --- |
| `cost` | What the assets cost, together |
| `accumulated_depreciation` | What has been charged against them so far |
| `net_book_value` | Cost less accumulated depreciation |
| `by_category` | The same figures, one block per category, with an `asset_count` |

Neither categories nor assets are ever deleted. An asset leaves the register by
being **disposed of**, and a disposal is corrected by being **reversed**.

## Categories

A category is the four accounts a class of asset posts to, plus a default life.
Create one with:

```
POST /api/v1/asset-categories
```

| Field | Required | Meaning |
| --- | --- | --- |
| `code` | yes | A short code, unique within the company |
| `name` | yes | What the category is called |
| `asset_account_id` | yes | The asset account a cost is debited to |
| `accumulated_depreciation_account_id` | yes | The contra-account depreciation is credited to |
| `depreciation_expense_account_id` | yes | The expense account a period's charge is debited to |
| `disposal_account_id` | yes | The account a gain or loss on disposal is booked to |
| `default_useful_life_months` | yes | The life new assets of this class default to |
| `description` | no | Free text |

The four accounts are the whole point of a category: they are what lets a
month's charge be written without asking an operator which account each asset
posts to. Read, list and amend categories with `GET /api/v1/asset-categories`,
`GET /api/v1/asset-categories/{assetCategory}` and
`PATCH /api/v1/asset-categories/{assetCategory}`.

## Entering an asset

```
POST /api/v1/assets
```

| Field | Required | Meaning |
| --- | --- | --- |
| `asset_category_id` | yes | The category it belongs to |
| `code` | yes | Its own code, unique within the company |
| `name` | yes | What it is |
| `acquisition_cost` | yes | What it cost |
| `salvage_value` | no | What it is expected to be worth at the end of its life |
| `useful_life_months` | no | Its life in months; defaults from the category |
| `in_service_date` | no | The day it was placed in service |
| `acquisition_account_id` | no | The account that met the cost, where the cost was not met by a bill |

An asset's cost is **posted to the ledger when it is placed in service** — that
is, when it has an `in_service_date`. The entry debits the category's asset
account and credits whatever met the cost. Amend an asset with
`PATCH /api/v1/assets/{asset}`; list and read with `GET /api/v1/assets` and
`GET /api/v1/assets/{asset}`.

## Charging a period's depreciation

```
POST /api/v1/depreciation-runs
```

Body:

| Field | Required | Meaning |
| --- | --- | --- |
| `fiscal_period` | yes | The fiscal period's ULID (26 characters) |
| `posting_date` | no | Defaults to the period |
| `memo` | no | Free text |

A run is named by its **fiscal period**, not by a list of assets, because a
period's charge is a company-wide act over everything in service. The run:

- charges every asset in service across the period, one line per asset, so a
  reader can see which assets the charge was made of;
- writes **one** journal of type `depreciation` for the period, and refuses a
  second run for the same period rather than doubling the charge;
- refuses a period or year the calendar has already closed;
- is write-once. A run that has to be corrected is undone by:

```
POST /api/v1/depreciation-runs/reversal
```

The reversal writes a mirror-image entry with type `reversal`. It is the same
shape of correction every posted entry in the system uses: nothing posted is
edited, it is answered by its opposite.

### The method

Depreciation is **straight-line, month by month from the in-service date**: cost
less salvage, spread evenly over the life in months, one charge for each whole
month of the life the asset was in service at the end of. An asset placed in
service on the 15th is charged a full month for that month. Two rules keep the
arithmetic exact:

- an asset is never depreciated below its salvage value;
- the period in which the life runs out takes what is left rather than a whole
  monthly amount, so the total charged over the life equals cost less salvage
  exactly.

**This is the accounting method and nothing else. It is not a statement about
Tanzanian tax depreciation.** The Income Tax Act sets its own rates and method,
which may differ in both. The useful life used here needs confirmation from a
qualified accountant before the figures are relied on. The same sentence is
returned beside the schedule so a reader of the API is told rather than left to
assume.

## Disposing of an asset

```
POST /api/v1/assets/{asset}/disposal
```

| Field | Required | Meaning |
| --- | --- | --- |
| `disposal_date` | yes | The day it left the books |
| `proceeds` | no | Money received, as a decimal string |
| `proceeds_account_id` | no | The account the money was received into |

A disposal brings depreciation current for the period it falls in, then takes
the asset's cost and accumulated depreciation out of the books and books any
gain or loss to the category's disposal account. A **correction** is the
reversal:

```
POST /api/v1/assets/{asset}/disposal/reversal
```

Both a depreciation run and a disposal accept an `Idempotency-Key` header, so a
retried request does not charge or dispose twice.

## Role notes

| Right | Roles | What it allows |
| --- | --- | --- |
| `asset.view` | every role | Read the register and the categories |
| `asset.manage` | Owner, Accountant, Finance Officer | Keep the register: create and amend categories and assets |
| `asset.post` | Owner, Accountant, Finance Officer | Charge depreciation, dispose of an asset, and reverse either |

The three rights are deliberately not one. Entering an asset is deciding what
the company holds, and moves the ledger only as the consequence of the entry;
depreciating and disposing of one move the ledger on their own. Cashier, Auditor
and Read-only hold `asset.view` alone — they may read the register and may not
touch it, and an audit that posts its own depreciation is not an audit.

## What is not verified here

- **No fixed-asset register has been run against real assets and real
  postings.** The behaviour above is proven by the automated test suite against
  invented fixtures, not by an operator depreciating a real year and reading the
  report afterwards.
- **The useful lives are not verified against anything.** Nothing in the system
  says a given class of asset should be given the life that was entered. The
  life is an input, and this guide makes no claim that any particular life is
  right.
- **Straight-line is not asserted to be the correct method, for tax or for
  accounting.** It is the one method built. Whether it is the method the company
  should use, and at what rate, is a decision for a qualified accountant.
- **No Tanzanian tax, capital-allowance or compliance claim is made anywhere in
  this document.** Nothing here has been checked against the Income Tax Act or
  any filing requirement.
