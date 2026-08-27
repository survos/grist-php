<?php

declare(strict_types=1);

namespace Survos\Grist\Service;

use Survos\Grist\Model\WebhookBlueprint;
use Survos\Grist\Model\WebhookDefinition;
use Survos\Grist\Contract\GristClientInterface;

/**
 * Declarative management of Grist's outgoing webhooks.
 *
 * Grist fires these on row add/update, which is what lets an app enrich records
 * (transcribe an audio link, push an upload to object storage) without a batch
 * sync step. Webhooks are matched by name so applying the same blueprint twice
 * updates rather than duplicates.
 */
final readonly class GristWebhookManager
{
    public function __construct(private GristApplicationLocator $applications)
    {
    }

    /** @return list<WebhookDefinition> */
    public function list(string $application): array
    {
        [$app, $client] = $this->applications->locate($application);

        return array_map(
            static fn (array $row): WebhookDefinition => WebhookDefinition::fromApi($application, $app->id, $row),
            $this->fetch($client, $app->id),
        );
    }

    public function upsert(WebhookBlueprint $blueprint): WebhookDefinition
    {
        [$app, $client] = $this->applications->locate($blueprint->application);
        $this->assertColumnsExist($client, $app->id, $blueprint);

        $existing = array_find(
            $this->fetch($client, $app->id),
            static fn (array $row): bool => $blueprint->name === ($row['fields']['name'] ?? null),
        );

        $path = sprintf('docs/%s/webhooks', rawurlencode($app->id));
        if (null === $existing) {
            $client->request('POST', $path, ['json' => ['webhooks' => [['fields' => $blueprint->toFields()]]]]);
        } else {
            $client->request('PATCH', $path.'/'.rawurlencode((string) $existing['id']), ['json' => $blueprint->toFields()]);
        }

        $saved = array_find(
            $this->fetch($client, $app->id),
            static fn (array $row): bool => $blueprint->name === ($row['fields']['name'] ?? null),
        );
        if (null === $saved) {
            throw new \RuntimeException(sprintf('Grist did not persist webhook "%s".', $blueprint->name));
        }

        return WebhookDefinition::fromApi($blueprint->application, $app->id, $saved);
    }

    public function delete(string $application, string $name): bool
    {
        [$app, $client] = $this->applications->locate($application);
        $existing = array_find(
            $this->fetch($client, $app->id),
            static fn (array $row): bool => $name === ($row['fields']['name'] ?? null),
        );
        if (null === $existing) {
            return false;
        }
        $client->request('DELETE', sprintf('docs/%s/webhooks/%s', rawurlencode($app->id), rawurlencode((string) $existing['id'])));

        return true;
    }

    /**
     * A wrong column id is silently accepted by Grist and the hook simply never fires,
     * which is close to impossible to debug later. Fail loudly at declaration time.
     */
    private function assertColumnsExist(GristClientInterface $client, string $doc, WebhookBlueprint $blueprint): void
    {
        $referenced = $blueprint->watchedColIds;
        if (null !== $blueprint->isReadyColumn) {
            $referenced[] = $blueprint->isReadyColumn;
        }
        if ([] === $referenced) {
            return;
        }

        $known = array_values(array_filter(
            array_map(static fn (array $c): mixed => $c['id'] ?? null, $client->columns($doc, $blueprint->table)),
            is_string(...),
        ));
        $unknown = array_diff($referenced, $known);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown column(s) on table "%s": %s. Known columns: %s.',
                $blueprint->table,
                implode(', ', $unknown),
                implode(', ', $known),
            ));
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetch(GristClientInterface $client, string $doc): array
    {
        $response = $client->request('GET', sprintf('docs/%s/webhooks', rawurlencode($doc)));

        return array_values(array_filter((array) ($response['webhooks'] ?? []), is_array(...)));
    }
}
