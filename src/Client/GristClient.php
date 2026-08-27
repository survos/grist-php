<?php

declare(strict_types=1);

namespace Survos\Grist\Client;

use Survos\Grist\Contract\GristClientInterface;
use Survos\Grist\Exception\GristApiException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GristClient implements GristClientInterface
{
    public function __construct(private HttpClientInterface $http)
    {
    }

    public function tables(string $documentId): array
    {
        self::requireId($documentId, 'document');
        $result = $this->request('GET', sprintf('docs/%s/tables', rawurlencode($documentId)));

        return self::objectList($result['tables'] ?? null, 'tables');
    }

    public function columns(string $documentId, string $tableId): array
    {
        self::requireId($documentId, 'document');
        self::requireId($tableId, 'table');
        $result = $this->request('GET', sprintf(
            'docs/%s/tables/%s/columns',
            rawurlencode($documentId),
            rawurlencode($tableId),
        ));

        return self::objectList($result['columns'] ?? null, 'columns');
    }

    public function queryRecords(
        string $documentId,
        string $tableId,
        array $filters = [],
        array $sorts = [],
        int $limit = 100,
    ): array {
        self::requireId($documentId, 'document');
        self::requireId($tableId, 'table');
        if ($limit < 1) {
            throw new \InvalidArgumentException('A Grist record limit must be at least 1.');
        }

        $query = ['limit' => $limit];
        if ([] !== $filters) {
            $query['filter'] = json_encode($filters, JSON_THROW_ON_ERROR);
        }
        if ([] !== $sorts) {
            $query['sort'] = implode(',', $sorts);
        }
        $result = $this->request('GET', sprintf(
            'docs/%s/tables/%s/records',
            rawurlencode($documentId),
            rawurlencode($tableId),
        ), ['query' => $query]);

        $records = self::objectList($result['records'] ?? null, 'records');
        $normalized = [];
        foreach ($records as $record) {
            $id = $record['id'] ?? null;
            $fields = $record['fields'] ?? null;
            if ((!is_int($id) && !is_string($id)) || !is_array($fields)) {
                throw new \UnexpectedValueException('Grist returned an invalid record.');
            }
            $normalizedFields = [];
            foreach ($fields as $name => $value) {
                if (!is_string($name)) {
                    throw new \UnexpectedValueException('Grist returned a record with a non-string field name.');
                }
                $normalizedFields[$name] = $value;
            }
            $normalized[] = ['id' => $id, 'fields' => $normalizedFields];
        }

        return $normalized;
    }

    public function addRecords(string $documentId, string $tableId, array $records): array
    {
        if ([] === $records) {
            throw new \InvalidArgumentException('At least one Grist record is required.');
        }
        $payload = ['records' => array_map(static fn (array $fields): array => ['fields' => $fields], $records)];
        $result = $this->request('POST', self::recordsPath($documentId, $tableId), ['json' => $payload]);

        return self::recordIds($result);
    }

    public function upsertRecords(string $documentId, string $tableId, array $records): array
    {
        if ([] === $records) {
            throw new \InvalidArgumentException('At least one Grist record is required.');
        }
        foreach ($records as $record) {
            if ([] === $record['require']) {
                throw new \InvalidArgumentException('Each Grist upsert requires at least one matching field.');
            }
        }
        $result = $this->request('PUT', self::recordsPath($documentId, $tableId), [
            'json' => ['records' => $records],
        ]);

        return self::recordIds($result);
    }

    public function request(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->http->request($method, ltrim($path, '/'), $options);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new GristApiException('Grist transport failure: '.$exception->getMessage(), previous: $exception);
        }

        $decoded = [];
        if ('' !== $content) {
            try {
                $value = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                $decoded = is_array($value) ? $value : ['data' => $value];
            } catch (\JsonException $exception) {
                throw new GristApiException(
                    sprintf('Grist returned invalid JSON (HTTP %d).', $statusCode),
                    $statusCode,
                    previous: $exception,
                );
            }
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $decoded['error'] ?? $decoded['message'] ?? sprintf('Grist request failed with HTTP %d.', $statusCode);
            throw new GristApiException(
                is_string($message) ? $message : sprintf('Grist request failed with HTTP %d.', $statusCode),
                $statusCode,
                $decoded,
            );
        }

        return $decoded;
    }

    private static function recordsPath(string $documentId, string $tableId): string
    {
        self::requireId($documentId, 'document');
        self::requireId($tableId, 'table');

        return sprintf('docs/%s/tables/%s/records', rawurlencode($documentId), rawurlencode($tableId));
    }

    private static function requireId(string $id, string $kind): void
    {
        if ('' === trim($id)) {
            throw new \InvalidArgumentException(sprintf('The Grist %s ID cannot be empty.', $kind));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function objectList(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(sprintf('Grist returned an invalid %s list.', $name));
        }

        $objects = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \UnexpectedValueException(sprintf('Grist returned a non-object item in its %s list.', $name));
            }
            $object = [];
            foreach ($item as $key => $fieldValue) {
                if (!is_string($key)) {
                    throw new \UnexpectedValueException(sprintf('Grist returned a %s object with a non-string key.', $name));
                }
                $object[$key] = $fieldValue;
            }
            $objects[] = $object;
        }

        return $objects;
    }

    /** @param array<array-key, mixed> $result
     *  @return list<int|string>
     */
    private static function recordIds(array $result): array
    {
        if (!isset($result['records'])) {
            return [];
        }

        $records = self::objectList($result['records'], 'records');
        $ids = [];
        foreach ($records as $record) {
            $id = $record['id'] ?? null;
            if (is_int($id) || is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
