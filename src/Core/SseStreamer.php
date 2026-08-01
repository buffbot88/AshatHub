<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\SseStreamer — Server-Sent Events streaming utility.
 *
 * Extracted from Controllers\ChatController so the streaming logic
 * (headers, event formatting, upstream proxying) is testable and
 * reusable by any controller that needs SSE output.
 *
 * Usage:
 *   SseStreamer::headers();                    // emit SSE content-type + flush config
 *   SseStreamer::send('progress', ['pct'=>50]); // one event
 *   $content = SseStreamer::proxy($url, $headers, $payload); // relay upstream SSE
 *   if ($content !== null) {
 *       SseStreamer::send('done', ['full_content' => $content]);
 *   }
 *
 * Testing (capture output with ob_start):
 *   ob_start();
 *   SseStreamer::send('test', ['msg' => 'hi']);
 *   $output = ob_get_clean();
 *   assert(str_contains($output, 'event: test'));
 *   assert(str_contains($output, '"msg":"hi"'));
 * ═══════════════════════════════════════════════════════════════════════
 */
final class SseStreamer
{
    /**
     * Emit SSE headers and configure output flushing.
     * Call once before any send() or proxy() call.
     */
    public static function headers(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        // Discard any pre-existing buffered content (stray echoes/notices)
        // WITHOUT closing the outermost buffer — callers capturing output
        // (and PHPUnit itself) keep their own buffer open. Closing foreign
        // buffers here marks PHPUnit tests as risky and breaks stream
        // capture in production code that wraps output.
        if (ob_get_level() > 0) {
            ob_clean();
        }
        ob_implicit_flush(true);
    }

    /**
     * Format and send a single SSE event.
     *
     * @param string $event Event type name (e.g. 'error', 'done')
     * @param array  $data  JSON-serializable payload
     */
    public static function send(string $event, array $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * Open a streaming POST connection to an upstream SSE endpoint and
     * relay every line to the client verbatim.
     *
     * On error (connection failure, non-200 status), sends an 'error'
     * SSE event and returns null.
     *
     * On success, relays all lines and returns the accumulated message
     * content so the caller can send a terminal 'done' event.
     *
     * @param string $endpoint  Upstream URL
     * @param array  $headers   HTTP headers (raw strings, e.g. ['Content-Type: application/json'])
     * @param array  $payload   JSON body to POST
     * @return string|null  Accumulated content on success, null on error
     */
    /** Max upstream attempts for transient failures (429 / 5xx). */
    private const MAX_ATTEMPTS = 3;

    /** Seconds to sleep between attempts (attempt # → delay). */
    private const BACKOFF = [1 => 1, 2 => 3];

    public static function proxy(string $endpoint, array $headers, array $payload): ?string
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            $streamCtx = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => $headers,
                    'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'timeout'       => 120,
                    'ignore_errors' => true,
                ],
            ]);

            $stream = @fopen($endpoint, 'r', false, $streamCtx);
            if (!$stream) {
                // Connection-level failure — fail fast. Transient "Loading
                // model" 503s always arrive as real HTTP responses and are
                // retried via the status check below instead.
                self::send('error', ['message' => 'Could not connect to the upstream API endpoint.']);
                return null;
            }

            // Check HTTP status from the response header
            $statusLine = $http_response_header[0] ?? '';
            $status = self::statusCode($statusLine);

            if ($status !== 0 && ($status < 200 || $status >= 300)) {
                $body = (string) stream_get_contents($stream);
                fclose($stream);

                // Transient upstream failures (429 / 5xx) — e.g. OpenAI-style
                // "Loading model" 503s that clear once the provider has
                // finished cold-starting the model. Retry with backoff.
                if ($attempt < self::MAX_ATTEMPTS && self::isTransient($status)) {
                    sleep(self::BACKOFF[$attempt] ?? 2);
                    continue;
                }

                $errData = json_decode($body, true);
                $msg = $errData['error']['message'] ?? $errData['message'] ?? $statusLine;
                self::send('error', ['message' => self::friendlyError($msg)]);
                return null;
            }

            // 2xx (or unknown status) — relay the stream.
            break;
        }

        $fullContent = '';

        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                break;
            }

            $trimmed = trim($line);
            echo $line;

            // Accumulate delta content from OpenAI-style SSE chunks
            if (str_starts_with($trimmed, 'data: ')) {
                $dataBody = substr($trimmed, 6);
                if ($dataBody === '[DONE]') {
                    continue;
                }

                $json = json_decode($dataBody, true);
                if ($json && isset($json['choices'][0]['delta']['content'])) {
                    $fullContent .= $json['choices'][0]['delta']['content'];
                }
            }

            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }

        fclose($stream);
        return $fullContent;
    }

    /** Extract the numeric HTTP status from a status line ("HTTP/1.1 200 OK"). */
    private static function statusCode(string $statusLine): int
    {
        if (preg_match('/\s(\d{3})\s/', $statusLine, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /** Whether a status is a transient upstream failure worth retrying. */
    private static function isTransient(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /** Human-friendly replacement for known transient provider messages. */
    private static function friendlyError(string $msg): string
    {
        if (stripos($msg, 'loading model') !== false || stripos($msg, 'model is loading') !== false) {
            return 'The AI model is still loading. Give it a moment and try again.';
        }
        return $msg;
    }
}
