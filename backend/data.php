<?php
/**
 * game_api.php — single-file backend for a 1-player-at-a-time quiz/game
 *
 * Endpoints (all JSON):
 *  - POST  ?action=migrate&admin_password=...           → create tables if not exist
 *  - POST  ?action=set_state&admin_password=...         → body: { "state": "waiting|playing|ending" }
 *  - GET   ?action=get_state                            → returns current state
 *  - POST  ?action=set_answer                           → body: { "user_id":"abc", "qa":"1-2" } OR { "user_id":"abc", "question_id":1, "answer":2 }
 *  - GET   ?action=get_answers                          → returns current session answers (not yet archived)
 *  - GET   ?action=poll                                 → TouchDesigner polls; returns flag/payload; resets flag to 0 when delivered
 *  - POST  ?action=end_game&admin_password=...          → archives current answers and resets for next player; sets state to "waiting"
 *  - POST  ?action=reset_round&admin_password=...       → force-clear current answers & flag; sets state to "waiting"
 *
 * Notes:
 *  - Single user at a time (global session). We still store user_id (you generate it client-side).
 *  - Each question has 2 answers; we accept "qa" like "2-1" (q=2, a=1).
 *  - On every new answer, we set a one-shot flag (for TouchDesigner) with the last payload.
 *  - On poll, if flag==1 → return payload and atomically set flag=0 so it’s delivered exactly once.
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// --- Configuration (edit as needed) ---
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
function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function require_admin($config) {
    $pass = $_GET['admin_password'] ?? ($_POST['admin_password'] ?? null);
    if ($pass !== $config['admin']['password']) {
        respond(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }
}
function pdo($config) {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset=utf8mb4",
            $config['database']['username'],
            $config['database']['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        respond(['status' => 'error', 'message' => 'Database connection failed'], 500);
    }
}

// --- Parse input ---
$action = $_GET['action'] ?? null;
$raw    = file_get_contents('php://input');
$input  = json_decode($raw, true);
if (!is_array($input)) $input = [];

// --- Actions ---
switch ($action) {


    case 'set_state': { // POST (admin)
        require_admin($config);
        $state = $input['state'] ?? null;
        if (!in_array($state, ['waiting','playing','ending'], true)) {
            respond(['status' => 'error', 'message' => 'Invalid state'], 422);
        }
        $db = pdo($config);
        $stmt = $db->prepare("UPDATE game_state SET state = :s WHERE id = 1");
        $stmt->execute([':s' => $state]);

        // optional: when moving to playing, start a fresh round if none open
        if ($state === 'playing') {
            // start a new round only if there is no open round
            $open = $db->query("SELECT id FROM rounds WHERE ended_at IS NULL ORDER BY id DESC LIMIT 1")->fetch();
            if (!$open) {
                $db->exec("INSERT INTO rounds (started_at) VALUES (CURRENT_TIMESTAMP)");
            }
        }
        respond(['status' => 'ok', 'state' => $state]);
    }

    case 'get_state': { // GET
        $db = pdo($config);
        $row = $db->query("SELECT state, updated_at FROM game_state WHERE id = 1")->fetch();
        if (!$row) respond(['status' => 'error', 'message' => 'State not initialized'], 500);
        respond(['status' => 'ok', 'state' => $row['state'], 'updated_at' => $row['updated_at']]);
    }
    case 'get_update': { // GET
        $db = pdo($config);
        $row = $db->query("SELECT state, updated_at FROM game_state WHERE id = 1")->fetch();
        if (!$row) respond(['status' => 'error', 'message' => 'State not initialized'], 500);

        // Step 1: find last user_id in current_answers
        $lastUser = $db->query("
            SELECT user_id 
            FROM current_answers 
            ORDER BY created_at DESC 
            LIMIT 1
        ")->fetchColumn();

        if (!$lastUser) {
            respond(['status' => 'ok', 'state' => $row['state'], 'updated_at' => $row['updated_at'], 'answers' => []]);

        }

        // Step 2: get all answers for that user
        $stmt = $db->prepare("
            SELECT user_id, question_id, answer, created_at 
            FROM current_answers 
            WHERE user_id = :uid 
            ORDER BY question_id ASC
        ");
        $stmt->execute([':uid' => $lastUser]);
        $ans_rows = $stmt->fetchAll();

        respond(['status' => 'ok', 'state' => $row['state'], 'updated_at' => $row['updated_at'],'answers'=>$ans_rows]);
    }
    case 'set_answer': { // POST
        // body: { user_id, qa: "1-2" } OR { user_id, question_id, answer }
        $user_id = trim((string)($input['user_id'] ?? ''));
        if ($user_id === '') respond(['status' => 'error', 'message' => 'user_id required'], 422);

        if (isset($input['qa'])) {
            $qa = (string)$input['qa'];
            if (!preg_match('/^\s*(\d+)\s*-\s*([1234])\s*$/', $qa, $m)) {
                respond(['status' => 'error', 'message' => 'qa must look like \"2-1\"'], 422);
            }
            $question_id = (int)$m[1];
            $answer      = (int)$m[2];
        } else {
            $question_id = isset($input['question_id']) ? (int)$input['question_id'] : null;
            $answer      = isset($input['answer']) ? (int)$input['answer'] : null;
            if (!$question_id || !in_array($answer, [1,2], true)) {
                respond(['status' => 'error', 'message' => 'question_id and answer(1|2) required'], 422);
            }
        }

        $db = pdo($config);

        // Ensure we are in playing state
        $state = $db->query("SELECT state FROM game_state WHERE id = 1")->fetchColumn();
        if ($state !== 'playing') {
            respond(['status' => 'error', 'message' => 'Game is not in playing state'], 409);
        }

        // Upsert the current answer for that question (single active player/session)
        $db->beginTransaction();
        try {
            // If there is no open round, start one
            $open = $db->query("SELECT id FROM rounds WHERE ended_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetch();
            if (!$open) {
                $db->exec("INSERT INTO rounds (started_at) VALUES (CURRENT_TIMESTAMP)");
                $round_id = (int)$db->lastInsertId();
            } else {
                $round_id = (int)$open['id'];
            }

            // Insert or update current_answers for that question
            $stmt = $db->prepare("
                INSERT INTO current_answers (user_id, question_id, answer)
                VALUES (:u, :q, :a)
                ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), answer = VALUES(answer), created_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([':u'=>$user_id, ':q'=>$question_id, ':a'=>$answer]);

            // Set one-shot signal for TouchDesigner
            $stmt = $db->prepare("
                UPDATE td_signal
                SET flag = 1, user_id = :u, question_id = :q, answer = :a, updated_at = CURRENT_TIMESTAMP
                WHERE id = 1
            ");
            $stmt->execute([':u'=>$user_id, ':q'=>$question_id, ':a'=>$answer]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            respond(['status'=>'error','message'=>'Failed to set answer'], 500);
        }

        respond(['status' => 'ok', 'saved' => ['user_id'=>$user_id,'question_id'=>$question_id,'answer'=>$answer], 'round_id'=>$round_id ?? null]);
    }

    case 'get_answers': { 
        $db = pdo($config);

        // Step 1: find last user_id in current_answers
        $lastUser = $db->query("
            SELECT user_id 
            FROM current_answers 
            ORDER BY created_at DESC 
            LIMIT 1
        ")->fetchColumn();

        if (!$lastUser) {
            respond(['status' => 'ok', 'answers' => []]);
        }

        // Step 2: get all answers for that user
        $stmt = $db->prepare("
            SELECT user_id, question_id, answer, created_at 
            FROM current_answers 
            WHERE user_id = :uid 
            ORDER BY question_id ASC
        ");
        $stmt->execute([':uid' => $lastUser]);
        $rows = $stmt->fetchAll();

        respond(['status' => 'ok', 'user_id' => $lastUser, 'answers' => $rows]);
    }

    case 'poll': { // GET — TouchDesigner one-shot
        $db = pdo($config);

        // Lock row so we can atomically read+reset the flag
        $db->beginTransaction();
        try {
            $row = $db->query("SELECT flag, user_id, question_id, answer, updated_at FROM td_signal WHERE id = 1 FOR UPDATE")->fetch();
            if (!$row) {
                // initialize if somehow missing
                $db->exec("INSERT IGNORE INTO td_signal (id, flag) VALUES (1, 0)");
                $row = ['flag'=>0,'user_id'=>null,'question_id'=>null,'answer'=>null,'updated_at'=>null];
            }
            if ((int)$row['flag'] === 1) {
                // reset now (one-shot)
                $db->exec("UPDATE td_signal SET flag = 0 WHERE id = 1");
                $db->commit();
                respond([
                    'status'=>'ok',
                    'flag'=>1,
                    'payload'=>[
                        'user_id'=>$row['user_id'],
                        'question_id'=>(int)$row['question_id'],
                        'answer'=>(int)$row['answer'],
                        'updated_at'=>$row['updated_at']
                    ]
                ]);
            } else {
                $db->commit();
                respond(['status'=>'ok','flag'=>0]);
            }
        } catch (Throwable $e) {
            $db->rollBack();
            respond(['status'=>'error','message'=>'Poll failed'], 500);
        }
    }

    case 'end_game': { // POST (admin) — archive and reset for next player, set state=waiting
        require_admin($config);
        $db = pdo($config);

        $db->beginTransaction();
        try {
            // get or create open round
            $open = $db->query("SELECT id FROM rounds WHERE ended_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetch();
            if (!$open) {
                // If no open round, create one to archive into
                $db->exec("INSERT INTO rounds (started_at) VALUES (CURRENT_TIMESTAMP)");
                $round_id = (int)$db->lastInsertId();
            } else {
                $round_id = (int)$open['id'];
            }

            // Move all current answers to archive
            $rows = $db->query("SELECT user_id, question_id, answer, created_at FROM current_answers")->fetchAll();
            if ($rows) {
                $stmt = $db->prepare("
                    INSERT INTO answers_archive (round_id, user_id, question_id, answer, created_at)
                    VALUES (:r, :u, :q, :a, :c)
                ");
                foreach ($rows as $r) {
                    $stmt->execute([
                        ':r'=>$round_id,
                        ':u'=>$r['user_id'],
                        ':q'=>$r['question_id'],
                        ':a'=>$r['answer'],
                        ':c'=>$r['created_at'],
                    ]);
                }
            }

            // Clear current answers
            // $db->exec("TRUNCATE TABLE current_answers");
            $db->exec("DELETE FROM current_answers");

            // Close the round
            $db->exec("UPDATE rounds SET ended_at = CURRENT_TIMESTAMP WHERE id = {$round_id}");

            // Reset TD signal
            $db->exec("UPDATE td_signal SET flag = 0, user_id = NULL, question_id = NULL, answer = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = 1");

            // Set game state to waiting
            $db->exec("UPDATE game_state SET state = 'waiting' WHERE id = 1");

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            respond(['status'=>'error','message'=>'End game failed'], 500);
        }

        respond(['status'=>'ok','message'=>'Archived and reset','round_id'=>$round_id]);
    }

    case 'reset_round': { // POST (admin) — force reset without archiving
        require_admin($config);
        $db = pdo($config);
        $db->beginTransaction();
        try {
            $db->exec("TRUNCATE TABLE current_answers");
            $db->exec("UPDATE td_signal SET flag = 0, user_id = NULL, question_id = NULL, answer = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
            $db->exec("UPDATE game_state SET state = 'waiting' WHERE id = 1");
            $db->commit();
            respond(['status'=>'ok','message'=>'Round reset']);
        } catch (Throwable $e) {
            $db->rollBack();
            respond(['status'=>'error','message'=>'Reset failed'], 500);
        }
    }

    default:
        respond(['status' => 'error', 'message' => 'Unknown or missing action'], 404);
}
