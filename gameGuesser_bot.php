<?php

include 'config.php';

define ( 'API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/' );

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
function buildMainMenu(){ //Cretes simple menu with two buttons
	$mainMenu = array ();
	$btnStartGame = (object) array('text' => "Start Game" , 'callback_data' => '{"mainMenu":"startGame"}');
	$btnSeeStats = (object) array('text' => "See Stats" , 'callback_data' => '{"mainMenu":"seeStats"}');
	array_push($mainMenu,array($btnStartGame),array($btnSeeStats));
	return $mainMenu;
}
function sendMessage($chat_id, $message) {
	apiRequest ( "sendMessage", array (
			'chat_id' => $chat_id,
			'parse_mode' => 'HTML',
			"text" => $message
	) );

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
function initPlayer($chat_id) {
	
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('INSERT INTO player(chat_id) VALUES (:chatId)');
	$stmt->bindParam(':chatId', $chat_id);
	$result = $stmt->execute();
}
function processCallbackQuery($callbackQuery) {
	
	//debug($callbackQuery);
	$chat_id = $callbackQuery['from']['id'];
	$callbackData = $callbackQuery['data'];
	$callback_query_id = $callbackQuery['id'];
	$message_id = $callbackQuery['message']['message_id'];
	
	$JsonCallbackData = json_decode($callbackData);
	
		if(isset($JsonCallbackData->mainMenu)) {
			$pickedOption = $JsonCallbackData->mainMenu;
			switch ($pickedOption) {
				case "startGame":
					//Start Game
					break;
				case "seeStats":
					//Show stats
					break;
			}
		}
	
	apiRequest ( "answerCallbackQuery", array ("callback_query_id" => $callback_query_id));
}
function processMessage($message) {
	
	$text = $message ['text'];
	$reply = $message ['reply_to_message'] ['text'];
	$message_id = $message ['message_id'];
	$chat_id = $message ['chat'] ['id'];

	if (isset ( $message ['text'] )) { // User has send text - Either by typing or pressing button

		//The bot will react only to following commands given by the bot's user. All of them are entered by either keyboard button pressing or usage of bot's command list under "/".
		if (strpos ( $text, "/start" ) === 0) {
			sendMessageAndInlineKeyboard($chat_id, "<b>Welcome to Guess The Game!</b>", buildMainMenu());
			initPlayer($chat_id);
		}
		else { } //User input not covered by cases above
	
	}
	else if (isset ( $reply )) { // User has responded to bot by force_reply initiated by bot. Field 'reply_to_message' is empty otherwise.
		//if($reply === "someTextTheBotHasSent") {...}
	}
	else { // user sends anything but text msg
	}
}


$content = file_get_contents ( "php://input" );
$update = json_decode ( $content, true );

if (!$update) {
	// Received wrong update, must not happen.
	exit ();
}
if (isset ( $update ["message"] )) {  //User sends text or pressed custom keyboard
	processMessage($update ["message"] );
}
if (isset ( $update ["callback_query"] )) { //User pressed inline keyboard button
	processCallbackQuery($update ["callback_query"]);

}


?>