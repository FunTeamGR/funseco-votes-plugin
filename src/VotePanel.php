<?php
namespace Funseco\Votes;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\CurrentSpectators;
use Funseco\Core\Services\Looks\PlayerLooks;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;

/**
 * XAseco vote panel (panels/Vote*.xml): Yes (F5) runs /y, No (F6) just hides it.
 * The template is each player's own choice (/votepanel); geometry is in config/panels.php.
 */
class VotePanel
{
    /** Template names (full, with the 'Vote' prefix) this plugin can draw. */
    public static function templates(): array
    {
        return array_map(fn ($n) => 'Vote'.$n, array_keys(config('panels.vote', [])));
    }

    public function showToAll(string $starter): void
    {
        foreach (CurrentPlayers::all() as $player) {
            $spectator = CurrentSpectators::has($player->login);
            if ($spectator && !config('votes.rules.spectators_can_vote') && !$player->allowed('cancel')) {
                continue;
            }
            // Function keys don't work for spectators
            $this->show($player, $spectator ? '$0f0Yes' : '$0f0Yes - F5', $spectator ? '$333No' : '$333No - F6');
        }
    }

    /** Draw the player's chosen panel (nothing when they switched it off); $seconds > 0 hides it by itself. */
    public function show(Player $player, string $yes, string $no, int $seconds = 0): void
    {
        $name = app(PlayerLooks::class)->panel($player, 'vote');
        $t = config('panels.vote.'.substr($name, strlen('Vote')));
        if ($name === '' || !is_array($t)) {
            return;
        }

        [$qw, $qh] = $t['quad'];
        [$lw, $lh, $lx, $ly, $lz] = $t['label'];
        $button = fn (array $pos, string $action, int $key, string $text) => '<frame posn="'.$pos[0].' '.$pos[1].' 0">'
            .'<quad sizen="'.$qw.' '.$qh.'" style="Bgs1InRace" substyle="'.$t['substyle'].'" halign="center" action="'.ManiaLink::getAction($action).'" actionkey="'.$key.'"/>'
            .'<label sizen="'.$lw.' '.$lh.'" posn="'.$lx.' '.$ly.' '.$lz.'" style="TextButtonSmall" halign="center" text="'.$text.'"/></frame>';

        ManiaLink::displayLogin($player, '<manialink id="'.ManiaLink::getAction('vote_panel').'">'
            .$button($t['yes'], 'vote_yes', 1, $yes).$button($t['no'], 'vote_no', 2, $no)
            .'</manialink>', $seconds);
    }

    /** Two-second preview after choosing a panel with /votepanel. */
    public function preview(Player $player, bool $named): void
    {
        $this->show($player, format_colors(($named ? '{#pink}' : '{#orange}').'Yes - F5'), '$333No - F6', 2);
    }

    public function hide(Player $player): void
    {
        ManiaLink::displayLogin($player, '<manialink id="'.ManiaLink::getAction('vote_panel').'"></manialink>');
    }

    public function hideFromAll(): void
    {
        ManiaLink::displayAll('<manialink id="'.ManiaLink::getAction('vote_panel').'"></manialink>');
    }

    /** Click handler: Yes votes, No hides the panel. */
    public function handle(Player $player, string $action): void
    {
        $action === 'vote_yes' ? \Funseco\Core\Services\Chat\Facades\Chat::run($player, 'y') : $this->hide($player);
    }
}
