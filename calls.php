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

// Sort combined data by call_time DESC to interleave results from both tokens
usort($data, function($a, $b) {
    // Assuming call_time is in HH:MM:SS format
    return strtotime($b['call_time']) - strtotime($a['call_time']);
});

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




        // --- DAY RESET LOGIC ---
        $conn->query("UPDATE `agent data` SET 
                      yesterday_total = today_total, 
                      today_total = 0, 
                      yesterday_idle_total = today_idle_total,
                      today_idle_total = 0,
                      last_log_date = CURDATE() 
                      WHERE last_log_date < CURDATE() OR last_log_date IS NULL");

        // --- TRACK ACTIVE AGENTS ---
        $activeAgentNames = [];

        // --- UPDATE & INSERT LOGIC (Active Calls) ---
        if ($checkStmt && $insertStmt) {
            foreach ($data as $call) {
                $name = $call['agent_name'] ?? null;
                $id = $call['user_id'] ?? null;
                $state = $call['state'] ?? '';

                if ($name && $id) {
                    $activeAgentNames[$name] = true;

                    $checkStmt->bind_param("s", $name);
                    $checkStmt->execute();
                    $result = $checkStmt->get_result();

                    if ($result->num_rows === 0) {
                        // Insert new agent
                        $insertStmt->bind_param("si", $name, $id);
                        $insertStmt->execute();
                        
                        // Initialize timestamps
                        $conn->query("UPDATE `agent data` SET 
                            last_log_date = CURDATE(), 
                            last_activity_timestamp = UNIX_TIMESTAMP(),
                            last_idle_timestamp = UNIX_TIMESTAMP() 
                            WHERE BINARY `name` = '" . $conn->real_escape_string($name) . "'");
                    } else {
                        // Update Active Agent
                        // Calculate Talk Time Increment
                        $timeIncSql = "0";
                        if ($state === 'Answered' || $state === 'Ringing') {
                            $timeIncSql = "IF(UNIX_TIMESTAMP() - last_activity_timestamp < 15, UNIX_TIMESTAMP() - last_activity_timestamp, 0)";
                        }

                        // Reset idle timestamp so it doesn't jump when they go idle
                        $updateSql = "UPDATE `agent data` SET 
                                      `logg-off` = NOW(),
                                      `last_log_date` = CURDATE(),
                                      `today_total` = `today_total` + $timeIncSql,
                                      `last_activity_timestamp` = UNIX_TIMESTAMP(),
                                      `last_idle_timestamp` = UNIX_TIMESTAMP()
                                      WHERE BINARY `name` = '" . $conn->real_escape_string($name) . "'";
                        
                        $conn->query($updateSql);
                    }
                }
            }
            $checkStmt->close();
            $insertStmt->close();
        }

        // --- UPDATE IDLE LOGIC (Inactive Agents) ---
        // We must update agents who are NOT in $activeAgentNames
        // We iterate all agents to do this efficiently or use a WHERE NOT IN query?
        // Using a single query is efficient:
        if (!empty($activeAgentNames)) {
            // Escape names for safety
            $escapedNames = array_map(function($n) use ($conn) { return "'" . $conn->real_escape_string($n) . "'"; }, array_keys($activeAgentNames));
            $nameList = implode(',', $escapedNames);
            
            // Increment idle time for everyone NOT in the active list
            // Only if request time - last_idle_timestamp is small (captured consistently)
            // We use last_idle_timestamp to track 'idle' segments. 
            $idleUpdateSql = "UPDATE `agent data` SET 
                              `today_idle_total` = `today_idle_total` + IF(UNIX_TIMESTAMP() - last_idle_timestamp < 15, UNIX_TIMESTAMP() - last_idle_timestamp, 0),
                              `last_activity_timestamp` = UNIX_TIMESTAMP(), 
                              `last_idle_timestamp` = UNIX_TIMESTAMP()
                              WHERE `name` NOT IN ($nameList) AND last_log_date = CURDATE()"; 
                              // Only update agents we've seen today (log date reset handles this)
            
            $conn->query($idleUpdateSql);
        } else {
             // If NO active calls, EVERYONE is idle (who has logged in today)
             $idleUpdateSql = "UPDATE `agent data` SET 
                              `today_idle_total` = `today_idle_total` + IF(UNIX_TIMESTAMP() - last_idle_timestamp < 15, UNIX_TIMESTAMP() - last_idle_timestamp, 0),
                              `last_activity_timestamp` = UNIX_TIMESTAMP(),
                              `last_idle_timestamp` = UNIX_TIMESTAMP()
                              WHERE last_log_date = CURDATE()";
             $conn->query($idleUpdateSql);
        }

        // --- MERGE LOGIC ---
        // 1. Get all known agents with TOTALS
            $allAgents = [];
            $dbResult = $conn->query("SELECT `name`, `agent_id`, `logg-off`, `today_total`, `yesterday_total`, `today_idle_total`, NOW() as db_now FROM `agent data` ORDER BY `logg-off` ASC");
            while ($row = $dbResult->fetch_assoc()) {
                $allAgents[$row['name']] = $row;
                $currentDbTime = strtotime($row['db_now']); // Capture DB time
            }

            // 2. Map Active Calls 
            $activeCallsMap = [];
            foreach ($data as $call) {
                if (isset($call['agent_name'])) {
                    $activeCallsMap[$call['agent_name']] = $call;
                }
            }

        // 3. Build Final Response
        $finalResponse = [];

        // Helper to formatting seconds
        function formatSeconds($sec) {
            $hours = floor($sec / 3600);
            $minutes = floor(($sec % 3600) / 60);
            $seconds = $sec % 60;
            return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
        }

        // First, add all ACTIVE calls
        foreach ($data as $call) {
             $name = $call['agent_name'];
             // Attach totals from DB if available
             $todayTotal = 0;
             $yesterdayTotal = 0;
             $todayIdle = 0;
             if (isset($allAgents[$name])) {
                 $todayTotal = (int)$allAgents[$name]['today_total'];
                 $yesterdayTotal = (int)$allAgents[$name]['yesterday_total'];
                 $todayIdle = (int)$allAgents[$name]['today_idle_total'];
             }
             
             $call['today_total'] = formatSeconds($todayTotal);
             $call['yesterday_total'] = formatSeconds($yesterdayTotal);
             $call['today_idle_total'] = formatSeconds($todayIdle);
             
             $finalResponse[] = $call;
             unset($allAgents[$name]);
        }

        // Next, add INACTIVE agents
        foreach ($allAgents as $name => $agentInfo) {
            if (isset($activeCallsMap[$name])) continue;

            $logOffTime = $agentInfo['logg-off'];
            $displayTime = "Offline";
            if ($logOffTime) {
                $diff = $currentDbTime - strtotime($logOffTime);
                if ($diff < 0) $diff = 0;
                $hours = floor($diff / 3600);
                $minutes = floor(($diff % 3600) / 60);
                $seconds = $diff % 60;
                $timeStr = sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
                $displayTime = "Offline: <span style='color:#e03d24'>$timeStr</span>";
            }

            $finalResponse[] = [
                "agent_name" => $name,
                "customer_number" => "-",
                "state" => "Not on call",
                "call_time" => $displayTime,
                "today_total" => formatSeconds((int)$agentInfo['today_total']),
                "yesterday_total" => formatSeconds((int)$agentInfo['yesterday_total']),
                "today_idle_total" => formatSeconds((int)$agentInfo['today_idle_total']),
                "user_id" => $agentInfo['agent_id']
            ];
        }
        
        $response = json_encode($finalResponse);
    }
    $conn->close();
}

echo $response;
