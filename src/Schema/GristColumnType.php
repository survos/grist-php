<?php

declare(strict_types=1);

namespace Survos\Grist\Schema;

use Survos\RecordStore\Model\FieldType;

/**
 * The direction GristAdapter::fieldType() does not go: FieldType -> a Grist column type.
 *
 * Reading a store only ever needs the inward mapping, so both adapters have one and
 * neither has this. Creating columns needs the reverse, and it is not a lookup table
 * turned around: Ref needs the name of the table it points at, DateTime carries a
 * timezone in its type string, and Choice is meaningless without its allowed values.
 * Anything that builds a Grist document out of a normalized schema needs all of that,
 * which is why it lives beside the adapter rather than inside one importer.
 *
 * The wire format is the other half. Grist's REST API takes numbers where a person
 * sees a date, and a list as ['L', ...values] rather than a JSON array, so a value
 * that looks right in a payload can still land in the column as invalid text -- Grist
 * keeps it, shows it pink, and nothing errors. coerce() is what stops that happening
 * silently.
 */
final readonly class GristColumnType
{
    public const string DEFAULT_TIMEZONE = 'UTC';

    /** Grist's encoding for a cell holding a list of values. */
    public const string LIST_PREFIX = 'L';

    /**
     * The native Grist type string for a normalized field type.
     *
     * @param string|null $referencedTable required for FieldType::Reference -- the Grist table id
     *                                     the column points at, which the normalized schema does
     *                                     not carry because not every provider has one
     * @param bool        $list            a multi-valued column: ChoiceList / RefList
     */
    public static function native(
        FieldType $type,
        ?string $referencedTable = null,
        bool $list = false,
        string $timezone = self::DEFAULT_TIMEZONE,
    ): string {
        if (FieldType::Reference === $type) {
            if (null === $referencedTable || '' === trim($referencedTable)) {
                throw new \InvalidArgumentException('A Grist reference column needs the id of the table it points at; resolve the target table first, or map the field to something else and say so.');
            }

            return sprintf('%s:%s', $list ? 'RefList' : 'Ref', $referencedTable);
        }

        return match ($type) {
            FieldType::Text => 'Text',
            FieldType::Integer => 'Int',
            FieldType::Decimal => 'Numeric',
            FieldType::Boolean => 'Bool',
            FieldType::Date => 'Date',
            FieldType::DateTime => '' === trim($timezone) ? 'DateTime' : 'DateTime:'.$timezone,
            FieldType::Choice => $list ? 'ChoiceList' : 'Choice',
            FieldType::Attachment => 'Attachments',
            // Grist's Any is a formula column when empty, so an unknown field becomes Text:
            // a column people can still type into is more useful than one they cannot.
            FieldType::Time, FieldType::Unknown => 'Text',
        };
    }

    /**
     * The `fields` payload for GristSchemaManager::addColumns() / GristDocument::addColumns().
     *
     * @param array<array-key, string> $choices       allowed values, for Choice and ChoiceList; re-indexed,
     *                                                because a keyed array encodes as a JSON object and Grist
     *                                                then shows a Choice column with no choices at all
     * @param array<string, mixed>     $widgetOptions merged over the defaults, e.g. ['numMode' => 'currency']
     *
     * @return array<string, mixed>
     */
    public static function definition(
        FieldType $type,
        string $label,
        ?string $referencedTable = null,
        array $choices = [],
        bool $list = false,
        array $widgetOptions = [],
        string $timezone = self::DEFAULT_TIMEZONE,
    ): array {
        $fields = [
            'type' => self::native($type, $referencedTable, $list, $timezone),
            'label' => $label,
        ];

        if (FieldType::Choice === $type && [] !== $choices) {
            // choiceOptions is a map of choice => styling. An empty PHP array encodes as [],
            // and Grist's client expects an object there.
            $widgetOptions = ['choices' => array_values($choices), 'choiceOptions' => new \stdClass()] + $widgetOptions;
        }

        if ([] !== $widgetOptions) {
            // Grist stores widgetOptions as a JSON *string*, not an object. An object is
            // accepted by the API and then ignored by the client, so the column arrives
            // with no choices and no error.
            $fields['widgetOptions'] = json_encode($widgetOptions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return $fields;
    }

    /**
     * A value in the wire format the target column will actually store.
     *
     * Returns null for "leave this cell empty", which is not the same as writing null into
     * a typed column -- callers strip nulls rather than send them.
     *
     * @param string $nativeType the Grist type string, as returned by native()
     */
    public static function coerce(string $nativeType, mixed $value): mixed
    {
        if (null === $value || '' === $value || [] === $value) {
            return null;
        }

        if (str_starts_with($nativeType, 'RefList:') || 'ChoiceList' === $nativeType || 'Attachments' === $nativeType) {
            $items = is_array($value) ? array_values($value) : [$value];

            return array_merge([self::LIST_PREFIX], $items);
        }

        if (str_starts_with($nativeType, 'Ref:')) {
            return is_numeric($value) ? (int) $value : null;
        }

        if (str_starts_with($nativeType, 'DateTime')) {
            return self::timestamp($value);
        }

        return match ($nativeType) {
            // Grist stores a Date as the epoch second of UTC midnight, never as a string.
            'Date' => self::timestamp($value, midnight: true),
            'Int' => is_numeric($value) ? (int) $value : null,
            'Numeric' => is_numeric($value) ? (float) $value : null,
            'Bool' => self::boolean($value),
            default => is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        };
    }

    private static function timestamp(mixed $value, bool $midnight = false): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        if (!is_string($value)) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return ($midnight ? $date->setTime(0, 0) : $date)->getTimestamp();
    }

    private static function boolean(mixed $value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no'], true);
        }

        return (bool) $value;
    }
}
