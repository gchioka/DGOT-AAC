<?php
/**
 * Status Controller — dados públicos do servidor
 * Endpoint: GET /api/v1/server/status
 * Sem autenticação, sem rate limit agressivo.
 */

namespace App\Controller\Api;

use App\Model\Functions\Server as FunctionServer;

class Status extends Api
{
    /**
     * Retorna players online + IDs da criatura e boss boosted do dia.
     * Resposta consolidada em uma única chamada para evitar múltiplas
     * requests ao endpoint autenticado /api/v1/login.
     */
    public static function getServerStatus(): array
    {
        $boostedCreature = FunctionServer::getBoostedCreature();
        $boostedBoss     = FunctionServer::getBoostedBoss();

        return [
            'playersonline'        => (int) FunctionServer::getCountPlayersOnline(),
            'twitchstreams'        => 0,
            'twitchviewer'         => 0,
            'gamingyoutubestreams' => 0,
            'gamingyoutubeviewer'  => 0,
            'creatureraceid'       => (int) $boostedCreature['raceid'],
            'bossraceid'           => (int) $boostedBoss['raceid'],
        ];
    }
}
