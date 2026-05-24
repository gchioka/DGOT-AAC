<?php
namespace App\Controller\Api;

use App\Model\Entity\Account as EntityAccount;
use App\Model\Entity\CreateAccount as EntityCreateAccount;
use App\Model\Entity\Player as EntityPlayer;
use App\Model\Entity\Worlds as EntityWorlds;
use App\DatabaseManager\Database;
use App\Utils\Argon;

class Account extends Api
{
    private static function err(string $msg, int $code = 3): array
    {
        return ['errorCode' => $code, 'errorMessage' => $msg];
    }

    private static function auth(string $token): ?object
    {
        $accountId = Login::validateToken($token);
        if (!$accountId) return null;
        return EntityAccount::getAccount(['id' => $accountId])->fetchObject() ?: null;
    }

    public static function handle($request): array
    {
        $vars   = $request->getPostVars();
        $action = $vars['action'] ?? '';
        $token  = $vars['token']  ?? '';
        if (empty($token)) return self::err('Authentication required.', 1);
        switch ($action) {
            case 'changepassword':  return self::changePassword($vars, $token);
            case 'changeemail':     return self::changeEmail($vars, $token);
            case 'changemain':      return self::changeMain($vars, $token);
            case 'createcharacter': return self::createCharacter($vars, $token);
            case 'deletecharacter': return self::deleteCharacter($vars, $token);
            default: return self::err("Unknown action: {$action}");
        }
    }

    private static function changePassword(array $v, string $token): array
    {
        $account = self::auth($token);
        if (!$account) return self::err('Session expired.', 1);
        $old  = $v['oldpassword']  ?? '';
        $new1 = $v['newpassword']  ?? '';
        $new2 = $v['newpassword2'] ?? '';
        if (!$old || !$new1 || !$new2) return self::err('All fields are required.');
        if (!Argon::checkPassword($old, $account->password, $account->id))
                                       return self::err('Current password is incorrect.');
        if ($new1 !== $new2)           return self::err('New passwords do not match.');
        if (strlen($new1) < 8)         return self::err('Password must be at least 8 characters.');
        EntityAccount::updateAccount(['id' => $account->id], ['password' => Argon::generateArgonPassword($new1)]);
        return ['success' => true];
    }

    private static function changeEmail(array $v, string $token): array
    {
        $account = self::auth($token);
        if (!$account) return self::err('Session expired.', 1);
        $new1 = trim($v['newemail']  ?? '');
        $new2 = trim($v['newemail2'] ?? '');
        $pass = $v['password'] ?? '';
        if (!$new1 || !$new2 || !$pass) return self::err('All fields are required.');
        if (!filter_var($new1, FILTER_VALIDATE_EMAIL)) return self::err('Invalid email address.');
        if ($new1 !== $new2) return self::err('Email addresses do not match.');
        if (!Argon::checkPassword($pass, $account->password, $account->id))
                             return self::err('Password is incorrect.');
        $existing = EntityAccount::getAccount(['email' => $new1])->fetchObject();
        if ($existing && (int)$existing->id !== (int)$account->id)
                             return self::err('Email address is already in use.');
        EntityAccount::updateAccount(['id' => $account->id], ['email' => $new1]);
        return ['success' => true, 'email' => $new1];
    }

    private static function changeMain(array $v, string $token): array
    {
        $account = self::auth($token);
        if (!$account) return self::err('Session expired.', 1);
        $charName = trim($v['character'] ?? '');
        $pass     = $v['password'] ?? '';
        if (!$charName || !$pass) return self::err('All fields are required.');
        if (!Argon::checkPassword($pass, $account->password, $account->id))
                                  return self::err('Password is incorrect.');
        $char = EntityPlayer::getPlayer(['name' => $charName])->fetchObject();
        if (!$char || (int)$char->account_id !== (int)$account->id)
                                  return self::err('Character not found on this account.');
        (new Database('players'))->update(
            "account_id = {$account->id} AND deletion = 0",
            ['main' => 0]
        );
        (new Database('players'))->update(['id' => $char->id], ['main' => 1]);
        return ['success' => true, 'character' => $charName];
    }

    private static function createCharacter(array $v, string $token): array
    {
        $account = self::auth($token);
        if (!$account) return self::err('Session expired.', 1);
        $name  = trim($v['name']  ?? '');
        $sex   = (int)($v['sex']  ?? -1);
        $voc   = $v['vocation']   ?? '';
        $world = trim($v['world'] ?? '');
        if (!$name) return self::err('Character name is required.');
        if (strlen($name) < 5 || strlen($name) > 29)
                    return self::err('Name must be 5 to 29 characters.');
        if (!preg_match('/^[A-Za-z][A-Za-z ]*$/', $name))
                    return self::err('Name may only contain letters and spaces.');
        if ($sex < 0 || $sex > 1) return self::err('Please select a gender.');
        if (EntityPlayer::getPlayer(['name' => $name])->fetchObject())
                    return self::err('Character name is already in use.');
        $worldObj = EntityWorlds::getWorlds(['name' => $world])->fetchObject();
        if (!$worldObj) return self::err('Invalid world selected.');
        $filterVoc = (int)$voc;
        $sample = EntityCreateAccount::getPlayerSamples(['vocation' => $filterVoc])->fetchObject();
        if (!$sample) return self::err('Invalid vocation selected.');
        EntityCreateAccount::createCharacter([
            'name'       => $name,
            'group_id'   => 1,
            'account_id' => $account->id,
            'main'       => 0,
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
            // Town selection: 8=Thais 13=Darashia
            'town_id'    => in_array((int)($v['town'] ?? 8), [8,13]) ? (int)($v['town'] ?? 8) : 8,
            'world'      => $worldObj->id,
            'posx'       => $sample->posx,
            'posy'       => $sample->posy,
            'posz'       => $sample->posz,
            'cap'        => $sample->cap,
            'sex'        => $sex,
            'balance'    => $sample->balance,
            'istutorial' => 1,
        ]);
        return ['success' => true, 'character' => $name];
    }

    private static function deleteCharacter(array $v, string $token): array
    {
        $account = self::auth($token);
        if (!$account) return self::err('Session expired.', 1);
        $charName = trim($v['character'] ?? '');
        $pass     = $v['password'] ?? '';
        if (!$charName || !$pass) return self::err('All fields are required.');
        if (!Argon::checkPassword($pass, $account->password, $account->id))
                                  return self::err('Password is incorrect.');
        $char = EntityPlayer::getPlayer(['name' => $charName])->fetchObject();
        if (!$char || (int)$char->account_id !== (int)$account->id)
                                  return self::err('Character not found on this account.');
        (new Database('players'))->update(['id' => $char->id], ['deletion' => time()]);
        if ($char->main == 1) {
            (new Database('players'))->execute(
                "UPDATE players SET main = 1 WHERE account_id = ? AND id != ? AND deletion = 0 ORDER BY id ASC LIMIT 1",
                [(int)$account->id, (int)$char->id]
            );
        }
        return ['success' => true];
    }
}
