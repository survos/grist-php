<?php

declare(strict_types=1);

namespace Survos\Grist\Service;

use Survos\Grist\Adapter\GristAdapterFactory;
use Survos\Grist\Contract\GristClientInterface;
use Survos\RecordStore\Model\ApplicationReference;
use Survos\RecordStore\Registry\RecordStoreRegistry;

final readonly class GristApplicationLocator
{
    public function __construct(private RecordStoreRegistry $registry, private GristAdapterFactory $factory)
    {
    }

    /** @return array{ApplicationReference,GristClientInterface} */
    public function locate(string $name): array
    {
        $app = $this->registry->application($name);
        $connection = $this->registry->connectionConfiguration($app->connection);
        if ('grist' !== strtolower($connection->driver)) {
            throw new \InvalidArgumentException(sprintf('Application "%s" does not use Grist.', $name));
        }

        return [$app, $this->factory->client($connection)];
    }
}
