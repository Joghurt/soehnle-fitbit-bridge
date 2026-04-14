<?php

require_once('config.php');

/*
HOW-TO: upload.php - Batch Upload of Historical Weight Data to Fitbit

This file reads all existing log files, parses date/time/weight
and uploads the data to Fitbit. Duplicates are avoided.

Usage:
- Call in browser: http://bridge1.soehnle.de/devicedataservice/upload.php
- Or via console: php /path/to/upload.php

The script:
1. Reads all log/*.log files
2. Parses format: "dd.mm.yy hh:mm:ss weight"
3. Tracks already uploaded entries in upload_history.txt
4. Uploads new entries to Fitbit
5. Avoids duplicates (same weights on the same date)
*/

define('TOKEN_FILE', __DIR__ . '/token.txt');
define('HISTORY_FILE', __DIR__ . '/upload_history.txt');
define('UPLOAD_DELAY', 1); // Seconds between successful uploads
define('ERROR_DELAY', 3); // Seconds after error or rate limit

date_default_timezone_set('Europe/Berlin');
setlocale(LC_TIME, "de_DE.UTF-8", "de_DE@euro", "de_DE", "deu_deu", "de", "ge");

header('Content-Type: text/plain; charset=utf-8');
ob_start();

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
		echo "Token refreshed successfully\n";
		sleep(1);
		return $token_data['access_token'];
	} else {
		echo "Token refresh failed: " . $response . "\n";
		sleep(ERROR_DELAY);
		return null;
	}
}

function send_to_fitbit($weight, $date, $time = null) {
	$token_data = load_token();
	if (!$token_data) {
		echo "ERROR: No token available for Fitbit upload\n";
		return false;
	}

	$access_token = $token_data['access_token'];
	$max_retries = 3;
	$retry_count = 0;

	while ($retry_count < $max_retries) {
		$url = 'https://api.fitbit.com/1/user/-/body/log/weight.json';
		$data = [
			'weight' => $weight,
			'date' => $date,
			'time' => $time ?? date('H:i:s'),
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
			echo "✓ Uploaded: $weight kg on $date at " . ($time ?? date('H:i:s')) . "\n";
			sleep(UPLOAD_DELAY);
			return true;
		} elseif ($http_code == 401) {
			// Token expired - try refresh
			$new_token = refresh_token($token_data['refresh_token']);
			if ($new_token) {
				$access_token = $new_token;
				$retry_count++;
				continue; // Retry with new token
			} else {
				echo "ERROR: Fitbit upload failed after token refresh\n";
				sleep(ERROR_DELAY);
				return false;
			}
		} elseif ($http_code == 429) {
			$retry_count++;
			if ($retry_count < $max_retries) {
				$wait_time = ERROR_DELAY * $retry_count; // Progressive backoff: 3s, 6s, 9s
				echo "Rate limit hit (attempt $retry_count/$max_retries) - waiting $wait_time seconds...\n";
				sleep($wait_time);
				continue; // Retry
			} else {
				echo "ERROR: Rate limit exceeded after $max_retries attempts - " . $response . "\n";
				sleep(ERROR_DELAY);
				return false;
			}
		} else {
			echo "ERROR: HTTP $http_code - " . $response . "\n";
			sleep(ERROR_DELAY);
			return false;
		}
	}

	return false;
}

function load_history() {
	$history = [];
	if (file_exists(HISTORY_FILE)) {
		$lines = file(HISTORY_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		foreach ($lines as $line) {
			$parts = explode('|', $line);
			if (count($parts) === 3) {
				$key = $parts[0] . '|' . $parts[1]; // date|weight
				$history[$key] = true;
			}
		}
	}
	return $history;
}

function save_to_history($date, $weight, $time) {
	$entry = "$date|$weight|$time\n";
	file_put_contents(HISTORY_FILE, $entry, FILE_APPEND);
}

function parse_log_date($date_str) {
	// Convert dd.mm.yy to yyyy-mm-dd
	$parts = explode('.', $date_str);
	if (count($parts) === 3) {
		$day = $parts[0];
		$month = $parts[1];
		$year = '20' . $parts[2]; // assume 20xx
		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}
	return null;
}

function parse_line($line) {
	$line = trim($line);
	if (empty($line)) {
		return null;
	}
	
	// Check for new format: "dd.mm.yy hh:mm:ss weight"
	if (preg_match('/^(\d{2}\.\d{2}\.\d{2}) (\d{2}:\d{2}:\d{2}) (\d+(?:\.\d+)?)$/', $line, $matches)) {
		$date_str = $matches[1];
		$time_str = $matches[2];
		$weight = floatval($matches[3]);
		return [$date_str, $time_str, $weight];
	}
	
	// Check for old format: "RFC822 datetime ... weight: weight"
	if (preg_match('/^(.+) (?:[0-9a-z-_]+) weight: (\d+(?:\.\d+)?)$/u', $line, $matches)) {
		$datetime_str = trim($matches[1]);
		$weight = floatval($matches[2]);
		try {
			$datetime = new DateTime($datetime_str);
			$datetime->setTimezone(new DateTimeZone(date_default_timezone_get()));
			$date_str = $datetime->format('d.m.y');
			$time_str = $datetime->format('H:i:s');
			return [$date_str, $time_str, $weight];
		} catch (Exception $e) {
			echo $e->getMessage() . "\n";
			return null; // Invalid datetime
		}
	}
	
	return null; // Unrecognized format
}

// Main logic
echo "Starting batch upload from logs...\n";
echo "================================\n";
echo "Rate limit delay: " . UPLOAD_DELAY . "s between uploads, " . ERROR_DELAY . "s after errors\n";
echo "================================\n\n";

$history = load_history();
$log_dir = __DIR__ . '/log';
$uploaded_count = 0;
$duplicate_count = 0;
$error_count = 0;

if (!is_dir($log_dir)) {
	echo "ERROR: log directory not found\n";
	exit;
}

$log_files = glob($log_dir . '/*.log');
if (empty($log_files)) {
	echo "No log files found\n";
	exit;
}

echo "Found " . count($log_files) . " log file(s)\n";
echo "Loaded " . count($history) . " already uploaded entries from history\n";
echo "Processing...\n\n";

foreach ($log_files as $log_file) {
	echo "Reading: " . basename($log_file) . "\n";
	$lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	$parsed_count = 0;
	
	foreach ($lines as $line) {
		$parsed = parse_line($line);
		if ($parsed) {
			list($date_str, $time_str, $weight) = $parsed;
			
			$date = parse_log_date($date_str);
			
			if ($date && $weight > 0) {
				$parsed_count++;
				$history_key = "$date|$weight"; // Track by date and weight to avoid duplicates
				
				if (!isset($history[$history_key])) {
					// Not yet uploaded
					if (send_to_fitbit($weight, $date, $time_str)) {
						save_to_history($date, $weight, $time_str);
						$history[$history_key] = true;
						$uploaded_count++;
					} else {
						$error_count++;
					}
				} else {
					$duplicate_count++;
					echo " ⊘ Already uploaded: $weight kg on $date\n";
				}
			}
		}
	}
	if ($parsed_count > 0) {
		echo " (Parsed: $parsed_count entries)\n";
	}
}

echo "\n================================\n";
echo "Summary:\n";
echo " Uploaded: $uploaded_count\n";
echo " Duplicates skipped: $duplicate_count\n";
echo " Errors: $error_count\n";
echo "================================\n";

if (!headers_sent()) {
	header('Content-Length: ' . ob_get_length());
}
ob_end_flush();
?>
