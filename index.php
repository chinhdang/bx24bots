<?php
// Khai báo chế độ strict types cho PHP
declare(strict_types=1);

// Bao gồm các lớp cần thiết từ Bitrix24 PHP SDK và các thư viện khác
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\MemoryUsageProcessor;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

// Bao gồm autoloader để tải các lớp cần thiết
require_once 'vendor/autoload.php';

// Định nghĩa các hằng số cho CLIENT_ID và CLIENT_SECRET
define('CLIENT_ID', 'YOUR_CLIENT_ID'); // Thay bằng CLIENT_ID của bạn
define('CLIENT_SECRET', 'YOUR_CLIENT_SECRET'); // Thay bằng CLIENT_SECRET của bạn
define('WEBHOOK_URL', 'https://your-make-or-n8n-webhook-url'); // Thay bằng URL webhook của bạn

// Tạo đối tượng Request từ HTTP request hiện tại
$request = Request::createFromGlobals();

// Tạo logger để ghi log (không bắt buộc)
$log = new Logger('bitrix24-php-sdk');
$log->pushHandler(new StreamHandler('bitrix24-php-sdk.log'));
$log->pushProcessor(new MemoryUsageProcessor(true, true));

// Tạo ServiceBuilderFactory với EventDispatcher và Logger
$serviceBuilderFactory = new ServiceBuilderFactory(new EventDispatcher(), $log);

// Khởi tạo ApplicationProfile với CLIENT_ID và CLIENT_SECRET
$appProfile = ApplicationProfile::initFromArray([
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID' => CLIENT_ID,
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET' => CLIENT_SECRET,
    'BITRIX24_PHP_SDK_APPLICATION_SCOPE' => 'imbot', // Phạm vi cần thiết cho chatbot
]);

// Lấy thông tin AUTH từ request
$authToken = AuthToken::initFromRequest($request);

// Khởi tạo dịch vụ Bitrix24
$bitrix24 = $serviceBuilderFactory->getServiceBuilder($appProfile, $authToken, $request->get('DOMAIN'));

// Lấy dữ liệu từ request
$requestData = $request->request->all();

// Ghi log dữ liệu nhận được (tùy chọn)
$log->info('Received request from Bitrix24', $requestData);

// Kiểm tra sự kiện nhận được từ Bitrix24
if (isset($requestData['event'])) {
    $event = $requestData['event'];

    switch ($event) {
        case 'ONIMBOTMESSAGEADD':
            // Xử lý sự kiện nhận tin nhắn mới cho bot
            handleIncomingMessage($bitrix24, $requestData);
            break;

        case 'ONIMBOTJOINCHAT':
            // Xử lý sự kiện bot tham gia chat
            sendWelcomeMessage($bitrix24, $requestData);
            break;

        case 'ONAPPINSTALL':
            // Xử lý sự kiện cài đặt ứng dụng
            registerBot($bitrix24, $requestData);
            break;

        default:
            // Xử lý các sự kiện khác nếu cần
            break;
    }
}

/**
 * Hàm xử lý tin nhắn đến từ người dùng
 */
function handleIncomingMessage($bitrix24, $data)
{
    global $log;

    $messageText = $data['data']['PARAMS']['MESSAGE'];
    $dialogId = $data['data']['PARAMS']['DIALOG_ID'];
    $userId = $data['data']['PARAMS']['FROM_USER_ID'];

    // Chuẩn bị dữ liệu để gửi đến webhook
    $webhookData = [
        'DIALOG_ID' => $dialogId,
        'USER_ID' => $userId,
        'MESSAGE' => $messageText,
    ];

    // Gửi dữ liệu đến webhook của Make.com hoặc n8n
    $responseMessage = sendToWebhook($webhookData);

    // Gửi phản hồi lại cho người dùng trong Bitrix24
    sendMessageToUser($bitrix24, $dialogId, $responseMessage);
}

/**
 * Hàm gửi tin nhắn chào mừng khi bot tham gia chat
 */
function sendWelcomeMessage($bitrix24, $data)
{
    $dialogId = $data['data']['PARAMS']['DIALOG_ID'];
    $welcomeMessage = "Chào bạn! Tôi là chatbot hỗ trợ của bạn. Vui lòng nhập câu hỏi của bạn.";

    sendMessageToUser($bitrix24, $dialogId, $welcomeMessage);
}

/**
 * Hàm đăng ký bot khi ứng dụng được cài đặt
 */
function registerBot($bitrix24, $data)
{
    global $request;

    $handlerBackUrl = $request->getSchemeAndHttpHost() . $request->getPathInfo();

    // Đăng ký bot
    $result = $bitrix24->getImScope()->client->call('imbot.register', [
        'CODE' => 'simple_chatbot',
        'TYPE' => 'B', // Bot loại Chatbot
        'EVENT_MESSAGE_ADD' => $handlerBackUrl,
        'EVENT_WELCOME_MESSAGE' => $handlerBackUrl,
        'PROPERTIES' => [
            'NAME' => 'Simple Chatbot',
            'COLOR' => 'GREEN',
        ],
    ]);

    // Kiểm tra kết quả đăng ký
    if ($result->getResult()->getResultData()['BOT_ID']) {
        $botId = $result->getResult()->getResultData()['BOT_ID'];
        // Lưu BOT_ID nếu cần thiết
    }
}

/**
 * Hàm gửi dữ liệu đến webhook và nhận phản hồi
 */
function sendToWebhook($data)
{
    global $log;

    $webhookUrl = WEBHOOK_URL;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $webhookUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($data),
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        $error = curl_error($curl);
        $log->error('Error sending data to webhook: ' . $error);
        $responseMessage = 'Xin lỗi, có lỗi xảy ra khi xử lý yêu cầu của bạn.';
    } else {
        $log->info('Received response from webhook', ['response' => $response]);
        $jsonResponse = json_decode($response, true);
        $responseMessage = $jsonResponse['RESPONSE'] ?? 'Xin lỗi, tôi không hiểu yêu cầu của bạn.';
    }

    curl_close($curl);

    return $responseMessage;
}

/**
 * Hàm gửi tin nhắn đến người dùng trong Bitrix24
 */
function sendMessageToUser($bitrix24, $dialogId, $message)
{
    $bitrix24->getImScope()->client->call('imbot.message.add', [
        'DIALOG_ID' => $dialogId,
        'MESSAGE' => $message,
    ]);
}
?>
