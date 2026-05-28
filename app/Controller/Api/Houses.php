<?php
namespace App\Controller\Api;

use App\DatabaseManager\Database;

class Houses extends Api {

    public static function getHouses($request)
    {
        $q = $request->getQueryParams();

        $type   = strtolower($q['type']   ?? 'houses');
        $townId = isset($q['town']) && is_numeric($q['town']) ? (int)$q['town'] : null;
        $status = strtolower($q['status'] ?? 'all');

        $where  = [];
        $params = [];

        // Guildhalls are identified by name pattern (no dedicated DB flag)
        if ($type === 'guildhalls') {
            $where[] = "(h.name LIKE '%Guildhall%' OR h.name LIKE '%Clanhall%' OR h.name LIKE '%Headquarter%')";
        } else {
            $where[] = "NOT (h.name LIKE '%Guildhall%' OR h.name LIKE '%Clanhall%' OR h.name LIKE '%Headquarter%')";
        }

        if ($townId !== null) {
            $where[]  = 'h.town_id = ?';
            $params[] = $townId;
        } else {
            $where[] = 'h.town_id > 0';
        }

        if ($status === 'available') {
            $where[] = 'h.owner = 0 AND h.bidder = 0';
        } elseif ($status === 'rented') {
            $where[] = 'h.owner > 0';
        } elseif ($status === 'auctioned') {
            $where[] = 'h.bidder > 0';
        }

        $whereStr = 'WHERE ' . implode(' AND ', $where);

        $sql = "
            SELECT
                h.id,
                h.name,
                h.town_id,
                t.name        AS town_name,
                h.size,
                h.beds,
                h.rent,
                h.owner,
                h.bidder,
                h.bidder_name,
                h.highest_bid,
                h.bid_end_date,
                h.guildid,
                p.name        AS owner_name
            FROM houses h
            LEFT JOIN towns t ON t.id = h.town_id
            LEFT JOIN players p ON p.id = h.owner AND h.owner > 0
            {$whereStr}
            ORDER BY t.name ASC, h.name ASC
        ";

        $db   = new Database('houses');
        $stmt = $db->execute($sql, $params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $houses = [];
        foreach ($rows as $row) {
            if ((int)$row['owner'] > 0) {
                $hStatus = 'Rented';
            } elseif ((int)$row['bidder'] > 0) {
                $hStatus = 'Auctioned';
            } else {
                $hStatus = 'Available';
            }

            $houses[] = [
                'id'          => (int)$row['id'],
                'name'        => $row['name'],
                'town'        => $row['town_name'] ?? 'Unknown',
                'town_id'     => (int)$row['town_id'],
                'size'        => (int)$row['size'],
                'beds'        => (int)$row['beds'],
                'rent'        => (int)$row['rent'],
                'bid'         => (int)$row['highest_bid'],
                'bid_end'     => (int)$row['bid_end_date'],
                'status'      => $hStatus,
                'owner'       => $row['owner_name'] ?? null,
                'bidder_name' => $row['bidder_name'] ?: null,
            ];
        }

        // Also return towns that have houses for frontend filtering
        $townSql = "
            SELECT DISTINCT h.town_id AS id, t.name
            FROM houses h
            LEFT JOIN towns t ON t.id = h.town_id
            WHERE h.town_id > 0
            ORDER BY t.name ASC
        ";
        $townRows = $db->execute($townSql, [])->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'houses' => $houses,
            'total'  => count($houses),
            'towns'  => $townRows,
        ];
    }
}
