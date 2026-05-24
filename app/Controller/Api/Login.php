<?php
/**
 * Login Class — security-hardened version
 * - session token: bin2hex(random_bytes(32)) stored in account_sessions
 * - password NEVER returned to client
 */

namespace App\Controller\Api;

use App\Model\Entity\Account as EntityAccount;
use App\Model\Entity\Bans;
use App\Controller\Admin\Compendium as EntityCompendium;
use App\Model\Entity\Player as EntityPlayer;
use App\Model\Entity\ServerConfig;
use App\Model\Functions\EventSchedule;
use App\Model\Functions\Player as FunctionPlayer;
use App\Model\Functions\Server as FunctionServer;
use PragmaRX\Google2FA\Google2FA;
use App\Utils\Argon;
use App\DatabaseManager\Database;

// Token válido por 24 horas
define('SESSION_DURATION', 3600);

class Login extends Api
{
    public static function sendError($message, $code = 3): array
    {
        return [
            'errorCode'    => $code,
            'errorMessage' => $message,
        ];
    }

    /** Gera token seguro e salva em account_sessions */
    private static function createSessionToken(int $accountId): string
    {
        // Remove sessões expiradas deste account antes de criar nova
        (new Database('account_sessions'))
            ->delete("account_id = $accountId AND expires < " . time());

        $token   = bin2hex(random_bytes(32)); // 64 chars hex, criptograficamente seguro
        $expires = time() + SESSION_DURATION;

        // Canary game server looks up SHA1(sessionKey) in account_sessions
        EntityPlayer::insertSessions([
            'id'         => sha1($token),
            'account_id' => $accountId,
            'expires'    => $expires,
        ]);

        return $token;
    }

    /** Valida token — retorna account_id ou null se inválido/expirado */
    public static function validateToken(string $token): ?int
    {
        if (empty($token) || strlen($token) !== 64) {
            return null;
        }
        $now = time();
        $safeToken = preg_replace('/[^a-f0-9]/', '', $token);
        if (strlen($safeToken) !== 64) {
            return null;
        }
        $hashedToken = sha1($safeToken);
        $row = (new Database('account_sessions'))
            ->select('account_id, expires', "id = '$hashedToken' AND expires > $now")
            ->fetchObject();

        return $row ? (int)$row->account_id : null;
    }

    public static function selectAccount($request)
    {
        $postVars     = $request->getPostVars();
        $request_type = $postVars['type'] ?? '';

        if (empty($request_type)) {
            return 'You are trying to access an unauthorized page.';
        }

        switch ($request_type) {

            /* dados publicos (sem autenticacao) */

            case 'cacheinfo':
                return [
                    'playersonline'          => (int)FunctionServer::getCountPlayersOnline(),
                    'twitchstreams'          => 0,
                    'twitchviewer'           => 0,
                    'gamingyoutubestreams'   => 0,
                    'gamingyoutubeviewer'    => 0,
                ];

            case 'boostedcreature':
                $boostedCreature = FunctionServer::getBoostedCreature();
                $boostedBoss     = FunctionServer::getBoostedBoss();
                return [
                    'creatureraceid' => (int)$boostedCreature['raceid'],
                    'bossraceid'     => (int)$boostedBoss['raceid'],
                ];

            case 'eventschedule':
                return EventSchedule::getServerEvents();

            case 'news':
                return EntityCompendium::loadJsonCompendium();

            /* login */

            case 'login':
                $email    = trim($postVars['email']    ?? '');
                $password = $postVars['password'] ?? '';

                if (empty($email) || empty($password)) {
                    return self::sendError('Email and password are required.', 3);
                }

                $account = EntityAccount::getAccount(['email' => $email])->fetchObject();
                if (empty($account)) {
                    return self::sendError('Email or password is not correct.', 3);
                }

                if (!Argon::checkPassword($password, $account->password, $account->id)) {
                    return self::sendError('Email or password is not correct.', 3);
                }

                // 2FA
                $authentication = EntityAccount::getAuthentication(['account_id' => $account->id])->fetchObject();
                if (!empty($authentication) && $authentication->status == 1) {
                    $totp = $postVars['token'] ?? '';
                    if (empty($totp)) {
                        return self::sendError('Two-factor token required.', 6);
                    }
                    $google2fa = new Google2FA();
                    if (!$google2fa->verifyKey($authentication->secret, $totp)) {
                        return self::sendError('Invalid two-factor token.', 6);
                    }
                }

                // Banimento
                $ban = Bans::getAccountBans(['account_id' => $account->id])->fetchObject();
                if (!empty($ban)) {
                    $expires_at = date('M d Y', $ban->expires_at);
                    $banned_by  = EntityPlayer::getPlayer(['id' => $ban->banned_by])->fetchObject();
                    return self::sendError(
                        'Your account has been banned until ' . $expires_at . ' by ' . ($banned_by->name ?? 'Admin'),
                        3
                    );
                }

                // Gera token seguro — senha NUNCA retorna ao cliente
                $sessionToken = self::createSessionToken((int)$account->id);

                // Mundos
                $arrayWorlds  = [];
                $worlds = ServerConfig::getWorlds();
                while ($world = $worlds->fetchObject()) {
                    $arrayWorlds[] = [
                        'id'                            => (int)$world->id,
                        'name'                          => $world->name,
                        'externaladdress'               => $world->ip,
                        'externaladdressprotected'      => $world->ip,
                        'externaladdressunprotected'    => $world->ip,
                        'externalport'                  => (int)$world->port,
                        'externalportprotected'        => (int)$world->port,
                        'externalportunprotected'      => (int)$world->port,
                        'pvptype'                       => 0,
                        'location'                      => FunctionServer::convertLocation($world->location),
                        'previewstate'                  => 0,
                        'anticheatprotection'           => false,
                        'istournamentworld'             => false,
                        'restrictedstore'               => false,
                    ];
                }

                // Personagens
                $arrayPlayers = [];
                $characters   = EntityPlayer::getPlayer(['account_id' => $account->id, 'deletion' => 0]);
                while ($character = $characters->fetchObject()) {
                    $display = EntityPlayer::getDisplay(['player_id' => $character->id])->fetchObject();
                    $arrayPlayers[] = [
                        'worldid'           => (int)$character->world,
                        'name'              => $character->name,
                        'ismale'            => (int)$character->sex,
                        'tutorial'          => false,
                        'level'             => (int)$character->level,
                        'vocation'          => FunctionPlayer::convertVocation($character->vocation),
                        'outfitid'          => (int)$character->looktype,
                        'headcolor'         => (int)$character->lookhead,
                        'torsocolor'        => (int)$character->lookbody,
                        'legscolor'         => (int)$character->looklegs,
                        'detailcolor'       => (int)$character->lookfeet,
                        'addonsflags'       => (int)$character->lookaddons,
                        'ishidden'          => !empty($display) && $display->account == 1,
                        'ismaincharacter'   => $character->main == 1,
                        'dailyrewardstate'  => (int)$character->isreward,
                    ];
                }

                return [
                    'account' => [
                        'email'    => $account->email,
                        'creation' => $account->creation,
                    ],
                    'playdata' => [
                        'worlds'     => $arrayWorlds,
                        'characters' => $arrayPlayers,
                    ],
                    'session' => [
                        'sessionkey'   => $sessionToken,
                        'ispremium'    => (bool)($account->premdays > 0),
                        'premiumuntil' => $account->premdays > 0 ? time() + ($account->premdays * 86400) : 0,
                        'status'       => 'active',
                        'expires_in'   => SESSION_DURATION,
                    ],
                ];

            /* validate — verifica token e renova por mais 1h (sliding window) */

            case 'validate':
                $token = $postVars['token'] ?? '';
                $safeToken = preg_replace('/[^a-f0-9]/', '', $token);
                if (strlen($safeToken) !== 64) {
                    http_response_code(401);
                    return self::sendError('Invalid session.', 1);
                }
                $now = time();
                $row = (new Database('account_sessions'))
                    ->select('account_id, expires', "id = '$safeToken' AND expires > $now")
                    ->fetchObject();
                if (!$row) {
                    http_response_code(401);
                    return self::sendError('Session expired.', 1);
                }
                // Renova: deleta e reinsere com novo expires (+1h)
                (new Database('account_sessions'))->delete("id = '$safeToken'");
                EntityPlayer::insertSessions([
                    'id'         => $safeToken,
                    'account_id' => (int)$row->account_id,
                    'expires'    => $now + SESSION_DURATION,
                ]);
                return ['valid' => true, 'expires_in' => SESSION_DURATION];

            /* logout — invalida token no banco */

            case 'logout':
                $token = $postVars['token'] ?? '';
                if (!empty($token)) {
                    $safeToken = preg_replace('/[^a-f0-9]/', '', $token);
                    if (strlen($safeToken) === 64) {
                        (new Database('account_sessions'))->delete("id = '" . sha1($safeToken) . "'");
                    }
                }
                return ['status' => 'logged_out'];

            default:
                return self::sendError("Unrecognized type: {$request_type}.", 3);
        }
    }

    public static function getLogin($request): array|string|null
    {
        return self::selectAccount($request);
    }
}
