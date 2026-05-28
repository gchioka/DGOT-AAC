<?php

namespace App\Controller\Api;

class Creatures extends Api {

    private const DATA_DIR = '/var/www/canaryaac/data/';

    public static function handle($request): array {
        $tag      = $_GET['tag']      ?? '';
        $type     = $_GET['type']     ?? 'creatures';
        $category = $_GET['category'] ?? '';
        $search   = trim($_GET['search'] ?? '');
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $perPage  = min(100, max(1, (int)($_GET['per_page'] ?? 24)));

        // Single creature lookup
        if ($tag !== '') {
            return self::getByTag($tag);
        }

        $file = $type === 'bosses'
            ? self::DATA_DIR . 'bosses.json'
            : self::DATA_DIR . 'creatures.json';

        if (!file_exists($file)) {
            return ['errorCode' => 404, 'errorMessage' => 'Data not found. Run parse_monsters.py first.'];
        }

        $all = json_decode(file_get_contents($file), true);
        if (!$all) {
            return ['errorCode' => 500, 'errorMessage' => 'Failed to decode data.'];
        }

        // Collect distinct categories (with counts) before filtering
        $catCounts = [];
        foreach ($all as $m) {
            $cls = $m['class'] ?? '';
            if ($cls !== '') {
                $catCounts[$cls] = ($catCounts[$cls] ?? 0) + 1;
            }
        }
        arsort($catCounts);

        // Apply category filter
        if ($category !== '') {
            $all = array_values(array_filter($all, function($m) use ($category) {
                return ($m['class'] ?? '') === $category;
            }));
        }

        // Apply search filter
        if ($search !== '') {
            $s = strtolower($search);
            $all = array_values(array_filter($all, function($m) use ($s) {
                return strpos(strtolower($m['name'] ?? ''), $s) !== false;
            }));
        }

        $total      = count($all);
        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
        $offset     = ($page - 1) * $perPage;
        $items      = array_slice($all, $offset, $perPage);

        return [
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
            'categories'  => $catCounts,
            'items'       => $items,
        ];
    }

    private static function getByTag(string $tag): array {
        // Search in both files
        foreach (['creatures.json', 'bosses.json'] as $filename) {
            $file = self::DATA_DIR . $filename;
            if (!file_exists($file)) continue;
            $all = json_decode(file_get_contents($file), true);
            if (!$all) continue;
            foreach ($all as $m) {
                if (($m['tag'] ?? '') === $tag || strtolower($m['name'] ?? '') === strtolower($tag)) {
                    return ['item' => $m];
                }
            }
        }
        return ['errorCode' => 404, 'errorMessage' => 'Creature not found.'];
    }
}
