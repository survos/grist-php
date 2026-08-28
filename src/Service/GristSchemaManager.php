<?php

declare(strict_types=1);

namespace Survos\Grist\Service;

/**
 * Reads and extends a Grist document's schema, addressed by configured application name.
 *
 * Deliberately additive: `addColumns` will create what is missing and leave
 * everything else alone. It never drops or retypes a column, because a document
 * is a place people type things by hand and a reconciling tool that "fixes" a
 * column by replacing it destroys work that exists nowhere else.
 *
 * The work itself lives in GristDocument, which is addressed by document id -- a document
 * being created has no configured name yet, and both entry points must behave identically.
 */
final readonly class GristSchemaManager
{
    public function __construct(private GristApplicationLocator $applications)
    {
    }

    /** @return list<string> */
    public function tables(string $application): array
    {
        return $this->document($application)->tableIds();
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
        return $this->document($application)->addColumns($table, $columns);
    }

    public function document(string $application): GristDocument
    {
        [$app, $client] = $this->applications->locate($application);

        return new GristDocument($client, $app->id);
    }
}
