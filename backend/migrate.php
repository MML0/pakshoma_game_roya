<?php
// migrate.php — robust, idempotent schema setup & FK fix

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

// --- Helpers ---
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
function tableExists(PDO $db, $schema, $table) {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $stmt->execute([$schema, $table]);
    return (bool)$stmt->fetchColumn();
}
function columnExists(PDO $db, $schema, $table, $column) {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->execute([$schema, $table, $column]);
    return (bool)$stmt->fetchColumn();
}
function indexExists(PDO $db, $schema, $table, $index) {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?");
    $stmt->execute([$schema, $table, $index]);
    return (bool)$stmt->fetchColumn();
}
function fkExists(PDO $db, $schema, $table, $refTable, $refColumn, $constraintName = null) {
    if ($constraintName) {
        $stmt = $db->prepare("
            SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA=? AND CONSTRAINT_NAME=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME=?");
        $stmt->execute([$schema, $constraintName, $table, $refTable]);
        return (bool)$stmt->fetchColumn();
    }
    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=? AND TABLE_NAME=?
          AND REFERENCED_TABLE_NAME=? AND REFERENCED_COLUMN_NAME=?");
    $stmt->execute([$schema, $table, $refTable, $refColumn]);
    return (bool)$stmt->fetchColumn();
}
function getTableCollation(PDO $db, $schema, $table) {
    $stmt = $db->prepare("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $stmt->execute([$schema, $table]);
    return $stmt->fetchColumn() ?: null;
}

require_admin($config);

try {
    $db = pdo($config);
    $schema = $config['database']['dbname'];

    echo "Connected to database.\n";

    // ---------- Core tables (idempotent) ----------
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

    // ---------- Ensure users table is present; if already exists with INT id PK, extend it ----------
    if (!tableExists($db, $schema, 'users')) {
        // Create a fresh users table in your preferred format
        $db->exec("
            CREATE TABLE users (
                user_id VARCHAR(64) PRIMARY KEY,
                full_name VARCHAR(191) NOT NULL,
                phone VARCHAR(64) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        echo "Created users table (new format).\n";
    } else {
        echo "Found existing users table.\n";

        // Ensure utf8mb4 collation (match child table)
        $coll = getTableCollation($db, $schema, 'users');
        if (!$coll || stripos($coll, 'utf8mb4_') !== 0) {
            $db->exec("ALTER TABLE `users` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
            echo "Converted users table collation to utf8mb4.\n";
        }

        // Add user_id if missing
        if (!columnExists($db, $schema, 'users', 'user_id')) {
            $db->exec("ALTER TABLE `users` ADD COLUMN `user_id` VARCHAR(64) NULL;");
            echo "Added users.user_id column.\n";

            // Try to populate from existing INT id if present
            $hasIntId = columnExists($db, $schema, 'users', 'id');
            if ($hasIntId) {
                // Populate with deterministic values (user_000123)
                $db->exec("UPDATE `users` SET `user_id` = CONCAT('user_', LPAD(id, 6, '0')) WHERE user_id IS NULL OR user_id = '';");
                echo "Populated users.user_id from users.id.\n";
            } else {
                // Fallback: generate from existing row data (hash)
                $db->exec("UPDATE `users` SET `user_id` = CONCAT('user_', SUBSTRING(MD5(CONCAT(IFNULL(name,''), IFNULL(last_name,''), IFNULL(phone_number,''), IFNULL(created_at,''))),1,12)) WHERE user_id IS NULL OR user_id='';");
                echo "Populated users.user_id from name/phone hash (no id column).\n";
            }
        }

        // Ensure NOT NULL + unique index on user_id
        // (Make NOT NULL only after it's filled)
        $db->exec("UPDATE `users` SET `user_id` = CONCAT('user_', SUBSTRING(MD5(CONCAT(IFNULL(user_id,''), RAND())),1,12)) WHERE user_id IS NULL OR user_id='';");
        $db->exec("ALTER TABLE `users` MODIFY COLUMN `user_id` VARCHAR(64) NOT NULL;");
        if (!indexExists($db, $schema, 'users', 'ux_users_user_id')) {
            $db->exec("ALTER TABLE `users` ADD UNIQUE KEY `ux_users_user_id` (`user_id`);");
        }
        echo "Ensured users.user_id is NOT NULL & UNIQUE.\n";

        // (Optional) If you want a normalized schema, you may also want to align columns:
        // rename phone_number -> phone, name/last_name -> full_name (skip here to avoid data loss).
    }

    // ---------- Create/align user_responses ----------
    $db->exec("
        CREATE TABLE IF NOT EXISTS user_responses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(64) NOT NULL,
            question_id INT NOT NULL,
            answer TINYINT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_q (user_id, question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Created user_responses table (exists or created).\n";

    // Make sure user_responses collation is utf8mb4
    $collUR = getTableCollation($db, $schema, 'user_responses');
    if (!$collUR || stripos($collUR, 'utf8mb4_') !== 0) {
        $db->exec("ALTER TABLE `user_responses` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
        echo "Converted user_responses collation to utf8mb4.\n";
    }

    // ---------- Add FK user_responses.user_id → users.user_id if not exists ----------
    $fkName = 'fk_user_responses_user';
    $fkPresent = fkExists($db, $schema, 'user_responses', 'users', 'user_id') || fkExists($db, $schema, 'user_responses', 'users', 'user_id', $fkName);
    if (!$fkPresent) {
        try {
            $db->exec("
                ALTER TABLE `user_responses`
                ADD CONSTRAINT `{$fkName}`
                FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`)
                ON DELETE CASCADE;
            ");
            echo "Foreign key {$fkName} added successfully.\n";
        } catch (Throwable $e) {
            echo "Attempt to add FK failed: " . $e->getMessage() . "\n";
            echo "---- Diagnostic: SHOW CREATE TABLE users ----\n";
            $row = $db->query("SHOW CREATE TABLE `users`")->fetch();
            echo ($row['Create Table'] ?? json_encode($row)) . "\n\n";
            echo "---- Diagnostic: SHOW CREATE TABLE user_responses ----\n";
            $row2 = $db->query("SHOW CREATE TABLE `user_responses`")->fetch();
            echo ($row2['Create Table'] ?? json_encode($row2)) . "\n\n";
            echo "---- Diagnostic: SHOW ENGINE INNODB STATUS (LATEST FOREIGN KEY ERROR) ----\n";
            $diag = $db->query("SHOW ENGINE INNODB STATUS")->fetchAll();
            if (isset($diag[0])) {
                $text = implode("\n", array_map(function($r){ return is_array($r) ? implode(' ', $r) : (string)$r; }, $diag[0]));
                echo substr($text, 0, 8000) . "\n";
            } else {
                echo "No InnoDB status available.\n";
            }
            echo "FK not added. See diagnostics above. You can re-run after fixing issues.\n";
        }
    } else {
        echo "Foreign key already present.\n";
    }

    echo "Migration complete!\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage() . "\n";
}
