<?php
// Hostforge Health Check Endpoint
// Lightweight - no DB connection, no session, no includes
http_response_code(200);
header("Content-Type: application/json");
echo json_encode(["status" => "ok", "service" => "SMS2"]);
