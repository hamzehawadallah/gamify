<?php
/**
 * Activities (polls today; built so new types — feedback, quiz, word cloud —
 * can be added alongside) together with their options, votes and live results.
 */
declare(strict_types=1);

const GM_ACTIVITY_TYPES = ['poll'];
const GM_MAX_OPTIONS = 20;
const GM_LIVE_WAIT_SECONDS = 15;

function poll_default_settings(): array
{
    return [
        'multiple' => false,      // allow choosing more than one option
        'max_choices' => 0,       // 0 = no limit (only when multiple)
        'allow_change' => true,   // participants can change their vote while voting is open
        'show_results' => false,  // show live results on participants' phones after voting
        'show_qr' => true,        // show the QR code on the presenter screen
        'show_voters' => true,    // show voters' initials on the presenter screen
    ];
}

function activity_settings(array $activity): array
{
    $saved = json_decode((string) ($activity['settings'] ?? ''), true);
    return array_merge(poll_default_settings(), is_array($saved) ? $saved : []);
}

function activity_normalize_settings($input): array
{
    $input = is_array($input) ? $input : [];
    $defaults = poll_default_settings();
    $out = [];
    foreach ($defaults as $key => $default) {
        if (is_bool($default)) {
            $out[$key] = array_key_exists($key, $input) ? filter_var($input[$key], FILTER_VALIDATE_BOOLEAN) : $default;
        } else {
            $out[$key] = array_key_exists($key, $input) ? max(0, min(GM_MAX_OPTIONS, (int) $input[$key])) : $default;
        }
    }
    if (!$out['multiple']) {
        $out['max_choices'] = 0;
    }
    return $out;
}

function activity_by_code(string $code): ?array
{
    return q_one('SELECT * FROM gm_activities WHERE code = ?', [$code]);
}

function activity_by_id(int $id): ?array
{
    return q_one('SELECT * FROM gm_activities WHERE id = ?', [$id]);
}

function activity_options(int $activityId): array
{
    return q_all(
        'SELECT id, label, image, position FROM gm_options WHERE activity_id = ? ORDER BY position, id',
        [$activityId]
    );
}

function image_url(?string $image): ?string
{
    return $image ? url('uploads/' . $image) : null;
}

function activity_join_url(array $activity): string
{
    return absolute_url($activity['code']);
}

function activity_present_url(array $activity): string
{
    return absolute_url('present/' . $activity['code'] . '?key=' . $activity['present_key']);
}

/** "vote.example.com/123456" — what people can type if they can't scan. */
function activity_join_display(array $activity): string
{
    return preg_replace('#^https?://(www\.)?#i', '', activity_join_url($activity));
}

/** What participants are allowed to see about an activity. */
function activity_public(array $activity, array $options): array
{
    $s = activity_settings($activity);
    return [
        'code' => $activity['code'],
        'type' => $activity['type'],
        'title' => $activity['title'],
        'description' => (string) ($activity['description'] ?? ''),
        'status' => $activity['status'],
        'results_hidden' => (bool) $activity['results_hidden'],
        'settings' => [
            'multiple' => $s['multiple'],
            'max_choices' => $s['max_choices'],
            'allow_change' => $s['allow_change'],
            'show_results' => $s['show_results'],
            'show_qr' => $s['show_qr'],
            'show_voters' => $s['show_voters'],
        ],
        'options' => array_map(static function ($o) {
            return ['id' => (int) $o['id'], 'label' => $o['label'], 'image' => image_url($o['image'])];
        }, $options),
    ];
}

function activity_results(array $activity, array $options): array
{
    $counts = [];
    foreach (q_all(
        'SELECT option_id, COUNT(*) AS c FROM gm_responses WHERE activity_id = ? GROUP BY option_id',
        [$activity['id']]
    ) as $row) {
        $counts[(int) $row['option_id']] = (int) $row['c'];
    }
    $voters = (int) q_val('SELECT COUNT(DISTINCT participant_id) FROM gm_responses WHERE activity_id = ?', [$activity['id']]);
    return [
        'voters' => $voters,
        'votes' => array_sum($counts),
        'counts' => array_map(static function ($o) use ($counts) {
            return ['id' => (int) $o['id'], 'count' => $counts[(int) $o['id']] ?? 0];
        }, $options),
    ];
}

/** Most recent voters, newest first. Presenter only gets initials, the admin gets names. */
function activity_recent_voters(int $activityId, int $limit = 14): array
{
    return q_all(
        'SELECT p.id, p.name, MAX(r.created_at) AS voted_at
           FROM gm_responses r JOIN gm_participants p ON p.id = r.participant_id
          WHERE r.activity_id = ?
          GROUP BY p.id, p.name
          ORDER BY voted_at DESC, p.id DESC
          LIMIT ' . max(1, min(50, $limit)),
        [$activityId]
    );
}

function initials(string $name): string
{
    $words = preg_split('/\s+/u', trim($name)) ?: [];
    $out = '';
    foreach (array_slice(array_values(array_filter($words, 'strlen')), 0, 2) as $w) {
        $out .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    return $out !== '' ? $out : '?';
}

/** Everything a live screen needs. $admin = true includes hidden results and names. */
function activity_live_payload(array $activity, bool $admin = false): array
{
    $options = activity_options((int) $activity['id']);
    $settings = activity_settings($activity);
    $hidden = !$admin && (bool) $activity['results_hidden'];
    $results = activity_results($activity, $options);
    if ($hidden) {
        $results['counts'] = null;
        $results['votes'] = null;
    }

    $voters = [];
    if ($admin || $settings['show_voters']) {
        foreach (activity_recent_voters((int) $activity['id']) as $v) {
            $voters[] = $admin
                ? ['id' => (int) $v['id'], 'name' => $v['name'], 'initials' => initials($v['name'])]
                : ['id' => (int) $v['id'], 'initials' => initials($v['name'])];
        }
    }

    $payload = [
        'version' => (int) $activity['version'],
        'activity' => activity_public($activity, $options),
        'results' => $results,
        'recent_voters' => $voters,
        'join' => [
            'url' => activity_join_url($activity),
            'display' => activity_join_display($activity),
            'code' => $activity['code'],
        ],
    ];
    if ($admin) {
        $payload['responses'] = activity_response_rows((int) $activity['id'], $options, 500);
    }
    return $payload;
}

/** One row per participant with the option(s) they picked, newest first. */
function activity_response_rows(int $activityId, array $options, int $limit = 0): array
{
    $sql = 'SELECT r.participant_id, p.name, r.option_id, r.created_at
              FROM gm_responses r JOIN gm_participants p ON p.id = r.participant_id
             WHERE r.activity_id = ?
             ORDER BY r.created_at DESC, r.id DESC';
    $rows = [];
    foreach (q_all($sql, [$activityId]) as $r) {
        $pid = (int) $r['participant_id'];
        if (!isset($rows[$pid])) {
            if ($limit > 0 && count($rows) >= $limit) {
                continue;
            }
            $rows[$pid] = ['participant_id' => $pid, 'name' => $r['name'], 'option_ids' => [], 'at' => iso_utc($r['created_at'])];
        }
        $rows[$pid]['option_ids'][] = (int) $r['option_id'];
    }
    $order = array_flip(array_map(static fn($o) => (int) $o['id'], $options));
    foreach ($rows as &$row) {
        usort($row['option_ids'], static fn($a, $b) => ($order[$a] ?? 0) <=> ($order[$b] ?? 0));
    }
    return array_values($rows);
}

/** The option ids this participant picked, or [] if they haven't voted. */
function activity_my_choice(int $activityId, int $participantId): array
{
    return array_map('intval', array_column(q_all(
        'SELECT option_id FROM gm_responses WHERE activity_id = ? AND participant_id = ? ORDER BY id',
        [$activityId, $participantId]
    ), 'option_id'));
}

/**
 * Long-poll: wait until the activity's version differs from $since (or the
 * timeout passes). Cheap primary-key lookups every 250ms; returns the fresh row.
 */
function activity_wait_for_change(int $id, int $since, int $timeout = GM_LIVE_WAIT_SECONDS): ?array
{
    if (function_exists('set_time_limit')) {
        @set_time_limit($timeout + 15);
    }
    $deadline = microtime(true) + $timeout;
    $stmt = db()->prepare('SELECT version FROM gm_activities WHERE id = ?');
    while (true) {
        $stmt->execute([$id]);
        $version = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($version === false) {
            return null;
        }
        if ((int) $version !== $since || microtime(true) >= $deadline) {
            break;
        }
        usleep(250000);
    }
    return activity_by_id($id);
}

function activity_bump(int $id): void
{
    q('UPDATE gm_activities SET version = version + 1, updated_at = ? WHERE id = ?', [now_utc(), $id]);
}

function activity_new_code(): string
{
    for ($i = 0; $i < 50; $i++) {
        $code = (string) random_int(100000, 999999);
        if (!q_val('SELECT 1 FROM gm_activities WHERE code = ?', [$code])) {
            return $code;
        }
    }
    throw new RuntimeException('Could not generate a unique activity code.');
}

/**
 * Record a vote. Locks the activity row so votes are counted one at a time and
 * the version bump that wakes up presenter screens is always consistent. All
 * rules are checked after the lock, so an edit made at the same moment can't
 * slip through.
 */
function activity_vote(array $activity, array $participant, $optionIds): array
{
    $optionIds = array_values(array_unique(array_map('intval', is_array($optionIds) ? $optionIds : [])));
    if (!$optionIds) {
        throw new UserError('Pick an option first.', 422, 'empty');
    }

    return db_tx(static function (PDO $pdo) use ($activity, $participant, $optionIds) {
        $id = (int) $activity['id'];
        $lock = $pdo->prepare('SELECT * FROM gm_activities WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        $locked = $lock->fetch();
        if (!$locked) {
            throw new UserError('This activity no longer exists.', 404, 'not_found');
        }
        if ($locked['status'] !== 'open') {
            throw new UserError('Voting is closed for this activity.', 409, 'closed');
        }
        $settings = activity_settings($locked);
        $valid = array_map('intval', array_column(activity_options($id), 'id'));
        foreach ($optionIds as $oid) {
            if (!in_array($oid, $valid, true)) {
                throw new UserError('That option no longer exists. Refresh and try again.', 409, 'stale');
            }
        }
        if (!$settings['multiple'] && count($optionIds) > 1) {
            throw new UserError('Pick just one option.', 422, 'too_many');
        }
        if ($settings['multiple'] && $settings['max_choices'] > 0 && count($optionIds) > $settings['max_choices']) {
            throw new UserError('You can pick up to ' . $settings['max_choices'] . ' options.', 422, 'too_many');
        }

        $existing = (int) q_val(
            'SELECT COUNT(*) FROM gm_responses WHERE activity_id = ? AND participant_id = ?',
            [$id, $participant['id']]
        );
        if ($existing > 0 && !$settings['allow_change']) {
            throw new UserError('You have already voted.', 409, 'already_voted');
        }
        q('DELETE FROM gm_responses WHERE activity_id = ? AND participant_id = ?', [$id, $participant['id']]);
        $now = now_utc();
        $insert = $pdo->prepare(
            'INSERT INTO gm_responses (activity_id, participant_id, option_id, created_at) VALUES (?, ?, ?, ?)'
        );
        foreach ($optionIds as $oid) {
            $insert->execute([$id, $participant['id'], $oid, $now]);
        }
        q('UPDATE gm_activities SET version = version + 1 WHERE id = ?', [$id]);
        q('UPDATE gm_participants SET last_seen_at = ? WHERE id = ?', [$now, $participant['id']]);
        return $optionIds;
    });
}

/**
 * Validate the editor's payload. Returns [type, title, description, settings, options].
 * $savedImages: images already stored on this activity — kept as-is even if
 * the file is missing, so re-saving never silently drops an option and its votes.
 */
function activity_validate_input(array $in, array $savedImages = []): array
{
    $type = (string) ($in['type'] ?? 'poll');
    if (!in_array($type, GM_ACTIVITY_TYPES, true)) {
        throw new UserError('Unknown activity type.');
    }
    $title = clean_text($in['title'] ?? '', 500);
    if ($title === '') {
        throw new UserError('Add a question so people know what they are voting on.', 422, 'title_required');
    }
    $description = clean_text($in['description'] ?? '', 1000);
    $settings = activity_normalize_settings($in['settings'] ?? []);

    $options = [];
    foreach ((array) ($in['options'] ?? []) as $o) {
        if (!is_array($o)) {
            continue;
        }
        $label = clean_text($o['label'] ?? '', 300);
        $image = isset($o['image']) && is_string($o['image']) && $o['image'] !== '' ? basename($o['image']) : null;
        if ($image !== null && !in_array($image, $savedImages, true) && !upload_exists($image)) {
            throw new UserError('One of the images didn’t finish uploading. Remove it and add it again.', 422, 'missing_image');
        }
        if ($label === '' && $image === null) {
            continue; // skip blank rows
        }
        $options[] = ['id' => isset($o['id']) ? (int) $o['id'] : 0, 'label' => $label, 'image' => $image];
    }
    if (count($options) < 2) {
        throw new UserError('Add at least two options.', 422, 'options_required');
    }
    if (count($options) > GM_MAX_OPTIONS) {
        throw new UserError('A poll can have up to ' . GM_MAX_OPTIONS . ' options.', 422, 'too_many_options');
    }
    return [$type, $title, $description, $settings, $options];
}

/** Create ($id = null) or update an activity with its options. Returns the id. */
function activity_save(?int $id, array $in): int
{
    $savedImages = $id === null ? [] : array_values(array_filter(array_column(activity_options($id), 'image')));
    [$type, $title, $description, $settings, $options] = activity_validate_input($in, $savedImages);
    $removedImages = [];

    $id = db_tx(static function (PDO $pdo) use ($id, $type, $title, $description, $settings, $options, &$removedImages) {
        $now = now_utc();
        $json = json_encode($settings);
        if ($id === null) {
            q(
                'INSERT INTO gm_activities (code, type, title, description, settings, status, results_hidden, present_key, version, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, \'open\', 0, ?, 1, ?, ?)',
                [activity_new_code(), $type, $title, $description, $json, random_token(12), $now, $now]
            );
            $id = (int) $pdo->lastInsertId();
            $existing = [];
        } else {
            // Same lock as voting, so a vote and an edit never interleave.
            q('SELECT id FROM gm_activities WHERE id = ? FOR UPDATE', [$id]);
            q(
                'UPDATE gm_activities SET title = ?, description = ?, settings = ?, version = version + 1, updated_at = ? WHERE id = ?',
                [$title, $description, $json, $now, $id]
            );
            $existing = [];
            foreach (activity_options($id) as $o) {
                $existing[(int) $o['id']] = $o;
            }
        }

        $keep = [];
        foreach ($options as $pos => $o) {
            if ($o['id'] && isset($existing[$o['id']])) {
                q('UPDATE gm_options SET label = ?, image = ?, position = ? WHERE id = ?', [$o['label'], $o['image'], $pos, $o['id']]);
                $keep[$o['id']] = true;
                if ($existing[$o['id']]['image'] && $existing[$o['id']]['image'] !== $o['image']) {
                    $removedImages[] = $existing[$o['id']]['image'];
                }
            } else {
                q(
                    'INSERT INTO gm_options (activity_id, label, image, position) VALUES (?, ?, ?, ?)',
                    [$id, $o['label'], $o['image'], $pos]
                );
            }
        }
        foreach ($existing as $oid => $o) {
            if (!isset($keep[$oid])) {
                q('DELETE FROM gm_options WHERE id = ?', [$oid]);
                if ($o['image']) {
                    $removedImages[] = $o['image'];
                }
            }
        }
        return $id;
    });

    upload_delete_unused($removedImages);
    return $id;
}

function activity_set_state(array $activity, array $in): void
{
    $status = $activity['status'];
    if (isset($in['status'])) {
        $status = $in['status'] === 'closed' ? 'closed' : 'open';
    }
    $hidden = isset($in['results_hidden']) ? (filter_var($in['results_hidden'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : (int) $activity['results_hidden'];
    q(
        'UPDATE gm_activities SET status = ?, results_hidden = ?, version = version + 1, updated_at = ? WHERE id = ?',
        [$status, $hidden, now_utc(), $activity['id']]
    );
}

function activity_reset(array $activity): void
{
    db_tx(static function () use ($activity) {
        q('DELETE FROM gm_responses WHERE activity_id = ?', [$activity['id']]);
        q('UPDATE gm_activities SET version = version + 1, updated_at = ? WHERE id = ?', [now_utc(), $activity['id']]);
    });
}

function activity_duplicate(array $activity): int
{
    return db_tx(static function (PDO $pdo) use ($activity) {
        $now = now_utc();
        q(
            'INSERT INTO gm_activities (code, type, title, description, settings, status, results_hidden, present_key, version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, \'open\', 0, ?, 1, ?, ?)',
            [
                activity_new_code(), $activity['type'], mb_substr($activity['title'] . ' (copy)', 0, 500),
                $activity['description'], $activity['settings'], random_token(12), $now, $now,
            ]
        );
        $newId = (int) $pdo->lastInsertId();
        q(
            'INSERT INTO gm_options (activity_id, label, image, position)
             SELECT ?, label, image, position FROM gm_options WHERE activity_id = ? ORDER BY position, id',
            [$newId, $activity['id']]
        );
        return $newId;
    });
}

function activity_delete(array $activity): void
{
    $images = array_filter(array_column(activity_options((int) $activity['id']), 'image'));
    q('DELETE FROM gm_activities WHERE id = ?', [$activity['id']]);
    upload_delete_unused($images);
}

/** All activities for the dashboard, newest first, with vote counts. */
function activity_list(): array
{
    $rows = q_all(
        'SELECT a.id, a.code, a.type, a.title, a.status, a.results_hidden, a.created_at, a.updated_at,
                (SELECT COUNT(DISTINCT r.participant_id) FROM gm_responses r WHERE r.activity_id = a.id) AS voters,
                (SELECT COUNT(*) FROM gm_options o WHERE o.activity_id = a.id) AS option_count,
                (SELECT o.image FROM gm_options o WHERE o.activity_id = a.id AND o.image IS NOT NULL ORDER BY o.position LIMIT 1) AS cover
           FROM gm_activities a
          ORDER BY a.created_at DESC, a.id DESC'
    );
    return array_map(static function ($r) {
        return [
            'id' => (int) $r['id'],
            'code' => $r['code'],
            'type' => $r['type'],
            'title' => $r['title'],
            'status' => $r['status'],
            'results_hidden' => (bool) $r['results_hidden'],
            'voters' => (int) $r['voters'],
            'option_count' => (int) $r['option_count'],
            'cover' => image_url($r['cover']),
            'created_at' => iso_utc($r['created_at']),
            'updated_at' => iso_utc($r['updated_at']),
        ];
    }, $rows);
}

/** Editor payload (admin only). */
function activity_editor_data(array $activity): array
{
    return [
        'id' => (int) $activity['id'],
        'type' => $activity['type'],
        'title' => $activity['title'],
        'description' => (string) ($activity['description'] ?? ''),
        'settings' => activity_settings($activity),
        'options' => array_map(static function ($o) {
            return ['id' => (int) $o['id'], 'label' => $o['label'], 'image' => $o['image'], 'image_url' => image_url($o['image'])];
        }, activity_options((int) $activity['id'])),
        'votes' => (int) q_val('SELECT COUNT(*) FROM gm_responses WHERE activity_id = ?', [$activity['id']]),
    ];
}
