<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

const MAX_SLUG_LENGTH = 64;
const MAX_TAB_TITLE_LENGTH = 80;
const MAX_CONTENT_BYTES = 2_000_000;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalize_slug(string $slug): string
{
    return strtolower(trim($slug));
}

function valid_slug(string $slug): bool
{
    if ($slug === '' || strlen($slug) > MAX_SLUG_LENGTH) {
        return false;
    }

    return (bool)preg_match('/^[a-z0-9](?:[a-z0-9_-]{0,62}[a-z0-9])?$/', $slug);
}

function requested_slug(): ?string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = rawurldecode((string)$path);
    $path = trim($path, '/');

    if ($path === '' || $path === 'index.php') {
        return null;
    }

    if (str_contains($path, '/')) {
        return '__INVALID__';
    }

    return $path;
}

function url_for(string $path = ''): string
{
    return $path === '' ? '/' : '/' . ltrim($path, '/');
}

function redirect_to(string $path): never
{
    header('Location: ' . url_for($path), true, 303);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submitted = (string)($_POST['csrf_token'] ?? '');
    $stored = (string)($_SESSION['csrf_token'] ?? '');

    if ($submitted === '' || $stored === '' || !hash_equals($stored, $submitted)) {
        http_response_code(403);
        exit('Invalid request token.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pull_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

function find_page(string $slug): ?array
{
    $stmt = db()->prepare(
        'SELECT id, slug, password_hash, content, created_at, updated_at
         FROM pages
         WHERE slug = ?
         LIMIT 1'
    );
    $stmt->execute([$slug]);

    $page = $stmt->fetch();
    return $page ?: null;
}

function get_tabs(int $pageId): array
{
    $stmt = db()->prepare(
        'SELECT id, page_id, title, content, position, created_at, updated_at
         FROM page_tabs
         WHERE page_id = ?
         ORDER BY position ASC, id ASC'
    );
    $stmt->execute([$pageId]);

    return $stmt->fetchAll();
}

function find_tab(int $pageId, int $tabId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, page_id, title, content, position, created_at, updated_at
         FROM page_tabs
         WHERE page_id = ? AND id = ?
         LIMIT 1'
    );
    $stmt->execute([$pageId, $tabId]);

    $tab = $stmt->fetch();
    return $tab ?: null;
}

function ensure_first_tab(array $page): void
{
    $tabs = get_tabs((int)$page['id']);

    if ($tabs) {
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO page_tabs (page_id, title, content, position)
         VALUES (?, ?, ?, 1)'
    );
    $stmt->execute([
        (int)$page['id'],
        'Main',
        (string)($page['content'] ?? ''),
    ]);
}

function next_tab_position(int $pageId): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(MAX(position), 0) + 1
         FROM page_tabs
         WHERE page_id = ?'
    );
    $stmt->execute([$pageId]);

    return (int)$stmt->fetchColumn();
}

function next_tab_title(int $pageId): string
{
    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM page_tabs
         WHERE page_id = ?'
    );
    $stmt->execute([$pageId]);

    return 'New Tab ' . ((int)$stmt->fetchColumn() + 1);
}

function page_unlocked(string $slug): bool
{
    return !empty($_SESSION['unlocked_pages'][$slug]);
}

function unlock_page(string $slug): void
{
    $_SESSION['unlocked_pages'][$slug] = true;
    session_regenerate_id(true);
}

function lock_page(string $slug): void
{
    unset($_SESSION['unlocked_pages'][$slug], $_SESSION['active_tabs'][$slug]);
    session_regenerate_id(true);
}

function active_tab_id(string $slug, array $tabs): int
{
    $requested = isset($_GET['tab']) ? (int)$_GET['tab'] : 0;

    if ($requested > 0) {
        foreach ($tabs as $tab) {
            if ((int)$tab['id'] === $requested) {
                $_SESSION['active_tabs'][$slug] = $requested;
                return $requested;
            }
        }
    }

    $stored = (int)($_SESSION['active_tabs'][$slug] ?? 0);

    if ($stored > 0) {
        foreach ($tabs as $tab) {
            if ((int)$tab['id'] === $stored) {
                return $stored;
            }
        }
    }

    $first = (int)$tabs[0]['id'];
    $_SESSION['active_tabs'][$slug] = $first;

    return $first;
}

function saved_time(string $timestamp): string
{
    try {
        return (new DateTimeImmutable($timestamp))->format('M j, Y · g:i A');
    } catch (Throwable) {
        return $timestamp;
    }
}

function page_start(string $title, string $class = ''): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="referrer" content="no-referrer">
<title><?= e($title) ?></title>
<style>
*{box-sizing:border-box}
:root{
    --navy:#0a436d;
    --navy2:#083451;
    --navy3:#12628f;
    --page:#eef2f6;
    --line:#bfc8d1;
    --line2:#d9dfe5;
    --white:#fff;
    --ink:#1c2630;
    --muted:#6b7681;
    --blue:#2388c4;
    --green:#1b8b59;
    --red:#ad3840;
    --shadow:0 18px 55px rgba(22,44,64,.12);
}
html,body{margin:0;min-height:100%;font-family:Arial,Helvetica,sans-serif;color:var(--ink)}
body{background:var(--page)}
button,input,textarea{font:inherit}
a{color:inherit}

/* ---------- public screens ---------- */
.public-shell{min-height:100vh;background:linear-gradient(180deg,#f8fafc 0,#eef3f7 100%)}
.public-top{
    height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    max-width:1080px;
    margin:0 auto;
    padding:0 20px;
}
.public-brand{
    display:flex;
    gap:10px;
    align-items:center;
    text-decoration:none;
    font-weight:800;
    color:var(--navy2);
}
.brand-box{
    width:34px;height:34px;
    border-radius:8px;
    display:grid;place-items:center;
    background:var(--navy);
    color:white;
    box-shadow:0 6px 15px rgba(10,67,109,.2);
}
.public-note{font-size:12px;color:var(--muted);font-weight:700}
.public-main{
    min-height:calc(100vh - 64px);
    display:grid;
    place-items:center;
    padding:32px 20px 70px;
}
.home-card,.auth-card{
    width:min(560px,100%);
    background:white;
    border:1px solid var(--line2);
    border-radius:20px;
    box-shadow:var(--shadow);
}
.home-card{padding:42px}
.auth-card{padding:36px}
.kicker{
    font-size:11px;
    letter-spacing:.13em;
    text-transform:uppercase;
    font-weight:900;
    color:var(--navy3);
}
.home-card h1,.auth-card h1{
    margin:10px 0 8px;
    color:#132536;
    letter-spacing:-.04em;
    line-height:1.05;
}
.home-card h1{font-size:42px}
.auth-card h1{font-size:31px}
.home-card p,.auth-card p{margin:0;color:var(--muted);line-height:1.65}
.home-form,.auth-form{display:grid;gap:11px;margin-top:26px}
.input-row{display:grid;grid-template-columns:1fr auto;gap:9px}
input{
    width:100%;
    min-height:48px;
    border:1px solid #b8c2cc;
    border-radius:9px;
    outline:0;
    padding:0 14px;
    background:white;
}
input:focus{border-color:var(--navy3);box-shadow:0 0 0 4px rgba(18,98,143,.1)}
.btn{
    min-height:44px;
    border:1px solid #aab6c1;
    border-radius:8px;
    padding:0 16px;
    background:linear-gradient(#fff,#edf1f5);
    color:#173a53;
    font-weight:800;
    cursor:pointer;
}
.btn.primary{border-color:var(--navy);background:var(--navy);color:white}
.btn.primary:hover{background:#0d537f}
.btn.full{width:100%}
.label{font-size:12px;font-weight:800;color:#3b4b59}
.examples{display:flex;flex-wrap:wrap;gap:7px;margin-top:18px}
.examples span,.page-chip{
    border:1px solid var(--line2);
    border-radius:7px;
    background:#f4f7f9;
    color:#60707d;
    font:12px Consolas,monospace;
}
.examples span{padding:5px 8px}
.page-chip{display:inline-flex;padding:6px 10px;margin-bottom:18px}
.notice{margin-top:16px;padding:10px 12px;border-radius:8px;font-size:13px;font-weight:700}
.notice.success{background:#edf9f2;border:1px solid #bfe2cc;color:#157348}
.notice.error{background:#fff1f1;border:1px solid #ecc4c6;color:#9a2e35}
.password-wrap{position:relative}
.password-wrap input{padding-right:70px}
.show-pass{
    position:absolute;right:7px;top:50%;transform:translateY(-50%);
    border:0;border-radius:6px;background:#edf2f5;color:#52616e;
    padding:6px 9px;cursor:pointer;font-size:11px;font-weight:800
}

/* ---------- editor ---------- */
.editor-body{min-height:100vh;background:#dde7f1}
.app{
    min-height:100vh;
    display:flex;
    flex-direction:column;
}
.topbar{
    min-height:60px;
    border-top:8px solid #30363b;
    background:linear-gradient(90deg,var(--navy2),var(--navy));
    color:white;
    display:flex;
    align-items:center;
    gap:22px;
    padding:7px 42px;
    box-shadow:0 3px 12px rgba(7,52,81,.18);
}
.logo{
    text-decoration:none;
    font:700 24px Georgia,"Times New Roman",serif;
    white-space:nowrap;
}
.logo .soft{color:#a7c4d8;font-weight:400}
.page-id{
    flex:1;
    min-width:0;
    display:flex;
    align-items:baseline;
    gap:8px;
    color:#d6e7f1;
}
.page-id small{font-size:9px;font-weight:900;letter-spacing:.12em;opacity:.7}
.page-id strong{font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.top-actions{display:flex;gap:7px;align-items:center}
.top-actions form{margin:0}
.top-btn{
    height:32px;
    border:1px solid #93a8b8;
    border-radius:6px;
    padding:0 13px;
    background:linear-gradient(#fff,#e5ebf0);
    color:#17384f;
    font:700 12px Georgia,"Times New Roman",serif;
    cursor:pointer;
}
.top-btn.save{background:linear-gradient(#d9ebf5,#aac6d8);border-color:#8baabd}
.workspace{
    flex:1;
    min-height:0;
    display:flex;
    flex-direction:column;
    padding:9px 42px 22px;
}
.tabs-bar{
    flex:0 0 auto;
    width:100%;
    overflow-x:auto;
    overflow-y:hidden;
    white-space:nowrap;
    padding-bottom:5px;
    scrollbar-width:thin;
}
.tabs-row{
    display:flex;
    flex-direction:row;
    flex-wrap:nowrap;
    align-items:flex-end;
    gap:4px;
    min-width:max-content;
}
.tab-box{
    position:relative;
    flex:0 0 176px;
    height:35px;
    display:flex;
    align-items:center;
    border:1px solid #aab6c1;
    border-radius:8px 8px 3px 3px;
    background:linear-gradient(#f7f9fb,#e4eaef);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.9);
}
.tab-box.active{
    background:linear-gradient(#fff,#f8fafc);
    border-color:#7292aa;
    box-shadow:inset 0 3px 0 var(--blue),0 1px 3px rgba(31,51,68,.12);
}
.tab-link{
    height:100%;
    min-width:0;
    flex:1;
    display:flex;
    align-items:center;
    gap:7px;
    padding:0 31px 0 9px;
    text-decoration:none;
    color:#183f5c;
    font:700 12px Georgia,"Times New Roman",serif;
}
.tab-icon{
    width:18px;height:18px;flex:0 0 18px;
    display:grid;place-items:center;
    border-radius:4px;
    background:#dbe9f3;color:#15587e;
    font:900 10px Arial,sans-serif;
}
.tab-title{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tab-close-form{position:absolute;right:5px;top:50%;transform:translateY(-50%);margin:0}
.tab-close{
    width:22px;height:22px;border:0;border-radius:5px;background:transparent;
    color:#637482;font-size:16px;cursor:pointer;display:grid;place-items:center
}
.tab-close:hover{background:#d5dde4;color:#263a49}
.add-tab-form{margin:0}
.add-tab{
    width:36px;height:35px;border:1px solid #aab6c1;border-radius:8px 8px 3px 3px;
    background:linear-gradient(#f7f9fb,#e4eaef);color:#24465f;font-size:20px;cursor:pointer
}
.editor-head{
    min-height:50px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    border:1px solid #aab6c1;
    border-bottom:0;
    border-radius:8px 8px 0 0;
    background:linear-gradient(#f8fafc,#edf2f6);
    padding:8px 11px;
}
.meta{min-width:0;display:flex;align-items:baseline;gap:13px}
.active-name{
    max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
    color:#183d58;font:800 14px Georgia,"Times New Roman",serif
}
.saved{font-size:11px;color:#6c7883;white-space:nowrap}
.saved:before{
    content:"";width:7px;height:7px;display:inline-block;border-radius:50%;
    background:#38a36c;margin-right:5px
}
.editor-actions{display:flex;gap:7px}
.small-btn{
    height:30px;border:1px solid #b1bbc5;border-radius:6px;background:white;color:#344e62;
    padding:0 11px;cursor:pointer;font:700 12px Georgia,"Times New Roman",serif
}
.small-btn.danger{color:#913b41}
.flashbar{
    border-left:1px solid #aab6c1;border-right:1px solid #aab6c1;
    padding:7px 11px;font-size:12px;font-weight:700
}
.flashbar.success{background:#eef9f2;color:#157348}
.flashbar.error{background:#fff1f1;color:#9a2e35}
.editor-form{flex:1;min-height:0;display:flex;margin:0}
textarea{
    width:100%;
    height:calc(100vh - 166px);
    min-height:430px;
    resize:none;
    border:1px solid #aab6c1;
    border-radius:0 0 8px 8px;
    outline:0;
    background:white;
    color:#293540;
    padding:16px 18px 24px;
    font:14px/1.55 Consolas,"Courier New",monospace;
    box-shadow:inset 0 1px 3px rgba(28,49,68,.05),0 5px 16px rgba(33,54,74,.05);
}
textarea:focus{border-color:#6f96b2;box-shadow:0 0 0 2px rgba(35,136,196,.08)}
.dialog{
    width:min(430px,calc(100% - 28px));
    border:0;border-radius:14px;padding:0;box-shadow:0 25px 70px rgba(6,42,71,.28)
}
.dialog::backdrop{background:rgba(8,26,40,.55)}
.dialog form{padding:24px;display:grid;gap:10px}
.dialog-top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}
.dialog h2{margin:0;color:#14334b;font-size:24px}
.dialog-close{
    width:32px;height:32px;border:1px solid #c6ced6;border-radius:7px;
    background:#f3f6f8;color:#5d6b77;cursor:pointer;font-size:20px
}

@media(max-width:760px){
    .public-note{display:none}
    .home-card,.auth-card{padding:26px 20px}
    .home-card h1{font-size:35px}
    .input-row{grid-template-columns:1fr}
    .topbar{padding:7px 10px;align-items:flex-start;flex-direction:column;gap:7px}
    .page-id{display:none}
    .top-actions{width:100%;overflow-x:auto;padding-bottom:2px}
    .top-btn{white-space:nowrap}
    .workspace{padding:7px 8px 10px}
    .editor-head{align-items:flex-start;flex-direction:column}
    .meta{display:block}
    .active-name,.saved{display:block}
    .saved{margin-top:3px}
    .editor-actions{width:100%}
    .small-btn{flex:1}
    textarea{height:calc(100vh - 240px);min-height:430px;font-size:13px;padding:14px}
}
</style>
</head>
<body class="<?= e($class) ?>">
<?php
}

function page_end(): void
{
    ?>
<script>
(() => {
    const editorForm = document.getElementById('editor-form');
    const editorAction = document.getElementById('editor-action');
    const textArea = document.getElementById('content');

    let dirty = false;
    let submitting = false;

    if (textArea) {
        textArea.addEventListener('input', () => dirty = true);
    }

    if (editorForm) {
        editorForm.addEventListener('submit', () => {
            submitting = true;
            dirty = false;
        });
    }

    document.querySelectorAll('[data-show-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.showPassword);
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.textContent = show ? 'Hide' : 'Show';
        });
    });

    const clearButton = document.querySelector('[data-clear]');
    if (clearButton && editorForm && editorAction) {
        clearButton.addEventListener('click', () => {
            if (!confirm('Clear all text from this tab?')) return;
            editorAction.value = 'clear_tab';
            submitting = true;
            dirty = false;
            editorForm.submit();
        });
    }

    document.querySelectorAll('[data-delete-tab]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!confirm('Delete this tab and everything stored in it?')) {
                event.preventDefault();
                return;
            }
            submitting = true;
            dirty = false;
        });
    });

    const renameDialog = document.getElementById('rename-dialog');
    const passDialog = document.getElementById('password-dialog');

    document.querySelector('[data-rename-open]')?.addEventListener('click', () => {
        renameDialog?.showModal();
        setTimeout(() => {
            const input = document.getElementById('rename_title');
            input?.focus();
            input?.select();
        }, 30);
    });

    document.querySelector('[data-password-open]')?.addEventListener('click', () => {
        passDialog?.showModal();
        setTimeout(() => document.getElementById('new_password')?.focus(), 30);
    });

    document.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog')?.close());
    });

    document.querySelectorAll('dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
    });

    if (editorForm && editorAction) {
        document.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                editorAction.value = 'save_tab';
                submitting = true;
                dirty = false;
                if (editorForm.requestSubmit) editorForm.requestSubmit();
                else editorForm.submit();
            }
        });
    }

    window.addEventListener('beforeunload', (event) => {
        if (!dirty || submitting) return;
        event.preventDefault();
        event.returnValue = '';
    });
})();
</script>
</body>
</html>
<?php
}

/* ---------- secure session ---------- */
$https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);

session_name('temptext_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = requested_slug();

/* ================= HOME ================= */
if ($slug === null) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();

        $candidate = normalize_slug((string)($_POST['page_name'] ?? ''));

        if (!valid_slug($candidate)) {
            flash('error', 'Use letters, numbers, hyphens or underscores only.');
            redirect_to('');
        }

        redirect_to(rawurlencode($candidate));
    }

    $flash = pull_flash();

    page_start('TempText', 'public-shell');
    ?>
    <header class="public-top">
        <a class="public-brand" href="/">
            <span class="brand-box">T</span>
            <span>TempText</span>
        </a>
        <span class="public-note">Private URL-based text storage</span>
    </header>

    <main class="public-main">
        <section class="home-card">
            <span class="kicker">Private text storage</span>
            <h1>Enter page name</h1>
            <p>Open an existing private page or create a new one.</p>

            <?php if ($flash): ?>
                <div class="notice <?= e($flash['type']) ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="home-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="input-row">
                    <input
                        name="page_name"
                        type="text"
                        maxlength="<?= MAX_SLUG_LENGTH ?>"
                        placeholder="first"
                        required
                        autofocus
                        spellcheck="false"
                        autocapitalize="none"
                    >
                    <button class="btn primary" type="submit">Open</button>
                </div>
            </form>

            <div class="examples">
                <span>/first</span>
                <span>/work</span>
                <span>/project123</span>
                <span>/ideas</span>
            </div>
        </section>
    </main>
    <?php
    page_end();
    exit;
}

/* ================= VALIDATE ================= */
$slug = normalize_slug($slug);

if ($slug === '__invalid__' || !valid_slug($slug)) {
    http_response_code(404);
    page_start('Invalid page', 'public-shell');
    ?>
    <header class="public-top">
        <a class="public-brand" href="/">
            <span class="brand-box">T</span>
            <span>TempText</span>
        </a>
    </header>
    <main class="public-main">
        <section class="auth-card">
            <h1>Invalid page name</h1>
            <p>Use letters, numbers, hyphens and underscores only.</p>
            <p style="margin-top:20px"><a class="btn primary" href="/">Go home</a></p>
        </section>
    </main>
    <?php
    page_end();
    exit;
}

$page = find_page($slug);
$flash = pull_flash();

/* ================= POST ACTIONS ================= */
if ($page !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'unlock') {
        $password = (string)($_POST['password'] ?? '');

        if (password_verify($password, $page['password_hash'])) {
            unlock_page($slug);
            flash('success', 'Page unlocked.');
        } else {
            flash('error', 'Incorrect password.');
        }

        redirect_to(rawurlencode($slug));
    }

    if ($action === 'lock') {
        lock_page($slug);
        flash('success', 'Page locked.');
        redirect_to(rawurlencode($slug));
    }

    if (!page_unlocked($slug)) {
        http_response_code(403);
        exit('Authentication required.');
    }

    ensure_first_tab($page);

    if ($action === 'add_tab') {
        $stmt = db()->prepare(
            'INSERT INTO page_tabs (page_id, title, content, position)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$page['id'],
            next_tab_title((int)$page['id']),
            '',
            next_tab_position((int)$page['id']),
        ]);

        $newId = (int)db()->lastInsertId();
        $_SESSION['active_tabs'][$slug] = $newId;

        redirect_to(rawurlencode($slug) . '?tab=' . $newId);
    }

    if ($action === 'save_tab' || $action === 'clear_tab') {
        $tabId = (int)($_POST['tab_id'] ?? 0);
        $tab = find_tab((int)$page['id'], $tabId);

        if (!$tab) {
            http_response_code(404);
            exit('Tab not found.');
        }

        $content = $action === 'clear_tab'
            ? ''
            : (string)($_POST['content'] ?? '');

        if (strlen($content) > MAX_CONTENT_BYTES) {
            flash('error', 'This tab is too large.');
            redirect_to(rawurlencode($slug) . '?tab=' . $tabId);
        }

        $stmt = db()->prepare(
            'UPDATE page_tabs
             SET content = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND page_id = ?'
        );
        $stmt->execute([$content, $tabId, (int)$page['id']]);

        $_SESSION['active_tabs'][$slug] = $tabId;
        flash('success', $action === 'clear_tab' ? 'Tab cleared.' : 'Saved.');

        redirect_to(rawurlencode($slug) . '?tab=' . $tabId);
    }

    if ($action === 'rename_tab') {
        $tabId = (int)($_POST['tab_id'] ?? 0);
        $tab = find_tab((int)$page['id'], $tabId);

        if (!$tab) {
            http_response_code(404);
            exit('Tab not found.');
        }

        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') $title = 'Untitled';
        if (strlen($title) > MAX_TAB_TITLE_LENGTH) {
            $title = substr($title, 0, MAX_TAB_TITLE_LENGTH);
        }

        $stmt = db()->prepare(
            'UPDATE page_tabs SET title = ? WHERE id = ? AND page_id = ?'
        );
        $stmt->execute([$title, $tabId, (int)$page['id']]);

        redirect_to(rawurlencode($slug) . '?tab=' . $tabId);
    }

    if ($action === 'delete_tab') {
        $tabId = (int)($_POST['tab_id'] ?? 0);
        $tabs = get_tabs((int)$page['id']);

        if (count($tabs) <= 1) {
            flash('error', 'A page must keep at least one tab.');
            redirect_to(rawurlencode($slug) . '?tab=' . $tabId);
        }

        $stmt = db()->prepare(
            'DELETE FROM page_tabs WHERE id = ? AND page_id = ?'
        );
        $stmt->execute([$tabId, (int)$page['id']]);

        $remaining = get_tabs((int)$page['id']);
        $nextId = (int)$remaining[0]['id'];
        $_SESSION['active_tabs'][$slug] = $nextId;

        redirect_to(rawurlencode($slug) . '?tab=' . $nextId);
    }

    if ($action === 'change_password') {
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_new_password'] ?? '');

        if ($newPassword === '') {
            flash('error', 'Password cannot be empty.');
            redirect_to(rawurlencode($slug));
        }

        if (!hash_equals($newPassword, $confirmPassword)) {
            flash('error', 'Passwords do not match.');
            redirect_to(rawurlencode($slug));
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        $stmt = db()->prepare(
            'UPDATE pages SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$hash, (int)$page['id']]);

        flash('success', 'Password changed.');
        redirect_to(rawurlencode($slug));
    }

    http_response_code(400);
    exit('Unknown action.');
}

/* ================= CREATE PAGE ================= */
if ($page === null) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();

        if ((string)($_POST['action'] ?? '') !== 'create') {
            http_response_code(400);
            exit('Unknown action.');
        }

        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        /* Any NON-EMPTY password length is accepted. */
        if ($password === '') {
            flash('error', 'Password cannot be empty.');
            redirect_to(rawurlencode($slug));
        }

        if (!hash_equals($password, $confirm)) {
            flash('error', 'Passwords do not match.');
            redirect_to(rawurlencode($slug));
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            db()->beginTransaction();

            $stmt = db()->prepare(
                'INSERT INTO pages (slug, password_hash, content)
                 VALUES (?, ?, ?)'
            );
            $stmt->execute([$slug, $hash, '']);

            $pageId = (int)db()->lastInsertId();

            $stmt = db()->prepare(
                'INSERT INTO page_tabs (page_id, title, content, position)
                 VALUES (?, ?, ?, 1)'
            );
            $stmt->execute([$pageId, 'Main', '']);

            $tabId = (int)db()->lastInsertId();

            db()->commit();

            unlock_page($slug);
            $_SESSION['active_tabs'][$slug] = $tabId;
            flash('success', 'Page created.');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            throw $exception;
        }

        redirect_to(rawurlencode($slug));
    }

    page_start('Create /' . $slug, 'public-shell');
    ?>
    <header class="public-top">
        <a class="public-brand" href="/">
            <span class="brand-box">T</span>
            <span>TempText</span>
        </a>
    </header>

    <main class="public-main">
        <section class="auth-card">
            <div class="page-chip">/<?= e($slug) ?></div>
            <span class="kicker">New private page</span>
            <h1>Create this page</h1>
            <p>Choose any non-empty password. Your first tab will be created automatically.</p>

            <?php if ($flash): ?>
                <div class="notice <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <form method="post" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">

                <label class="label" for="password">Set password</label>
                <div class="password-wrap">
                    <input id="password" name="password" type="password" required autofocus>
                    <button class="show-pass" type="button" data-show-password="password">Show</button>
                </div>

                <label class="label" for="confirm_password">Confirm password</label>
                <div class="password-wrap">
                    <input id="confirm_password" name="confirm_password" type="password" required>
                    <button class="show-pass" type="button" data-show-password="confirm_password">Show</button>
                </div>

                <button class="btn primary full" type="submit">Create page</button>
            </form>
        </section>
    </main>
    <?php
    page_end();
    exit;
}

/* ================= LOCKED PAGE ================= */
if (!page_unlocked($slug)) {
    page_start('Unlock /' . $slug, 'public-shell');
    ?>
    <header class="public-top">
        <a class="public-brand" href="/">
            <span class="brand-box">T</span>
            <span>TempText</span>
        </a>
    </header>

    <main class="public-main">
        <section class="auth-card">
            <div class="page-chip">/<?= e($slug) ?></div>
            <span class="kicker">Protected page</span>
            <h1>🔒 Password required</h1>
            <p>Enter this page’s password to access its tabs and text.</p>

            <?php if ($flash): ?>
                <div class="notice <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <form method="post" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="unlock">

                <label class="label" for="password">Password</label>
                <div class="password-wrap">
                    <input id="password" name="password" type="password" required autofocus>
                    <button class="show-pass" type="button" data-show-password="password">Show</button>
                </div>

                <button class="btn primary full" type="submit">Unlock</button>
            </form>
        </section>
    </main>
    <?php
    page_end();
    exit;
}

/* ================= EDITOR ================= */
ensure_first_tab($page);

$tabs = get_tabs((int)$page['id']);
$activeId = active_tab_id($slug, $tabs);
$activeTab = find_tab((int)$page['id'], $activeId);

if (!$activeTab) {
    http_response_code(500);
    exit('Could not load active tab.');
}

page_start('/' . $slug . ' · TempText', 'editor-body');
?>
<div class="app">
    <header class="topbar">
        <a class="logo" href="/">
            <span class="soft">TEMP</span>TEXT
        </a>

        <div class="page-id">
            <small>PRIVATE PAGE</small>
            <strong>/<?= e($slug) ?></strong>
        </div>

        <div class="top-actions">
            <button class="top-btn save" type="submit" form="editor-form">Save</button>
            <button class="top-btn" type="button" data-rename-open>Rename tab</button>
            <button class="top-btn" type="button" data-password-open>Change password</button>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="lock">
                <button class="top-btn" type="submit">Lock</button>
            </form>
        </div>
    </header>

    <main class="workspace">
        <div class="tabs-bar">
            <div class="tabs-row">
                <?php foreach ($tabs as $tab): ?>
                    <?php $active = (int)$tab['id'] === $activeId; ?>

                    <div class="tab-box <?= $active ? 'active' : '' ?>">
                        <a class="tab-link" href="/<?= rawurlencode($slug) ?>?tab=<?= (int)$tab['id'] ?>">
                            <span class="tab-icon">T</span>
                            <span class="tab-title"><?= e($tab['title']) ?></span>
                        </a>

                        <?php if (count($tabs) > 1): ?>
                            <form method="post" class="tab-close-form" data-delete-tab>
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_tab">
                                <input type="hidden" name="tab_id" value="<?= (int)$tab['id'] ?>">
                                <button class="tab-close" type="submit" title="Delete tab">×</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <form method="post" class="add-tab-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_tab">
                    <button class="add-tab" type="submit" title="New tab">+</button>
                </form>
            </div>
        </div>

        <div class="editor-head">
            <div class="meta">
                <div class="active-name"><?= e($activeTab['title']) ?></div>
                <div class="saved">Last saved <?= e(saved_time($activeTab['updated_at'])) ?></div>
            </div>

            <div class="editor-actions">
                <button class="small-btn" type="button" onclick="window.location.reload()">Reload</button>
                <button class="small-btn danger" type="button" data-clear>Clear</button>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="flashbar <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <form method="post" class="editor-form" id="editor-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="editor-action" value="save_tab">
            <input type="hidden" name="tab_id" value="<?= (int)$activeTab['id'] ?>">

            <textarea
                id="content"
                name="content"
                maxlength="<?= MAX_CONTENT_BYTES ?>"
                placeholder="Start typing..."
                spellcheck="true"
                autofocus
            ><?= e($activeTab['content']) ?></textarea>
        </form>
    </main>
</div>

<dialog class="dialog" id="rename-dialog">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="rename_tab">
        <input type="hidden" name="tab_id" value="<?= (int)$activeTab['id'] ?>">

        <div class="dialog-top">
            <div>
                <span class="kicker">Active tab</span>
                <h2>Rename tab</h2>
            </div>
            <button class="dialog-close" type="button" data-dialog-close>×</button>
        </div>

        <label class="label" for="rename_title">Tab name</label>
        <input
            id="rename_title"
            name="title"
            type="text"
            maxlength="<?= MAX_TAB_TITLE_LENGTH ?>"
            value="<?= e($activeTab['title']) ?>"
            required
        >

        <button class="btn primary full" type="submit">Save name</button>
    </form>
</dialog>

<dialog class="dialog" id="password-dialog">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="change_password">

        <div class="dialog-top">
            <div>
                <span class="kicker">Page security</span>
                <h2>Change password</h2>
            </div>
            <button class="dialog-close" type="button" data-dialog-close>×</button>
        </div>

        <label class="label" for="new_password">New password</label>
        <input id="new_password" name="new_password" type="password" required>

        <label class="label" for="confirm_new_password">Confirm new password</label>
        <input id="confirm_new_password" name="confirm_new_password" type="password" required>

        <button class="btn primary full" type="submit">Update password</button>
    </form>
</dialog>
<?php
page_end();
