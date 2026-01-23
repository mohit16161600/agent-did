<?php
header('Content-Type: application/json');

$apiUrl = "https://api-smartflo.tatateleservices.com/v1/live_calls";
$token  = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIyMDYzMTYiLCJjciI6dHJ1ZSwiaXNzIjoiaHR0cHM6Ly9jbG91ZHBob25lLnRhdGF0ZWxlc2VydmljZXMuY29tL3Rva2VuL2dlbmVyYXRlIiwiaWF0IjoxNzY5MDgwNjE5LCJleHAiOjIwNjkwODA2MTksIm5iZiI6MTc2OTA4MDYxOSwianRpIjoiUFB1WHdITWU5a2UyN0c3eSJ9.rrqJy2Ok-vIsJtGBKqfoCwnkMJy37L3wARTQkEaORjo";

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
curl_close($ch);

// Database insertion logic
$conn = new mysqli('127.0.0.1', 'root', '', 'agent_did');
if (!$conn->connect_error) {
    $data = json_decode($response, true);
    if (is_array($data)) {
        // Prepare statements to prevent SQL injection and improve performance
        $checkStmt = $conn->prepare("SELECT `agent_id` FROM `agent data` WHERE `agent_id` = ?");
        $insertStmt = $conn->prepare("INSERT INTO `agent data` (`name`, `agent_id`) VALUES (?, ?)");

        if ($checkStmt && $insertStmt) {
            foreach ($data as $call) {
                $name = $call['agent_name'] ?? null;
                $id = $call['user_id'] ?? null;

                if ($name && $id) {
                    // Check if agent already exists
                    $checkStmt->bind_param("i", $id);
                    $checkStmt->execute();
                    $result = $checkStmt->get_result();

                    if ($result->num_rows === 0) {
                        // Insert new agent
                        $insertStmt->bind_param("si", $name, $id);
                        $insertStmt->execute();
                    }
                }
            }
            $checkStmt->close();
            $insertStmt->close();
        }
    }
    $conn->close();
}

echo $response;
