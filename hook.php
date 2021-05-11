<?php

declare(strict_types=1);

require_once('config.php');

class Api {

	private static function exec_curl_request($handle) {
		$response = curl_exec($handle);

		if ($response === false) {
			$errno = curl_errno($handle);
			$error = curl_error($handle);
			error_log("Curl returned error $errno: $error\n");
			curl_close($handle);
			return false;
		}

		$http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
		curl_close($handle);

		if ($http_code >= 500) {
			// do not wat to DDOS server if something goes wrong
			sleep(10);
			return false;
		} else if ($http_code != 200) {
			$response = json_decode($response, true);
			error_log("Request has failed with error {$response['error_code']}: {$response['description']}\n");
			if ($http_code == 401) {
				throw new Exception('Invalid access token provided');
			}
			return false;
		} else {
			$response = json_decode($response, true);
			if (isset($response['description'])) {
				error_log("Request was successful: {$response['description']}\n");
			}
			$response = $response['result'];
		}
		return $response;
	}

	public static function __callStatic($method, $args) {
		$parameters = $args[0];

		if (!is_string($method)) {
			error_log("Method name must be a string\n");
			return false;
		}

		if (!$parameters) {
			$parameters = array();
		} else if (!is_array($parameters)) {
			error_log("Parameters must be an array\n");
			return false;
		}

		$parameters["method"] = $method;

		if (isset($args[1])) {
			$json = json_encode($parameters);
			if ($args[1] === true) {
				header("Content-Type: application/json");
				echo $json;
				return true;
			}
			if ($args[1] > 0) {
				sleep($args[1]);
			}
		}

		$handle = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/');
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($handle, CURLOPT_TIMEOUT, 60);
		curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($parameters));
		curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

		return Api::exec_curl_request($handle);
	}
}

class Request {

	private $msg;
	private $chat;
	private $user;

	public function __construct(Data $msg, Chat $chat, User $user) {
		$this->msg = $msg;
		$this->chat = $chat;
		$this->user = $user;
	}

	public function sendText(String $text) {
		return Api::sendMessage([
			'chat_id' => $this->chat->id,
			'text' => $text
		]);
	}

	public function sendReplyText(String $text) {
		return Api::sendMessage([
			'chat_id' => $this->chat->id,
			'text' => $text,
			'reply_to_message_id' => $this->msg->message_id
		]);
	}

	public function delMsg() {
		return Api::sendMessage([
			'chat_id' => $this->chat->id,
			'message_id' => $this->msg->message_id
		]);
	}
}

class Data {

	protected $data;

	public function	__construct(array $data) {
		$this->data = $data;
	}

	public function has(String $name) {
		return isset($this->data[$name]);
	}

	public function __get($name) {
		return $this->data[$name] ?? null;
	}
}

class Chat extends Data {

	public function isPrivate() {
		return $this->data['type'] == 'private';
	}

	public function isGroup() {
		return $this->data['type'] == 'group';
	}

	public function isSuperGroup() {
		return $this->data['type'] == 'supergroup';
	}

	public function isChannel() {
		return $this->data['type'] == 'channel';
	}

	public function is(Int $id) {
		return $this->data['id'] == $id;
	}

	public function leave() {
		return Api::leaveChat(['chat_id' => $this->data['id']]);
	}
}

class User extends Data {

	public function isBot() {
		return $this->data['is_bot'];
	}

	public function isNotBot() {
		return !$this->data['is_bot'];
	}
}

class Text {

	public function __construct($text) {
		$this->text = $text;
	}

	public function is(String $string) {
		return $this->text == $string;
	}

	public function isBegin(String $string) {
		return strpos($this->text, $string) === 0;
	}

	public function isCmd(String $string) {
		return $this->isBegin($string) or $this->isBegin($string . BOT_USERNAME);
	}

	public function has(String $string) {
		return strpos($this->text, $string) !== false;
	}
}

class Permissions {

	private $admins = [];
	private $user;

	public function __construct(Chat $chat, User $user) {
		$admins = api::getChatAdministrators(['chat_id' => $chat->id]);
		foreach ($admins as $val) {
			$this->admins[$val['user']['id']] = $val;
		}
		$this->user = $user;
	}

	public function isAdmin(User $user = null) {
		$user = $user ?? $this->user;
		if ($user->id == USER_ID) {
			return true;
		}
		return isset($this->admins[$user->id]);
	}

	public function has(String $permissions, User $user = null) {
		$user = $user ?? $this->user;
		if (isset($this->admins[$user->id])) {
			if ($this->admins[$user->id]['status'] == 'creator') {
				return true;
			}
			return $this->admins[$user->id][$permissions];
		}
		return false;
	}
}

function message($data) {
	$msg = new Data($data);
	$chat = new Chat($msg->chat);
	if ($chat->isChannel()) {
		$chat->leave();
		return;
	}

	$user = new User($msg->from);
	$req = new Request($msg, $chat, $user);
	if ($chat->isPrivate()) {
		if ($msg->has('text')) {
			$text = new Text($msg->text);
			if ($text->isCmd('/start')) {
				$req->sendText('startPrivate');
			}
		}
		return;
	}

	if ($msg->has('text')) {
		$text = new Text($msg->text);
		if ($text->isCmd('/start')) {
			$req->sendText('startGroup');
		}
		$pm = new Permissions($chat, $user);
		if ($pm->isAdmin()) {
			// $req->sendText('isAdmin');
		}
	} else if ($msg->has('new_chat_members')) {
		foreach ($msg->new_chat_members as $newMember) {
			$newUser = new User($newMember);
			if ($newUser->id != BOT_ID) {
				$req->sendText('newMember');
			}
		}
	}
}

function editedMessage($data) {
}

function callbackQuery($data) {
}

function app($data) {
	$app = new Data($data);
	if ($app->has('message')) {
		message($app->message);
	} else if ($app->has('edited_message')) {
		editedMessage($app->edited_message);
	} else if ($app->has('callback_query')) {
		callbackQuery($app->callback_query);
	}
}

function webhook() {
	$update = json_decode(file_get_contents("php://input"), true);

	if (!$update) {
		$url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$setWebhook = api::setWebhook(['url' => $url]);
		echo '<pre>', print_r($setWebhook, true), '</pre>';
		exit;
	}

	app($update);
}

function longPolling() {
	$i = 0;
	while (true) {
		$api = api::getUpdates(['offset' => $i, 'timeout' => 50]);
		if (isset($api[0])) {
			foreach ($api as $update) {
				echo "--------------\n";
				print_r($update);
				app($update);
			}
			$i = intval($api[count($api) - 1]['update_id']) + 1;
		} else {
			print_r($api);
			sleep(3);
		}
	}
}

// webhook();
// api::deleteWebhook([]);
longPolling();
