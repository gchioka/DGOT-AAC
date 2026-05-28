<?php
namespace App\Controller\Api;

class Items extends Api {

    private const DATA_FILE = '/var/www/canaryaac/data/items.json';

    public static function handle($request): array {
        $q        = $request->getQueryParams();
        $category = trim($q['category'] ?? '');
        $search   = trim($q['search']   ?? '');
        $page     = max(1, (int)($q['page']     ?? 1));
        $perPage  = min(100, max(1, (int)($q['per_page'] ?? 24)));

        if (!file_exists(self::DATA_FILE)) {
            return ['errorCode' => 404, 'errorMessage' => 'Items data not found.'];
        }

        $all = json_decode(file_get_contents(self::DATA_FILE), true);
        if (!$all) {
            return ['errorCode' => 500, 'errorMessage' => 'Failed to decode items data.'];
        }

        // Distinct categories with counts
        $catCounts = [];
        foreach ($all as $item) {
            $cat = $item['category'] ?? '';
            if ($cat !== '') $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
        }
        ksort($catCounts);

        // Category filter
        if ($category !== '') {
            $all = array_values(array_filter($all, fn($i) => ($i['category'] ?? '') === $category));
        }

        // Search filter
        if ($search !== '') {
            $s = strtolower($search);
            $all = array_values(array_filter($all, fn($i) => strpos(strtolower($i['name'] ?? ''), $s) !== false));
        }

        $total  = count($all);
        $pages  = max(1, (int)ceil($total / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $slice  = array_slice($all, $offset, $perPage);

        return [
            'items'      => $slice,
            'categories' => $catCounts,
            'pagination' => [
                'current'  => $page,
                'total'    => $pages,
                'per_page' => $perPage,
                'count'    => $total,
            ],
        ];
    }
}
