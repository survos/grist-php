<?php

declare(strict_types=1);

namespace Survos\Grist\Model;

final readonly class FormDefinition
{
    /** @param list<string> $fields */
    public function __construct(public string $application, public string $documentId, public string $table, public string $title, public int $viewId, public int $sectionId, public array $fields, public bool $published, public ?string $linkId)
    {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
