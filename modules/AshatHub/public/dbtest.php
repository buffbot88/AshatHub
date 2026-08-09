<?php
// Temporary test script - DELETE AFTER TESTING
header('Content-Type: text/plain');

echo "=== Database Connection Test ===\n\n";

// Load config
$config = json_decode(file_get_contents('/home/opc/AshatPlatform/modules/AshatHub/config/server_config.json'), true);
echo "Config loaded: " . ($config ? 'YES' : 'NO') . "\n";
echo "DB_HOST: " . ($config['DB_HOST'] ?? 'MISSING') . "\n";
echo "DB_PORT: " . ($config['DB_PORT'] ?? 'MISSING') . "\n";
echo "DB_NAME: " . ($config['DB_NAME'] ?? 'MISSING') . "\n";
echo "DB_USER: " . ($config['DB_USER'] ?? 'MISSING') . "\n\n";

// Test PDO extension
echo "PDO extension: " . (extension_loaded('pdo') ? 'LOADED' : 'NOT LOADED') . "\n";
echo "pdo_mysql extension: " . (extension_loaded('pdo_mysql') ? 'LOADED' : 'NOT LOADED') . "\n\n";

// Test connection
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', 
        $config['DB_HOST'], $config['DB_PORT'], $config['DB_NAME']);
    $pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "Connection: SUCCESS\n\n";
    
    // Test SHOW TABLE STATUS
    $stmt = $pdo->query('SHOW TABLE STATUS');
    $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Tables found: " . count($tables) . "\n";
    foreach ($tables as $t) {
        echo "  - " . $t['Name'] . " (" . $t['Engine'] . ", " . $t['Rows'] . " rows)\n";
    }
} catch (PDOException $e) {
    echo "Connection FAILED: " . $e->getMessage() . "\n";
    echo "Error code: " . $e->getCode() . "\n";
}
