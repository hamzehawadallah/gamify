<?php
/**
 * Activities: one QR code each, holding one or more questions. Each question
 * has its own options (with optional images), votes and live results.
 * Built so other activity types (feedback, quiz, word cloud) can be added.
 */
declare(strict_types=1);

const GM_ACTIVITY_TYPES = ['poll'];
const GM_MAX_OPTIONS = 20;
const GM_MAX_QUESTIONS = 12;
const GM_LIVE_WAIT_SECONDS = 15;
const GM_QUESTION_COLORS = ['blue', 'red', 'yellow', 'green', 'purple', 'teal', 'orange', 'indigo'];

/* ---------- Settings ---------- */

function activity_default_settings(): array
{
    return [
        'allow_change' => true,   // participants can change their votes while voting is open
        'show_results' => false,  // show live results on participants' phones after voting
        'show_qr' => true,        // show the QR code on the presenter screen
        'show_voters' => true,    // show voters' initials on the presenter screen
    ];
}

function question_default_settings(int $position = 0): array
{
    return [
        'multiple' => false,      // allow choosing more than one option
        'max_choices' => 0,       // 0 = no limit (only when multiple)
        'color' => GM_QUESTION_COLORS[$position % count(GM_QUESTION_COLORS)],
    ];
}

function activity_settings(array $activity): array
{
    $saved = json_decode((string) ($activity['settings'] ?? ''), true);
    $saved = is_array($saved) ? array_intersect_key($saved, activity_default_settings()) : [];
    return array_merge(activity_default_settings(), $saved);
}

function question_settings(array $question, int $position = 0): array
{
    $saved = json_decode((string) ($question['settings'] ?? ''), true);
    $out = array_merge(question_default_settings($position), is_array($saved) ? $saved : []);
    if (!in_array($out['color'], GM_QUESTION_COLORS, true)) {
        $out['color'] = question_default_settings($position)['color'];
    }
    return $out;
}

function activity_normalize_settings($input): array
{
    $input = is_array($input) ? $input : [];
    $out = [];
    foreach (activity_default_settings() as $key => $default) {
        $out[$key] = array_key_exists($key, $input) ? filter_var($input[$key], FILTER_VALIDATE_BOOLEAN) : $default;
    }
    return $out;
}

function question_normalize_settings($input, int $position): array
{
    $input = is_array($input) ? $input : [];
    $defaults = question_default_settings($position);
    $multiple = array_key_exists('multiple', $input) ? filter_var($input['multiple'], FILTER_VALIDATE_BOOLEAN) : false;
    $color = (string) ($input['color'] ?? '');
    return [
        'multiple' => $multiple,
        'max_choices' => $multiple ? max(0, min(GM_MAX_OPTIONS, (int) ($input['max_choices'] ?? 0))) : 0,
        'color' => in_array($color, GM_QUESTION_COLORS, true) ? $color : $defaults['color'],
    ];
}

/* ---------- Loading ---------- */

function activity_by_code(string $code): ?array
{
    return q_one('SELECT * FROM gm_activities WHERE code = ?', [$code]);
}

function activity_by_id(int $id): ?array
{
    return q_one('SELECT * FROM gm_activities WHERE id = ?', [$id]);
}

/** Questions in order, each with its settings and options. */
function activity_questions(int $activityId): array
{
    $questions = [];
    foreach (q_all(
        'SELECT id, title, description, settings, position FROM gm_questions WHERE activity_id = ? ORDER BY position, id',
        [$activityId]
    ) as $i => $q) {
        $questions[(int) $q['id']] = [
            'id' => (int) $q['id'],
            'title' => $q['title'],
            'description' => (string) ($q['description'] ?? ''),
            'settings' => question_settings($q, $i),
            'options' => [],
        ];
    }
    foreach (q_all(
        'SELECT id, question_id, label, image FROM gm_options WHERE activity_id = ? ORDER BY position, id',
        [$activityId]
    ) as $o) {
        $qid = (int) $o['question_id'];
        if (isset($questions[$qid])) {
            $questions[$qid]['options'][] = ['id' => (int) $o['id'], 'label' => $o['label'], 'image' => $o['image']];
        }
    }
    return array_values($questions);
}

/** All image file names used by an activity. */
function activity_images(int $activityId): array
{
    return array_values(array_filter(array_column(
        q_all('SELECT image FROM gm_options WHERE activity_id = ? AND image IS NOT NULL', [$activityId]),
        'image'
    )));
}

/** Title to show for a question: its own, else the activity's (single question), else "Question N". */
function question_display_title(array $question, int $index, array $activity, int $count): string
{
    if ($question['title'] !== '') {
        return $question['title'];
    }
    return $count === 1 ? (string) $activity['title'] : 'Question ' . ($index + 1);
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
function activity_public(array $activity, array $questions): array
{
    return [
        'code' => $activity['code'],
        'type' => $activity['type'],
        'title' => $activity['title'],
        'description' => (string) ($activity['description'] ?? ''),
        'status' => $activity['status'],
        'results_hidden' => (bool) $activity['results_hidden'],
        'settings' => activity_settings($activity),
        'questions' => array_map(static function ($q) {
            return [
                'id' => $q['id'],
                'title' => $q['title'],
                'description' => $q['description'],
                'color' => $q['settings']['color'],
                'settings' => ['multiple' => $q['settings']['multiple'], 'max_choices' => $q['settings']['max_choices']],
                'options' => array_map(static function ($o) {
                    return ['id' => $o['id'], 'label' => $o['label'], 'image' => image_url($o['image'])];
                }, $q['options']),
            ];
        }, $questions),
    ];
}

/** Vote counts per option and voters per question, plus everyone who took part. */
function activity_results(array $activity, array $questions): array
{
    $id = (int) $activity['id'];
    $counts = [];
    foreach (q_all('SELECT option_id, COUNT(*) AS c FROM gm_responses WHERE activity_id = ? GROUP BY option_id', [$id]) as $r) {
        $counts[(int) $r['option_id']] = (int) $r['c'];
    }
    $perQuestion = [];
    foreach (q_all(
        'SELECT question_id, COUNT(DISTINCT participant_id) AS v FROM gm_responses WHERE activity_id = ? GROUP BY question_id',
        [$id]
    ) as $r) {
        $perQuestion[(int) $r['question_id']] = (int) $r['v'];
    }
    return [
        'voters' => (int) q_val('SELECT COUNT(DISTINCT participant_id) FROM gm_responses WHERE activity_id = ?', [$id]),
        'questions' => array_map(static function ($q) use ($counts, $perQuestion) {
            return [
                'id' => $q['id'],
                'voters' => $perQuestion[$q['id']] ?? 0,
                'counts' => array_map(static function ($o) use ($counts) {
                    return ['id' => $o['id'], 'count' => $counts[$o['id']] ?? 0];
                }, $q['options']),
            ];
        }, $questions),
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
    $questions = activity_questions((int) $activity['id']);
    $settings = activity_settings($activity);
    $results = activity_results($activity, $questions);
    if (!$admin && $activity['results_hidden']) {
        foreach ($results['questions'] as &$qr) {
            $qr['counts'] = null;
        }
        unset($qr);
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
        'activity' => activity_public($activity, $questions),
        'results' => $results,
        'recent_voters' => $voters,
        'join' => [
            'url' => activity_join_url($activity),
            'display' => activity_join_display($activity),
            'code' => $activity['code'],
        ],
    ];
    if ($admin) {
        $payload['responses'] = activity_response_rows((int) $activity['id'], $questions, 500);
    }
    return $payload;
}

/** One row per participant with their picks per question, newest first. */
function activity_response_rows(int $activityId, array $questions, int $limit = 0): array
{
    $order = [];
    foreach ($questions as $q) {
        foreach ($q['options'] as $i => $o) {
            $order[$o['id']] = $i;
        }
    }
    $rows = [];
    foreach (q_all(
        'SELECT r.participant_id, p.name, r.question_id, r.option_id, r.created_at
           FROM gm_responses r JOIN gm_participants p ON p.id = r.participant_id
          WHERE r.activity_id = ?
          ORDER BY r.created_at DESC, r.id DESC',
        [$activityId]
    ) as $r) {
        $pid = (int) $r['participant_id'];
        if (!isset($rows[$pid])) {
            if ($limit > 0 && count($rows) >= $limit) {
                continue;
            }
            $rows[$pid] = ['participant_id' => $pid, 'name' => $r['name'], 'answers' => [], 'at' => iso_utc($r['created_at'])];
        }
        $rows[$pid]['answers'][(int) $r['question_id']][] = (int) $r['option_id'];
    }
    foreach ($rows as &$row) {
        foreach ($row['answers'] as &$ids) {
            usort($ids, static fn($a, $b) => ($order[$a] ?? 0) <=> ($order[$b] ?? 0));
        }
        unset($ids);
        $row['answers'] = (object) $row['answers'];
    }
    unset($row);
    return array_values($rows);
}

/** The participant's picks: [question_id => [option ids]]. */
function activity_my_choices(int $activityId, int $participantId): array
{
    $mine = [];
    foreach (q_all(
        'SELECT question_id, option_id FROM gm_responses WHERE activity_id = ? AND participant_id = ? ORDER BY id',
        [$activityId, $participantId]
    ) as $r) {
        $mine[(int) $r['question_id']][] = (int) $r['option_id'];
    }
    return $mine;
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

/* ---------- Voting ---------- */

/**
 * Record a participant's answer to one question. Locks the activity row so
 * votes are counted one at a time and the version bump that wakes up
 * presenter screens is always consistent. All rules are checked after the
 * lock, so an edit made at the same moment can't slip through.
 */
function activity_vote(array $activity, array $participant, ?int $questionId, $optionIds): void
{
    $optionIds = array_values(array_unique(array_map('intval', is_array($optionIds) ? $optionIds : [])));
    if (!$optionIds) {
        throw new UserError('Pick an option first.', 422, 'empty');
    }

    db_tx(static function (PDO $pdo) use ($activity, $participant, $questionId, $optionIds) {
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

        $questions = activity_questions($id);
        $question = null;
        foreach ($questions as $q) {
            if ($q['id'] === $questionId || ($questionId === null && count($questions) === 1)) {
                $question = $q;
            }
        }
        if (!$question) {
            throw new UserError('This question no longer exists. Refresh and try again.', 409, 'stale');
        }
        $valid = array_column($question['options'], 'id');
        foreach ($optionIds as $oid) {
            if (!in_array($oid, $valid, true)) {
                throw new UserError('That option no longer exists. Refresh and try again.', 409, 'stale');
            }
        }
        $qs = $question['settings'];
        if (!$qs['multiple'] && count($optionIds) > 1) {
            throw new UserError('Pick just one option.', 422, 'too_many');
        }
        if ($qs['multiple'] && $qs['max_choices'] > 0 && count($optionIds) > $qs['max_choices']) {
            throw new UserError('You can pick up to ' . $qs['max_choices'] . ' options.', 422, 'too_many');
        }

        $existing = (int) q_val(
            'SELECT COUNT(*) FROM gm_responses WHERE question_id = ? AND participant_id = ?',
            [$question['id'], $participant['id']]
        );
        if ($existing > 0 && !activity_settings($locked)['allow_change']) {
            throw new UserError('You have already answered this one.', 409, 'already_voted');
        }
        q('DELETE FROM gm_responses WHERE question_id = ? AND participant_id = ?', [$question['id'], $participant['id']]);
        $now = now_utc();
        $insert = $pdo->prepare(
            'INSERT INTO gm_responses (activity_id, question_id, participant_id, option_id, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($optionIds as $oid) {
            $insert->execute([$id, $question['id'], $participant['id'], $oid, $now]);
        }
        q('UPDATE gm_activities SET version = version + 1 WHERE id = ?', [$id]);
        q('UPDATE gm_participants SET last_seen_at = ? WHERE id = ?', [$now, $participant['id']]);
    });
}

/* ---------- Editing ---------- */

/**
 * Validate the editor's payload. Returns [type, title, description, settings, questions].
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
        throw new UserError('Give the activity a title.', 422, 'title_required');
    }
    $description = clean_text($in['description'] ?? '', 1000);
    $settings = activity_normalize_settings($in['settings'] ?? []);

    $questions = [];
    foreach (array_values((array) ($in['questions'] ?? [])) as $qIn) {
        if (!is_array($qIn)) {
            continue;
        }
        $n = count($questions) + 1;
        $options = [];
        foreach ((array) ($qIn['options'] ?? []) as $o) {
            if (!is_array($o)) {
                continue;
            }
            $label = clean_text($o['label'] ?? '', 300);
            $image = isset($o['image']) && is_string($o['image']) && $o['image'] !== '' ? basename($o['image']) : null;
            if ($image !== null && !in_array($image, $savedImages, true) && !upload_exists($image)) {
                throw new UserError('An image in question ' . $n . ' didn’t finish uploading. Remove it and add it again.', 422, 'missing_image');
            }
            if ($label === '' && $image === null) {
                continue; // skip blank rows
            }
            $options[] = ['id' => isset($o['id']) ? (int) $o['id'] : 0, 'label' => $label, 'image' => $image];
        }
        $qTitle = clean_text($qIn['title'] ?? '', 300);
        $qDesc = clean_text($qIn['description'] ?? '', 1000);
        if (!$options && $qTitle === '' && $qDesc === '') {
            continue; // skip an untouched, empty question
        }
        if (count($options) < 2) {
            throw new UserError('Question ' . $n . ' needs at least two options.', 422, 'options_required');
        }
        if (count($options) > GM_MAX_OPTIONS) {
            throw new UserError('Question ' . $n . ' can have up to ' . GM_MAX_OPTIONS . ' options.', 422, 'too_many_options');
        }
        $questions[] = [
            'id' => isset($qIn['id']) ? (int) $qIn['id'] : 0,
            'title' => $qTitle,
            'description' => $qDesc,
            'settings' => question_normalize_settings($qIn['settings'] ?? [], count($questions)),
            'options' => $options,
        ];
    }
    if (!$questions) {
        throw new UserError('Add at least one question with two options.', 422, 'questions_required');
    }
    if (count($questions) > GM_MAX_QUESTIONS) {
        throw new UserError('An activity can have up to ' . GM_MAX_QUESTIONS . ' questions.', 422, 'too_many_questions');
    }
    if (count($questions) > 1) {
        foreach ($questions as $i => $q) {
            if ($q['title'] === '') {
                throw new UserError('Give question ' . ($i + 1) . ' a title.', 422, 'question_title_required');
            }
        }
    }
    return [$type, $title, $description, $settings, $questions];
}

/** Create ($id = null) or update an activity with its questions and options. Returns the id. */
function activity_save(?int $id, array $in): int
{
    $savedImages = $id === null ? [] : activity_images($id);
    [$type, $title, $description, $settings, $questions] = activity_validate_input($in, $savedImages);
    $removedImages = [];

    $id = db_tx(static function (PDO $pdo) use ($id, $type, $title, $description, $settings, $questions, &$removedImages) {
        $now = now_utc();
        $json = json_encode($settings);
        $existing = [];
        if ($id === null) {
            q(
                'INSERT INTO gm_activities (code, type, title, description, settings, status, results_hidden, present_key, version, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, \'open\', 0, ?, 1, ?, ?)',
                [activity_new_code(), $type, $title, $description, $json, random_token(12), $now, $now]
            );
            $id = (int) $pdo->lastInsertId();
        } else {
            // Same lock as voting, so a vote and an edit never interleave.
            q('SELECT id FROM gm_activities WHERE id = ? FOR UPDATE', [$id]);
            q(
                'UPDATE gm_activities SET title = ?, description = ?, settings = ?, version = version + 1, updated_at = ? WHERE id = ?',
                [$title, $description, $json, $now, $id]
            );
            foreach (activity_questions($id) as $q) {
                $existing[$q['id']] = $q;
            }
        }

        $keepQuestions = [];
        foreach ($questions as $qPos => $q) {
            $qJson = json_encode($q['settings']);
            $oldOptions = [];
            if ($q['id'] && isset($existing[$q['id']])) {
                $qid = $q['id'];
                q(
                    'UPDATE gm_questions SET title = ?, description = ?, settings = ?, position = ? WHERE id = ?',
                    [$q['title'], $q['description'], $qJson, $qPos, $qid]
                );
                foreach ($existing[$qid]['options'] as $o) {
                    $oldOptions[$o['id']] = $o;
                }
            } else {
                q(
                    'INSERT INTO gm_questions (activity_id, title, description, settings, position) VALUES (?, ?, ?, ?, ?)',
                    [$id, $q['title'], $q['description'], $qJson, $qPos]
                );
                $qid = (int) $pdo->lastInsertId();
            }
            $keepQuestions[$qid] = true;

            $keepOptions = [];
            foreach ($q['options'] as $pos => $o) {
                if ($o['id'] && isset($oldOptions[$o['id']])) {
                    q('UPDATE gm_options SET label = ?, image = ?, position = ? WHERE id = ?', [$o['label'], $o['image'], $pos, $o['id']]);
                    $keepOptions[$o['id']] = true;
                    if ($oldOptions[$o['id']]['image'] && $oldOptions[$o['id']]['image'] !== $o['image']) {
                        $removedImages[] = $oldOptions[$o['id']]['image'];
                    }
                } else {
                    q(
                        'INSERT INTO gm_options (activity_id, question_id, label, image, position) VALUES (?, ?, ?, ?, ?)',
                        [$id, $qid, $o['label'], $o['image'], $pos]
                    );
                }
            }
            foreach ($oldOptions as $oid => $o) {
                if (!isset($keepOptions[$oid])) {
                    q('DELETE FROM gm_options WHERE id = ?', [$oid]);
                    if ($o['image']) {
                        $removedImages[] = $o['image'];
                    }
                }
            }
        }
        foreach ($existing as $qid => $q) {
            if (!isset($keepQuestions[$qid])) {
                q('DELETE FROM gm_questions WHERE id = ?', [$qid]); // cascades to options and votes
                foreach ($q['options'] as $o) {
                    if ($o['image']) {
                        $removedImages[] = $o['image'];
                    }
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
        foreach (q_all('SELECT * FROM gm_questions WHERE activity_id = ? ORDER BY position, id', [$activity['id']]) as $q) {
            q(
                'INSERT INTO gm_questions (activity_id, title, description, settings, position) VALUES (?, ?, ?, ?, ?)',
                [$newId, $q['title'], $q['description'], $q['settings'], $q['position']]
            );
            $newQid = (int) $pdo->lastInsertId();
            q(
                'INSERT INTO gm_options (activity_id, question_id, label, image, position)
                 SELECT ?, ?, label, image, position FROM gm_options WHERE question_id = ? ORDER BY position, id',
                [$newId, $newQid, $q['id']]
            );
        }
        return $newId;
    });
}

function activity_delete(array $activity): void
{
    $images = activity_images((int) $activity['id']);
    q('DELETE FROM gm_activities WHERE id = ?', [$activity['id']]);
    upload_delete_unused($images);
}

/** All activities for the dashboard, newest first, with vote counts. */
function activity_list(): array
{
    $rows = q_all(
        'SELECT a.id, a.code, a.type, a.title, a.status, a.results_hidden, a.created_at, a.updated_at,
                (SELECT COUNT(DISTINCT r.participant_id) FROM gm_responses r WHERE r.activity_id = a.id) AS voters,
                (SELECT COUNT(*) FROM gm_questions q WHERE q.activity_id = a.id) AS question_count,
                (SELECT o.image FROM gm_options o JOIN gm_questions q ON q.id = o.question_id
                  WHERE o.activity_id = a.id AND o.image IS NOT NULL ORDER BY q.position, o.position LIMIT 1) AS cover
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
            'question_count' => (int) $r['question_count'],
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
        'questions' => array_map(static function ($q) {
            return [
                'id' => $q['id'],
                'title' => $q['title'],
                'description' => $q['description'],
                'settings' => $q['settings'],
                'options' => array_map(static function ($o) {
                    return ['id' => $o['id'], 'label' => $o['label'], 'image' => $o['image'], 'image_url' => image_url($o['image'])];
                }, $q['options']),
            ];
        }, activity_questions((int) $activity['id'])),
        'votes' => (int) q_val('SELECT COUNT(DISTINCT participant_id) FROM gm_responses WHERE activity_id = ?', [$activity['id']]),
    ];
}
