#!/usr/bin/env php
<?php

/**
 * Test script for Team51 CLI MCP Server
 * 
 * This script tests the MCP server by sending sample requests
 */

function sendMCPRequest($request) {
    $json = json_encode($request);
    echo "Sending: $json\n";
    
    $process = proc_open(
        'php mcp-server.php',
        [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ],
        $pipes
    );
    
    if (!is_resource($process)) {
        echo "Failed to start MCP server\n";
        return null;
    }
    
    // Send the request
    fwrite($pipes[0], $json . "\n");
    fclose($pipes[0]);
    
    // Read the response
    $response = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $exitCode = proc_close($process);
    
    if (!empty($errors)) {
        echo "Errors: $errors\n";
    }
    
    return $response;
}

echo "Testing Team51 CLI MCP Server\n";
echo "============================\n\n";

// Test 1: Initialize
echo "1. Testing initialize...\n";
$response = sendMCPRequest([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => '2024-11-05',
        'capabilities' => [],
        'clientInfo' => [
            'name' => 'test-client',
            'version' => '1.0.0'
        ]
    ]
]);
echo "Response: $response\n\n";

// Test 2: List tools
echo "2. Testing tools/list...\n";
$response = sendMCPRequest([
    'jsonrpc' => '2.0',
    'id' => 2,
    'method' => 'tools/list'
]);
echo "Response: $response\n\n";

// Test 3: Call a simple tool (if available)
echo "3. Testing tools/call with help command...\n";
$response = sendMCPRequest([
    'jsonrpc' => '2.0',
    'id' => 3,
    'method' => 'tools/call',
    'params' => [
        'name' => 'team51_wpcom_list_sites',
        'arguments' => [
            'format' => 'json'
        ]
    ]
]);
echo "Response: $response\n\n";

echo "MCP Server test completed.\n";
