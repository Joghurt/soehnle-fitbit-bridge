# soehnle-fitbit-bridge

This PHP script emulates the terminated Soehnle/Leifheit data service for Webconnect body scales like the 063340 model. Due to Leifheit/Soehnle's decision to discontinue the mysoehnle service, this bridge allows continued use of the scale by forwarding weight data to Fitbit for tracking and analysis.

The return strings have been determined via tcpdump and from `https://github.com/biggaeh/bathroom_scales`.

This is a fork of the `https://github.com/haklein/soehnle-webconnect-dataservice` project.

## Features

- **Weight Data Logging**: Parses and logs weight measurements from the scale to local log files.
- **Fitbit Integration**: Automatically uploads weight data to Fitbit for cloud-based tracking.
- **Batch Upload**: Uploads historical weight data from log files to Fitbit, avoiding duplicates.
- **Web Dashboard**: Displays weight data with charts and statistics via a web interface.
- **Token Management**: Handles Fitbit OAuth tokens with automatic refresh.

# Installation

## Prerequisites

- PHP with cURL extension enabled
- Web server (e.g., Apache or Nginx) with PHP support
- Fitbit Developer Account and App

## Fitbit Setup

1. **Create a Fitbit App**:
   - Go to `https://dev.fitbit.com/apps/new`
   - Create a new app with the following settings:
     - Application Name: e.g., `Soehnle Fitbit Bridge`
     - Description: Any description, doesn't matter since this will be no official app
     - Application Website URL: Any
     - Organization: Any
     - Organization Website URL: Any
     - Terms of Service URL: Any
     - Privacy Policy URL: Any
     - OAuth 2.0 Application Type: Personal
     - Redirect URL: `https://bridge1.soehnle.de/devicedataservice/dataservice?action=callback`
     - Default Access Type: Read & Write
   - After creation, note the `OAuth 2.0 Client ID` and `Client Secret`.

2. **Configure the Script**:
   - Rename `config_example.php` to `config.php` and replace `FITBIT_CLIENT_ID` with the `OAuth 2.0 Client ID` and `FITBIT_CLIENT_SECRET` with the `Client Secret` from the Fitbit app overview.

3. **Authorize the App**:
   - Call in browser: `https://bridge1.soehnle.de/devicedataservice/dataservice?action=authorize`
   - Log in to Fitbit and allow access.
   - You will be redirected, and the token will be saved in a local file `token.txt`.

## DNS Mangling

### One way

To redirect HTTP requests from the scale to this script, DNS manipulation is required. Using `unbound` as DNS cache, add the following to the `server:` section:

```
local-zone: "bridge1.soehnle.de" transparent
local-data: "bridge1.soehnle.de A 192.168.x.y"
```

Replace `192.168.x.y` with your webserver's IP address.

### Another way

If you use for e.g. `pi-hole` put

```
192.168.x.y   bridge1.soehnle.de
```

into its `/etc/hosts`.

## URL Rewriting

The script resides in `$WEB_ROOT/devicedataservice/dataservice.php`.

### One way

To handle extensionless PHP execution (mapping requests like `https://bridge1.soehnle.de/devicedataservice/dataservice?data=...` to `dataservice.php`), use the provided `.htaccess` file for Apache config:

```
RewriteEngine On
RewriteCond %{SCRIPT_FILENAME} !-d
RewriteRule ^([^.]+)$ $1.php [NC,L]
```

or use the provided `.htaccess` file.

For Nginx, add to your configuration:

```
location / {
    if (!-e $request_filename){
        rewrite ^(.*)$ /$1.php;
    }
    try_files $uri $uri/ =404;
}
```

## Log Directory

Create a `log` directory with proper permissions in the project directory for storing weight logs.

## Testing the Integration

Once you have completed the authorization steps, you can test the integration:

- Send weight data from your scale: `?data=...` (e.g., from your scale)
- The script parses the weight and automatically sends it to Fitbit
- Weight data is logged to the `log/` directory (e.g., `log/240414.log`)
- Fitbit upload results are logged to `fitbit_log.txt`

## Token Management

- Tokens are stored in `token.txt`
- They are automatically renewed when they expire
- Errors and token refresh activities are logged in `fitbit_log.txt`

# Usage

## Receiving Data from Scale

The scale sends data via GET requests to `https://bridge1.soehnle.de/devicedataservice/dataservice.php`. The script:

- Parses the data string for the weight.
- Logs the weight to a daily log file in the `log/` directory (format: `dd.mm.yy hh:mm:ss weight`).
- And automatically uploads the weight to Fitbit.

Example log entry:
```
24.04.24 12:02:28 71.9
```

## Batch Upload of Historical Data

To upload existing log data to Fitbit:

- **Browser**: Call `https://bridge1.soehnle.de/devicedataservice/upload.php`
- **Command line**: `php /path/to/upload.php`

The script performs the following steps:

1. Reads all `log/*.log` files
2. Parses the log format: `dd.mm.yy hh:mm:ss weight`
3. Tracks already uploaded entries in `upload_history.txt` to avoid duplicates
4. Uploads new entries to Fitbit with rate limiting (1 second between successful uploads)
5. Implements error handling with longer delays (3 seconds) after errors or rate-limit responses

The script will display upload progress and any errors encountered during the process.

## Viewing Data

- **Browser**: Call `https://bridge1.soehnle.de/devicedataservice/show.php`

- Current, minimum, maximum, and average weight.
- Interactive chart of weight over time.
- Statistics and data summary.

# Files Overview

- `config_example.php`: Fitbit client example configuration (Client ID and Secret).
- `dataservice.php`: Main script handling scale data reception and Fitbit upload.
- `upload.php`: Batch uploader for historical data.
- `show.php`: Web dashboard for viewing weight data.
- `.htaccess`: Apache URL rewriting rules.
- `token.txt`: Stores Fitbit OAuth tokens (auto-generated).
- `upload_history.txt`: Tracks uploaded entries (auto-generated).
- `fitbit_log.txt`: Logs Fitbit API interactions (auto-generated).
- `log/*.log`: Daily weight log files.

# Notes

- Ensure files like `token.txt`, `log/` directory, `fitbit_log.txt`, and `upload_history.txt` are writable by the web server.
- Weight is sent to Fitbit in kg with dot as decimal separator.
- Tokens are automatically refreshed when expired.
- Check logs in the `log/` directory and `fitbit_log.txt` for any issues during operation.
- Repeated identical requests within 30 seconds are automatically ignored to prevent duplicate uploads.
