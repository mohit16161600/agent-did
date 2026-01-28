<?php
$conn = new mysqli('localhost', 'root', '', 'agent_did');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Columns to add if they don't exist
$columns = [
    "ADD COLUMN `today_total` INT DEFAULT 0",
    "ADD COLUMN `yesterday_total` INT DEFAULT 0",
    "ADD COLUMN `today_idle_total` INT DEFAULT 0",
    "ADD COLUMN `yesterday_idle_total` INT DEFAULT 0",
    "ADD COLUMN `last_log_date` DATE DEFAULT NULL",
    "ADD COLUMN `last_activity_timestamp` INT DEFAULT 0",
    "ADD COLUMN `last_idle_timestamp` INT DEFAULT 0" 
];

foreach ($columns as $col) {
    try {
        $sql = "ALTER TABLE `agent data` " . $col;
        $conn->query($sql);
        echo "Executed: $col\n";
    } catch (Exception $e) {
        // Ignore "Duplicate column name" error (Code 1060) or any error really for this script
        echo "Skipped (maybe exists): " . $e->getMessage() . "\n";
    }
}

$conn->close();
?>
