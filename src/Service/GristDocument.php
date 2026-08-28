<?php

declare(strict_types=1);

namespace Survos\Grist\Service;

use Survos\Grist\Contract\GristClientInterface;

/**
 * One document, addressed by id rather than by configured application name.
 *
 * GristSchemaManager and friends go through GristApplicationLocator, which resolves a name
 * from record-store configuration. That is the right entry point for a document an app
 * already knows about, and useless for a document that does not exist yet: an importer has
 * to create it, build it, and fill it before anyone can write it into configuration.
 *
 * So this takes a client and a document id, the same reasoning GristUrlBuilder gives for
 * taking a client. Everything schema-shaped here is additive, matching GristSchemaManager:
 * missing tables and columns are created, existing ones are left exactly as they are.
 */
final readonly class GristDocument
{
    /**
     * Grist rejects a records payload with 413 long before any documented row limit -- the
     * cap is on bytes, and a table of long text hits it inside a few dozen rows. Batching
     * on row count is the bug that looks like a Grist outage.
     */
    public const int DEFAULT_MAX_BYTES = 500_000;

    public function __construct(
        private GristClientInterface $client,
        public string $id,
    ) {
        if ('' === trim($this->id)) {
            throw new \InvalidArgumentException('A Grist document id cannot be empty.');
        }
    }

    /**
     * Create an empty document in a workspace.
     *
     * Grist seeds every new document with an empty "Table1"; it is left alone rather than
     * removed, because dropping a table is not something an import should decide.
     */
    public static function create(GristClientInterface $client, int|string $workspace, string $name): self
    {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('A Grist document name cannot be empty.');
        }

        $response = $client->request(
            'POST',
            sprintf('workspaces/%s/docs', rawurlencode((string) $workspace)),
            ['json' => ['name' => $name]],
        );

        // The endpoint answers with a bare JSON string, which GristClient wraps as ['data' => id].
        $id = $response['data'] ?? $response['id'] ?? null;

        if (!is_string($id) || '' === trim($id)) {
            throw new \UnexpectedValueException('Grist did not return an id for the new document.');
        }

        return new self($client, $id);
    }

    /** @return list<string> */
    public function tableIds(): array
    {
        return array_values(array_filter(
            array_map(static fn (array $t): mixed => $t['id'] ?? null, $this->client->tables($this->id)),
            is_string(...),
        ));
    }

    /** @return list<string> */
    public function columnIds(string $table): array
    {
        return array_values(array_filter(
            array_map(static fn (array $c): mixed => $c['id'] ?? null, $this->client->columns($this->id, $table)),
            is_string(...),
        ));
    }

    /**
     * Create the tables that do not exist yet, with their columns.
     *
     * @param array<string, array{label?: string, columns: array<string, array<string, mixed>>}> $tables
     *
     * @return list<string> the table ids actually created
     */
    public function addTables(array $tables): array
    {
        $present = $this->tableIds();

        $payload = [];
        foreach ($tables as $id => $definition) {
            $id = (string) $id;
            if (in_array($id, $present, true)) {
                continue;
            }
            $columns = [];
            foreach ($definition['columns'] as $columnId => $fields) {
                $fields['label'] ??= (string) $columnId;
                $columns[] = ['id' => (string) $columnId, 'fields' => $fields];
            }
            if ([] === $columns) {
                throw new \InvalidArgumentException(sprintf('Grist table "%s" needs at least one column.', $id));
            }
            $table = ['id' => $id, 'columns' => $columns];
            if (isset($definition['label'])) {
                $table['fields'] = ['label' => $definition['label']];
            }
            $payload[] = $table;
        }

        if ([] === $payload) {
            return [];
        }

        $this->client->request(
            'POST',
            sprintf('docs/%s/tables', rawurlencode($this->id)),
            ['json' => ['tables' => $payload]],
        );

        return array_map(static fn (array $t): string => $t['id'], $payload);
    }

    /**
     * Add only the columns that do not already exist.
     *
     * @param array<string, string|array<string, mixed>> $columns colId => Grist type, or colId => full fields array
     *
     * @return list<string> the column ids actually created
     */
    public function addColumns(string $table, array $columns): array
    {
        $present = $this->columnIds($table);

        $payload = [];
        foreach ($columns as $id => $spec) {
            $id = (string) $id;
            if (in_array($id, $present, true)) {
                continue;
            }
            $fields = is_array($spec) ? $spec : ['type' => (string) $spec];
            $fields['label'] ??= $id;
            $payload[] = ['id' => $id, 'fields' => $fields];
        }

        if ([] === $payload) {
            return [];
        }

        $this->client->request(
            'POST',
            sprintf('docs/%s/tables/%s/columns', rawurlencode($this->id), rawurlencode($table)),
            ['json' => ['columns' => $payload]],
        );

        return array_map(static fn (array $c): string => $c['id'], $payload);
    }

    /**
     * Row ids keyed by the value of one column: the map an import needs to turn a foreign
     * key from the source system into a Grist row id.
     *
     * Read over SQL rather than the records endpoint because the whole table is wanted and
     * only two of its columns are, and because the records endpoint has no offset to page
     * with.
     *
     * @return array<string, int>
     */
    public function rowIdsBy(string $table, string $column): array
    {
        $response = $this->client->request('POST', sprintf('docs/%s/sql', rawurlencode($this->id)), [
            'json' => ['sql' => sprintf('SELECT id, %s AS k FROM %s WHERE k IS NOT NULL', self::quote($column), self::quote($table))],
        ]);

        $map = [];
        foreach ((array) ($response['records'] ?? []) as $record) {
            $fields = is_array($record) ? ($record['fields'] ?? null) : null;
            if (!is_array($fields) || !isset($fields['id'], $fields['k'])) {
                continue;
            }
            $map[(string) $fields['k']] = (int) $fields['id'];
        }

        return $map;
    }

    /**
     * Upsert records in batches small enough for Grist to accept.
     *
     * There is no transaction and no save button behind this: every batch that lands is
     * committed the moment it lands. A run that dies half way leaves half the rows, which is
     * only recoverable because the key columns make re-running it a no-op for what is already
     * there -- so `$keys` is required, not optional.
     *
     * @param iterable<array<string, mixed>> $records keyed by column id; must contain every key column
     * @param list<string>                   $keys    column ids that identify an existing row
     *
     * @return array{records: int, batches: int}
     */
    public function upsert(string $table, iterable $records, array $keys, int $maxBytes = self::DEFAULT_MAX_BYTES): array
    {
        if ([] === $keys) {
            throw new \InvalidArgumentException('Upserting into Grist needs at least one key column, or a re-run duplicates every row.');
        }
        if ($maxBytes < 1_000) {
            throw new \InvalidArgumentException('A Grist batch needs room for at least one record.');
        }

        $written = $batches = 0;
        $batch = [];
        $bytes = 0;

        foreach ($records as $fields) {
            $require = [];
            foreach ($keys as $key) {
                if (!array_key_exists($key, $fields)) {
                    throw new \InvalidArgumentException(sprintf('Record is missing key column "%s".', $key));
                }
                $require[$key] = $fields[$key];
            }
            $entry = ['require' => $require, 'fields' => $fields];
            $size = strlen(json_encode($entry, JSON_THROW_ON_ERROR));

            if ([] !== $batch && $bytes + $size > $maxBytes) {
                $this->client->upsertRecords($this->id, $table, $batch);
                $written += count($batch);
                ++$batches;
                $batch = [];
                $bytes = 0;
            }

            $batch[] = $entry;
            $bytes += $size;
        }

        if ([] !== $batch) {
            $this->client->upsertRecords($this->id, $table, $batch);
            $written += count($batch);
            ++$batches;
        }

        return ['records' => $written, 'batches' => $batches];
    }

    /**
     * Upload a file and return the attachment id to put in an Attachments cell.
     *
     * Hand-rolled multipart rather than symfony/mime: this library depends on the HTTP client
     * *contracts* only, so that a WordPress or plain-PHP caller can pass any implementation.
     */
    public function uploadAttachment(string $filename, string $contents, string $mimeType = 'application/octet-stream'): int
    {
        $boundary = 'grist'.bin2hex(random_bytes(16));
        $body = sprintf(
            "--%s\r\nContent-Disposition: form-data; name=\"upload\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n%s\r\n--%s--\r\n",
            $boundary,
            str_replace(['"', "\r", "\n"], '', $filename),
            $mimeType,
            $contents,
            $boundary,
        );

        $response = $this->client->request('POST', sprintf('docs/%s/attachments', rawurlencode($this->id)), [
            'headers' => ['Content-Type' => 'multipart/form-data; boundary='.$boundary],
            'body' => $body,
        ]);

        $ids = $response['data'] ?? $response;
        $id = is_array($ids) ? ($ids[0] ?? null) : null;

        if (!is_int($id)) {
            throw new \UnexpectedValueException('Grist did not return an attachment id.');
        }

        return $id;
    }

    /** SQLite identifier quoting, for the SQL endpoint. */
    private static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
