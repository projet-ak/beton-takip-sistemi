<?php
/**
 * ai_call.php — Ortak AI çağrı katmanı (Claude ve Gemini desteği)
 *
 * Kullanım:
 *   $result = ai_call($systemPrompt, $parts, $maxTokens);
 *   if ($result['ok']) echo $result['text'];
 *   else              echo $result['msg'];
 *
 * $parts her biri şu formda olmalı:
 *   ['type' => 'text',     'text' => '...']
 *   ['type' => 'image',    'mime' => 'image/jpeg', 'data' => '<base64>']
 *   ['type' => 'document', 'data' => '<base64>']      (PDF)
 */

function ai_call(string $system, array $parts, int $maxTokens = 512): array
{
    if (!defined('AI_PROVIDER') || AI_PROVIDER === '') {
        return ['ok' => false, 'msg' => 'AI_PROVIDER tanımlı değil (config.php)'];
    }
    return match (AI_PROVIDER) {
        'claude'     => _ai_claude($system, $parts, $maxTokens),
        'gemini'     => _ai_gemini($system, $parts, $maxTokens),
        'openrouter' => _ai_openrouter($system, $parts, $maxTokens),
        default      => ['ok' => false, 'msg' => 'Geçersiz AI_PROVIDER: ' . AI_PROVIDER],
    };
}

// ── Claude (Haiku 4.5) ──────────────────────────────────────────────────────
function _ai_claude(string $system, array $parts, int $maxTokens): array
{
    if (!defined('CLAUDE_API_KEY') || CLAUDE_API_KEY === '') {
        return ['ok' => false, 'msg' => 'CLAUDE_API_KEY yapılandırılmamış'];
    }

    $content = [];
    foreach ($parts as $p) {
        $content[] = match ($p['type']) {
            'text'     => ['type' => 'text', 'text' => $p['text']],
            'image'    => ['type' => 'image',    'source' => ['type' => 'base64', 'media_type' => $p['mime'], 'data' => $p['data']]],
            'document' => ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $p['data']]],
            default    => null,
        };
    }
    $content = array_filter($content);

    $payload = ['model' => 'claude-haiku-4-5', 'max_tokens' => $maxTokens, 'messages' => [['role' => 'user', 'content' => array_values($content)]]];
    if ($system !== '') $payload['system'] = $system;

    return _ai_http(
        'https://api.anthropic.com/v1/messages',
        $payload,
        ['x-api-key: ' . CLAUDE_API_KEY, 'anthropic-version: 2023-06-01', 'Content-Type: application/json'],
        fn($d) => $d['content'][0]['text'] ?? null,
        fn($d) => 'Claude API hatası: ' . ($d['error']['message'] ?? 'bilinmiyor')
    );
}

// ── Gemini 1.5 Flash ────────────────────────────────────────────────────────
function _ai_gemini(string $system, array $parts, int $maxTokens): array
{
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
        return ['ok' => false, 'msg' => 'GEMINI_API_KEY yapılandırılmamış'];
    }

    $gParts = [];
    foreach ($parts as $p) {
        $gParts[] = match ($p['type']) {
            'text'                 => ['text' => $p['text']],
            'image', 'document'    => ['inline_data' => ['mime_type' => $p['type'] === 'image' ? $p['mime'] : 'application/pdf', 'data' => $p['data']]],
            default                => null,
        };
    }
    $gParts = array_filter($gParts);

    $payload = [
        'contents'          => [['parts' => array_values($gParts)]],
        'generationConfig'  => ['maxOutputTokens' => $maxTokens],
    ];
    if ($system !== '') {
        $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode(GEMINI_API_KEY);

    return _ai_http(
        $url,
        $payload,
        ['Content-Type: application/json'],
        fn($d) => $d['candidates'][0]['content']['parts'][0]['text'] ?? null,
        fn($d) => 'Gemini API hatası: ' . ($d['error']['message'] ?? 'bilinmiyor')
    );
}

// ── OpenRouter (Qwen / DeepSeek / Llama — ücretsiz modeller) ───────────────
function _ai_openrouter(string $system, array $parts, int $maxTokens): array
{
    if (!defined('OPENROUTER_API_KEY') || OPENROUTER_API_KEY === '') {
        return ['ok' => false, 'msg' => 'OPENROUTER_API_KEY yapılandırılmamış'];
    }

    // Görsel içerik var mı? → vision modeli seç
    $hasMedia = false;
    foreach ($parts as $p) {
        if (in_array($p['type'], ['image', 'document'], true)) { $hasMedia = true; break; }
    }
    $model = $hasMedia
        ? 'google/gemma-4-26b-a4b-it:free'             // görsel OCR
        : 'meta-llama/llama-3.3-70b-instruct:free';    // metin Q&A

    $content = [];
    foreach ($parts as $p) {
        if ($p['type'] === 'text') {
            $content[] = ['type' => 'text', 'text' => $p['text']];
        } elseif ($p['type'] === 'image') {
            $content[] = ['type' => 'image_url', 'image_url' => [
                'url' => 'data:' . $p['mime'] . ';base64,' . $p['data'],
            ]];
        } elseif ($p['type'] === 'document') {
            return ['ok' => false, 'msg' => 'PDF, OpenRouter ile desteklenmiyor. Lütfen fotoğraf (JPG/PNG) yükleyin veya Claude sağlayıcısına geçin.'];
        }
    }

    $messages = [];
    if ($system !== '') $messages[] = ['role' => 'system', 'content' => $system];
    $messages[] = ['role' => 'user', 'content' => $content];

    $payload = [
        'model'      => $model,
        'max_tokens' => $maxTokens,
        'messages'   => $messages,
    ];

    return _ai_http(
        'https://openrouter.ai/api/v1/chat/completions',
        $payload,
        [
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'Content-Type: application/json',
            'HTTP-Referer: https://takbulut.com/beton/',
            'X-Title: Beton Takip Sistemi',
        ],
        fn($d) => $d['choices'][0]['message']['content'] ?? null,
        fn($d) => 'OpenRouter API hatası: ' . ($d['error']['message'] ?? 'bilinmiyor')
    );
}

// ── Ortak HTTP yardımcısı ───────────────────────────────────────────────────
function _ai_http(string $url, array $payload, array $headers, callable $extractText, callable $extractErr): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['ok' => false, 'msg' => 'cURL hatası: ' . $curlErr];

    $decoded = json_decode($response, true);
    $text    = $decoded !== null ? $extractText($decoded) : null;

    if ($httpCode !== 200 || $text === null) {
        return ['ok' => false, 'msg' => $decoded !== null ? $extractErr($decoded) : ('HTTP ' . $httpCode)];
    }

    return ['ok' => true, 'text' => $text];
}
