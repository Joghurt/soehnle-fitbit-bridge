<?php

if (!file_exists(__DIR__ . '/config.php')) {
    echo "ERROR: config.php not found. Please copy config_example.php to config.php and set your Fitbit credentials.\n";
    exit;
}

require_once('config.php');
require_once(__DIR__ . '/fitbit_common.php');

define('FITBIT_REDIRECT_URI', 'https://bridge1.soehnle.de/devicedataservice/dataservice?action=callback');
define('DUPLICATE_REQUEST_WINDOW', 30); // seconds within which repeated identical requests are ignored

date_default_timezone_set('Europe/Berlin');
setlocale(LC_TIME, "de_DE.UTF-8", "de_DE@euro", "de_DE", "deu_deu", "de", "ge");

header('Content-Type: text/plain; charset=utf-8');
ob_start();

$action = $_GET['action'] ?? null;

if ($action === 'authorize') {
    $auth_url = 'https://www.fitbit.com/oauth2/authorize?' . http_build_query([
        'client_id' => FITBIT_CLIENT_ID,
        'response_type' => 'code',
        'scope' => 'weight',
        'redirect_uri' => FITBIT_REDIRECT_URI,
        'expires_in' => 604800, // 1 week
    ]);
    header('Location: ' . $auth_url);
    ob_end_clean();
    header('Content-Length: 0');
    exit;
} elseif ($action === 'callback') {
    $code = $_GET['code'] ?? null;
    if ($code) {
        $url = 'https://api.fitbit.com/oauth2/token';
        $data = [
            'client_id' => FITBIT_CLIENT_ID,
            'grant_type' => 'authorization_code',
            'redirect_uri' => FITBIT_REDIRECT_URI,
            'code' => $code,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode(FITBIT_CLIENT_ID . ':' . FITBIT_CLIENT_SECRET),
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($http_code == 200) {
            $token_data = json_decode($response, true);
            save_token($token_data);
            fitbit_log("Token saved successfully");
            echo "Authorization successful. Token saved.";
        } else {
            fitbit_log("Token exchange failed: " . $response);
            echo "Authorization failed.";
        }
    } else {
        echo "No code received.";
    }
    send_response_and_exit();
}

function wh_log($log_msg)
{
    $log_filename = "log";
    if (!file_exists($log_filename))
    {
        // create directory/folder uploads.
        mkdir($log_filename, 0777, true);
    }
    $log_file_data = $log_filename.'/' . date('ymd') . '.log';
    // if you don't add `FILE_APPEND`, the file will be erased each time you add a log
    file_put_contents($log_file_data, $log_msg . "\n", FILE_APPEND);
}

function fitbit_log($log_msg)
{
    $log_file = __DIR__ . '/fitbit_log.txt';
    $timestamp = date('d.m.y H:i:s');
    file_put_contents($log_file, "[$timestamp] $log_msg\n", FILE_APPEND);
}

function is_duplicate_request($id_str, $weight)
{
    $history_file = __DIR__ . '/last_request.txt';
    if (!file_exists($history_file)) {
        return false;
    }

    $line = trim(file_get_contents($history_file));
    if ($line === '') {
        return false;
    }

    $parts = explode('|', $line);
    if (count($parts) !== 3) {
        return false;
    }

    list($prev_id, $prev_weight, $prev_timestamp) = $parts;
    if ($prev_id !== $id_str) {
        return false;
    }
    if (floatval($prev_weight) !== floatval($weight)) {
        return false;
    }
    if ((time() - intval($prev_timestamp)) > DUPLICATE_REQUEST_WINDOW) {
        return false;
    }

    return true;
}

function update_last_request($id_str, $weight)
{
    $history_file = __DIR__ . '/last_request.txt';
    $entry = sprintf('%s|%s|%s', $id_str, $weight, time());
    file_put_contents($history_file, $entry);
}

function send_response_and_exit() {
    $body = ob_get_contents();
    if (!headers_sent()) {
        header('Content-Length: ' . strlen($body));
    }
    ob_end_flush();
    exit;
}

function send_to_fitbit($weight) {
    $token_data = load_token();
    if (!$token_data) {
        fitbit_log("No token available for Fitbit upload");
        return false;
    }

    $access_token = $token_data['access_token'];

    $url = 'https://api.fitbit.com/1/user/-/body/log/weight.json';
    $data = [
        'weight' => $weight,
        'date' => date('Y-m-d'),
        'time' => date('H:i:s'),
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($http_code == 201) {
        fitbit_log("Weight uploaded to Fitbit: $weight kg on " . $data['date']);
        return true;
    } elseif ($http_code == 401) {
        // Try refresh
        $access_token = refresh_token($token_data['refresh_token']);
        if ($access_token) {
            fitbit_log("Token refreshed successfully");

            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json',
            ]);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($http_code == 201) {
                fitbit_log("Weight uploaded to Fitbit after refresh: $weight kg on " . $data['date']);
                return true;
            }
        }
        fitbit_log("Fitbit upload failed after refresh: " . $response);
        return false;
    } else {
        fitbit_log("Fitbit upload failed: HTTP $http_code - " . $response);
        return false;
    }
}

$data_string = $_GET['data'];

$data_id = hexdec(substr($data_string, 0,2));
$mac = substr($data_string, 2, 12);
$id1 = hexdec(substr($data_string, 14, 6));
$id2 = hexdec(substr($data_string, 20, 6));
$id_str = str_pad($id1, 8, '0', STR_PAD_LEFT) . "-" . str_pad($id2, 8, '0', STR_PAD_LEFT);
$weight = hexdec(substr($data_string, 38, 4))/100;

if ($id1 && $id2 && $weight > 0) {
    if (is_duplicate_request($id_str, $weight)) {
        fitbit_log("Duplicate request skipped for scale $id_str with weight $weight kg");
    } else {
        wh_log(date("d.m.y H:i:s") . " " . $weight);
        if (send_to_fitbit($weight)) {
            update_last_request($id_str, $weight);
        }
    }
}

# just return what we've sniffed with tcpdump, and taken from https://github.com/biggaeh/bathroom_scales/blob/master/dataservice
switch($data_id) {
    case 0x24:
        echo 'A00000000000000001000000000000000000000000000000bec650a1';
        break;
    case 0x22:
        echo 'A20000000000000000000000000000000000000000000000c9950d3f';
        break;
    case 0x25:
        echo 'A00000000000000001000000000000000000000000000000bec650a1';
        break;
    case 0x28:
        echo 'A5000000000000000100000000000000000000000000000056e5abd9';
        break;
    case 0x21:
        echo 'A10000000000000009c4914c0000000000000000000000001d095ec4';
        break;
}

if (!headers_sent()) {
    header('Content-Length: ' . ob_get_length());
}

ob_end_flush();
