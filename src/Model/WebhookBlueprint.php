<?php

declare(strict_types=1);

namespace Survos\Grist\Model;

/**
 * A declarative webhook, matched to an existing one by name so `upsert` is idempotent.
 *
 * The two guard fields are the reason this is worth declaring rather than clicking:
 * `watchedColIds` stops a callback that writes derived columns back from re-triggering
 * itself, and `isReadyColIds`/`isReadyColumn` stops half-filled rows from firing at all.
 */
final readonly class WebhookBlueprint
{
    public const array EVENT_TYPES = ['add', 'update'];

    /** @var list<string> */
    public array $eventTypes;

    /** @var list<string> */
    public array $watchedColIds;

    /**
     * Inputs are deliberately typed wider than the properties. These blueprints are
     * built from decoded JSON supplied by an agent, so a keyed array is a real
     * possibility -- it is normalized here instead of being trusted.
     *
     * @param array<array-key,string> $eventTypes    'add' and/or 'update'
     * @param array<array-key,string> $watchedColIds columns whose change fires the hook; empty = any column
     */
    public function __construct(
        public string $application,
        public string $table,
        public string $name,
        public string $url,
        array $eventTypes = ['add', 'update'],
        array $watchedColIds = [],
        public ?string $isReadyColumn = null,
        public bool $enabled = true,
        public ?string $memo = null,
        public ?string $authorization = null,
    ) {
        $this->eventTypes = array_values($eventTypes);
        $this->watchedColIds = array_values($watchedColIds);

        if ('' === trim($application) || '' === trim($table) || '' === trim($name) || '' === trim($url)) {
            throw new \InvalidArgumentException('Webhook application, table, name, and url are required.');
        }
        if ([] === $this->eventTypes) {
            throw new \InvalidArgumentException('At least one event type is required.');
        }
        $unknown = array_diff($this->eventTypes, self::EVENT_TYPES);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(sprintf('Unsupported event types: %s. Grist supports only %s.', implode(', ', $unknown), implode(' and ', self::EVENT_TYPES)));
        }
        if (count($this->watchedColIds) !== count(array_unique($this->watchedColIds))) {
            throw new \InvalidArgumentException('Watched column ids must be unique.');
        }
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
            throw new \InvalidArgumentException('Webhook url must be http(s).');
        }
    }

    /** The payload shape Grist's REST layer expects (see WebhookFields in Triggers.d.ts). */
    public function toFields(): array
    {
        $fields = [
            'url' => $this->url,
            'eventTypes' => $this->eventTypes,
            'tableId' => $this->table,
            'enabled' => $this->enabled,
            'name' => $this->name,
        ];
        if ([] !== $this->watchedColIds) {
            $fields['watchedColIds'] = $this->watchedColIds;
        }
        if (null !== $this->isReadyColumn) {
            $fields['isReadyColumn'] = $this->isReadyColumn;
        }
        if (null !== $this->memo) {
            $fields['memo'] = $this->memo;
        }
        if (null !== $this->authorization) {
            $fields['authorization'] = $this->authorization;
        }

        return $fields;
    }
}
