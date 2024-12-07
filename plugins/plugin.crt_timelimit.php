<?php

Aseco::addChatCommand('crt_timelimit', 'Set/Get Cup/Rounds/Team time limit');

Aseco::registerEvent('onEndRace', 'crttl_OnEndRace_Post');

// time limit in minutes (0 or below is off)
$crt_timelimit = 0;

function chat_crt_timelimit($aseco, $command)
{
	global $crt_timelimit;
	$client = $command['author'];
	
	// no arguments - just return currently set CRT time limit
	if ($command['params'] == '')
	{
		if ($crt_timelimit > 0)
		{
			$message = formatText('{#server}>> {#admin}CRT time limit is {#highlite}{1}{#admin} minutes', $crt_timelimit);
			$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $client->login);
		}
		else
		{
			$message = formatText('{#server}>> {#admin}CRT time limit is not set', $crt_timelimit);
			$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $client->login);
		}
		return;
	}
	
	// argument provided - check for permissions & build chat/log message
	$logtitle = 'Player';
	$chattitle = 'Player';
	$perms = false;
	
	if ($aseco->isMasterAdmin($client))
	{
		$logtitle = 'MasterAdmin';
		$chattitle = $aseco->titles['MASTERADMIN'][0];
		$perms = true;
	} 
	
	else if ($aseco->isAdmin($client) && $aseco->allowAdminAbility($command['params'][0]))
	{
		$logtitle = 'Admin';
		$chattitle = $aseco->titles['ADMIN'][0];
		$perms = true;
	}
	
	else if ($aseco->isOperator($client) && $aseco->allowOpAbility($command['params'][0]))
	{
		$logtitle = 'Operator';
		$chattitle = $aseco->titles['OPERATOR'][0];
	}
	
	if (!$perms)
	{
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors('{#server}> {#error}This command is for Admins only!'), $client->login);
		return;
	}
	
	$new_crttl = $command['params'];
	if (!is_numeric($new_crttl))
	{
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors('{#server}> {#error}Argument is not a number!'), $client->login);
		return;
	}
	$crt_timelimit = $new_crttl;
	
	$aseco->console('{1} [{2}] sets CRT time limit to {3} mins', $logtitle, $client->login, $crt_timelimit);
	
	$message = formatText('{#server}>> {#admin}{1}$z$s {#highlite}{2}$z$s{#admin} sets CRT time limit to {#highlite}{3}$z$s{#admin} minutes!',
							$chattitle, $client->nickname, $crt_timelimit);
	$aseco->client->query('ChatSendServerMessage', $aseco->formatColors($message));
}

function crttl_OnEndRace_Post($aseco)
{
	global $crt_timelimit;
	
	// intermission time (before round start, "please wait" timer)
	// can be as low as 5-8 or as high as 20-23 seconds, 10 is usually good enough lol
	$crt_it = 10;
	
	// is CRT timelimit even enabled?
	if ($crt_timelimit > 0)
	{
		$mode = $aseco->server->gameinfo->mode;
		$is_cup = $mode == Gameinfo::CUP;
		$is_rounds = $mode == Gameinfo::RNDS;
		$is_team = $mode == Gameinfo::TEAM;
		
		// we don't care if we're in TA, stunts or laps
		if ($is_cup || $is_rounds || $is_team)
		{
			if ($aseco->client->query('GetNextChallengeInfo'))
			{
				$next = $aseco->client->getResponse();
				
				$at = $next["AuthorTime"];
				
				$aseco->client->query('GetFinishTimeout');
				$response = $aseco->client->getResponse();
				$ft = $response['NextValue'];
				if ($ft == 0)
				{
					// "default" value is 15 seconds (for laps it's 30, but we don't care about laps)
					$ft = 15000;
				}
				else if ($ft == 1)
				{
					// "adaptative to the duration of the challenge" value is 1/6th of AT plus 5 seconds
					// NOTE: does not take forced laps into consideration - can't get lap count from GetNextChallengeInfo !!!!!
					// in that case, the formula would be "FT = ((AT / lap_count) * forced_lap_count) / 6 + 5000"
					$ft = ($at / 6) + 5000;
				}
				// if not 0 or 1, it is taken directly as a value in milliseconds
				
				// first, announce CRT time limit, next AT and next finish timeout (FT) to all in chat
				$message = formatText('{#server}>> {#admin}CRT time limit is {#highlite}{1}{#admin} minutes, next track AT is {#highlite}{2}{#admin}, finish timeout is {#highlite}{3}{#admin}...',
					$crt_timelimit, crt_formatTime($at), crt_formatTime($ft, false));
				$aseco->client->query('ChatSendServerMessage', $aseco->formatColors($message));
				
				// convert AT and FT to seconds as floats
				$at = floatval($at) / 1000.0;
				$ft = floatval($ft) / 1000.0;
				
				// "rounds per challenge" is basically how many times we can start the race during our CRT time limit
				// race duration takes AT, FT and intermission time (see line 12) into consideration
				//
				// example:
				// - CRT time limit is 5 minutes (or 300 seconds)
				// - AT is 1:00.00
				// - FT is set to 1, meaning it is effectively 0:15 (according to our formula above)
				// - the sum of AT, FT and intermission is 1:25.00 (or 85 seconds)
				// - 300 divided by 85 is 3.53 - thus "rounds per challenge" will be 4 (as we can start 4 races in that time)
				$rpc = ceil(floatval($crt_timelimit * 60) / ($at + $ft + $crt_it));
				
				if ($is_cup)
				{
					// first, deduct warm-up rounds from the "rounds per challenge"
					$aseco->client->query('GetCupWarmUpDuration');
					$response = $aseco->client->getResponse();
					$wu = $response['NextValue'];
					if ($wu >= $rpc)
					{
						// too many warm-up rounds - set to 1 as a fallback
						$rpc = 1;
					}
					else
					{
						$rpc -= $wu;
					}
					
					$message = formatText('{#server}>> {#admin}Calculated and set rounds per challenge to {#highlite}{1}', $rpc);
					$aseco->client->query('ChatSendServerMessage', $aseco->formatColors($message));
					
					// directly set cup rounds per challenge from our value
					$aseco->client->query('SetCupRoundsPerChallenge', (int) $rpc);
				}
				else if ($is_rounds)
				{
					// first, deduct warm-up rounds from the "rounds per challenge"
					$aseco->client->query('GetAllWarmUpDuration');
					$response = $aseco->client->getResponse();
					$wu = $response['NextValue'];
					if ($wu >= $rpc)
					{
						// too many warm-up rounds - set to 1 as a fallback
						$rpc = 1;
					}
					else
					{
						$rpc -= $wu;
					}
					
					$aseco->client->query('GetRoundCustomPoints');
					$custom_pts = $aseco->client->getResponse();
					
					$pts_1st = 10;
					if (is_numeric($custom_pts[0]))
					{
						$pts_1st = $custom_pts[0];
					}
					
					$pts_2nd = 6;
					if (is_numeric($custom_pts[1]))
					{
						$pts_2nd = $custom_pts[1];
					}
					
					// take the average round points for first and second place
					$avg_pts = floatval($pts_1st + $pts_2nd) / 2.0;
					
					// and then multiply the average by our "rounds per challenge"
					// the idea is to account for back-and-forth 1st, 2nd, 1st, 2nd... place each round
					// it's not perfect but it's good enough for our use case
					// some tracks will end before our time limit, but in turn, going way over the time limit will be rarer
					$pl = $avg_pts * $rpc;
					
					$message = formatText('{#server}>> {#admin}Calculated {#highlite}{1}{#admin} round(s) per challenge - points limit set to {#highlite}{2}', $rpc, $pl);
					$aseco->client->query('ChatSendServerMessage', $aseco->formatColors($message));
					
					// set point limit according to above formula
					$aseco->client->query('SetRoundPointsLimit', (int) $pl);
				}
				else if ($is_team)
				{
					// first, deduct warm-up rounds from the "rounds per challenge"
					$aseco->client->query('GetAllWarmUpDuration');
					$response = $aseco->client->getResponse();
					$wu = $response['NextValue'];
					if ($wu >= $rpc)
					{
						// too many warm-up rounds - set to 1 as a fallback
						$rpc = 1;
					}
					else
					{
						$rpc -= $wu;
					}
					
					// divide our "rounds per challenge" by half, making sure to round upwards regardless of what we get
					// the idea is to account for back-and-forth red, blue, red, blue... round team wins
					// it's not perfect but it's good enough for our use case
					// some tracks will end before our time limit, but in turn, going way over the time limit will be rarer
					// as draws do not affect either team points at all, they will unfortunately be an outlier
					$pl = ceil(floatval($rpc) / 2.0);
					
					$message = formatText('{#server}>> {#admin}Calculated {#highlite}{1}{#admin} round(s) per challenge - points limit set to {#highlite}{2}', $rpc, $pl);
					$aseco->client->query('ChatSendServerMessage', $aseco->formatColors($message));
					
					// set point limit according to above formula
					$aseco->client->query('SetTeamPointsLimit', (int) $pl);
				}
			}
			else
			{
				// i don't think this should ever happen :3
				$aseco->client->query('ChatSendServerMessage', $aseco->formatColors('{#server}>> {#error}Failed to set CRT time limit: {#highlite}GetNextChallengeInfo failed.'));
			}
		}
	}
}

// stolen from records eyepiece which stole from basic.inc.php lol
function crt_formatTime($MwTime, $hsec = true)
{
	if ($MwTime == -1)
	{
		return '???';
	}
	else
	{
		$hseconds = (($MwTime - (floor($MwTime/1000) * 1000)) / 10);
		$MwTime = floor($MwTime / 1000);
		$hours = floor($MwTime / 3600);
		$MwTime = $MwTime - ($hours * 3600);
		$minutes = floor($MwTime / 60);
		$MwTime = $MwTime - ($minutes * 60);
		$seconds = floor($MwTime);
		
		if ($hsec)
		{
			if ($hours)
			{
				return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $seconds, $hseconds);
			}
			else
			{
				return sprintf('%d:%02d.%02d', $minutes, $seconds, $hseconds);
			}
		}
		else
		{
			if ($hours)
			{
				return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
			}
			else
			{
				return sprintf('%d:%02d', $minutes, $seconds);
			}
		}
	}
}

?>