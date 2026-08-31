<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\BookingResourceProvider;
use Bitrix24\SDK\Services\ServiceBuilder;
use RuntimeException;

final readonly class Bitrix24BookingResourceProvider implements BookingResourceProvider
{
    private const PAGE_SIZE = 50;

    public function __construct(private ServiceBuilder $b24)
    {
    }

    public function listResources(): array
    {
        $resourcesById = [];
        $start = 0;

        do {
            $result = $this->b24->core
                ->call('booking.v1.resource.list', [
                    'filter' => [],
                    'order' => ['name' => 'asc', 'id' => 'asc'],
                    'start' => $start,
                ])
                ->getResponseData()
                ->getResult();

            $page = $result['resource'] ?? null;
            if (!is_array($page)) {
                throw new RuntimeException('Bitrix24 returned an invalid booking resource list');
            }

            $newItems = 0;
            foreach ($page as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                $name = trim((string) ($item['name'] ?? ''));
                if ($id === false || $name === '') {
                    continue;
                }

                $id = (int) $id;
                if (!isset($resourcesById[$id])) {
                    $newItems++;
                }
                $resourcesById[$id] = ['id' => $id, 'name' => $name];
            }

            $pageSize = count($page);
            $start += $pageSize;
        } while ($pageSize === self::PAGE_SIZE && $newItems > 0);

        $resources = array_values($resourcesById);
        usort($resources, static function (array $left, array $right): int {
            $byName = strnatcasecmp($left['name'], $right['name']);

            return $byName !== 0 ? $byName : $left['id'] <=> $right['id'];
        });

        return $resources;
    }
}
