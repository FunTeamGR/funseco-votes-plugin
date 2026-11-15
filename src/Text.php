<?php
namespace Funseco\Votes;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentPlayers;

final class Text
{
    public static function get(string $key, string|int ...$values): string
    {
        $text = config("votes.messages.$key", '');
        $text = format_colors(str_replace('{br}', "\n", $text));   // colour tokens first: values must not expand {#..}
        $map = [];
        foreach ($values as $i => $value) {
            $map['{'.($i + 1).'}'] = (string) $value;
        }

        return strtr($text, $map);
    }

    public static function tell(Player $player, string $message): void
    {
        \Server::query()->ChatSendServerMessageToLogin($message, $player->login);
    }

    public static function broadcast(string $message): void
    {
        \Server::query()->ChatSendServerMessage($message);
    }

    public static function stripColors(?string $text): string
    {
        $text = str_replace('$$', "\0", (string) $text);
        $text = preg_replace('/\$(?:[0-9a-f][^$]{0,2}|[lh]\[[^\]]*\]|[^$]?)/i', '', $text);

        return str_replace("\0", '$', $text);
    }

    /** XAseco getPlayerParam(): login or /players number; tells the caller when not found. */
    public static function target(Player $caller, string $arg): ?Player
    {
        $target = $arg === '' ? null : CurrentPlayers::findByLoginOrIndex($arg);
        if (!$target) {
            self::tell($caller, format_colors(ctype_digit($arg) && $arg !== ''
                ? '{#server}> {#error}Player_ID not found! Type {#highlite}$i/players {#error}to see all players.'
                : '{#server}> {#highlite}'.$arg.' {#error}is not a valid player! Use {#highlite}$i/players {#error}to find the correct login or Player_ID.'));
        }

        return $target;
    }
}
