<?php

/*
 * Vote panel templates (XAseco panels/Vote*.xml), merged into config('panels.vote') by mergeConfigFrom (shallow array_merge):
 * a template name the app defines under 'vote' in config/panels.php replaces that whole template. Keys are the template names without the "Vote" prefix.
 */

// yes/no frame positions, button quad size, label size/position, quad style
$vote = fn (array $yes, array $no, array $quad, array $label, string $substyle = 'BgButtonSmall') => ['yes' => $yes, 'no' => $no, 'quad' => $quad, 'label' => $label, 'substyle' => $substyle];

return [
    'BelowChat'          => $vote([-56.8, -45.6], [-46, -45.6], [10, 2.5], [5, 1.5, 0, -0.3, 2]),
    'BottomCenter'       => $vote([-5, -30], [5.5, -30], [10, 3.5], [5, 2.5, 0, -0.8, 2]),
    'BottomCenterTransp' => $vote([-5, -30], [5.5, -30], [10, 3.5], [5, 2.5, 0, -0.8, 2], 'NavButton'),
    'CallVote'           => $vote([-45, 37.1], [-34.5, 37.1], [10, 3.5], [5, 2.5, 0, -0.8, 2]),
    'TopCenter'          => $vote([-5.8, 40], [4.7, 40], [10, 3.5], [5, 2.5, 0, -0.8, 2]),
];
