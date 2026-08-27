# survos/grist-php

A PHP client for [Grist](https://www.getgrist.com/) — the API client, a
[record-store](https://github.com/survos/record-store) adapter, and a model for Grist's
forms and webhooks.

Framework-agnostic. It depends on `symfony/http-client-contracts`, which is an
**interface** package — bring any implementation you like. The same code runs in a
Symfony application, a WordPress plugin, or a plain PHP script.

At the time of writing this appears to be the only Grist client on Packagist.

```bash
composer require survos/grist-php symfony/http-client
```

## Reading and writing records

The adapter speaks the provider-neutral vocabulary from `survos/record-store`, so code
written against it also works against Quickbase.

```php
use Survos\Grist\Adapter\GristAdapterFactory;
use Survos\RecordStore\Model\{ConnectionConfiguration, Record, RecordQuery, TableReference, UpsertRequest};
use Symfony\Component\HttpClient\HttpClient;

$factory = new GristAdapterFactory(HttpClient::create());

$adapter = $factory->create(new ConnectionConfiguration(
    name:   'pgsc',
    driver: 'grist',
    options: [
        'base_uri' => 'https://grist.example.org/api/',
        'token'    => $_ENV['GRIST_API_KEY'],
        // 'timeout' => 30.0,
    ],
));

$artists = new TableReference(
    application:   'pgsc',
    applicationId: $documentId,   // the Grist doc id
    connection:    'pgsc',
    name:          'artists',     // your logical name
    id:            'Artists',     // the table id in Grist
);

$page = $adapter->query($artists, new RecordQuery(limit: 20));
foreach ($page->records as $record) {
    echo $record->id, ': ', $record->fields['Name'] ?? '', PHP_EOL;
}

$adapter->upsert($artists, new UpsertRequest(
    records: [new Record(fields: ['Bio' => 'Updated bio'], id: 42)],
));
```

**Writes go through Grist's normal API path**, so formulas recalculate and webhooks fire
exactly as they do when a person edits a cell in the browser. There is no back door that
skips the data engine — which is the property you want if downstream automations matter.

## The client directly

When the neutral vocabulary is in the way, use the client:

```php
use Survos\Grist\Adapter\GristAdapterFactory;

$client = (new GristAdapterFactory(HttpClient::create()))->client($connection);

$client->tables($documentId);
$client->columns($documentId, 'Artists');
$client->queryRecords(/* … */);
$client->addRecords($documentId, 'Artists', $records);
$client->upsertRecords($documentId, 'Artists', $records);
$client->request('GET', 'docs/'.$documentId.'/sql?q=…');   // anything not wrapped yet
```

## Schema, forms, webhooks, attachments

`Service\` carries the operations that are about a document's *shape* rather than its
rows — `GristSchemaManager`, `GristFormManager`, `GristWebhookManager`,
`GristAttachmentManager`, `GristQueryRunner`, `GristApplicationLocator` — with
`Model\FormBlueprint`, `FormDefinition`, `WebhookBlueprint` and `WebhookDefinition`
describing forms and webhooks declaratively, so they can be diffed and upserted rather
than clicked.

One operational note: Grist will not deliver a webhook to a host that is not in its
`ALLOWED_WEBHOOK_DOMAINS`. If rows appear and nothing downstream happens, check that
before anything else — it fails silently.

## Symfony

[`survos/grist-bundle`](https://github.com/survos/grist-bundle) wires this into a Symfony
application: configuration, services, console commands, and agent tools. It contains no
Grist logic of its own; it is an adapter over this library.
