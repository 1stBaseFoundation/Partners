<?php
/**
 * SAMPLE WALLET CREATION - PHP VERSION
 * 
 * Simple one-request wallet creation from backend API
 * 
 * Usage: php sample_wallet.php
 */

define('API_BASE', 'http://localhost:3001/api');

// Create wallet via API call
$ch = curl_init(API_BASE . '/wallet/create?includeKeys=true');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([]));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);

if ($data['success'] ?? false) {
    echo "✅ WALLET CREATED\n";
    echo str_repeat('=', 60) . "\n";
    echo "📝 Mnemonic: " . $data['wallet']['mnemonic'] . "\n";
    echo "📍 Address:  " . $data['wallet']['address'] . "\n";
    echo "🔑 Private Key:  " . $data['wallet']['privateKey'] . "\n";
    echo "🔑 Public Key:  " . $data['wallet']['publicKey'];
} else {
    echo "❌ FAILED: " . ($data['error'] ?? 'Unknown error') . "\n";
    exit(1);
}
?>
