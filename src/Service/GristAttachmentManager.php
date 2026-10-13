<?php

declare(strict_types=1);

namespace Survos\Grist\Service;

use Survos\Grist\Contract\GristClientInterface;

/**
 * Where a document keeps the bytes behind its Attachments columns.
 *
 * With `GRIST_DOCS_MINIO_*` configured on the server, Grist can keep attachment
 * bytes in S3-compatible object storage instead of inside the document, keyed by
 * content hash:
 *
 *     docs/attachments/{docId}/{sha1}.{ext}
 *
 * That key is stable and content-addressed, so an image server can read it
 * directly and the application never has to proxy bytes back out of Grist.
 *
 * The catch, and the reason this class exists: **external storage is opt-in per
 * document**. Setting the server env vars changes nothing about documents that
 * already exist -- the storage decision is made when a document is created.
 * Existing *attachments* can still be moved across with `transferAll()`; the
 * document file itself cannot.
 */
final readonly class GristAttachmentManager
{
    public const string INTERNAL = 'internal';
    public const string EXTERNAL = 'external';

    public function __construct(private GristApplicationLocator $applications)
    {
    }

    /** @return array{store:string,transfer:array<string,mixed>} */
    public function status(string $application): array
    {
        [$app, $client] = $this->applications->locate($application);

        return [
            'store' => $this->store($client, $app->id),
            'transfer' => $this->transferStatus($client, $app->id),
        ];
    }

    /**
     * Point this document's attachments at the server's external store.
     *
     * @param bool $transferExisting also move attachments already held inside the document
     *
     * @return array{store:string,transfer:array<string,mixed>}
     */
    public function useExternalStore(string $application, bool $transferExisting = true): array
    {
        [$app, $client] = $this->applications->locate($application);

        $client->request(
            'POST',
            sprintf('docs/%s/attachments/store', rawurlencode($app->id)),
            ['json' => ['type' => self::EXTERNAL]],
        );

        if (self::EXTERNAL !== $this->store($client, $app->id)) {
            throw new \RuntimeException('Grist did not switch this document to external attachment storage. The usual cause is that the server has no external store configured (GRIST_DOCS_MINIO_*).');
        }

        if ($transferExisting) {
            $client->request('POST', sprintf('docs/%s/attachments/transferAll', rawurlencode($app->id)));
        }

        return [
            'store' => self::EXTERNAL,
            'transfer' => $this->transferStatus($client, $app->id),
        ];
    }

    private function store(GristClientInterface $client, string $doc): string
    {
        $response = $client->request('GET', sprintf('docs/%s/attachments/store', rawurlencode($doc)));

        return is_string($response['type'] ?? null) ? $response['type'] : self::INTERNAL;
    }

    /** @return array<string,mixed> */
    private function transferStatus(GristClientInterface $client, string $doc): array
    {
        $response = $client->request('GET', sprintf('docs/%s/attachments/transferStatus', rawurlencode($doc)));

        return [
            'locationSummary' => $response['locationSummary'] ?? null,
            'pending' => $response['status']['pendingTransferCount'] ?? null,
            'isRunning' => $response['status']['isRunning'] ?? null,
            'successes' => $response['status']['successes'] ?? null,
            'failures' => $response['status']['failures'] ?? null,
        ];
    }
}
