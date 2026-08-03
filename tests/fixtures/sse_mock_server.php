<?php
/**
 * Mock OpenAI-compatible upstream for ChatStreamSseTest.
 * stream:true bodies get an SSE relay; everything else a JSON reply.
 */
declare(strict_types=1);

if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/__ping')) {
    echo 'pong';
    return;
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

if (!empty($body['stream'])) {
    header('Content-Type: text/event-stream');
    echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'BYO ']]]]) . "\n\n";
    echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'hello']]]]) . "\n\n";
    echo "data: [DONE]\n\n";
    return;
}

header('Content-Type: application/json');
echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => 'BrainStem reply']]]]);
