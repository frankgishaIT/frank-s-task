<?php
/**
 * NEW FILE: config/sms.php
 * Pindo SMS settings. Keep this file private: it contains your API token.
 * Never share it, paste it in chats, or commit it to a public repository.
 */

// Your Pindo API token: app.pindo.io -> Account -> Security settings.
define('PINDO_API_TOKEN', 'PASTE_YOUR_PINDO_TOKEN_HERE');

// The sender name people see on their phone. It must be a sender ID registered and
// approved in your Pindo account, otherwise Pindo rejects the message.
define('PINDO_SENDER', 'RISEMOTIVE');

// Pindo endpoint for sending one SMS.
define('PINDO_API_URL', 'https://api.pindo.io/v1/sms/');

// Master switch. Set to false to stop ALL SMS at once (messages are logged as "skipped").
define('SMS_ENABLED', true);