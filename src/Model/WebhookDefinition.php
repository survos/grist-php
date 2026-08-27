<?php

declare(strict_types=1);

namespace Survos\Grist\Model;

final readonly class WebhookDefinition
{
    /**
     * @param list<string> $eventTypes
     * @param list<string> $watchedColIds
     */
    public function __construct(
        public string $application,
        public string $documentId,
        public string $id,
        public string $table,
        public string $name,
        public string $url,
        public array $eventTypes,
        public array $watchedColIds,
        public ?string $isReadyColumn,
        public bool $enabled,
        public ?string $status = null,
    ) {
    }

    /** @param array<string,mixed> $row a single entry from GET /docs/{id}/webhooks */
    public static function fromApi(string $application, string $documentId, array $row): self
    {
        $f = is_array($row['fields'] ?? null) ? $row['fields'] : [];

        return new self(
            application: $application,
            documentId: $documentId,
            id: (string) ($row['id'] ?? ''),
            table: (string) ($f['tableId'] ?? ''),
            name: (string) ($f['name'] ?? ''),
            url: (string) ($f['url'] ?? ''),
            eventTypes: array_values(array_filter((array) ($f['eventTypes'] ?? []), is_string(...))),
            watchedColIds: array_values(array_filter((array) ($f['watchedColIds'] ?? []), is_string(...))),
            isReadyColumn: is_string($f['isReadyColumn'] ?? null) ? $f['isReadyColumn'] : null,
            enabled: (bool) ($f['enabled'] ?? false),
            status: is_string($row['status'] ?? null) ? $row['status'] : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
