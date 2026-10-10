<?php
/**
 * NEW FILE: config/sms.php
 * Pindo SMS settings. Keep this file private: it contains your API token.
 * Never share it, paste it in chats, or commit it to a public repository.
 */

// Your Pindo API token: app.pindo.io -> Account -> Security settings.
define('PINDO_API_TOKEN', 'eyJhbGciOiJIUzUxMiIsInR5cCI6IkpXVCJ9.eyJleHAiOjE4ODYzMzU2NzgsImlhdCI6MTc5MTY0MTI3OCwiaWQiOiJ1c2VyXzAxTTJXQjhZQUdURVYyS0ZBMVFOWEExRlZEIiwicmV2b2tlZF90b2tlbl9jb3VudCI6MH0.I7J03oo4uu6dFSVfu_p4wIckJdlGNuYaGsnPfwkNGPkHPx7m7xhHHIz4bdxrY8resxmeyZTMA7wTG-fZIyFS4w');

// The sender name people see on their phone. It must be a sender ID registered and
// approved in your Pindo account, otherwise Pindo rejects the message.
define('PINDO_SENDER', 'RISEMOTIVE');

// Pindo endpoint for sending one SMS.
define('PINDO_API_URL', 'https://api.pindo.io/v1/sms/');

// Master switch. Set to false to stop ALL SMS at once (messages are logged as "skipped").
define('SMS_ENABLED', true);