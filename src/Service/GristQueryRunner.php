<?php

declare(strict_types=1);

namespace Survos\Grist\Service;

/**
 * Read-only SQL against a Grist document.
 *
 * Each Grist document is a SQLite file, so its tables are real tables and joins
 * across them work -- one query can return records already resolved through their
 * Ref columns, instead of N follow-up lookups to turn row ids into names.
 *
 * Grist's endpoint rejects anything but SELECT ("only select statements are
 * supported"). The guard here is not the security boundary; it exists to fail
 * before the round-trip and with a message that names the offending statement.
 */
final readonly class GristQueryRunner
{
    public function __construct(private GristApplicationLocator $applications)
    {
    }

    /**
     * @param array<array-key,scalar|null> $args bound to `?` placeholders -- always prefer these to
     *                                           interpolation. Normalized to a list, since agent-supplied
     *                                           JSON may arrive keyed.
     *
     * @return list<array<string,mixed>>
     */
    public function sql(string $application, string $sql, array $args = [], int $limit = 500): array
    {
        $this->assertSelect($sql);
        [$app, $client] = $this->applications->locate($application);

        $payload = ['sql' => $sql];
        if ([] !== $args) {
            $payload['args'] = array_values($args);
        }

        $response = $client->request('POST', sprintf('docs/%s/sql', rawurlencode($app->id)), ['json' => $payload]);

        $rows = [];
        foreach ((array) ($response['records'] ?? []) as $record) {
            if (is_array($record) && is_array($record['fields'] ?? null)) {
                $rows[] = $record['fields'];
            }
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    private function assertSelect(string $sql): void
    {
        $normalized = ltrim($sql);
        // Strip leading line and block comments, which could otherwise hide the verb.
        do {
            $before = $normalized;
            $normalized = (string) preg_replace('#^(--[^\n]*\n|/\*.*?\*/)\s*#s', '', $normalized);
        } while ($normalized !== $before);

        if (1 !== preg_match('/^(select|with)\b/i', ltrim($normalized))) {
            throw new \InvalidArgumentException(sprintf(
                'Only SELECT statements can be run against a Grist document; got: %s',
                self::excerpt($sql),
            ));
        }
    }

    private static function excerpt(string $sql): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $sql));

        return mb_strlen($flat) > 80 ? mb_substr($flat, 0, 77).'...' : $flat;
    }
}
