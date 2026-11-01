<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Takes the personal data out of a copy of the books so the copy can be used
 * as staging.
 *
 * A staging environment is only worth having with real shape and real volume,
 * so it is seeded from a copy of the production database. That copy carries
 * the real names, the real telephone numbers, the real tax identifiers and the
 * real credentials of the company and of everyone it trades with, on a machine
 * that is deliberately not the one those people trust. This command takes them
 * out before the copy is handed to anybody.
 *
 * What it replaces, and what it deliberately does not:
 *
 *   names, contact details and tax identifiers   users, companies, branches,
 *                                                customers, suppliers,
 *                                                bank_accounts, warehouses
 *   credentials                                  every member's password is
 *                                                set to one value printed at
 *                                                the end; two-factor secrets
 *                                                and recovery codes are
 *                                                cleared; API tokens, session
 *                                                rows and password reset
 *                                                tokens are deleted
 *   recorded payloads                            the JSON held against an
 *                                                audit, outbox or idempotency
 *                                                row
 *   the audit trail                              see below
 *
 * Amounts, quantities, journal lines, the ledger and the shape of every
 * document are untouched. Only identifying values move.
 *
 * The audit trail is the difficult one. audit_logs is append-only — a trigger
 * refuses UPDATE and DELETE outright — and every row's hash is a SHA-256 over
 * its own content together with the hash of the row before it. So the chain is
 * not edited. The rows are read, scrubbed and written to a scratch table; the
 * trail and the chain head are then emptied; and the scrubbed rows go back in
 * through the ordinary INSERT path, in id order. The BEFORE INSERT trigger
 * recomputes previous_hash and hash as each row lands and the AFTER INSERT
 * trigger advances the head, so the rebuilt chain is correct by construction
 * and `audit:verify` passes afterwards. Nothing is ever UPDATEd or DELETEd in
 * audit_logs, so the trail stays append-only even while it is being rewritten.
 *
 * Each row's hash is recomputed by the database from the row as it now stands,
 * so a row that held no personal value — and carried no address — comes back
 * with the hash it had; the rows that did hold one get new hashes, and the
 * links after them move with them.
 *
 * The command refuses to run when APP_ENV is production. It destroys a trail
 * that it can only rebuild on a copy, and the books themselves are not a copy.
 *
 * Not covered: the *contents* of a filed document. An attachment's name is
 * replaced, but the PDF behind it is not opened, so a name printed inside a
 * scanned invoice survives. A staging copy that must be free of personal data
 * in the documents themselves should not carry the documents at all.
 */
class AnonymiseStaging extends Command
{
    protected $signature = 'staging:anonymise
        {--force : run without the confirmation prompt}
        {--password= : the password every member is given; a random one is generated and printed when omitted}';

    protected $description = 'Replace the personal data in a staging copy of the database and rebuild the audit chain';

    /**
     * JSON keys whose values are replaced wherever they appear in a recorded
     * payload.
     *
     * `name` is in the list knowing that it also names accounts and products.
     * A recorded payload carries no pointer back to the model it describes, so
     * a name is a name and is replaced rather than guessed at; the keys around
     * it are left in place, so a diff still shows which field changed.
     *
     * @var list<string>
     */
    private const PERSONAL_KEYS = [
        'name', 'first_name', 'last_name', 'full_name', 'legal_name', 'display_name',
        'email', 'phone', 'mobile', 'telephone', 'contact', 'contact_name',
        'address', 'tin', 'vrn', 'tax_id', 'tax_number',
        'bank_name', 'account_number', 'job_title',
        'original_name', 'file_name', 'notes', 'ip_address', 'user_agent',
    ];

    /**
     * What a replaced value becomes. Deliberately obvious rather than
     * plausible: a staging row should read as anonymised at a glance.
     */
    private const REPLACEMENT = '[anonymised]';

    public function handle(): int
    {
        if ($this->runningInProduction()) {
            $this->error(sprintf(
                'Refusing to run: APP_ENV is "%s". This command destroys the audit trail and rewrites personal data, so it is only ever run against a copy of the database.',
                (string) config('app.env'),
            ));

            return self::FAILURE;
        }

        if (! $this->option('force')
            && ! $this->confirm('This rewrites personal data and rebuilds the audit trail. Continue?')) {
            $this->warn('Nothing was changed.');

            return self::INVALID;
        }

        $password = (string) ($this->option('password') ?: Str::password(24));

        /** @var list<array{0: string, 1: string, 2: int}> $report */
        $report = [];

        $this->anonymiseUsers(Hash::make($password), $report);
        $this->anonymiseCompanies($report);
        $this->anonymiseBranches($report);
        $this->anonymiseParty('customers', 'Customer', $report);
        $this->anonymiseParty('suppliers', 'Supplier', $report);
        $this->anonymiseBankAccounts($report);
        $this->anonymiseWarehouses($report);
        $this->anonymiseFileNames('attachments', 'original_name', 'document', $report);
        $this->anonymiseFileNames('exports', 'file_name', 'report', $report);
        $this->anonymiseRecordedPayloads($report);
        $this->rebuildAuditTrail($report);
        $this->clearCredentials($report);

        $this->summarise($report, $password);

        return self::SUCCESS;
    }

    /**
     * Whether the books being reached are the real ones.
     *
     * Both readings are consulted deliberately. `config('app.env')` is what
     * the deployment actually declares and cannot be swayed by a stray
     * `--env` on the command line; `app()->environment('production')` honours
     * that override. Either one naming production is enough to refuse, so an
     * operator cannot anonymise the live books by mistake.
     */
    private function runningInProduction(): bool
    {
        return config('app.env') === 'production'
            || app()->environment('production');
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $report
     */
    private function anonymiseUsers(string $hashed, array &$report): void
    {
        $count = 0;

        DB::table('users')->chunkById(200, function ($users) use ($hashed, &$count): void {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update([
                    'name' => 'Member '.$this->pad($user->id),
                    'email' => 'member'.$user->id.'@example.test',
                    'phone' => $this->phone($user->id),
                    'job_title' => 'Staff',
                    // Written as a hash: this is a query-builder write, so the
                    // model's `hashed` cast does not run.
                    'password' => $hashed,
                    'remember_token' => null,
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                ]);

                $count++;
            }
        });

        $report[] = ['users', 'rewritten', $count];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $report
     */
    private function anonymiseCompanies(array &$report): void
    {
        $count = 0;

        DB::table('companies')->chunkById(200, function ($companies) use (&$count): void {
            foreach ($companies as $company) {
                DB::table('companies')->where('id', $company->id)->update([
                    'name' => 'Company '.$this->pad($company->id),
                    'legal_name' => 'Company '.$this->pad($company->id).' Limited',
                    'tin' => $this->taxNumber($company->id),
                    'vrn' => $this->taxNumber($company->id),
                ]);

                $count++;
            }
        });

        $report[] = ['companies', 'rewritten', $count];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $report
     */
    private function anonymiseBranches(array &$report): void
    {
        $count = 0;

        DB::table('branches')->chunkById(200, function ($branches) use (&$count): void {
            foreach ($branches as $branch) {
                DB::table('branches')->where('id', $branch->id)->update([
                    'name' => 'Branch '.$this->pad($branch->id),
                    'address' => 'Address '.$this->pad($branch->id),
                    'phone' => $this->phone($branch->id),
                ]);

                $count++;
            }
        });

        $report[] = ['branches', 'rewritten', $count];
    }

    /**
     * Customers and suppliers carry the same identifying columns, so they are
     * scrubbed by the same code with a different label.
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $report
     */
    private function anonymiseParty(string $table, string $label, array &$report): void
    {
        $count = 0;

        DB::table($table)->chunkById(200, function ($parties) use ($table, $label, &$count): void {
            foreach ($parties as $party) {
                DB::table($table)->where('id', $party->id)->update([
                    'name' => $label.' '.$this->pad($party->id),
                    'tin' => $this->taxNumber($party->id),
                    'vrn' => $this->taxNumber($party->id),
                    'email' => Str::lower($label).$party->id.'@example.test',
                    'phone' => $this->phone($party->id),
                    'address' => 'Address '.$this->pad($party->id),
                    // Free text about a trading partner: the likeliest place
                    // for a contact name to be recorded outside a column.
                    'notes' => null,
                ]);

                $count++;
            }
        });

        $report[] = [$table, 'rewritten', $count];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $report
     */
    private function anonymiseBankAccounts(array &$report): void
    {
        $count = 0;

        DB::table('bank_accounts')->chunkById(200, function ($accounts) use (&$count): void {
            foreach ($accounts as $account) {
                DB::table('bank_accounts')->where('id', $account->id)->update([
                    'name' => 'Bank Account '.$this->pad($account->id),
                    'bank_name' => 'Bank '.$this->pad($account->id),
                    'account_number' => $this->taxNumber($account->id),
                ]);

                $count++;
            }
        });

        $report[] = ['bank_accounts', 'rewritten', $count];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $report
     */
    private function anonymiseWarehouses(array &$report): void
    {
        $count = 0;

        DB::table('warehouses')->chunkById(200, function ($warehouses) use (&$count): void {
            foreach ($warehouses as $warehouse) {
                DB::table('warehouses')->where('id', $warehouse->id)->update([
                    'name' => 'Warehouse '.$this->pad($warehouse->id),
                    'address' => 'Address '.$this->pad($warehouse->id),
                ]);

                $count++;
            }
        });

        $report[] = ['warehouses', 'rewritten', $count];
    }

    /**
     * The name a file was filed under, keeping its extension so the copy still
     * behaves like the original when something opens it.
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $report
     */
    private function anonymiseFileNames(string $table, string $column, string $prefix, array &$report): void
    {
        $count = 0;

        DB::table($table)->whereNotNull($column)->chunkById(200, function ($rows) use ($table, $column, $prefix, &$count): void {
            foreach ($rows as $row) {
                $extension = pathinfo((string) $row->{$column}, PATHINFO_EXTENSION);

                DB::table($table)->where('id', $row->id)->update([
                    $column => $prefix.'-'.$row->id.($extension === '' ? '' : '.'.$extension),
                ]);

                $count++;
            }
        });

        $report[] = [$table, 'rewritten', $count];
    }

    /**
     * The JSON recorded beside a unit of work: what an audit row says changed,
     * what an event carried, what an idempotent request answered with. Each is
     * a copy of something a caller sent or was sent, so each can hold a name.
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $report
     */
    private function anonymiseRecordedPayloads(array &$report): void
    {
        foreach ([
            ['outbox_events', 'payload'],
            ['idempotency_keys', 'response_body'],
        ] as [$table, $column]) {
            $count = 0;

            DB::table($table)->whereNotNull($column)->chunkById(200, function ($rows) use ($table, $column, &$count): void {
                foreach ($rows as $row) {
                    $original = (string) $row->{$column};
                    $scrubbed = $this->scrubJson($original);

                    if ($scrubbed === $original) {
                        continue;
                    }

                    DB::table($table)->where('id', $row->id)->update([$column => $scrubbed]);

                    $count++;
                }
            });

            $report[] = [$table, 'rewritten', $count];
        }
    }

    /**
     * Rebuild the audit chain around a scrubbed trail.
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $report
     */
    private function rebuildAuditTrail(array &$report): void
    {
        $total = (int) DB::table('audit_logs')->count();

        // A scratch table rather than an array in memory: the trail is the one
        // table here that grows without bound, and a staging copy may hold more
        // rows than the PHP process could carry. Created from audit_logs so the
        // two have the same shape; temporary, so it goes with the connection.
        DB::statement('DROP TEMPORARY TABLE IF EXISTS `tmp_anonymised_audit_logs`');
        DB::statement('CREATE TEMPORARY TABLE `tmp_anonymised_audit_logs` LIKE `audit_logs`');

        DB::table('audit_logs')->chunkById(500, function ($rows): void {
            $batch = [];

            foreach ($rows as $row) {
                $batch[] = [
                    'id' => $row->id,
                    'ulid' => $row->ulid,
                    'company_id' => $row->company_id,
                    'user_id' => $row->user_id,
                    'action' => $row->action,
                    'auditable_type' => $row->auditable_type,
                    'auditable_id' => $row->auditable_id,
                    // Where the write came from, and from what. Both identify a
                    // person or their device rather than the change itself.
                    'ip_address' => null,
                    'user_agent' => null,
                    'before' => $this->scrubJson($row->before),
                    'after' => $this->scrubJson($row->after),
                    'meta' => $this->scrubJson($row->meta),
                    // Carried across so the scratch table has the same shape,
                    // but overwritten by the trigger on the way back in.
                    'previous_hash' => $row->previous_hash,
                    'hash' => $row->hash,
                    'created_at' => $row->created_at,
                ];
            }

            DB::table('tmp_anonymised_audit_logs')->insert($batch);
        });

        // Emptying the head and the trail together is what makes this a rebuild
        // rather than an edit: the first row back in is the first row in.
        DB::table('audit_chain_head')->where('id', 1)->update([
            'last_hash' => null,
            'last_id' => null,
            'chain_length' => 0,
            'updated_at' => now(),
        ]);

        DB::statement('TRUNCATE TABLE `audit_logs`');

        // Straight back through the ordinary insert path, in id order, so the
        // database recomputes the chain link by link exactly as it did the
        // first time.
        DB::statement('INSERT INTO `audit_logs` SELECT * FROM `tmp_anonymised_audit_logs` ORDER BY `id`');

        DB::statement('DROP TEMPORARY TABLE `tmp_anonymised_audit_logs`');

        $report[] = ['audit_logs', 'rebuilt', $total];
    }

    /**
     * A credential is only as anonymous as the person it opens. Sessions and
     * reset tokens are pointing at people who will never reach this machine,
     * and an API token is a working key to the copy, so all three go.
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $report
     */
    private function clearCredentials(array &$report): void
    {
        foreach (['personal_access_tokens', 'password_reset_tokens', 'sessions'] as $table) {
            $report[] = [$table, 'deleted', DB::table($table)->delete()];
        }
    }

    /**
     * The scrubbed form of one JSON column, re-encoded only when something
     * actually changed, so a payload that held nothing personal comes back
     * byte for byte rather than through a re-encoding that could reorder it.
     */
    private function scrubJson(?string $json): ?string
    {
        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return $json;
        }

        $scrubbed = $this->scrub($decoded);

        return $scrubbed === $decoded ? $json : (string) json_encode($scrubbed);
    }

    /**
     * Walk a decoded payload, replacing the value of every personal key
     * wherever it sits. Keys are kept so the payload still reads as a record of
     * which fields a change touched.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function scrub(array $payload): array
    {
        $scrubbed = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $scrubbed[$key] = $this->scrub($value);

                continue;
            }

            $personal = is_string($key)
                && in_array(Str::lower($key), self::PERSONAL_KEYS, true)
                && $value !== null;

            $scrubbed[$key] = $personal ? self::REPLACEMENT : $value;
        }

        return $scrubbed;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $report
     */
    private function summarise(array $report, string $password): void
    {
        $this->newLine();
        $this->table(['Table', 'Action', 'Rows'], $report);

        $this->newLine();
        $this->info('Every member signs in to this copy with the password below. It is shown once, and is not stored anywhere else:');
        $this->line('  '.$password);

        $this->newLine();
        $this->line('Run `php artisan audit:verify` to confirm the rebuilt chain holds.');
        $this->line('Filed documents were renamed but not opened: a name printed inside one survives.');
    }

    private function pad(int $id): string
    {
        return str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * A telephone number in the reserved +255 000 range: shaped like a number a
     * form will accept, belonging to nobody.
     */
    private function phone(int $id): string
    {
        return '+2550000'.$this->pad($id);
    }

    /**
     * A tax identifier of the right width for a `tin` or `vrn` column, drawn
     * from the row's own id so a re-run produces the same value.
     */
    private function taxNumber(int $id): string
    {
        return str_pad((string) $id, 9, '0', STR_PAD_LEFT);
    }
}
