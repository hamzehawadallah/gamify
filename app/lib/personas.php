<?php
/**
 * Personas: the emoji each participant picks to represent them. A persona
 * belongs to one person at a time. While someone is choosing, the persona
 * we suggest is held for them so two people never get the same default.
 */
declare(strict_types=1);

const GM_PERSONA_KEEP_HOURS = 12;   // an owner keeps their persona while active this recently
const GM_PERSONA_HOLD_MINUTES = 15; // a suggested persona is held this long before it's confirmed

/**
 * The personas on offer, in the order they're suggested: single people only
 * (jobs, characters, looks), all widely supported by phones and Windows.
 * [key => [emoji, label]]
 */
function persona_catalog(): array
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }
    $list = [
        ['man-office', '1F468 200D 1F4BC', 'Man office worker'],
        ['woman-office', '1F469 200D 1F4BC', 'Woman office worker'],
        ['man-hero', '1F9B8 200D 2642 FE0F', 'Man superhero'],
        ['woman-hero', '1F9B8 200D 2640 FE0F', 'Woman superhero'],
        ['man-astronaut', '1F468 200D 1F680', 'Man astronaut'],
        ['woman-astronaut', '1F469 200D 1F680', 'Woman astronaut'],
        ['man-scientist', '1F468 200D 1F52C', 'Man scientist'],
        ['woman-headscarf', '1F9D5', 'Woman with headscarf'],
        ['man-turban', '1F473 200D 2642 FE0F', 'Man with turban'],
        ['woman-scientist', '1F469 200D 1F52C', 'Woman scientist'],
        ['man-technologist', '1F468 200D 1F4BB', 'Man technologist'],
        ['woman-technologist', '1F469 200D 1F4BB', 'Woman technologist'],
        ['man-teacher', '1F468 200D 1F3EB', 'Man teacher'],
        ['woman-teacher', '1F469 200D 1F3EB', 'Woman teacher'],
        ['man-pilot', '1F468 200D 2708 FE0F', 'Man pilot'],
        ['woman-pilot', '1F469 200D 2708 FE0F', 'Woman pilot'],
        ['man-artist', '1F468 200D 1F3A8', 'Man artist'],
        ['woman-artist', '1F469 200D 1F3A8', 'Woman artist'],
        ['man-cook', '1F468 200D 1F373', 'Man cook'],
        ['woman-cook', '1F469 200D 1F373', 'Woman cook'],
        ['man-mage', '1F9D9 200D 2642 FE0F', 'Man mage'],
        ['woman-mage', '1F9D9 200D 2640 FE0F', 'Woman mage'],
        ['man-detective', '1F575 FE0F 200D 2642 FE0F', 'Man detective'],
        ['woman-detective', '1F575 FE0F 200D 2640 FE0F', 'Woman detective'],
        ['man-health', '1F468 200D 2695 FE0F', 'Man health worker'],
        ['woman-health', '1F469 200D 2695 FE0F', 'Woman health worker'],
        ['man-student', '1F468 200D 1F393', 'Man student'],
        ['woman-student', '1F469 200D 1F393', 'Woman student'],
        ['man-singer', '1F468 200D 1F3A4', 'Man singer'],
        ['woman-singer', '1F469 200D 1F3A4', 'Woman singer'],
        ['man-firefighter', '1F468 200D 1F692', 'Man firefighter'],
        ['woman-firefighter', '1F469 200D 1F692', 'Woman firefighter'],
        ['man-mechanic', '1F468 200D 1F527', 'Man mechanic'],
        ['woman-mechanic', '1F469 200D 1F527', 'Woman mechanic'],
        ['man-judge', '1F468 200D 2696 FE0F', 'Man judge'],
        ['woman-judge', '1F469 200D 2696 FE0F', 'Woman judge'],
        ['man-farmer', '1F468 200D 1F33E', 'Man farmer'],
        ['woman-farmer', '1F469 200D 1F33E', 'Woman farmer'],
        ['man-factory', '1F468 200D 1F3ED', 'Man factory worker'],
        ['woman-factory', '1F469 200D 1F3ED', 'Woman factory worker'],
        ['man-construction', '1F477 200D 2642 FE0F', 'Man construction worker'],
        ['woman-construction', '1F477 200D 2640 FE0F', 'Woman construction worker'],
        ['man-police', '1F46E 200D 2642 FE0F', 'Man police officer'],
        ['woman-police', '1F46E 200D 2640 FE0F', 'Woman police officer'],
        ['man-guard', '1F482 200D 2642 FE0F', 'Man guard'],
        ['woman-guard', '1F482 200D 2640 FE0F', 'Woman guard'],
        ['man-elf', '1F9DD 200D 2642 FE0F', 'Man elf'],
        ['woman-elf', '1F9DD 200D 2640 FE0F', 'Woman elf'],
        ['man-genie', '1F9DE 200D 2642 FE0F', 'Man genie'],
        ['woman-genie', '1F9DE 200D 2640 FE0F', 'Woman genie'],
        ['prince', '1F934', 'Prince'],
        ['princess', '1F478', 'Princess'],
        ['man-tuxedo', '1F935', 'Man in tuxedo'],
        ['woman-turban', '1F473 200D 2640 FE0F', 'Woman with turban'],
        ['man-beard', '1F9D4', 'Man with beard'],
        ['man', '1F468', 'Man'],
        ['woman', '1F469', 'Woman'],
        ['man-blond', '1F471 200D 2642 FE0F', 'Blond man'],
        ['woman-blond', '1F471 200D 2640 FE0F', 'Blond woman'],
        ['man-red-hair', '1F468 200D 1F9B0', 'Man with red hair'],
        ['woman-red-hair', '1F469 200D 1F9B0', 'Woman with red hair'],
        ['man-curly', '1F468 200D 1F9B1', 'Man with curly hair'],
        ['woman-curly', '1F469 200D 1F9B1', 'Woman with curly hair'],
        ['man-white-hair', '1F468 200D 1F9B3', 'Man with white hair'],
        ['woman-white-hair', '1F469 200D 1F9B3', 'Woman with white hair'],
        ['man-bald', '1F468 200D 1F9B2', 'Bald man'],
        ['old-man', '1F474', 'Old man'],
        ['old-woman', '1F475', 'Old woman'],
        ['boy', '1F466', 'Boy'],
        ['girl', '1F467', 'Girl'],
        ['cowboy', '1F920', 'Cowboy'],
    ];
    $catalog = [];
    foreach ($list as [$key, $codepoints, $label]) {
        $emoji = '';
        foreach (explode(' ', $codepoints) as $cp) {
            $emoji .= mb_chr((int) hexdec($cp), 'UTF-8');
        }
        $catalog[$key] = ['emoji' => $emoji, 'label' => $label];
    }
    return $catalog;
}

function persona_emoji(?string $key): ?string
{
    return $key !== null ? (persona_catalog()[$key]['emoji'] ?? null) : null;
}

function persona_valid_hold($hold): string
{
    return is_string($hold) && preg_match('/^[a-f0-9]{32}$/', $hold) ? $hold : random_token(16);
}

function persona_keep_cutoff(): string
{
    return gmdate('Y-m-d H:i:s', time() - GM_PERSONA_KEEP_HOURS * 3600);
}

/**
 * Who has what right now.
 * Returns [taken => [key => true], stale => [key => owner last seen]]:
 * "taken" personas belong to someone active (or are held for someone else);
 * "stale" ones belong to people who haven't been around for a while.
 */
function persona_usage(?int $meId, string $hold): array
{
    $cutoff = persona_keep_cutoff();
    $taken = [];
    $stale = [];
    // At most one owner per persona, so this stays as small as the catalog.
    foreach (q_all('SELECT id, persona, last_seen_at FROM gm_participants WHERE persona IS NOT NULL') as $row) {
        if ((int) $row['id'] === $meId) {
            continue;
        }
        if ($row['last_seen_at'] >= $cutoff) {
            $taken[$row['persona']] = true;
        } else {
            $stale[$row['persona']] = $row['last_seen_at'];
        }
    }
    foreach (q_all('SELECT persona FROM gm_persona_holds WHERE expires_at > ? AND hold_token <> ?', [now_utc(), $hold]) as $row) {
        $taken[$row['persona']] = true;
    }
    return ['taken' => $taken, 'stale' => $stale];
}

/** Hold $key for this visitor (and let go of anything else they held). True if it's theirs now. */
function persona_hold(string $key, string $hold, ?int $meId): bool
{
    $now = now_utc();
    $until = gmdate('Y-m-d H:i:s', time() + GM_PERSONA_HOLD_MINUTES * 60);
    // Atomic: take the row if it's free, expired or already ours; otherwise leave it alone.
    q(
        'INSERT INTO gm_persona_holds (persona, hold_token, expires_at) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
           hold_token = IF(expires_at <= ? OR hold_token = VALUES(hold_token), VALUES(hold_token), hold_token),
           expires_at = IF(hold_token = VALUES(hold_token), VALUES(expires_at), expires_at)',
        [$key, $hold, $until, $now]
    );
    if (q_val('SELECT hold_token FROM gm_persona_holds WHERE persona = ?', [$key]) !== $hold) {
        return false;
    }
    // Someone may have confirmed it a moment ago (confirming releases the hold).
    $owned = q_val(
        'SELECT 1 FROM gm_participants WHERE persona = ? AND id <> ? AND last_seen_at >= ?',
        [$key, $meId ?? 0, persona_keep_cutoff()]
    );
    if ($owned) {
        q('DELETE FROM gm_persona_holds WHERE persona = ? AND hold_token = ?', [$key, $hold]);
        return false;
    }
    q('DELETE FROM gm_persona_holds WHERE hold_token = ? AND persona <> ?', [$hold, $key]);
    return true;
}

/**
 * The picker's list for this visitor: the personas they can choose, with the
 * selected one first. The selection is their own persona, the one they asked
 * for ($want), the one already held for them, or the next free one — which is
 * then held so nobody else is offered it.
 */
function persona_choices(?array $me, string $hold, ?string $want = null): array
{
    return db_retry(static fn() => persona_pick($me, $hold, $want));
}

function persona_pick(?array $me, string $hold, ?string $want): array
{
    if (random_int(1, 20) === 1) {
        // Expired holds are ignored and overwritten anyway; tidy up now and then.
        q('DELETE FROM gm_persona_holds WHERE expires_at <= ?', [now_utc()]);
    }
    $catalog = persona_catalog();
    $meId = $me ? (int) $me['id'] : null;
    $usage = persona_usage($meId, $hold);
    $free = static fn(string $key): bool => isset($catalog[$key]) && !isset($usage['taken'][$key]);

    $mine = $me['persona'] ?? null;
    $mine = $mine !== null && $free($mine) ? $mine : null;
    $held = q_val('SELECT persona FROM gm_persona_holds WHERE hold_token = ? AND expires_at > ?', [$hold, now_utc()]);
    $held = is_string($held) && $free($held) ? $held : null;

    $selected = null;
    if ($want !== null && $free($want) && ($want === $mine || persona_hold($want, $hold, $meId))) {
        $selected = $want;
    } elseif ($want === null && $mine !== null) {
        $selected = $mine;
    } else {
        $selected = $held ?? $mine; // a pick that just got taken falls back to the previous one
    }
    if ($selected !== null && $selected === $mine) {
        q('DELETE FROM gm_persona_holds WHERE hold_token = ?', [$hold]); // their own needs no hold
    }
    if ($selected === null) {
        // Suggest personas nobody owns first, then those whose owners have been away longest.
        $candidates = array_values(array_filter(array_keys($catalog), $free));
        usort($candidates, static function ($a, $b) use ($usage, $catalog) {
            $sa = $usage['stale'][$a] ?? '';
            $sb = $usage['stale'][$b] ?? '';
            return $sa === $sb ? 0 : ($sa === '' ? -1 : ($sb === '' ? 1 : strcmp($sa, $sb)));
        });
        foreach ($candidates as $key) {
            if (persona_hold($key, $hold, $meId)) {
                $selected = $key;
                break;
            }
        }
    }

    $list = [];
    foreach ($catalog as $key => $p) {
        if ($key !== $selected && $free($key)) {
            $list[] = ['key' => $key, 'emoji' => $p['emoji'], 'label' => $p['label']];
        }
    }
    if ($selected !== null) {
        array_unshift($list, ['key' => $selected, 'emoji' => $catalog[$selected]['emoji'], 'label' => $catalog[$selected]['label']]);
    }
    return ['personas' => $list, 'selected' => $selected, 'hold' => $hold];
}

/**
 * Give $key to participant $meId. Call inside a transaction. Fails if someone
 * active owns it or it's held for someone else; takes it over from an owner
 * who has been away longer than GM_PERSONA_KEEP_HOURS.
 */
function persona_claim(int $meId, string $key, string $hold): void
{
    if (!isset(persona_catalog()[$key])) {
        throw new UserError('Please pick one of the personas shown.', 422, 'persona_invalid');
    }
    $taken = new UserError('Someone just picked that persona. Please choose another.', 409, 'persona_taken');
    $owner = q_one('SELECT id, last_seen_at FROM gm_participants WHERE persona = ? FOR UPDATE', [$key]);
    if ($owner && (int) $owner['id'] !== $meId && $owner['last_seen_at'] >= persona_keep_cutoff()) {
        throw $taken;
    }
    $heldBy = q_val('SELECT hold_token FROM gm_persona_holds WHERE persona = ? AND expires_at > ? FOR UPDATE', [$key, now_utc()]);
    if (is_string($heldBy) && $heldBy !== $hold) {
        throw $taken;
    }
    if ($owner && (int) $owner['id'] !== $meId) {
        q('UPDATE gm_participants SET persona = NULL WHERE id = ?', [$owner['id']]);
    }
    try {
        q('UPDATE gm_participants SET persona = ? WHERE id = ?', [$key, $meId]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw $taken;
        }
        throw $e;
    }
    q('DELETE FROM gm_persona_holds WHERE hold_token = ? OR persona = ?', [$hold, $key]);
}
