<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\Http — Lightweight HTTP + JSON helpers for Galileo Studio.
 *
 * Replaces the retired SystemValidationEngine. Only provides the
 * transport utilities needed by the chat and agent controllers.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class Http
{
    /** POST JSON, return the decoded array or null. Best-effort, one try. */
    public static function postJson(string $endpoint, array $headers, array $payload, int $timeout = 120): ?array
    {
        // stream_context_create expects header as a single \r\n-delimited string.
        $headerStr = implode("\r\n", $headers);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => $headerStr,
                'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($endpoint, false, $ctx);
        if ($raw === false) return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** GET a URL and decode the JSON response. */
    public static function getJson(string $url): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Pull the assistant text out of an OpenAI-style response. */
    public static function extractContent(?array $body): string
    {
        if (!is_array($body)) return '';
        if (isset($body['choices'][0]['message']['content']) && is_string($body['choices'][0]['message']['content'])) {
            return $body['choices'][0]['message']['content'];
        }
        return '';
    }

    /**
     * Robust JSON recovery from model output.
     *
     * Handles: raw JSON, ```json fenced blocks, and embedded {...} objects.
     */
    public static function lenientJson(string $text): ?array
    {
        $text = trim((string) $text);
        if ($text === '') return null;

        $direct = json_decode($text, true);
        if (is_array($direct)) return $direct;

        // Strip ```json fences if present.
        if (preg_match('/```(?:json)?\s*\n([\s\S]*?)(?:```|$)/', $text, $m)) {
            $direct = json_decode(trim($m[1]), true);
            if (is_array($direct)) return $direct;
        }

        // First balanced {...} block (string-aware, naive).
        $depth = 0;
        $inStr = false;
        $esc = false;
        $start = -1;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($esc) { $esc = false; continue; }
            if ($inStr) {
                if ($ch === '\\') { $esc = true; }
                elseif ($ch === '"') { $inStr = false; }
                continue;
            }
            if ($ch === '"') { $inStr = true; continue; }
            if ($ch === '{') {
                if ($start === -1) $start = $i;
                $depth++;
            } elseif ($ch === '}' && $start !== -1) {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($text, $start, $i - $start + 1);
                    $parsed = json_decode($candidate, true);
                    if (is_array($parsed)) return $parsed;
                    $start = -1;
                }
            }
        }
        return null;
    }

    /**
     * Pull a document out of a model response.
     *
     * Prompts ask for plain markdown, so the raw (fence-stripped) text is
     * the primary result; a JSON wrapper is decoded when the model emits one.
     */
    public static function docFromMarkdown(string $content, string $key): string
    {
        $parsed = self::lenientJson($content);
        if (is_array($parsed)) {
            $doc = trim((string) ($parsed[$key] ?? ''));
            if ($doc !== '') {
                if ($doc[0] !== '{') {
                    return $doc;
                }
                $inner = json_decode($doc, true);
                if (is_array($inner)) {
                    $flat = trim(implode("\n\n", array_map('strval', array_values($inner))));
                    if ($flat !== '') {
                        return $flat;
                    }
                }
            }
        }
        $raw = trim($content);
        $raw = preg_replace('/^```(?:json)?\s*\n?/', '', $raw);
        $raw = preg_replace('/\n?```\s*$/', '', $raw);
        $raw = trim($raw);
        if ($raw !== '' && strpos($raw, '"' . $key . '":') !== 0) {
            return $raw;
        }
        return '';
    }
}
