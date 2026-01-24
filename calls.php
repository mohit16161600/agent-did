<?php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata');

$apiUrl = "https://api-smartflo.tatateleservices.com/v1/live_calls";
$tokens = [
    "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIyMDYzMTYiLCJjciI6dHJ1ZSwiaXNzIjoiaHR0cHM6Ly9jbG91ZHBob25lLnRhdGF0ZWxlc2VydmljZXMuY29tL3Rva2VuL2dlbmVyYXRlIiwiaWF0IjoxNzY5MDgwNjE5LCJleHAiOjIwNjkwODA2MTksIm5iZiI6MTc2OTA4MDYxOSwianRpIjoiUFB1WHdITWU5a2UyN0c3eSJ9.rrqJy2Ok-vIsJtGBKqfoCwnkMJy37L3wARTQkEaORjo",
    "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI1MzM5NTAiLCJjciI6dHJ1ZSwiaXNzIjoiaHR0cHM6Ly9jbG91ZHBob25lLnRhdGF0ZWxlc2VydmljZXMuY29tL3Rva2VuL2dlbmVyYXRlIiwiaWF0IjoxNzY5MDgzNzA4LCJleHAiOjIwNjkwODM3MDgsIm5iZiI6MTc2OTA4MzcwOCwianRpIjoiY1dGRjFWQTdjV1F6QVZlSSJ9.heWxZAnF9__eg_oe1YAe2KVSYM3sjop1_KXYyexo8u4"
];

$data = [];

foreach ($tokens as $token) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $fetchedData = json_decode($response, true);
        if (is_array($fetchedData)) {
            $data = array_merge($data, $fetchedData);
        }
    }
}

// Database insertion logic
$conn = new mysqli('localhost', 'root', '', 'agent_did');
if (!$conn->connect_error) {
    // $data is already populated from the loop above
    if (is_array($data)) {
        // Prepare statements to prevent SQL injection and improve performance
        // Check uniqueness by NAME, not agent_id (which is the account ID/user_id)
        // Prepare statements outside loop for efficiency, but BINARY needs care.
        // Actually, for maximum safety with BINARY and distinct variables, let's keep it simple.
        // However, to use BINARY effectively with placeholders:
        $checkSql = "SELECT `name` FROM `agent data` WHERE BINARY `name` = ?";
        $insertSql = "INSERT INTO `agent data` (`name`, `agent_id`) VALUES (?, ?)";
        
        $checkStmt = $conn->prepare($checkSql);
        $insertStmt = $conn->prepare($insertSql);

        if ($checkStmt && $insertStmt) {
            foreach ($data as $call) {
                $name = $call['agent_name'] ?? null;
                $id = $call['user_id'] ?? null;

                if ($name && $id) {
                    $checkStmt->bind_param("s", $name);
                    if (!$checkStmt->execute()) {
                         file_put_contents('db_error.log', date('Y-m-d H:i:s') . " Check Execute failed: " . $checkStmt->error . "\n", FILE_APPEND);
                    }
                    $result = $checkStmt->get_result();

                    if ($result->num_rows === 0) {
                        $insertStmt->bind_param("si", $name, $id);
                        if (!$insertStmt->execute()) {
                            file_put_contents('db_error.log', date('Y-m-d H:i:s') . " Insert Execute failed for $name: " . $insertStmt->error . "\n", FILE_APPEND);
                        }
                    } else {
                        // Agent exists and is active, update logg-off to NOW()
                        // This effectively keeps "active" status fresh
                        // Note: prepared statement for UPDATE is also better, but raw query with escape is acceptable for now given the context
                        if (!$conn->query("UPDATE `agent data` SET `logg-off` = NOW() WHERE BINARY `name` = '" . $conn->real_escape_string($name) . "'")) {
                             file_put_contents('db_error.log', date('Y-m-d H:i:s') . " Update failed for $name: " . $conn->error . "\n", FILE_APPEND);
                        }
                    }
                } else {
                    // Log missing data
                     // file_put_contents('db_error.log', date('Y-m-d H:i:s') . " Missing name or id for call: " . json_encode($call) . "\n", FILE_APPEND);
                }
            }
            $checkStmt->close();
            $insertStmt->close();
        } else {
            file_put_contents('db_error.log', date('Y-m-d H:i:s') . " Prepare failed: " . $conn->error . "\n", FILE_APPEND);
        }

        // --- MERGE LOGIC ---
        // 1. Get all known agents from DB AND current DB time
        // We fetch NOW() to ensure we calculate diff based on DB timezone
        // ORDER BY logg-off DESC ensures latest offline agents appear first
            $allAgents = [];
            $dbResult = $conn->query("SELECT `name`, `logg-off`, NOW() as db_now FROM `agent data` ORDER BY `logg-off` ASC");
            while ($row = $dbResult->fetch_assoc()) {
                $allAgents[$row['name']] = $row;
                $currentDbTime = strtotime($row['db_now']); // Capture DB time once (or per row, same thing)
            }

            // 2. Map Active Calls by agent name
            $activeCallsMap = [];
            foreach ($data as $call) {
                if (isset($call['agent_name'])) {
                    $activeCallsMap[$call['agent_name']] = $call;
                }
            }

        // 3. Build Final Response
        $finalResponse = [];

        // First, add all ACTIVE calls (ensure they are in the list)
        foreach ($data as $call) {
             $finalResponse[] = $call;
             // Remove from map to track who is processed
             unset($allAgents[$call['agent_name']]);
        }

        // Next, add INACTIVE agents from DB
        foreach ($allAgents as $name => $agentInfo) {
            // Skip if somehow already added (though strict loop key check handles this)
            if (isset($activeCallsMap[$name])) continue;

            $logOffTime = $agentInfo['logg-off'];
            
            $displayTime = "Offline";
            if ($logOffTime) {
                // Use DB time to avoid timezone mismatch
                $diff = $currentDbTime - strtotime($logOffTime);
                
                // Ensure no negative values just in case
                if ($diff < 0) $diff = 0;

                $hours = floor($diff / 3600);
                $minutes = floor(($diff % 3600) / 60);
                $seconds = $diff % 60;
                
                // Format with leading zeros
                $timeStr = sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
                $displayTime = "Offline: <span style='color:#e03d24'>$timeStr</span>";
            }

            $finalResponse[] = [
                "agent_name" => $name,
                "customer_number" => "-",
                "state" => "Not on call",
                "call_time" => $displayTime,
                // Add dummy fields to match structure if needed
                "user_id" => null
            ];
        }
        
        // Overwrite standard response with our merged list
        $response = json_encode($finalResponse);
    }
    $conn->close();
}

echo $response;
