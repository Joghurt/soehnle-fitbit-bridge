<?php

define('TOKEN_FILE', __DIR__ . '/token.txt');

function load_token() {
    if (file_exists(TOKEN_FILE)) {
        return json_decode(file_get_contents(TOKEN_FILE), true);
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode(FITBIT_CLIENT_ID . ':' . FITBIT_CLIENT_SECRET),
        'Content-Type: application/x-www-form-urlencoded',
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($http_code == 200) {
        $token_data = json_decode($response, true);
        save_token($token_data);
        return $token_data['access_token'];
    }

    return null;
}
