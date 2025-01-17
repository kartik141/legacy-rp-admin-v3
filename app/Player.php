<?php

namespace App;

use App\Helpers\CacheHelper;
use App\Helpers\GeneralHelper;
use App\Http\Resources\BanResource;
use App\Http\Resources\CharacterResource;
use App\Http\Resources\PanelLogResource;
use App\Http\Resources\PlayerResource;
use App\Http\Resources\WarningResource;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use kanalumaddela\LaravelSteamLogin\SteamUser;
use SteamID;

/**
 * @package App
 */
class Player extends Model
{
    use HasFactory;

    /**
     * The link used for Steam's new invite code.
     */
    const STEAM_INVITE_URL = 'http://s.team/p/';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * Whether to use timestamps.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'user_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'steam_identifier',
        'player_name',
        'identifiers',
        'is_staff',
        'is_super_admin',
        'is_trusted',
        'is_panel_trusted',
        'is_debugger',
        'is_soft_banned',
        'playtime',
        'total_joins',
        'priority_level',
        'last_connection',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'identifiers'      => 'array',
        'last_connection'  => 'datetime',
        'is_trusted'       => 'boolean',
        'is_staff'         => 'boolean',
        'is_super_admin'   => 'boolean',
        'is_panel_trusted' => 'boolean',
        'is_debugger'      => 'boolean',
        'is_soft_banned'   => 'boolean',
        'playtime'         => 'integer',
        'total_joins'      => 'integer',
        'priority_level'   => 'integer',
    ];

    /**
     * @param string $player
     * @param Request $request
     * @return Player|array|null
     */
    public static function resolvePlayer(string $player, Request $request)
    {
        if (Str::startsWith($player, 'steam:1100002')) {
            $steam = str_replace('steam:1100002', 'steam:1100001', $player);

            $key = 'fake_' . $steam;

            $status = Player::getOnlineStatus($steam, false, true);

            if ($status && $status->fakeName && $status->character) {
                $resolved = Player::query()->select()->where('steam_identifier', '=', $steam)->first();

                if ($resolved) {
                    $characters = Character::query()->select()->where('character_id', '=', $status->character)->get();

                    $res = [
                        'id'              => $resolved->user_id,
                        'avatar'          => null,
                        'discord'         => null,
                        'steamIdentifier' => $player,
                        'overrideSteam'   => $steam,
                        'steam36'         => base_convert(str_replace('steam:', '', $player), 16, 36),
                        'playerName'      => $status->fakeName,
                        'playTime'        => $resolved->playtime,
                        'lastConnection'  => $resolved->last_connection,
                        'steamProfileUrl' => $resolved->getSteamProfileUrl() . 'f',
                        'isTrusted'       => false,
                        'isDebugger'      => false,
                        'isPanelTrusted'  => false,
                        'isStaff'         => false,
                        'isSuperAdmin'    => false,
                        'isRoot'          => false,
                        'isBanned'        => false,
                        'warnings'        => 0,
                        'ban'             => null,
                        'status'          => [
                            'status'     => PlayerStatus::STATUS_ONLINE,
                            'serverIp'   => $status->serverIp,
                            'serverId'   => $status->serverId,
                            'serverName' => $status->serverName,
                            'character'  => $status->character,
                            'fakeName'   => null,
                        ],
                    ];

                    $data = [
                        'player'      => $res,
                        'characters'  => CharacterResource::collection($characters),
                        'warnings'    => [],
                        'panelLogs'   => [],
                        'discord'     => null,
                        'kickReason'  => '',
                        'screenshots' => [],
                        'whitelisted' => false,
                    ];

                    CacheHelper::write($key, $data, 3 * CacheHelper::MONTH);
                } else {
                    return null;
                }
            } else if (CacheHelper::exists($key)) {
                $data = CacheHelper::read($key);

                $data['player']['status']['status'] = PlayerStatus::STATUS_OFFLINE;
                $data['player']['status']['character'] = 0;

                CacheHelper::write($key, $data, 3 * CacheHelper::MONTH);
            }

            return CacheHelper::read($key);
        }

        $resolved = Player::query()->select()->where('steam_identifier', '=', $player)->first();

        if ($resolved and $resolved instanceof Player) {
            return $resolved;
        }

        return null;
    }

    /**
     * Gets the route key name.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'steam_identifier';
    }

    /**
     * Gets the avatar attribute.
     *
     * @return string
     */
    public function getAvatarAttribute(): string
    {
        $steam = $this->getSteamUser();

        return $steam && isset($steam['avatar']) ? $steam['avatar'] : 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';
    }

    /**
     * Returns the discord user info (username, avatar, etc.)
     *
     * @return array|null
     */
    public function getDiscordInfo(): ?array
    {
        $user = DiscordUser::getUser($this->getDiscordID());
        return $user ? $user->toArray() : null;
    }

    /**
     * Returns the discord user id
     *
     * @return string
     */
    public function getDiscordID(): string
    {
        $ids = $this->getIdentifiers();

        foreach ($ids as $id) {
            if (Str::startsWith($id, 'discord:')) {
                return str_replace('discord:', '', $id);
            }
        }

        return '';
    }

    /**
     * Gets a URL to the player's steam profile.
     *
     * @return string
     */
    public function getSteamProfileUrl(): string
    {
        return self::STEAM_INVITE_URL . $this->getSteamID()->RenderSteamInvite();
    }

    /**
     * Gets all the identifiers.
     *
     * @return array
     */
    public function getIdentifiers(): array
    {
        $identifiers = $this->identifiers ?? [];
        $identifiers[] = $this->steam_identifier;

        return array_values(
            array_unique(
                $identifiers
            )
        );
    }

    /**
     * Returns all bannable identifiers
     *
     * @return array
     */
    public function getBannableIdentifiers(): array
    {
        return array_values(array_filter($this->getIdentifiers(), function ($identifier) {
            return !Str::startsWith($identifier, 'ip:');
        }));
    }

    /**
     * Gets the identifier for the provided key.
     *
     * @param $key
     * @return mixed|null
     */
    public function getIdentifier($key)
    {
        foreach ($this->getIdentifiers() as $identifier) {
            if (strpos($identifier, $key) === 0) return $identifier;
        }
        return null;
    }

    /**
     * Checks whether this player is a staff member.
     *
     * @return bool
     */
    public function isStaff(): bool
    {
        return ($this->is_staff ?? false) || $this->isSuperAdmin();
    }

    /**
     * Checks whether this player is a super admin.
     *
     * @return bool
     */
    public function isSuperAdmin(): bool
    {
        return ($this->is_super_admin ?? false) || $this->isRoot();
    }

    /**
     * Checks whether this player has root access to the panel.
     *
     * @return bool
     */
    public function isRoot(): bool
    {
        return GeneralHelper::isUserRoot($this->steam_identifier);
    }

    /**
     * Checks whether this player is a trusted panel user.
     *
     * @return bool
     */
    public function isPanelTrusted(): bool
    {
        return $this->isSuperAdmin() || $this->is_panel_trusted;
    }

    /**
     * Checks whether this player is a debugger.
     *
     * @return bool
     */
    public function isDebugger(): bool
    {
        return $this->isSuperAdmin() || $this->is_debugger;
    }

    /**
     * Checks whether player is banned.
     *
     * @return bool
     */
    public function isBanned(): bool
    {
        return !is_null($this->getActiveBan());
    }

    /**
     * Gets the active ban.
     *
     * @return Ban
     */
    public function getActiveBan(): ?Ban
    {
        return Ban::query()
            ->where('identifier', '=', $this->steam_identifier)
            ->get()
            ->first();
    }

    /**
     * Gets the steam id.
     *
     * @return SteamID|null
     */
    public function getSteamID(): ?SteamID
    {
        return get_steam_id($this->steam_identifier);
    }

    /**
     * Gets the steam user.
     *
     * @return array|null
     */
    public function getSteamUser(): ?array
    {
        $id = $this->getSteamID()->ConvertToUInt64();
        $key = 'steam_user_' . md5($id);

        if (CacheHelper::exists($key)) {
            return CacheHelper::read($key, []);
        }

        try {
            $steam = new SteamUser($id);
            $steam->getUserInfo();

            $info = $steam->toArray();
            CacheHelper::write($key, $info, CacheHelper::DAY);

            return $info;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Gets the characters' relationship.
     *
     * @return HasMany
     */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class, 'steam_identifier', 'steam_identifier')->orderBy('character_slot');
    }

    /**
     * Gets the logs' relationship.
     *
     * @return HasMany
     */
    public function logs(): HasMany
    {
        return $this->hasMany(Log::class, 'identifier', 'steam_identifier');
    }

    /**
     * Gets the warnings' relationship.
     *
     * @return HasMany
     */
    public function warnings(): HasMany
    {
        return $this->hasMany(Warning::class, 'player_id', 'user_id');
    }

    /**
     * Gets the panel_logs relationship.
     *
     * @return HasMany
     */
    public function panelLogs(): HasMany
    {
        return $this->hasMany(PanelLog::class, 'target_identifier', 'steam_identifier');
    }

    /**
     * Gets the query for bans.
     *
     * @return Builder
     */
    public function bans(): Builder
    {
        return Ban::query()->whereIn('identifier', $this->getIdentifiers());
    }

    /**
     * Returns a map of steamIdentifier->serverId,server for each online player
     *
     * @param bool $useCache
     * @return array|null
     */
    public static function getAllOnlinePlayers(bool $useCache): ?array
    {
        $serverIps = explode(',', env('OP_FW_SERVERS', ''));

        if (!$serverIps) {
            return [];
        }

        $result = [];
        foreach ($serverIps as $serverIp) {
            if ($serverIp) {
                $steamIdentifiers = Server::fetchSteamIdentifiers($serverIp, $useCache);

                if ($steamIdentifiers === null) {
                    return null;
                }

                foreach ($steamIdentifiers as $key => $player) {
                    if (!isset($result[$key])) {
                        $result[$key] = [
                            'id'               => intval($player['source']),
                            'character'        => $player['character'],
                            'server'           => $serverIp,
                            'fakeDisconnected' => $player['fakeDisconnected'],
                            'fakeName'         => $player['identityOverride'] ? $player['name'] : null,
                        ];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Returns the online status of the player
     *
     * @param string $steamIdentifier
     * @param bool $useCache
     * @return PlayerStatus
     */
    public static function getOnlineStatus(string $steamIdentifier, bool $useCache, bool $trueStatus = false): PlayerStatus
    {
        $serverIps = explode(',', env('OP_FW_SERVERS', ''));

        if (!$serverIps) {
            return new PlayerStatus(PlayerStatus::STATUS_UNAVAILABLE, '', 0);
        }

        $players = self::getAllOnlinePlayers($useCache);

        if ($players === null) {
            return new PlayerStatus(PlayerStatus::STATUS_UNAVAILABLE, '', 0);
        }

        if (isset($players[$steamIdentifier])) {
            $player = $players[$steamIdentifier];

            if (!$trueStatus && ($player['fakeDisconnected'] || $player['fakeName'])) {
                return new PlayerStatus(PlayerStatus::STATUS_OFFLINE, '', 0);
            }

            return new PlayerStatus(PlayerStatus::STATUS_ONLINE, $player['server'], $player['id'], $player['character'], $player['fakeName']);
        }

        return new PlayerStatus(PlayerStatus::STATUS_OFFLINE, '', 0);
    }

    /**
     * Returns a map of steamIdentifier->player_name
     * This is used instead of a left join as it appears to be a lot faster
     *
     * @param array $source
     * @param string|array $sourceKey
     * @return array
     */
    public static function fetchSteamPlayerNameMap(array $source, $sourceKey): array
    {
        if (!is_array($sourceKey)) {
            $sourceKey = [$sourceKey];
        }

        $identifiers = [];
        foreach ($source as $entry) {
            foreach ($sourceKey as $key) {
                if (!in_array($entry[$key], $identifiers)) {
                    $identifiers[] = $entry[$key];
                }
            }
        }

        $identifiers = array_values(array_unique($identifiers));
        $playerMap = CacheHelper::loadSteamPlayerNameMap($identifiers);

        if (empty($playerMap)) {
            $playerMap['empty'] = 'empty';
        }

        return $playerMap;
    }

    public static function getIdentifierLabel(string $identifier): ?string
    {
        $type = explode(':', $identifier)[0];

        switch ($type) {
            case 'ip':
                return 'IP-Address';
            case 'steam':
                return 'Steam Account';
            case 'discord':
                return 'Discord Account';
            case 'fivem':
                return 'FiveM Account';
            case 'license':
            case 'license2':
                return 'Rockstar Account';
            case 'live':
                return 'Microsoft Account';
            case 'xbl':
                return 'XBox Live';
            default:
                return null;
        }
    }

    public static function isValidIdentifier(string $identifier): bool
    {
        return sizeof(explode(':', $identifier)) === 2 && self::getIdentifierLabel($identifier) !== null;
    }
}

/**
 * Takes the given identifier and tries to resolve a SteamID from it.
 *
 * @param string $identifier
 * @return SteamID|null
 */
function get_steam_id(string $identifier): ?SteamID
{
    try {
        // Get rid of any prefix.
        return new SteamID(hexdec(explode('steam:', $identifier)[1]));
    } catch (Exception $ex) {
        return null;
    }
}
