<?php
/**
 * Participants: people who join activities. They type their name once and a
 * long-lived cookie (backed up in localStorage) remembers them on this device.
 */
declare(strict_types=1);

const GM_PARTICIPANT_COOKIE = 'gm_pid';
const GM_PARTICIPANT_TTL = 34560000; // 400 days, the longest browsers allow

/** Well-formed tokens sent with this request: the cookie first, then the localStorage copy. */
function participant_tokens_from_request(): array
{
    $tokens = [];
    foreach ([$_COOKIE[GM_PARTICIPANT_COOKIE] ?? null, $_SERVER['HTTP_X_PARTICIPANT_TOKEN'] ?? null] as $candidate) {
        if (is_string($candidate) && preg_match('/^[a-f0-9]{40}$/', $candidate) && !in_array($candidate, $tokens, true)) {
            $tokens[] = $candidate;
        }
    }
    return $tokens;
}

function participant_set_cookie(string $token): void
{
    setcookie(GM_PARTICIPANT_COOKIE, $token, cookie_options(time() + GM_PARTICIPANT_TTL));
    $_COOKIE[GM_PARTICIPANT_COOKIE] = $token;
}

/** The participant making this request, or null if they haven't told us their name yet. */
function participant_current(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    foreach (participant_tokens_from_request() as $token) {
        $row = q_one('SELECT id, token, name FROM gm_participants WHERE token = ?', [$token]);
        if ($row) {
            // Restore the cookie from the localStorage copy if the browser dropped it.
            if (($_COOKIE[GM_PARTICIPANT_COOKIE] ?? '') !== $token) {
                participant_set_cookie($token);
            }
            $cache = $row;
            break;
        }
    }
    return $cache;
}

function participant_validate_name($name): string
{
    $name = preg_replace('/\s+/u', ' ', clean_text($name, 60)) ?? '';
    if ($name === '') {
        throw new UserError('Please enter your name.', 422, 'name_required');
    }
    return $name;
}

function participant_create(string $name): array
{
    $token = random_token(20);
    $now = now_utc();
    q(
        'INSERT INTO gm_participants (token, name, created_at, last_seen_at) VALUES (?, ?, ?, ?)',
        [$token, $name, $now, $now]
    );
    participant_set_cookie($token);
    return ['id' => (int) db()->lastInsertId(), 'token' => $token, 'name' => $name];
}

function participant_rename(array $participant, string $name): array
{
    q('UPDATE gm_participants SET name = ?, last_seen_at = ? WHERE id = ?', [$name, now_utc(), $participant['id']]);
    $participant['name'] = $name;
    return $participant;
}

function participant_public(?array $p): ?array
{
    return $p ? ['name' => $p['name'], 'token' => $p['token']] : null;
}

function participant_forget(): void
{
    setcookie(GM_PARTICIPANT_COOKIE, '', cookie_options(time() - 3600));
    unset($_COOKIE[GM_PARTICIPANT_COOKIE]);
}
