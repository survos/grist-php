<?php

declare(strict_types=1);

namespace Survos\Grist\Service;

/**
 * Reads and extends a Grist document's schema.
 *
 * Deliberately additive: `addColumns` will create what is missing and leave
 * everything else alone. It never drops or retypes a column, because a document
 * is a place people type things by hand and a reconciling tool that "fixes" a
 * column by replacing it destroys work that exists nowhere else.
 */
final readonly class GristSchemaManager
{
    public function __construct(private GristApplicationLocator $applications)
    {
    }

    /** @return list<string> */
    public function tables(string $application): array
    {
        [$app, $client] = $this->applications->locate($application);

        return array_values(array_filter(
            array_map(static fn (array $t): mixed => $t['id'] ?? null, $client->tables($app->id)),
            is_string(...),
        ));
    }

    /**
     * @return list<array{id:string,type:string,label:string,formula:string,isFormula:bool}>
     */
    public function describeTable(string $application, string $table): array
    {
        [$app, $client] = $this->applications->locate($application);

        $columns = [];
        foreach ($client->columns($app->id, $table) as $column) {
            if (!is_string($column['id'] ?? null)) {
                continue;
            }
            $f = is_array($column['fields'] ?? null) ? $column['fields'] : [];
            $columns[] = [
                'id' => $column['id'],
                'type' => (string) ($f['type'] ?? ''),
                'label' => (string) ($f['label'] ?? $column['id']),
                'formula' => (string) ($f['formula'] ?? ''),
                'isFormula' => (bool) ($f['isFormula'] ?? false),
            ];
        }

        return $columns;
    }

    /**
     * Add only the columns that do not already exist.
     *
     * @param array<string,string|array<string,mixed>> $columns colId => Grist type, or colId => full fields array
     *
     * @return list<string> the column ids actually created
     */
    public function addColumns(string $application, string $table, array $columns): array
    {
        [$app, $client] = $this->applications->locate($application);

        $present = array_values(array_filter(
            array_map(static fn (array $c): mixed => $c['id'] ?? null, $client->columns($app->id, $table)),
            is_string(...),
        ));

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

        $client->request(
            'POST',
            sprintf('docs/%s/tables/%s/columns', rawurlencode($app->id), rawurlencode($table)),
            ['json' => ['columns' => $payload]],
        );

        return array_map(static fn (array $c): string => $c['id'], $payload);
    }
}
