<?php
namespace Funseco\Votes\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Replays\ReplayCounter;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Votes\Text;
use Funseco\Votes\VoteBox;

/** /endround, /ladder, /replay (alias /extend), /skip: one instance each. */
class StartVoteCommand implements Command, Described
{
    use DeclaresCommand;

    private const DESCRIPTIONS = [
        'endround' => ['Starts a vote to end current round', 'End this Round'],
        'ladder'   => ['Starts a vote to restart track for ladder', 'Restart Track for Ladder'],
        'replay'   => ['Starts a vote to replay this track', 'Replay Track after Finish'],
        'skip'     => ['Starts a vote to skip this track', 'Skip this Track'],
    ];
    private const MODES = [0 => 'Rounds', 1 => 'TimeAttack', 2 => 'Team', 3 => 'Laps', 4 => 'Stunts', 5 => 'Cup'];

    protected string $name;
    protected string $description;
    protected array $aliases = [];

    public function __construct(private string $type)
    {
        $this->name = $type;
        $this->description = self::DESCRIPTIONS[$type][0];
        if ($type === 'replay') {
            $this->aliases = ['extend'];
        }
    }

    public function handle(Player $player, array $args): void
    {
        $box = app(VoteBox::class);
        $mode = ServerInfo::getGameMode();

        if ($this->type === 'endround' && in_array($mode, [1, 3, 4], true)) {
            Text::tell($player, Text::get('endround_mode', self::MODES[$mode]));
            return;
        }
        if ($this->type === 'replay') {
            $limit = (int) config('votes.replays_in_a_row', 1);
            if (app(ReplayCounter::class)->votedMaxed()) {
                Text::tell($player, Text::get('no_more_replay', $limit, $limit === 1 ? '' : 's'));
                return;
            }
        }

        // With FlexiTime running a passed replay vote adds the default round time instead.
        $desc = $this->type === 'replay' && $box->wouldExtend()
            ? Text::get('extend_title')
            : self::DESCRIPTIONS[$this->type][1];
        $box->start($player, $this->type, $desc);
    }
}
