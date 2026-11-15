<?php
namespace Funseco\Votes\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\Enums\Role;
use Funseco\Votes\Text;
use Funseco\Votes\VoteBox;

/** /kick and /ignore votes against a player. */
class TargetVoteCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name;
    protected string $description;
    protected string $usage = '<login/id>';

    public function __construct(private string $type)
    {
        $this->name = $type;
        $this->description = 'Starts a vote to '.$type.' a player';
    }

    public function handle(Player $player, array $args): void
    {
        if (config("votes.types.{$this->type}.per_track") === 0) {
            Text::tell($player, Text::get('not_allowed', ucfirst($this->type)));
            return;
        }
        // XAseco: the vote rules first, privately; only then the public "fruitlessly tried" line
        if ($message = app(VoteBox::class)->refusal($player, $this->type)) {
            Text::tell($player, $message);
            return;
        }
        if (!$target = Text::target($player, (string) ($args[0] ?? ''))) {
            return;
        }
        if (config('votes.rules.protect_staff', true) && $target->effectiveRole() !== Role::Player) {
            Text::broadcast(Text::get('no_admin_'.$this->type, Text::stripColors($player->nickname), Text::stripColors($target->nickname)));
            return;
        }

        app(VoteBox::class)->start($player, $this->type, ucfirst($this->type).' '.Text::stripColors($target->nickname), $target);
    }
}
