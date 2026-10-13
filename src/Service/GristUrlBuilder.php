<?php

declare(strict_types=1);

namespace Survos\Grist\Service;

use Survos\Grist\Contract\GristClientInterface;

/**
 * Links into the Grist UI, pointing at a specific row.
 *
 * Anything that shows Grist data eventually wants to send someone to the source to change it,
 * and a link to the document is not that: landing on the wrong page, or on the right page
 * scrolled to row 1 of 34, is a scavenger hunt rather than an edit.
 *
 * A row URL looks like:
 *
 *     https://host/{docId}/{slug}/p/{pageId}#a1.s{sectionId}.r{rowId}
 *
 * None of pageId, sectionId or slug is guessable, and none of them is the table id. They come
 * from the document's own metadata tables, so a page renamed or reordered in Grist stays
 * linkable with nothing to change here.
 *
 * Takes a client rather than a GristApplicationLocator so it is usable outside Symfony -- the
 * WordPress plugin this was written for constructs a client directly and has no service
 * registry to look an application up in.
 *
 * Deliberately does not cache. Every call is two API requests, which is far too many per page
 * render, but caching belongs to the application: the library has no idea what the acceptable
 * staleness of a document's page layout is.
 */
final readonly class GristUrlBuilder
{
    /**
     * @param string $baseUrl the Grist server's public URL, e.g. https://grist.example.com.
     *                        The client knows it, but only as an internal API base_uri, and
     *                        these are links for a person rather than calls for the API.
     */
    public function __construct(
        private GristClientInterface $client,
        private string $baseUrl,
    ) {
    }

    /** The URL of one row, or null if no page in the document shows that table. */
    public function row(string $documentId, string $table, int $rowId): ?string
    {
        $target = $this->targets($documentId)[$table] ?? null;

        if (null === $target || $rowId <= 0) {
            return null;
        }

        // a1 is Grist's own anchor version marker; s and r select section and row.
        return sprintf('%s#a1.s%d.r%d', $this->page($documentId, $target['page']), $target['section'], $rowId);
    }

    /** The URL of the page showing a table, without selecting a row. */
    public function table(string $documentId, string $table): ?string
    {
        $target = $this->targets($documentId)[$table] ?? null;

        return null === $target ? null : $this->page($documentId, $target['page']);
    }

    /**
     * Where each table is shown: table id => page and section.
     *
     * One query rather than three round trips. _grist_Tables names the table,
     * _grist_Views_section is the widget showing it -- parentId 0 means a raw-data view with
     * no page of its own -- and _grist_Pages turns that view into the number the URL wants.
     *
     * A table shown on several pages keeps the first, so it has one obvious home.
     *
     * @return array<string, array{page: int, section: int}>
     */
    public function targets(string $documentId): array
    {
        $response = $this->client->request('POST', sprintf('docs/%s/sql', rawurlencode($documentId)), [
            'json' => [
                'sql' => 'SELECT t.tableId AS tableId, s.id AS sectionId, p.id AS pageId'
                    .' FROM _grist_Tables t'
                    .' JOIN _grist_Views_section s ON s.tableRef = t.id AND s.parentId != 0'
                    .' JOIN _grist_Pages p ON p.viewRef = s.parentId'
                    .' ORDER BY p.pagePos',
            ],
        ]);

        $targets = [];
        foreach ((array) ($response['records'] ?? []) as $record) {
            $fields = $record['fields'] ?? null;
            if (!is_array($fields)) {
                continue;
            }

            $table = (string) ($fields['tableId'] ?? '');
            if ('' !== $table && !isset($targets[$table])) {
                $targets[$table] = [
                    'page' => (int) ($fields['pageId'] ?? 0),
                    'section' => (int) ($fields['sectionId'] ?? 0),
                ];
            }
        }

        return $targets;
    }

    private function page(string $documentId, int $page): string
    {
        return sprintf(
            '%s/%s/%s/p/%d',
            rtrim($this->baseUrl, '/'),
            rawurlencode($documentId),
            rawurlencode($this->slug($documentId)),
            $page,
        );
    }

    /** Grist builds a document's URL slug from its name. */
    private function slug(string $documentId): string
    {
        $document = $this->client->request('GET', sprintf('docs/%s', rawurlencode($documentId)));
        $name = (string) ($document['name'] ?? '');

        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-');

        return '' === $slug ? 'doc' : $slug;
    }
}
