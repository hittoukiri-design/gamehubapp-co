<?php
// ===============================
// YAARWIN TELEGRAM BOT
// UID FLOW + SAMPLE PHOTO + RECHARGE SUBMENU + HUMAN TEACHER HANDOFF
// File: api/telegram-support.php
// ===============================

// Private config berada di luar public_html: ../private_bot/config.php
$PRIVATE_BOT_DIR = dirname(__DIR__, 2) . "/private_bot";
$PRIVATE_CONFIG = $PRIVATE_BOT_DIR . "/config.php";

if (!is_file($PRIVATE_CONFIG)) {
    http_response_code(500);
    error_log("YaarWin bot config missing: " . $PRIVATE_CONFIG);
    exit;
}

$config = require $PRIVATE_CONFIG;
$BOT_TOKEN = $config["BOT_TOKEN"] ?? "";
$WEBHOOK_SECRET = $config["WEBHOOK_SECRET"] ?? "";

if ($BOT_TOKEN === "") {
    http_response_code(500);
    error_log("YaarWin bot token is empty.");
    exit;
}

$requestMethod = $_SERVER["REQUEST_METHOD"] ?? "GET";
if ($requestMethod === "GET" || $requestMethod === "HEAD") {
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    echo json_encode([
        "ok" => true,
        "service" => "telegram-support",
        "status" => "ready"
    ]);
    exit;
}

// Secret webhook validation. Setelah upload versi ini, webhook perlu diset ulang dengan secret_token yang sama.
if ($WEBHOOK_SECRET !== "") {
    $incomingSecret = $_SERVER["HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN"] ?? "";
    if (!hash_equals($WEBHOOK_SECRET, $incomingSecret)) {
        http_response_code(403);
        exit;
    }
}

// Optional: isi chat ID admin/group kalau nanti mau notifikasi masuk ke admin.
// Kalau belum perlu, biarkan kosong.
$ADMIN_CHAT_ID = "8655066559";

// URL sample UID kamu
$UID_SAMPLE_PHOTO = "https://yaarwinapp.co/assets/uid-sample.jpg";
$WELCOME_ANIMATION_URL = "https://yaarwinapp.co/assets/yaarwin-intro-tg.mp4";

$API_URL = "https://api.telegram.org/bot" . $BOT_TOKEN . "/";

// File data bot sekarang berada di private_bot, di luar public_html.
$STATE_FILE = $PRIVATE_BOT_DIR . "/user_state.json";
$USERS_FILE = $PRIVATE_BOT_DIR . "/users.json";
$WITHDRAWAL_STATUS_FILE = $PRIVATE_BOT_DIR . "/withdrawal_status.json";
$REGISTERED_UIDS_FILE = $PRIVATE_BOT_DIR . "/registered_uids.json";
$ABUSE_FILE = $PRIVATE_BOT_DIR . "/abuse_control.json";
$LOCKOUT_SECONDS = 300;
$MAX_FAILED_ATTEMPTS = 3;
$PROGRESSIVE_COOLDOWN_SECONDS = [300, 900, 3600, 86400];
$HARD_MUTE_AFTER_COOLDOWNS = 5;
$DAILY_WITHDRAW_CHECK_LIMIT = 5;
$DAILY_UID_FAILURE_LIMIT = 6;
$HOURLY_MESSAGE_LIMIT = 10;
$UID_REVIEW_CHAT_LIMIT = 3;
$HUMAN_AGENT_URL = "https://t.me/Official_YaarWinapp";

function readBotDataFile($file) {
    if (!file_exists($file)) {
        return [];
    }

    $content = trim(file_get_contents($file));
    if ($content === "") {
        return [];
    }

    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function writeBotDataFile($file, $data) {
    file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function loadUsers($usersFile) {
    if (!file_exists($usersFile)) {
        writeBotDataFile($usersFile, []);
    }

    $users = readBotDataFile($usersFile);

    if (!is_array($users)) {
        $users = [];
    }

    return $users;
}

function saveUsers($usersFile, $users) {
    writeBotDataFile($usersFile, $users);
}

function loadWithdrawalStatuses() {
    global $WITHDRAWAL_STATUS_FILE;

    if (!file_exists($WITHDRAWAL_STATUS_FILE)) {
        writeBotDataFile($WITHDRAWAL_STATUS_FILE, []);
    }

    $statuses = readBotDataFile($WITHDRAWAL_STATUS_FILE);
    return is_array($statuses) ? $statuses : [];
}

function loadRegisteredUids() {
    global $REGISTERED_UIDS_FILE;

    if (!file_exists($REGISTERED_UIDS_FILE)) {
        writeBotDataFile($REGISTERED_UIDS_FILE, []);
    }

    $uids = readBotDataFile($REGISTERED_UIDS_FILE);
    return is_array($uids) ? $uids : [];
}

function isUidRegistered($uid) {
    $uid = trim((string)$uid);
    $registeredUids = loadRegisteredUids();

    if (count($registeredUids) === 0) {
        return true;
    }

    if (array_keys($registeredUids) !== range(0, count($registeredUids) - 1)) {
        return !empty($registeredUids[$uid]);
    }

    return in_array($uid, array_map("strval", $registeredUids), true);
}

function normalizeOrderNumber($orderNumber) {
    return strtoupper(trim((string)$orderNumber));
}

function extractOrderNumber($text) {
    $text = trim((string)$text);

    if ($text === "") {
        return "";
    }

    if (preg_match('/\b(WD[A-Z0-9]{8,40})\b/i', $text, $matches)) {
        return normalizeOrderNumber($matches[1]);
    }

    if (preg_match('/(?:order\s*(?:number|no|id)?|orderno|orderid)\s*[:#-]?\s*([A-Z0-9]{8,45})/i', $text, $matches)) {
        return normalizeOrderNumber($matches[1]);
    }

    if (preg_match('/\b([A-Z0-9]{12,45})\b/i', $text, $matches)) {
        return normalizeOrderNumber($matches[1]);
    }

    return "";
}

function getWithdrawalStatus($orderNumber) {
    $statuses = loadWithdrawalStatuses();
    $orderKey = normalizeOrderNumber($orderNumber);

    foreach ($statuses as $key => $value) {
        if (normalizeOrderNumber($key) !== $orderKey) {
            continue;
        }

        if (is_array($value)) {
            $status = strtolower(trim($value["status"] ?? "processing"));
        } else {
            $status = strtolower(trim((string)$value));
        }

        if (in_array($status, ["completed", "complete", "done", "success", "paid", "transferred"], true)) {
            return "completed";
        }

        return "processing";
    }

    return "";
}

function formatWaitTime($seconds) {
    $seconds = max(0, (int)$seconds);
    $minutes = intdiv($seconds, 60);
    $remainingSeconds = $seconds % 60;
    $parts = [];

    if ($minutes > 0) {
        $parts[] = $minutes . " " . ($minutes === 1 ? "minute" : "minutes");
    }

    if ($remainingSeconds > 0 || $minutes === 0) {
        $parts[] = $remainingSeconds . " " . ($remainingSeconds === 1 ? "second" : "seconds");
    }

    return implode(" ", $parts);
}

function getTesterWithdrawalStatus($text) {
    $normalized = strtolower(trim((string)$text));
    $normalized = preg_replace('/\s+/', ' ', $normalized);

    if ($normalized === "tester 1") {
        return "processing";
    }

    if ($normalized === "tester 2") {
        return "completed";
    }

    return "";
}

function saveMemberData($usersFile, $chatId, $from, $uid = null) {
    $users = loadUsers($usersFile);
    $key = (string)$chatId;

    if (!isset($users[$key])) {
        $users[$key] = [
            "chat_id" => $chatId,
            "user_id" => $from["id"] ?? null,
            "username" => $from["username"] ?? null,
            "first_name" => $from["first_name"] ?? null,
            "last_name" => $from["last_name"] ?? null,
            "uid" => $uid,
            "started_at" => date("Y-m-d H:i:s"),
            "updated_at" => date("Y-m-d H:i:s")
        ];
    } else {
        $users[$key]["username"] = $from["username"] ?? null;
        $users[$key]["first_name"] = $from["first_name"] ?? null;
        $users[$key]["last_name"] = $from["last_name"] ?? null;
        $users[$key]["updated_at"] = date("Y-m-d H:i:s");

        if ($uid !== null) {
            $users[$key]["uid"] = $uid;
        }
    }

    saveUsers($usersFile, $users);
}

function apiRequest($method, $data = []) {
    global $API_URL;

    $ch = curl_init($API_URL . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("Telegram API Error: " . curl_error($ch));
    }

    curl_close($ch);

    return $result;
}


function isTelegramOk($result) {
    $decoded = json_decode($result, true);
    return is_array($decoded) && !empty($decoded["ok"]);
}

function sendAnimation($chat_id, $animation_url, $caption = "", $reply_markup = null) {
    $data = [
        "chat_id" => $chat_id,
        "animation" => $animation_url,
        "parse_mode" => "HTML"
    ];

    if ($caption !== "") {
        $data["caption"] = $caption;
    }

    if ($reply_markup) {
        $data["reply_markup"] = json_encode($reply_markup);
    }

    return apiRequest("sendAnimation", $data);
}

function sendVideo($chat_id, $video_url, $caption = "", $reply_markup = null) {
    $data = [
        "chat_id" => $chat_id,
        "video" => $video_url,
        "parse_mode" => "HTML"
    ];

    if ($caption !== "") {
        $data["caption"] = $caption;
    }

    if ($reply_markup) {
        $data["reply_markup"] = json_encode($reply_markup);
    }

    return apiRequest("sendVideo", $data);
}

function sendWelcomeAnimation($chat_id) {
    global $WELCOME_ANIMATION_URL;

    if ($WELCOME_ANIMATION_URL === "") {
        return;
    }

    $result = sendAnimation($chat_id, $WELCOME_ANIMATION_URL);

    if (!isTelegramOk($result)) {
        sendVideo($chat_id, $WELCOME_ANIMATION_URL);
    }
}

function sendMessage($chat_id, $text, $reply_markup = null) {
    $data = [
        "chat_id" => $chat_id,
        "text" => $text,
        "parse_mode" => "HTML"
    ];

    if ($reply_markup) {
        $data["reply_markup"] = json_encode($reply_markup);
    }

    return apiRequest("sendMessage", $data);
}

function sendPhoto($chat_id, $photo_url, $caption = "", $reply_markup = null) {
    $data = [
        "chat_id" => $chat_id,
        "photo" => $photo_url,
        "parse_mode" => "HTML"
    ];

    if ($caption !== "") {
        $data["caption"] = $caption;
    }

    if ($reply_markup) {
        $data["reply_markup"] = json_encode($reply_markup);
    }

    return apiRequest("sendPhoto", $data);
}

function answerCallbackQuery($callback_query_id, $text = "") {
    $data = [
        "callback_query_id" => $callback_query_id
    ];

    if ($text !== "") {
        $data["text"] = $text;
        $data["show_alert"] = false;
    }

    return apiRequest("answerCallbackQuery", $data);
}

function loadStates() {
    global $STATE_FILE;

    if (!file_exists($STATE_FILE)) {
        return [];
    }

    return readBotDataFile($STATE_FILE);
}

function saveStates($states) {
    global $STATE_FILE;

    writeBotDataFile($STATE_FILE, $states);
}

function setUserState($chat_id, $state, $extra = []) {
    $states = loadStates();

    $oldState = $states[$chat_id] ?? [];

    $states[$chat_id] = array_merge($oldState, [
        "state" => $state,
        "updated_at" => date("Y-m-d H:i:s")
    ], $extra);

    saveStates($states);
}

function getUserState($chat_id) {
    $states = loadStates();

    return $states[$chat_id] ?? [
        "state" => "none"
    ];
}

function clearUserState($chat_id) {
    $states = loadStates();

    if (isset($states[$chat_id])) {
        unset($states[$chat_id]);
        saveStates($states);
    }
}

function loadAbuseData() {
    global $ABUSE_FILE;

    if (!file_exists($ABUSE_FILE)) {
        writeBotDataFile($ABUSE_FILE, [
            "users" => [],
            "uids" => []
        ]);
    }

    $data = readBotDataFile($ABUSE_FILE);

    if (!isset($data["users"]) || !is_array($data["users"])) {
        $data["users"] = [];
    }

    if (!isset($data["uids"]) || !is_array($data["uids"])) {
        $data["uids"] = [];
    }

    return $data;
}

function saveAbuseData($data) {
    global $ABUSE_FILE;

    writeBotDataFile($ABUSE_FILE, $data);
}

function getDefaultAbuseUserRecord() {
    return [
        "cooldown_level" => 0,
        "cooldown_until" => 0,
        "hard_muted" => false,
        "daily" => [
            "date" => date("Y-m-d"),
            "withdraw_checks" => 0,
            "uid_failures" => 0
        ],
        "hourly" => [
            "hour" => date("Y-m-d H"),
            "messages" => 0
        ],
        "last_reason" => "",
        "updated_at" => date("Y-m-d H:i:s")
    ];
}

function getAbuseUserRecord($chat_id, &$data) {
    $key = (string)$chat_id;

    if (!isset($data["users"][$key]) || !is_array($data["users"][$key])) {
        $data["users"][$key] = getDefaultAbuseUserRecord();
    }

    $record = array_merge(getDefaultAbuseUserRecord(), $data["users"][$key]);

    if (($record["daily"]["date"] ?? "") !== date("Y-m-d")) {
        $record["daily"] = [
            "date" => date("Y-m-d"),
            "withdraw_checks" => 0,
            "uid_failures" => 0
        ];
    }

    if (($record["hourly"]["hour"] ?? "") !== date("Y-m-d H")) {
        $record["hourly"] = [
            "hour" => date("Y-m-d H"),
            "messages" => 0
        ];
    }

    $data["users"][$key] = $record;
    return $record;
}

function isHardMuted($chat_id) {
    $data = loadAbuseData();
    $record = getAbuseUserRecord($chat_id, $data);

    saveAbuseData($data);
    return !empty($record["hard_muted"]);
}

function showRestrictedAccessMessage($chat_id) {
    sendMessage(
        $chat_id,
        "Your access is temporarily restricted. Please contact support."
    );
}

function notifyPossibleSpam($chat_id, $username, $first_name, $uid, $reason, $count) {
    notifyAdmin(
        "⚠️ Possible spam detected\n\n" .
        "User: @" . ($username ?: "no_username") . "\n" .
        "Name: " . $first_name . "\n" .
        "Chat ID: " . $chat_id . "\n" .
        "UID: " . ($uid !== "" ? $uid : "Not provided") . "\n" .
        "Reason: " . $reason . "\n" .
        "Count: " . $count
    );
}

function activateSafetyCooldown($chat_id, $reason, $username, $first_name, $uid = "") {
    global $PROGRESSIVE_COOLDOWN_SECONDS, $HARD_MUTE_AFTER_COOLDOWNS;

    $data = loadAbuseData();
    $record = getAbuseUserRecord($chat_id, $data);
    $record["cooldown_level"] = (int)($record["cooldown_level"] ?? 0) + 1;
    $record["last_reason"] = $reason;
    $record["updated_at"] = date("Y-m-d H:i:s");

    if ($record["cooldown_level"] >= $HARD_MUTE_AFTER_COOLDOWNS) {
        $record["hard_muted"] = true;
        $record["cooldown_until"] = time() + 86400;
    } else {
        $durationIndex = min($record["cooldown_level"] - 1, count($PROGRESSIVE_COOLDOWN_SECONDS) - 1);
        $duration = $PROGRESSIVE_COOLDOWN_SECONDS[$durationIndex];
        $record["cooldown_until"] = time() + $duration;
    }

    $data["users"][(string)$chat_id] = $record;
    saveAbuseData($data);

    setUserState($chat_id, "locked", [
        "lock_reason" => $reason,
        "locked_until" => $record["cooldown_until"],
        "username" => $username,
        "first_name" => $first_name
    ]);

    notifyPossibleSpam($chat_id, $username, $first_name, $uid, $reason, $record["cooldown_level"]);
    return $record;
}

function trackIncomingMessageLimit($chat_id, $username, $first_name) {
    global $HOURLY_MESSAGE_LIMIT;

    $data = loadAbuseData();
    $record = getAbuseUserRecord($chat_id, $data);
    $record["hourly"]["messages"] = (int)($record["hourly"]["messages"] ?? 0) + 1;
    $data["users"][(string)$chat_id] = $record;
    saveAbuseData($data);

    if ($record["hourly"]["messages"] > $HOURLY_MESSAGE_LIMIT) {
        activateSafetyCooldown($chat_id, "Hourly message limit exceeded", $username, $first_name);
        return false;
    }

    return true;
}

function trackUidFailureLimit($chat_id, $username, $first_name, $uid = "") {
    global $DAILY_UID_FAILURE_LIMIT;

    $data = loadAbuseData();
    $record = getAbuseUserRecord($chat_id, $data);
    $record["daily"]["uid_failures"] = (int)($record["daily"]["uid_failures"] ?? 0) + 1;
    $data["users"][(string)$chat_id] = $record;
    saveAbuseData($data);

    if ($record["daily"]["uid_failures"] > $DAILY_UID_FAILURE_LIMIT) {
        activateSafetyCooldown($chat_id, "Daily UID failure limit exceeded", $username, $first_name, $uid);
        return false;
    }

    return true;
}

function trackWithdrawCheckLimit($chat_id, $uid, $username, $first_name) {
    global $DAILY_WITHDRAW_CHECK_LIMIT;

    $data = loadAbuseData();
    $record = getAbuseUserRecord($chat_id, $data);
    $record["daily"]["withdraw_checks"] = (int)($record["daily"]["withdraw_checks"] ?? 0) + 1;
    $data["users"][(string)$chat_id] = $record;
    saveAbuseData($data);

    if ($record["daily"]["withdraw_checks"] > $DAILY_WITHDRAW_CHECK_LIMIT) {
        activateSafetyCooldown($chat_id, "Daily withdrawal check limit exceeded", $username, $first_name, $uid);
        return false;
    }

    return true;
}

function registerUidUsage($uid, $chat_id, $username, $first_name) {
    global $UID_REVIEW_CHAT_LIMIT;

    $uid = trim((string)$uid);

    if ($uid === "") {
        return false;
    }

    $data = loadAbuseData();

    if (!isset($data["uids"][$uid]) || !is_array($data["uids"][$uid])) {
        $data["uids"][$uid] = [
            "chat_ids" => [],
            "review_mode" => false,
            "last_alert_at" => 0,
            "updated_at" => date("Y-m-d H:i:s")
        ];
    }

    $chatIds = $data["uids"][$uid]["chat_ids"] ?? [];
    $chatIds[] = (string)$chat_id;
    $chatIds = array_values(array_unique($chatIds));
    $data["uids"][$uid]["chat_ids"] = $chatIds;
    $data["uids"][$uid]["updated_at"] = date("Y-m-d H:i:s");

    if (count($chatIds) >= $UID_REVIEW_CHAT_LIMIT) {
        $data["uids"][$uid]["review_mode"] = true;

        if (time() - (int)($data["uids"][$uid]["last_alert_at"] ?? 0) > 3600) {
            notifyPossibleSpam($chat_id, $username, $first_name, $uid, "Same UID used by multiple Telegram users", count($chatIds));
            $data["uids"][$uid]["last_alert_at"] = time();
        }
    }

    saveAbuseData($data);
    return !empty($data["uids"][$uid]["review_mode"]);
}

function showUidReviewModeMessage($chat_id) {
    global $HUMAN_AGENT_URL;

    sendMessage(
        $chat_id,
        "This UID requires a manual review.\n\nWe will connect you with a human teacher. Please wait a moment.",
        [
            "inline_keyboard" => [
                [
                    [
                        "text" => "Connect me",
                        "url" => $HUMAN_AGENT_URL
                    ]
                ]
            ]
        ]
    );
}

function getDisplayName($username, $first_name) {
    if ($username !== "") {
        return "@" . htmlspecialchars($username);
    }

    return htmlspecialchars($first_name ?: "there");
}

function showWelcome($chat_id, $username, $first_name) {
    sendWelcomeAnimation($chat_id);

    $displayName = getDisplayName($username, $first_name);

    $text = "👋 Hello, " . $displayName . " welcome to <b>YaarWin</b>! 🎉\n\n";
    $text .= "Please choose one option below so we can guide you faster.";

    sendMessage($chat_id, $text, [
        "inline_keyboard" => [
            [
                [
                    "text" => "Member",
                    "callback_data" => "member_selected"
                ],
                [
                    "text" => "Non-member",
                    "callback_data" => "non_member_selected"
                ]
            ]
        ]
    ]);
}

function showMemberUidPrompt($chat_id, $username, $first_name) {
    global $UID_SAMPLE_PHOTO;

    setUserState($chat_id, "waiting_uid", [
        "username" => $username,
        "first_name" => $first_name,
        "uid_failed_attempts" => 0
    ]);

    $text = "🆔 Please send me your <b>UID</b>, so we can process your request faster!\n\n";
    $text .= "<i>(See the sample photos down below to find your UID number)</i>";

    sendMessage($chat_id, $text);

    sendPhoto(
        $chat_id,
        $UID_SAMPLE_PHOTO,
        "📌 <b>Sample photo:</b> You can find your UID number in the Account page."
    );
}

function showNonMemberTeacherPrompt($chat_id) {
    sendMessage(
        $chat_id,
        "Are you interested in becoming a YaarWin agent and building a successful future with us?",
        [
            "inline_keyboard" => [
                [
                    [
                        "text" => "Yes",
                        "callback_data" => "potential_agent_yes"
                    ]
                ]
            ]
        ]
    );
}

function handlePotentialAgentInterest($chat_id, $username, $first_name) {
    $displayName = getDisplayName($username, $first_name);
    $adminName = $username !== "" ? "@" . $username : "Telegram ID " . $chat_id;

    sendMessage(
        $chat_id,
        "Thank you, " . $displayName . ". We have received your Telegram ID. Our teacher will contact you with more information."
    );

    notifyAdmin($adminName . " (potential agent)");
}

function showProblemMenu($chat_id, $uid, $withQuit = false) {
    global $HUMAN_AGENT_URL;

    $text = "✅ UID received: <b>" . htmlspecialchars($uid) . "</b>\n\n";
    $text .= "<b>What's your problem?</b>";

    $buttons = [
        [
            [
                "text" => "Withdraw",
                "callback_data" => "problem_withdraw"
            ],
            [
                "text" => "Recharge",
                "callback_data" => "problem_recharge"
            ],
            [
                "text" => "Salary",
                "callback_data" => "problem_salary"
            ]
        ]
    ];

    // Human teacher button appears after user returns from submenu / back main menu.
    if ($withQuit) {
        $buttons[] = [
            [
                "text" => "Chat with human teacher",
                "url" => $HUMAN_AGENT_URL
            ]
        ];
    }

    $keyboard = [
        "inline_keyboard" => $buttons
    ];

    sendMessage($chat_id, $text, $keyboard);
}

function showRechargeMenu($chat_id) {
    $text = "💳 <b>Problem with your recharge</b>\n\n";
    $text .= "Please choose your recharge problem:";

    $keyboard = [
        "inline_keyboard" => [
            [
                [
                    "text" => "Recharge Pending",
                    "callback_data" => "recharge_pending"
                ]
            ],
            [
                [
                    "text" => "Recharge Failed",
                    "callback_data" => "recharge_failed"
                ]
            ],
            [
                [
                    "text" => "⬅️ Back to Main Menu",
                    "callback_data" => "back_main_menu"
                ]
            ]
        ]
    ];

    sendMessage($chat_id, $text, $keyboard);
}

function showRechargeHumanTeacherPrompt($chat_id) {
    global $HUMAN_AGENT_URL;

    sendMessage(
        $chat_id,
        "💳 <b>Recharge support</b>\n\n" .
        "Please prepare a screenshot of your transfer receipt.\n\n" .
        "We will connect you with a human teacher. Please wait a moment.",
        [
            "inline_keyboard" => [
                [
                    [
                        "text" => "Connect me",
                        "url" => $HUMAN_AGENT_URL
                    ]
                ]
            ]
        ]
    );
}

function showHumanAgentMessage($chat_id, $username, $first_name) {
    global $HUMAN_AGENT_URL;

    $displayName = getDisplayName($username, $first_name);

    $text = $displayName . ", please chat with our human teacher for further assistance.";

    $keyboard = [
        "inline_keyboard" => [
            [
                [
                    "text" => "Chat with human teacher",
                    "url" => $HUMAN_AGENT_URL
                ]
            ]
        ]
    ];

    sendMessage($chat_id, $text, $keyboard);
}

function showHumanAgentMenu($chat_id, $text) {
    global $HUMAN_AGENT_URL;

    $keyboard = [
        "inline_keyboard" => [
            [
                [
                    "text" => "Chat with human teacher",
                    "url" => $HUMAN_AGENT_URL
                ]
            ]
        ]
    ];

    sendMessage($chat_id, $text, $keyboard);
}

function getActiveLockout($chat_id) {
    $state = getUserState($chat_id);
    $lockedUntil = (int)($state["locked_until"] ?? 0);

    if ($lockedUntil <= 0) {
        return null;
    }

    $remaining = $lockedUntil - time();

    if ($remaining <= 0) {
        clearUserState($chat_id);
        return null;
    }

    $state["remaining_seconds"] = $remaining;
    return $state;
}

function showLockoutMessage($chat_id, $username, $first_name, $intro = "") {
    $lockout = getActiveLockout($chat_id);

    if (!$lockout) {
        return false;
    }

    $displayName = getDisplayName($username ?: ($lockout["username"] ?? ""), $first_name ?: ($lockout["first_name"] ?? "there"));
    $waitTime = formatWaitTime($lockout["remaining_seconds"]);

    if ($intro === "") {
        $intro = $displayName . ", please try again in " . $waitTime . ".";
    } else {
        $intro .= "\n\nPlease try again in " . $waitTime . ".";
    }

    sendMessage($chat_id, $intro);
    return true;
}

function lockUser($chat_id, $reason, $username, $first_name, $uid = "") {
    activateSafetyCooldown($chat_id, $reason, $username, $first_name, $uid);
}

function handleFailedUidAttempt($chat_id, $username, $first_name, $attemptedUid = "") {
    global $MAX_FAILED_ATTEMPTS;

    if (!trackUidFailureLimit($chat_id, $username, $first_name, $attemptedUid)) {
        showLockoutMessage($chat_id, $username, $first_name);
        return;
    }

    $state = getUserState($chat_id);
    $attempts = (int)($state["uid_failed_attempts"] ?? 0) + 1;

    if ($attempts >= $MAX_FAILED_ATTEMPTS) {
        lockUser($chat_id, "UID failed 3 times", $username, $first_name, $attemptedUid);
        $displayName = getDisplayName($username, $first_name);
        showLockoutMessage(
            $chat_id,
            $username,
            $first_name,
            $displayName . ", you have entered an incorrect UID number 3 times."
        );
        return;
    }

    setUserState($chat_id, "waiting_uid", [
        "username" => $username,
        "first_name" => $first_name,
        "uid_failed_attempts" => $attempts
    ]);

    sendMessage(
        $chat_id,
        "❌ The UID number you entered is incorrect. Please enter your correct UID number again."
    );
}

function handleFailedOrderAttempt($chat_id, $uid, $username, $first_name) {
    global $MAX_FAILED_ATTEMPTS;

    $state = getUserState($chat_id);
    $attempts = (int)($state["order_failed_attempts"] ?? 0) + 1;

    if ($attempts >= $MAX_FAILED_ATTEMPTS) {
        lockUser($chat_id, "Order number failed 3 times", $username, $first_name, $uid);
        $displayName = getDisplayName($username, $first_name);
        showLockoutMessage(
            $chat_id,
            $username,
            $first_name,
            $displayName . ", you have entered an incorrect order number 3 times."
        );
        return;
    }

    setUserState($chat_id, "waiting_withdraw_evidence", [
        "uid" => $uid,
        "problem" => "Withdraw",
        "username" => $username,
        "first_name" => $first_name,
        "withdraw_screenshot_received" => true,
        "withdraw_order_number" => "",
        "order_failed_attempts" => $attempts
    ]);

    sendMessage(
        $chat_id,
        "❌ The order number you entered is incorrect. Please copy the order number directly from your account's withdrawal history."
    );
}

function showWithdrawInstructions($chat_id) {
    $text = "✅ You selected <b>Withdraw</b>.\n\n";
    $text .= "Please send your <b>withdrawal history screenshot</b> and enter your <b>Order number</b>.\n\n";
    $text .= "You can send the screenshot with the order number in the caption, or send the screenshot first and then type the order number.\n\n";
    $text .= "Example:\n<b>WD2026042213325277855142a</b>";

    sendMessage($chat_id, $text);
}

function sendWithdrawalStatusResult($chat_id, $uid, $orderNumber, $status, $username, $first_name) {
    $orderNumber = normalizeOrderNumber($orderNumber);

    sendMessage($chat_id, "⏳ Please wait while we check your withdrawal status.");

    if ($status === "completed") {
        $text = "✅ <b>Your withdrawal has been completed.</b>\n\n";
        $text .= "Congratulations on your win.\n\n";
    } else {
        $text = "⏳ <b>Your withdrawal is currently being processed.</b>\n\n";
        $text .= "Please wait and check your bank account regularly.\n\n";
    }

    $text .= "<b>UID:</b> " . htmlspecialchars($uid) . "\n";
    $text .= "<b>Order number:</b> " . htmlspecialchars($orderNumber);

    showHumanAgentMenu($chat_id, $text);

    notifyAdmin(
        "📩 YaarWin Withdrawal Check\n\n" .
        "User: @" . ($username ?: "no_username") . "\n" .
        "Name: " . $first_name . "\n" .
        "UID: " . $uid . "\n" .
        "Order number: " . $orderNumber . "\n" .
        "Status returned: " . $status
    );
}

function completeWithdrawalCheck($chat_id, $uid, $orderNumber, $username, $first_name) {
    $orderNumber = normalizeOrderNumber($orderNumber);

    if (!trackWithdrawCheckLimit($chat_id, $uid, $username, $first_name)) {
        showLockoutMessage($chat_id, $username, $first_name);
        return;
    }

    $status = getWithdrawalStatus($orderNumber);

    if ($status === "") {
        handleFailedOrderAttempt($chat_id, $uid, $username, $first_name);
        return;
    }

    sendWithdrawalStatusResult($chat_id, $uid, $orderNumber, $status, $username, $first_name);
}

function notifyAdmin($message) {
    global $ADMIN_CHAT_ID;

    if ($ADMIN_CHAT_ID !== "") {
        sendMessage($ADMIN_CHAT_ID, $message);
    }
}

// Ambil update dari Telegram
$update = json_decode(file_get_contents("php://input"), true);

if (!$update) {
    exit;
}

// ===============================
// HANDLE MESSAGE
// ===============================
if (isset($update["message"])) {
    $message = $update["message"];

    $chat_id = $message["chat"]["id"];
    $text = trim($message["text"] ?? "");
    $caption = trim($message["caption"] ?? "");
    $hasPhoto = isset($message["photo"]) && is_array($message["photo"]) && count($message["photo"]) > 0;
    $hasDocument = isset($message["document"]);

    $first_name = $message["from"]["first_name"] ?? "there";
    $username = $message["from"]["username"] ?? "";

    if (isHardMuted($chat_id)) {
        showRestrictedAccessMessage($chat_id);
        exit;
    }

    if (getActiveLockout($chat_id)) {
        showLockoutMessage($chat_id, $username, $first_name);
        exit;
    }

    if (!trackIncomingMessageLimit($chat_id, $username, $first_name)) {
        showLockoutMessage($chat_id, $username, $first_name);
        exit;
    }

    if ($text === "/start") {
        clearUserState($chat_id);
        showWelcome($chat_id, $username, $first_name);
        exit;
    }

    if (preg_match('/^\/(?:myid|id)(?:@\w+)?$/i', $text)) {
        $displayName = getDisplayName($username, $first_name);
        sendMessage(
            $chat_id,
            "Hello, " . $displayName . ".\n\nYour Telegram chat ID is:\n<code>" . $chat_id . "</code>"
        );
        exit;
    }

    $userState = getUserState($chat_id);

    if ($userState["state"] === "waiting_withdraw_evidence") {
        $uid = $userState["uid"] ?? "Not provided";
        $combinedText = trim($text . "\n" . $caption);
        $testerStatus = getTesterWithdrawalStatus($combinedText);
        $orderNumber = extractOrderNumber($combinedText);
        $screenshotReceived = !empty($userState["withdraw_screenshot_received"]) || $hasPhoto || $hasDocument;
        $storedOrderNumber = $userState["withdraw_order_number"] ?? "";

        if ($orderNumber === "" && $storedOrderNumber !== "") {
            $orderNumber = $storedOrderNumber;
        }

        if (($hasPhoto || $hasDocument) || $orderNumber !== "") {
            setUserState($chat_id, "waiting_withdraw_evidence", [
                "uid" => $uid,
                "problem" => "Withdraw",
                "username" => $username,
                "first_name" => $first_name,
                "withdraw_screenshot_received" => $screenshotReceived,
                "withdraw_order_number" => $orderNumber
            ]);
        }

        if (!$screenshotReceived) {
            sendMessage(
                $chat_id,
                "Please send your <b>withdrawal history screenshot</b> first, then input your Order number."
            );
            exit;
        }

        if ($testerStatus !== "") {
            sendWithdrawalStatusResult($chat_id, $uid, "TESTER", $testerStatus, $username, $first_name);
            exit;
        }

        if ($orderNumber === "") {
            sendMessage(
                $chat_id,
                "Screenshot received. Please type your <b>Order number</b> now.\n\nExample:\n<b>WD2026042213325277855142a</b>\n\nTester mode:\n<b>tester 1</b> = processing\n<b>tester 2</b> = completed"
            );
            exit;
        }

        completeWithdrawalCheck($chat_id, $uid, $orderNumber, $username, $first_name);
        exit;
    }

    // Kalau user sedang diminta input UID
    if ($userState["state"] === "waiting_uid") {
        // Format UID: angka saja, tepat 8 digit
        if (preg_match('/^[0-9]{8}$/', $text) && isUidRegistered($text)) {
            $uid = $text;
            $requiresReview = registerUidUsage($uid, $chat_id, $username, $first_name);

            setUserState($chat_id, "uid_received", [
                "uid" => $uid,
                "username" => $username,
                "first_name" => $first_name
            ]);

            if ($requiresReview) {
                showUidReviewModeMessage($chat_id);
                exit;
            }

            // Pertama kali setelah UID, belum ada tombol human teacher.
            showProblemMenu($chat_id, $uid, false);
            exit;
        } else {
            handleFailedUidAttempt($chat_id, $username, $first_name, $text);
            exit;
        }
    }

    // Kalau user sudah pilih problem/detail, pesan berikutnya dianggap detail masalah
    if (
        $userState["state"] === "problem_selected" ||
        $userState["state"] === "recharge_pending_selected" ||
        $userState["state"] === "recharge_failed_selected"
    ) {
        $uid = $userState["uid"] ?? "Not provided";
        $problem = $userState["problem"] ?? "Not selected";

        sendMessage(
            $chat_id,
            "✅ Thank you. Your request has been received.\n\n" .
            "<b>UID:</b> " . htmlspecialchars($uid) . "\n" .
            "<b>Problem:</b> " . htmlspecialchars($problem) . "\n\n" .
            "Our admin will check your request soon."
        );

        notifyAdmin(
            "📩 New YaarWin Message\n\n" .
            "User: @" . ($username ?: "no_username") . "\n" .
            "Name: " . $first_name . "\n" .
            "UID: " . $uid . "\n" .
            "Problem: " . $problem . "\n\n" .
            "Message:\n" . $text
        );

        exit;
    }

    // Default kalau user kirim pesan biasa sebelum input UID
    sendMessage(
        $chat_id,
        "Please choose <b>Member</b> first, then send your UID number.",
        [
            "inline_keyboard" => [
                [
                    [
                        "text" => "🆔 Enter your UID",
                        "callback_data" => "enter_uid"
                    ]
                ]
            ]
        ]
    );

    exit;
}

// ===============================
// HANDLE BUTTON / CALLBACK
// ===============================
if (isset($update["callback_query"])) {
    $callback = $update["callback_query"];

    $callback_query_id = $callback["id"];
    $chat_id = $callback["message"]["chat"]["id"];
    $data = $callback["data"];

    $first_name = $callback["from"]["first_name"] ?? "there";
    $username = $callback["from"]["username"] ?? "";

    answerCallbackQuery($callback_query_id);

    if (isHardMuted($chat_id)) {
        showRestrictedAccessMessage($chat_id);
        exit;
    }

    if ($data === "quit_bot") {
        if (!getActiveLockout($chat_id)) {
            clearUserState($chat_id);
        }

        showHumanAgentMessage($chat_id, $username, $first_name);
        exit;
    }

    if (getActiveLockout($chat_id)) {
        showLockoutMessage($chat_id, $username, $first_name);
        exit;
    }

    // Legacy Start button handler from older bot messages.
    if ($data === "restart_bot") {
        clearUserState($chat_id);
        showWelcome($chat_id, $username, $first_name);
        exit;
    }

    if ($data === "member_selected") {
        showMemberUidPrompt($chat_id, $username, $first_name);
        exit;
    }

    if ($data === "non_member_selected") {
        clearUserState($chat_id);
        showNonMemberTeacherPrompt($chat_id);
        exit;
    }

    if ($data === "potential_agent_yes") {
        clearUserState($chat_id);
        handlePotentialAgentInterest($chat_id, $username, $first_name);
        exit;
    }

    if ($data === "enter_uid") {
        showMemberUidPrompt($chat_id, $username, $first_name);
        exit;
    }

    $userState = getUserState($chat_id);
    $uid = $userState["uid"] ?? "";

    // Kalau user klik menu problem tapi belum input UID
    if ($uid === "") {
        sendMessage(
            $chat_id,
            "Please choose <b>Member</b> first, then send your UID number.",
            [
                "inline_keyboard" => [
                    [
                        [
                            "text" => "Member",
                            "callback_data" => "member_selected"
                        ],
                        [
                            "text" => "Non-member",
                            "callback_data" => "non_member_selected"
                        ]
                    ]
                ]
            ]
        );
        exit;
    }

    // Balik ke menu utama: What's your problem + tombol human teacher
    if ($data === "back_main_menu") {
        setUserState($chat_id, "uid_received", [
            "uid" => $uid,
            "username" => $username,
            "first_name" => $first_name
        ]);

        showProblemMenu($chat_id, $uid, true);
        exit;
    }

    // Main menu: Withdraw
    if ($data === "problem_withdraw") {
        setUserState($chat_id, "waiting_withdraw_evidence", [
            "uid" => $uid,
            "problem" => "Withdraw",
            "username" => $username,
            "first_name" => $first_name,
            "withdraw_screenshot_received" => false,
            "withdraw_order_number" => ""
        ]);

        showWithdrawInstructions($chat_id);

        notifyAdmin(
            "📩 New YaarWin Request\n\n" .
            "User: @" . ($username ?: "no_username") . "\n" .
            "Name: " . $first_name . "\n" .
            "UID: " . $uid . "\n" .
            "Problem: Withdraw"
        );

        exit;
    }

    // Main menu: Recharge, masuk submenu
    if ($data === "problem_recharge") {
        setUserState($chat_id, "recharge_menu", [
            "uid" => $uid,
            "problem" => "Recharge",
            "username" => $username,
            "first_name" => $first_name
        ]);

        showRechargeMenu($chat_id);
        exit;
    }

    // Main menu: Salary
    if ($data === "problem_salary") {
        setUserState($chat_id, "uid_received", [
            "uid" => $uid,
            "problem" => "Salary",
            "username" => $username,
            "first_name" => $first_name
        ]);

        sendMessage(
            $chat_id,
            "💼 <b>Salary support</b>\n\n" .
            "We will connect you with a human teacher. Please wait a moment.",
            [
                "inline_keyboard" => [
                    [
                        [
                            "text" => "Connect me",
                            "url" => $HUMAN_AGENT_URL
                        ]
                    ]
                ]
            ]
        );

        notifyAdmin(
            "📩 New YaarWin Request\n\n" .
            "User: @" . ($username ?: "no_username") . "\n" .
            "Name: " . $first_name . "\n" .
            "UID: " . $uid . "\n" .
            "Problem: Salary\n" .
            "Action: Directed to human teacher"
        );

        exit;
    }

    // Recharge submenu: Pending
    if ($data === "recharge_pending") {
        setUserState($chat_id, "uid_received", [
            "uid" => $uid,
            "problem" => "Recharge Pending",
            "username" => $username,
            "first_name" => $first_name
        ]);

        showRechargeHumanTeacherPrompt($chat_id);

        notifyAdmin(
            "📩 New YaarWin Request\n\n" .
            "User: @" . ($username ?: "no_username") . "\n" .
            "Name: " . $first_name . "\n" .
            "UID: " . $uid . "\n" .
            "Problem: Recharge Pending\n" .
            "Action: Directed to human teacher"
        );

        exit;
    }

    // Recharge submenu: Failed
    if ($data === "recharge_failed") {
        setUserState($chat_id, "uid_received", [
            "uid" => $uid,
            "problem" => "Recharge Failed",
            "username" => $username,
            "first_name" => $first_name
        ]);

        showRechargeHumanTeacherPrompt($chat_id);

        notifyAdmin(
            "📩 New YaarWin Request\n\n" .
            "User: @" . ($username ?: "no_username") . "\n" .
            "Name: " . $first_name . "\n" .
            "UID: " . $uid . "\n" .
            "Problem: Recharge Failed\n" .
            "Action: Directed to human teacher"
        );

        exit;
    }

    exit;
}
?>
