<?php 

require_once('config.php');

/*
HOW-TO: Set up Fitbit Integration

1. Create Fitbit App
   - Go to https://dev.fitbit.com/apps/new
   - Create a new app with the following settings:
     - Application Name: e.g. "Device Data Service"
     - Description: Any
     - Application Website: Your domain, e.g. http://yourdomain.com
     - Organization: Any
     - Organization Website: Any
     - Terms of Service URL: Optional
     - Privacy Policy URL: Optional
     - OAuth 2.0 Application Type: Personal
     - Redirect URI: https://bridge1.soehnle.de/devicedataservice/dataservice?action=callback
     - Default Access Type: Read & Write
   - After creation: Note the Client ID and Client Secret

2. Set configuration in config.php
   - Replace 'FITBIT_CLIENT_ID' with your Client ID
   - Replace 'FITBIT_CLIENT_SECRET' with your Client Secret

3. Perform authorization
   - Call in browser: https://bridge1.soehnle.de/devicedataservice/dataservice?action=authorize
   - Log in to Fitbit and allow access
   - You will be redirected, and the token will be saved in token.txt

4. For testing: Send weight data
   - Send as usual: ?data=... (e.g. from your scale)
   - The script parses the weight and sends it automatically to Fitbit
   - Weight data is logged to log/ directory (e.g., log/240414.log)
   - Fitbit upload results are logged to fitbit_log.txt

5. Token Management
   - Tokens are stored in token.txt
   - They are automatically renewed when expired
   - Errors are logged in fitbit_log.txt

Notes:
- Ensure the files are writable (token.txt, log/ directory, fitbit_log.txt)
- Weight is sent to Fitbit in kg, with dot as decimal separator
- Check logs in log/ directory and fitbit_log.txt for issues
*/

define('FITBIT_REDIRECT_URI', 'https://bridge1.soehnle.de/devicedataservice/dataservice?action=callback');
define('TOKEN_FILE', __DIR__ . '/token.txt');

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
        curl_close($ch);

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

function load_token() {
    if (file_exists(TOKEN_FILE)) {
        $data = json_decode(file_get_contents(TOKEN_FILE), true);
        return $data;
    }
    return null;
}

function save_token($token_data) {
    file_put_contents(TOKEN_FILE, json_encode($token_data));
}

function send_response_and_exit() {
    $body = ob_get_contents();
    if (!headers_sent()) {
        header('Content-Length: ' . strlen($body));
    }
    ob_end_flush();
    exit;
}

function refresh_token($refresh_token) {
    $url = 'https://api.fitbit.com/oauth2/token';
    $data = [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh_token,
        'client_id' => FITBIT_CLIENT_ID,
        'client_secret' => FITBIT_CLIENT_SECRET,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        $token_data = json_decode($response, true);
        save_token($token_data);
        fitbit_log("Token refreshed successfully");
        return $token_data['access_token'];
    } else {
        fitbit_log("Token refresh failed: " . $response);
        return null;
    }
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
    curl_close($ch);

    if ($http_code == 201) {
        fitbit_log("Weight uploaded to Fitbit: $weight kg on " . $data['date']);
        return true;
    } elseif ($http_code == 401) {
        // Try refresh
        $new_token = refresh_token($token_data['refresh_token']);
        if ($new_token) {
            // Retry with new token
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $new_token,
                'Content-Type: application/json',
            ]);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
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
    wh_log(date("d.m.y H:i:s") . " " . $weight);
    send_to_fitbit($weight);
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
?>