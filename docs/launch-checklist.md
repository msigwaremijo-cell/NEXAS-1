# Launch checklist (Stage 12C)

A checklist for taking a company live on Nexas. Each item says who does it, what
"done" means, and — where it matters — that it has **not** been verified yet.
Nothing here is a compliance claim: see
[What is not verified here](#what-is-not-verified-here).

Work top to bottom. The steps below assume the guides that cover each part in
full: [deployment.md](deployment.md) for the machine,
[backups.md](backups.md) for the backup and its key, and
[imports.md](imports.md) for bringing the opening data in.

## 1. The machine

Covered in full by [deployment.md](deployment.md).

- [ ] Ubuntu 24.04 LTS host with Nginx, PHP 8.4-FPM, MySQL 8.4, Redis and
      Supervisor installed
- [ ] The four files from `deploy/` placed and enabled — Nginx, the PHP-FPM
      pool, `99-nexas.ini` (both the FPM **and** the CLI copy), and Supervisor
- [ ] `shared/.env` created from `.env.staging.example`, with the hostname and
      database password set
- [ ] TLS issued with certbot, and the hostname replaced in
      `deploy/nginx/nexas.conf`
- [ ] `curl -sf https://<host>/up -o /dev/null && echo up` prints `up`
- [ ] `php /var/www/nexas/current/artisan schedule:list` shows the nightly jobs
      once — and only once (one scheduler, never two)
- [ ] `supervisorctl status` shows the queue workers and the scheduler running

**Owner / operator.** "Done" is the four checks in the last three lines
answering as expected. **None of this has been run on a real machine yet** —
see [What is not verified here](#what-is-not-verified-here).

## 2. The keys

Covered in full by [backups.md](backups.md).

- [ ] `php artisan key:generate` run, so `APP_KEY` exists
- [ ] `NEXAS_BACKUP_KEY` generated and set on the server — the server holds it
      because the server is what encrypts each night's backup
- [ ] The owner keeps a **second copy off the server**, and has filled in the
      table in [backups.md](backups.md#where-the-second-copy-lives) recording who
      holds it, where, and when

**Owner.** The second copy is a manual step that cannot be automated from this
repository. Until the table is filled in, it is **not verified**.

## 3. The backup, proven

- [ ] `php artisan backups:run` completes and writes a file and a row
- [ ] `php artisan backups:restore` run with the **off-server copy** of the key,
      which must open the newest backup and agree with the books

**Owner.** Running the drill with the copy rather than the server's key is the
only thing that proves the copy is the right one.

## 4. The books

Covered in full by [imports.md](imports.md).

- [ ] Chart of accounts imported, and the company's accountant has confirmed it
      is the chart the company keeps its books on
- [ ] Customers and suppliers imported
- [ ] Products imported
- [ ] Opening stock imported against the cut-over date
- [ ] Opening balances imported against the same date, and the import posted its
      single opening journal — the import refuses if the debits do not equal the
      credits, or if the inventory-account debits do not equal the value of the
      stock layers that opening stock created
- [ ] The trial balance agrees, and the accountant has read it

**Owner, Accountant, or Finance Officer** — whichever holds the rights the
dataset needs. Each import must be dry-run first, and the report read, before it
is committed for real.

## 5. The people

- [ ] Each member invited and given a role: Owner, Accountant, Finance Officer,
      Cashier, Inventory Manager, Auditor or Read-only
- [ ] The Owner has checked the role split against the company's own rules —
      in particular that the member who enters a supplier bill is not the member
      who approves it
- [ ] A Read-only or Auditor account exists for anyone who must look but must
      not write

**Owner.** See the role notes in
[day-to-day-accounting.md](day-to-day-accounting.md#role-notes) and in each
guide.

## 6. The policies

- [ ] The company's accountant has answered the questions in
      [accountant-policy-questions.md](accountant-policy-questions.md),
      including the inventory costing method, the depreciation method and useful
      lives, and the retained-earnings account

**Owner and the company's accountant.** These are accounting decisions, not
settings, and the system does not answer them for you.

## 7. Go live and after

- [ ] The nightly backup and the ledger/audit verifications are running
- [ ] The first month's postings have been entered and the trial balance
      checked against the company's own expectation
- [ ] The first year-end close has been rehearsed on a copy, or at least read
      through, before it is run on real books

**Owner.** The first close is the point at which the books stop being a working
draft and become a reported fact; see
[period-close-and-reopen.md](period-close-and-reopen.md).

## What is not verified here

- **The whole launch has not been carried out.** No company has been taken live
  on this system. Every box above is a step that should be taken, not a step
  that has been shown to work end to end.
- **The server does not exist yet.** Every file in `deploy/` is unrun, the
  release script has never executed, and the certificate, firewall and database
  grants are operational steps on a host that has not been provisioned. This is
  stated in full in
  [deployment.md](deployment.md#what-is-not-verified-here).
- **No import has been run against real data.** The imports section is proven by
  the automated test suite against invented fixtures only.
- **The dates and figures are the company's to choose, and are not verified
  against anything.** The cut-over date, the opening balances, the useful lives
  and the costing method are inputs; this checklist makes no claim that any
  particular choice is correct.
- **No Tanzanian compliance claim is made.** Nothing here has been checked
  against a tax rule, a filing requirement or a statutory format, and the
  checklist does not constitute advice to a company.
