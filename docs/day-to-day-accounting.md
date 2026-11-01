# Day-to-day accounting (operator guide, Stage 12B)

This is the guide for the ordinary working day: raising documents, taking and
making payments, moving stock, and correcting what was entered wrongly. It
makes no tax or compliance claim of any kind — see
[What is not verified here](#what-is-not-verified-here).

## The one rule that governs everything

**Nothing posted is ever edited or deleted.** A posted journal, a posted bill, a
dispatched stock movement and a depreciated asset are all frozen at the
database level. A mistake is corrected by posting the opposite entry, not by
quietly rewriting the first one. Every correction therefore leaves a visible
pair — the entry and its reversal — and the audit chain records both. The
purpose-built reversals are named beside each task below.

## Sales

An invoice moves through a fixed set of statuses:

```
draft → issued → partially_paid → paid
                 ↘ void
```

| Step | Call |
| --- | --- |
| Create as a draft | `POST /api/v1/invoices` |
| Read one | `GET /api/v1/invoices/{invoice}` |
| List | `GET /api/v1/invoices` |
| Issue it (this is what posts) | `POST /api/v1/invoices/{invoice}/issue` |
| Answer an issued invoice | `POST /api/v1/invoices/{invoice}/credit-notes` |

A credit note names **one line** of the invoice it answers and a quantity, so
the reversal is tied to the sale it corrects rather than floating free. Issuing
an invoice and issuing a credit note both post; creating a draft does not.

## Purchases

A bill has an approval gate, which is what stops the person who entered a bill
from also being the person who signs it off:

```
draft → pending_approval → approved → partially_paid → paid
                                       ↘ reversed
```

| Step | Call |
| --- | --- |
| Enter as a draft | `POST /api/v1/bills` |
| Submit for approval | `POST /api/v1/bills/{bill}/submit` |
| Approve | `POST /api/v1/bills/{bill}/approve` |
| Post it | `POST /api/v1/bills/{bill}/post` |
| Read one / list | `GET /api/v1/bills/{bill}`, `GET /api/v1/bills` |
| Answer a posted bill | `POST /api/v1/bills/{bill}/debit-notes` |

A debit note names one line of the bill it answers, exactly as a credit note
does for an invoice. A bill is posted once approved; the post is what reaches
the ledger.

## Payments

```
POST /api/v1/payments      record a payment
GET  /api/v1/payments/{payment}
GET  /api/v1/payments
```

A payment settles part or all of an invoice or a bill. It is its own write
rather than part of issuing the document, because taking money over a counter
and raising the invoice are different jobs done by different people.

## Stock

A stock movement changes what is on the shelf and posts the ledger entry that
carries its value, in one act.

| Task | Call |
| --- | --- |
| Receive into a warehouse | `POST /api/v1/stock/receipts` |
| Issue out of a warehouse | `POST /api/v1/stock/issues` |
| Adjust | `POST /api/v1/stock/adjustments` |
| Transfer between two of the company's warehouses | `POST /api/v1/stock/transfers` |
| Correct a movement | `POST /api/v1/stock/movements/{movement}/reversal` |
| Read movements | `GET /api/v1/stock/movements` |

A transfer between two of the company's own warehouses changes the total by
nothing and posts no journal at all. Whether the quantity may go below zero is a
company policy, not a per-movement decision. See [inventory.md](inventory.md)
for how stock is costed (FIFO) and how the method is locked once movements
exist.

## Journals

For an entry that is not raised by one of the documents above:

```
POST /api/v1/journals                    write a draft
POST /api/v1/journals/{journal}/post     post it
POST /api/v1/journals/{journal}/reverse  post the opposite entry
```

A draft may be unbalanced, because balance is a **posting** rule, not a drafting
one. Posting is what enforces double entry: the entry must balance, must have at
least two lines, must not post to a header, and must fall in an open fiscal
period of an open year. A posting the system has seen before under the same
idempotency key is not posted twice.

## The bank

```
GET /api/v1/bank-accounts
POST /api/v1/bank-accounts/{bankAccount}/statement-imports
```

A statement import is matched against the cashbook and signed off. It is a
three-step process because the middle step — matching a bank line to a ledger
line — cannot be guessed from the file alone.

## Role notes

| Task | Right | Owner | Accountant | Finance Officer | Cashier | Inventory Mgr | Auditor | Read-only |
| --- | --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| Read the books | `journal.view` / `report.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Write a journal draft | `journal.create` | ✓ | ✓ | ✓ | ✓ | | | |
| Post a journal | `journal.post` | ✓ | ✓ | ✓ | | | | |
| Reverse a journal | `journal.reverse` | ✓ | ✓ | ✓ | | | | |
| Create / issue an invoice | `invoice.manage` / `invoice.issue` | ✓ | ✓ | ✓ | | | | |
| Enter a bill | `bill.manage` | ✓ | ✓ | | | | | |
| Approve / post a bill | `bill.approve` / `bill.post` | ✓ | *(post only)* | ✓ | | | | |
| Record a payment | `payment.record` | ✓ | ✓ | ✓ | ✓ | | | |
| Move stock | `stock.post` | ✓ | ✓ | ✓ | | ✓ | | |
| Reconcile the bank | `bank.reconcile` | ✓ | ✓ | ✓ | | | | |
| Read the audit trail | `audit.view` | ✓ | | | | | ✓ | |
| Manage members | `user.manage` | ✓ | | | | | | |

Notes on the shape of this table:

- **Owning a document and clearing it are separated on purpose.** An Accountant
  may enter a supplier bill but **not** approve it; a Finance Officer may
  approve and post one but **not** enter it. That is what makes approval a
  control rather than a second signature by the same hand.
- **The Cashier takes money but does not raise the invoice.** They hold
  `journal.create` and `payment.record`, and neither `journal.post` nor
  `invoice.manage`.
- **The Inventory Manager keeps the stock books and nothing else.** They may
  receive, issue, adjust, transfer and correct stock and read who it came from
  and went to; they hold no journal, payment or member rights.
- **The Auditor and the Read-only role write nothing.** The Auditor additionally
  reads the audit trail; the Read-only role may see the books but not the trail
  behind them.

## What is not verified here

- **No company has run a full day's work on this system yet.** The statuses and
  gates above are proven by the automated test suite against invented fixtures,
  not by an operator posting a real month and reconciling it afterwards.
- **The right-hand columns are read from the access matrix in the source.** They
  are accurate as the matrix stands today and are not a statement about how a
  particular company should divide its staff.
- **Nothing here is advice on how to keep books.** When to issue a credit note,
  when to approve a bill, and what a payment should settle are accounting
  judgements this guide does not make.
- **No Tanzanian compliance claim is made.** Nothing here has been checked
  against a tax rule, a filing requirement or any statutory format.
