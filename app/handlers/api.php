<?php
/**
 * Public JSON API used by participants' phones and the presenter screen.
 */
declare(strict_types=1);

/** What a participant's phone needs: the activity, their own answers and (if allowed) results. */
function participant_payload(?array $activity, ?array $me): array
{
    $payload = ['me' => participant_public($me), 'activity' => null];
    if (!$activity) {
        return $payload;
    }
    $questions = activity_questions((int) $activity['id']);
    $settings = activity_settings($activity);
    $my = $me ? activity_my_choices((int) $activity['id'], (int) $me['id']) : [];

    $payload['version'] = (int) $activity['version'];
    $payload['activity'] = activity_public($activity, $questions);
    $payload['my'] = (object) $my; // {question_id: [option ids]}
    $payload['results'] = ($my && $settings['show_results'] && !$activity['results_hidden'])
        ? activity_results($activity, $questions)
        : null;
    return $payload;
}

function api_find_activity(string $code): array
{
    $activity = activity_by_code($code);
    if (!$activity) {
        json_error('We couldn’t find that activity. Check the code and try again.', 404, 'not_found');
    }
    return $activity;
}

function api_me(): void
{
    json_out(['me' => participant_public(participant_current())]);
}

/** Save the participant's name and, if given, the persona they picked. */
function api_me_save(): void
{
    $in = input_json();
    $name = participant_validate_name($in['name'] ?? '');
    $persona = isset($in['persona']) && is_string($in['persona']) && $in['persona'] !== '' ? $in['persona'] : null;
    $hold = persona_valid_hold($in['hold'] ?? null);
    $me = participant_current();
    $changed = $me && ($me['name'] !== $name || ($persona !== null && $persona !== $me['persona']));
    $me = db_retry(static fn() => db_tx(function () use ($me, $name, $persona, $hold) {
        $me = $me ? participant_rename($me, $name) : participant_create($name);
        if ($persona !== null && $persona !== ($me['persona'] ?? null)) {
            persona_claim((int) $me['id'], $persona, $hold);
            $me['persona'] = $persona;
        }
        return $me;
    }));
    if ($changed) {
        participant_touch_activities((int) $me['id']);
    }
    json_out(['me' => participant_public($me)]);
}

/** The personas this visitor can pick from; holds the suggested one (or the one in "want"). */
function api_personas(): void
{
    $in = input_json();
    $want = isset($in['want']) && is_string($in['want']) && $in['want'] !== '' ? $in['want'] : null;
    json_out(persona_choices(participant_current(), persona_valid_hold($in['hold'] ?? null), $want));
}

function api_me_forget(): void
{
    participant_forget();
    json_out(['me' => null]);
}

function api_activity(string $code): void
{
    $activity = api_find_activity($code);
    // Phones refresh every few seconds; answer "nothing new" as cheaply as possible.
    if (isset($_GET['v']) && (int) $_GET['v'] === (int) $activity['version']) {
        json_out(['changed' => false, 'version' => (int) $activity['version']]);
    }
    json_out(participant_payload($activity, participant_current()));
}

function api_vote(string $code): void
{
    $activity = api_find_activity($code);
    $me = participant_current();
    if (!$me) {
        json_error('Tell us your name first.', 401, 'no_participant');
    }
    $in = input_json();
    $questionId = isset($in['question_id']) ? (int) $in['question_id'] : null;
    activity_vote($activity, $me, $questionId, $in['option_ids'] ?? []);
    json_out(participant_payload(activity_by_id((int) $activity['id']), $me));
}

/**
 * Live results for the presenter screen. With ?v=<version> the request waits
 * (up to ~20s) until something changes, so new votes appear within a moment.
 */
function api_present(string $code): void
{
    $activity = api_find_activity($code);
    if (!present_key_ok($activity)) {
        json_error('This presenter link is no longer valid.', 403, 'bad_key');
    }
    $since = (int) ($_GET['v'] ?? 0);
    if ($since > 0 && $since === (int) $activity['version']) {
        $activity = activity_wait_for_change((int) $activity['id'], $since);
        if (!$activity) {
            json_error('This activity was deleted.', 404, 'not_found');
        }
        if ((int) $activity['version'] === $since) {
            json_out(['changed' => false, 'version' => $since]);
        }
    }
    json_out(activity_live_payload($activity, false));
}
