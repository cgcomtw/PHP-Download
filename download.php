<?php
// ===== 0) 基本設定 =====
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Taipei');
if (session_id() === '') { session_start(); }

// JSON 檔名（與本檔同層）
define('ADMIN_JSON', __DIR__ . '/admin.json');
define('DOWNLOAD_JSON', __DIR__ . '/download.json');

// ===== 1) 小工具 =====
function load_json_assoc($path) {
    if (!file_exists($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $data = @json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function save_json_assoc($path, $arr) {
    // 寫檔（含簡單檔鎖）
    $tmp = $path . '.tmp';
    $json = json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    $fp = @fopen($tmp, 'wb');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); @unlink($tmp); return false; }
    $ok = fwrite($fp, $json) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if (!$ok) { @unlink($tmp); return false; }
    return @rename($tmp, $path);
}

function now_iso() {
    $dt = new DateTime('now', new DateTimeZone('Asia/Taipei'));
    return $dt->format('c'); // ISO8601
}

function is_logged_in_today($admin) {
    if (!isset($_SESSION['dl_auth']) || $_SESSION['dl_auth'] !== true) return false;
    // 比對 passcode_version，若管理端重設後，舊 session 失效
    if (isset($admin['passcode_version']) && isset($_SESSION['passcode_version'])) {
        if (intval($_SESSION['passcode_version']) !== intval($admin['passcode_version'])) return false;
    }
    return true;
}

function html_escape($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ===== 2) 載入設定與清單 =====
$admin = load_json_assoc(ADMIN_JSON);
$downloads = load_json_assoc(DOWNLOAD_JSON);

$errors = array();
$messages = array();

// 檢查 admin.json 基本欄位
if (!$admin) { $errors[] = '系統設定檔 admin.json 無法讀取或格式錯誤。'; }
else {
    // 預設值保護
    if (!isset($admin['ttl_hours']) || !is_numeric($admin['ttl_hours'])) $admin['ttl_hours'] = 5;
    if (!isset($admin['locked_after_expire'])) $admin['locked_after_expire'] = true;
    if (!isset($admin['passcode_version'])) $admin['passcode_version'] = 1;
}

// ===== 3) 登入處理（使用者通行碼） =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $input_pass = isset($_POST['user_passcode']) ? trim($_POST['user_passcode']) : '';

    if (!$admin) {
        $errors[] = '系統尚未完成設定（admin.json）。';
    } elseif ($input_pass === '') {
        $errors[] = '請輸入使用者通行碼。';
    } else {
        $stored_pass = isset($admin['user_passcode']) ? strval($admin['user_passcode']) : '';
        if ($stored_pass === '') {
            $errors[] = '系統尚未設定使用者通行碼，請聯絡管理者。';
        } elseif ($input_pass !== $stored_pass) {
            $errors[] = '通行碼不正確。';
        } else {
            // 通行碼正確，檢查 TTL 狀態
            $first = isset($admin['first_used_at']) ? $admin['first_used_at'] : null;
            $expires = isset($admin['expires_at']) ? $admin['expires_at'] : null;
            $ttl_hours = intval($admin['ttl_hours']);

            $now = new DateTime('now', new DateTimeZone('Asia/Taipei'));

            if ($first === null || $first === '' || $expires === null || $expires === '') {
                // 第一次成功使用：設定 first_used_at 與 expires_at
                $first_iso = now_iso();
                $expires_dt = clone $now;
                $expires_dt->modify('+' . $ttl_hours . ' hours');
                $expires_iso = $expires_dt->format('c');

                $admin['first_used_at'] = $first_iso;
                $admin['expires_at'] = $expires_iso;

                if (!save_json_assoc(ADMIN_JSON, $admin)) {
                    $errors[] = '無法寫入 admin.json，請檢查目錄權限。';
                } else {
                    $_SESSION['dl_auth'] = true;
                    $_SESSION['login_time'] = $now->format('c');
                    $_SESSION['passcode_version'] = intval($admin['passcode_version']);
                    $messages[] = '登入成功！通行碼已啟用 5 小時有效期。';
                }
            } else {
                // 已啟用，檢查是否逾期
                $exp_dt = DateTime::createFromFormat(DateTime::ATOM, $admin['expires_at']);
                if (!$exp_dt) { // 格式錯誤時，保守處理：視為逾期
                    $errors[] = '通行碼狀態異常，請聯絡管理者重設。';
                } else {
                    if ($now <= $exp_dt) {
                        // 尚在有效期內
                        $_SESSION['dl_auth'] = true;
                        $_SESSION['login_time'] = $now->format('c');
                        $_SESSION['passcode_version'] = intval($admin['passcode_version']);
                        $messages[] = '登入成功！';
                    } else {
                        // 已逾期
                        if (!empty($admin['locked_after_expire'])) {
                            $errors[] = '通行碼已逾時，請聯絡管理者重設。';
                        } else {
                            $errors[] = '通行碼已逾時。';
                        }
                    }
                }
            }
        }
    }
}

// ===== 4) 是否已登入（且版本一致） =====
$logged_in = ($admin && is_logged_in_today($admin));

// ===== 5) 計算剩餘時間（顯示用） =====
$remaining_text = null;
if ($logged_in && isset($admin['expires_at']) && $admin['expires_at']) {
    $now = new DateTime('now', new DateTimeZone('Asia/Taipei'));
    $exp = DateTime::createFromFormat(DateTime::ATOM, $admin['expires_at']);
    if ($exp && $now <= $exp) {
        $diff = $now->diff($exp);
        $h = $diff->h + $diff->d * 24;
        $m = $diff->i;
        $s = $diff->s;
        $remaining_text = sprintf('剩餘時間：%02d:%02d:%02d', $h, $m, $s);
    }
}

// ===== 6) 下載清單（僅顯示 visible=true） =====
$items = array();
if ($logged_in && $downloads && isset($downloads['items']) && is_array($downloads['items'])) {
    foreach ($downloads['items'] as $it) {
        if (!empty($it['visible'])) {
            $items[] = $it;
        }
    }
}

// ===== 7) 輸出 HTML（深色繁中 UI） =====
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>課堂下載 | Download</title>
<style>
    :root {
        --bg: #0f1115;
        --card: #151823;
        --card-hover: #1a1f2e;
        --text: #e6e9ef;
        --muted: #a0a6b5;
        --primary: #8ab4f8;
        --danger: #ff6b6b;
        --success: #5bd6a8;
        --accent: #caa6ff;
        --border: #222737;
        --btn: #222945;
        --btn-hover: #2b365e;
        --input: #111521;
        --input-border: #2a3350;
        --shadow: 0 10px 30px rgba(0,0,0,0.35);
        --radius: 16px;
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
        margin:0; background: radial-gradient(1200px 800px at 20% -20%, rgba(138,180,248,0.15), transparent 60%),
                                 radial-gradient(1000px 700px at 120% 20%, rgba(202,166,255,0.15), transparent 55%),
                                 var(--bg);
        color:var(--text); font:16px/1.6 system-ui, -apple-system, "Segoe UI", "PingFang TC", "Noto Sans TC", "Microsoft JhengHei", sans-serif;
        display:flex; align-items:center; justify-content:center; padding:32px;
    }
    .container{
        width:100%; max-width:900px;
        background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.0));
        border:1px solid var(--border);
        border-radius:var(--radius);
        box-shadow:var(--shadow);
        padding:28px;
        backdrop-filter: blur(8px);
    }
    h1{margin:0 0 8px 0; font-size:28px; letter-spacing:0.5px}
    .sub{color:var(--muted); margin-bottom:20px}
    .row{display:flex; gap:12px; align-items:center}
    .grow{flex:1}
    input[type="password"], input[type="text"]{
        width:100%; padding:14px 16px; border-radius:12px;
        border:1px solid var(--input-border); background:var(--input);
        color:var(--text); outline:none; transition:.2s border, .2s background;
        font-size:16px;
    }
    input:focus{border-color:var(--primary); background:#0b0f1a}
    .btn{
        display:inline-flex; align-items:center; justify-content:center; gap:8px;
        padding:12px 16px; border-radius:12px; border:1px solid var(--border);
        background:var(--btn); color:var(--text); cursor:pointer; transition:.2s;
        text-decoration:none; font-weight:600;
    }
    .btn:hover{background:var(--btn-hover)}
    .btn-primary{border-color:#30407a}
    .btn-primary:hover{filter:brightness(1.1)}
    .status{
        display:flex; align-items:center; gap:8px; color:var(--muted); font-size:14px;
    }
    .badge{
        display:inline-block; padding:4px 10px; border-radius:999px; font-size:13px; font-weight:700;
        border:1px solid var(--border); background:#131724; color:var(--muted);
    }
    .badge.success{color:#1fe1a2; border-color:#1b3f34; background:#0f1c19}
    .badge.danger{color:#ff8b8b; border-color:#4a1f29; background:#1b0f12}
    .list{margin-top:18px; display:grid; grid-template-columns:1fr; gap:12px}
    .card{
        border:1px solid var(--border); border-radius:14px; background:var(--card);
        padding:16px; transition:.2s background, .2s transform;
    }
    .card:hover{background:var(--card-hover); transform: translateY(-1px)}
    .title{font-weight:700; letter-spacing:.2px}
    .notes{color:var(--muted); font-size:14px}
    .err, .msg{
        padding:12px 14px; border-radius:12px; margin:8px 0; font-size:15px;
        border:1px solid var(--border);
    }
    .err{ background:#1c1214; color:#ffb3b3; border-color:#3b1c22 }
    .msg{ background:#0f1a15; color:#aef5d6; border-color:#173d31 }
    .footer{margin-top:18px; display:flex; justify-content:space-between; align-items:center; color:var(--muted); font-size:13px}
    .muted{color:var(--muted)}
    .sp{height:10px}
    .hint{font-size:14px; color:var(--muted)}
    .hr{height:1px; background:var(--border); margin:16px 0}
    @media (max-width:640px){
        .row{flex-direction:column; align-items:stretch}
    }
</style>
</head>
<body>
<div class="container">
    <h1>課堂下載</h1>
    <div class="sub">使用者輸入通行碼後即可看到下載清單（外部連結）。</div>

    <?php foreach ($errors as $e): ?>
        <div class="err">⚠️ <?= html_escape($e) ?></div>
    <?php endforeach; ?>
    <?php foreach ($messages as $m): ?>
        <div class="msg">✅ <?= html_escape($m) ?></div>
    <?php endforeach; ?>

    <?php if (!$logged_in): ?>
        <div class="card">
            <div class="title">輸入使用者通行碼</div>
            <div class="sp"></div>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="login">
                <div class="row">
                    <div class="grow">
                        <input type="password" name="user_passcode" placeholder="請輸入使用者通行碼">
                    </div>
                    <button class="btn btn-primary" type="submit">登入</button>
                </div>
                <div class="sp"></div>
                <div class="hint">說明：通行碼自<strong>首次成功使用</strong>起，效期 <strong><?= isset($admin['ttl_hours']) ? intval($admin['ttl_hours']) : 5 ?></strong> 小時，逾期需由管理者重設。</div>
            </form>
        </div>
    <?php else: ?>
        <div class="status">
            <span class="badge success">已登入</span>
            <?php if ($remaining_text): ?>
                <span><?= html_escape($remaining_text) ?></span>
            <?php elseif (isset($admin['expires_at']) && $admin['expires_at']): ?>
                <?php
                $exp = DateTime::createFromFormat(DateTime::ATOM, $admin['expires_at']);
                $exp_txt = $exp ? $exp->format('Y-m-d H:i') : $admin['expires_at'];
                ?>
                <span>到期時間：<?= html_escape($exp_txt) ?></span>
            <?php endif; ?>
        </div>

        <div class="hr"></div>

        <?php if (count($items) === 0): ?>
            <div class="card">
                <div class="title">目前沒有可下載項目</div>
                <div class="notes">請稍後再試，或聯絡管理者開放清單。</div>
            </div>
        <?php else: ?>
            <div class="list">
                <?php foreach ($items as $it): ?>
                    <div class="card">
                        <div class="title"><?= html_escape(isset($it['title']) ? $it['title'] : '未命名項目') ?></div>
                        <?php if (!empty($it['notes'])): ?>
                            <div class="notes"><?= html_escape($it['notes']) ?></div>
                        <?php endif; ?>
                        <div class="sp"></div>
                        <?php if (!empty($it['url'])): ?>
                            <a class="btn" href="<?= html_escape($it['url']) ?>" target="_blank" rel="noopener">開啟連結</a>
                        <?php else: ?>
                            <span class="badge danger">未設定連結</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="footer">
            <div>通行碼版本：<?= intval($admin['passcode_version']) ?></div>
            <div class="muted">系統時間：<?= html_escape((new DateTime('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d H:i:s')) ?>（台北）</div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
