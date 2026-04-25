<?php
/**
 * Script Marketplace Bot - PHP Version with Webhook
 * Converted from Python seller.py
 * FIXED VERSION - March 2026
 * 
 * Fixes:
 * 1. Referral flow - inviter only notified after verification completes
 * 2. Start params - better handling of referral codes
 * 3. Admin commands - work even when user is in a state
 * 4. User data - properly initialized at earliest interaction
 * 5. DATA STRUCTURE FIXES - Script and document keys now match
 * 6. Added /reset admin command for full data wipe
 */

// ==================== CONFIGURATION ====================
/**
 * Script Marketplace Bot - PHP Version with Webhook
 * Converted from Python seller.py
 * FIXED VERSION - March 2026
 * SECURITY UPDATE: Using environment variables for tokens
 */

// Load secure configuration
require_once __DIR__ . '/config.php';

// ==================== SECURE CONFIGURATION ====================
// All sensitive data loaded from .env file (NEVER commit .env to git!)

$TOKEN = env('TELEGRAM_BOT_TOKEN');
$ADMIN_ID = env('ADMIN_ID');
$CHANNEL = env('CHANNEL', '@testingSJdfKDhSFH');
$OTP_CHANNEL = env('OTP_CHANNEL', '@testingSJdfKDhSFH');
$WEBHOOK_URL = env('WEBHOOK_URL', 'https://khmerservice.online/sell_fix.php');
$PHOTO_URL = env('PHOTO_URL', 'https://t.me/testingSJdfKDhSFH/2');

// Bakong API Configuration
$BAKONG_API_URL = "https://api-bakong.nbc.gov.kh/v1/check_transaction_by_short_hash";
$BAKONG_TOKEN = env('BAKONG_API_TOKEN');
$BAKONG_ACCOUNT = env('BAKONG_ACCOUNT');

// TRX Address for crypto deposits
$TRX_ADDRESS = env('TRX_ADDRESS');

// Data directory - make sure this exists and is writable!
$DATA_DIR = __DIR__ . "/data/";

// Security: Ensure data directory is protected
if (!is_dir($DATA_DIR)) {
    if (!mkdir($DATA_DIR, 0750, true)) { // Restricted permissions
        die("Failed to create data directory: {$DATA_DIR}");
    }
}

// Create .htaccess in data directory if not exists
$htaccessFile = $DATA_DIR . ".htaccess";
if (!file_exists($htaccessFile)) {
    file_put_contents($htaccessFile, "Deny from all\n");
}

// Debug logging function
function logDebug($message) {
    global $DATA_DIR;
    $logFile = $DATA_DIR . "debug.log";
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

// Log all requests for debugging
$raw_input = file_get_contents('php://input');
logDebug("RAW INPUT: " . $raw_input);
logDebug("REQUEST METHOD: " . $_SERVER['REQUEST_METHOD']);
logDebug("QUERY STRING: " . $_SERVER['QUERY_STRING']);

// ==================== DATABASE FUNCTIONS ====================

function getUserData($userId, $field) {
    global $DATA_DIR;
    $file = $DATA_DIR . "users.json";
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return isset($data[$userId][$field]) ? $data[$userId][$field] : null;
}

function saveUserData($userId, $field, $value) {
    global $DATA_DIR;
    $file = $DATA_DIR . "users.json";
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    if (!isset($data[$userId])) $data[$userId] = [];
    $data[$userId][$field] = $value;
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function initUserData($userId, $referral = "None") {
    // Initialize user data if not exists
    $existingBalance = getUserData($userId, "balance");
    if ($existingBalance === null) {
        saveUserData($userId, "history", 0);
        saveUserData($userId, "balance", 0.00);
        saveUserData($userId, "deposit_total", 0);
        saveUserData($userId, "withdraw_total", 0);
        saveUserData($userId, "balance_total", 0);
        saveUserData($userId, "request_scr_tool", 0);
        saveUserData($userId, "request_scr_web", 0);
        saveUserData($userId, "values", 0);
        saveUserData($userId, "referral", $referral);
        saveUserData($userId, "state", "none");
        saveUserData($userId, "verified", false);

        // Add to tg.txt
        $tgFile = $GLOBALS['DATA_DIR'] . "tg.txt";
        if (!file_exists($tgFile) || strpos(file_get_contents($tgFile), " {$userId}") === false) {
            file_put_contents($tgFile, " {$userId}", FILE_APPEND);
        }
        return true; // New user
    }
    return false; // Existing user
}

// FIXED: saveSellData - stores flat data structure
function saveSellData($id, $data) {
    global $DATA_DIR;
    $file = $DATA_DIR . "Sell.json";
    $existing = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $existing[$id] = $data; // Store flat data, not wrapped
    file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT));
}

// FIXED: saveNameData - stores flat data structure
function saveNameData($id, $data) {
    global $DATA_DIR;
    $file = $DATA_DIR . "Names.json";
    $existing = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $existing[$id] = $data; // Store flat data, not wrapped
    file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT));
}

// FIXED: saveDocData - stores flat data structure with consistent key
function saveDocData($id, $data, $append = false) {
    global $DATA_DIR;
    $file = $DATA_DIR . "document.json";
    $existing = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    
    if (!isset($existing[$id])) {
        $existing[$id] = [];
    }
    
    if ($append && is_array($data)) {
        // Append single file to array
        $existing[$id][] = $data;
    } else {
        // Initialize or replace entire array
        $existing[$id] = is_array($data) && !isset($data['file_id']) ? $data : [$data];
    }
    
    file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT));
    logDebug("DocData saved for ID {$id}: " . count($existing[$id]) . " files");
}

function historySaveData($userId, $data) {
    global $DATA_DIR;
    $file = $DATA_DIR . "history.json";
    $existing = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    if (!isset($existing[$userId])) $existing[$userId] = [];
    $existing[$userId] = array_merge($existing[$userId], $data);
    file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT));
}

function saveRefData($userId, $referral) {
    global $DATA_DIR;
    $file = $DATA_DIR . "referral.json";
    $existing = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $existing[$userId] = $referral;
    file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT));
}

function saveBotData($key, $value) {
    global $DATA_DIR;
    $file = $DATA_DIR . "bot_data.json";
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $data[$key] = $value;
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

// ==================== TELEGRAM API FUNCTIONS ====================

function apiRequest($method, $params = []) {
    global $TOKEN;
    $url = "https://api.telegram.org/bot{$TOKEN}/{$method}";

    logDebug("API Request: {$method} - " . json_encode($params));

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $result = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        logDebug("CURL Error: {$error}");
        return null;
    }

    logDebug("API Response (HTTP {$httpCode}): {$result}");

    return json_decode($result, true);
}

function sendMessage($chatId, $text, $parseMode = "HTML", $replyMarkup = null) {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode
    ];
    if ($replyMarkup) $params['reply_markup'] = json_encode($replyMarkup);
    return apiRequest('sendMessage', $params);
}

function sendPhoto($chatId, $photo, $caption = "", $parseMode = "HTML", $replyMarkup = null) {
    $params = [
        'chat_id' => $chatId,
        'photo' => $photo,
        'caption' => $caption,
        'parse_mode' => $parseMode
    ];
    if ($replyMarkup) $params['reply_markup'] = json_encode($replyMarkup);
    return apiRequest('sendPhoto', $params);
}

function sendDocument($chatId, $document, $caption = "", $replyMarkup = null) {
    global $TOKEN;
    $url = "https://api.telegram.org/bot{$TOKEN}/sendDocument";
    
    $params = [
        'chat_id' => $chatId,
        'document' => $document,
        'caption' => $caption
    ];
    if ($replyMarkup) $params['reply_markup'] = json_encode($replyMarkup);
    
    logDebug("sendDocument: Sending to {$chatId}, file_id: {$document}");
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $result = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($error) {
        logDebug("sendDocument Error: {$error}");
        return null;
    }
    
    logDebug("sendDocument Response (HTTP {$httpCode}): {$result}");
    
    return json_decode($result, true);
}

function editMessageText($chatId, $messageId, $text, $replyMarkup = null) {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($replyMarkup) $params['reply_markup'] = json_encode($replyMarkup);
    return apiRequest('editMessageText', $params);
}

function answerCallbackQuery($callbackQueryId, $text = "") {
    $params = [
        'callback_query_id' => $callbackQueryId,
        'text' => $text
    ];
    return apiRequest('answerCallbackQuery', $params);
}

function getChatMember($chatId, $userId) {
    $params = [
        'chat_id' => $chatId,
        'user_id' => $userId
    ];
    return apiRequest('getChatMember', $params);
}

// ==================== KEYBOARD BUILDERS ====================

function buildReplyKeyboard($buttons) {
    return [
        'keyboard' => $buttons,
        'resize_keyboard' => true
    ];
}

function buildInlineKeyboard($buttons) {
    return [
        'inline_keyboard' => $buttons
    ];
}

// ==================== MAIN MENU ====================

function showMainMenu($chatId) {
    $keyboard = buildReplyKeyboard([
        ['💾 Balance'],
        ['🖱️ Buy script', '🖥️ Sell script'],
        ['⭐ Deposit', '📊 Status', '🌟 Withdraw'],
        ['📞 Support', '⌨️ Code'],
        ['👥 Referral']
    ]);
    sendMessage($chatId, "Welcome", "HTML", $keyboard);
}

function showJoinedMenu($chatId) {
    $keyboard = buildReplyKeyboard([
        ['✅ join']
    ]);
    global $CHANNEL, $PHOTO_URL;
    $text = "*hello* User\n\n*please join our channel*\n\n{$CHANNEL}\n\n*with our bot telegram, users can browse through a wide range of scripts available for purchase. whether you are looking for scripts related to programming, web development, or any other niche, you can find it on our platform. we ensure that all scripts listed on our platform are of high quality and meet the standards set by our team.*";
    sendPhoto($chatId, $PHOTO_URL, $text, "Markdown", $keyboard);
}

// ==================== VERIFICATION FUNCTIONS ====================

function verifyUser($chatId, $username, $referralCode = "") {
    global $OTP_CHANNEL, $PHOTO_URL;
    $randoms = rand(1000, 999999);

    // Save OTP to file
    $otpFile = $GLOBALS['DATA_DIR'] . "otps.json";
    $otps = file_exists($otpFile) ? json_decode(file_get_contents($otpFile), true) : [];
    $otps[$chatId] = [
        'otp' => $randoms,
        'referral_code' => $referralCode, // Store referral code for later
        'time' => time()
    ];
    file_put_contents($otpFile, json_encode($otps));

    // Send OTP to OTP channel
    $text = "@{$username} <b>Get your OTP here</b>\n\n<code>{$randoms}</code>\n\n<b>✅ Verify you're not robot</b>";
    $key = buildInlineKeyboard([
        [['text' => '🤖 Verify', 'url' => 'https://t.me/SellerScriptFastBot']]
    ]);
    sendMessage($OTP_CHANNEL, $text, "HTML", $key);

    // Send instruction to user
    $tets = "*⚠️ Get your OTP in * {$OTP_CHANNEL}\n\n_Submit your OTP here_\n\n❌* Please enter only number*";
    $keyboard = buildReplyKeyboard([['🚫 Cancel']]);
    sendMessage($chatId, $tets, "Markdown", $keyboard);

    // Set user state
    saveUserData($chatId, "state", "waiting_otp");
    saveUserData($chatId, "otp_expected", $randoms);
    saveUserData($chatId, "pending_referral", $referralCode); // Store for after verification
}

function checkVerification($chatId) {
    $verifyFile = $GLOBALS['DATA_DIR'] . "verify.txt";
    if (!file_exists($verifyFile)) return false;
    $verified = file_get_contents($verifyFile);
    return strpos($verified, " {$chatId}") !== false;
}

function addVerification($chatId) {
    $verifyFile = $GLOBALS['DATA_DIR'] . "verify.txt";
    file_put_contents($verifyFile, " {$chatId}", FILE_APPEND);
    saveUserData($chatId, "verified", true);
}

// ==================== COMMAND HANDLERS ====================

function handleStart($message) {
    global $ADMIN_ID, $PHOTO_URL;
    $chatId = $message['chat']['id'];
    $username = isset($message['chat']['username']) ? $message['chat']['username'] : 'unknown';
    $firstName = isset($message['chat']['first_name']) ? $message['chat']['first_name'] : 'User';

    $text = isset($message['text']) ? $message['text'] : '';

    // Better params extraction - handle both " /start CODE" and "/startCODE"
    $params = "";
    if (strpos($text, '/start') === 0) {
        $params = trim(substr($text, 6)); // Remove "/start" and trim
    }

    logDebug("Start command received. ChatID: {$chatId}, Raw text: '{$text}', Params: '{$params}'");

    // Check if user is verified
    $isVerified = checkVerification($chatId);

    // Initialize user data FIRST (before anything else)
    $isNewUser = initUserData($chatId, $params);

    if (!$isVerified) {
        // User needs verification first
        // Store referral code if provided, but DON'T process it yet
        verifyUser($chatId, $username, $params);
        return;
    }

    // User is verified - check if they have a pending referral to process
    $pendingReferral = getUserData($chatId, "pending_referral");
    if (!empty($pendingReferral) && $pendingReferral != "None") {
        // Process the referral now that user is verified
        processReferralAfterVerification($chatId, $pendingReferral);
        // Clear pending referral
        saveUserData($chatId, "pending_referral", "None");
    }

    // Show appropriate menu
    if ($isNewUser) {
        showJoinedMenu($chatId);
    } else {
        showJoinedMenu($chatId);
    }
}

function processReferralAfterVerification($chatId, $referralCode) {
    global $ADMIN_ID;

    logDebug("Processing referral after verification: User {$chatId}, Referrer {$referralCode}");

    // Validate referral code exists
    $tgFile = $GLOBALS['DATA_DIR'] . "tg.txt";
    if (!file_exists($tgFile) || strpos(file_get_contents($tgFile), " {$referralCode}") === false) {
        logDebug("Invalid referral code: {$referralCode}");
        return;
    }

    // Check if already referred
    $refFile = $GLOBALS['DATA_DIR'] . "referral.txt";
    if (file_exists($refFile) && strpos(file_get_contents($refFile), " {$chatId}") !== false) {
        logDebug("User {$chatId} already referred");
        return;
    }

    // Save referral
    file_put_contents($refFile, " {$chatId}", FILE_APPEND);
    saveUserData($chatId, "referral", $referralCode);

    // Notify inviter (only after verification!)
    sendMessage($referralCode, "*🎉 Your referral has completed verification!*\n\nYou will get 10% when your referral buys a script.", "Markdown");

    logDebug("Referral processed: {$chatId} referred by {$referralCode}");
}

function handleJoined($message) {
    global $CHANNEL, $PHOTO_URL;
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];

    if (!checkVerification($chatId)) {
        sendMessage($chatId, "Make sure you you're Robot");
        return;
    }

    $member = getChatMember($CHANNEL, $userId);
    if (isset($member['result']['status']) && in_array($member['result']['status'], ['member', 'administrator', 'creator'])) {
        showMainMenu($chatId);
    } else {
        sendMessage($chatId, "Please join {$CHANNEL} before using this bot!");
    }
}

function handleBalance($message) {
    $chatId = $message['chat']['id'];
    $firstName = isset($message['chat']['first_name']) ? $message['chat']['first_name'] : 'User';

    $balance = getUserData($chatId, "balance");
    $totalDeposit = getUserData($chatId, "deposit_total");
    $withdrawTotal = getUserData($chatId, "withdraw_total");
    $requestWeb = getUserData($chatId, "request_scr_web");
    $requestTool = getUserData($chatId, "request_scr_tool");

    $TEXT = "🤠 Name: *{$firstName}*\n🧐 ID: *{$chatId}*\n• =================== •\n🌟 Total Star Poin: *{$balance}*\n• =================== •\n💻 Total Script Request for sale: *{$requestWeb}*\n• =================== •";

    global $PHOTO_URL;
    sendPhoto($chatId, $PHOTO_URL, $TEXT, "Markdown");
}

function handleStatus($message) {
    $chatId = $message['chat']['id'];
    $values = getUserData($chatId, "values");
    $balance = getUserData($chatId, "balance");
    sendMessage($chatId, "📊 Status\n\nScripts listed: {$values}\nBalance: {$balance} STAR");
}

// FIXED: handleBuyScript - corrected data structure lookup
// FIXED: handleBuyScript - show ALL scripts, not just one
// FIXED: Show 10 scripts per page with numbered buttons
function handleBuyScript($message, $page = 1) {
    $chatId = $message['chat']['id'];
    $itemsPerPage = 10;

    $file = $GLOBALS['DATA_DIR'] . "Sell.json";
    if (!file_exists($file)) {
        sendMessage($chatId, "❌ No scripts available for sale yet.");
        return;
    }

    $data = json_decode(file_get_contents($file), true);
    if (empty($data) || !is_array($data)) {
        sendMessage($chatId, "❌ No scripts available for sale yet.");
        return;
    }

    // Filter valid scripts and reindex
    $scripts = [];
    foreach ($data as $id => $script) {
        if (is_array($script) && isset($script['name']) && !empty($script['name'])) {
            $scripts[$id] = $script;
        }
    }

    $totalScripts = count($scripts);
    
    if ($totalScripts === 0) {
        sendMessage($chatId, "❌ No valid scripts available.");
        return;
    }

    // Calculate pagination
    $totalPages = max(1, ceil($totalScripts / $itemsPerPage));
    $page = max(1, min($page, $totalPages));
    
    // Get scripts for current page
    $scriptIds = array_keys($scripts);
    $start = ($page - 1) * $itemsPerPage;
    $pageScriptIds = array_slice($scriptIds, $start, $itemsPerPage);
    
    $pageScripts = [];
    foreach ($pageScriptIds as $id) {
        $pageScripts[] = ['id' => $id, 'data' => $scripts[$id]];
    }

    logDebug("User {$chatId} viewing page {$page}/{$totalPages} with " . count($pageScripts) . " scripts");

    // Build the list message
    $text = "📦 *Available Scripts*\n\n";
    $text .= "Page *{$page}* of *{$totalPages}* | Total: *{$totalScripts}* scripts\n\n";
    
    // Build numbered list
    $counter = ($page - 1) * $itemsPerPage + 1;
    foreach ($pageScripts as $script) {
        $name = $script['data']['name'];
        $price = $script['data']['price'] ?? '0';
        $category = $script['data']['category'] ?? 'General';
        
        // Numbered list format: 1. Script Name - 10⭐ [Category]
        $text .= "*{$counter}.* {$name}\n";
        $text .= "    💰 {$price} STAR | 📂 {$category}\n\n";
        $counter++;
    }

    $text .= "🔢 *Click a number below to view script details*";

    // Build numbered buttons (1-10)
    $numberButtons = [];
    $row = [];
    $btnCount = 0;
    
    foreach ($pageScripts as $index => $script) {
        $btnNum = ($page - 1) * $itemsPerPage + $index + 1;
        $row[] = ['text' => (string)$btnNum, 'callback_data' => "viewscript {$script['id']}"];
        $btnCount++;
        
        // 5 buttons per row
        if ($btnCount % 5 == 0) {
            $numberButtons[] = $row;
            $row = [];
        }
    }
    if (!empty($row)) {
        $numberButtons[] = $row;
    }

    // Build navigation buttons
    $navRow = [];
    if ($page > 1) {
        $navRow[] = ['text' => '⬅️ Back', 'callback_data' => "scriptpage " . ($page - 1)];
    }
    $navRow[] = ['text' => "📄 {$page}/{$totalPages}", 'callback_data' => 'currentpage'];
    if ($page < $totalPages) {
        $navRow[] = ['text' => 'Next ➡️', 'callback_data' => "scriptpage " . ($page + 1)];
    }
    
    // Combine all buttons
    $allButtons = array_merge($numberButtons, [$navRow]);
    $keyboard = buildInlineKeyboard($allButtons);

    sendMessage($chatId, $text, "Markdown", $keyboard);
}

function handleDeposit($message) {
    $chatId = $message['chat']['id'];
    global $PHOTO_URL;

    $text = "*Choose deposit method:*";
    $keyboard = buildInlineKeyboard([
        [['text' => '💳 Bakong', 'callback_data' => 'bakong']],
        [['text' => '💎 Crypto (TRX)', 'callback_data' => 'crypto']]
    ]);

    sendPhoto($chatId, $PHOTO_URL, $text, "Markdown", $keyboard);
}

function handleWithdraw($message) {
    $chatId = $message['chat']['id'];
    $balance = getUserData($chatId, "balance");

    $text = "*⚠️ if you withdraw star. Your all star poin will be remove, money will send to your wallet*\n\n_Your balance now: {$balance}_\n\n*💳 Click button below for withdraw.*";
    $keyboard = buildInlineKeyboard([
        [['text' => '⭐ Withdraw', 'callback_data' => 'with']]
    ]);

    sendMessage($chatId, $text, "Markdown", $keyboard);
}

function handleSellScript($message) {
    $chatId = $message['chat']['id'];
    saveUserData($chatId, "state", "waiting_photo");
    sendMessage($chatId, "*Please send a photo for your script*", "Markdown");
}

function handleSupport($message) {
    $chatId = $message['chat']['id'];
    saveUserData($chatId, "state", "waiting_support");
    $keyboard = buildReplyKeyboard([['🚫 Cancel']]);
    sendMessage($chatId, "*Enter your message*", "Markdown", $keyboard);
}

function handleReferral($message) {
    $chatId = $message['chat']['id'];
    global $PHOTO_URL;

    $link = "https://t.me/SellerScriptFastBot?start={$chatId}";
    $text = "*👥 You will be get 10% per your referral buy*\n\nYour code: \n{$link}\n\n*Money will send to you if referral has completed*";

    sendPhoto($chatId, $PHOTO_URL, $text, "Markdown");
}

function handleReferralCode($message, $code) {
    $chatId = $message['chat']['id'];

    logDebug("Handling referral code input: {$code} for user {$chatId}");

    // Check if code exists in tg.txt
    $tgFile = $GLOBALS['DATA_DIR'] . "tg.txt";
    if (!file_exists($tgFile) || strpos(file_get_contents($tgFile), " {$code}") === false) {
        sendMessage($chatId, "*Wrong code*", "Markdown");
        return;
    }

    // Check if already referred
    $refFile = $GLOBALS['DATA_DIR'] . "referral.txt";
    if (file_exists($refFile) && strpos(file_get_contents($refFile), " {$chatId}") !== false) {
        sendMessage($chatId, "You have already used a referral code.");
        return;
    }

    // Check if trying to refer self
    if ($chatId == $code) {
        sendMessage($chatId, "You cannot use your own referral code!");
        return;
    }

    // Save referral
    file_put_contents($refFile, " {$chatId}", FILE_APPEND);
    saveUserData($chatId, "referral", $code);

    sendMessage($code, "*🎉 Your referral has used your code!*\n\nYou will get 10% when your referral buys a script.", "Markdown");
    sendMessage($chatId, "✅ Referral code accepted!");
}

function handleCode($message) {
    $chatId = $message['chat']['id'];
    $refFile = $GLOBALS['DATA_DIR'] . "referral.txt";

    if (file_exists($refFile) && strpos(file_get_contents($refFile), " {$chatId}") !== false) {
        sendMessage($chatId, "You have completed");
    } else {
        saveUserData($chatId, "state", "waiting_referral_code");
        sendMessage($chatId, "*Send your referral code*", "Markdown");
    }
}

// ==================== CALLBACK HANDLERS ====================

function handleCallback($callback) {
    global $ADMIN_ID, $CHANNEL, $PHOTO_URL, $TRX_ADDRESS, $BAKONG_API_URL, $BAKONG_TOKEN, $BAKONG_ACCOUNT;

    $callbackId = $callback['id'];
    $chatId = $callback['message']['chat']['id'];
    $messageId = $callback['message']['message_id'];
    $data = $callback['data'];
    $fromId = $callback['from']['id'];

    logDebug("Callback received: {$data} from {$fromId}");

    // Handle reset callbacks first (admin only)
    if (strpos($data, 'reset_') === 0) {
        handleResetCallback($callback, $data);
        return;
    }
        // PAGINATION: Handle page navigation
    if (strpos($data, 'scriptpage') === 0) {
        $parts = explode(" ", $data);
        $page = intval($parts[1] ?? 1);
        
        // Edit current message to show new page
        $sellFile = $GLOBALS['DATA_DIR'] . "Sell.json";
        $sellData = json_decode(file_get_contents($sellFile), true);
        
        $scripts = [];
        foreach ($sellData as $id => $script) {
            if (is_array($script) && isset($script['name'])) {
                $scripts[$id] = $script;
            }
        }
        
        $totalScripts = count($scripts);
        $itemsPerPage = 10;
        $totalPages = max(1, ceil($totalScripts / $itemsPerPage));
        $page = max(1, min($page, $totalPages));
        
        $scriptIds = array_keys($scripts);
        $start = ($page - 1) * $itemsPerPage;
        $pageScriptIds = array_slice($scriptIds, $start, $itemsPerPage);
        
        $pageScripts = [];
        foreach ($pageScriptIds as $id) {
            $pageScripts[] = ['id' => $id, 'data' => $scripts[$id]];
        }

        // Build updated text
        $text = "📦 *Available Scripts*\n\n";
        $text .= "Page *{$page}* of *{$totalPages}* | Total: *{$totalScripts}* scripts\n\n";
        
        $counter = ($page - 1) * $itemsPerPage + 1;
        foreach ($pageScripts as $script) {
            $name = $script['data']['name'];
            $price = $script['data']['price'] ?? '0';
            $category = $script['data']['category'] ?? 'General';
            
            $text .= "*{$counter}.* {$name}\n";
            $text .= "    💰 {$price} STAR | 📂 {$category}\n\n";
            $counter++;
        }

        $text .= "🔢 *Click a number below to view script details*";

        // Build numbered buttons
        $numberButtons = [];
        $row = [];
        $btnCount = 0;
        
        foreach ($pageScripts as $index => $script) {
            $btnNum = ($page - 1) * $itemsPerPage + $index + 1;
            $row[] = ['text' => (string)$btnNum, 'callback_data' => "viewscript {$script['id']}"];
            $btnCount++;
            
            if ($btnCount % 5 == 0) {
                $numberButtons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $numberButtons[] = $row;
        }

        // Navigation
        $navRow = [];
        if ($page > 1) {
            $navRow[] = ['text' => '⬅️ Back', 'callback_data' => "scriptpage " . ($page - 1)];
        }
        $navRow[] = ['text' => "📄 {$page}/{$totalPages}", 'callback_data' => 'currentpage'];
        if ($page < $totalPages) {
            $navRow[] = ['text' => 'Next ➡️', 'callback_data' => "scriptpage " . ($page + 1)];
        }
        
        $allButtons = array_merge($numberButtons, [$navRow]);
        $keyboard = buildInlineKeyboard($allButtons);

        editMessageText($chatId, $messageId, $text, $keyboard);
        answerCallbackQuery($callbackId, "Page {$page}");
        return;
    }
        // View individual script details
    if (strpos($data, 'viewscript') === 0) {
        $parts = explode(" ", $data);
        $scriptId = $parts[1] ?? '';
        
        if (empty($scriptId)) {
            answerCallbackQuery($callbackId, "Invalid script ID");
            return;
        }

        $sellFile = $GLOBALS['DATA_DIR'] . "Sell.json";
        if (!file_exists($sellFile)) {
            answerCallbackQuery($callbackId, "Script not found");
            return;
        }

        $sellData = json_decode(file_get_contents($sellFile), true);
        if (!isset($sellData[$scriptId])) {
            answerCallbackQuery($callbackId, "Script not available");
            return;
        }

        $script = $sellData[$scriptId];
        
        // Calculate which page this script is on (for back button)
        $allScripts = [];
        foreach ($sellData as $id => $s) {
            if (is_array($s) && isset($s['name'])) {
                $allScripts[] = $id;
            }
        }
        $scriptIndex = array_search($scriptId, $allScripts);
        $currentPage = floor($scriptIndex / 10) + 1;

        // Build detailed view
        $name = $script['name'] ?? 'Unknown';
        $price = $script['price'] ?? '0';
        $category = $script['category'] ?? 'General';
        $language = $script['language'] ?? 'Unknown';
        $description = $script['description'] ?? 'No description';
        $demoLink = $script['demo_link'] ?? '';
        $fileCount = $script['file_count'] ?? 1;
        $photo = $script['photo'] ?? $GLOBALS['PHOTO_URL'];

        $text = "*📜 {$name}*\n\n";
        $text .= "💰 *Price:* {$price} STAR\n";
        $text .= "📂 *Category:* {$category}\n";
        $text .= "🌏 *Language:* {$language}\n";
        $text .= "📦 *Files Included:* {$fileCount}\n\n";
        $text .= "*Description:*\n{$description}\n\n";
        
        if (!empty($demoLink)) {
            $text .= "[🔗 View Demo]({$demoLink})\n\n";
        }
        
        $text .= "Click *Buy Now* to purchase all {$fileCount} files.";

        // Build action buttons with back to list
        $keyboard = buildInlineKeyboard([
            [['text' => '💳 Buy Now', 'callback_data' => "onbuy {$scriptId}"]],
            [['text' => '🔙 Back to List', 'callback_data' => "scriptpage {$currentPage}"]]
        ]);

        // If you have photo, use sendPhoto for new message
        // Or editMessageText to update current
        if (isset($callback['message']['photo'])) {
            // Edit existing photo message
            editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            // Send new photo
            sendPhoto($chatId, $photo, $text, "Markdown", $keyboard);
        }
        
        answerCallbackQuery($callbackId, "Viewing: {$name}");
        return;
    }

    // Do nothing for current page button
    if ($data == 'currentpage') {
        answerCallbackQuery($callbackId, "Current page");
        return;
    }

    answerCallbackQuery($callbackId);

    // Bakong deposit flow
    if ($data == 'bakong') {
        $text = "*Deposit with bakong*\n\n_Deposit with bakong is actually easy to pay. just click button below and complete all_";
        $keyboard = buildInlineKeyboard([
            [['text' => '💳 Payment', 'callback_data' => 'bakong_depo']]
        ]);
        sendPhoto($chatId, $PHOTO_URL, $text, "Markdown", $keyboard);
    }

    if ($data == 'bakong_depo') {
        $caption = "⚠️ Please scan only this *QR* code\n\n✅ if your payment success, please *click* button below";
        $keyboard = buildInlineKeyboard([
            [['text' => '💳 Payment', 'url' => "https://apidepositbakong-devbk.broskhuach.repl.co?c=USD&chat={$chatId}"]]
        ]);
        sendPhoto($chatId, $PHOTO_URL, $caption, "Markdown", $keyboard);
    }

    // Crypto deposit flow
    if ($data == 'crypto') {
        $text = "*Deposit with Crypto Currency*\n\n_Deposit with Cryptocurrency is actually easy to pay. just click button below and complete all_";
        $keyboard = buildInlineKeyboard([
            [['text' => '💳 Payment', 'callback_data' => 'depo_cryto']]
        ]);
        sendPhoto($chatId, $PHOTO_URL, $text, "Markdown", $keyboard);
    }

    if ($data == 'depo_cryto') {
        $text = "⚠️ We are support only <b>TRX</b> currency now\n\n<code>{$TRX_ADDRESS}</code>\n\n✅ If your payment success please <b>click</b> button below or want to cancel just type <b>🚫 Cancel</b>";
        $keyboard = buildInlineKeyboard([
            [['text' => '💚 Check 💚', 'callback_data' => 'check_depo_trx'], ['text' => '🚫 Cancel', 'callback_data' => 'cancel']]
        ]);
        sendMessage($chatId, $text, "HTML", $keyboard);
    }

    if ($data == 'check_depo_trx') {
        sendMessage($chatId, "*Please send your link transaction*", "Markdown");
        saveUserData($chatId, "state", "waiting_trx_link");
    }

    // FIXED: Buy script - corrected document lookup
        // FIXED: Buy script - properly retrieve and send ALL files
    if (strpos($data, 'onbuy') === 0) {
        $parts = explode(" ", $data);
        $param = $parts[1] ?? ''; // This is the Sell.json ID
        
        if (empty($param)) {
            sendMessage($chatId, "❌ Invalid script ID");
            return;
        }

        $sellFile = $GLOBALS['DATA_DIR'] . "Sell.json";
        if (!file_exists($sellFile)) {
            sendMessage($chatId, "❌ Script database not found");
            return;
        }

        $sellData = json_decode(file_get_contents($sellFile), true);
        if (!isset($sellData[$param])) {
            sendMessage($chatId, "❌ Script not found (ID: {$param})");
            return;
        }

        $script = $sellData[$param];
        $name = $script['name'] ?? 'Unknown';
        $price = $script['price'] ?? '0';
        $category = $script['category'] ?? 'Unknown';
        $language = $script['language'] ?? 'Unknown';
        $seller = $script['seller'] ?? '';

        // Check balance
        $balance = getUserData($chatId, "balance") ?? 0;
        if (floatval($balance) < floatval($price)) {
            sendMessage($chatId, "❌ *Insufficient Balance!*\n\nYour balance: *{$balance}* STAR\nScript price: *{$price}* STAR\n\nDeposit more to buy this script.", "Markdown");
            return;
        }

        // Get files from document.json
        $docFile = $GLOBALS['DATA_DIR'] . "document.json";
        if (!file_exists($docFile)) {
            sendMessage($chatId, "⚠️ Script files missing. Contact admin.", "Markdown");
            logDebug("BUY ERROR: document.json not found for script {$param}");
            return;
        }

        $docData = json_decode(file_get_contents($docFile), true);
        
        // CRITICAL: Check if files exist under the Sell.json ID ($param)
        if (!isset($docData[$param]) || !is_array($docData[$param]) || empty($docData[$param])) {
            sendMessage($chatId, "⚠️ Script files not found (ID: {$param}). Contact admin.", "Markdown");
            logDebug("BUY ERROR: No files in document.json for key {$param}");
            logDebug("Available keys: " . (is_array($docData) ? implode(', ', array_keys($docData)) : 'none'));
            return;
        }

        $files = $docData[$param];
        $fileCount = count($files);

        // Confirm purchase to buyer
        sendMessage($chatId, "✅ *Purchase Successful!*\n\n*{$name}*\n💸 Paid: {$price} STAR\n📦 Files: {$fileCount}\n\n⬇️ Downloading all files...", "Markdown");

        // Send all files
        $sentCount = 0;
        foreach ($files as $idx => $file) {
            if (!isset($file['file_id']) || empty($file['file_id'])) {
                logDebug("BUY WARNING: Missing file_id for file " . ($idx + 1));
                continue;
            }
            
            $caption = "📄 File " . ($idx + 1) . " of {$fileCount}";
            if (isset($file['file_name'])) {
                $caption .= "\n{$file['file_name']}";
            }
            
            $result = sendDocument($chatId, $file['file_id'], $caption);
            
            if ($result && isset($result['ok']) && $result['ok']) {
                $sentCount++;
                logDebug("BUY: Sent file " . ($idx + 1) . " to {$chatId}");
            } else {
                logDebug("BUY ERROR: Failed to send file " . ($idx + 1) . " - " . json_encode($result));
            }
            
            usleep(200000); // 0.2s delay between files
        }

        if ($sentCount == 0) {
            sendMessage($chatId, "⚠️ Failed to send files. Please contact admin with your purchase details.", "Markdown");
            return;
        }

        // Update balances
        $newBalance = floatval($balance) - floatval($price);
        saveUserData($chatId, "balance", $newBalance);

        // Pay seller
        if (!empty($seller)) {
            $sellerBalance = getUserData($seller, "balance") ?? 0;
            $newSellerBalance = floatval($sellerBalance) + floatval($price);
            saveUserData($seller, "balance", $newSellerBalance);
            sendMessage($seller, "🎉 *SALE!*\n\n*{$name}* was purchased!\n💰 +{$price} STAR\n📦 Files delivered: {$sentCount}/{$fileCount}", "Markdown");
        }

        // Referral commission
        $referral = getUserData($chatId, "referral");
        if ($referral && $referral != "None") {
            $commission = floatval($price) * 0.10;
            $refBalance = getUserData($referral, "balance") ?? 0;
            saveUserData($referral, "balance", floatval($refBalance) + $commission);
            sendMessage($referral, "💸 *Referral Commission!*\nYour referral bought *{$name}*\n+{$commission} STAR", "Markdown");
        }

        // Channel notification
        $texts = "*🛒 New Purchase!*\n\n";
        $texts .= "👤 User bought *{$name}*\n";
        $texts .= "💸 Price: *{$price}* STAR\n";
        $texts .= "📦 Files: *{$sentCount}*\n\n";
        $texts .= "_🛍️ Shop more scripts!_";
        
        $key = buildInlineKeyboard([
            [['text' => '🛍️ Shop Now', 'url' => 'https://t.me/SellerScriptFastBot']]
        ]);
        sendMessage($CHANNEL, $texts, "Markdown", $key);
        
        logDebug("BUY COMPLETE: User {$chatId} bought script {$param} - {$name}, got {$sentCount} files");
        return;
    }

    // FIXED: Admin accept script - corrected data structure
        // FIXED: Admin accept script - copy document to new key
            // FIXED: Admin accept script - properly copy all files to new ID
    if (strpos($data, '/accept') === 0) {
        $params = explode(" ", $data)[1]; // This is the Names.json ID ($ran)
        $namesFile = $GLOBALS['DATA_DIR'] . "Names.json";

        if (!file_exists($namesFile)) {
            sendMessage($chatId, "❌ Error: Names.json not found");
            return;
        }

        $namesData = json_decode(file_get_contents($namesFile), true);
        if (!isset($namesData[$params])) {
            sendMessage($chatId, "❌ Error: Script not found in pending list");
            return;
        }

        $value = $namesData[$params];
        
        // Get new ID for Sell.json
        $adminValues = getUserData("admin", "values") ?? 0;
        $plus = intval($adminValues) + 1;

        // Build script data for Sell.json
        $sellData = [
            "description" => $value['description'] ?? '',
            "demo_link" => $value['demo_link'] ?? '',
            "photo" => $value['photo'] ?? $GLOBALS['PHOTO_URL'],
            "name" => $value['name'] ?? 'Unnamed',
            "price" => $value['price'] ?? '0',
            "category" => $value['category'] ?? 'General',
            "language" => $value['language'] ?? 'Unknown',
            "seller" => $value['admin'] ?? $value['seller'] ?? '',
            "file_count" => 0
        ];

        // CRITICAL FIX: Copy ALL files from document.json
        $docFile = $GLOBALS['DATA_DIR'] . "document.json";
        $fileCount = 0;
        
        if (file_exists($docFile)) {
            $docData = json_decode(file_get_contents($docFile), true);
            
            // Check if files exist under the old Names.json ID
            if (isset($docData[$params]) && is_array($docData[$params])) {
                $files = $docData[$params];
                $fileCount = count($files);
                
                // Copy files to NEW Sell.json ID ($plus)
                $docData[$plus] = $files;
                file_put_contents($docFile, json_encode($docData, JSON_PRETTY_PRINT));
                
                logDebug("ACCEPT: Copied {$fileCount} files from Names ID {$params} to Sell ID {$plus}");
            } else {
                logDebug("ACCEPT WARNING: No files found for Names ID {$params}");
                // List available keys for debugging
                if (is_array($docData)) {
                    logDebug("Available doc keys: " . implode(', ', array_keys($docData)));
                }
            }
        }
        
        $sellData['file_count'] = $fileCount;
        
        // Save to Sell.json
        saveSellData($plus, $sellData);
        
        // Update admin counter
        saveUserData("admin", "values", $plus);

        // Announce to channel
        $texts = "*🆕 New Script Available!*\n\n";
        $texts .= "*{$sellData['name']}*\n";
        $texts .= "💸 Price: *{$sellData['price']}* STAR\n";
        $texts .= "📂 Category: *{$sellData['category']}*\n";
        $texts .= "🌏 Language: *{$sellData['language']}*\n";
        $texts .= "📦 Files: *{$fileCount}*\n\n";
        $texts .= "_🛒 Buy now using the bot!_";
        
        $key = buildInlineKeyboard([
            [['text' => '🛒 Buy Now', 'url' => 'https://t.me/SellerScriptFastBot']]
        ]);
        sendPhoto($CHANNEL, $sellData['photo'], $texts, "Markdown", $key);

        // Remove from pending
        unset($namesData[$params]);
        file_put_contents($namesFile, json_encode($namesData, JSON_PRETTY_PRINT));

        // Update counter file
        $textFile = $GLOBALS['DATA_DIR'] . "text.txt";
        file_put_contents($textFile, " {$adminValues}", FILE_APPEND);

        // Notify seller
        sendMessage($sellData['seller'], "🎉 *Congratulations!*\n\nYour script *{$sellData['name']}* has been approved!\n📦 Files: {$fileCount}\n💰 You'll earn when someone buys it.", "Markdown");
        
        // Confirm to admin
        sendMessage($chatId, "✅ Script #{$plus} approved with {$fileCount} files\n\nName: {$sellData['name']}\nPrice: {$sellData['price']} STAR", "Markdown");
        
        logDebug("ACCEPT COMPLETE: Script {$plus} - {$sellData['name']} with {$fileCount} files");
        return;
    }

    // Withdraw
    if ($data == 'with') {
        $balance = getUserData($chatId, "balance");
        if (floatval($balance) < 1) {
            sendMessage($chatId, "⚠️ *You don't have star poin for withdraw*", "Markdown");
        } else {
            sendMessage($chatId, "*Please send your address TRX*", "Markdown");
            saveUserData($chatId, "state", "waiting_withdraw_address");
        }
    }

    // Cancel
    if ($data == 'cancel') {
        showMainMenu($chatId);
    }

    // Accept Bakong deposit (admin)
    if (strpos($data, 'accept_bakong_depo') === 0) {
        $param = explode(" ", $data)[1];
        sendMessage($ADMIN_ID, "*Enter amount for pay back*", "Markdown");
        saveUserData($ADMIN_ID, "state", "waiting_pay_amount");
        saveUserData($ADMIN_ID, "pay_user", $param);
    }

    // Accept TRX deposit (admin)
    if (strpos($data, 'Accept_trx') === 0) {
        $param = explode(" ", $data)[1];
        sendMessage($ADMIN_ID, "*Enter amount for user deposit*", "Markdown");
        saveUserData($ADMIN_ID, "state", "waiting_give_amount");
        saveUserData($ADMIN_ID, "give_user", $param);
    }

    // Reject script
    if (strpos($data, 'reject') === 0) {
        $params = explode(" ", $data)[1];
        $namesFile = $GLOBALS['DATA_DIR'] . "Names.json";

        if (file_exists($namesFile)) {
            $data = json_decode(file_get_contents($namesFile), true);
            if (isset($data[$params]['admin'])) {
                $admin = $data[$params]['admin'];
                $g = getUserData($admin, "values");
                $dr = intval($g) - 1;
                saveUserData($admin, "values", $dr);
                unset($data[$params]);
                file_put_contents($namesFile, json_encode($data, JSON_PRETTY_PRINT));
            }
        }
        sendMessage($chatId, "Deleted");
    }

    // Check Bakong auto deposit
    if (strpos($data, 'check_deposit_bakong_auto') === 0) {
        $pash = explode(" ", $data)[1];
        $all = explode("-", $pash);
        if (count($all) >= 3) {
            $amount = $all[1];
            $hash = $all[0];
            $currency = $all[2];

            $body = json_encode([
                "hash" => $hash,
                "amount" => floatval($amount),
                "currency" => $currency
            ]);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $BAKONG_API_URL);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$BAKONG_TOKEN}",
                "Content-type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);

            if (isset($data['responseCode']) && $data['responseCode'] == 0) {
                $toAcc = $data['data']['toAccountId'];
                $amt = $data['data']['amount'];
                $currency = $data['data']['currency'];

                if ($toAcc == $BAKONG_ACCOUNT) {
                    $hashFile = $GLOBALS['DATA_DIR'] . "hash.txt";
                    if (file_exists($hashFile) && strpos(file_get_contents($hashFile), " {$hash}") !== false) {
                        sendMessage($chatId, "Hash is ready");
                    } else {
                        $balance = getUserData($chatId, "balance");
                        $plus = floatval($balance) + floatval($amt);
                        saveUserData($chatId, "balance", $plus);
                        sendMessage($chatId, "*✅ Your deposit has completed*", "Markdown");
                        file_put_contents($hashFile, " {$hash}", FILE_APPEND);

                        // Post to channel
                        $texts = "*👤 User * has deposit using bakong\n\nStatus: *Complet*\n💳 Amount: *{$amt}*\n🗃️ Currency: *{$currency}*\n⌛ Time: *Today*\n🗂️ Category: *Deposit online*\n🆔 Id: *{$chatId}*\n\n_Deposiy with bakong now 👇_";
                        $key = buildInlineKeyboard([
                            [['text' => '🛒 Shop', 'url' => 'https://t.me/SellerScriptFastBot']]
                        ]);
                        sendMessage($CHANNEL, $texts, "Markdown", $key);
                    }
                } else {
                    sendMessage($chatId, "Error");
                }
            } else {
                sendMessage($chatId, "Bed request");
            }
        }
    }
        // ADMIN: Process file link input
    if ($state == 'waiting_file_link' && $chatId == $ADMIN_ID) {
        if ($text == '❌ Cancel') {
            saveUserData($ADMIN_ID, "state", "none");
            saveUserData($ADMIN_ID, "admin_edit_script_id", null);
            showMainMenu($chatId);
            return;
        }
        
        $scriptId = getUserData($ADMIN_ID, "admin_edit_script_id");
        if (empty($scriptId)) {
            sendMessage($chatId, "❌ Error: No script ID found. Start over.", "Markdown");
            saveUserData($ADMIN_ID, "state", "none");
            return;
        }
        
        // Parse Telegram link to get file_id
        $fileId = extractFileIdFromLink($text);
        
        if (empty($fileId)) {
            sendMessage($chatId, "❌ Invalid link or couldn't extract file ID.\n\nPlease send a valid Telegram file link or file_id directly.", "Markdown");
            // Stay in same state to retry
            return;
        }
        
        // Get file info from Telegram
        $fileInfo = getFileInfo($fileId);
        if (!$fileInfo) {
            sendMessage($chatId, "❌ Could not access file. Make sure:\n1. The link is valid\n2. The bot has access to the channel\n3. The file exists", "Markdown");
            return;
        }
        
        // Add to document.json
        $newFile = [
            'file_id' => $fileId,
            'file_name' => $fileInfo['file_name'] ?? 'unnamed_file',
            'file_size' => $fileInfo['file_size'] ?? 0,
            'added_via' => 'link',
            'added_at' => date('Y-m-d H:i:s')
        ];
        
        saveDocData($scriptId, $newFile, true);
        
        // Update file count in Sell.json
        $sellFile = $GLOBALS['DATA_DIR'] . "Sell.json";
        if (file_exists($sellFile)) {
            $sellData = json_decode(file_get_contents($sellFile), true);
            if (isset($sellData[$scriptId])) {
                $currentCount = $sellData[$scriptId]['file_count'] ?? 0;
                $sellData[$scriptId]['file_count'] = $currentCount + 1;
                file_put_contents($sellFile, json_encode($sellData, JSON_PRETTY_PRINT));
            }
        }
        
        sendMessage($chatId, "✅ *File added successfully!*\n\n", "Markdown");
        sendDocument($chatId, $fileId, "📄 Preview of added file:");
        
        // Ask if add more or finish
        $keyboard = buildReplyKeyboard([
            ['📎 Add Another Link'],
            ['✅ Done - Back to Script'],
            ['❌ Cancel']
        ]);
        sendMessage($chatId, "Add another file or finish?", "Markdown", $keyboard);
        
        // Stay in same state for multiple adds
        return;
    }
    
    // Handle add another link button
    if ($text == '📎 Add Another Link' && $chatId == $ADMIN_ID) {
        $currentState = getUserData($ADMIN_ID, "state");
        if ($currentState == 'waiting_file_link') {
            sendMessage($chatId, "Send the next file link:", "Markdown");
        }
        return;
    }
    
    if ($text == '✅ Done - Back to Script' && $chatId == $ADMIN_ID) {
        saveUserData($ADMIN_ID, "state", "none");
        $scriptId = getUserData($ADMIN_ID, "admin_edit_script_id");
        
        // Show script management again
        $callback = [
            'id' => 'internal',
            'from' => ['id' => $ADMIN_ID],
            'message' => ['chat' => ['id' => $chatId], 'message_id' => null]
        ];
        $callback['data'] = "admin_manage_script_{$scriptId}";
        handleCallback($callback);
        return;
    }
        // ADMIN: Script Management Menu
    if ($data == 'admin_scripts') {
        $sellFile = $GLOBALS['DATA_DIR'] . "Sell.json";
        if (!file_exists($sellFile)) {
            editMessageText($chatId, $messageId, "❌ No scripts in store");
            return;
        }
        
        $sellData = json_decode(file_get_contents($sellFile), true);
        $count = count($sellData);
        
        $text = "📝 *Script Management*\n\n";
        $text .= "Total scripts in store: *{$count}*\n\n";
        $text .= "Select action:";
        
        $keyboard = buildInlineKeyboard([
            [['text' => '📋 List All Scripts', 'callback_data' => 'admin_list_scripts_1']],
            [['text' => '🔍 Search Script', 'callback_data' => 'admin_search_script']],
            [['text' => '➕ Add New Script', 'callback_data' => 'admin_add_manual']],
            [['text' => '🔙 Back', 'callback_data' => 'admin_back']]
        ]);
        
        editMessageText($chatId, $messageId, $text, $keyboard);
        answerCallbackQuery($callbackId);
        return;
    }

    // ADMIN: List all scripts (paginated)
    if (strpos($data, 'admin_list_scripts_') === 0) {
        $page = intval(explode("_", $data)[3] ?? 1);
        $itemsPerPage = 10;
        
        $sellFile = $GLOBALS['DATA_DIR'] . "Sell.json";
        $sellData = json_decode(file_get_contents($sellFile), true);
        
        if (empty($sellData)) {
            editMessageText($chatId, $messageId, "❌ No scripts found");
            return;
        }
        
        $scripts = [];
        foreach ($sellData as $id => $script) {
            if (is_array($script) && isset($script['name'])) {
                $scripts[] = ['id' => $id, 'data' => $script];
            }
        }
        
        $total = count($scripts);
        $totalPages = max(1, ceil($total / $itemsPerPage));
        $page = max(1, min($page, $totalPages));
        
        $start = ($page - 1) * $itemsPerPage;
        $pageScripts = array_slice($scripts, $start, $itemsPerPage);
        
        $text = "📋 *All Scripts (Page {$page}/{$totalPages})*\n\n";
        $text .= "Total: *{$total}* scripts\n\n";
        
        // Build numbered list
        $counter = $start + 1;
        foreach ($pageScripts as $script) {
            $name = $script['data']['name'];
            $price = $script['data']['price'] ?? '0';
            $seller = $script['data']['seller'] ?? 'Unknown';
            $fileCount = $script['data']['file_count'] ?? 1;
            
            $text .= "*{$counter}.* {$name}\n";
            $text .= "    💰 {$price}⭐ | 👤 {$seller} | 📦 {$fileCount} files\n\n";
            $counter++;
        }
        
        $text .= "🔢 *Click number to manage script*";
        
        // Number buttons
        $buttons = [];
        $row = [];
        foreach ($pageScripts as $index => $script) {
            $num = $start + $index + 1;
            $row[] = ['text' => (string)$num, 'callback_data' => "admin_manage_script_{$script['id']}"];
            if (count($row) == 5) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $buttons[] = $row;
        }
        
        // Navigation
        $navRow = [];
        if ($page > 1) {
            $navRow[] = ['text' => '⬅️', 'callback_data' => "admin_list_scripts_" . ($page - 1)];
        }
        $navRow[] = ['text' => "{$page}/{$totalPages}", 'callback_data' => 'admin_nop'];
        if ($page < $totalPages) {
            $navRow[] = ['text' => '➡️', 'callback_data' => "admin_list_scripts_" . ($page + 1)];
        }
        $buttons[] = $navRow;
        
        $buttons[] = [['text' => '🔙 Back to Menu', 'callback_data' => 'admin_scripts']];
        
        $keyboard = buildInlineKeyboard($buttons);
        editMessageText($chatId, $messageId, $text, $keyboard);
        answerCallbackQuery($callbackId);
        return;
    }
        // ADMIN: Manage specific script
    if (strpos($data, 'admin_manage_script_') === 0) {
        $scriptId = explode("_", $data)[3];
        
        $sellFile = $GLOBALS['DATA_DIR'] . "Sell.json";
        $sellData = json_decode(file_get_contents($sellFile), true);
        
        if (!isset($sellData[$scriptId])) {
            answerCallbackQuery($callbackId, "Script not found");
            return;
        }
        
        $script = $sellData[$scriptId];
        
        // Get files info
        $docFile = $GLOBALS['DATA_DIR'] . "document.json";
        $files = [];
        if (file_exists($docFile)) {
            $docData = json_decode(file_get_contents($docFile), true);
            $files = $docData[$scriptId] ?? [];
        }
        
        $text = "📝 *Managing Script #{$scriptId}*\n\n";
        $text .= "*Name:* {$script['name']}\n";
        $text .= "*Price:* {$script['price']} STAR\n";
        $text .= "*Category:* {$script['category']}\n";
        $text .= "*Language:* {$script['language']}\n";
        $text .= "*Seller:* {$script['seller']}\n";
        $text .= "*Files:* " . count($files) . "\n\n";
        $text .= "*Description:*\n{$script['description']}\n\n";
        $text .= "Select action:";
        
        $keyboard = buildInlineKeyboard([
            [['text' => '✏️ Edit Details', 'callback_data' => "admin_edit_{$scriptId}"], ['text' => '💰 Change Price', 'callback_data' => "admin_price_{$scriptId}"]],
            [['text' => '📁 Replace Files', 'callback_data' => "admin_files_{$scriptId}"], ['text' => '📎 Add File (Link)', 'callback_data' => "admin_addlink_{$scriptId}"]],
            [['text' => '🗑️ Delete Script', 'callback_data' => "admin_delete_{$scriptId}"]],
            [['text' => '👁️ Preview Files', 'callback_data' => "admin_preview_{$scriptId}"]],
            [['text' => '🔙 Back to List', 'callback_data' => 'admin_list_scripts_1']]
        ]);
        
        editMessageText($chatId, $messageId, $text, $keyboard);
        answerCallbackQuery($callbackId, "Managing: {$script['name']}");
        return;
    }
        // ADMIN: Replace/Add files via Telegram link
    if (strpos($data, 'admin_addlink_') === 0) {
        $scriptId = explode("_", $data)[2];
        
        saveUserData($ADMIN_ID, "admin_edit_script_id", $scriptId);
        saveUserData($ADMIN_ID, "state", "waiting_file_link");
        
        $text = "📎 *Add File via Telegram Link*\n\n";
        $text .= "Script ID: `{$scriptId}`\n\n";
        $text .= "To add a file via link:\n";
        $text .= "1. Go to your channel/storage\n";
        $text .= "2. Forward the file to @getidsbot or right-click → Copy Link\n";
        $text .= "3. Paste the link here\n\n";
        $text .= "Supported formats:\n";
        $text .= "• `https://t.me/c/CHANNELID/MESSAGEID`\n";
        $text .= "• `https://t.me/username/MESSAGEID`\n";
        $text .= "• Direct file_id if you have it\n\n";
        $text .= "Send the link now or click Cancel:";
        
        $keyboard = buildReplyKeyboard([['❌ Cancel']]);
        sendMessage($chatId, $text, "Markdown", $keyboard);
        answerCallbackQuery($callbackId);
        return;
    }
        // ADMIN: Edit script details
    if (strpos($data, 'admin_edit_') === 0) {
        $scriptId = explode("_", $data)[2];
        
        saveUserData($ADMIN_ID, "admin_edit_script_id", $scriptId);
        saveUserData($ADMIN_ID, "state", "admin_edit_menu");
        
        $text = "✏️ *Edit Script #{$scriptId}*\n\n";
        $text .= "What would you like to edit?\n\n";
        $text .= "1️⃣ Name\n";
        $text .= "2️⃣ Description\n";
        $text .= "3️⃣ Category\n";
        $text .= "4️⃣ Language\n";
        $text .= "5️⃣ Demo Link\n";
        $text .= "6️⃣ Photo/Thumbnail\n\n";
        $text .= "Send the number (1-6):";
        
        $keyboard = buildReplyKeyboard([['❌ Cancel']]);
        sendMessage($chatId, $text, "Markdown", $keyboard);
        answerCallbackQuery($callbackId);
        return;
    }

    // ADMIN: Change price only
    if (strpos($data, 'admin_price_') === 0) {
        $scriptId = explode("_", $data)[2];
        
        saveUserData($ADMIN_ID, "admin_edit_script_id", $scriptId);
        saveUserData($ADMIN_ID, "state", "waiting_new_price");
        
        $sellFile = $GLOBALS['DATA_DIR'] . "Sell.json";
        $sellData = json_decode(file_get_contents($sellFile), true);
        $currentPrice = $sellData[$scriptId]['price'] ?? '0';
        
        sendMessage($chatId, "💰 *Change Price*\n\nScript ID: `{$scriptId}`\nCurrent price: *{$currentPrice}* STAR\n\nEnter new price:", "Markdown", buildReplyKeyboard([['❌ Cancel']]));
        answerCallbackQuery($callbackId);
        return;
    }
        // ADMIN: Preview all files of a script
    if (strpos($data, 'admin_preview_') === 0) {
        $scriptId = explode("_", $data)[2];
        
        $docFile = $GLOBALS['DATA_DIR'] . "document.json";
        if (!file_exists($docFile)) {
            answerCallbackQuery($callbackId, "No files found");
            return;
        }
        
        $docData = json_decode(file_get_contents($docFile), true);
        if (!isset($docData[$scriptId]) || empty($docData[$scriptId])) {
            answerCallbackQuery($callbackId, "No files for this script");
            return;
        }
        
        $files = $docData[$scriptId];
        
        sendMessage($chatId, "📁 *Previewing " . count($files) . " file(s)* for script #{$scriptId}:", "Markdown");
        
        foreach ($files as $idx => $file) {
            $caption = "📄 File " . ($idx + 1) . " of " . count($files);
            if (isset($file['file_name'])) {
                $caption .= "\n{$file['file_name']}";
            }
            sendDocument($chatId, $file['file_id'], $caption);
            usleep(200000);
        }
        
        answerCallbackQuery($callbackId, "Sent " . count($files) . " files");
        return;
    }
        // ADMIN: Delete script confirmation
    if (strpos($data, 'admin_delete_') === 0) {
        $scriptId = explode("_", $data)[2];
        
        saveUserData($ADMIN_ID, "admin_delete_script_id", $scriptId);
        
        $text = "🗑️ *Delete Script #{$scriptId}*\n\n";
        $text .= "Are you sure you want to delete this script?\n";
        $text .= "This will remove it from the store permanently.\n\n";
        $text .= "Files will be kept in storage for existing buyers.";
        
        $keyboard = buildInlineKeyboard([
            [['text' => '⚠️ YES - DELETE', 'callback_data' => 'admin_confirm_delete'], ['text' => '❌ NO - CANCEL', 'callback_data' => 'admin_cancel_delete']]
        ]);
        
        editMessageText($chatId, $messageId, $text, $keyboard);
        answerCallbackQuery($callbackId);
        return;
    }
    
    
        // ADMIN: Add balance button
    if (strpos($data, 'admin_addbal_') === 0) {
        $userId = explode("_", $data)[2];
        saveUserData($ADMIN_ID, "admin_target_user", $userId);
        saveUserData($ADMIN_ID, "state", "waiting_add_balance");
        
        $currentBalance = getUserData($userId, "balance") ?? 0;
        
        sendMessage($chatId, "💰 *Add Balance*\n\nUser: `{$userId}`\nCurrent: {$currentBalance} STAR\n\nEnter amount to add:", "Markdown", buildReplyKeyboard([['❌ Cancel']]));
        answerCallbackQuery($callbackId);
        return;
    }

    // ADMIN: Deduct balance button
    if (strpos($data, 'admin_deductbal_') === 0) {
        $userId = explode("_", $data)[2];
        saveUserData($ADMIN_ID, "admin_target_user", $userId);
        saveUserData($ADMIN_ID, "state", "waiting_deduct_balance");
        
        $currentBalance = getUserData($userId, "balance") ?? 0;
        
        sendMessage($chatId, "➖ *Deduct Balance*\n\nUser: `{$userId}`\nCurrent: {$currentBalance} STAR\n\nEnter amount to deduct:", "Markdown", buildReplyKeyboard([['❌ Cancel']]));
        answerCallbackQuery($callbackId);
        return;
    }

    // ADMIN: Message user button
    if (strpos($data, 'admin_msg_') === 0) {
        $userId = explode("_", $data)[2];
        saveUserData($ADMIN_ID, "admin_target_user", $userId);
        saveUserData($ADMIN_ID, "state", "waiting_admin_message");
        
        sendMessage($chatId, "📨 *Send Message to User*\n\nUser ID: `{$userId}`\n\nType your message:", "Markdown", buildReplyKeyboard([['❌ Cancel']]));
        answerCallbackQuery($callbackId);
        return;
    }

    // ADMIN: Ban user
    if (strpos($data, 'admin_ban_') === 0) {
        $userId = explode("_", $data)[2];
        
        // Add to banned list
        $banFile = $GLOBALS['DATA_DIR'] . "banned.txt";
        $banned = file_exists($banFile) ? file_get_contents($banFile) : "";
        
        if (strpos($banned, " {$userId}") !== false) {
            // Already banned - unban
            $banned = str_replace(" {$userId}", "", $banned);
            file_put_contents($banFile, $banned);
            sendMessage($userId, "✅ *You have been unbanned*\n\nYou can now use the bot again.", "Markdown");
            answerCallbackQuery($callbackId, "User unbanned");
        } else {
            // Ban user
            file_put_contents($banFile, " {$userId}", FILE_APPEND);
            saveUserData($userId, "banned", true);
            sendMessage($userId, "🚫 *You have been banned*\n\nContact admin if you think this is a mistake.", "Markdown");
            answerCallbackQuery($callbackId, "User banned");
        }
        
        // Refresh user detail view
        $callback['data'] = "admin_user_detail_{$userId}";
        handleCallback($callback);
        return;
    }

    // ADMIN: Back button
    if ($data == 'admin_back' || $data == 'admin_nop') {
        handleAdmin($callback['message']);
        answerCallbackQuery($callbackId);
        return;
    }

    // ADMIN: List all users (paginated)
    if (strpos($data, 'admin_list_users_') === 0) {
        $page = intval(explode("_", $data)[3] ?? 1);
        $perPage = 10;
        
        $tgFile = $GLOBALS['DATA_DIR'] . "tg.txt";
        if (!file_exists($tgFile)) {
            editMessageText($chatId, $messageId, "❌ No users found");
            return;
        }
        
        $content = trim(file_get_contents($tgFile));
        $allUsers = array_filter(explode(" ", $content));
        $total = count($allUsers);
        
        if ($total == 0) {
            editMessageText($chatId, $messageId, "❌ No users found");
            return;
        }
        
        $totalPages = max(1, ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        
        $start = ($page - 1) * $perPage;
        $pageUsers = array_slice($allUsers, $start, $perPage);
        
        $text = "👥 *All Users (Page {$page}/{$totalPages})*\n\n";
        $text .= "Total: *{$total}* users\n\n";
        
        $buttons = [];
        $counter = $start + 1;
        
        foreach ($pageUsers as $userId) {
            $userId = trim($userId);
            if (empty($userId)) continue;
            
            // Get user info
            $balance = getUserData($userId, "balance") ?? 0;
            $verified = checkVerification($userId) ? "✅" : "⏳";
            
            $text .= "{$counter}. `{$userId}` {$verified}\n";
            $text .= "   💰 {$balance} STAR\n\n";
            
            $buttons[] = [['text' => (string)$counter, 'callback_data' => "admin_user_detail_{$userId}"]];
            $counter++;
        }
        
        // 5 buttons per row
        $rows = [];
        $row = [];
        foreach ($buttons as $btn) {
            $row[] = $btn[0];
            if (count($row) == 5) {
                $rows[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $rows[] = $row;
        }
        
        // Navigation
        $navRow = [];
        if ($page > 1) {
            $navRow[] = ['text' => '⬅️', 'callback_data' => "admin_list_users_" . ($page - 1)];
        }
        $navRow[] = ['text' => "{$page}/{$totalPages}", 'callback_data' => 'admin_nop'];
        if ($page < $totalPages) {
            $navRow[] = ['text' => '➡️', 'callback_data' => "admin_list_users_" . ($page + 1)];
        }
        $rows[] = $navRow;
        $rows[] = [['text' => '🔙 Back', 'callback_data' => 'admin_users']];
        
        $keyboard = buildInlineKeyboard($rows);
        editMessageText($chatId, $messageId, $text, $keyboard);
        answerCallbackQuery($callbackId);
        return;
    }

    // ADMIN: User detail view
    if (strpos($data, 'admin_user_detail_') === 0) {
        $userId = explode("_", $data)[3];
        
        // Get all user data
        $balance = getUserData($userId, "balance") ?? 0;
        $totalDeposit = getUserData($userId, "deposit_total") ?? 0;
        $withdrawTotal = getUserData($userId, "withdraw_total") ?? 0;
        $values = getUserData($userId, "values") ?? 0;
        $referral = getUserData($userId, "referral") ?? 'None';
        $verified = checkVerification($userId);
        $history = getUserData($userId, "history") ?? 0;
        
        // Get username from tg.txt lookup or API
        $username = "Unknown";
        
        $text = "👤 *User Details*\n\n";
        $text .= "🆔 ID: `{$userId}`\n";
        $text .= "👤 Username: {$username}\n";
        $text .= "✅ Verified: " . ($verified ? "Yes" : "No") . "\n";
        $text .= "💰 Balance: *{$balance}* STAR\n";
        $text .= "📥 Total Deposit: {$totalDeposit}\n";
        $text .= "📤 Total Withdraw: {$withdrawTotal}\n";
        $text .= "📝 Scripts Listed: {$values}\n";
        $text .= "👥 Referred By: {$referral}\n";
        $text .= "📊 Transactions: {$history}\n\n";
        
        $keyboard = buildInlineKeyboard([
            [['text' => '💰 Add Balance', 'callback_data' => "admin_addbal_{$userId}"], ['text' => '➖ Deduct Balance', 'callback_data' => "admin_deductbal_{$userId}"]],
            [['text' => '🚫 Ban User', 'callback_data' => "admin_ban_{$userId}"], ['text' => '📨 Message User', 'callback_data' => "admin_msg_{$userId}"]],
            [['text' => '📋 View History', 'callback_data' => "admin_userhist_{$userId}"]],
            [['text' => '🔙 Back to List', 'callback_data' => 'admin_list_users_1']]
        ]);
        
        editMessageText($chatId, $messageId, $text, $keyboard);
        answerCallbackQuery($callbackId, "User: {$userId}");
        return;
    }
        // ADMIN: Statistics
    if ($data == 'admin_stats') {
        // Gather stats
        $usersFile = $DATA_DIR . "tg.txt";
        $totalUsers = 0;
        if (file_exists($usersFile)) {
            $content = trim(file_get_contents($usersFile));
            $totalUsers = !empty($content) ? count(array_filter(explode(" ", $content))) : 0;
        }
        
        $verifiedFile = $DATA_DIR . "verify.txt";
        $verifiedUsers = 0;
        if (file_exists($verifiedFile)) {
            $content = trim(file_get_contents($verifiedFile));
            $verifiedUsers = !empty($content) ? count(array_filter(explode(" ", $content))) : 0;
        }
        
        $sellFile = $DATA_DIR . "Sell.json";
        $totalScripts = 0;
        $totalScriptValue = 0;
        if (file_exists($sellFile)) {
            $sellData = json_decode(file_get_contents($sellFile), true);
            $totalScripts = count($sellData);
            foreach ($sellData as $script) {
                $totalScriptValue += floatval($script['price'] ?? 0);
            }
        }
        
        $namesFile = $DATA_DIR . "Names.json";
        $pendingScripts = file_exists($namesFile) ? count(json_decode(file_get_contents($namesFile), true)) : 0;
        
        // Calculate total balance in system
        $usersDataFile = $DATA_DIR . "users.json";
        $totalUserBalance = 0;
        if (file_exists($usersDataFile)) {
            $usersData = json_decode(file_get_contents($usersDataFile), true);
            foreach ($usersData as $userId => $userData) {
                $totalUserBalance += floatval($userData['balance'] ?? 0);
            }
        }
        
        $text = "📊 *Bot Statistics*\n\n";
        $text .= "👥 *Users:*\n";
        $text .= "• Total: {$totalUsers}\n";
        $text .= "• Verified: {$verifiedUsers}\n";
        $text .= "• Unverified: " . ($totalUsers - $verifiedUsers) . "\n\n";
        
        $text .= "📝 *Scripts:*\n";
        $text .= "• Active: {$totalScripts}\n";
        $text .= "• Pending: {$pendingScripts}\n";
        $text .= "• Total Value: {$totalScriptValue} STAR\n\n";
        
        $text .= "💰 *Economy:*\n";
        $text .= "• Total User Balance: {$totalUserBalance} STAR\n";
        $text .= "• Average per user: " . ($totalUsers > 0 ? round($totalUserBalance / $totalUsers, 2) : 0) . " STAR\n\n";
        
        $text .= "📅 *Generated:* " . date('Y-m-d H:i:s');
        
        $keyboard = buildInlineKeyboard([
            [['text' => '🔄 Refresh', 'callback_data' => 'admin_stats']],
            [['text' => '🔙 Back', 'callback_data' => 'admin_back']]
        ]);
        
        editMessageText($chatId, $messageId, $text, $keyboard);
        answerCallbackQuery($callbackId);
        return;
    }
    if ($data == 'admin_users') {
        $usersFile = $GLOBALS['DATA_DIR'] . "users.json";
        $tgFile = $GLOBALS['DATA_DIR'] . "tg.txt";
        
        $totalUsers = 0;
        if (file_exists($tgFile)) {
            $content = trim(file_get_contents($tgFile));
            $totalUsers = !empty($content) ? count(explode(" ", $content)) : 0;
        }
        
        $verifiedFile = $GLOBALS['DATA_DIR'] . "verify.txt";
        $verifiedCount = 0;
        if (file_exists($verifiedFile)) {
            $content = trim(file_get_contents($verifiedFile));
            $verifiedCount = !empty($content) ? count(explode(" ", $content)) : 0;
        }
        
        $text = "👥 *User Management*\n\n";
        $text .= "📊 Statistics:\n";
        $text .= "• Total users: *{$totalUsers}*\n";
        $text .= "• Verified: *{$verifiedCount}*\n";
        $text .= "• Unverified: *" . ($totalUsers - $verifiedCount) . "*\n\n";
        $text .= "Select action:";
        
        $keyboard = buildInlineKeyboard([
            [['text' => '🔍 Find User', 'callback_data' => 'admin_find_user']],
            [['text' => '📋 List All Users', 'callback_data' => 'admin_list_users_1']],
            [['text' => '🚫 Banned Users', 'callback_data' => 'admin_banned_users']],
            [['text' => '💰 Top Buyers', 'callback_data' => 'admin_top_buyers']],
            [['text' => '🔙 Back', 'callback_data' => 'admin_back']]
        ]);
        
        editMessageText($chatId, $messageId, $text, $keyboard);
        answerCallbackQuery($callbackId);
        return;
    }
        // ADMIN: Add balance to user
    
}

// ==================== STATE HANDLERS ====================

function handleState($message, $state) {
    global $ADMIN_ID, $CHANNEL, $PHOTO_URL;
    $chatId = $message['chat']['id'];
    $text = isset($message['text']) ? $message['text'] : '';

    // Cancel handling - works in any state
    if ($text == '🚫 Cancel') {
        saveUserData($chatId, "state", "none");
        showMainMenu($chatId);
        return;
    }

    // OTP Verification
    if ($state == 'waiting_otp') {
        $expected = getUserData($chatId, "otp_expected");

        if ($text == $expected) {
            addVerification($chatId);
            sendMessage($chatId, "✅ Correct");

            // Check if there's a pending referral to process
            $pendingReferral = getUserData($chatId, "pending_referral");
            if (!empty($pendingReferral) && $pendingReferral != "None") {
                processReferralAfterVerification($chatId, $pendingReferral);
                saveUserData($chatId, "pending_referral", "None");
            }

            showJoinedMenu($chatId);
        } else {
            sendMessage($chatId, "❌ Wrong");
        }
        saveUserData($chatId, "state", "none");
        return;
    }

    // Support message
    if ($state == 'waiting_support') {
        $msg = "👤 User: {$message['chat']['first_name']}\n\n🆔 Id: {$chatId}\n\n✉️ Message: {$text}\n\n";
        sendMessage($ADMIN_ID, $msg);
        sendMessage($chatId, "✅ Your message has send to support please wait.");
        saveUserData($chatId, "state", "none");
        return;
    }

    // TRX deposit link
    if ($state == 'waiting_trx_link') {
        $ley = buildInlineKeyboard([
            [['text' => '✅ Accept', 'callback_data' => "Accept_trx {$chatId}"], ['text' => '🚫 Rejecte', 'callback_data' => "reject_trx {$chatId}"]]
        ]);
        $info = "*💸 User has deposit using crypto currency*\n\n👤 Name: *{$message['chat']['first_name']}*\n\n🆔 Id: *{$chatId}*\n\n🔗 Link: {$text}";
        sendMessage($ADMIN_ID, $info, "Markdown", $ley);
        sendMessage($chatId, "✅ Please wait up to 24h before admin accept");
        saveUserData($chatId, "state", "none");
        return;
    }

    // Withdraw address
    if ($state == 'waiting_withdraw_address') {
        $balance = getUserData($chatId, "balance");
        $address = $text;
        $textMsg = "👤 *{$message['chat']['first_name']}*\n\n🆔 Id: {$chatId}\n\n⭐ Balance: {$balance}\n\n📂 Address: {$address}";
        sendMessage($CHANNEL, $textMsg, "Markdown");
        sendMessage($ADMIN_ID, $textMsg, "Markdown");
        sendMessage($chatId, "✅ Please wait up to 24h before admin confirm");
        saveUserData($chatId, "balance", 0);
        saveUserData($chatId, "state", "none");
        return;
    }

    // Admin give amount (TRX)
    if ($state == 'waiting_give_amount' && $chatId == $ADMIN_ID) {
        $amount = $text;
        $depositor = getUserData($ADMIN_ID, "give_user");
        $values = getUserData($depositor, "history") ?? 0;
        $all = intval($values) + 1;

        $jsons = [
            $all => [
                "id" => $depositor,
                "values" => $all,
                "category" => "deposit",
                "payment" => "trx",
                "amount" => $amount
            ]
        ];

        historySaveData($depositor, $jsons);
        saveUserData($depositor, "history", $all);
        sendMessage($depositor, "🎉 Your deposit crypto *{$amount}* has completed", "Markdown");

        $bal = getUserData($depositor, "balance");
        $total = intval($bal) + intval($amount);
        saveUserData($depositor, "balance", $total);
        saveUserData($ADMIN_ID, "state", "none");
        return;
    }

    // Admin pay amount (Bakong)
    if ($state == 'waiting_pay_amount' && $chatId == $ADMIN_ID) {
        $amount = $text;
        $depositor = getUserData($ADMIN_ID, "pay_user");

        sendMessage($depositor, "🎉 Your deposit crypto *{$amount}* has completed", "Markdown");

        $textz = "👤 {$message['chat']['first_name']} has deposit with *bakong*\n\n💸 Amount: *{$amount}*\n\n📊 Status: *Complete*\n\n_🔶 Check our script click button below_";
        $key = buildInlineKeyboard([
            [['text' => '🛒 Check', 'url' => 'https://t.me/SellerScriptFastBot']]
        ]);
        sendMessage($CHANNEL, $textz, "Markdown", $key);

        $bal = getUserData($depositor, "balance");
        $total = intval($bal) + intval($amount);
        saveUserData($depositor, "balance", $total);
        saveUserData($ADMIN_ID, "state", "none");
        return;
    }

    // Referral code input
    if ($state == 'waiting_referral_code') {
        handleReferralCode($message, $text);
        saveUserData($chatId, "state", "none");
        return;
    }

    // Sell script flow
    if ($state == 'waiting_photo') {
        if (isset($message['photo'])) {
            $photo = end($message['photo']);
            $fileId = $photo['file_id'];
            saveUserData($chatId, "temp_photo", $fileId);
            saveUserData($chatId, "state", "waiting_script_name");
            sendMessage($chatId, "*Enter your name script*", "Markdown");
        } else {
            sendMessage($chatId, "Please send a photo");
        }
        return;
    }

    if ($state == 'waiting_script_name') {
        saveUserData($chatId, "temp_name", $text);
        saveUserData($chatId, "state", "waiting_script_price");
        sendMessage($chatId, "*Enter your price*", "Markdown");
        return;
    }

    if ($state == 'waiting_script_price') {
        if (!is_numeric($text)) {
            sendMessage($chatId, "Please enter a valid number");
            return;
        }
        saveUserData($chatId, "temp_price", $text);
        saveUserData($chatId, "state", "waiting_script_category");
        sendMessage($chatId, "*Enter your script category*", "Markdown");
        return;
    }

    if ($state == 'waiting_script_category') {
        saveUserData($chatId, "temp_category", $text);
        saveUserData($chatId, "state", "waiting_script_language");
        sendMessage($chatId, "*Enter your script language*", "Markdown");
        return;
    }

    if ($state == 'waiting_script_language') {
        saveUserData($chatId, "temp_language", $text);
        saveUserData($chatId, "state", "waiting_script_description");
        sendMessage($chatId, "*Enter your description about your script*", "Markdown");
        return;
    }

    if ($state == 'waiting_script_description') {
        saveUserData($chatId, "temp_description", $text);
        saveUserData($chatId, "state", "waiting_script_demo");
        sendMessage($chatId, "*Enter your demo link*", "Markdown");
        return;
    }

    if ($state == 'waiting_script_demo') {
        $demoLink = $text;
        $name = getUserData($chatId, "temp_name");
        $price = getUserData($chatId, "temp_price");
        $category = getUserData($chatId, "temp_category");
        $language = getUserData($chatId, "temp_language");
        $description = getUserData($chatId, "temp_description");
        $photo = getUserData($chatId, "temp_photo");

        $ran = rand(1, 999999);
        $va = getUserData($chatId, "values") ?? 0;
        $plus = intval($va) + 1;

        // FIXED: Flat data structure instead of wrapped
        $jsonsn = [
            "description" => $description,
            "demo_link" => $demoLink,
            "photo" => $photo,
            "name" => $name,
            "price" => $price,
            "category" => $category,
            "language" => $language,
            "values" => $plus,
            "admin" => $chatId,
            "random" => $ran
        ];

        saveNameData($ran, $jsonsn);
        saveUserData($chatId, "temp_random", $ran);
        saveUserData($chatId, "state", "waiting_script_document");
        sendMessage($chatId, "Please send Your documents file with no error");
        return;
    }

    // FIXED: Document saving with correct key matching
            // First file upload
        // ==================== MULTI-FILE UPLOAD SYSTEM ====================
    
    // First file upload
    if ($state == 'waiting_script_document') {
        if (isset($message['document'])) {
            $ran = getUserData($chatId, "temp_random");
            
            logDebug("First file received for script {$ran}: " . $message['document']['file_name']);

            // Initialize files array with first file
            $filesArray = [
                [
                    'file_id' => $message['document']['file_id'],
                    'file_name' => $message['document']['file_name'],
                    'file_size' => $message['document']['file_size']
                ]
            ];

            saveDocData($ran, $filesArray, false); // Initialize with array
            
            // Build keyboard - MUST use buildReplyKeyboard correctly
            $keyboardButtons = [
                ['📎 Add Another File'],
                ['✅ Done - Submit to Admin'],
                ['🚫 Cancel']
            ];
            $keyboard = buildReplyKeyboard($keyboardButtons);
            
            $responseText = "✅ *File 1 added*: `{$message['document']['file_name']}`\n\n";
            $responseText .= "Size: " . round($message['document']['file_size']/1024, 2) . " KB\n\n";
            $responseText .= "What would you like to do?";
            
            logDebug("Sending keyboard to user {$chatId}");
            
            sendMessage($chatId, $responseText, "Markdown", $keyboard);
            
            // IMPORTANT: Change state to allow more files
            saveUserData($chatId, "state", "waiting_additional_files");
            logDebug("State changed to waiting_additional_files for user {$chatId}");
            
        } else {
            sendMessage($chatId, "❌ Please send a *document file* (ZIP, PDF, etc.)\n\nOr click 🚫 Cancel to exit", "Markdown");
        }
        return;
    }

    // Additional files upload
    if ($state == 'waiting_additional_files') {
        logDebug("waiting_additional_files state - Text: '{$text}', HasDoc: " . (isset($message['document']) ? 'yes' : 'no'));
        
        // Handle button clicks
        if ($text == '📎 Add Another File') {
            sendMessage($chatId, "📤 *Send the next file...*\n\nUpload another document or photo.", "Markdown");
            return;
        }
        
        if ($text == '✅ Done - Submit to Admin') {
            $ran = getUserData($chatId, "temp_random");
            
            // Get all files
            $docFile = $GLOBALS['DATA_DIR'] . "document.json";
            $files = [];
            if (file_exists($docFile)) {
                $docData = json_decode(file_get_contents($docFile), true);
                $files = $docData[$ran] ?? [];
            }
            
            if (empty($files)) {
                sendMessage($chatId, "❌ No files found! Please start over with /sellscript", "Markdown");
                saveUserData($chatId, "state", "none");
                return;
            }
            
            logDebug("Submitting script {$ran} with " . count($files) . " files to admin");
            
            // Build file list for admin
            $fileList = "";
            foreach ($files as $idx => $file) {
                $fileList .= ($idx + 1) . ". `{$file['file_name']}` (" . round($file['file_size']/1024, 2) . " KB)\n";
            }
            
            // Get script details from temp data
            $scriptName = getUserData($chatId, "temp_name") ?? 'Unknown';
            $scriptPrice = getUserData($chatId, "temp_price") ?? '0';
            $scriptCategory = getUserData($chatId, "temp_category") ?? 'Unknown';
            
            $informationUser = "📦 *New Script Submission*\n\n";
            $informationUser .= "📛 *Name:* {$scriptName}\n";
            $informationUser .= "💰 *Price:* {$scriptPrice} STAR\n";
            $informationUser .= "📂 *Category:* {$scriptCategory}\n";
            $informationUser .= "👤 *Seller:* {$message['chat']['first_name']} ({$chatId})\n";
            $informationUser .= "🆔 *Script ID:* `{$ran}`\n\n";
            $informationUser .= "📎 *Files (" . count($files) . "):*\n{$fileList}\n";
            $informationUser .= "Click *Accept All* to approve this submission";

            $adminKeyboard = buildInlineKeyboard([
                [
                    ['text' => '✅ Accept All (' . count($files) . ' files)', 'callback_data' => "/accept {$ran}"],
                    ['text' => '🚫 Reject', 'callback_data' => "reject {$ran}"]
                ]
            ]);
            
            // Send admin notification
            sendMessage($ADMIN_ID, $informationUser, "Markdown", $adminKeyboard);
            
            // Send all files to admin for review
            sendMessage($ADMIN_ID, "📁 *File Attachments:*", "Markdown");
            foreach ($files as $idx => $file) {
                $docCaption = "📄 File " . ($idx + 1) . " of " . count($files) . ": {$file['file_name']}";
                sendDocument($ADMIN_ID, $file['file_id'], $docCaption);
                usleep(200000); // 0.2s delay
            }
            
            // Confirm to seller
            sendMessage($chatId, "✅ *Submitted to admin!*\n\nYour script with " . count($files) . " file(s) is pending approval.\nYou'll be notified once approved.", "Markdown");
            
            // Update state and menu
            saveUserData($chatId, "state", "none");
            showMainMenu($chatId);
            return;
        }
        
        if ($text == '🚫 Cancel') {
            // Clean up temp data
            $ran = getUserData($chatId, "temp_random");
            $docFile = $GLOBALS['DATA_DIR'] . "document.json";
            if (file_exists($docFile)) {
                $docData = json_decode(file_get_contents($docFile), true);
                unset($docData[$ran]);
                file_put_contents($docFile, json_encode($docData, JSON_PRETTY_PRINT));
            }
            
            sendMessage($chatId, "❌ Submission cancelled. Files discarded.", "Markdown");
            saveUserData($chatId, "state", "none");
            showMainMenu($chatId);
            return;
        }
        
        // Handle document upload
        if (isset($message['document'])) {
            $ran = getUserData($chatId, "temp_random");
            
            // Append additional file
            $newFile = [
                'file_id' => $message['document']['file_id'],
                'file_name' => $message['document']['file_name'],
                'file_size' => $message['document']['file_size']
            ];
            
            saveDocData($ran, $newFile, true); // Append = true
            
            // Get updated count
            $docFile = $GLOBALS['DATA_DIR'] . "document.json";
            $count = 1;
            if (file_exists($docFile)) {
                $docData = json_decode(file_get_contents($docFile), true);
                $count = count($docData[$ran] ?? []);
            }
            
            logDebug("Added file {$count} for script {$ran}: {$newFile['file_name']}");
            
            // Re-show keyboard
            $keyboardButtons = [
                ['📎 Add Another File'],
                ['✅ Done - Submit to Admin'],
                ['🚫 Cancel']
            ];
            $keyboard = buildReplyKeyboard($keyboardButtons);
            
            $responseText = "✅ *File {$count} added*: `{$message['document']['file_name']}`\n\n";
            $responseText .= "Total files: *{$count}*\n\n";
            $responseText .= "Add more or submit?";
            
            sendMessage($chatId, $responseText, "Markdown", $keyboard);
            // Stay in same state
            
        } else {
            // Not a document and not a recognized command
            sendMessage($chatId, "❌ Please send a *file* or click a button below.\n\nUse the keyboard buttons to continue.", "Markdown");
        }
        return;
    }
        // ADMIN: Process file link input
    if ($state == 'waiting_file_link' && $chatId == $ADMIN_ID) {
        if ($text == '❌ Cancel') {
            saveUserData($ADMIN_ID, "state", "none");
            saveUserData($ADMIN_ID, "admin_edit_script_id", null);
            showMainMenu($chatId);
            return;
        }
        
        $scriptId = getUserData($ADMIN_ID, "admin_edit_script_id");
        if (empty($scriptId)) {
            sendMessage($chatId, "❌ Error: No script ID found. Start over.", "Markdown");
            saveUserData($ADMIN_ID, "state", "none");
            return;
        }
        
        // Parse Telegram link to get file_id
        $fileId = extractFileIdFromLink($text);
        
        if (empty($fileId)) {
            sendMessage($chatId, "❌ Invalid link or couldn't extract file ID.\n\nPlease send a valid Telegram file link or file_id directly.", "Markdown");
            // Stay in same state to retry
            return;
        }
        
        // Get file info from Telegram
        $fileInfo = getFileInfo($fileId);
        if (!$fileInfo) {
            sendMessage($chatId, "❌ Could not access file. Make sure:\n1. The link is valid\n2. The bot has access to the channel\n3. The file exists", "Markdown");
            return;
        }
        
        // Add to document.json
        $newFile = [
            'file_id' => $fileId,
            'file_name' => $fileInfo['file_name'] ?? 'unnamed_file',
            'file_size' => $fileInfo['file_size'] ?? 0,
            'added_via' => 'link',
            'added_at' => date('Y-m-d H:i:s')
        ];
        
        saveDocData($scriptId, $newFile, true);
        
        // Update file count in Sell.json
        $sellFile = $GLOBALS['DATA_DIR'] . "Sell.json";
        if (file_exists($sellFile)) {
            $sellData = json_decode(file_get_contents($sellFile), true);
            if (isset($sellData[$scriptId])) {
                $currentCount = $sellData[$scriptId]['file_count'] ?? 0;
                $sellData[$scriptId]['file_count'] = $currentCount + 1;
                file_put_contents($sellFile, json_encode($sellData, JSON_PRETTY_PRINT));
            }
        }
        
        sendMessage($chatId, "✅ *File added successfully!*\n\n", "Markdown");
        sendDocument($chatId, $fileId, "📄 Preview of added file:");
        
        // Ask if add more or finish
        $keyboard = buildReplyKeyboard([
            ['📎 Add Another Link'],
            ['✅ Done - Back to Script'],
            ['❌ Cancel']
        ]);
        sendMessage($chatId, "Add another file or finish?", "Markdown", $keyboard);
        
        // Stay in same state for multiple adds
        return;
    }
    
    // Handle add another link button
    if ($text == '📎 Add Another Link' && $chatId == $ADMIN_ID) {
        $currentState = getUserData($ADMIN_ID, "state");
        if ($currentState == 'waiting_file_link') {
            sendMessage($chatId, "Send the next file link:", "Markdown");
        }
        return;
    }
    
    if ($text == '✅ Done - Back to Script' && $chatId == $ADMIN_ID) {
        saveUserData($ADMIN_ID, "state", "none");
        $scriptId = getUserData($ADMIN_ID, "admin_edit_script_id");
        
        // Show script management again
        $callback = [
            'id' => 'internal',
            'from' => ['id' => $ADMIN_ID],
            'message' => ['chat' => ['id' => $chatId], 'message_id' => null]
        ];
        $callback['data'] = "admin_manage_script_{$scriptId}";
        handleCallback($callback);
        return;
    }
        // ADMIN: Edit menu selection
    if ($state == 'admin_edit_menu' && $chatId == $ADMIN_ID) {
        if ($text == '❌ Cancel') {
            saveUserData($ADMIN_ID, "state", "none");
            $scriptId = getUserData($ADMIN_ID, "admin_edit_script_id");
            // Return to script management
            $callback = ['id' => 'internal', 'from' => ['id' => $ADMIN_ID], 'message' => ['chat' => ['id' => $chatId], 'message_id' => null], 'data' => "admin_manage_script_{$scriptId}"];
            handleCallback($callback);
            return;
        }
        
        $fieldMap = [
            '1' => ['field' => 'name', 'name' => 'Name', 'state' => 'waiting_edit_name'],
            '2' => ['field' => 'description', 'name' => 'Description', 'state' => 'waiting_edit_description'],
            '3' => ['field' => 'category', 'name' => 'Category', 'state' => 'waiting_edit_category'],
            '4' => ['field' => 'language', 'name' => 'Language', 'state' => 'waiting_edit_language'],
            '5' => ['field' => 'demo_link', 'name' => 'Demo Link', 'state' => 'waiting_edit_demo'],
            '6' => ['field' => 'photo', 'name' => 'Photo URL', 'state' => 'waiting_edit_photo']
        ];
        
        if (!isset($fieldMap[$text])) {
            sendMessage($chatId, "❌ Invalid choice. Send 1-6 or Cancel:", "Markdown");
            return;
        }
        
        $selected = $fieldMap[$text];
        saveUserData($ADMIN_ID, "admin_edit_field", $selected['field']);
        saveUserData($ADMIN_ID, "state", $selected['state']);
        
        sendMessage($chatId, "✏️ Editing *{$selected['name']}*\n\nEnter new value:", "Markdown", buildReplyKeyboard([['❌ Cancel']]));
        return;
    }

    // Generic edit handlers
    $editStates = [
        'waiting_edit_name' => 'name',
        'waiting_edit_description' => 'description',
        'waiting_edit_category' => 'category',
        'waiting_edit_language' => 'language',
        'waiting_edit_demo' => 'demo_link',
        'waiting_edit_photo' => 'photo'
    ];
    
    foreach ($editStates as $stateName => $fieldName) {
        if ($state == $stateName && $chatId == $ADMIN_ID) {
            if ($text == '❌ Cancel') {
                saveUserData($ADMIN_ID, "state", "none");
                $scriptId = getUserData($ADMIN_ID, "admin_edit_script_id");
                $callback = ['id' => 'internal', 'from' => ['id' => $ADMIN_ID], 'message' => ['chat' => ['id' => $chatId], 'message_id' => null], 'data' => "admin_manage_script_{$scriptId}"];
                handleCallback($callback);
                return;
            }
            
            $scriptId = getUserData($ADMIN_ID, "admin_edit_script_id");
            
            // Update Sell.json
            $sellFile = $GLOBALS['DATA_DIR'] . "Sell.json";
            $sellData = json_decode(file_get_contents($sellFile), true);
            
            if (isset($sellData[$scriptId])) {
                $oldValue = $sellData[$scriptId][$fieldName] ?? 'N/A';
                $sellData[$scriptId][$fieldName] = $text;
                file_put_contents($sellFile, json_encode($sellData, JSON_PRETTY_PRINT));
                
                sendMessage($chatId, "✅ *Updated!*\n\n{$fieldName}:\nOld: `{$oldValue}`\nNew: `{$text}`", "Markdown");
                
                // Return to script management
                saveUserData($ADMIN_ID, "state", "none");
                $callback = ['id' => 'internal', 'from' => ['id' => $ADMIN_ID], 'message' => ['chat' => ['id' => $chatId], 'message_id' => null], 'data' => "admin_manage_script_{$scriptId}"];
                handleCallback($callback);
            } else {
                sendMessage($chatId, "❌ Script not found", "Markdown");
                saveUserData($ADMIN_ID, "state", "none");
            }
            return;
        }
    }
    
    // ADMIN: Change price
    if ($state == 'waiting_new_price' && $chatId == $ADMIN_ID) {
        if ($text == '❌ Cancel') {
            saveUserData($ADMIN_ID, "state", "none");
            $scriptId = getUserData($ADMIN_ID, "admin_edit_script_id");
            $callback = ['id' => 'internal', 'from' => ['id' => $ADMIN_ID], 'message' => ['chat' => ['id' => $chatId], 'message_id' => null], 'data' => "admin_manage_script_{$scriptId}"];
            handleCallback($callback);
            return;
        }
        
        if (!is_numeric($text) || floatval($text) < 0) {
            sendMessage($chatId, "❌ Invalid price. Enter a number:", "Markdown");
            return;
        }
        
        $scriptId = getUserData($ADMIN_ID, "admin_edit_script_id");
        
        $sellFile = $GLOBALS['DATA_DIR'] . "Sell.json";
        $sellData = json_decode(file_get_contents($sellFile), true);
        
        if (isset($sellData[$scriptId])) {
            $oldPrice = $sellData[$scriptId]['price'] ?? '0';
            $sellData[$scriptId]['price'] = $text;
            file_put_contents($sellFile, json_encode($sellData, JSON_PRETTY_PRINT));
            
            sendMessage($chatId, "✅ *Price Updated!*\n\nOld: {$oldPrice} STAR\nNew: {$text} STAR", "Markdown");
            
            // Notify seller
            $seller = $sellData[$scriptId]['seller'] ?? '';
            if (!empty($seller)) {
                sendMessage($seller, "📢 *Your Script Price Updated*\n\n*{$sellData[$scriptId]['name']}*\nNew price: {$text} STAR\n\nChanged by admin.", "Markdown");
            }
            
            saveUserData($ADMIN_ID, "state", "none");
            $callback = ['id' => 'internal', 'from' => ['id' => $ADMIN_ID], 'message' => ['chat' => ['id' => $chatId], 'message_id' => null], 'data' => "admin_manage_script_{$scriptId}"];
            handleCallback($callback);
        }
        return;
    }
    if ($data == 'admin_confirm_delete') {
        $scriptId = getUserData($ADMIN_ID, "admin_delete_script_id");
        
        if (empty($scriptId)) {
            answerCallbackQuery($callbackId, "Error: No script to delete");
            return;
        }
        
        // Remove from Sell.json
        $sellFile = $GLOBALS['DATA_DIR'] . "Sell.json";
        if (file_exists($sellFile)) {
            $sellData = json_decode(file_get_contents($sellFile), true);
            $scriptName = $sellData[$scriptId]['name'] ?? 'Unknown';
            unset($sellData[$scriptId]);
            file_put_contents($sellFile, json_encode($sellData, JSON_PRETTY_PRINT));
            
            // Optionally remove files from document.json too
            // Or keep them for record purposes
            
            sendMessage($chatId, "✅ *Script Deleted*\n\nName: {$scriptName}\nID: {$scriptId}\n\nRemoved from store.", "Markdown");
            logDebug("Admin deleted script {$scriptId}: {$scriptName}");
        }
        
        saveUserData($ADMIN_ID, "admin_delete_script_id", null);
        answerCallbackQuery($callbackId, "Script deleted");
        
        // Return to script list
        $callback['data'] = 'admin_list_scripts_1';
        handleCallback($callback);
        return;
    }
    
    if ($data == 'admin_cancel_delete') {
        saveUserData($ADMIN_ID, "admin_delete_script_id", null);
        answerCallbackQuery($callbackId, "Deletion cancelled");
        
        // Return to script management
        $scriptId = getUserData($ADMIN_ID, "admin_delete_script_id");
        if ($scriptId) {
            $callback['data'] = "admin_manage_script_{$scriptId}";
            handleCallback($callback);
        } else {
            $callback['data'] = 'admin_scripts';
            handleCallback($callback);
        }
        return;
    }
        // ADMIN: User Management Menu
    

    // ADMIN: Find user by ID or username
    if ($data == 'admin_find_user') {
        saveUserData($ADMIN_ID, "state", "waiting_find_user");
        
        $text = "🔍 *Find User*\n\n";
        $text .= "Enter user ID, username (with @), or name:\n";
        $text .= "Examples:\n";
        $text .= "• `123456789`\n";
        $text .= "• `@username`\n";
        $text .= "• `John Doe`";
        
        sendMessage($chatId, $text, "Markdown", buildReplyKeyboard([['❌ Cancel']]));
        answerCallbackQuery($callbackId);
        return;
    }
    if ($state == 'waiting_add_balance' && $chatId == $ADMIN_ID) {
        if ($text == '❌ Cancel') {
            saveUserData($ADMIN_ID, "state", "none");
            saveUserData($ADMIN_ID, "admin_target_user", null);
            showMainMenu($chatId);
            return;
        }
        
        if (!is_numeric($text) || floatval($text) <= 0) {
            sendMessage($chatId, "❌ Enter a valid positive number:", "Markdown");
            return;
        }
        
        $targetUser = getUserData($ADMIN_ID, "admin_target_user");
        $amount = floatval($text);
        
        $currentBalance = getUserData($targetUser, "balance") ?? 0;
        $newBalance = $currentBalance + $amount;
        saveUserData($targetUser, "balance", $newBalance);
        
        // Log transaction
        $transData = [
            'type' => 'admin_add',
            'amount' => $amount,
            'admin' => $ADMIN_ID,
            'time' => date('Y-m-d H:i:s')
        ];
        historySaveData($targetUser, [time() => $transData]);
        
        // Notify user
        sendMessage($targetUser, "💰 *Balance Added!*\n\nAmount: +{$amount} STAR\nNew balance: {$newBalance} STAR\n\nBy admin", "Markdown");
        
        sendMessage($chatId, "✅ Added {$amount} STAR to user {$targetUser}\nNew balance: {$newBalance} STAR", "Markdown");
        
        saveUserData($ADMIN_ID, "state", "none");
        saveUserData($ADMIN_ID, "admin_target_user", null);
        return;
    }

    // ADMIN: Deduct balance from user
    if ($state == 'waiting_deduct_balance' && $chatId == $ADMIN_ID) {
        if ($text == '❌ Cancel') {
            saveUserData($ADMIN_ID, "state", "none");
            saveUserData($ADMIN_ID, "admin_target_user", null);
            showMainMenu($chatId);
            return;
        }
        
        if (!is_numeric($text) || floatval($text) <= 0) {
            sendMessage($chatId, "❌ Enter a valid positive number:", "Markdown");
            return;
        }
        
        $targetUser = getUserData($ADMIN_ID, "admin_target_user");
        $amount = floatval($text);
        
        $currentBalance = getUserData($targetUser, "balance") ?? 0;
        
        if ($amount > $currentBalance) {
            sendMessage($chatId, "❌ Cannot deduct more than user's balance ({$currentBalance} STAR)", "Markdown");
            return;
        }
        
        $newBalance = $currentBalance - $amount;
        saveUserData($targetUser, "balance", $newBalance);
        
        // Log transaction
        $transData = [
            'type' => 'admin_deduct',
            'amount' => -$amount,
            'admin' => $ADMIN_ID,
            'time' => date('Y-m-d H:i:s')
        ];
        historySaveData($targetUser, [time() => $transData]);
        
        // Notify user
        sendMessage($targetUser, "⚠️ *Balance Deducted*\n\nAmount: -{$amount} STAR\nNew balance: {$newBalance} STAR\n\nBy admin", "Markdown");
        
        sendMessage($chatId, "✅ Deducted {$amount} STAR from user {$targetUser}\nNew balance: {$newBalance} STAR", "Markdown");
        
        saveUserData($ADMIN_ID, "state", "none");
        saveUserData($ADMIN_ID, "admin_target_user", null);
        return;
    }

    // ADMIN: Send message to user
    if ($state == 'waiting_admin_message' && $chatId == $ADMIN_ID) {
        if ($text == '❌ Cancel') {
            saveUserData($ADMIN_ID, "state", "none");
            saveUserData($ADMIN_ID, "admin_target_user", null);
            showMainMenu($chatId);
            return;
        }
        
        $targetUser = getUserData($ADMIN_ID, "admin_target_user");
        
        sendMessage($targetUser, "📨 *Message from Admin:*\n\n{$text}\n\n_Reply to this message if you need help_", "Markdown");
        
        sendMessage($chatId, "✅ Message sent to user {$targetUser}", "Markdown");
        
        saveUserData($ADMIN_ID, "state", "none");
        saveUserData($ADMIN_ID, "admin_target_user", null);
        return;
    }

    // ADMIN: Find user
    if ($state == 'waiting_find_user' && $chatId == $ADMIN_ID) {
        if ($text == '❌ Cancel') {
            saveUserData($ADMIN_ID, "state", "none");
            showMainMenu($chatId);
            return;
        }
        
        $search = trim($text);
        $tgFile = $GLOBALS['DATA_DIR'] . "tg.txt";
        
        if (!file_exists($tgFile)) {
            sendMessage($chatId, "❌ No user database found", "Markdown");
            saveUserData($ADMIN_ID, "state", "none");
            return;
        }
        
        $content = trim(file_get_contents($tgFile));
        $allUsers = array_filter(explode(" ", $content));
        
        $matches = [];
        foreach ($allUsers as $userId) {
            $userId = trim($userId);
            if (empty($userId)) continue;
            
            // Check if matches ID, username pattern, or name
            if (strpos($userId, $search) !== false || 
                $search == $userId || 
                stripos($userId, $search) !== false) {
                $matches[] = $userId;
            }
        }
        
        if (empty($matches)) {
            sendMessage($chatId, "❌ No users found matching: `{$search}`\n\nTry again or Cancel:", "Markdown");
            return;
        }
        
        if (count($matches) == 1) {
            // Single match - show details
            $userId = $matches[0];
            $callback = [
                'id' => 'internal',
                'from' => ['id' => $ADMIN_ID],
                'message' => ['chat' => ['id' => $chatId], 'message_id' => null],
                'data' => "admin_user_detail_{$userId}"
            ];
            saveUserData($ADMIN_ID, "state", "none");
            handleCallback($callback);
        } else {
            // Multiple matches - show list
            $text = "🔍 *Found " . count($matches) . " matches:*\n\n";
            $buttons = [];
            
            foreach ($matches as $idx => $userId) {
                $balance = getUserData($userId, "balance") ?? 0;
                $text .= ($idx + 1) . ". `{$userId}` - {$balance} STAR\n";
                $buttons[] = [['text' => (string)($idx + 1), 'callback_data' => "admin_user_detail_{$userId}"]];
            }
            
            $text .= "\nClick number to view details:";
            
            // Arrange in rows of 5
            $rows = [];
            $row = [];
            foreach ($buttons as $btn) {
                $row[] = $btn[0];
                if (count($row) == 5) {
                    $rows[] = $row;
                    $row = [];
                }
            }
            if (!empty($row)) {
                $rows[] = $row;
            }
            $rows[] = [['text' => '🔙 Cancel', 'callback_data' => 'admin_users']];
            
            $keyboard = buildInlineKeyboard($rows);
            sendMessage($chatId, $text, "Markdown", $keyboard);
            saveUserData($ADMIN_ID, "state", "none");
        }
        return;
    } 
        
}



// ==================== ADMIN COMMANDS ====================

function handleAdmin($message) {
    global $ADMIN_ID;
    $chatId = $message['chat']['id'];

    if ($chatId != $ADMIN_ID) {
        showMainMenu($chatId);
        return;
    }

    $keyboard = buildInlineKeyboard([
        [['text' => '📊 Statistics', 'callback_data' => 'admin_stats']],
        [['text' => '📝 Manage Scripts', 'callback_data' => 'admin_scripts']],
        [['text' => '👥 Manage Users', 'callback_data' => 'admin_users']],
        [['text' => '🔊 Broadcast', 'callback_data' => 'broadcast']]
    ]);

    sendMessage($chatId, "🔧 *Admin Panel*\n\nSelect an option:", "Markdown", $keyboard);
}

function handleBros($message) {
    global $ADMIN_ID;
    $chatId = $message['chat']['id'];

    if ($chatId != $ADMIN_ID) return;

    $tgFile = $GLOBALS['DATA_DIR'] . "tg.txt";
    if (!file_exists($tgFile)) return;

    $ids = explode(" ", trim(file_get_contents($tgFile)));
    $number = 0;

    $text = "*ឱកាសៗ ត្រឹមតែ 5$ ទេណា*\n\n_ដេីម្បីបាន 5$ យេីងបានបន្ថែមលុយទៅកាន់កូនម្នាក់បាន 0.10 star(0.10$) ដកលុយ 1$ ភ្លាមៗ\n\nជាធម្មតាយេីងអាចរកបាន 0.05 Star ក្នុងមួយកូនក្រុម ។ តែអីលូវនេះបានផ្លាស់ប្ដូរហេីយគឺ 2x ពេាលគឺ 0.10 Star(0.10$) រកបាន 1$ អាចដកបានភ្លាមៗ\n\nព័ត៌មានបន្ថែមអាចទាក់ទងទៅ Admin @broskhuach_";

    foreach ($ids as $id) {
        if (empty($id)) continue;
        $number++;
        $token = "6466011887:AAEI0-XUEz0UhxJbSH45iWlZbDKjdoK6Hno";
        $link = "https://api.telegram.org/bot{$token}/sendMessage?chat_id={$id}&text=" . urlencode($text) . "&parse_mode=markdown";
        file_get_contents($link);
    }

    sendMessage($chatId, "Broadcast sent to {$number} users");
}

function handleList($message) {
    $chatId = $message['chat']['id'];
    $text = "*🔶 List for star poin 🔶*\n\n*1 USDT = 1 STAR\n\n1$(TRX) = 1 STAR\n\n1$(LTC) = 1 STAR\n\n1$(BNB) = 1 STAR*\n\n_For other crypto contact admin @broskhuqch_";
    sendMessage($chatId, $text, "Markdown");
}

// ==================== RESET COMMAND ====================

function handleReset($message) {
    global $ADMIN_ID, $DATA_DIR;
    $chatId = $message['chat']['id'];

    if ($chatId != $ADMIN_ID) {
        sendMessage($chatId, "❌ Unauthorized");
        return;
    }

    $keyboard = buildInlineKeyboard([
        [['text' => '⚠️ YES - RESET ALL DATA', 'callback_data' => 'reset_confirm_step1']],
        [['text' => '❌ Cancel', 'callback_data' => 'reset_cancel']]
    ]);
    
    sendMessage($chatId, "🚨 *DANGER ZONE* 🚨\n\nThis will delete:\n• All users data\n• All scripts (Sell.json)\n• All pending scripts (Names.json)\n• All documents (document.json)\n• All history\n• All referrals\n• All verification records\n\n*This cannot be undone!*\n\nClick below to proceed:", "Markdown", $keyboard);
}

function handleResetCallback($callback, $data) {
    global $ADMIN_ID, $DATA_DIR;
    $chatId = $callback['message']['chat']['id'];
    $messageId = $callback['message']['message_id'];

    if ($chatId != $ADMIN_ID) {
        answerCallbackQuery($callback['id'], "Unauthorized");
        return;
    }

    // Step 1: Final confirmation with code
    if ($data == 'reset_confirm_step1') {
        $code = rand(1000, 9999);
        saveUserData($ADMIN_ID, "reset_code", $code);
        
        $keyboard = buildInlineKeyboard([
            [['text' => "CONFIRM CODE: {$code}", 'callback_data' => 'reset_confirm_step2']],
            [['text' => '❌ Cancel', 'callback_data' => 'reset_cancel']]
        ]);
        
        editMessageText($chatId, $messageId, "🔥 *FINAL WARNING* 🔥\n\nTo confirm complete data reset, click the code below:\n\n*Code: {$code}*\n\nBot will be empty after this!", $keyboard);
        answerCallbackQuery($callback['id']);
        return;
    }

    // Step 2: Execute reset
    if ($data == 'reset_confirm_step2') {
        $filesToDelete = [
            'users.json',
            'Sell.json',
            'Names.json',
            'document.json',
            'history.json',
            'referral.json',
            'bot_data.json',
            'tg.txt',
            'verify.txt',
            'referral.txt',
            'text.txt',
            'hash.txt',
            'otps.json'
        ];

        $deleted = [];
        $errors = [];

        foreach ($filesToDelete as $file) {
            $path = $DATA_DIR . $file;
            if (file_exists($path)) {
                if (unlink($path)) {
                    $deleted[] = $file;
                } else {
                    $errors[] = $file;
                }
            }
        }

        // Recreate empty data files
        file_put_contents($DATA_DIR . "users.json", "{}");
        file_put_contents($DATA_DIR . "Sell.json", "{}");
        file_put_contents($DATA_DIR . "Names.json", "{}");
        file_put_contents($DATA_DIR . "document.json", "{}");
        file_put_contents($DATA_DIR . "history.json", "{}");
        file_put_contents($DATA_DIR . "referral.json", "{}");
        file_put_contents($DATA_DIR . "bot_data.json", "{}");
        file_put_contents($DATA_DIR . "tg.txt", "");
        file_put_contents($DATA_DIR . "verify.txt", "");
        file_put_contents($DATA_DIR . "referral.txt", "");
        file_put_contents($DATA_DIR . "text.txt", "");
        file_put_contents($DATA_DIR . "hash.txt", "");
        file_put_contents($DATA_DIR . "otps.json", "{}");

        // Clear admin state
        saveUserData($ADMIN_ID, "reset_code", null);
        saveUserData($ADMIN_ID, "state", "none");

        $report = "✅ *RESET COMPLETE*\n\n";
        $report .= "*Deleted:* " . count($deleted) . " files\n";
        if (!empty($errors)) {
            $report .= "*Failed:* " . implode(", ", $errors) . "\n";
        }
        $report .= "\n*Recreated:* Empty data files\n";
        $report .= "\nBot is now fresh. All users need to /start again.";

        editMessageText($chatId, $messageId, $report);
        answerCallbackQuery($callback['id'], "Reset complete!");
        
        logDebug("ADMIN RESET: All data reset by {$ADMIN_ID}");
        return;
    }

    // Cancel
    if ($data == 'reset_cancel') {
        saveUserData($ADMIN_ID, "reset_code", null);
        editMessageText($chatId, $messageId, "❌ Reset cancelled. No data was deleted.");
        answerCallbackQuery($callback['id'], "Cancelled");
    }
}
// Extract file_id from various Telegram link formats
function extractFileIdFromLink($link) {
    // If it's already a file_id (contains no slashes and looks like base64)
    if (!strpos($link, '/') && strlen($link) > 20) {
        return $link; // Assume it's direct file_id
    }
    
    // Pattern 1: https://t.me/c/CHANNELID/MESSAGEID (private channel)
    if (preg_match('/t\.me\/c\/(\-?\d+)\/(\d+)/', $link, $matches)) {
        $channelId = $matches[1];
        $messageId = $matches[2];
        return fetchFileIdFromMessage($channelId, $messageId);
    }
    
    // Pattern 2: https://t.me/username/MESSAGEID (public channel/group)
    if (preg_match('/t\.me\/([a-zA-Z0-9_]+)\/(\d+)/', $link, $matches)) {
        $username = $matches[1];
        $messageId = $matches[2];
        $chatId = '@' . $username;
        return fetchFileIdFromMessage($chatId, $messageId);
    }
    
    // Pattern 3: https://t.me/username/MESSAGEID?single (with query)
    if (preg_match('/t\.me\/([a-zA-Z0-9_]+)\/(\d+)\?/', $link, $matches)) {
        $username = $matches[1];
        $messageId = $matches[2];
        $chatId = '@' . $username;
        return fetchFileIdFromMessage($chatId, $messageId);
    }
    
    return null;
}

// Fetch file_id from a message in a channel
function fetchFileIdFromMessage($chatId, $messageId) {
    global $TOKEN;
    
    $url = "https://api.telegram.org/bot{$TOKEN}/forwardMessage";
    $params = [
        'chat_id' => $GLOBALS['ADMIN_ID'], // Forward to admin to extract
        'from_chat_id' => $chatId,
        'message_id' => $messageId
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($result, true);
    
    if (isset($data['result']['document']['file_id'])) {
        return $data['result']['document']['file_id'];
    }
    if (isset($data['result']['photo'])) {
        $photos = $data['result']['photo'];
        return end($photos)['file_id']; // Get largest photo
    }
    if (isset($data['result']['video']['file_id'])) {
        return $data['result']['video']['file_id'];
    }
    
    return null;
}

// Get file info from Telegram
function getFileInfo($fileId) {
    global $TOKEN;
    
    $url = "https://api.telegram.org/bot{$TOKEN}/getFile";
    $params = ['file_id' => $fileId];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($result, true);
    
    if (isset($data['result'])) {
        return [
            'file_id' => $data['result']['file_id'],
            'file_name' => $data['result']['file_path'] ?? basename($data['result']['file_path'] ?? 'unknown'),
            'file_size' => $data['result']['file_size'] ?? 0,
            'file_path' => $data['result']['file_path'] ?? null
        ];
    }
    
    return null;
}

// ==================== WEBHOOK HANDLER ====================

// Get input
$input = file_get_contents("php://input");
$update = json_decode($input, true);

logDebug("Processing update: " . json_encode($update));

// If no update received, show setup page or debug info
if (!$update) {
    logDebug("No update received - showing setup page");

    if (isset($_GET['setup'])) {
        // Set webhook
        $params = [
            'url' => $WEBHOOK_URL
        ];
        $result = apiRequest('setWebhook', $params);
        echo "<h2>Webhook Setup</h2>";
        echo "<pre>";
        print_r($result);
        echo "</pre>";

        // Also show webhook info
        $info = apiRequest('getWebhookInfo');
        echo "<h3>Current Webhook Info:</h3>";
        echo "<pre>";
        print_r($info);
        echo "</pre>";

        logDebug("Setup attempted. Result: " . json_encode($result));
    } elseif (isset($_GET['test'])) {
        // Test sending message to admin
        $test = sendMessage($ADMIN_ID, "Test message from webhook at " . date("Y-m-d H:i:s"));
        echo "<h2>Test Result</h2>";
        echo "<pre>";
        print_r($test);
        echo "</pre>";
    } elseif (isset($_GET['debug'])) {
        // Show debug info
        echo "<h2>Debug Information</h2>";
        echo "<p>Data Directory: {$DATA_DIR}</p>";
        echo "<p>Directory exists: " . (is_dir($DATA_DIR) ? "Yes" : "No") . "</p>";
        echo "<p>Directory writable: " . (is_writable($DATA_DIR) ? "Yes" : "No") . "</p>";
        echo "<p>PHP Version: " . phpversion() . "</p>";
        echo "<p>CURL Available: " . (function_exists('curl_init') ? "Yes" : "No") . "</p>";
        echo "<p>JSON Available: " . (function_exists('json_decode') ? "Yes" : "No") . "</p>";

        // List data files
        echo "<h3>Data Files:</h3>";
        if (is_dir($DATA_DIR)) {
            $files = scandir($DATA_DIR);
            echo "<ul>";
            foreach ($files as $file) {
                if ($file != '.' && $file != '..') {
                    echo "<li>{$file}</li>";
                }
            }
            echo "</ul>";
        }
    } else {
        echo "<h1>Script Marketplace Bot</h1>";
        echo "<p>Bot is running. Use the following parameters:</p>";
        echo "<ul>";
        echo "<li><a href='?setup=1'>?setup=1</a> - Set webhook</li>";
        echo "<li><a href='?test=1'>?test=1</a> - Test send message</li>";
        echo "<li><a href='?debug=1'>?debug=1</a> - Debug information</li>";
        echo "</ul>";
        echo "<p><strong>Important:</strong> Make sure to update \$WEBHOOK_URL in this file before setting up!</p>";
    }
    exit;
}

// Handle message
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = isset($message['text']) ? $message['text'] : '';
        // Check if user is banned
    $banFile = $DATA_DIR . "banned.txt";
    if (file_exists($banFile) && strpos(file_get_contents($banFile), " {$chatId}") !== false) {
        sendMessage($chatId, "🚫 *Access Denied*\n\nYour account has been suspended.\nContact @your_admin for assistance.", "Markdown");
        http_response_code(200);
        echo "OK";
        exit;
    }

    // Initialize user data FIRST for any interaction
    initUserData($chatId);

    logDebug("Message from {$chatId}: {$text}");

    // Check for admin commands FIRST (they bypass state checks)
    $isAdmin = ($chatId == $ADMIN_ID);
    $isAdminCommand = in_array($text, ['/admin', '/bros', '/list', '/reset']);

    if ($isAdmin && $isAdminCommand) {
        // Admin commands work regardless of state
        if ($text == '/admin') {
            handleAdmin($message);
        } elseif ($text == '/bros') {
            handleBros($message);
        } elseif ($text == '/list') {
            handleList($message);
        } elseif ($text == '/reset') {
            handleReset($message);
        }
        // Send OK response immediately
        http_response_code(200);
        echo "OK";
        exit;
    }

    // Check user state for regular users
    $state = getUserData($chatId, "state");
    if ($state && $state != 'none') {
        handleState($message, $state);
        // Send OK response immediately
        http_response_code(200);
        echo "OK";
        exit;
    }

    // Regular command handlers
    if (strpos($text, '/start') === 0) {
        handleStart($message);
    } elseif ($text == '✅ join') {
        handleJoined($message);
    } elseif ($text == '💾 Balance') {
        handleBalance($message);
    } elseif ($text == '📊 Status') {
        handleStatus($message);
    } elseif ($text == '🖱️ Buy script') {
        handleBuyScript($message);
    } elseif ($text == '⭐ Deposit') {
        handleDeposit($message);
    } elseif ($text == '🌟 Withdraw') {
        handleWithdraw($message);
    } elseif ($text == '🖥️ Sell script') {
        handleSellScript($message);
    } elseif ($text == '📞 Support') {
        handleSupport($message);
    } elseif ($text == '👥 Referral') {
        handleReferral($message);
    } elseif ($text == '⌨️ Code') {
        handleCode($message);
    } else {
        // Unknown command - show main menu if verified
        if (checkVerification($chatId)) {
            showMainMenu($chatId);
        } else {
            showJoinedMenu($chatId);
        }
    }
}


// Handle callback queries
if (isset($update['callback_query'])) {
    handleCallback($update['callback_query']);
}

// Send OK response to Telegram immediately
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
logDebug("Request processed successfully");
?>