<?php
/**
 * Activities: one QR code each, holding one or more questions. A question is
 * a vote (options with optional images) or a ranking (items with a picture
 * and a color that people drag into order). Each has its own live results.
 * Built so other activity types (feedback, quiz, word cloud) can be added.
 */
declare(strict_types=1);

const GM_ACTIVITY_TYPES = ['poll'];
// "poll": pick one or more options. "ranking": put every item in order.
const GM_QUESTION_TYPES = ['poll', 'ranking'];
const GM_MAX_OPTIONS = 20;
const GM_MAX_TAGS = 20;
const GM_MAX_QUESTIONS = 12;
const GM_LIVE_WAIT_SECONDS = 15;
// Red and charcoal are the VCL brand colors; the first question gets red.
const GM_QUESTION_COLORS = ['red', 'blue', 'yellow', 'green', 'purple', 'teal', 'orange', 'charcoal'];

/* ---------- Settings ---------- */

function activity_default_settings(): array
{
    return [
        'allow_change' => true,   // participants can change their votes while voting is open
        'show_results' => false,  // show live results on participants' phones after voting
        'show_qr' => true,        // show the QR code on the presenter screen
        'show_voters' => true,    // show voters' personas on the presenter screen
        'tags' => [],             // [{id, name, color}]: a tag shows as a pill above an option's title
    ];
}

/** The activity's tags, cleaned up: named, a palette color each, unique ids. */
function activity_normalize_tags($input): array
{
    $out = [];
    $seen = [];
    foreach (is_array($input) ? array_values($input) : [] as $t) {
        if (!is_array($t) || count($out) >= GM_MAX_TAGS) {
            continue;
        }
        $name = clean_text($t['name'] ?? '', 40);
        if ($name === '') {
            continue;
        }
        $id = is_string($t['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,16}$/', $t['id']) ? $t['id'] : '';
        if ($id === '' || isset($seen[$id])) {
            $id = 't' . substr(random_token(6), 0, 10);
        }
        $seen[$id] = true;
        $color = is_string($t['color'] ?? null) ? $t['color'] : null;
        $out[] = ['id' => $id, 'name' => $name, 'color' => option_color($color, count($out))];
    }
    return $out;
}

/** Tag id => {name, color}, for showing an option's tag. */
function activity_tag_map(array $activity): array
{
    $map = [];
    foreach (activity_settings($activity)['tags'] as $t) {
        $map[$t['id']] = ['name' => $t['name'], 'color' => $t['color']];
    }
    return $map;
}

function question_default_settings(int $position = 0): array
{
    return [
        'multiple' => false,      // allow choosing more than one option
        'max_choices' => 0,       // 0 = no limit (only when multiple)
        'color' => GM_QUESTION_COLORS[$position % count(GM_QUESTION_COLORS)],
        'top_label' => '',        // ranking: what the top of the list means, e.g. "Most important"
        'bottom_label' => '',     // ranking: what the bottom means, e.g. "Least important"
        'shuffle' => true,        // ranking: everyone starts from their own shuffled order
    ];
}

/** A ranking item's color: its own if valid, else the next one in the palette. */
function option_color(?string $color, int $index): string
{
    return in_array($color, GM_QUESTION_COLORS, true) ? $color : GM_QUESTION_COLORS[$index % count(GM_QUESTION_COLORS)];
}

function activity_settings(array $activity): array
{
    $saved = json_decode((string) ($activity['settings'] ?? ''), true);
    $saved = is_array($saved) ? array_intersect_key($saved, activity_default_settings()) : [];
    $out = array_merge(activity_default_settings(), $saved);
    $out['tags'] = is_array($out['tags']) ? $out['tags'] : [];
    return $out;
}

function question_settings(array $question, int $position = 0): array
{
    $saved = json_decode((string) ($question['settings'] ?? ''), true);
    $out = array_merge(question_default_settings($position), is_array($saved) ? $saved : []);
    if ($out['color'] === 'indigo') {
        $out['color'] = 'charcoal'; // the color charcoal replaced
    }
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
        if ($key === 'tags') {
            $out['tags'] = activity_normalize_tags($input['tags'] ?? []);
            continue;
        }
        $out[$key] = array_key_exists($key, $input) ? filter_var($input[$key], FILTER_VALIDATE_BOOLEAN) : $default;
    }
    return $out;
}

function question_normalize_settings($input, int $position, string $type = 'poll'): array
{
    $input = is_array($input) ? $input : [];
    $defaults = question_default_settings($position);
    $multiple = $type === 'poll' && array_key_exists('multiple', $input) && filter_var($input['multiple'], FILTER_VALIDATE_BOOLEAN);
    $color = (string) ($input['color'] ?? '');
    $out = [
        'multiple' => $multiple,
        'max_choices' => $multiple ? max(0, min(GM_MAX_OPTIONS, (int) ($input['max_choices'] ?? 0))) : 0,
        'color' => in_array($color, GM_QUESTION_COLORS, true) ? $color : $defaults['color'],
    ];
    if ($type === 'ranking') {
        $out['top_label'] = clean_text($input['top_label'] ?? '', 60);
        $out['bottom_label'] = clean_text($input['bottom_label'] ?? '', 60);
        $out['shuffle'] = array_key_exists('shuffle', $input) ? filter_var($input['shuffle'], FILTER_VALIDATE_BOOLEAN) : $defaults['shuffle'];
    }
    return $out;
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
        'SELECT id, type, title, description, settings, position FROM gm_questions WHERE activity_id = ? ORDER BY position, id',
        [$activityId]
    ) as $i => $q) {
        $questions[(int) $q['id']] = [
            'id' => (int) $q['id'],
            'type' => in_array($q['type'], GM_QUESTION_TYPES, true) ? $q['type'] : 'poll',
            'title' => $q['title'],
            'description' => (string) ($q['description'] ?? ''),
            'settings' => question_settings($q, $i),
            'options' => [],
        ];
    }
    foreach (q_all(
        'SELECT id, question_id, label, image, color, tag FROM gm_options WHERE activity_id = ? ORDER BY position, id',
        [$activityId]
    ) as $o) {
        $qid = (int) $o['question_id'];
        if (isset($questions[$qid])) {
            $ranking = $questions[$qid]['type'] === 'ranking';
            $questions[$qid]['options'][] = [
                'id' => (int) $o['id'],
                'label' => $o['label'],
                'image' => $o['image'],
                'color' => $ranking ? option_color($o['color'], count($questions[$qid]['options'])) : null,
                'tag' => $o['tag'] !== null && $o['tag'] !== '' ? $o['tag'] : null,
            ];
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
    $tags = activity_tag_map($activity);
    return [
        'code' => $activity['code'],
        'type' => $activity['type'],
        'title' => $activity['title'],
        'description' => (string) ($activity['description'] ?? ''),
        'status' => $activity['status'],
        'results_hidden' => (bool) $activity['results_hidden'],
        'settings' => activity_settings($activity),
        'questions' => array_map(static function ($q) use ($tags) {
            $s = $q['settings'];
            $settings = ['multiple' => $s['multiple'], 'max_choices' => $s['max_choices']];
            if ($q['type'] === 'ranking') {
                $settings += ['top_label' => $s['top_label'], 'bottom_label' => $s['bottom_label'], 'shuffle' => $s['shuffle']];
            }
            return [
                'id' => $q['id'],
                'type' => $q['type'],
                'title' => $q['title'],
                'description' => $q['description'],
                'color' => $s['color'],
                'settings' => $settings,
                'options' => array_map(static function ($o) use ($tags) {
                    return [
                        'id' => $o['id'],
                        'label' => $o['label'],
                        'image' => image_url($o['image']),
                        'color' => $o['color'],
                        'tag' => $o['tag'] !== null ? ($tags[$o['tag']] ?? null) : null, // {name, color}
                    ];
                }, $q['options']),
            ];
        }, $questions),
    ];
}

/**
 * For each ranking item: how many people placed it, the sum of their places,
 * its points (1 for first place down to 0 for last) and how often it came
 * first. Places count among the items each person ranked, so an item removed
 * later leaves no gaps.
 */
function ranking_stats(int $activityId, array $questions): array
{
    $ids = [];
    foreach ($questions as $q) {
        if ($q['type'] === 'ranking') {
            $ids[] = $q['id'];
        }
    }
    if (!$ids) {
        return [];
    }
    $lists = [];
    foreach (q_all(
        'SELECT question_id, participant_id, option_id FROM gm_responses
          WHERE activity_id = ? AND question_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
          ORDER BY question_id, participant_id, value_num, id',
        array_merge([$activityId], $ids)
    ) as $r) {
        $lists[$r['question_id'] . '|' . $r['participant_id']][] = (int) $r['option_id'];
    }
    $stats = [];
    foreach ($lists as $list) {
        $k = count($list);
        foreach ($list as $i => $oid) {
            $s = $stats[$oid] ?? ['n' => 0, 'places' => 0, 'points' => 0.0, 'first' => 0];
            $s['n']++;
            $s['places'] += $i + 1;
            $s['points'] += $k > 1 ? ($k - 1 - $i) / ($k - 1) : 1;
            $s['first'] += $i === 0 ? 1 : 0;
            $stats[$oid] = $s;
        }
    }
    return $stats;
}

/**
 * Vote counts per option and voters per question, plus everyone who took part.
 * Ranking items also get their average place, a 0–100 score and first places.
 */
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
    $ranks = ranking_stats($id, $questions);
    return [
        'voters' => (int) q_val('SELECT COUNT(DISTINCT participant_id) FROM gm_responses WHERE activity_id = ?', [$id]),
        'questions' => array_map(static function ($q) use ($counts, $perQuestion, $ranks) {
            return [
                'id' => $q['id'],
                'voters' => $perQuestion[$q['id']] ?? 0,
                'counts' => array_map(static function ($o) use ($q, $counts, $ranks) {
                    $c = ['id' => $o['id'], 'count' => $counts[$o['id']] ?? 0];
                    if ($q['type'] === 'ranking') {
                        $r = $ranks[$o['id']] ?? null;
                        $c['avg'] = $r ? round($r['places'] / $r['n'], 2) : null;
                        $c['score'] = $r ? (int) round($r['points'] / $r['n'] * 100) : 0;
                        $c['first'] = $r ? $r['first'] : 0;
                    }
                    return $c;
                }, $q['options']),
            ];
        }, $questions),
    ];
}

/** Most recent voters, newest first. Presenter only gets initials, the admin gets names. */
function activity_recent_voters(int $activityId, int $limit = 14): array
{
    return q_all(
        'SELECT p.id, p.name, p.persona, MAX(r.created_at) AS voted_at
           FROM gm_responses r JOIN gm_participants p ON p.id = r.participant_id
          WHERE r.activity_id = ?
          GROUP BY p.id, p.name, p.persona
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
            $voter = ['id' => (int) $v['id'], 'initials' => initials($v['name']), 'emoji' => persona_emoji($v['persona'])];
            $voters[] = $admin ? $voter + ['name' => $v['name']] : $voter;
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

/** One row per participant with their picks per question (a ranking in their order), newest first. */
function activity_response_rows(int $activityId, array $questions, int $limit = 0): array
{
    $order = [];
    $ranked = [];
    foreach ($questions as $q) {
        $ranked[$q['id']] = $q['type'] === 'ranking';
        foreach ($q['options'] as $i => $o) {
            $order[$o['id']] = $i;
        }
    }
    $rows = [];
    foreach (q_all(
        'SELECT r.participant_id, p.name, p.persona, r.question_id, r.option_id, r.value_num, r.created_at
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
            $rows[$pid] = [
                'participant_id' => $pid,
                'name' => $r['name'],
                'emoji' => persona_emoji($r['persona']),
                'answers' => [],
                'at' => iso_utc($r['created_at']),
            ];
        }
        $rows[$pid]['answers'][(int) $r['question_id']][] = [(int) $r['option_id'], (float) $r['value_num']];
    }
    foreach ($rows as &$row) {
        foreach ($row['answers'] as $qid => &$picks) {
            usort($picks, static fn($a, $b) => !empty($ranked[$qid])
                ? $a[1] <=> $b[1]
                : ($order[$a[0]] ?? 0) <=> ($order[$b[0]] ?? 0));
            $picks = array_column($picks, 0);
        }
        unset($picks);
        $row['answers'] = (object) $row['answers'];
    }
    unset($row);
    return array_values($rows);
}

/** The participant's picks: [question_id => [option ids]], a ranking in their order. */
function activity_my_choices(int $activityId, int $participantId): array
{
    $mine = [];
    foreach (q_all(
        'SELECT question_id, option_id FROM gm_responses WHERE activity_id = ? AND participant_id = ? ORDER BY value_num, id',
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
        $ranking = $question['type'] === 'ranking';
        if ($ranking && count($optionIds) !== count($valid)) {
            // Every item exactly once. A short list means an item was added meanwhile.
            throw new UserError('The list just changed. Check your order and submit again.', 409, 'stale');
        }
        if (!$ranking && !$qs['multiple'] && count($optionIds) > 1) {
            throw new UserError('Pick just one option.', 422, 'too_many');
        }
        if (!$ranking && $qs['multiple'] && $qs['max_choices'] > 0 && count($optionIds) > $qs['max_choices']) {
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
        // A ranking stores each item's place (1 = top) in value_num.
        $insert = $pdo->prepare(
            'INSERT INTO gm_responses (activity_id, question_id, participant_id, option_id, value_num, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($optionIds as $i => $oid) {
            $insert->execute([$id, $question['id'], $participant['id'], $oid, $ranking ? $i + 1 : null, $now]);
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
    $tagIds = array_column($settings['tags'], 'id');

    $questions = [];
    foreach (array_values((array) ($in['questions'] ?? [])) as $qIn) {
        if (!is_array($qIn)) {
            continue;
        }
        $n = count($questions) + 1;
        $qType = (string) ($qIn['type'] ?? 'poll');
        if (!in_array($qType, GM_QUESTION_TYPES, true)) {
            throw new UserError('Question ' . $n . ' has an unknown type.', 422, 'bad_type');
        }
        $ranking = $qType === 'ranking';
        $what = $ranking ? 'items' : 'options';
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
            $color = isset($o['color']) && is_string($o['color']) ? $o['color'] : null;
            // Saved options of another question that the editor merged into this one (same name).
            $merge = array_values(array_unique(array_filter(array_map('intval', is_array($o['merge'] ?? null) ? $o['merge'] : []))));
            $options[] = [
                'id' => isset($o['id']) ? (int) $o['id'] : 0,
                'label' => $label,
                'image' => $image,
                'color' => $ranking ? option_color($color, count($options)) : null,
                'tag' => isset($o['tag']) && in_array($o['tag'], $tagIds, true) ? $o['tag'] : null,
                'merge' => array_slice($merge, 0, GM_MAX_OPTIONS * GM_MAX_QUESTIONS),
            ];
        }
        $qTitle = clean_text($qIn['title'] ?? '', 300);
        $qDesc = clean_text($qIn['description'] ?? '', 1000);
        if (!$options && $qTitle === '' && $qDesc === '') {
            continue; // skip an untouched, empty question
        }
        if (count($options) < 2) {
            throw new UserError('Question ' . $n . ' needs at least two ' . $what . '.', 422, 'options_required');
        }
        if (count($options) > GM_MAX_OPTIONS) {
            throw new UserError('Question ' . $n . ' can have up to ' . GM_MAX_OPTIONS . ' ' . $what . '.', 422, 'too_many_options');
        }
        $questions[] = [
            'id' => isset($qIn['id']) ? (int) $qIn['id'] : 0,
            'type' => $qType,
            'title' => $qTitle,
            'description' => $qDesc,
            'settings' => question_normalize_settings($qIn['settings'] ?? [], count($questions), $qType),
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

/**
 * Create ($id = null) or update an activity with its questions and options. Returns the id.
 * Merging: an option may arrive under another question than the one it was saved in
 * (it moves there), or list same-named options it absorbed in "merge" (they are removed).
 * Between two vote questions their votes come along; ranking places can't, so they go.
 */
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

        // Which question each saved option belongs to, and which ones the editor
        // still uses (directly or merged into another option), wherever they now sit.
        $owner = [];
        $optionRow = [];
        foreach ($existing as $eq) {
            foreach ($eq['options'] as $o) {
                $owner[$o['id']] = $eq['id'];
                $optionRow[$o['id']] = $o;
            }
        }
        $direct = [];
        $absorbed = [];
        foreach ($questions as $q) {
            foreach ($q['options'] as $o) {
                if (isset($owner[$o['id']])) {
                    $direct[$o['id']] = true;
                }
                foreach ($o['merge'] as $mid) {
                    $absorbed[$mid] = true;
                }
            }
        }
        $placed = [];

        $keepQuestions = [];
        foreach ($questions as $qPos => $q) {
            $qJson = json_encode($q['settings']);
            $oldOptions = [];
            if ($q['id'] && isset($existing[$q['id']])) {
                $qid = $q['id'];
                q(
                    'UPDATE gm_questions SET type = ?, title = ?, description = ?, settings = ?, position = ? WHERE id = ?',
                    [$q['type'], $q['title'], $q['description'], $qJson, $qPos, $qid]
                );
                if ($existing[$qid]['type'] !== $q['type']) {
                    // Votes and rankings are stored differently, so a new type starts afresh.
                    q('DELETE FROM gm_responses WHERE question_id = ?', [$qid]);
                }
                foreach ($existing[$qid]['options'] as $o) {
                    $oldOptions[$o['id']] = $o;
                }
            } else {
                q(
                    'INSERT INTO gm_questions (activity_id, type, title, description, settings, position) VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $q['type'], $q['title'], $q['description'], $qJson, $qPos]
                );
                $qid = (int) $pdo->lastInsertId();
            }
            $keepQuestions[$qid] = true;
            // Votes from another question can join this one only if both are, and stay, votes.
            $takesVotes = $q['type'] === 'poll' && (!isset($existing[$qid]) || $existing[$qid]['type'] === 'poll');

            $keepOptions = [];
            foreach ($q['options'] as $pos => $o) {
                $oid = $o['id'];
                if ($oid && isset($owner[$oid]) && !isset($placed[$oid])) {
                    // Saved before: here already, or merged in from another question.
                    $from = $owner[$oid];
                    q(
                        'UPDATE gm_options SET question_id = ?, label = ?, image = ?, color = ?, tag = ?, position = ? WHERE id = ?',
                        [$qid, $o['label'], $o['image'], $o['color'], $o['tag'], $pos, $oid]
                    );
                    if ($from !== $qid) {
                        if ($takesVotes && $existing[$from]['type'] === 'poll') {
                            q('UPDATE gm_responses SET question_id = ? WHERE option_id = ?', [$qid, $oid]);
                        } else {
                            q('DELETE FROM gm_responses WHERE option_id = ?', [$oid]);
                        }
                    }
                    if ($optionRow[$oid]['image'] && $optionRow[$oid]['image'] !== $o['image']) {
                        $removedImages[] = $optionRow[$oid]['image'];
                    }
                } else {
                    q(
                        'INSERT INTO gm_options (activity_id, question_id, label, image, color, tag, position) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$id, $qid, $o['label'], $o['image'], $o['color'], $o['tag'], $pos]
                    );
                    $oid = (int) $pdo->lastInsertId();
                }
                $placed[$oid] = true;
                $keepOptions[$oid] = true;

                // Same-named options merged into this one: their votes join it, then they go.
                foreach ($o['merge'] as $mid) {
                    if (!isset($owner[$mid]) || isset($direct[$mid]) || isset($placed[$mid])) {
                        continue; // not this activity's, or still an option in its own right
                    }
                    $placed[$mid] = true;
                    if ($takesVotes && $existing[$owner[$mid]]['type'] === 'poll') {
                        // Someone who picked both keeps a single pick.
                        q(
                            'DELETE r FROM gm_responses r
                               JOIN gm_responses k ON k.participant_id = r.participant_id AND k.option_id = ?
                              WHERE r.option_id = ?',
                            [$oid, $mid]
                        );
                        q('UPDATE gm_responses SET option_id = ?, question_id = ? WHERE option_id = ?', [$oid, $qid, $mid]);
                    }
                    q('DELETE FROM gm_options WHERE id = ?', [$mid]); // and any answers it still has
                    if ($optionRow[$mid]['image']) {
                        $removedImages[] = $optionRow[$mid]['image'];
                    }
                }
            }
            foreach ($oldOptions as $oid => $o) {
                // Options moved to (or merged into) another question are handled there.
                if (!isset($keepOptions[$oid]) && !isset($direct[$oid]) && !isset($absorbed[$oid])) {
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
                'INSERT INTO gm_questions (activity_id, type, title, description, settings, position) VALUES (?, ?, ?, ?, ?, ?)',
                [$newId, $q['type'], $q['title'], $q['description'], $q['settings'], $q['position']]
            );
            $newQid = (int) $pdo->lastInsertId();
            q(
                'INSERT INTO gm_options (activity_id, question_id, label, image, color, tag, position)
                 SELECT ?, ?, label, image, color, tag, position FROM gm_options WHERE question_id = ? ORDER BY position, id',
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
                (SELECT COUNT(*) FROM gm_questions q WHERE q.activity_id = a.id AND q.type = \'ranking\') AS ranking_count,
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
            'ranking_count' => (int) $r['ranking_count'],
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
                'type' => $q['type'],
                'title' => $q['title'],
                'description' => $q['description'],
                'settings' => $q['settings'],
                'options' => array_map(static function ($o) {
                    return ['id' => $o['id'], 'label' => $o['label'], 'image' => $o['image'], 'image_url' => image_url($o['image']), 'color' => $o['color'], 'tag' => $o['tag']];
                }, $q['options']),
            ];
        }, activity_questions((int) $activity['id'])),
        'votes' => (int) q_val('SELECT COUNT(DISTINCT participant_id) FROM gm_responses WHERE activity_id = ?', [$activity['id']]),
    ];
}
