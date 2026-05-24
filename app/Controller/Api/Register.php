<?php
/**
 * Register Controller — criação de conta via JSON API
 * Endpoint: POST /api/v1/register
 * Protegido por Cloudflare Turnstile + rate limit nginx.
 */

namespace App\Controller\Api;

use App\Model\Entity\Worlds as EntityWorlds;
use App\Model\Entity\CreateAccount as EntityCreateAccount;
use App\Model\Entity\ServerConfig as EntityServerConfig;
use App\Model\Entity\Player as EntityPlayer;
use App\Utils\Argon;

class Register extends Api
{
    private static function sendError(string $message, int $code = 2): array
    {
        return ['errorCode' => $code, 'errorMessage' => $message];
    }

    /**
     * Verifica token do Cloudflare Turnstile via siteverify API.
     * Usa cURL para controle completo sobre connection timeout e read timeout.
     * file_get_contents com HTTPS pode travar na negociação TLS sem connection timeout.
     */
    private static function verifyTurnstile(string $token, string $remoteIp): bool
    {
        $secret = $_ENV['TURNSTILE_SECRET_KEY'] ?? '';

        // If no secret configured, skip Turnstile (nginx rate limiting is sufficient)
        if (empty($secret)) {
            return true;
        }
        if (empty($token)) {
            error_log('[Register] Turnstile blocked: secret=' . (empty($secret) ? 'MISSING' : 'OK') . ' token=' . (empty($token) ? 'EMPTY' : 'OK'));
            return false;
        }

        $postData = http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $remoteIp,
        ]);

        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $resp     = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $httpCode !== 200) {
            error_log('[Register] Turnstile cURL failed: err=' . $curlErr . ' http=' . $httpCode);
            return false;
        }

        $data = json_decode($resp, true);

        if (!is_array($data)) {
            error_log('[Register] Turnstile invalid JSON: ' . $resp);
            return false;
        }

        $success = ($data['success'] ?? false) === true;

        if (!$success) {
            error_log('[Register] Turnstile rejected: ' . json_encode($data));
        }

        return $success;
    }

    /**
     * Cria conta + personagem inicial.
     * JSON esperado: {accname, email, password1, password2, name, sex, vocation, world, cf_turnstile_response}
     */
    public static function createAccount(array $postVars, string $remoteIp): array
    {
        // 1. Verificacao Turnstile
        $cfToken = $postVars['cf_turnstile_response'] ?? '';
        if (!self::verifyTurnstile($cfToken, $remoteIp)) {
            return self::sendError('Security check failed. Please try again.', 2);
        }

        // 2. Sanitizacao
        $accName   = trim($postVars['accname']   ?? '');
        $email     = trim($postVars['email']     ?? '');
        $password1 = $postVars['password1']      ?? '';
        $password2 = $postVars['password2']      ?? '';
        $charName  = trim($postVars['name']      ?? '');
        $sex       = (int)($postVars['sex']      ?? -1);
        $vocation  = $postVars['vocation']       ?? '';
        $worldRaw  = $postVars['world']          ?? '';
        $fullName  = trim($postVars['full_name']  ?? '');
        $birthdateStr = trim($postVars['birthdate'] ?? '');

        // 3. Validacoes
        if (empty($accName)) {
            return self::sendError('Account name is required.');
        }
        if (strlen($accName) < 4 || strlen($accName) > 32) {
            return self::sendError('Account name must be between 4 and 32 characters.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return self::sendError('Invalid email address.');
        }
        if ($password1 !== $password2) {
            return self::sendError('Passwords do not match.');
        }
        if (strlen($password1) < 8) {
            return self::sendError('Password must be at least 8 characters.');
        }
        if (EntityPlayer::getAccount(['name' => $accName])->fetchObject()) {
            return self::sendError('Account name is already in use.');
        }
        if (EntityPlayer::getAccount(['email' => $email])->fetchObject()) {
            return self::sendError('Email address is already in use.');
        }
        if (empty($charName)) {
            return self::sendError('Character name is required.');
        }
        if (strlen($charName) < 5 || strlen($charName) > 29) {
            return self::sendError('Character name must be between 5 and 29 characters.');
        }
        if (EntityPlayer::getPlayer(['name' => $charName])->fetchObject()) {
            return self::sendError('Character name is already in use.');
        }
        if ($sex < 0 || $sex > 1) {
            return self::sendError('Please select a gender.');
        }

        // 4. Mundo
        $worldName = str_replace('server_', '', $worldRaw);
        $world = EntityWorlds::getWorlds(['name' => $worldName])->fetchObject();
        if (!$world) {
            return self::sendError('Please select a valid world.');
        }

        // 5. Vocacao
        $activeVocations = EntityServerConfig::getInfoWebsite()->fetchObject();
        if ($activeVocations && $activeVocations->player_voc == 1) {
            if (empty($vocation)) {
                return self::sendError('Please select a vocation.');
            }
            if (!EntityCreateAccount::getPlayerSamples(['vocation' => $vocation])->fetchObject()) {
                return self::sendError('Invalid vocation selected.');
            }
            $filterVocation = (int)$vocation;
        } else {
            $filterVocation = 0;
        }

        // 5b. Validacao nome e data de nascimento
        if (empty($fullName) || strlen($fullName) < 2 || strlen($fullName) > 100) {
            return self::sendError('Por favor, informe seu nome completo (2 a 100 caracteres).');
        }
        $birthdate = \DateTime::createFromFormat('Y-m-d', $birthdateStr);
        if (!$birthdate || $birthdate->format('Y-m-d') !== $birthdateStr) {
            return self::sendError('Data de nascimento invalida.');
        }
        $age = (new \DateTime())->diff($birthdate)->y;
        if ($age < 16) {
            return self::sendError('Voce precisa ter pelo menos 16 anos para se cadastrar.');
        }
        if ($age > 120) {
            return self::sendError('Data de nascimento invalida.');
        }

        // 6. Cria conta
        $hashedPassword = Argon::generateArgonPassword($password1);

        $accountId = EntityCreateAccount::createAccount([
            'name'        => $accName,
            'password'    => $hashedPassword,
            'email'       => $email,
            'page_access' => 0,
            'premdays'    => 0,
            'type'        => 1,
            'coins'       => 0,
            'recruiter'   => 0,
            'full_name'   => $fullName,
            'birthdate'   => $birthdate->format('Y-m-d'),
        ]);

        if (!$accountId) {
            error_log('[Register] Failed to create account for: ' . $accName);
            return self::sendError('Failed to create account. Please try again.');
        }

        // 7. Sample do personagem
        $sample = EntityCreateAccount::getPlayerSamples(['vocation' => $filterVocation])->fetchObject();

        if (!$sample) {
            error_log('[Register] No sample for vocation: ' . $filterVocation);
            (new \App\DatabaseManager\Database('accounts'))->delete(['id' => $accountId]);
            return self::sendError('Server configuration error. Please contact support.');
        }

        // 8. Cria personagem
        EntityCreateAccount::createCharacter([
            'name'       => $charName,
            'group_id'   => 1,
            'account_id' => $accountId,
            'main'       => 1,
            'level'      => $sample->level,
            'vocation'   => $sample->vocation,
            'health'     => $sample->health,
            'healthmax'  => $sample->healthmax,
            'experience' => $sample->experience,
            'lookbody'   => $sample->lookbody,
            'lookfeet'   => $sample->lookfeet,
            'lookhead'   => $sample->lookhead,
            'looklegs'   => $sample->looklegs,
            'looktype'   => $sample->looktype,
            'lookaddons' => $sample->lookaddons,
            'maglevel'   => $sample->maglevel,
            'mana'       => $sample->mana,
            'manamax'    => $sample->manamax,
            'manaspent'  => $sample->manaspent,
            'soul'       => $sample->soul,
            // Town selection: 9=Thais 11=Ankrahmun
            'town_id'    => in_array((int)($postVars['town'] ?? 9), [9,11]) ? (int)($postVars['town'] ?? 9) : 9,
            'world'      => $world->id,
            'posx'       => $sample->posx,
            'posy'       => $sample->posy,
            'posz'       => $sample->posz,
            'cap'        => $sample->cap,
            'sex'        => $sex,
            'balance'    => $sample->balance,
            'istutorial' => 1,
        ]);

        return [
            'success'   => true,
            'account'   => $accName,
            'character' => $charName,
            'message'   => 'Account created successfully! You can now log in.',
        ];
    }
}
