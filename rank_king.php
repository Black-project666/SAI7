<?php
// rank_king.php - updated: clearer client response when backend returns 200
header('Content-Type: application/json; charset=utf-8');

// --- Config ---
const FIREBASE_API_KEY = 'AIzaSyBW1ZbMiUeDZHYUO2bY8Bfnf5rRgrQGPTM';
const FIREBASE_LOGIN_URL = 'https://www.googleapis.com/identitytoolkit/v3/relyingparty/verifyPassword?key=' . FIREBASE_API_KEY;
const RANK_URL = 'https://us-central1-cp-multiplayer.cloudfunctions.net/SetUserRating6';

// Telegram config (prefer env)
$TELEGRAM_BOT_TOKEN = getenv('TELEGRAM_BOT_TOKEN') ?: '8353360576:AAGKrMzlLeILm4PqcSla7L4P4IICoNNss1M';
$TELEGRAM_CHAT_ID  = getenv('TELEGRAM_CHAT_ID') ?: '6316935371';

$ALL_KEYS = [
  "cars","car_fix","car_collided","car_exchange","car_trade","car_wash",
  "slicer_cut","drift_max","drift","cargo","delivery","taxi","levels","gifts",
  "fuel","offroad","speed_banner","reactions","police","run","real_estate",
  "t_distance","treasure","block_post","push_ups","burnt_tire","passanger_distance","race_win"
];

// helpers
function read_input() {
    $data = [];
    if (!empty($_POST)) {
        $data = $_POST;
    } else {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) $data = $json;
    }
    return $data;
}

function respond($ok, $payload = [], $http_code = 200) {
    http_response_code($http_code);
    if ($ok) {
        echo json_encode(array_merge(['ok' => true], $payload));
    } else {
        echo json_encode(array_merge(['ok' => false], is_array($payload) ? $payload : ['error' => $payload]));
    }
    exit;
}

function firebase_auth($email, $password) {
    $payload = json_encode(['email'=>$email,'password'=>$password,'returnSecureToken'=>true]);
    $ch = curl_init(FIREBASE_LOGIN_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json','User-Agent: Dalvik/2.1.0 (Linux; U; Android 12)']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) return false;
    return json_decode($res, true);
}

function call_set_rating($idToken, $rating_data) {
    $payload = json_encode(['data' => json_encode(['RatingData' => $rating_data])]);
    $ch = curl_init(RANK_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $idToken,
        'Content-Type: application/json',
        'User-Agent: okhttp/3.12.13'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http'=>$http,'body'=>$res];
}

function mask_email($email) {
    $parts = explode('@',$email);
    if (count($parts)!==2) return 'unknown';
    $local = $parts[0]; $domain = $parts[1];
    if (strlen($local) <= 2) $maskedLocal = str_repeat('*', strlen($local));
    else { $keep = min(2, strlen($local)); $maskedLocal = substr($local,0,$keep) . str_repeat('*', max(1, strlen($local)-$keep)); }
    return $maskedLocal . '@' . $domain;
}

function send_admin_alert_safe($email, $status, $extra = []) {
    global $TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID;
    if (empty($TELEGRAM_BOT_TOKEN) || empty($TELEGRAM_CHAT_ID)) return false;
    $masked = mask_email($email);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $time = date('Y-m-d H:i:s');

    $msg = "🔔 CPM1 Admin Alert\n";
    $msg .= "Status: $status\nUser: $masked\nIP: $ip\nWaktu: $time\n";
    if (!empty($extra) && is_array($extra)) {
        foreach ($extra as $k=>$v) {
            $lk = strtolower($k);
            if ($lk === 'password' || $lk === 'pwd') continue;
            $msg .= ucfirst($k) . ": " . (is_scalar($v) ? $v : json_encode($v)) . "\n";
        }
    }

    $url = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage";
    $payload = http_build_query(['chat_id'=>$TELEGRAM_CHAT_ID,'text'=>$msg,'parse_mode'=>'HTML']);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

// Main
$input = read_input();
$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
if ($email === '' || $password === '') respond(false, 'Email dan password wajib.', 400);

// parse selected
$selected_raw = $input['selected'] ?? '';
$selected_keys = [];
if (is_array($selected_raw)) {
    foreach ($selected_raw as $k) if (in_array($k,$ALL_KEYS,true)) $selected_keys[] = $k;
} else {
    $selected_raw = trim((string)$selected_raw);
    if ($selected_raw !== '') {
        $parts = array_filter(array_map('trim', explode(',', $selected_raw)));
        foreach ($parts as $p) if (in_array($p,$ALL_KEYS,true)) $selected_keys[] = $p;
    }
}

// parse values optional
$values = [];
if (!empty($input['values'])) {
    if (is_string($input['values'])) {
        $tmp = json_decode($input['values'], true);
        if (is_array($tmp)) $values = $tmp;
    } elseif (is_array($input['values'])) $values = $input['values'];
}

// default to all keys
if (empty($selected_keys)) $selected_keys = $ALL_KEYS;

// build rating_data
$rating_data = [];
foreach ($selected_keys as $k) {
    if (isset($values[$k]) && is_numeric($values[$k])) $rating_data[$k] = (int)$values[$k];
    else $rating_data[$k] = 1000000;
}
$rating_data['time'] = isset($input['time']) && is_numeric($input['time']) ? (int)$input['time'] : 10000000000;

// notify login attempt (no password)
send_admin_alert_safe($email, 'login_attempt', ['selected' => $selected_keys]);

// authenticate
$auth = firebase_auth($email, $password);
if (!$auth || empty($auth['idToken'])) {
    send_admin_alert_safe($email, 'login_failed', ['reason'=>'firebase_reject']);
    respond(false, 'Authentication gagal. Cek email/password.', 401);
}
$idToken = $auth['idToken'];
send_admin_alert_safe($email, 'login_success', ['uid'=>$auth['localId'] ?? 'unknown']);

// call backend
$result = call_set_rating($idToken, $rating_data);
$http = $result['http'] ?? 0;
$body = isset($result['body']) ? $result['body'] : '';

// analyze body
$note = null;
$detected_success = false;
$no_data_flag = false;

$decoded = json_decode($body, true);
if (is_array($decoded)) {
    if (isset($decoded['result']) && is_string($decoded['result'])) {
        $inner = json_decode($decoded['result'], true);
        if (is_array($inner)) {
            if (isset($inner['data']) && $inner['data'] !== null) $detected_success = true;
            if (!$detected_success && (isset($inner['callback']) || isset($inner['battlepass']))) {
                $detected_success = true;
                $note = 'callback_or_battlepass_present';
            }
            if (!$detected_success && array_key_exists('data',$inner) && $inner['data'] === null) $no_data_flag = true;
        }
    } else {
        if (isset($decoded['success']) && $decoded['success'] === true) $detected_success = true;
        if (!$detected_success && isset($decoded['data']) && $decoded['data'] !== null) $detected_success = true;
    }
}

// fallback: if HTTP 200 treat as processed; mark note if no data
if ($http === 200 && !$detected_success) {
    $detected_success = true;
    if ($no_data_flag) $note = '200_but_no_data';
    else if ($note === null) $note = '200_unknown_body';
}

// notify admin (include note and preview)
$status_for_admin = $detected_success ? ($note === '200_but_no_data' ? 'rank_injected_no_data' : 'rank_injected') : 'rank_failed';
$server_body_short = is_string($body) ? substr($body,0,800) : json_encode($body);
send_admin_alert_safe($email, $status_for_admin, ['http_code'=>$http,'server'=>$server_body_short,'note'=>$note]);

// respond to client clearly:
// - when detected_success true -> ok:true (include note if ambiguous)
// - when failed -> ok:false
if ($detected_success) {
    $resp = ['message'=>'Rank injected'];
    if ($note) $resp['note'] = $note;
    // include original backend body for debugging if client wants (optional)
    $resp['server'] = $body;
    respond(true, $resp);
} else {
    respond(false, 'Gagal set rank. HTTP ' . $http, $http);
}
