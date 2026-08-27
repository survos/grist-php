<?php

declare(strict_types=1);

namespace Survos\Grist\Service;

use Survos\Grist\Model\FormBlueprint;
use Survos\Grist\Model\FormDefinition;
use Survos\Grist\Contract\GristClientInterface;

final readonly class GristFormManager
{
    public function __construct(private GristApplicationLocator $applications)
    {
    }

    /** @return list<FormDefinition> */
    public function list(string $application): array
    {
        [$app,$client] = $this->applications->locate($application);
        $tables = [];
        foreach ($client->tables($app->id) as $table) {
            if (is_string($table['id'] ?? null) && is_int($table['fields']['tableRef'] ?? null)) {
                $tables[$table['fields']['tableRef']] = $table['id'];
            }
        }
        $views = $this->metadata($client, $app->id, '_grist_Views');
        $result = [];
        foreach ($this->metadata($client, $app->id, '_grist_Views_section') as $section) {
            $f = $section['fields'];
            if ('form' !== ($f['parentKey'] ?? null) || !is_int($f['tableRef'] ?? null) || !isset($tables[$f['tableRef']])) {
                continue;
            }
            $view = (int) ($f['parentId'] ?? 0);
            $share = json_decode(is_string($f['shareOptions'] ?? null) ? $f['shareOptions'] : '{}', true);
            $result[] = new FormDefinition($application, $app->id, $tables[$f['tableRef']], (string) ($views[$view]['fields']['name'] ?? $f['title'] ?? 'Form'), $view, (int) $section['id'], [], true === ($share['publish'] ?? false), null);
        }

        return $result;
    }

    public function upsert(FormBlueprint $blueprint): FormDefinition
    {
        [$app,$client] = $this->applications->locate($blueprint->application);
        $table = $app->table($blueprint->table);
        $tableRef = $this->tableRef($client, $app->id, $table->id);
        $match = static fn (array $r): bool => 'form' === ($r['fields']['parentKey'] ?? null) && $tableRef === ($r['fields']['tableRef'] ?? null);
        $section = array_find($this->metadata($client, $app->id, '_grist_Views_section'), $match);
        if (null === $section) {
            $this->apply($client, $app->id, [['CreateViewSection', $tableRef, 0, 'form', null, $blueprint->title]]);
            $section = array_find($this->metadata($client, $app->id, '_grist_Views_section'), $match);
        }
        if (null === $section) {
            throw new \RuntimeException('Grist did not create the form section.');
        }
        $sectionId = (int) $section['id'];
        $viewId = (int) $section['fields']['parentId'];
        $columnRefs = [];
        foreach ($client->columns($app->id, $table->id) as $column) {
            if (is_string($column['id'] ?? null) && is_int($column['fields']['colRef'] ?? null)) {
                $columnRefs[$column['id']] = $column['fields']['colRef'];
            }
        }
        $fields = array_map(static fn (string $f): string => (string) $table->remoteField($f), $blueprint->fields);
        $unknown = array_diff($fields, array_keys($columnRefs));
        if ([] !== $unknown) {
            throw new \InvalidArgumentException('Unknown Grist fields: '.implode(', ', $unknown));
        }
        $records = $this->sectionFields($client, $app->id, $sectionId, $columnRefs);
        $actions = [];
        foreach ($fields as $field) {
            if (!isset($records[$field])) {
                $actions[] = ['AddRecord', '_grist_Views_section_field', null, ['parentId' => $sectionId, 'colRef' => $columnRefs[$field]]];
            }
        }
        if ([] !== $actions) {
            $this->apply($client, $app->id, $actions);
            $records = $this->sectionFields($client, $app->id, $sectionId, $columnRefs);
        }
        $children = array_map(static fn (string $f): array => ['type' => 'Field', 'id' => 'field-'.$records[$f]['id'], 'leaf' => (int) $records[$f]['id']], $fields);
        $layout = ['type' => 'Layout', 'id' => 'layout-'.$sectionId, 'children' => [
            ['type' => 'Paragraph', 'id' => 'heading-'.$sectionId, 'text' => '# '.$blueprint->title],
            ['type' => 'Paragraph', 'id' => 'intro-'.$sectionId, 'text' => $blueprint->intro],
            ['type' => 'Section', 'id' => 'fields-'.$sectionId, 'children' => $children],
            ['type' => 'Submit', 'id' => 'submit-'.$sectionId, 'text' => $blueprint->submitLabel],
        ]];
        $this->apply($client, $app->id, [['UpdateRecord', '_grist_Views', $viewId, ['name' => $blueprint->title]], ['UpdateRecord', '_grist_Views_section', $sectionId, ['title' => $blueprint->title, 'layoutSpec' => json_encode($layout, JSON_THROW_ON_ERROR), 'shareOptions' => json_encode(['publish' => $blueprint->publish, 'form' => new \stdClass()], JSON_THROW_ON_ERROR)]]]);
        $link = $blueprint->linkId;
        if ($blueprint->publish) {
            $link ??= $this->slug($blueprint->title);
            $this->publish($client, $app->id, $viewId, $link, $blueprint->title);
        }

        return new FormDefinition($blueprint->application, $app->id, $table->id, $blueprint->title, $viewId, $sectionId, $fields, $blueprint->publish, $link);
    }

    /** @return array<int,array{id:int,fields:array<string,mixed>}> */
    private function metadata(GristClientInterface $client, string $doc, string $table): array
    {
        $response = $client->request('GET', sprintf('docs/%s/tables/%s/records?limit=500', rawurlencode($doc), rawurlencode($table)));
        $result = [];
        foreach ($response['records'] ?? [] as $record) {
            if (is_array($record) && is_int($record['id'] ?? null) && is_array($record['fields'] ?? null)) {
                $result[$record['id']] = $record;
            }
        }

        return $result;
    }

    private function tableRef(GristClientInterface $client, string $doc, string $id): int
    {
        foreach ($client->tables($doc) as $table) {
            if ($id === ($table['id'] ?? null) && is_int($table['fields']['tableRef'] ?? null)) {
                return $table['fields']['tableRef'];
            }
        }

        throw new \InvalidArgumentException('Grist table not found: '.$id);
    }

    /** @param array<string,int> $refs @return array<string,array{id:int,fields:array<string,mixed>}> */
    private function sectionFields(GristClientInterface $client, string $doc, int $section, array $refs): array
    {
        $columns = array_flip($refs);
        $result = [];
        foreach ($this->metadata($client, $doc, '_grist_Views_section_field') as $record) {
            if ($section === ($record['fields']['parentId'] ?? null) && isset($columns[$record['fields']['colRef'] ?? null])) {
                $result[$columns[$record['fields']['colRef']]] = $record;
            }
        }

        return $result;
    }

    /** @param list<array<mixed>> $actions */
    private function apply(GristClientInterface $client, string $doc, array $actions): void
    {
        $client->request('POST', sprintf('docs/%s/apply', rawurlencode($doc)), ['json' => $actions]);
    }

    private function publish(GristClientInterface $client, string $doc, int $view, string $link, string $label): void
    {
        $share = array_find($this->metadata($client, $doc, '_grist_Shares'), static fn (array $r): bool => $link === ($r['fields']['linkId'] ?? null));
        if (null === $share) {
            $this->apply($client, $doc, [['AddRecord', '_grist_Shares', null, ['linkId' => $link, 'options' => json_encode(['publish' => true], JSON_THROW_ON_ERROR), 'label' => $label]]]);
            $share = array_find($this->metadata($client, $doc, '_grist_Shares'), static fn (array $r): bool => $link === ($r['fields']['linkId'] ?? null));
        }
        $page = array_find($this->metadata($client, $doc, '_grist_Pages'), static fn (array $r): bool => $view === ($r['fields']['viewRef'] ?? null));
        if (null === $share || null === $page) {
            throw new \RuntimeException('Unable to publish Grist form.');
        }
        $this->apply($client, $doc, [['UpdateRecord', '_grist_Pages', (int) $page['id'], ['shareRef' => (int) $share['id']]]]);
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));

        return '' !== $slug ? $slug : 'form';
    }
}
