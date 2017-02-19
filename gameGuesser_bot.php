<?php

include 'config.php';

define ( 'API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/' );
define ('PICTURE_COUNT', 22630); //Won't change for now. Better than retrieving count everytime from db
define ('TITLE_COUNT', 4539); 

function exec_curl_request($handle, $chat_id, $url) {
	echo $handle;
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
function debug($string) {
	apiRequest ( "sendMessage", array (
			'chat_id' => 167103785, //Your own chat_id goes here
			'parse_mode' => 'HTML',
			"text" => $string
	));
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
	$btnStartEasy = (object) array('text' => "Start Easy Mode" , 'callback_data' => '{"mainMenu":"startEasy"}');
	$btnStartExpert = (object) array('text' => "Start Expert Mode" , 'callback_data' => '{"mainMenu":"startExpert"}');
	$btnSeeStats = (object) array('text' => "See Stats" , 'callback_data' => '{"mainMenu":"seeStats"}');
	array_push($mainMenu,array($btnStartEasy),array($btnStartExpert),array($btnSeeStats));
	return $mainMenu;
}
function buildActiveGameMenu() {
	$menu = array ();
	$btnStartGame = (object) array('text' => "Next " , 'callback_data' => '{"mainMenu":"next"}');
	$btnSeeStats = (object) array('text' => "See Stats" , 'callback_data' => '{"mainMenu":"seeStats"}');
	$btnExit = ((object) array('text' => "Exit" , 'callback_data' => '{"mainMenu":"exit"}'));
	array_push($menu,array($btnStartGame),array($btnSeeStats),array($btnExit));
	return $menu;
}
function buildActiveGameMenuExpert() {
	$menu = array ();
	$btnStartGame = (object) array('text' => "Next " , 'callback_data' => '{"mainMenu":"nextExpert"}');
	$btnSeeStats = (object) array('text' => "See Stats" , 'callback_data' => '{"mainMenu":"seeStats"}');
	$btnExit = ((object) array('text' => "Exit" , 'callback_data' => '{"mainMenu":"exit"}'));
	array_push($menu,array($btnStartGame),array($btnSeeStats),array($btnExit));
	return $menu;
}
function buildSuggestionsInlineKeyboard($searcheInput) {

	$suggestions = searchTitleByName($searcheInput);

	$inlineKeyboard = array();

	foreach ($suggestions as $item) {  //Set together inline keyboard with data queried from db.

		$data = '{"expertAnswer":"' . $item['id'] . '"}';
		$inlineBtn = (object) array('text' => $item['name'] , 'callback_data' => $data);
		array_push($inlineKeyboard, array($inlineBtn));
	}

	if(empty($inlineKeyboard))
		return false;
		else {
			return $inlineKeyboard;
		}
}
function searchTitleByName($searchString){ //Searches database for games similar to searchString

	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	$stmt = $db->prepare ( "SELECT id, name FROM title");  //Retrieve all games in db
	$stmt->execute();
	$allTitles = $stmt->fetchAll(PDO::FETCH_ASSOC);

	$suggestionsArray = array();

	foreach ($allTitles as $element) {
		$percent = 0;
		$nameInDb = str_replace("'", "", strtolower($element['name']));
		$searchString = strtolower($searchString);
		similar_text($nameInDb, $searchString, $percent);
		if(round($percent >50,2)) { //Get all titles with match rate of at least 50.00%
			$element['perc'] = round($percent,2);
			array_push($suggestionsArray, $element);
		}
	}

	$percentageArray = array();
	foreach ($suggestionsArray as $key => $row) {
		$percentageArray[$key] = $row['perc'];
	}

	array_multisort($percentageArray,  SORT_DESC, $suggestionsArray); //Sort games by similarity

	$bestMatches = array();
	$counter = 0;
	for($counter = 0; $counter<sizeof($suggestionsArray) && $counter<=10;$counter++) {  //Retrieve 10 highest rated matches
		array_push($bestMatches, $suggestionsArray[$counter]);
	}

	return $bestMatches;
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
function updateMessage($chat_id, $message_id, $text, $inlineKeyboard){

	apiRequest ( "editMessageText", array (
			'chat_id' => $chat_id,
			'message_id' => $message_id,
			'parse_mode' => 'HTML',
			"text" => $text,
			'reply_markup' => array ('inline_keyboard' => $inlineKeyboard)
	));
}
function initPlayer($chat_id) {
	
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");

	$stmt = $db->prepare('INSERT INTO player_state(chat_id, correct_answer) VALUES (:chat_id, :correct_answer)');
	$stmt->bindParam(':chat_id', $chat_id);
	$stmt->bindParam(':correct_answer', $answer = 0);
	$stmt->execute();

	
	$stmt = $db->prepare('INSERT INTO player(chat_id) VALUES (:chatId)');
	$stmt->bindParam(':chatId', $chat_id);
	$stmt->execute();
	

	
}
function createQuestion() {
	//1. Retrieve random picture from db
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('SELECT url, title_id_FK from picture WHERE id = :id');
	$id = rand(1,PICTURE_COUNT);
	$stmt->bindParam(':id', $id); //Select one random picture
	$stmt->execute();
	$result = $stmt->fetch(PDO::FETCH_ASSOC);
	$pictureUrl = $result['url'];
	//2. Get correct title
	$correctTitle = getTitle($result['title_id_FK']);
	//Retrieve 3 other random names
	
	$stmt = $db->prepare('SELECT name, id from title WHERE id = :idOne OR id = :idTwo OR id = :idThree');
	$idOne = rand(1,TITLE_COUNT);
	$idTwo = rand(1,TITLE_COUNT);
	$idThree = rand(1,TITLE_COUNT);
	$stmt->bindParam(':idOne', $idOne); 
	$stmt->bindParam(':idTwo', $idTwo); 
	$stmt->bindParam(':idThree', $idThree);
	
	$stmt->execute();
	$result = $stmt->fetchAll(PDO::FETCH_ASSOC);
	$answers = array();
	foreach ($result as $value) {
		array_push($answers, $value);
	}
	array_push($answers, $correctTitle);
	
	$inlineButtons = buildInlineButtons($answers, $correctTitle['id']);
	$array = array();
	array_push($array, $inlineButtons, "%0A%0A<a href='" . $pictureUrl . "'>&#160</a>What's this?");
	return($array);
	
}
function createExpertQuestion($chat_id) {
	
	
	//1. Retrieve random picture from db
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('SELECT url, title_id_FK from picture WHERE id = :id');
	$id = rand(1,PICTURE_COUNT);
	$stmt->bindParam(':id', $id); //Select one random picture
	$stmt->execute();
	$result = $stmt->fetch(PDO::FETCH_ASSOC);
	$pictureUrl = $result['url'];
	//2. Get correct title
	$correctTitle = getTitle($result['title_id_FK']);
	//Write correct answer to db
	writeCorrectAnswerToDb($chat_id, $result['title_id_FK']);
	//Create keyboard with buttons "I don't know. Next./Exit"
	$array = array();
	array_push($array, array(array((object) array('text' => "I don't know." , 'callback_data' => '{"mainMenu":"dontKnow"}'))), "%0A%0A<a href='" . $pictureUrl . "'>&#160</a>What's this? Type the name below.");
	return($array);
}
function answerIsCorrect($pickedOption, $chat_id) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	$stmt = $db->prepare('SELECT correct_answer from player_state WHERE chat_id = :chat_id');
	$stmt->bindParam(':chat_id', $chat_id); //Select one random picture
	$stmt->execute();
	$result = $stmt->fetch(PDO::FETCH_ASSOC);
	$correctAnswer = $result['correct_answer'];
	
	if($pickedOption == $correctAnswer)
		return true;
	else
		return false;
}
function getTitle($titleId) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('SELECT name, id from title WHERE id = :id');
	$stmt->bindParam(':id', $titleId); //Select one random picture
	$stmt->execute();
	$result = $stmt->fetch(PDO::FETCH_ASSOC);
	return $result;
}
function getCorrectIdExpert($chat_id) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('SELECT correct_answer from player_state WHERE chat_id = :chat_id');
	$stmt->bindParam(':chat_id', $chat_id); //Select one random picture
	$stmt->execute();
	$result = $stmt->fetch(PDO::FETCH_ASSOC);
	return $result['correct_answer'];
}
function buildInlineButtons($answers, $correctAnswerId) {
	shuffle($answers); //Randomize position of answers
	
	$answerKeyboard = array();
	foreach ($answers as $value) {
		if($value['id'] == $correctAnswerId)
			array_push($answerKeyboard, array((object) array('text' => $value['name'] , 'callback_data' => '{"answer":"correct"}')));
		else
			array_push($answerKeyboard, array((object) array('text' => $value['name'] , 'callback_data' => '{"answer":"wrong","correctAnswer":"'.$correctAnswerId.'"}')));
	}
	return $answerKeyboard;
}
function incrementTimesPlayed($chat_id) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('Update player SET times_played = times_played+1 WHERE chat_id = :chat_id');
	$stmt->bindParam(':chat_id', $chat_id); //Select one random picture
	$stmt->execute();
}
function incrementCorrectAnswer($chat_id) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('Update player SET no_correct_answers = no_correct_answers+1 WHERE chat_id = :chat_id');
	$stmt->bindParam(':chat_id', $chat_id); //Select one random picture
	$stmt->execute();
}
function getPlayerData($chat_id) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('SELECT times_played, no_correct_answers from player WHERE chat_id = :chat_id');
	$stmt->bindParam(':chat_id', $chat_id); 
	$stmt->execute();
	$result = $stmt->fetch(PDO::FETCH_ASSOC);
	
	$timesPlayed = $result['times_played'];
	$rightAnswers = $result['no_correct_answers'];
	$rightInPerc = round($rightAnswers/$timesPlayed*100,2);
	
	$string = "Total played: $timesPlayed%0ARight answers: $rightAnswers%0A%0A$rightInPerc% answered correct!";
	return $string;
}
function writeCorrectAnswerToDb($chat_id, $correct_answer) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('UPDATE player_state SET correct_answer = :correct_answer WHERE chat_id = :chat_id');
	$stmt->bindParam(':chat_id', $chat_id);
	$stmt->bindParam(':correct_answer', $correct_answer);
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
				case "startEasy":
					$question = createQuestion();
					updateMessage($chat_id, $message_id, $question[1], $question[0]);
					break;
				case "startExpert":
					$question = createExpertQuestion($chat_id);
					updateMessage($chat_id, $message_id, $question[1], $question[0]);
					break;
				case "next":
					$question = createQuestion();
					updateMessage($chat_id, $message_id, $question[1], $question[0]);
					break;
				case "dontKnow":
					$titleId = getCorrectIdExpert($chat_id);
					$titleName = getTitle($titleId)['name'];
					$question = createExpertQuestion($chat_id);
					updateMessage($chat_id, $message_id, "Right answer:%0A<b>$titleName</b>", buildActiveGameMenuExpert());
//					updateMessage($chat_id, $message_id, "<b>Right answer:%0A<b>$titleName</b>", buildActiveGameMenu());
					break;
				case "nextExpert":
					$question = createExpertQuestion($chat_id);
					updateMessage($chat_id, $message_id, $question[1], $question[0]);
					break;
				case "seeStats":
					apiRequest ( "answerCallbackQuery", array (
							"callback_query_id" => $callback_query_id,
							"show_alert" => true,
							"text" => getPlayerData($chat_id)
					));
					break;
				case "exit":
					updateMessage($chat_id, $message_id, "<b>Welcome to Guess The Game!</b>", buildMainMenu());
					break;
			}
		}
		else if(isset($JsonCallbackData->answer)) {
			$pickedOption = $JsonCallbackData->answer;
			switch ($pickedOption) {
				case "correct":
					updateMessage($chat_id, $message_id, "<b>That's correct! \xE2\x9C\x85</b>", buildActiveGameMenu());
					incrementCorrectAnswer($chat_id);
					break;
				case "wrong":
					$correctAnswerId = $JsonCallbackData->correctAnswer;
					$titleName = getTitle($correctAnswerId)['name'];
					updateMessage($chat_id, $message_id, "<b>That's wrong! \xE2\x9D\x8C</b>%0A%0ARight answer:%0A<b>$titleName</b>", buildActiveGameMenu());
					break;
			}
			incrementTimesPlayed($chat_id);
		}
		else if(isset($JsonCallbackData->expertAnswer)) {
			$pickedOption = $JsonCallbackData->expertAnswer;
			if(answerIsCorrect($pickedOption, $chat_id)) {
				updateMessage($chat_id, $message_id, "<b>That's correct! \xE2\x9C\x85</b>", buildActiveGameMenuExpert());
				incrementCorrectAnswer($chat_id);
			}
			else {
				$correct_id = getCorrectIdExpert($chat_id);
				$titleName = getTitle($correct_id)['name'];
				updateMessage($chat_id, $message_id, "<b>$pickedOption?%0AThat's wrong! \xE2\x9D\x8C</b>%0A%0ARight answer:%0A<b>$titleName</b>", buildActiveGameMenuExpert());
			}		
			incrementTimesPlayed($chat_id);
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
		else { // Input from user was free on keyboard. 
				
			if($suggestionsInlineKeyboard = buildSuggestionsInlineKeyboard($text)) {
				sendMessageAndInlineKeyboard($chat_id, "Select your answer.", $suggestionsInlineKeyboard);
			}
			else
				sendMessage($chat_id, "Nothing found for <b>$text</b>.");
		}
	
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