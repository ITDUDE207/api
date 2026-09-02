<?php
declare(strict_types=1);

final class Handlers
{
    public const TONES = ['professional', 'calm', 'friendly', 'firm', 'apologetic', 'concise', 'warm', 'neutral'];

    public function __construct(private Groq $groq)
    {
    }

    /** @param array<string, mixed> $in */
    public function excuse(array $in): array
    {
        $excuse = self::str($in, 'excuse', 1000, required: true);
        $recipient = self::str($in, 'recipient', 100) ?: 'my manager';
        $sender = self::str($in, 'sender', 100) ?: '';
        $context = self::str($in, 'context', 500) ?: '';
        $tone = self::parseTone($in['tone'] ?? 'professional');
        $honesty = self::str($in, 'honesty', 20) ?: 'honest';
        if (!in_array($honesty, ['honest', 'vague'], true)) {
            Response::error('honesty must be "honest" or "vague"', 422);
        }

        $system = <<<SYS
You turn informal, messy, or embarrassing excuses into polished emails that a real person could send.
Rules:
- Never lie or invent facts. If honesty is "vague", keep the reason truthful but generic (e.g. "a personal matter") without fabricating details.
- Sound like a human, not a template. No corporate buzzwords, no "I hope this email finds you well".
- Take responsibility where appropriate and, if relevant, say what you will do about it (make up the time, reschedule, etc.) only when the input implies it.
- Keep it short: 3-6 sentences in the body.
- Match the requested tone.
Return ONLY a JSON object with keys:
  "subject": string,
  "body": string (plain text, newlines allowed, include greeting and sign-off; use the sender name if given, otherwise "[Your name]"),
  "tip": string (one sentence of practical advice about sending this, e.g. timing or follow-up)
SYS;

        $user = json_encode([
            'raw_excuse' => $excuse,
            'recipient' => $recipient,
            'sender_name' => $sender ?: null,
            'extra_context' => $context ?: null,
            'tone' => $tone,
            'honesty' => $honesty,
        ], JSON_UNESCAPED_UNICODE);

        $out = $this->groq->completeJson($system, (string) $user, 0.6);
        return [
            'subject' => self::outStr($out, 'subject'),
            'body' => self::outStr($out, 'body'),
            'tip' => self::outStr($out, 'tip'),
            'tone' => $tone,
        ];
    }

    /** @param array<string, mixed> $in */
    public function tone(array $in): array
    {
        $text = self::str($in, 'text', 3000, required: true);
        $tone = self::parseTone($in['tone'] ?? 'calm');
        $audience = self::str($in, 'audience', 100) ?: '';

        $system = <<<SYS
You rewrite messages so they land better with the reader while preserving the sender's actual point.
Rules:
- Keep every substantive request, fact, and boundary from the original. Do not soften the message into meaninglessness.
- Remove hostility, sarcasm, passive-aggression, ALL CAPS shouting, and personal attacks.
- Keep roughly the same length and format (if the input is a chat message, output a chat message; if it is an email, keep it an email).
- Match the requested tone.
Return ONLY a JSON object with keys:
  "rewritten": string,
  "changes": array of 2-5 short strings describing what you changed and why,
  "anger_before": integer 0-10 rating how heated the original was,
  "anger_after": integer 0-10 rating the rewrite
SYS;

        $user = json_encode([
            'original_text' => $text,
            'target_tone' => $tone,
            'audience' => $audience ?: null,
        ], JSON_UNESCAPED_UNICODE);

        $out = $this->groq->completeJson($system, (string) $user, 0.5);
        $changes = is_array($out['changes'] ?? null) ? array_values(array_filter($out['changes'], 'is_string')) : [];
        return [
            'rewritten' => self::outStr($out, 'rewritten'),
            'changes' => $changes,
            'anger_before' => self::clampInt($out['anger_before'] ?? null),
            'anger_after' => self::clampInt($out['anger_after'] ?? null),
            'tone' => $tone,
        ];
    }

    /** @param array<string, mixed> $in */
    private static function str(array $in, string $key, int $max, bool $required = false): string
    {
        $v = $in[$key] ?? '';
        if (!is_string($v)) {
            Response::error("$key must be a string", 422);
        }
        $v = trim($v);
        if ($required && $v === '') {
            Response::error("$key is required", 422);
        }
        if (self::length($v) > $max) {
            Response::error("$key must be at most $max characters", 422);
        }
        return $v;
    }

    private static function length(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
    }

    private static function parseTone(mixed $tone): string
    {
        if (!is_string($tone)) {
            Response::error('tone must be a string', 422);
        }
        $tone = strtolower(trim($tone));
        if (!in_array($tone, self::TONES, true)) {
            Response::error('tone must be one of: ' . implode(', ', self::TONES), 422);
        }
        return $tone;
    }

    /** @param array<string, mixed> $out */
    private static function outStr(array $out, string $key): string
    {
        $v = $out[$key] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    private static function clampInt(mixed $v): ?int
    {
        if (!is_numeric($v)) {
            return null;
        }
        return max(0, min(10, (int) $v));
    }
}
