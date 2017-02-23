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
	$btnMyCollection = (object) array('text' => "My Collection" , 'callback_data' => '{"mainMenu":"collection"}');
	
	array_push($mainMenu,array($btnStartEasy),array($btnStartExpert),array($btnSeeStats),array($btnMyCollection));
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
		if (strpos($nameInDb, $searchString) !== false) {
			$element['perc'] = '100';
			array_push($suggestionsArray, $element);
		}
		else if(round($percent >50,2)) { //Get all titles with match rate of at least 50.00%
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
function queryRandomTitle() {
	
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$result = false;
	while ($result == false) { //Query as long as a result is retrieved. Some ids can be missing, so not all random generated ids work.
		$stmt = $db->prepare('SELECT name, id from title WHERE id = :id');
		$id= rand(1,TITLE_COUNT);
		$stmt->bindParam(':id', $id);
		$stmt->execute();
		$result = $stmt->fetch(PDO::FETCH_ASSOC);
	}
	return $result;
}
function queryRandomScreenshot() {
	
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$result = false;
	
	while ($result == false) { //Query as long as a result is retrieved. Some ids can be missing, so not all random generated ids work.
		$stmt = $db->prepare('SELECT * from picture WHERE id = :id');
		$id = rand(1,PICTURE_COUNT);
		$stmt->bindParam(':id', $id); //Select one random picture
		$stmt->execute();
		$result = $stmt->fetch(PDO::FETCH_ASSOC);
		
	}
	
	return $result;
}
function getScreenshotUrlById($screenshotId) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('SELECT url from picture WHERE id = :id');
	$stmt->bindParam(':id', $screenshotId); //Select one random picture
	$stmt->execute();
	$result = $stmt->fetch(PDO::FETCH_ASSOC);
	return $result['url'];
}
function createQuestion() {
	//1. Retrieve random picture from db
	$randomScreenshot = queryRandomScreenshot();
	$pictureUrl = $randomScreenshot['url'];
	//2. Get correct title
	$correctTitle = getTitle($randomScreenshot['title_id_FK']);
	//Retrieve 3 other random names
	$answers = array();
	for($i=0; $i<3; $i++) {
		$randomTitle = queryRandomTitle();
		array_push($answers, $randomTitle);
	}
	array_push($answers, $correctTitle);
	
	$inlineButtons = buildInlineButtons($answers, $correctTitle['id'], $randomScreenshot['id']);
	$array = array();
	array_push($array, $inlineButtons, "%0A%0A<a href='" . $pictureUrl . "'>&#160</a>What's this?");
	return($array);
	
}
function createExpertQuestion($chat_id) {
	//1. Retrieve random picture from db
	$randomScreenshot = queryRandomScreenshot();
	incrementScreenshotSeen($randomScreenshot);
	$pictureUrl = $randomScreenshot['url'];
	//Write correct answer to db
	writeCorrectAnswerToDb($chat_id, $randomScreenshot['title_id_FK'], $randomScreenshot['id']);
	//Create keyboard with buttons "I don't know. Next./Exit"
	$array = array();
	array_push($array, array(array((object) array('text' => "I don't know." , 'callback_data' => '{"mainMenu":"dontKnow"}'))), "%0A%0A<a href='" . $pictureUrl . "'>&#160</a>What's this? Type the name below.%0A{$randomScreenshot['no_correct_answers']} out of {$randomScreenshot['times_played']} people knew the answer.");
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
function buildInlineButtons($answers, $correctAnswerId, $screenshotId) {
	shuffle($answers); //Randomize position of answers
	
	$answerKeyboard = array();
	foreach ($answers as $value) {
		if($value['id'] == $correctAnswerId)
			array_push($answerKeyboard, array((object) array('text' => $value['name'] , 'callback_data' => '{"answer":"correct","screenshotId":"'.$screenshotId.'"}')));
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
function incrementTimesPlayedExpert($chat_id) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");

	$stmt = $db->prepare('Update player SET times_played_expert = times_played_expert+1 WHERE chat_id = :chat_id');
	$stmt->bindParam(':chat_id', $chat_id); //Select one random picture
	$stmt->execute();
}
function incrementCorrectAnswer($chat_id) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('Update player SET no_correct_answers = no_correct_answers+1 WHERE chat_id = :chat_id');
	$stmt->bindParam(':chat_id', $chat_id); 
	$stmt->execute();
}
function incrementCorrectAnswerExpert($chat_id) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");

	$stmt = $db->prepare('Update player SET no_correct_answers_expert = no_correct_answers_expert+1 WHERE chat_id = :chat_id');
	$stmt->bindParam(':chat_id', $chat_id);
	$stmt->execute();
}
function incrementScreenshotSeen($randomScreenshot) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	$stmt = $db->prepare('Update picture SET times_played = times_played+1 WHERE id = :id');
	$id = $randomScreenshot['id'];
	$stmt->bindParam(':id', $id);
	$stmt->execute();
}
function incrementCorrectAnswerScreenshot($chat_id) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	$stmt = $db->prepare('SELECT current_screenshot_id FROM player_state WHERE chat_id = :chat_id');
	$stmt->bindParam(':chat_id', $chat_id);
	$stmt->execute();
	$result = $stmt->fetch(PDO::FETCH_ASSOC);
	$screenId = $result['current_screenshot_id'];

	$stmt = $db->prepare('Update picture SET no_correct_answers = no_correct_answers+1 WHERE id = :id');
	$stmt->bindParam(':id', $screenId);
	$stmt->execute();
}
function savePicureToCollection($chat_id,$screenshotId) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8"); //INSERT INTO `gameGuesser`.`pictures_collected` (`chat_id`, `picture_id`) VALUES ('123', '456');
	$stmt = $db->prepare('INSERT INTO pictures_collected(chat_id, picture_id) VALUES (:chat_id, :picture_id)');
	$stmt->bindParam(':chat_id', $chat_id);
	$stmt->bindParam(':picture_id', $screenshotId);
	$stmt->execute();
}
function countCollectedScreenshots($chat_id){
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8"); //INSERT INTO `gameGuesser`.`pictures_collected` (`chat_id`, `picture_id`) VALUES ('123', '456');
	$stmt = $db->prepare('SELECT Count(*) FROM pictures_collected WHERE chat_id =:chat_id');
	$stmt->bindParam(':chat_id', $chat_id);
	$stmt->execute();
	$result = $stmt->fetch(PDO::FETCH_ASSOC);
	$noScreenshotsCollected = $result['Count(*)'];
	
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8"); //INSERT INTO `gameGuesser`.`pictures_collected` (`chat_id`, `picture_id`) VALUES ('123', '456');
	$stmt = $db->prepare('SELECT Count(*) FROM picture');
	$stmt->execute();
	$result = $stmt->fetch(PDO::FETCH_ASSOC);
	$noTotalScreenshots = $result['Count(*)'];
	
	return $noScreenshotsCollected . "/" . $noTotalScreenshots;
}
function inlineBtnMyCollectionPressed($chat_id, $message_id) {
	
	$unlockedGamesButtons = getUnlockedGamesButtons($chat_id);
	$inlineKeyboard = array();
	
	foreach ($unlockedGamesButtons as $value) {
		array_push($inlineKeyboard, array((object) array('text' => $value['name'], 'callback_data' => '{"collectionId":"'.$value['id'].'", "elementNo":"'.$value['backFromTitleView'].'"}')));
	}
	array_push($inlineKeyboard, array((object) array('text' => "Back \xE2\x86\xA9\xEF\xB8\x8F", 'callback_data' => '{"mainMenu":"exit"}')));
	
	inlinePagingBtnPressed($chat_id, "1", $message_id);
}
function inlinePagingBtnPressed($chat_id, $pressedButton, $message_id) {

	$inlineKeyboard = array(); //Represents all buttons including games + paging buttons + back button. First all games are added then paging interface
	$pagingInterface = array(); //Represents paging interface alone

	$unlockedGamesButtons = getUnlockedGamesButtons($chat_id);
	
	$pressedButtonVal = intval($pressedButton); //Get button pressed by user as integer (e.g 1,2,3,4,5...)

	$pages = ceil(sizeof($unlockedGamesButtons)/10); //Calculates the number of pages needed. When 28 games available => 3 pages needed. 10 Buttons per single page.  28/10 = 2,3 ->ceil->3

	//Fill up keyboard with games. If button "1" pressed, elements from [0] to [9] on array. When button "2" pressed add elements from [10] to [19] on array and so on...
	$counter = 0;
	foreach ($unlockedGamesButtons as $k => $element) {
		if(($k+10 >= $pressedButtonVal*10) && ($k+10 < $pressedButtonVal*10+10)) {
			array_push($inlineKeyboard, $element);
			$counter++;
		}
	}


	if($pages > 4) { //More than 40 buttons need to be displayed. This means we need buttons with ">>/<<". Interface will look like this " -1- 2 3 4> 13>> "
		if($pressedButton == "1") {
			$btnPos1 = "· 1 ·";
			$btnPos2 = "2";
			$btnPos3 = "3";
			$btnPos4 = "4 ›";
			$btnPos5 = strval($pages) . ' ››';
		}
		else if($pressedButton == "2") {
			$btnPos1 = "1";
			$btnPos2 = "· 2 · ";
			$btnPos3 = "3";
			$btnPos4 = "4 ›";
			$btnPos5 = strval($pages) . ' ››';
		}
		else if($pressedButton == "3") {
			$btnPos1 = "1";
			$btnPos2 = "2";
			$btnPos3 = "· 3 ·";
			$btnPos4 = "4 ›";
			$btnPos5 = strval($pages) . ' ››';
		}
		else if($pressedButton == $pages) {
			$btnPos1 = "‹‹ 1";
			$btnPos2 = '‹ ' . strval($pages-3);
			$btnPos3 = strval($pages-2);
			$btnPos4 = strval($pages-1);
			$btnPos5 = '· ' . strval($pages) . ' ·' ;
		}
		else if(intval($pressedButton) == $pages-2) {
			$btnPos1 = "‹‹ 1";
			$btnPos2 = '‹ ' . strval($pages-3);
			$btnPos3 = '· ' . strval($pages-2) . ' ·';
			$btnPos4 = strval($pages-1);
			$btnPos5 = strval($pages);

		}
		else if(intval($pressedButton) == $pages-1) {
			$btnPos1 = "‹‹ 1";
			$btnPos2 = '‹ ' . strval($pages-3);
			$btnPos3 = strval($pages-2);
			$btnPos4 = '· ' . strval($pages-1) . ' ·';
			$btnPos5 = strval($pages);

		}

		else {
			$btnPos1 = '‹‹ 1';
			$btnPos2 = '‹ ' . strval(intval($pressedButton)-1);
			$btnPos3 = '· ' . strval($pressedButton) . ' ·';
			$btnPos4 = strval(intval($pressedButton)+1) . ' ›';
			$btnPos5 = strval($pages) . ' ››';

		}
			
		$btnPos1 = (object) array('text'=>$btnPos1 , 'callback_data' => '{"inlinePaging":"' . str_replace(array("‹","›","·"," "), "",$btnPos1) . '"}'  );
		$btnPos2 = (object) array('text'=>$btnPos2 , 'callback_data' => '{"inlinePaging":"' . str_replace(array("‹","›","·"," "), "",$btnPos2) . '"}'  );
		$btnPos3 = (object) array('text'=>$btnPos3 , 'callback_data' => '{"inlinePaging":"' . str_replace(array("‹","›","·"," "), "",$btnPos3) . '"}'  );
		$btnPos4 = (object) array('text'=>$btnPos4 , 'callback_data' => '{"inlinePaging":"' . str_replace(array("‹","›","·"," "), "",$btnPos4) . '"}'  );
		$btnPos5 = (object) array('text'=>$btnPos5 , 'callback_data' => '{"inlinePaging":"' . str_replace(array("‹","›","·"," "), "",$btnPos5) . '"}'  );
			
		//Add paging buttons to keyboard below.
		array_push($pagingInterface, $btnPos1, $btnPos2, $btnPos3, $btnPos4, $btnPos5);

	}
	else if($pages != 1) { //Less than 40 buttons need to be shown. Paging interface will look like this "1,2,3,4"

			
		$i = 1;
		for($i=1 ; $i<=$pages; $i++) { //Builds inline paging interface. e.g "1,·2·,3"
			if($pressedButtonVal == $i)
				array_push($pagingInterface , (object) array('text'=> '· ' . strval($i) .' ·' , 'callback_data' => '{"inlinePaging":"' . strval($i) . '"}' )); //Mark current page with "·3·" symbol
				else
					array_push($pagingInterface , (object) array('text'=>strval($i) , 'callback_data' => '{"inlinePaging":"' . strval($i) . '"}' ));
		}
			
	}

	$backBtn = array('text' => "\xE2\x86\xA9\xEF\xB8\x8F Back" , 'callback_data' => '{"mainMenu":"exit"}');

	array_push($inlineKeyboard, $pagingInterface, array($backBtn)); //Place paging buttons + back button below games button
	updateMessage($chat_id, $message_id, "<code>".countCollectedScreenshots($chat_id)." Screenshots collected</code>", $inlineKeyboard); //Show keyboard with games,paging interface and count of update types at top as text

}
function getUnlockedGamesButtons($chat_id) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8"); //INSERT INTO `gameGuesser`.`pictures_collected` (`chat_id`, `picture_id`) VALUES ('123', '456');
	$stmt = $db->prepare('SELECT title.id, name FROM pictures_collected INNER join picture ON pictures_collected.picture_id = picture.id INNER JOIN title ON title.id = title_id_FK WHERE chat_id = :chat_id ORDER by name');
	$stmt->bindParam(':chat_id', $chat_id);
	$stmt->execute();
	$result = $stmt->fetchAll(PDO::FETCH_ASSOC);
	
	$buttonsArray = array();
	$counter = 0;
	foreach ($result as $value) {
		array_push($buttonsArray, array((object) array('text' => $value['name'], 'callback_data' => '{"collectionId":"'.$value['id'].'","backFromTitleView":"'.$counter.'"}')));
		$counter++;
	}
	
	return $buttonsArray;
}
function buildTitleScreenshotsOverview($chat_id, $titleId, $elementNo) {
	$unlockedScreenshots = getUnlockedScreenshots($chat_id, $titleId); 
	$inlineKeyboard = array();
	
	$counter = 1;
	foreach ($unlockedScreenshots as $value) {
		array_push($inlineKeyboard, array((object) array('text' => "#$counter", 'callback_data' => '{"screenshotId":"'.$value['id'].'","titleId":"'.$titleId.'","elementNo":"'.$elementNo.'"}')));
		$counter++;
	}
	array_push($inlineKeyboard, array((object) array('text' => "Back \xE2\x86\xA9\xEF\xB8\x8F", 'callback_data' => '{"backFromTitleView":"'.$elementNo.'"}')));
	return $inlineKeyboard;
}
function getUnlockedScreenshots($chat_id, $titleId) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8"); //INSERT INTO `gameGuesser`.`pictures_collected` (`chat_id`, `picture_id`) VALUES ('123', '456');
	$stmt = $db->prepare('SELECT picture.id, url FROM pictures_collected INNER join picture ON pictures_collected.picture_id = picture.id INNER JOIN title ON title.id = title_id_FK WHERE chat_id = :chat_id AND title.id = :titleId');
	$stmt->bindParam(':chat_id', $chat_id);
	$stmt->bindParam(':titleId', $titleId);
	$stmt->execute();
	$result = $stmt->fetchAll(PDO::FETCH_ASSOC);
	return $result;
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
function writeCorrectAnswerToDb($chat_id, $correct_answer, $screenshot_id) {
	$db = new PDO ( DSN.';dbname='.dbname, username, password );
	$db->exec("SET NAMES utf8");
	
	$stmt = $db->prepare('UPDATE player_state SET correct_answer = :correct_answer, current_screenshot_id = :screenshot_id WHERE chat_id = :chat_id');
	$stmt->bindParam(':chat_id', $chat_id);
	$stmt->bindParam(':correct_answer', $correct_answer);
	$stmt->bindParam(':screenshot_id', $screenshot_id);
	$result = $stmt->execute();
}
function processCallbackQuery($callbackQuery) {
	
	$chat_id = $callbackQuery['from']['id'];
	$callbackData = $callbackQuery['data'];
	$callback_query_id = $callbackQuery['id'];
	$message_id = $callbackQuery['message']['message_id'];
	//debug($callbackData);
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
					incrementTimesPlayedExpert($chat_id);
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
				case "collection":
					inlineBtnMyCollectionPressed($chat_id, $message_id);
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
					$screenshotId = $JsonCallbackData->screenshotId;
					savePicureToCollection($chat_id,$screenshotId);
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
				incrementCorrectAnswerExpert($chat_id);
				incrementCorrectAnswerScreenshot($chat_id);			
			}
			else {
				$correct_id = getCorrectIdExpert($chat_id);
				$titleName = getTitle($correct_id)['name'];
				$wrongTitleName = getTitle($pickedOption)['name'];
				updateMessage($chat_id, $message_id, "<b>$wrongTitleName?%0AThat's wrong! \xE2\x9D\x8C</b>%0A%0ARight answer:%0A<b>$titleName</b>", buildActiveGameMenuExpert());
			}		
			incrementTimesPlayedExpert($chat_id);
		}
		else if(isset($JsonCallbackData->collectionId)) {
			$titleId = $JsonCallbackData->collectionId;
			$elementNo = $JsonCallbackData->backFromTitleView;
			$titleName = getTitle($titleId)['name'];
			updateMessage($chat_id, $message_id, "<code>$titleName</code>", buildTitleScreenshotsOverview($chat_id, $titleId, $elementNo));
		}
		else if(isset($JsonCallbackData->screenshotId)) {
			$screenshotId = $JsonCallbackData->screenshotId;
			$titleId = $JsonCallbackData->titleId;
			$titleName = getTitle($titleId)['name'];
			$elementNo = $JsonCallbackData->elementNo;
			
			$screenshotUrl = getScreenshotUrlById($screenshotId);
			updateMessage($chat_id, $message_id, "<a href='" . $screenshotUrl . "'>&#160</a><code>$titleName</code>", array(array((object) array('text' => "Back \xE2\x86\xA9\xEF\xB8\x8F", 'callback_data' => '{"collectionId":"'.$titleId.'","backFromTitleView":"'.$elementNo.'"}'))));
		}
		else if((isset($JsonCallbackData->inlinePaging))) {
			$pressedButton = $JsonCallbackData->inlinePaging; //Can be 1,2,3, >>, <<
			inlinePagingBtnPressed($chat_id, $pressedButton, $message_id);
		}
		else if((isset($JsonCallbackData->backFromTitleView))) { // Inlinebtn "back" pressed on games's screenshotview
				
			$elementNo = $JsonCallbackData->backFromTitleView;
			if($elementNo==0) $elementNo=1; //Quick fix for element number 0 on array. Would lead to 0 division
			if(!($elementNo%10)) $elementNo++; //Quick fix for element number 10,20... This would lead to 10/10 = 1, 20/10 = 2 and ceilingOperator is useless. Will stay the same
				
			$pressedButton = ceil($elementNo/10); //E.g: Element number 26 would be on page 3.
			inlinePagingBtnPressed($chat_id, $pressedButton, $message_id);
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