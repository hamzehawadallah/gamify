<?php
/**
 * Database connection, query helpers and schema migrations.
 */
declare(strict_types=1);

const GM_SCHEMA_VERSION = 3;

function db_connect(array $c): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $c['host'] ?? 'localhost',
        (int) ($c['port'] ?? 3306),
        $c['name'] ?? ''
    );
    $pdo = new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db_connect((array) config('db', []));
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_val(string $sql, array $params = [])
{
    $val = q($sql, $params)->fetchColumn();
    return $val === false ? null : $val;
}

/** Run $fn inside a transaction; rolls back on any exception. */
function db_tx(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Run $fn, retrying a few times if MySQL picks it as a deadlock victim
 * (it can happen when many phones claim personas in the same instant).
 */
function db_retry(callable $fn, int $tries = 4)
{
    for ($attempt = 1; ; $attempt++) {
        try {
            return $fn();
        } catch (PDOException $e) {
            $deadlock = $e->getCode() === '40001' || in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true);
            if (!$deadlock || $attempt >= $tries || db()->inTransaction()) {
                throw $e;
            }
            usleep(random_int(20000, 80000) * $attempt);
        }
    }
}

/**
 * Every schema change gets a new numbered step. Steps only ever run once.
 * To change the schema later: add the next step and bump GM_SCHEMA_VERSION.
 * A step is a list of SQL strings and/or callables taking the PDO connection.
 */
function gm_migrations(): array
{
    $table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        1 => [
            "CREATE TABLE IF NOT EXISTS gm_participants (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                token CHAR(40) NOT NULL,
                name VARCHAR(80) NOT NULL,
                created_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_participant_token (token)
            ) $table",
            "CREATE TABLE IF NOT EXISTS gm_activities (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code CHAR(6) NOT NULL,
                type VARCHAR(24) NOT NULL DEFAULT 'poll',
                title VARCHAR(500) NOT NULL,
                description TEXT NULL,
                settings TEXT NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'open',
                results_hidden TINYINT(1) NOT NULL DEFAULT 0,
                present_key CHAR(24) NOT NULL,
                version INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_activity_code (code)
            ) $table",
            "CREATE TABLE IF NOT EXISTS gm_options (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                activity_id INT UNSIGNED NOT NULL,
                label VARCHAR(300) NOT NULL DEFAULT '',
                image VARCHAR(64) NULL,
                position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_options_activity (activity_id, position),
                CONSTRAINT fk_options_activity FOREIGN KEY (activity_id)
                    REFERENCES gm_activities (id) ON DELETE CASCADE
            ) $table",
            "CREATE TABLE IF NOT EXISTS gm_responses (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                activity_id INT UNSIGNED NOT NULL,
                participant_id INT UNSIGNED NOT NULL,
                option_id INT UNSIGNED NULL,
                value_text TEXT NULL,
                value_num DECIMAL(10,2) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_responses_activity_option (activity_id, option_id),
                KEY idx_responses_activity_participant (activity_id, participant_id),
                CONSTRAINT fk_responses_activity FOREIGN KEY (activity_id)
                    REFERENCES gm_activities (id) ON DELETE CASCADE,
                CONSTRAINT fk_responses_participant FOREIGN KEY (participant_id)
                    REFERENCES gm_participants (id) ON DELETE CASCADE,
                CONSTRAINT fk_responses_option FOREIGN KEY (option_id)
                    REFERENCES gm_options (id) ON DELETE CASCADE
            ) $table",
            "CREATE TABLE IF NOT EXISTS gm_login_attempts (
                ip VARCHAR(45) NOT NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                last_at DATETIME NOT NULL,
                PRIMARY KEY (ip)
            ) $table",
        ],
        // Activities hold one or more questions; options and votes belong to a question.
        2 => [
            "CREATE TABLE IF NOT EXISTS gm_questions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                activity_id INT UNSIGNED NOT NULL,
                title VARCHAR(300) NOT NULL DEFAULT '',
                description TEXT NULL,
                settings TEXT NOT NULL,
                position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_questions_activity (activity_id, position),
                CONSTRAINT fk_questions_activity FOREIGN KEY (activity_id)
                    REFERENCES gm_activities (id) ON DELETE CASCADE
            ) $table",
            'db_migrate_questions',
        ],
        // Each participant picks a persona (emoji); one owner per persona.
        3 => [
            'db_migrate_personas',
            "CREATE TABLE IF NOT EXISTS gm_persona_holds (
                persona VARCHAR(32) NOT NULL,
                hold_token CHAR(32) NOT NULL,
                expires_at DATETIME NOT NULL,
                PRIMARY KEY (persona),
                KEY idx_persona_holds_token (hold_token)
            ) $table",
        ],
    ];
}

function db_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS gm_meta (
        k VARCHAR(64) NOT NULL,
        v TEXT NULL,
        PRIMARY KEY (k)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $pdo->query("SELECT GET_LOCK('gm_migrate', 15)");
    try {
        $current = (int) ($pdo->query("SELECT v FROM gm_meta WHERE k = 'schema_version'")->fetchColumn() ?: 0);
        foreach (gm_migrations() as $version => $statements) {
            if ($version <= $current) {
                continue;
            }
            foreach ($statements as $sql) {
                if (is_callable($sql)) {
                    $sql($pdo);
                } else {
                    $pdo->exec($sql);
                }
            }
            $stmt = $pdo->prepare("REPLACE INTO gm_meta (k, v) VALUES ('schema_version', ?)");
            $stmt->execute([(string) $version]);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('gm_migrate')");
    }
}

/** Make sure the tables exist. A marker file avoids checking the database on every request. */
function ensure_schema(): void
{
    $marker = GM_ROOT . '/storage/schema_version';
    if (is_file($marker) && trim((string) @file_get_contents($marker)) === (string) GM_SCHEMA_VERSION) {
        return;
    }
    db_migrate(db());
    @file_put_contents($marker, (string) GM_SCHEMA_VERSION);
}

function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function db_constraint_exists(PDO $pdo, string $table, string $name): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
    $stmt->execute([$table, $name]);
    return (bool) $stmt->fetchColumn();
}

/** Schema step 3: the persona column. Safe to re-run. */
function db_migrate_personas(PDO $pdo): void
{
    if (!db_column_exists($pdo, 'gm_participants', 'persona')) {
        $pdo->exec('ALTER TABLE gm_participants
            ADD COLUMN persona VARCHAR(32) NULL AFTER name,
            ADD UNIQUE KEY uq_participant_persona (persona)');
    }
}

/**
 * Schema step 2: move each existing activity's options and votes into a
 * first question, carrying over its single/multiple-choice settings.
 * Safe to re-run if it was interrupted halfway.
 */
function db_migrate_questions(PDO $pdo): void
{
    foreach (['gm_options', 'gm_responses'] as $table) {
        if (!db_column_exists($pdo, $table, 'question_id')) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN question_id INT UNSIGNED NULL AFTER activity_id");
        }
    }

    $activities = $pdo->query(
        'SELECT a.id, a.settings FROM gm_activities a
          WHERE NOT EXISTS (SELECT 1 FROM gm_questions q WHERE q.activity_id = a.id)'
    )->fetchAll();
    $insert = $pdo->prepare("INSERT INTO gm_questions (activity_id, title, description, settings, position) VALUES (?, '', '', ?, 0)");
    foreach ($activities as $a) {
        $old = json_decode((string) $a['settings'], true) ?: [];
        $insert->execute([$a['id'], json_encode([
            'multiple' => !empty($old['multiple']),
            'max_choices' => (int) ($old['max_choices'] ?? 0),
            'color' => 'blue',
        ])]);
    }

    foreach (['gm_options', 'gm_responses'] as $table) {
        $pdo->exec("UPDATE $table t
                      JOIN (SELECT activity_id, MIN(id) AS qid FROM gm_questions GROUP BY activity_id) q
                        ON q.activity_id = t.activity_id
                       SET t.question_id = q.qid
                     WHERE t.question_id IS NULL");
    }

    if (!db_constraint_exists($pdo, 'gm_options', 'fk_options_question')) {
        $pdo->exec('ALTER TABLE gm_options
            MODIFY question_id INT UNSIGNED NOT NULL,
            ADD KEY idx_options_question (question_id, position),
            ADD CONSTRAINT fk_options_question FOREIGN KEY (question_id) REFERENCES gm_questions (id) ON DELETE CASCADE');
    }
    if (!db_constraint_exists($pdo, 'gm_responses', 'fk_responses_question')) {
        $pdo->exec('ALTER TABLE gm_responses
            MODIFY question_id INT UNSIGNED NOT NULL,
            ADD KEY idx_responses_question (question_id, participant_id),
            ADD CONSTRAINT fk_responses_question FOREIGN KEY (question_id) REFERENCES gm_questions (id) ON DELETE CASCADE');
    }
}
