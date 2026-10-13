<?php

declare(strict_types=1);

namespace Survos\Grist\Adapter;

use Survos\Grist\Contract\GristClientInterface;
use Survos\RecordStore\Contract\RecordStoreAdapterInterface;
use Survos\RecordStore\Exception\UnsupportedRecordStoreOperation;
use Survos\RecordStore\Model\ApplicationReference;
use Survos\RecordStore\Model\ApplicationSchema;
use Survos\RecordStore\Model\FieldSchema;
use Survos\RecordStore\Model\FieldType;
use Survos\RecordStore\Model\ProviderCapability;
use Survos\RecordStore\Model\Record;
use Survos\RecordStore\Model\RecordPage;
use Survos\RecordStore\Model\RecordQuery;
use Survos\RecordStore\Model\SortDirection;
use Survos\RecordStore\Model\TableReference;
use Survos\RecordStore\Model\TableSchema;
use Survos\RecordStore\Model\UpsertRequest;
use Survos\RecordStore\Model\WriteResult;

final readonly class GristAdapter implements RecordStoreAdapterInterface
{
    public function __construct(private GristClientInterface $client)
    {
    }

    public function provider(): string
    {
        return 'grist';
    }

    public function capabilities(): array
    {
        return [
            ProviderCapability::SchemaRead,
            ProviderCapability::RecordRead,
            ProviderCapability::RecordWrite,
            ProviderCapability::RecordUpsert,
        ];
    }

    public function schema(ApplicationReference $application): ApplicationSchema
    {
        $tables = [];
        foreach ($this->client->tables($application->id) as $table) {
            $tableId = self::stringValue($table['id'] ?? null, 'table ID');
            $fields = [];
            foreach ($this->client->columns($application->id, $tableId) as $column) {
                $columnId = self::stringValue($column['id'] ?? null, 'column ID');
                $metadata = self::metadata($column['fields'] ?? []);
                $label = is_string($metadata['label'] ?? null) ? $metadata['label'] : $columnId;
                $nativeType = is_string($metadata['type'] ?? null) ? $metadata['type'] : 'Any';
                $fields[] = new FieldSchema($columnId, $columnId, $label, self::fieldType($nativeType), $metadata);
            }
            $tableMetadata = self::metadata($table['fields'] ?? []);
            $label = is_string($tableMetadata['label'] ?? null) ? $tableMetadata['label'] : $tableId;
            $tables[] = new TableSchema($tableId, $tableId, $label, $fields);
        }

        return new ApplicationSchema($application->id, $application->name, $tables);
    }

    public function query(TableReference $table, RecordQuery $query): RecordPage
    {
        if (0 !== $query->offset) {
            throw new UnsupportedRecordStoreOperation('Grist record queries do not support a portable offset.');
        }

        $filters = [];
        foreach ($query->filters as $field => $values) {
            $filters[self::fieldName($table->remoteField($field))] = $values;
        }
        $sorts = array_map(
            static fn ($sort): string => (SortDirection::Descending === $sort->direction ? '-' : '')
                .self::fieldName($table->remoteField($sort->field)),
            $query->sorts,
        );
        $records = [];
        foreach ($this->client->queryRecords($table->applicationId, $table->id, $filters, $sorts, $query->limit) as $record) {
            $fields = self::logicalFields($table, $record['fields']);
            if ([] !== $query->select) {
                $fields = array_intersect_key($fields, array_fill_keys($query->select, true));
            }
            $records[] = new Record($fields, $record['id']);
        }

        return new RecordPage($records);
    }

    public function upsert(TableReference $table, UpsertRequest $request): WriteResult
    {
        $records = array_map(static fn (Record $record): array => self::remoteFields($table, $record->fields), $request->records);
        if ([] === $request->keyFields) {
            return new WriteResult(createdIds: $this->client->addRecords($table->applicationId, $table->id, $records));
        }

        $payload = [];
        foreach ($records as $index => $fields) {
            $require = [];
            foreach ($request->keyFields as $keyField) {
                $remoteField = self::fieldName($table->remoteField($keyField));
                if (!array_key_exists($remoteField, $fields)) {
                    throw new \InvalidArgumentException(sprintf('Upsert key field "%s" is missing from record %d.', $keyField, $index));
                }
                $require[$remoteField] = $fields[$remoteField];
            }
            $payload[] = ['require' => $require, 'fields' => $fields];
        }

        return new WriteResult(affectedIds: $this->client->upsertRecords($table->applicationId, $table->id, $payload));
    }

    private static function fieldType(string $nativeType): FieldType
    {
        if (str_starts_with($nativeType, 'Ref:') || str_starts_with($nativeType, 'RefList:')) {
            return FieldType::Reference;
        }

        return match ($nativeType) {
            'Text', 'Any' => FieldType::Text,
            'Int' => FieldType::Integer,
            'Numeric' => FieldType::Decimal,
            'Bool' => FieldType::Boolean,
            'Date' => FieldType::Date,
            'DateTime' => FieldType::DateTime,
            'Choice', 'ChoiceList' => FieldType::Choice,
            'Attachments' => FieldType::Attachment,
            default => FieldType::Unknown,
        };
    }

    /** @return array<string, mixed> */
    private static function metadata(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Grist returned invalid schema metadata.');
        }

        $metadata = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $metadata[$key] = $item;
            }
        }

        return $metadata;
    }

    private static function stringValue(mixed $value, string $kind): string
    {
        if (!is_string($value) || '' === trim($value)) {
            throw new \UnexpectedValueException(sprintf('Grist returned an invalid %s.', $kind));
        }

        return $value;
    }

    private static function fieldName(int|string $field): string
    {
        if (!is_string($field)) {
            throw new \InvalidArgumentException('Grist field mappings must use string column IDs.');
        }

        return $field;
    }

    /** @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function logicalFields(TableReference $table, array $fields): array
    {
        $reverse = [];
        foreach ($table->fields as $logical => $remote) {
            $reverse[self::fieldName($remote)] = $logical;
        }
        $logical = [];
        foreach ($fields as $name => $value) {
            $logical[$reverse[$name] ?? $name] = $value;
        }

        return $logical;
    }

    /** @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function remoteFields(TableReference $table, array $fields): array
    {
        $remote = [];
        foreach ($fields as $name => $value) {
            $remote[self::fieldName($table->remoteField($name))] = $value;
        }

        return $remote;
    }
}
