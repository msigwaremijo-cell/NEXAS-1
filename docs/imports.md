# Imports (operator guide, Stage 12B)

This is the guide for bringing a company's existing books onto Nexas as a
comma-separated file. It describes what the tooling of Stage 12A does and how an
operator drives it. It says nothing about tax and makes no compliance claim of
any kind — see [What is not verified here](#what-is-not-verified-here).

## What an import is

A dataset is one list of opening data. There are six, and they are a sequence
rather than six independent screens, because the later ones name the earlier
ones: opening stock names a product SKU and a warehouse code, and opening
balances name an account code. Import them in this order.

| Order | Dataset | Endpoint name | What it brings in |
| --- | --- | --- | --- |
| 1 | Chart of accounts | `chart_of_accounts` | The accounts every later posting names |
| 2 | Customers | `customers` | The parties the company sells to |
| 3 | Suppliers | `suppliers` | The parties the company buys from |
| 4 | Products | `products` | The item list, with its stock and expense accounts |
| 5 | Opening stock | `opening_stock` | What is on the shelves and what it cost |
| 6 | Opening balances | `opening_balances` | The balance every account starts from |

Later datasets refuse a row that names a code the earlier ones have not yet
created. Import the chart of accounts before anything that posts, and the
products and warehouses before opening stock.

## The two steps

An import is two calls, always in this order.

1. **Upload** the file. The system reads it and hands back what it really
   contains — the column names, how many rows, a short preview — so a mapping
   can be built against the columns the file carries rather than the columns
   somebody guessed it carries.
2. **Run** the import under a mapping that says which column is which field.

Nothing is written on the upload. The run is where data is created, and it is
run twice: once as a **dry run** to see every complaint, then once for real.

### 1. Upload

```
POST /api/v1/imports/{dataset}/uploads
```

Send the file as a multipart field named `file`. Only `.csv` is accepted, and
the file is sniffed by its bytes rather than trusted from its extension. The
largest file is `nexas.imports.max_kilobytes` (10 MB by default).

The response (HTTP 201) carries:

| Field | Meaning |
| --- | --- |
| `upload_token` | The handle to run against. A ULID, 26 characters. |
| `file_name` | The name the file arrived with, to carry back on the run |
| `file_hash` | SHA-256 of the file's bytes |
| `delimiter` | The delimiter that was detected |
| `headers` | The column names found in the first row |
| `row_count` | How many data rows were read |
| `preview` | The first few rows, so the mapping can be checked against real data |

The file is stored privately, under `storage/app/private/imports`, in a folder
per company. It is not served by the web server.

### 2. Dry run, then run

```
POST /api/v1/imports/{dataset}/runs
```

Body:

| Field | Required | Meaning |
| --- | --- | --- |
| `upload_token` | yes | The token from the upload |
| `mapping` | yes | An object of `field => column name`, one entry per field of the dataset |
| `dry_run` | no | `true` reports and writes nothing; `false` (or absent) writes |
| `as_of` | for opening stock and opening balances | The date the data is read against, `YYYY-MM-DD` |
| `file_name` | no | The file's own name, carried back so a refusal can name it |

A field mapped to an empty string means "no column", the same as leaving the
field out. A `dry_run` answers HTTP 200 and a commit answers HTTP 201, both with
the same report, so the report you approved on the dry run is the one the commit
obeys.

## Columns per dataset

A `*` marks a required field. Column names are yours; the mapping is what
connects them.

| Dataset | Fields |
| --- | --- |
| Chart of accounts | `code`*, `name`*, `type`, `normal_balance`, `parent_code`, `postable`, `is_cash`, `is_bank`, `cash_flow_category`, `description` |
| Customers | `code`*, `name`*, `receivable_account_code`* |
| Suppliers | `code`*, `name`*, `payable_account_code`* |
| Products | `sku`*, `name`*; a goods item also needs `inventory_account_code` and `expense_account_code` |
| Opening stock | `product_sku`*, `warehouse_code`*, `quantity`*, `unit_cost`* |
| Opening balances | `account_code`*, and a debit or credit amount per row |

A chart-of-accounts row's `parent_code` must appear **earlier in the same file**
than the row that names it, because the parent has to exist before the child is
linked to it. A child with no `type` of its own inherits the parent's.

## Role notes

Every right a dataset needs is the right the same job would need done by hand —
there is no separate set of "import rights". The caller must hold **all** of a
dataset's rights; holding half of them is not holding them.

| Dataset | Rights needed | Roles that hold them |
| --- | --- | --- |
| Chart of accounts | `account.manage` | Owner, Accountant, Finance Officer |
| Customers, Suppliers | `party.manage` | Owner, Accountant, Finance Officer |
| Products | `stock.manage` | Owner, Accountant, Finance Officer, Inventory Manager |
| Opening stock | `stock.manage` + `stock.post` | Owner, Accountant, Finance Officer, Inventory Manager |
| Opening balances | `journal.post` | Owner, Accountant, Finance Officer |

Cashier, Auditor and Read-only hold none of these and cannot import. The
Read-only role is a reader by design, and an Auditor who may write to the books
is not an auditor.

## The guards

- **A file that has already been imported is refused.** The system keeps the
  SHA-256 of every imported file per company and per dataset. Presenting a
  byte-identical file again is answered with HTTP 409 naming the file, whether
  or not the first run committed. This is checked twice — before the plan is
  built, and again inside the writing transaction — so two runs racing each
  other cannot both succeed.
- **A plan with any error is refused whole.** Nothing is written, no
  `import_runs` row is created and no journal is posted if any row in the file
  fails to check. An import is all or nothing.
- **Opening stock and opening balances must agree.** Opening balances post
  exactly one opening journal. It is refused if its debits do not equal its
  credits, and it is refused if the debits to inventory accounts do not equal
  the total value of the stock layers that opening stock created. A mismatch is
  answered with HTTP 422 and no journal is posted.
- **A whole run is audited.** The commit writes its rows and its `import_runs`
  row and its audit entry in one transaction, so the record of an import cannot
  exist without the import, or the reverse.

## What is not verified here

Everything below is marked plainly because none of it has been proven, and the
guide is only safe to follow if the unproven parts are visible.

- **No import has been run against real customer data.** Every test of this
  tooling uses invented fixtures. Nothing here has carried a real company's
  file end to end.
- **The dataset field lists were read from the importers' source, not proven
  against a real exported file from another system.** A file exported from
  another accounting package has not been through this tool.
- **`as_of` is only checked as a date.** Whether it falls inside an open fiscal
  period, and whether it is the right date for the company's migration, is not
  verified here. Choosing a date in a closed period will be refused by the
  ordinary posting rules rather than by the import.
- **Opening balance conventions are not checked against any statutory chart.**
  No claim is made about any country's accounts structure, and no claim at all
  is made about Tanzanian compliance, tax, statutory reporting or filing. What
  accounts exist, and what they are called, is the company's and its
  accountant's decision.
- **The order in the table above is the order the datasets depend on; it is not
  a claim that a particular migration must start with any one dataset.** Whether
  a company needs to import all six, or only some, is an accounting decision
  this guide does not make.
