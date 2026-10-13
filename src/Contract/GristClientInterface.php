<?php

declare(strict_types=1);

namespace Survos\Grist\Contract;

interface GristClientInterface
{
    /** @return list<array<string, mixed>> */
    public function tables(string $documentId): array;

    /** @return list<array<string, mixed>> */
    public function columns(string $documentId, string $tableId): array;

    /**
     * @param array<string, list<int|float|string|bool|null>> $filters
     * @param list<string>                                    $sorts
     *
     * @return list<array{id: int|string, fields: array<string, mixed>}>
     */
    public function queryRecords(string $documentId, string $tableId, array $filters = [], array $sorts = [], int $limit = 100): array;

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<int|string>
     */
    public function addRecords(string $documentId, string $tableId, array $records): array;

    /**
     * @param list<array{require: array<string, mixed>, fields: array<string, mixed>}> $records
     *
     * @return list<int|string>
     */
    public function upsertRecords(string $documentId, string $tableId, array $records): array;

    /**
     * @param array<string, mixed> $options
     *
     * @return array<array-key, mixed>
     */
    public function request(string $method, string $path, array $options = []): array;
}
