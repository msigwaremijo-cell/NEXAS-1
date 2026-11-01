# Closing and reopening a period (operator guide, Stage 12B)

This is the guide for ending a fiscal year and, when it is truly necessary,
putting one period of it back into the books. Both acts are the Owner's. It
makes no tax or compliance claim of any kind — see
[What is not verified here](#what-is-not-verified-here).

## The fiscal calendar

A company's books are divided into a fiscal year and, inside it, a set of
periods. Each period is one of three states:

| State | Accepts postings | Can be reopened |
| --- | --- | --- |
| `open` | yes | — (already open) |
| `closed` | no | yes |
| `locked` | no | no |

Read the calendar with:

```
GET /api/v1/fiscal-periods
```

The response lists the periods and marks which ones may be reopened, so the
answer to "can this be undone?" is read from the system rather than remembered.
Reading the calendar is `report.view`, which **every** role holds.

## Closing the year

```
POST /api/v1/fiscal-years/{fiscalYear}/close
```

Closing a year does two things as one act, and they are deliberately not
separable:

1. It writes and posts a **closing journal**, dated the year's last day, that
   clears every revenue and expense account still carrying a balance and takes
   the year's result to **retained earnings** (account code `3200` in the
   seeded chart). A profit is credited to retained earnings; a loss is debited.
2. It marks **every period of that year closed**, and then the year itself
   closed, so nothing further can be posted into it.

The two halves are one act because a closing entry on its own could be posted
again by hand, and a lock on its own would leave the temporary accounts carrying
a result that has been reported but never moved.

Notes an operator should hold onto:

- Only accounts **still in the chart** are closed. An account that was taken
  out of the chart with a balance still on it keeps that balance.
- If the retained-earnings account `3200` is missing or is not a posting
  account, the close is refused and says so rather than half-succeeding.
- The closing entry is written from each account's **whole balance as at the
  year's last day**, not from the year's own movement. The two agree whenever
  every earlier year was closed — the normal case.
- The close is recorded in the audit chain as `fiscal_year.closed`, naming how
  many periods were closed and the closing journal's number.

### Role notes — close

| Right | Roles |
| --- | --- |
| `fiscal_year.close` | Owner only |

Closing the year is `fiscal_year.close`, held by the Owner alone. An Accountant
may post any number of ordinary journals without being the person who declares
the year finished. This right is not inherited from `journal.post`.

## Reopening a period

```
POST /api/v1/fiscal-periods/{fiscalPeriod}/reopen
```

Body:

| Field | Required | Meaning |
| --- | --- | --- |
| `reason` | yes | Why the period is being reopened. A string of 10 to 500 characters. |

A reopen is deliberately narrow:

- **Only the period named is reopened.** Every other period of the year stays
  closed, so reopening one month does not put the rest of the year back into
  play.
- **The year above it is reopened with it**, because a posting needs both to be
  open. Nothing else about the year is touched — no balances move and no journal
  is written by the reopen itself.
- **The reason is mandatory, not decoration.** A reopen puts a period that has
  already been reported back into the books, so the audit chain records who did
  it, when, and why. The reason has no column of its own; it travels in the same
  audit entry as the state change, so the two cannot drift apart.
- **A locked year cannot be reopened.** The attempt is refused.

Every reopen is recorded as `fiscal_period.reopened`, and when the year is put
back to open as well it is also recorded as `fiscal_year.reopened`, naming the
period it was reopened for. Both entries carry the reason.

After the correction is posted into the reopened period, close the year again
with the ordinary close. That re-derives the closing entry from the accounts'
balances as they now stand, so the correcting journal's effect is carried into
retained earnings rather than left on the temporary accounts.

### Role notes — reopen

| Right | Roles |
| --- | --- |
| `fiscal_period.reopen` | Owner only |
| `report.view` (read the calendar) | every role |

Reopening is `fiscal_period.reopen`, held by the Owner alone. It is a separate
key from `fiscal_year.close` on purpose, even though the same person holds both
today, so that granting the close never grants the reopen by inheritance.

## What is not verified here

- **No company has been closed and reopened on real books.** The behaviour above
  is proven by the automated test suite against invented fixtures, not by an
  operator doing it to a real year and checking the reports afterwards.
- **Whether reopening is the right thing to do is an accounting judgement this
  guide does not make.** It describes the mechanism; it does not say when a
  reported period may properly be reopened, which is a decision for the
  company's accountant.
- **No statutory period is modelled.** The fiscal year and its periods are the
  company's own calendar. Nothing here asserts that the periods line up with any
  country's tax year, filing deadline or reporting requirement, and no claim at
  all is made about Tanzanian compliance.
- **The closing journal's accounts are not verified against any statutory
  chart.** Retained earnings is found at code `3200` in the seeded chart. A
  company using a different chart must ensure that code is its retained
  earnings account; that is not checked for you.
