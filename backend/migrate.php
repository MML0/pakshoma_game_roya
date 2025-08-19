<?php
// migrate.php — create DB schema for game

header("Content-Type: text/plain");

// --- Configuration ---
$config = [
    'database' => [
        'host'     => 'localhost',
        'dbname'   => 'pakshoma_game',
        'username' => 'root',
        'password' => '',
    ],
    'admin' => [
        'password' => 'admin123',
    ],
];

function require_admin($config) {
    $pass = $_GET['admin_password'] ?? ($_POST['admin_password'] ?? null);
    if ($pass !== $config['admin']['password']) {
        http_response_code(401);
        die("Unauthorized\n");
    }
}

function pdo($config) {
    return new PDO(
        "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset=utf8mb4",
        $config['database']['username'],
        $config['database']['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

// --- Run ---
require_admin($config);

try {
    $db = pdo($config);

    echo "Connected to database.\n";

    // Create tables if not exist (idempotent)
    $db->exec("
        CREATE TABLE IF NOT EXISTS game_state (
            id TINYINT PRIMARY KEY CHECK (id = 1),
            state ENUM('waiting','playing','ending') NOT NULL DEFAULT 'waiting',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $db->exec("INSERT IGNORE INTO game_state (id, state) VALUES (1, 'waiting');");
    echo "Created game_state table.\n";

    $db->exec("
        CREATE TABLE IF NOT EXISTS rounds (
            id INT AUTO_INCREMENT PRIMARY KEY,
            started_at TIMESTAMP NULL,
            ended_at   TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Created rounds table.\n";

    $db->exec("
        CREATE TABLE IF NOT EXISTS current_answers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(64) NOT NULL,
            question_id INT NOT NULL,
            answer TINYINT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_q (question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Created current_answers table.\n";

    $db->exec("
        CREATE TABLE IF NOT EXISTS answers_archive (
            id INT AUTO_INCREMENT PRIMARY KEY,
            round_id INT NOT NULL,
            user_id VARCHAR(64) NOT NULL,
            question_id INT NOT NULL,
            answer TINYINT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Created answers_archive table.\n";

    $db->exec("
        CREATE TABLE IF NOT EXISTS td_signal (
            id TINYINT PRIMARY KEY CHECK (id = 1),
            flag TINYINT NOT NULL DEFAULT 0,
            user_id VARCHAR(64) NULL,
            question_id INT NULL,
            answer TINYINT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $db->exec("INSERT IGNORE INTO td_signal (id, flag) VALUES (1, 0);");
    echo "Created td_signal table.\n";

    echo "Migration complete!\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage() . "\n";
}
