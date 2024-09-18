<?php
// Khai báo chế độ strict types cho PHP
declare(strict_types=1);
session_start();

// Bao gồm autoloader để tải các lớp cần thiết
require_once 'vendor/autoload.php';

// Bao gồm các lớp cần thiết từ Bitrix24 PHP SDK và các thư viện khác
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\MemoryUsageProcessor;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

// Tạo logger để ghi log (không bắt buộc)
$log = new Logger('bitrix24-php-sdk');
$log->pushHandler(new StreamHandler('bitrix24-php-sdk.log'));
$log->pushProcessor(new MemoryUsageProcessor(true, true));

// Tạo đối tượng Request từ HTTP request hiện tại
$request = Request::createFromGlobals();

// Tạo ServiceBuilderFactory với EventDispatcher và Logger
$serviceBuilderFactory = new ServiceBuilderFactory(new EventDispatcher(), $log);


// Định nghĩa các hằng số cho CLIENT_ID và CLIENT_SECRET
define('WEBHOOK_URL', 'https://www.uchat.com.au/api/iwh/020dfaf0037d162d394fbb65b192e2e0'); // Thay bằng URL webhook của bạn

// Khởi tạo ApplicationProfile với CLIENT_ID và CLIENT_SECRET
$appProfile = ApplicationProfile::initFromArray([
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID' => getenv('CLIENT_ID'),
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET' => getenv('CLIENT_SECRET'),
    'BITRIX24_PHP_SDK_APPLICATION_SCOPE' => 'crm,imbot,impoenlines,imconnector,im.import,messagesevice,im', // Phạm vi cần thiết cho chatbot
]);

// Lấy thông tin AUTH từ request
$authToken = AuthToken::initFromRequest($request);

// Khởi tạo dịch vụ Bitrix24
$bitrix24 = $serviceBuilderFactory->getServiceBuilder($appProfile, $authToken, $request->get('DOMAIN'));

// Lấy dữ liệu từ request
$requestData = $request->request->all();

// Ghi log dữ liệu nhận được (tùy chọn)
$log->info('Received request from Bitrix24', $requestData);

// receive event "new message for bot"
if ($_REQUEST['event'] == 'ONIMBOTMESSAGEADD')
{
	// Check the event - If the application token is authorized
	if (!isset($appsConfig[$_REQUEST['auth']['application_token']]))
		return false;

	// Ensure the message is from a valid chat entity type
	if ($_REQUEST['data']['PARAMS']['CHAT_ENTITY_TYPE'] != 'LINES')
		return false;

	// Call a function to process the message	
	itrRun($_REQUEST['auth']['application_token'], $_REQUEST['data']['PARAMS']['DIALOG_ID'], $_REQUEST['data']['PARAMS']['FROM_USER_ID'], $_REQUEST['data']['PARAMS']['MESSAGE']);
}

// Handle the event when the bot joins a chat
if ($_REQUEST['event'] == 'ONIMBOTJOINCHAT')
{
	// check the event - authorize this event or not
	if (!isset($appsConfig[$_REQUEST['auth']['application_token']]))
		return false;

	// Ensure the chat is Open Lines ('LINES') entity type
	if ($_REQUEST['data']['PARAMS']['CHAT_ENTITY_TYPE'] != 'LINES')
		return false;

	// Initiate the bot's main function	
	itrRun($_REQUEST['auth']['application_token'], $_REQUEST['data']['PARAMS']['DIALOG_ID'], $_REQUEST['data']['PARAMS']['USER_ID']);
}

// receive event "delete chat-bot"
else if ($_REQUEST['event'] == 'ONIMBOTDELETE')
{
	// check the event - authorize this event or not
	if (!isset($appsConfig[$_REQUEST['auth']['application_token']]))
		return false;

	// unset application variables
	unset($appsConfig[$_REQUEST['auth']['application_token']]);

	// save params
	saveParams($appsConfig);

	// write debug log
	writeToLog($_REQUEST['event'], 'ImBot unregister');
}

// Receive event "Application install"
else if ($_REQUEST['event'] == 'ONAPPINSTALL')
{
	/* Handler for events
	In the given code snippet, $_SERVER['SERVER_NAME'] plays a key role in constructing the $handlerBackUrl by providing the domain name (or IP address) of the server where the script is running. 
	This is important because $handlerBackUrl needs to contain the full URL that the bot can use to communicate back with the server when handling various events.

	Here's a breakdown of how $_SERVER['SERVER_NAME'] fits into $handlerBackUrl:

	- $handlerBackUrl is constructed as the full URL for event handlers that the bot will register. It needs to include:
	1. The protocol (http or https), determined by checking if the server is running on port 443 or using HTTPS ($_SERVER['SERVER_PORT'] == 443 || $_SERVER["HTTPS"] == "on").
	2. The server's domain name or IP address, provided by $_SERVER['SERVER_NAME']. This variable holds the domain name of the host where the request is being processed (like example.com).
	3. The port number, appended to the URL if it's not the default HTTP (80) or HTTPS (443) port.
	4. The script's path ($_SERVER['SCRIPT_NAME']), representing the path to the current executing script (like /bot.php).
	
	So, $_SERVER['SERVER_NAME'] ensures that the bot's callback URL points to the correct domain or IP address, where it can be reached for event handling.

	For example:
	If $_SERVER['SERVER_NAME'] is example.com, and the server is running over HTTPS (port 443), then $handlerBackUrl might look like: https://example.com/bot.php.
	*/
	$handlerBackUrl = ($_SERVER['SERVER_PORT']==443||$_SERVER["HTTPS"]=="on"? 'https': 'http')."://".$_SERVER['SERVER_NAME'].(in_array($_SERVER['SERVER_PORT'], Array(80, 443))?'':':'.$_SERVER['SERVER_PORT']).$_SERVER['SCRIPT_NAME'];

	// If your application supports different localizations
	// use $_REQUEST['data']['LANGUAGE_ID'] to load correct localization

	// Register the bot with the Bitrix24 API
	$result = restCommand('imbot.register', Array(
		'CODE' => 'itrbot',
		'TYPE' => 'O',
		'EVENT_MESSAGE_ADD' => $handlerBackUrl,
		'EVENT_WELCOME_MESSAGE' => $handlerBackUrl,
		'EVENT_BOT_DELETE' => $handlerBackUrl,
		'OPENLINE' => 'Y',
		'PROPERTIES' => Array(
			'NAME' => 'ITR Bot for Open Channels #'.(count($appsConfig)+1),
			'WORK_POSITION' => "Get ITR menu for you open channel",
			'COLOR' => 'RED',
		)
	), $_REQUEST["auth"]);
	
	// Retrieve the bot ID from the result
	$botId = $result['result'];

	// Bind event handlers for app update
	$result = restCommand('event.bind', Array(
		'EVENT' => 'OnAppUpdate',
		'HANDLER' => $handlerBackUrl
	), $_REQUEST["auth"]);

	// Store the new bot configuration
	$appsConfig[$_REQUEST['auth']['application_token']] = Array(
		'BOT_ID' => $botId,
		'LANGUAGE_ID' => $_REQUEST['data']['LANGUAGE_ID'],
		'AUTH' => $_REQUEST['auth'],
	);
	saveParams($appsConfig);

	// Log the bot registration
	writeToLog(Array($botId), 'ImBot register');
}

// Receive event "Application update"
else if ($_REQUEST['event'] == 'ONAPPUPDATE')
{
	// Check the event: check for valid application token
	if (!isset($appsConfig[$_REQUEST['auth']['application_token']]))
		return false;

	if ($_REQUEST['data']['VERSION'] == 2)
	{
		// Some logic in update event for VERSION 2
		// You can execute any method RestAPI, BotAPI or ChatAPI, for example delete or add a new command to the bot
		/*
		$result = restCommand('...', Array(
			'...' => '...',
		), $_REQUEST["auth"]);
		*/

		/*
		For example delete "Echo" command:

		$result = restCommand('imbot.command.unregister', Array(
			'COMMAND_ID' => $appsConfig[$_REQUEST['auth']['application_token']]['COMMAND_ECHO'],
		), $_REQUEST["auth"]);
		*/
	}
	else
	{
		// send answer message
		$result = restCommand('app.info', array(), $_REQUEST["auth"]);
	}

	// write debug log
	writeToLog($result, 'ImBot update event');
}

/**
 * Function to run ITR (interactive text response) menu.
 *
 * @param $portalId - ID of the portal where the bot operates.
 * @param $dialogId - ID of the dialog where the message was sent.
 * @param $userId - ID of the user sending the message.
 * @param string $message - Message content.
 * @return bool
 */

 function itrRun($portalId, $dialogId, $userId, $message = '')
{
	if ($userId <= 0)
		return false;

	// Define the main menu
	$menu0 = new ItrMenu(0);
	$menu0->setText('Main menu (#0)');
	$menu0->addItem(1, 'Text', ItrItem::sendText('Text message (for #USER_NAME#)'));
	$menu0->addItem(2, 'Text without menu', ItrItem::sendText('Text message without menu', true));
	$menu0->addItem(3, 'Open menu #1', ItrItem::openMenu(1));
	$menu0->addItem(0, 'Wait operator answer', ItrItem::sendText('Wait operator answer', true));

	// Define the secondary menu
	$menu1 = new ItrMenu(1);
	$menu1->setText('Second menu (#1)');
	$menu1->addItem(2, 'Transfer to queue', ItrItem::transferToQueue('Transfer to queue'));
	$menu1->addItem(3, 'Transfer to user', ItrItem::transferToUser(1, false, 'Transfer to user #1'));
	$menu1->addItem(4, 'Transfer to bot', ItrItem::transferToBot('marta', true, 'Transfer to bot Marta', 'Marta not found :('));
	$menu1->addItem(5, 'Finish session', ItrItem::finishSession('Finish session'));
	$menu1->addItem(6, 'Exec function', ItrItem::execFunction(function($context){
		// Example: send a message when function is executed
		$result = restCommand('imbot.message.add', Array(
			"DIALOG_ID" => $_REQUEST['data']['PARAMS']['DIALOG_ID'],
			"MESSAGE" => 'Function executed (action)',
		), $_REQUEST["auth"]);
		writeToLog($result, 'Exec function');
	}, 'Function executed (text)'));
	
	/*  Example of using a custom function: send bot data and querry ($prompt) to OpenAI, 
	get  response and send it back to user (MESSAGE in imbot.message.add)

	$menu1->addItem(6, 'Exec function', ItrItem::execFunction(function($context){
    // Thông tin yêu cầu đến OpenAI
    $apiKey = 'YOUR_OPENAI_API_KEY';
    $prompt = "Hello, how are you?";

    // Cấu hình cURL để gọi API OpenAI
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.openai.com/v1/completions",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ),
        CURLOPT_POSTFIELDS => json_encode(array(
            "model" => "text-davinci-003", // Mô hình bạn muốn sử dụng
            "prompt" => $prompt,
            "max_tokens" => 50 // Số lượng token tối đa OpenAI trả về
        ))
    ));

    // Thực hiện yêu cầu và nhận kết quả từ OpenAI
    $response = curl_exec($curl);
    curl_close($curl);

    // Giả sử kết quả trả về là JSON, phân tích kết quả
    $openAiResponse = json_decode($response, true);
    $generatedText = $openAiResponse['choices'][0]['text'];

    // Gửi tin nhắn có chứa kết quả từ OpenAI tới người dùng trong Bitrix24
    $result = restCommand('imbot.message.add', Array(
        "DIALOG_ID" => $_REQUEST['data']['PARAMS']['DIALOG_ID'],
        "MESSAGE" => "OpenAI trả lời: " . $generatedText,
    ), $_REQUEST["auth"]);

    // Ghi lại log kết quả
    writeToLog($result, 'Exec function');
	}, 'Function executed (text)'));
	*/
	
	// --------------------****--------------------
	
	/*
	$menu1->addItem(6, 'Exec function', ItrItem::execFunction(function($context){
    
	// Lấy itemId mà người dùng đã chọn từ request tự động
    $userChoice = 'Item ' . $_REQUEST['data']['PARAMS']['MESSAGE']; // Dữ liệu của item đã chọn từ người dùng

	// Lựa chọn của người dùng bằng cách điển thủ công nội dung muốn gửi
    $userChoice = 'Item 6';
	
	// URL Webhook của Make.com
    $webhookUrl = 'https://hook.make.com/abc123xyz';

    // Thông tin định danh từ Bitrix24
    $botId = $_REQUEST['auth']['application_token'];
    $chatId = $_REQUEST['data']['PARAMS']['CHAT_ENTITY_TYPE'];
    $dialogId = $_REQUEST['data']['PARAMS']['DIALOG_ID'];

    // Dữ liệu cần gửi tới Webhook Make.com
    $data = array(
        'choice' => $userChoice,
        'bot_id' => $botId,
        'chat_id' => $chatId,
        'dialog_id' => $dialogId
    );

    // Cấu hình cURL để gửi yêu cầu POST tới Webhook Make.com
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $webhookUrl,  // URL của Webhook
        CURLOPT_RETURNTRANSFER => true,  // Trả về phản hồi
        CURLOPT_POST => true,  // Phương thức POST
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),  // Định dạng JSON
        CURLOPT_POSTFIELDS => json_encode($data)  // Dữ liệu gửi đi
    ));

    // Thực hiện yêu cầu và nhận phản hồi từ Webhook
    $response = curl_exec($curl);
    curl_close($curl);

    // Phân tích phản hồi từ Webhook (giả sử phản hồi dạng JSON)
    $jsonResponse = json_decode($response, true);

    // Lấy giá trị 'response' từ phản hồi
    $responseMessage = isset($jsonResponse['response']) ? $jsonResponse['response'] : 'Không có phản hồi hợp lệ';

    // Gửi phản hồi từ Make.com về cho người dùng trong Bitrix24
    $result = restCommand('imbot.message.add', Array(
        "DIALOG_ID" => $_REQUEST['data']['PARAMS']['DIALOG_ID'],
        "MESSAGE" => "Make.com trả lời: " . $responseMessage,
    ), $_REQUEST["auth"]);

    // Ghi lại log phản hồi từ Webhook
    writeToLog($result, 'Webhook Response');
	}, 'Function executed (text)'));
	*/
	$menu1->addItem(9, 'Back to main menu', ItrItem::openMenu(0));

	$itr = new Itr($portalId, $dialogId, 0, $userId);
	$itr->addMenu($menu0);
	$itr->addMenu($menu1);
	$itr->run(prepareText($message));

	return true;
}


/**
 * Save application configuration.
 * WARNING: this method is only created for demonstration, never store config like this
 *
 * @param $params
 * @return bool
 */

function saveParams($params)
{
	$config = "<?php\n";
	$config .= "\$appsConfig = ".var_export($params, true).";\n";
	$config .= "?>";

	file_put_contents(__DIR__."/config.php", $config);

	return true;
}

/**
 * Send a REST API command to Bitrix24.
 * @param $method - Method name for Bitrix REST API.
 * @param array $params - Parameters for the method.
 * @param array $auth - Authorization data.
 * @param boolean $authRefresh - Flag to refresh the authorization token if expired.
 * @return mixed
 */

function restCommand($method, array $params = Array(), array $auth = Array(), $authRefresh = true)
{
	// $auth["client_endpoint"] là URL gốc để truy cập API của Bitrix24.
	// $method là tên của phương thức API mà bạn muốn gọi (ví dụ: 'imbot.message.add').
	// $queryData là dữ liệu sẽ được gửi tới API. Dữ liệu này bao gồm các tham số ($params) và access_token để xác thực.
	$queryUrl = $auth["client_endpoint"].$method;
	$queryData = http_build_query(array_merge($params, array("auth" => $auth["access_token"])));

	writeToLog(Array('URL' => $queryUrl, 'PARAMS' => array_merge($params, array("auth" => $auth["access_token"]))), 'ImBot send data');

	// Execute the REST API call using cURL
	$curl = curl_init(); // khởi tạo phiên làm việc với cURL.
	// Các tùy chọn của cURL (CURLOPT_POST, CURLOPT_URL, CURLOPT_POSTFIELDS) được thiết lập để gửi một yêu cầu POST tới URL API.
	curl_setopt_array($curl, array(
		CURLOPT_POST => 1,
		CURLOPT_HEADER => 0,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_SSL_VERIFYPEER => 1,
		CURLOPT_URL => $queryUrl,
		CURLOPT_POSTFIELDS => $queryData,
	));

	$result = curl_exec($curl); // thực hiện yêu cầu và lưu kết quả trả về.
	curl_close($curl); // đóng phiên làm việc với cURL sau khi yêu cầu được thực hiện.

	// Phản hồi từ API Bitrix24 được trả về dưới dạng chuỗi JSON, và ở đây ta phân tích chuỗi JSON thành mảng PHP (json_decode($result, 1)).
	$result = json_decode($result, 1); 

	// Nếu phản hồi từ API chứa lỗi liên quan đến việc token hết hạn (expired_token hoặc invalid_token), 
	// hàm sẽ gọi restAuth() để làm mới token xác thực.
	if ($authRefresh && isset($result['error']) && in_array($result['error'], array('expired_token', 'invalid_token')))
	{
		$auth = restAuth($auth);
		if ($auth)
		{
			$result = restCommand($method, $params, $auth, false);
		}
	}

	return $result;
}

/**
 * Get new authorize data if you authorize is expire.
 *
 * @param array $auth - Authorize data, received from event
 * @return bool|mixed
 */
function restAuth($auth)
{
	if (!CLIENT_ID || !CLIENT_SECRET)
		return false;

	if(!isset($auth['refresh_token']))
		return false;

	$queryUrl = 'https://oauth.bitrix.info/oauth/token/';
	$queryData = http_build_query($queryParams = array(
		'grant_type' => 'refresh_token',
		'client_id' => CLIENT_ID,
		'client_secret' => CLIENT_SECRET,
		'refresh_token' => $auth['refresh_token'],
	));

	writeToLog(Array('URL' => $queryUrl, 'PARAMS' => $queryParams), 'ImBot request auth data');

	$curl = curl_init();

	curl_setopt_array($curl, array(
		CURLOPT_HEADER => 0,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_URL => $queryUrl.'?'.$queryData,
	));

	$result = curl_exec($curl);
	curl_close($curl);

	$result = json_decode($result, 1);
	if (!isset($result['error']))
	{
		$appsConfig = Array();
		if (file_exists(__DIR__.'/config.php'))
			include(__DIR__.'/config.php');

		$result['application_token'] = $auth['application_token'];
		$appsConfig[$auth['application_token']]['AUTH'] = $result;
		saveParams($appsConfig);
	}
	else
	{
		$result = false;
	}

	return $result;
}

/**
 * Write data to log file. (by default disabled)
 * WARNING: this method is only created for demonstration, never store log file in public folder
 *
 * @param mixed $data
 * @param string $title
 * @return bool
 */
function writeToLog($data, $title = '')
{
	if (!DEBUG_FILE_NAME)
		return false;

	$log = "\n------------------------\n";
	$log .= date("Y.m.d G:i:s")."\n";
	$log .= (strlen($title) > 0 ? $title : 'DEBUG')."\n";
	$log .= print_r($data, 1);
	$log .= "\n------------------------\n";

	file_put_contents(__DIR__."/".DEBUG_FILE_NAME, $log, FILE_APPEND);

	return true;
}

/**
 * Clean text before select ITR item
 *
 * @param $message
 * @return string
 */
function prepareText($message)
{
	$message = preg_replace("/\[s\].*?\[\/s\]/i", "-", $message);
	$message = preg_replace("/\[[bui]\](.*?)\[\/[bui]\]/i", "$1", $message);
	$message = preg_replace("/\\[url\\](.*?)\\[\\/url\\]/i", "$1", $message);
	$message = preg_replace("/\\[url\\s*=\\s*((?:[^\\[\\]]++|\\[ (?: (?>[^\\[\\]]+) | (?:\\1) )* \\])+)\\s*\\](.*?)\\[\\/url\\]/ixs", "$2", $message);
	$message = preg_replace("/\[USER=([0-9]{1,})\](.*?)\[\/USER\]/i", "$2", $message);
	$message = preg_replace("/\[CHAT=([0-9]{1,})\](.*?)\[\/CHAT\]/i", "$2", $message);
	$message = preg_replace("/\[PCH=([0-9]{1,})\](.*?)\[\/PCH\]/i", "$2", $message);
	$message = preg_replace('#\-{54}.+?\-{54}#s', "", str_replace(array("#BR#"), Array(" "), $message));
	$message = strip_tags($message);

	return trim($message);
}


/**
 * Class Itr
 * @package Bitrix\ImBot\Bot
 */
class Itr
{
	public $botId = 0;
	public $userId = 0;
	public $dialogId = '';
	public $portalId = '';

	private $cacheId = '';
	private static $executed = false;

	private $menuItems = Array();
	private $menuText = Array();

	private $currentMenu = 0;
	private $skipShowMenu = false;

	public function __construct($portalId, $dialogId, $botId, $userId)
	{
		$this->portalId = $portalId;
		$this->userId = $userId;
		$this->botId = $botId;
		$this->dialogId = $dialogId;

		$this->getCurrentMenu();
	}

	public function addMenu(ItrMenu $items)
	{
		$this->menuText[$items->getId()] = $items->getText();
		$this->menuItems[$items->getId()] = $items->getItems();

		return true;
	}

	/**
	 * Get menu state.
	 * WARNING: this method is only created for demonstration, never store cache like this
	 */
	private function getCurrentMenu()
	{
		$this->cacheId = md5($this->portalId.$this->botId.$this->dialogId);

		if (file_exists(__DIR__.'/cache') && file_exists(__DIR__.'/cache/'.$this->cacheId.'.cache'))
		{
			$this->currentMenu = intval(file_get_contents(__DIR__.'/cache/'.$this->cacheId.'.cache'));
		}
		else
		{
			if (!file_exists(__DIR__.'/cache'))
			{
				mkdir(__DIR__.'/cache');
 				chmod(__DIR__.'/cache', 0777);
			}
			file_put_contents(__DIR__.'/cache/'.$this->cacheId.'.cache', 0);
		}
	}

	/**
	 * Save menu state.
	 * WARNING: this method is only created for demonstration, never store cache like this
	 */
	private function setCurrentMenu($id)
	{
		$this->currentMenu = intval($id);
		file_put_contents(__DIR__.'/cache/'.$this->cacheId.'.cache', $this->currentMenu);
	}

	private function execMenuItem($itemId = '')
	{
		if ($itemId === '')
		{
			return true;
		}
		else if ($itemId === "0")
		{
			$this->skipShowMenu = true;
		}

		if (!isset($this->menuItems[$this->currentMenu][$itemId]))
		{
			return false;
		}

		$menuItemAction = $this->menuItems[$this->currentMenu][$itemId]['ACTION'];

		if ($menuItemAction['HIDE_MENU'])
		{
			$this->skipShowMenu = true;
		}

		if (isset($menuItemAction['TEXT']))
		{
			$messageText = str_replace('#USER_NAME#', $_REQUEST["data"]["USER"]["NAME"], $menuItemAction['TEXT']);
			restCommand('imbot.message.add', Array(
				"DIALOG_ID" => $this->dialogId,
				"MESSAGE" => $messageText,
			), $_REQUEST["auth"]);
		}

		if ($menuItemAction['TYPE'] == ItrItem::TYPE_MENU)
		{
			$this->setCurrentMenu($menuItemAction['MENU']);
		}
		else if ($menuItemAction['TYPE'] == ItrItem::TYPE_QUEUE)
		{
			restCommand('imopenlines.bot.session.operator', Array(
				"CHAT_ID" => substr($this->dialogId, 4),
			), $_REQUEST["auth"]);
		}
		else if ($menuItemAction['TYPE'] == ItrItem::TYPE_USER)
		{
			restCommand('imopenlines.bot.session.transfer', Array(
				"CHAT_ID" => substr($this->dialogId, 4),
				"USER_ID" => $menuItemAction['USER_ID'],
				"LEAVE" => $menuItemAction['LEAVE']? 'Y': 'N',
			), $_REQUEST["auth"]);
		}
		else if ($menuItemAction['TYPE'] == ItrItem::TYPE_BOT)
		{
			$botId = 0;
			$result = restCommand('imbot.bot.list', Array(), $_REQUEST["auth"]);
			foreach ($result['result'] as $botData)
			{
				if ($botData['CODE'] == $menuItemAction['BOT_CODE'] && $botData['OPENLINE'] == 'Y')
				{
					$botId = $botData['ID'];
					break;
				}
			}
			if ($botId)
			{
				restCommand('imbot.chat.user.add', Array(
					'CHAT_ID' => substr($this->dialogId, 4),
   					'USERS' => Array($botId)
				), $_REQUEST["auth"]);
				if ($menuItemAction['LEAVE'])
				{
					restCommand('imbot.chat.leave', Array(
						'CHAT_ID' => substr($this->dialogId, 4)
					), $_REQUEST["auth"]);
				}
			}
			else if ($menuItemAction['ERROR_TEXT'])
			{
				$messageText = str_replace('#USER_NAME#', $_REQUEST["data"]["USER"]["NAME"], $menuItemAction['ERROR_TEXT']);
				restCommand('imbot.message.add', Array(
					"DIALOG_ID" => $this->dialogId,
					"MESSAGE" => $messageText,
				), $_REQUEST["auth"]);
				$this->skipShowMenu = false;
			}
		}
		else if ($menuItemAction['TYPE'] == ItrItem::TYPE_FINISH)
		{
			restCommand('imopenlines.bot.session.finish', Array(
				"CHAT_ID" => substr($this->dialogId, 4)
			), $_REQUEST["auth"]);
		}
		else if ($menuItemAction['TYPE'] == ItrItem::TYPE_FUNCTION)
		{
			$menuItemAction['FUNCTION']($this);
		}

		return true;
	}

	private function getMenuItems()
	{
		$messageText = '';
		if ($this->skipShowMenu)
		{
			$this->skipShowMenu = false;
			return $messageText;
		}

		if (isset($this->menuText[$this->currentMenu]))
		{
			$messageText = $this->menuText[$this->currentMenu].'[br]';
		}

		foreach ($this->menuItems[$this->currentMenu] as $itemId => $data)
		{
			$messageText .= '[send='.$itemId.']'.$itemId.'. '.$data['TITLE'].'[/send][br]';
		}

		$messageText = str_replace('#USER_NAME#', $_REQUEST["data"]["USER"]["NAME"], $messageText);
		restCommand('imbot.message.add', Array(
			"DIALOG_ID" => $this->dialogId,
			"MESSAGE" => $messageText,
		), $_REQUEST["auth"]);

		return true;
	}

	public function run($text)
	{
		if (self::$executed)
			return false;

		list($itemId) = explode(" ", $text);

		$this->execMenuItem($itemId);

		$this->getMenuItems();

		self::$executed = true;

		return true;
	}
}

class ItrMenu
{
	private $id = 0;
	private $text = '';
	private $items = Array();

	/**
	 * ItrMenu constructor.
	 * @param $id
	 */
	public function __construct($id)
	{
		$this->id = intval($id);
	}

	public function getId()
	{
		return $this->id;
	}

	public function getText()
	{
		return $this->text;
	}

	public function getItems()
	{
		return $this->items;
	}

	public function setText($text)
	{
		$this->text = trim($text);
	}

	public function addItem($id, $title, array $action)
	{
		$id = intval($id);
		if ($id <= 0 && !in_array($action['TYPE'], Array(ItrItem::TYPE_VOID, ItrItem::TYPE_TEXT)))
		{
			return false;
		}

		$title = trim($title);

		$this->items[$id] = Array(
			'ID' => $id,
			'TITLE' => $title,
			'ACTION' => $action
		);

		return true;
	}
}

class ItrItem
{
	const TYPE_VOID = 'VOID';
	const TYPE_TEXT = 'TEXT';
	const TYPE_MENU = 'MENU';
	const TYPE_USER = 'USER';
	const TYPE_BOT = 'BOT';
	const TYPE_QUEUE = 'QUEUE';
	const TYPE_FINISH = 'FINISH';
	const TYPE_FUNCTION = 'FUNCTION';

	public static function void($hideMenu = true)
	{
		return Array(
			'TYPE' => self::TYPE_VOID,
			'HIDE_MENU' => $hideMenu? true: false
		);
	}

	public static function sendText($text = '', $hideMenu = false)
	{
		return Array(
			'TYPE' => self::TYPE_TEXT,
			'TEXT' => $text,
			'HIDE_MENU' => $hideMenu? true: false
		);
	}

	public static function openMenu($menuId)
	{
		return Array(
			'TYPE' => self::TYPE_MENU,
			'MENU' => $menuId
		);
	}

	public static function transferToQueue($text = '', $hideMenu = true)
	{
		return Array(
			'TYPE' => self::TYPE_QUEUE,
			'TEXT' => $text,
			'HIDE_MENU' => $hideMenu? true: false
		);
	}

	public static function transferToUser($userId, $leave = false, $text = '', $hideMenu = true)
	{
		return Array(
			'TYPE' => self::TYPE_USER,
			'TEXT' => $text,
			'HIDE_MENU' => $hideMenu? true: false,
			'USER_ID' => $userId,
			'LEAVE' => $leave? true: false,
		);
	}

	public static function transferToBot($botCode, $leave = true, $text = '', $errorText = '')
	{
		return Array(
			'TYPE' => self::TYPE_BOT,
			'TEXT' => $text,
			'ERROR_TEXT' => $errorText,
			'HIDE_MENU' => true,
			'BOT_CODE' => $botCode,
			'LEAVE' => $leave? true: false,
		);
	}

	public static function finishSession($text = '')
	{
		return Array(
			'TYPE' => self::TYPE_FINISH,
			'TEXT' => $text,
			'HIDE_MENU' => true
		);
	}

	public static function execFunction($function, $text = '', $hideMenu = false)
	{
		return Array(
			'TYPE' => self::TYPE_FUNCTION,
			'FUNCTION' => $function,
			'TEXT' => $text,
			'HIDE_MENU' => $hideMenu? true: false
		);
	}
}
