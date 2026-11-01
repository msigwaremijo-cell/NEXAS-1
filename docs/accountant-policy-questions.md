# Accountant policy questions (Stage 12D)

The questions the company's accountant must answer before the figures in Nexas
are relied on. The system enforces double entry, immutability and the arithmetic
of every posting, but it does not decide the policies underneath them. Each
question below says why it matters, where the answer is kept, and whether it has
been answered.

**As the document stands today, none of these has been answered.** They are
questions, not settings, and no claim of compliance — Tanzanian or otherwise —
is made anywhere in this document.

## The role notes

| Who | Right | What they decide |
| --- | --- | --- |
| Owner | `company.manage` | The policies that change what every later number means |
| Accountant | `company.manage` | The same, as the person who keeps the books |

Both hold `company.manage`, which today covers the inventory policy (the method
and whether stock may go negative). Everything else below is recorded on the
records themselves — an account, a category, a customer — and is decided by
whoever holds the right to keep that record.

## The questions

### 1. Which costing method is the stock valued by?

- **Why it matters.** FIFO is the one method built. Once any stock movement
  exists the method may not be changed, because the layers already written were
  priced under the method in force at the time. Two movements valued by
  different rules would make the inventory account stop meaning anything.
- **Where it lives.** The application default is `NEXAS_INVENTORY_COSTING` in
  `config/nexas.php` (`fifo`); a company may override it in its own settings.
  The change is refused once movements exist.
- **Answered?** No. Confirm FIFO is the method the company should use before
  posting any stock movement.

### 2. May the quantity on hand go below zero?

- **Why it matters.** Allowing a negative quantity permits an issue before the
  receipt behind it is entered; forbidding it refuses the issue. It is one
  choice for the tenant, not a per-movement decision.
- **Where it lives.** The company's inventory settings.
- **Answered?** No.

### 3. What depreciation method and what useful lives?

- **Why it matters.** The system depreciates **straight-line, month by month**,
  from the in-service date, cost less salvage over the life in months. This is
  the accounting method only: the Income Tax Act sets its own rates and its own
  method, which may differ in both. The method's own note, returned beside the
  schedule, says so.
- **Where it lives.** The method is fixed (`straight_line`); the **lives** are
  inputs — a category's default and each asset's own life in months.
- **Answered?** No. The useful lives used here need confirmation from a
  qualified accountant before the figures are relied on.

### 4. Which account is retained earnings?

- **Why it matters.** The year-end close clears revenue and expense into
  retained earnings, which it finds by **code `3200`** in the seeded chart. If
  that account is missing, or is not a posting account, the close is refused.
- **Where it lives.** The chart of accounts, at code `3200`.
- **Answered?** No. Confirm that `3200` is the company's retained-earnings
  account, or change the chart accordingly, before relying on a close.

### 5. Is the seeded chart of accounts the company's chart?

- **Why it matters.** Every report and every close reads the chart: account
  types decide what is a temporary account and what is not, and each account's
  **cash flow category** decides which activity it appears under.
- **Where it lives.** The chart of accounts; the cash flow category is set with
  `account.manage`.
- **Answered?** No. The chart must be read and agreed by the company's
  accountant, not accepted by default.

### 6. What is the cut-over date, and what are the opening balances?

- **Why it matters.** Opening balances post exactly one opening journal. The
  import refuses if its debits do not equal its credits, and refuses if the
  debits to inventory accounts do not equal the total value of the stock layers
  that opening stock created. The date is the day the books begin.
- **Where it lives.** Taken with the opening stock and opening balances imports,
  as their `as_of` date.
- **Answered?** No. The cut-over date and every opening figure are the
  accountant's to set and to sign off.

### 7. Which accounts do customers and suppliers post to?

- **Why it matters.** Each customer names a receivable account and each supplier
  a payable account. The wrong account puts the balance in the wrong place in
  the reports, and a control account with the wrong balance is worse than none.
- **Where it lives.** On each customer and supplier record.
- **Answered?** No.

### 8. What are the fiscal year's dates, and how is it divided into periods?

- **Why it matters.** A posting must fall in an open period of an open year, and
  the year-end close moves the year's result to retained earnings. If the
  calendar is wrong, so is every close.
- **Where it lives.** The company's fiscal years and periods.
- **Answered?** No. No statutory period is modelled; the calendar is the
  company's own.

### 9. Who approves a supplier bill, and at what point?

- **Why it matters.** A bill passes draft → pending approval → approved →
  posted, and the member who enters a bill cannot approve it. That is what makes
  approval a control rather than a second signature. Whether an amount threshold
  should also apply is a company rule.
- **Where it lives.** The role held by each member. An amount threshold is **not
  built** — the approval gate is per bill, not per value.
- **Answered?** No.

### 10. What is the money rounding, and is it the company's convention?

- **Why it matters.** Money is held at four decimals and quantities at six
  (`money_scale` and `quantity_scale` in `config/nexas.php`). A company whose
  convention differs will see figures that do not match its expectation on the
  last decimal place.
- **Where it lives.** `config/nexas.php`.
- **Answered?** No.

## What is not verified here

- **None of these questions has been answered, and none of the answers has been
  checked against the company's practice.** This document collects what must be
  decided; it decides nothing.
- **No answer here is a claim about Tanzanian tax, statutory reporting or
  compliance.** Where a question touches tax — depreciation in particular — the
  system explicitly says it is the accounting method and **not** a tax
  computation, and that the figures need a qualified accountant's confirmation.
- **The system does not verify that a policy is right, only that it is applied
  consistently.** Confirming FIFO is correct, that a useful life reflects the
  asset's real life, or that the chart suits the company is a professional
  judgement outside this software.
- **Some policies are not built at all**, and are named above as such — for
  example an approval amount threshold, and any statutory format. Their absence
  is stated rather than hidden.
