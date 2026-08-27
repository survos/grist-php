<?php

declare(strict_types=1);

namespace Survos\Grist\Model;

final readonly class FormBlueprint
{
    /** @param list<string> $fields */
    public function __construct(public string $application, public string $table, public string $title, public string $intro, public array $fields, public string $submitLabel = 'Submit', public bool $publish = false, public ?string $linkId = null)
    {
        if ('' === trim($application) || '' === trim($table) || '' === trim($title) || '' === trim($submitLabel) || [] === $fields) {
            throw new \InvalidArgumentException('Form application, table, title, submit label, and fields are required.');
        }
        if (count($fields) !== count(array_unique($fields))) {
            throw new \InvalidArgumentException('Form fields must be unique.');
        }
    }
}
