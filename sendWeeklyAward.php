<?php

include 'config.php';

define ( 'API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/' );

ignore_user_abort(true);
set_time_limit(5000);

function exec_curl_request($handle, $chat_id, $url) {
	$response = curl_exec ( $handle );

	if ($response === false) {
		$errno = curl_errno ( $handle );
		$error = curl_error ( $handle );
		error_log ( "Curl returned error $errno: $error\n" );
		curl_close ( $handle );
		return false;
	}

	$http_code = intval ( curl_getinfo ( $handle, CURLINFO_HTTP_CODE ) );
	curl_close ( $handle );

	if ($http_code >= 500) {
		// do not wat to DDOS server if something goes wrong
		sleep ( 10 );
		return false;
	} else if ($http_code != 200) {
		$response = json_decode ( $response, true );
		error_log ( "Request has failed with error {$response['error_code']}: {$response['description']}\n" );
		writeBotErrorLog($chat_id, $response, $url);
		if ($http_code == 401) {
			throw new Exception ( 'Invalid access token provided' );
		}
		return false;
	} else {
		$response = json_decode ( $response, true );
		if (isset ( $response ['description'] )) {
			error_log ( "Request was successfull: {$response['description']}\n" );
		}
		$response = $response ['result'];
	}

	return $response;
}
function writeBotErrorLog($chat_id, $response, $url) {

	$string = " Connection with chat_id $chat_id failed. Error Code {$response['error_code']}: {$response['description']} \n". urldecode($url) ."\n\n";
	$fileName = "bot_error.log";
	if(!file_exists($fileName)) { //Create file if doesn't exist.

		$fh = fopen($fileName, 'w');
		fclose($fh);
	}
	file_put_contents($fileName, date_format(date_create(), 'U = Y-m-d H:i:s') . $string, FILE_APPEND);
}
function apiRequest($method, $parameters) {
	if (! is_string ( $method )) {
		error_log ( "Method name must be a string\n" );
		return false;
	}

	if (! $parameters) {
		$parameters = array ();
	} else if (! is_array ( $parameters )) {
		error_log ( "Parameters must be an array\n" );
		return false;
	}

	foreach ( $parameters as $key => &$val ) {
		// encoding to JSON array parameters, for example reply_markup
		if (! is_numeric ( $val ) && ! is_string ( $val )) {
			$val = json_encode ( $val );
		}
	}
	$url = API_URL . $method . '?' . http_build_query ( $parameters );
	$url = str_replace ( '%25', '%', $url ); // quick fix to enable newline in messages. '%' musn't be replaced by http encoding to '%25'
	$handle = curl_init ( $url );

	curl_setopt ( $handle, CURLOPT_RETURNTRANSFER, true );
	curl_setopt ( $handle, CURLOPT_CONNECTTIMEOUT, 5 );
	curl_setopt ( $handle, CURLOPT_TIMEOUT, 60 );

	return exec_curl_request ( $handle, $parameters['chat_id'], $url );
}
function sendMessageAndInlineKeyboard($chat_id, $message, $inlineKeyboard) {
	apiRequest ( "sendMessage", array (
			'chat_id' => $chat_id,
			'parse_mode' => 'HTML',
			"text" => $message,
			'disable_notification' => true,
			'reply_markup' => array ('inline_keyboard' =>$inlineKeyboard)
	));
}
function buildFeedbackInlineKeyboard() {
	$feedbackMenu = array ();
	$btnSendFeedback = (object) array('text' => "\xF0\x9F\x93\x9D Send Feedback" , 'callback_data' => '{"feedbackMenu":"sendFeedback"}');
	$btnRateBot = (object) array('text' => "Rate Bot \xE2\xAD\x90\xEF\xB8\x8F\xE2\xAD\x90\xEF\xB8\x8F\xE2\xAD\x90\xEF\xB8\x8F\xE2\xAD\x90\xEF\xB8\x8F\xE2\xAD\x90\xEF\xB8\x8F" , 'url' => 'https://telegram.me/storebot?start=GameGuesserBot');
	$btnBack = (object) array('text' => "\xE2\x86\xA9\xEF\xB8\x8F Back" , 'callback_data' => '{"mainMenu":"backFresh"}');
	array_push($feedbackMenu,array($btnSendFeedback,$btnRateBot),array($btnBack));

	return $feedbackMenu;
}
function apiSendDocument($chat_id, $url) {

	echo var_dump(apiRequest ( "sendDocument", array (
			'chat_id' => $chat_id,
			'parse_mode' => 'HTML',	
			"document" => $url
	)));
}
function getThisWeeksHighscoreData() {
	
	$string = null;
	
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('SELECT pts_week, username FROM gameGuesser.player WHERE pts_week!=0 ORDER BY pts_week DESC LIMIT 10');
	$stmt->execute();
	$result = $stmt->fetchAll(PDO::FETCH_ASSOC);
	
	
	$string = "<pre>Congratulations to the winner of the week.%0A</pre>%0A<b>\xF0\x9F\x8F\x86 {$result[0]['username']}</b><code> - </code><b>{$result[0]['pts_week']} pts</b>.<pre>%0AWhat an amazing result!%0A%0A</pre>";
	
	$counter = 1;
	foreach ($result as $value) {
		$string .= $counter .".". $value['username']."				".$value['pts_week'] ."pts%0A";
		$counter++;
	}
	
	$string .= "%0A<b>This week's leaderboard has been reset. Climb to the top right now!</b>";
	return $string;
}
function saveThisWeeksWinner() {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('SELECT chat_id, pts_week, username FROM gameGuesser.player WHERE pts_week!=0 ORDER BY pts_week DESC LIMIT 1');
	$stmt->execute();
	$topPlayer = $stmt->fetch(PDO::FETCH_ASSOC);
	
	$chat_id = $topPlayer['chat_id']; 
	$pts_week = $topPlayer['pts_week'];
	
	$stmt = $db->prepare('INSERT INTO weekly_winner(chat_id, pts_week) VALUES (:chat_id, :pts_week)');
	$stmt->bindParam(':chat_id', $chat_id);
	$stmt->bindParam(':pts_week', $pts_week);
	$stmt->execute();
	
}
function resetWeeklyHighscores() {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('SET SQL_SAFE_UPDATES = 0');
	$stmt->execute();
	
	$stmt = $db->prepare('Update player SET pts_week2 = 0');
	$stmt->execute();
}
function getAllPlayers() {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('SELECT chat_id FROM gameGuesser.player');
	$stmt->execute();
	$result = $stmt->fetchAll(PDO::FETCH_ASSOC);
	return $result;
}

$stringToSend = getThisWeeksHighscoreData();
$listOfAllPlayers = getAllPlayers();
saveThisWeeksWinner();
resetWeeklyHighscores();


$counter = 0;
foreach ($listOfAllPlayers as $player) {
	
	apiSendDocument($player['chat_id'], "https://media.giphy.com/media/l0Ex3vQtX5VX2YtAQ/source.gif");
	$counter++;
	if(!($counter%30))
		sleep(1);
	
	sendMessageAndInlineKeyboard($player['chat_id'], $stringToSend, buildFeedbackInlineKeyboard());
	$counter++;
	if(!($counter%30))
		sleep(1);
}


?>