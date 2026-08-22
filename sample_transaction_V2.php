<?php
/**
 * SAMPLE TRANSACTION SCRIPT - PHP VERSION (Exchange-Ready)
 * 
 * Complete transaction workflow with exchange-grade features:
 * - Backend API for all crypto operations (NO PHP crypto libraries!)
 * - UTXO lock tracking (prevents double-spends)
 * - Proper accounting for withdrawals
 * 
 * Uncomment "extension=curl" in php.ini to enable curl extension
 * 
 * Features:
 * 1. Fetches UTXOs from blockchain indexer
 * 2. Filters out locked UTXOs (pending + confirmed <10 blocks)
 * 3. Signs transaction via backend (canonical hash)
 * 4. Broadcasts to mempool
 * 5. Returns the canonical on-chain tx hash for confirmation tracking
 * 
 * Usage: php sample_transaction.php
 */

// ==========================================
// CONFIGURATION
// ==========================================

define('API_BASE', 'http://localhost:3001/api');
define('EXPLORER_API', 'http://localhost:3003/api');
define('MEMPOOL_API', 'http://localhost:4003/api');

// Test wallet credentials (provided by user)
define('WALLET_ADDRESS', '149JLju5whdijtyJCgjd8ujaGyURaSzh4U');
define('PRIVATE_KEY', '45fba9c7e4b8b098924fd7ab2eca2074d4ff5fda57c9c4ed4a74124b2824f81f');
define('PUBLIC_KEY', '020e028dd3dc4ed28886b307941fcc9a9b335e9842e6b8f6303f7dcb066d676266');

// Transaction Details
define('RECIPIENT_ADDRESS', '1NkiztfDVMRtYzBP7rj2KhP4P3yiCFn7jB');
define('AMOUNT_TO_SEND', 1000000); // units
define('CUSTOM_FEE', 20000); // units 
define('UTXO_COUNT_SUFFIX', ' UTXOs\n');

// ==========================================
// UTILITY FUNCTIONS
// ==========================================

function httpRequest($url, $method = 'GET', $data = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 400) {
        echo "❌ HTTP Error $httpCode: $response\n";
        return null;
    }
    
    return json_decode($response, true);
}

// ==========================================
// MAIN TRANSACTION FLOW
// ==========================================

echo "🚀 PHP TRANSACTION SCRIPT (Library-Free)\n";
echo str_repeat('=', 60) . "\n";
echo "📡 APIs:\n";
echo "   Node:     " . API_BASE . "\n";
echo "   Explorer: " . EXPLORER_API . "\n";
echo "   Mempool:  " . MEMPOOL_API . "\n";
echo "💸 Transaction:\n";
echo "   Amount:   " . AMOUNT_TO_SEND . " units\n";
echo "   Fee:      " . CUSTOM_FEE . " units\n";
echo str_repeat('=', 60) . "\n\n";

// Step 1: Wallet Info
echo "👤 Step 1: Wallet Information\n";
echo "──────────────────────────────────────────\n";
echo "   Address: " . WALLET_ADDRESS . "\n";
echo "   PubKey:  " . substr(PUBLIC_KEY, 0, 32) . "...\n";
echo "   PrivKey: [HIDDEN]\n\n";

// Step 2: Fetch UTXOs from Indexer (with retry)
echo "🔍 Step 2: Fetch UTXOs from Indexer\n";
echo "──────────────────────────────────────────\n";

$maxRetries = 3; // 3 retries at 5-minute intervals = 15 minutes
$retryDelay = 300; // 5 minutes
$allUtxos = null;

for ($retry = 0; $retry <= $maxRetries; $retry++) {
    $utxoResponse = httpRequest(EXPLORER_API . "/explorer/addresses/" . WALLET_ADDRESS . "/utxos");
    
    if ($utxoResponse && $utxoResponse['success'] && !empty($utxoResponse['utxos'])) {
        $allUtxos = $utxoResponse['utxos'];
        break;
    }
    
    if ($retry < $maxRetries) {
        echo "   ⚠️  No UTXOs found (attempt " . ($retry + 1) . "/" . ($maxRetries + 1) . ")\n";
        echo "   ⏳ Waiting {$retryDelay}s for indexer to update...\n";
        sleep($retryDelay);
    }
}

if (!$allUtxos || empty($allUtxos)) {
    die("\n❌ No UTXOs found after " . ($maxRetries + 1) . " attempts (15 minutes).\n" .
        "   Possible causes:\n" .
        "   • Wallet has no confirmed balance\n" .
        "   • Previous transaction not yet indexed\n" .
        "   • Indexer synchronization delay\n\n");
}

echo "   ✅ Found " . count($allUtxos) . " confirmed UTXOs\n\n";

// Step 2b: Fetch Locked UTXOs from Mempool (with retry for spendable)
echo "🔒 Step 2b: Fetch Locked UTXOs from Mempool\n";
echo "──────────────────────────────────────────\n";

$maxSpendableRetries = 3; // 3 retries at 5-minute intervals = 15 minutes
$spendableRetryDelay = 300; // 5 minutes
$utxos = null;

for ($spendableRetry = 0; $spendableRetry <= $maxSpendableRetries; $spendableRetry++) {
    $lockedResponse = httpRequest(MEMPOOL_API . "/mempool/locked-utxos?address=" . urlencode(WALLET_ADDRESS));
    
    $lockedUtxoSet = [];
    if ($lockedResponse && $lockedResponse['success'] && !empty($lockedResponse['lockedUTXOs'])) {
        foreach ($lockedResponse['lockedUTXOs'] as $lockedInfo) {
            $lockedUtxoSet[$lockedInfo['utxo']] = true;
        }
    }
    
    // Filter out locked UTXOs
    $utxos = array_filter($allUtxos, function($utxo) use ($lockedUtxoSet) {
        $utxoKey = $utxo['txid'] . ':' . $utxo['vout'];
        return !isset($lockedUtxoSet[$utxoKey]);
    });
    
    // Re-index array after filtering
    $utxos = array_values($utxos);
    
    // If we have spendable UTXOs, break out of retry loop
    if (!empty($utxos)) {
        if (!empty($lockedUtxoSet)) {
            echo "   ⚠️  Found " . count($lockedUtxoSet) . " locked UTXOs\n";
            echo "   Sources: Pending mempool + Confirmed (<10 blocks)\n";
        } else {
            echo "   ✅ No locked UTXOs\n";
        }
        break;
    }
    
    // All UTXOs are locked - retry if we haven't exhausted attempts
    if ($spendableRetry < $maxSpendableRetries) {
        echo "   ⚠️  All UTXOs locked (attempt " . ($spendableRetry + 1) . "/" . ($maxSpendableRetries + 1) . ")\n";
        echo "   📊 Analysis:\n";
        echo "      - Total Confirmed:  " . count($allUtxos) . " UTXOs\n";
        echo "      - Locked:           " . count($lockedUtxoSet) . " UTXOs\n";
        echo "      - Spendable:        0 UTXOs\n";
        echo "   ⏳ Waiting {$spendableRetryDelay}s for UTXOs to unlock...\n\n";
        sleep($spendableRetryDelay);
    }
}

echo "   📊 Analysis:\n";
echo "      - Total Confirmed:  " . count($allUtxos) . " UTXOs\n";
echo "      - Locked:           " . count($lockedUtxoSet) . " UTXOs\n";
echo "      - Spendable:        " . count($utxos) . " UTXOs\n";

if (empty($utxos)) {
    die("\n❌ No spendable UTXOs available after " . ($maxSpendableRetries + 1) . " attempts (15 minutes).\n" .
        "   All UTXOs are locked in pending/recent transactions.\n" .
        "   Possible causes:\n" .
        "   • Too many rapid withdrawals\n" .
        "   • Recent transactions not yet confirmed (need 10 confirmations)\n" .
        "   • Insufficient UTXO count for transaction volume\n\n" .
        "   RECOMMENDATIONS:\n" .
        "   • Wait for pending transactions to confirm\n" .
        "   • Fund wallet with more UTXOs\n" .
        "   • Implement UTXO consolidation during low-traffic periods\n\n");
}

echo "\n";

// Step 3: Fetch Current Height
echo "📏 Step 3: Fetch Current Height\n";
echo "──────────────────────────────────────────\n";
$heightResponse = httpRequest(API_BASE . "/blockchain/latest");
$currentHeight = $heightResponse['block']['header']['height'] ?? 0;
// NOTE: lockTime is now set by the signer as a RELATIVE block count (how many confirmations
// must pass before the output is spendable). The miner no longer overrides it.
// Set it to any non-negative value you want here.
$targetLockTime = 10;
echo "   ✅ Current Height: $currentHeight\n";
echo "   🔐 Target LockTime: $targetLockTime blocks\n\n";

// Step 4: Build Transaction
echo "📝 Step 4: Build Transaction\n";
echo "──────────────────────────────────────────\n";

// Sort UTXOs by value (largest first)
usort($utxos, function($a, $b) {
    return $b['value'] - $a['value'];
});

$targetAmount = AMOUNT_TO_SEND + CUSTOM_FEE;
$selectedUtxos = [];
$totalInput = 0;

foreach ($utxos as $utxo) {
    $selectedUtxos[] = $utxo;
    $totalInput += $utxo['value'];
    if ($totalInput >= $targetAmount) break;
}

if ($totalInput < $targetAmount) {
    die("❌ Insufficient funds. Need: $targetAmount, Have: $totalInput\n");
}

$changeAmount = $totalInput - AMOUNT_TO_SEND - CUSTOM_FEE;
echo "   Selected " . count($selectedUtxos) . " UTXOs\n";
echo "   Total Input: $totalInput units\n";
echo "   Change:      $changeAmount units\n\n";

// Build inputs
$inputs = array_map(function($utxo) {
    return [
        'txid' => $utxo['txid'],
        'vout' => $utxo['vout'],
        'sequence' => 0xffffffff,
        'value' => $utxo['value'],
        'address' => WALLET_ADDRESS
    ];
}, $selectedUtxos);

// Build outputs
$outputs = [
    [
        'value' => AMOUNT_TO_SEND,
        'address' => RECIPIENT_ADDRESS,
        'type' => 'P2PKH',
        'scriptPubKey' => '',
        'tag' => ''
    ]
];

// Add change output if greater than dust limit (2000 units)
if ($changeAmount > 2000) {
    $outputs[] = [
        'value' => $changeAmount,
        'address' => WALLET_ADDRESS,
        'type' => 'P2PKH',
        'scriptPubKey' => '',
        'tag' => ''
    ];
}

$unsignedTransaction = [
    'version' => 1,
    'type' => 'transfer',
    'inputs' => $inputs,
    'outputs' => $outputs,
    'lockTime' => $targetLockTime
];

echo "   Inputs:  " . count($inputs) . "\n";
echo "   Outputs: " . count($outputs) . "\n\n";

// Step 5: Sign Transaction via Backend API
echo "🔏 Step 5: Sign Transaction (via Backend API)\n";
echo "──────────────────────────────────────────\n";

$signRequest = [
    'privateKey' => PRIVATE_KEY,
    'publicKey' => PUBLIC_KEY,
    'transaction' => $unsignedTransaction
];

$signResponse = httpRequest(API_BASE . "/wallet/sign-transaction", 'POST', $signRequest);

if (!$signResponse || !$signResponse['success']) {
    $error = $signResponse['error'] ?? 'Unknown error';
    die("❌ Signing failed: $error\n");
}

$signedTransaction = $signResponse['signedTransaction'];
$signature = $signResponse['signature'];
$txHash = $signResponse['txHash'];

echo "   ✅ Transaction signed!\n";
echo "   Signature: " . substr($signature, 0, 32) . "...\n";
echo "   TX Hash:   $txHash\n\n";

// Step 6: Broadcast Transaction
echo "📤 Step 6: Broadcast to Mempool\n";
echo "──────────────────────────────────────────\n";

$broadcastResponse = httpRequest(MEMPOOL_API . "/mempool/add", 'POST', $signedTransaction);

if ($broadcastResponse && ($broadcastResponse['success'] ?? false)) {
    // The signed txHash is the canonical on-chain hash (same as what gets mined).
    $txHash = $broadcastResponse['txid'] ?? $txHash;
    
    echo "\n✅ SUCCESS: Transaction Accepted!\n";
    echo str_repeat('=', 60) . "\n";
    echo "   TX Hash (canonical): $txHash\n";
    echo "   Status:              In Mempool (Pending Mining)\n";
    echo str_repeat('=', 60) . "\n\n";
    
    echo "ℹ️  EXCHANGE ACCOUNTING NOTES:\n";
    echo "   • Record the TX Hash above in your database — it is the canonical on-chain identifier\n";
    echo "   • Use it for blockchain explorer queries: " . EXPLORER_API . "/explorer/transactions/$txHash\n";
    echo "   • Poll /explorer/transactions/$txHash to confirm and read blockHeight/confirmations\n";
    echo "   • Withdrawal is spendable once confirmations >= 10\n\n";
    
} else {
    echo "\n❌ FAILED: Transaction Rejected\n";
    echo "   Reason: " . ($broadcastResponse['error'] ?? 'Unknown') . "\n\n";
}

echo str_repeat('=', 60) . "\n";
?>

