<?php

namespace App\Domain\Backups\Services;

use App\Domain\Accounting\Enums\JournalStatus;
use App\Domain\Backups\Exceptions\BackupsException;

/**
 * The only place in the application that talks to the MySQL client tools.
 *
 * A dump and a restore are not something an application can do through its own
 * database connection, and deliberately so. The dump has to carry the triggers
 * — they are what enforce the posting rules at the database level, so a dump
 * without them restores a database that looks right and behaves wrong — and the
 * client's own `DELIMITER` handling is what makes a file full of triggers
 * loadable in one pass. Nothing PDO can execute does that. So the work is handed
 * to `mysqldump` and `mysql`, and everything about how they are invoked lives
 * here rather than being spread across the actions that need them.
 *
 * Three details are worth knowing about how the processes are run.
 *
 * The password is passed in `MYSQL_PWD` rather than on the command line, so it
 * does not appear in a process listing where the whole machine can read it.
 *
 * The commands are passed as an argument *array*, which PHP runs without a
 * shell. Nothing here is assembled into a command line for a shell to re-split,
 * so a path with a space in it, or a table name, cannot become two arguments or
 * a different command.
 *
 * The child's three standard streams are descriptors, not pipes — stdin is the
 * file being restored, stdout and stderr go to scratch files that are read back
 * once the process has exited. A pipe would have to be drained while the child
 * is still running, and a restore that filled a pipe nobody was reading would
 * deadlock with real books half-written. A file cannot.
 */
final class MysqlClient
{
    /**
     * The database the application is reading its own books from, taken from
     * its own connection rather than from a second, driftable copy.
     */
    public function liveDatabase(): string
    {
        return $this->connection()['database'];
    }

    /**
     * Write a full dump of a database to a file.
     *
     * `--single-transaction` takes a consistent snapshot without locking the
     * tables, so a nightly dump does not stop the shop from working.
     * `--triggers` is the one that matters most: without it the restore would
     * be missing the rules that refuse an unbalanced journal and an edit to a
     * posted one. `--routines` carries any stored routines alongside, and
     * `--set-gtid-purged=OFF` keeps the file loadable on a server that has GTID
     * turned on and no privilege to set it.
     */
    public function dump(string $database, string $destination): void
    {
        $this->guardIdentifier($database);

        $this->run(array_merge(
            [$this->binary('mysqldump')],
            $this->connectionArguments(),
            [
                '--single-transaction',
                '--routines',
                '--triggers',
                '--no-tablespaces',
                '--set-gtid-purged=OFF',
                '--default-character-set=utf8mb4',
                '--result-file='.$destination,
                $database,
            ],
        ), sprintf('The dump of "%s"', $database));
    }

    /**
     * Load a dump into a database, the file handed to the client as its stdin.
     *
     * `--binary-mode` is what the client asks for when its input is a dump
     * rather than something typed at a prompt: it stops the client treating a
     * stray NUL in the file as the end of input, which would otherwise stop a
     * restore part-way through with no error.
     */
    public function load(string $database, string $source): void
    {
        $this->guardIdentifier($database);

        $this->run(array_merge(
            [$this->binary('mysql')],
            $this->connectionArguments(),
            ['--binary-mode', '--default-character-set=utf8mb4', $database],
        ), sprintf('The restore into "%s"', $database), $source);
    }

    public function createDatabase(string $database): void
    {
        $this->guardIdentifier($database);

        $collation = $this->guardIdentifier(
            (string) (config('database.connections.mysql.collation') ?: 'utf8mb4_0900_ai_ci')
        );

        $this->query(null, sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE %s',
            $database,
            $collation
        ), sprintf('Creating the scratch database "%s"', $database));
    }

    public function dropDatabase(string $database): void
    {
        $this->guardIdentifier($database);

        $this->query(null, sprintf(
            'DROP DATABASE IF EXISTS `%s`',
            $database
        ), sprintf('Dropping the scratch database "%s"', $database));
    }

    public function databaseExists(string $database): bool
    {
        $this->guardIdentifier($database);

        return (int) trim($this->query(null, sprintf(
            "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '%s'",
            $database
        ), sprintf('Looking for the database "%s"', $database))) > 0;
    }

    /**
     * Every base table, in a stable order so two databases can be compared
     * table by table.
     *
     * @return list<string>
     */
    public function tableNames(string $database): array
    {
        $this->guardIdentifier($database);

        return $this->lines($this->query($database, sprintf(
            "SELECT table_name FROM information_schema.tables
              WHERE table_schema = '%s' AND table_type = 'BASE TABLE'
              ORDER BY table_name",
            $database
        ), 'Listing the tables'));
    }

    /**
     * An exact row count for every table, in one round trip.
     *
     * information_schema's own TABLE_ROWS is an estimate on InnoDB and is not
     * good enough to prove a restore: it can be out by a wide margin on a table
     * that has been updated in place. COUNT(*) is exact, and the union of them
     * is one query rather than one per table.
     *
     * @return array<string, int>
     */
    public function rowCounts(string $database): array
    {
        $tables = $this->tableNames($database);

        if ($tables === []) {
            return [];
        }

        $union = implode(' UNION ALL ', array_map(
            static fn (string $table): string => sprintf(
                "SELECT '%s' AS table_name, COUNT(*) AS row_count FROM `%s`",
                $table,
                $table
            ),
            $tables
        ));

        $counts = [];

        foreach ($this->lines($this->query($database, $union, sprintf(
            'Counting the rows in "%s"',
            $database
        ))) as $line) {
            [$table, $rows] = array_pad(explode("\t", $line, 2), 2, '0');

            $counts[$table] = (int) $rows;
        }

        return $counts;
    }

    /**
     * The trial balance: what every account holds, summed from the ledger.
     *
     * This is the comparison that matters after a restore. Row counts prove the
     * tables came back; this proves the *numbers* did. It reads journal_lines
     * joined to journals on the statuses that belong in the ledger — posted and
     * reversed, never posted alone — because a reversed entry's original lines
     * stay in the ledger for ever and filtering them out would make a restored
     * set of books disagree with the source the moment anything had ever been
     * corrected.
     *
     * Amounts are returned as the database's own decimal strings, not as
     * floats, so the comparison between two databases is exact rather than
     * approximately equal.
     *
     * @return list<array{company_id: int, account_id: int, debit: string, credit: string}>
     */
    public function trialBalance(string $database): array
    {
        $this->guardIdentifier($database);

        $statuses = implode("', '", JournalStatus::ledgerStatuses());

        $rows = $this->lines($this->query($database, sprintf(
            "SELECT l.company_id, l.account_id,
                    CAST(SUM(l.base_debit) AS CHAR) AS debit,
                    CAST(SUM(l.base_credit) AS CHAR) AS credit
               FROM journal_lines l
               JOIN journals j ON j.id = l.journal_id
              WHERE j.status IN ('%s')
              GROUP BY l.company_id, l.account_id
              ORDER BY l.company_id, l.account_id",
            $statuses
        ), sprintf('Reading the trial balance of "%s"', $database)));

        $balance = [];

        foreach ($rows as $line) {
            [$companyId, $accountId, $debit, $credit] = array_pad(
                explode("\t", $line, 4),
                4,
                '0'
            );

            $balance[] = [
                'company_id' => (int) $companyId,
                'account_id' => (int) $accountId,
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        return $balance;
    }

    /**
     * Every trigger the database carries.
     *
     * A restore drill checks this because a dump that lost its triggers
     * restores a database that accepts an unbalanced journal, and nothing else
     * in the comparison would notice: the rows would all be there, and the
     * trial balance would agree, right up to the first bad entry posted into
     * the restored copy.
     *
     * @return list<string>
     */
    public function triggerNames(string $database): array
    {
        $this->guardIdentifier($database);

        return $this->lines($this->query($database, sprintf(
            "SELECT trigger_name FROM information_schema.triggers
              WHERE trigger_schema = '%s'
              ORDER BY trigger_name",
            $database
        ), 'Listing the triggers'));
    }

    /**
     * One query, its result returned as the client wrote it.
     */
    private function query(?string $database, string $sql, string $purpose): string
    {
        return $this->run(array_merge(
            [$this->binary('mysql')],
            $this->connectionArguments(),
            ['--batch', '--skip-column-names', '--raw'],
            $database === null ? [] : ['--database='.$database],
            ['--execute='.$sql],
        ), $purpose);
    }

    /**
     * Run a client, with the file it reads from and the three files it writes
     * to, and answer with what it wrote to stdout.
     */
    private function run(array $command, string $purpose, ?string $stdin = null): string
    {
        $out = tempnam(sys_get_temp_dir(), 'nexas_mysql_out_');
        $err = tempnam(sys_get_temp_dir(), 'nexas_mysql_err_');

        if ($out === false || $err === false) {
            throw BackupsException::temporaryMissing($purpose);
        }

        $descriptors = [
            0 => $stdin === null ? ['pipe', 'r'] : ['file', $stdin, 'r'],
            1 => ['file', $out, 'w'],
            2 => ['file', $err, 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, null, $this->environment());

        if (! is_resource($process)) {
            @unlink($out);
            @unlink($err);

            throw BackupsException::binaryMissing((string) ($command[0] ?? 'mysql'), $purpose);
        }

        if ($stdin === null) {
            fclose($pipes[0]);
        }

        $exitCode = proc_close($process);

        $stdout = (string) @file_get_contents($out);
        $stderr = (string) @file_get_contents($err);

        @unlink($out);
        @unlink($err);

        if ($exitCode !== 0) {
            throw BackupsException::commandFailed(
                $purpose,
                $exitCode,
                trim($stderr) !== '' ? $stderr : $stdout
            );
        }

        return $stdout;
    }

    /**
     * The child's environment: this process's own, plus the password.
     *
     * The environment is passed through rather than replaced, because a
     * deployment that leaves the binaries to PATH needs PATH to survive into
     * the child.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $environment = getenv();

        $environment['MYSQL_PWD'] = $this->connection()['password'];

        return $environment;
    }

    /**
     * @return list<string>
     */
    private function connectionArguments(): array
    {
        $connection = $this->connection();

        return [
            '--protocol=tcp',
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
        ];
    }

    /**
     * The application's own MySQL connection, or a refusal.
     *
     * @return array{host: string, port: string, username: string, password: string, database: string}
     */
    private function connection(): array
    {
        $name = (string) config('database.default');
        $connection = config('database.connections.'.$name);

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'mysql') {
            throw BackupsException::unsupportedConnection($name);
        }

        return [
            'host' => (string) ($connection['host'] ?? '127.0.0.1'),
            'port' => (string) ($connection['port'] ?? '3306'),
            'username' => (string) ($connection['username'] ?? 'root'),
            'password' => (string) ($connection['password'] ?? ''),
            'database' => (string) ($connection['database'] ?? ''),
        ];
    }

    /**
     * A database or collation name that is safe to put inside a statement.
     */
    private function guardIdentifier(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw BackupsException::unsafeIdentifier($name);
        }

        return $name;
    }

    /**
     * Which client to run, from configuration so a deployment can name them in
     * full where they are not on PATH.
     */
    private function binary(string $which): string
    {
        $key = $which === 'mysqldump' ? 'nexas.backups.mysqldump' : 'nexas.backups.mysql';

        return (string) config($key, $which);
    }

    /**
     * The client's output as lines, with the trailing newline and any carriage
     * return the client itself did not strip taken off.
     *
     * @return list<string>
     */
    private function lines(string $output): array
    {
        $lines = [];

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
