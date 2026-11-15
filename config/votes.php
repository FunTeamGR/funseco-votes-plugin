<?php

return [
    // Chat votes replace the server's own callvotes (which are switched off at startup).
    'enabled' => true,
    'disable_server_callvotes' => true,
    // Explain chat voting to everyone when a player joins ('all'), only to them ('player'), or not ('off').
    'explain' => 'all',

    'rules' => [
        'spectators_can_start' => false,
        'spectators_can_vote'  => false,
        'blocked_during_scoreboard' => true,
        // Admins (and operators) can't be kicked or ignored by vote.
        'protect_staff' => true,
        // The player who starts a vote counts as having voted yes (rasp $auto_vote_starter).
        'auto_vote_starter' => true,
    ],

    // Per vote type: share of active players needed, how many per track (null = unlimited,
    // 0 = disabled), and when it expires — seconds in Time Attack/Laps/Stunts, rounds otherwise.
    'types' => [
        'endround' => ['ratio' => 0.4, 'per_track' => null, 'expire_seconds' => 0,   'expire_rounds' => 1],
        'ladder'   => ['ratio' => 0.5, 'per_track' => 2,    'expire_seconds' => 90,  'expire_rounds' => 2],
        'replay'   => ['ratio' => 0.6, 'per_track' => 1,    'expire_seconds' => 90,  'expire_rounds' => 2],
        'skip'     => ['ratio' => 0.6, 'per_track' => 2,    'expire_seconds' => 90,  'expire_rounds' => 2],
        'kick'     => ['ratio' => 0.7, 'per_track' => null, 'expire_seconds' => 120, 'expire_rounds' => 3],
        'ignore'   => ['ratio' => 0.6, 'per_track' => null, 'expire_seconds' => 120, 'expire_rounds' => 3],
        'add'      => ['ratio' => 0.66, 'per_track' => null, 'expire_seconds' => 120, 'expire_rounds' => 3],
    ],

    // Times in a row the same track may be replayed.
    'replays_in_a_row' => 1,

    // Repeat "N more votes needed" every this many seconds (Time Attack).
    'reminder_interval' => 30,

    'messages' => [
        'explain'       => '{#server}>> {#vote}CallVotes disabled - see {#highlite}/helpvote{#vote} for info on chat-based voting',
        'already'       => '{#server}> {#error}A vote is already in progress. Wait for this to complete.',
        'limit'         => '{#server}> {#error}Already ran {#highlite}$i {1}{#error} {2} vote{3} for this track - that\'s enough!',
        'start'         => '{#server}>> {#highlite}{1}{#vote} is requesting to {#highlite}{2}{#vote}.{br}{#vote}This will require {#highlite}{3}{#vote} votes to pass. Type {#highlite}/y{#vote} to vote yes.',
        'y'             => '{#server}>> {#highlite}{1}{#vote} more vote{2} needed to {#highlite}{3}{#vote}. Type {#highlite}/y{#vote} to vote yes.',
        'pass'          => '{#server}>> {#vote}Vote Passed! Server to {#highlite}{1}{#vote}.',
        'end'           => '{#server}>> {#vote}Vote to {#highlite}{1}{#vote} {2} by {#highlite}{3}{#vote}!',
        'cancel'        => '{#server}>> {#vote}Current vote cancelled!',
        'no_more_replay' => '{#server}> {#vote}No more than {#highlite}{1}{#vote} replay{2} per track allowed!',
        'no_admin_kick' => '{#server}>> {#vote}Player {#highlite}{1}{#vote} fruitlessly tried to kick-vote {#logina}admin {#highlite}{2}{#vote}!',
        'no_admin_ignore' => '{#server}>> {#vote}Player {#highlite}{1}{#vote} fruitlessly tried to ignore-vote {#logina}admin {#highlite}{2}{#vote}!',
        'no_spectators' => '{#server}> {#error}Spectators not allowed to start votes!',
        'no_sb_vote'    => '{#server}> {#error}Chat-based votes disabled during scoreboard!',
        'no_vote'       => '{#server}> {#error}Chat-based votes disabled - use normal CallVotes.',
        'not_allowed'   => '{#server}> {#error}{1} votes not allowed!',
        'endround_mode' => '{#server}> {#error}Running {#highlite}$i {1}{#error} mode - end round disabled!',
        'already_voted' => '{#server}> {#error}You have already voted!',
        'no_vote_now'   => '{#server}> {#error}There is no vote right now! Use {#highlite}$i/add <ID>{#error} or see {#highlite}$i/helpvote{#error} to start one.',
        'no_vote_progress' => '{#server}> {#error}There is no vote in progress!',
        'not_starter'   => '{#server}> {#error}You didn\'t start the current vote!',
        'admin_no_vote' => '{#server}> {#error}There is no vote right now!',
        'admin_passes'  => '{#server}>> {#admin}{1}$z$s {#highlite}{2}$z$s{#admin} passes vote!',
        'extend_title'  => 'Extend Track time',
        'extended'      => '{#server}>> {#vote}Track time extended by {#highlite}{1}{#vote} minutes!',
        'already_replayed' => '{#server}> {#error}Track is already getting replayed!',
    ],
];
