<?php
/* ============================================================
   KPI Dashboard — รพ.แม่แตง
   Single-file PHP Application
   ============================================================ */
session_start();

define('DATA_DIR',  __DIR__ . '/data/');
define('USERS_F',   DATA_DIR . 'users.json');
define('KPI_F',     DATA_DIR . 'kpi_data.json');
define('LOGS_F',    DATA_DIR . 'logs.json');
define('SET_F',     DATA_DIR . 'settings.json');

/* ─── ensure data dir ─── */
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);

/* ─── helper functions ─── */
function readJson($f){
    if(!file_exists($f)) return null;
    return json_decode(file_get_contents($f), true);
}
function writeJson($f, $d){
    file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}
function clientIP(){
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown';
}
function writeLog($action, $detail='', $level='info'){
    $logs = readJson(LOGS_F) ?? [];
    $logs[] = [
        'ts'      => date('Y-m-d H:i:s'),
        'user'    => $_SESSION['user']['name'] ?? 'ไม่ระบุ',
        'role'    => $_SESSION['user']['role'] ?? '-',
        'action'  => $action,
        'detail'  => $detail,
        'ip'      => clientIP(),
        'level'   => $level,   // info | warn | success | danger
    ];
    if(count($logs)>2000) $logs = array_slice($logs,-2000);
    writeJson(LOGS_F, $logs);
}

/* ─── default users ─── */
function defaultUsers(){
    return [
        ['id'=>'u1','username'=>'superadmin','password'=>password_hash('Admin@1234',PASSWORD_DEFAULT),'name'=>'Super Administrator','role'=>'superadmin','dept'=>'IT','canEdit'=>[]],
        ['id'=>'u2','username'=>'hn.admin',  'password'=>password_hash('HN@2568',   PASSWORD_DEFAULT),'name'=>'เจ้าหน้าที่ HA ทีมนำ','role'=>'admin','dept'=>'HA-P1','canEdit'=>['ha-part1']],
        ['id'=>'u3','username'=>'rm.admin',  'password'=>password_hash('RM@2568',   PASSWORD_DEFAULT),'name'=>'เจ้าหน้าที่ RM','role'=>'admin','dept'=>'HA-P2-RM','canEdit'=>['ha-part2']],
        ['id'=>'u4','username'=>'strategy.admin','password'=>password_hash('ST@2568',PASSWORD_DEFAULT),'name'=>'เจ้าหน้าที่ยุทธศาสตร์','role'=>'admin','dept'=>'Strategic','canEdit'=>['strategic']],
        ['id'=>'u5','username'=>'viewer',    'password'=>password_hash('View@123',  PASSWORD_DEFAULT),'name'=>'ผู้สังเกตการณ์','role'=>'user','dept'=>'-','canEdit'=>[]],
    ];
}
if(!file_exists(USERS_F)) writeJson(USERS_F, defaultUsers());

/* ─── settings ─── */
if(!file_exists(SET_F)) writeJson(SET_F, ['activeYear'=>'2568','hospitalName'=>'โรงพยาบาลแม่แตง']);

/* ─── API router ─── */
if(($_SERVER['REQUEST_METHOD']==='POST') && isset($_POST['action'])){
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['action'];

    /* LOGIN */
    if($act==='login'){
        $users = readJson(USERS_F);
        $u     = trim($_POST['username'] ?? '');
        $p     = $_POST['password'] ?? '';
        $found = null;
        foreach($users as $usr){
            if($usr['username']===$u && password_verify($p,$usr['password'])){ $found=$usr; break; }
        }
        if($found){
            $safe = $found; unset($safe['password']);
            $_SESSION['user'] = $safe;
            writeLog('เข้าสู่ระบบ','username: '.$u,'success');
            echo json_encode(['ok'=>true,'user'=>$safe]);
        } else {
            writeLog('เข้าสู่ระบบล้มเหลว','username: '.$u,'warn');
            echo json_encode(['ok'=>false,'msg'=>'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง']);
        }
        exit;
    }

    /* LOGOUT */
    if($act==='logout'){
        writeLog('ออกจากระบบ','','info');
        session_destroy();
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* CHECK SESSION */
    if($act==='check'){
        $user = isset($_SESSION['user']) ? $_SESSION['user'] : null;
        echo json_encode(['ok'=>true,'user'=>$user]);
        exit;
    }

    /* SAVE KPI DATA */
    if($act==='saveKPI'){
        if(!isset($_SESSION['user'])){ echo json_encode(['ok'=>false,'msg'=>'กรุณาเข้าสู่ระบบ']); exit; }
        $payload = json_decode($_POST['payload']??'{}',true);
        $kpi = readJson(KPI_F) ?? $payload;
        writeJson(KPI_F, $payload);
        writeLog('บันทึกข้อมูล KPI','section: '.($_POST['section']??'-'),'success');
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* LOAD KPI DATA */
    if($act==='loadKPI'){
        echo json_encode(['ok'=>true,'data'=>readJson(KPI_F)]);
        exit;
    }

    /* SAVE SETTINGS */
    if($act==='saveSettings'){
        if(empty($_SESSION['user'])||$_SESSION['user']['role']!=='superadmin'){ echo json_encode(['ok'=>false]); exit; }
        $settings = json_decode($_POST['payload']??'{}',true);
        writeJson(SET_F,$settings);
        writeLog('บันทึกตั้งค่าระบบ','','info');
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* GET LOGS */
    if($act==='getLogs'){
        if(empty($_SESSION['user'])||$_SESSION['user']['role']!=='superadmin'){ echo json_encode(['ok'=>false]); exit; }
        $logs = readJson(LOGS_F) ?? [];
        $logs = array_reverse($logs);
        echo json_encode(['ok'=>true,'logs'=>array_slice($logs,0,500)]);
        exit;
    }

    /* MANAGE USERS */
    if($act==='saveUsers'){
        if(empty($_SESSION['user'])||$_SESSION['user']['role']!=='superadmin'){ echo json_encode(['ok'=>false]); exit; }
        $users = json_decode($_POST['payload']??'[]',true);
        writeJson(USERS_F,$users);
        writeLog('แก้ไขข้อมูลผู้ใช้งาน','','warn');
        echo json_encode(['ok'=>true]);
        exit;
    }
    if($act==='getUsers'){
        if(empty($_SESSION['user'])||$_SESSION['user']['role']!=='superadmin'){ echo json_encode(['ok'=>false]); exit; }
        $users = readJson(USERS_F)??[];
        foreach($users as &$u) unset($u['password']);
        echo json_encode(['ok'=>true,'users'=>$users]);
        exit;
    }
    if($act==='addUser'){
        if(empty($_SESSION['user'])||$_SESSION['user']['role']!=='superadmin'){ echo json_encode(['ok'=>false]); exit; }
        $users = readJson(USERS_F)??[];
        $nu=[
            'id'=>'u'.time(),
            'username'=>trim($_POST['username']??''),
            'password'=>password_hash($_POST['password']??'',PASSWORD_DEFAULT),
            'name'=>trim($_POST['name']??''),
            'role'=>$_POST['role']??'user',
            'dept'=>trim($_POST['dept']??''),
            'canEdit'=>json_decode($_POST['canEdit']??'[]',true),
        ];
        $users[]=$nu;
        writeJson(USERS_F,$users);
        writeLog('เพิ่มผู้ใช้งาน',$nu['username'],'success');
        echo json_encode(['ok'=>true]);
        exit;
    }
    if($act==='deleteUser'){
        if(empty($_SESSION['user'])||$_SESSION['user']['role']!=='superadmin'){ echo json_encode(['ok'=>false]); exit; }
        $uid=$_POST['uid']??'';
        $users = array_filter(readJson(USERS_F)??[], fn($u)=>$u['id']!==$uid);
        writeJson(USERS_F,array_values($users));
        writeLog('ลบผู้ใช้งาน','id:'.$uid,'warn');
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'unknown action']);
    exit;
}

$settings = readJson(SET_F) ?? ['activeYear'=>'2568','hospitalName'=>'โรงพยาบาลแม่แตง'];
$sessionUser = $_SESSION['user'] ?? null;
?>
<!DOCTYPE html>
<html lang="th" data-theme="auto">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KPI Dashboard | <?= htmlspecialchars($settings['hospitalName']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Noto+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ============================================================
   CSS VARIABLES – THEMES
   ============================================================ */
:root {
  --font: 'Sarabun', 'Noto Sans Thai', sans-serif;
  --radius: 12px;
  --radius-sm: 8px;
  --sidebar-w: 264px;
  --topbar-h: 58px;
  --transition: .2s ease;
}

/* === DARK THEME === */
[data-theme="dark"] {
  --bg:          #0c1220;
  --bg2:         #111827;
  --sidebar:     #0f1825;
  --card:        #182031;
  --card2:       #1e2940;
  --border:      #283548;
  --border2:     #334155;
  --text:        #e2e8f0;
  --text2:       #94a3b8;
  --text3:       #64748b;
  --accent:      #38bdf8;
  --accent2:     #0ea5e9;
  --accent-bg:   rgba(56,189,248,.12);
  --success:     #10b981;
  --success-bg:  rgba(16,185,129,.12);
  --warning:     #f59e0b;
  --warning-bg:  rgba(245,158,11,.12);
  --danger:      #ef4444;
  --danger-bg:   rgba(239,68,68,.12);
  --purple:      #8b5cf6;
  --purple-bg:   rgba(139,92,246,.12);
  --teal:        #14b8a6;
  --shadow:      0 8px 32px rgba(0,0,0,.5);
  --shadow-sm:   0 2px 8px rgba(0,0,0,.3);
  --logo-filter: none;
}
/* === LIGHT THEME === */
[data-theme="light"] {
  --bg:          #f1f5f9;
  --bg2:         #e8eef5;
  --sidebar:     #ffffff;
  --card:        #ffffff;
  --card2:       #f8fafc;
  --border:      #dde3ec;
  --border2:     #c8d0dc;
  --text:        #0f172a;
  --text2:       #475569;
  --text3:       #94a3b8;
  --accent:      #0369a1;
  --accent2:     #0284c7;
  --accent-bg:   rgba(3,105,161,.08);
  --success:     #059669;
  --success-bg:  rgba(5,150,105,.08);
  --warning:     #d97706;
  --warning-bg:  rgba(217,119,6,.08);
  --danger:      #dc2626;
  --danger-bg:   rgba(220,38,38,.08);
  --purple:      #7c3aed;
  --purple-bg:   rgba(124,58,237,.08);
  --teal:        #0d9488;
  --shadow:      0 8px 32px rgba(0,0,0,.08);
  --shadow-sm:   0 2px 8px rgba(0,0,0,.05);
  --logo-filter: none;
}

/* ============================================================
   BASE
   ============================================================ */
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0 }
html { scroll-behavior:smooth }
body {
  font-family: var(--font);
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  font-size: 14px;
  line-height: 1.5;
  transition: background var(--transition), color var(--transition);
}
a { color:inherit; text-decoration:none }
img { display:block }
button { cursor:pointer; font-family:var(--font) }

/* ============================================================
   SCROLLBAR
   ============================================================ */
::-webkit-scrollbar { width:4px; height:4px }
::-webkit-scrollbar-track { background:transparent }
::-webkit-scrollbar-thumb { background:var(--border2); border-radius:4px }

/* ============================================================
   SIDEBAR
   ============================================================ */
#sidebar {
  position: fixed; top:0; left:0;
  width: var(--sidebar-w); height:100vh;
  background: var(--sidebar);
  border-right: 1px solid var(--border);
  display: flex; flex-direction:column;
  overflow-y: auto; overflow-x:hidden;
  z-index: 100;
  transition: transform var(--transition), background var(--transition);
  box-shadow: var(--shadow-sm);
}
.sb-header {
  padding: 16px;
  border-bottom: 1px solid var(--border);
  display:flex; align-items:center; gap:10px;
  position:sticky; top:0; background:var(--sidebar); z-index:1;
}
.sb-logo-wrap {
  width:44px; height:44px; border-radius:10px;
  background: var(--accent-bg);
  display:flex; align-items:center; justify-content:center;
  overflow:hidden; flex-shrink:0;
  border: 1px solid var(--border);
}
.sb-logo-wrap img { width:40px; height:40px; object-fit:contain; filter:var(--logo-filter) }
.sb-title-wrap .t1 { font-size:13px; font-weight:700; color:var(--text); line-height:1.2 }
.sb-title-wrap .t2 { font-size:11px; color:var(--text3); margin-top:1px }

.nav-group { padding:10px 8px 0 }
.nav-lbl {
  font-size:10px; text-transform:uppercase; letter-spacing:.1em;
  color:var(--text3); font-weight:600; padding:8px 8px 4px;
}
.nav-item {
  display:flex; align-items:center; gap:9px;
  padding:9px 10px; border-radius:var(--radius-sm);
  color:var(--text2); margin-bottom:1px;
  transition:all var(--transition); position:relative;
  cursor:pointer; user-select:none;
}
.nav-item:hover { background:var(--card2); color:var(--text) }
.nav-item.active {
  background:var(--accent-bg); color:var(--accent);
  font-weight:600;
}
.nav-item.active::before {
  content:''; position:absolute; left:0; top:20%; height:60%;
  width:3px; background:var(--accent); border-radius:0 3px 3px 0;
}
.nav-icon { font-size:15px; width:20px; text-align:center; flex-shrink:0 }
.nav-text { font-size:13px; flex:1 }
.nav-badge {
  font-size:10px; font-weight:700; padding:1px 7px; border-radius:10px;
  background:var(--accent-bg); color:var(--accent);
}
.nav-arrow { font-size:12px; color:var(--text3); transition:transform .25s; margin-left:auto }
.nav-item.open .nav-arrow { transform:rotate(90deg) }
.nav-sub { overflow:hidden; max-height:0; transition:max-height .3s; padding:0 0 0 28px }
.nav-sub.open { max-height:600px }
.nav-sub-item {
  padding:6px 10px; border-radius:var(--radius-sm);
  color:var(--text3); font-size:12.5px; cursor:pointer;
  transition:all var(--transition); margin-bottom:1px;
  display:flex; align-items:center; gap:6px;
}
.nav-sub-item:hover { background:var(--card2); color:var(--text) }
.nav-sub-item.active { color:var(--accent); font-weight:600 }
.sb-footer {
  margin-top:auto; padding:12px 16px;
  border-top:1px solid var(--border);
  font-size:11px; color:var(--text3);
}

/* ============================================================
   TOPBAR
   ============================================================ */
#topbar {
  position:fixed; top:0;
  left:var(--sidebar-w); right:0;
  height:var(--topbar-h);
  background:var(--sidebar);
  border-bottom:1px solid var(--border);
  display:flex; align-items:center;
  justify-content:space-between;
  padding:0 20px; z-index:90;
  transition:left var(--transition), background var(--transition);
  box-shadow: var(--shadow-sm);
}
.tb-left { display:flex; align-items:center; gap:12px }
.hamburger {
  display:none; background:none; border:none;
  color:var(--text2); font-size:20px; padding:4px;
}
.tb-title { font-size:15px; font-weight:700; color:var(--text) }
.tb-right { display:flex; align-items:center; gap:8px }
/* theme toggle */
.theme-toggle {
  display:flex; align-items:center; gap:2px;
  background:var(--card2); border:1px solid var(--border);
  border-radius:20px; padding:3px 4px;
}
.theme-btn {
  border:none; background:none; padding:4px 8px;
  border-radius:16px; font-size:13px; color:var(--text3);
  transition:all var(--transition);
}
.theme-btn.active { background:var(--accent); color:#fff }
/* user info */
.user-chip {
  display:flex; align-items:center; gap:8px;
  background:var(--card2); border:1px solid var(--border);
  border-radius:20px; padding:4px 12px 4px 5px;
}
.user-av {
  width:28px; height:28px; border-radius:50%;
  background:linear-gradient(135deg,var(--accent),var(--purple));
  display:flex; align-items:center; justify-content:center;
  font-size:11px; font-weight:700; color:#fff; flex-shrink:0;
}
.user-info { line-height:1.2 }
.user-info .uname { font-size:12px; font-weight:600 }
.role-badge {
  font-size:10px; padding:1px 7px; border-radius:10px; font-weight:600;
}
.role-superadmin { background:var(--purple-bg); color:var(--purple) }
.role-admin       { background:var(--accent-bg); color:var(--accent) }
.role-user        { background:var(--success-bg); color:var(--success) }
/* login btn */
.btn-login-top {
  display:flex; align-items:center; gap:6px;
  background:var(--accent); color:#fff;
  border:none; border-radius:var(--radius-sm);
  padding:7px 14px; font-size:13px; font-weight:600;
  transition:opacity var(--transition);
}
.btn-login-top:hover { opacity:.88 }
.btn-logout {
  background:none; border:1px solid var(--border);
  color:var(--text2); border-radius:var(--radius-sm);
  padding:5px 12px; font-size:12px;
  transition:all var(--transition);
}
.btn-logout:hover { border-color:var(--danger); color:var(--danger) }

/* ============================================================
   MAIN CONTENT
   ============================================================ */
#main {
  margin-left:var(--sidebar-w);
  padding-top:var(--topbar-h);
  min-height:100vh;
  transition:margin-left var(--transition);
}
.page { display:none; padding:22px; animation:fadeUp .22s ease }
.page.active { display:block }
@keyframes fadeUp {
  from { opacity:0; transform:translateY(10px) }
  to   { opacity:1; transform:translateY(0) }
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.ph { margin-bottom:20px }
.ph h2 { font-size:21px; font-weight:700 }
.ph p { color:var(--text2); font-size:13px; margin-top:3px }

/* ============================================================
   STAT CARDS
   ============================================================ */
.stat-grid {
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
  gap:14px; margin-bottom:20px;
}
.stat-card {
  background:var(--card); border:1px solid var(--border);
  border-radius:var(--radius); padding:18px 16px;
  position:relative; overflow:hidden;
  transition:transform var(--transition), box-shadow var(--transition);
}
.stat-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-sm) }
.stat-card::after {
  content:''; position:absolute; top:0; left:0; right:0; height:3px;
}
.sc-blue::after   { background:linear-gradient(90deg,var(--accent),var(--teal)) }
.sc-green::after  { background:linear-gradient(90deg,var(--success),#34d399) }
.sc-red::after    { background:linear-gradient(90deg,var(--danger),#f87171) }
.sc-yellow::after { background:linear-gradient(90deg,var(--warning),#fcd34d) }
.sc-purple::after { background:linear-gradient(90deg,var(--purple),#a78bfa) }
.stat-lbl { font-size:11.5px; color:var(--text2); margin-bottom:6px }
.stat-val { font-size:28px; font-weight:700; line-height:1 }
.stat-sub { font-size:11px; color:var(--text3); margin-top:5px }
.stat-icon {
  position:absolute; right:14px; top:14px;
  font-size:26px; opacity:.15;
}

/* ============================================================
   GRID LAYOUTS
   ============================================================ */
.g2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px }
.g3 { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:16px }

/* ============================================================
   CARD / PANEL
   ============================================================ */
.card {
  background:var(--card); border:1px solid var(--border);
  border-radius:var(--radius); overflow:hidden;
  transition:background var(--transition);
  margin-bottom:14px;
}
.card-head {
  padding:13px 16px; border-bottom:1px solid var(--border);
  display:flex; align-items:center; gap:10px;
}
.card-icon {
  width:32px; height:32px; border-radius:8px;
  display:flex; align-items:center; justify-content:center; font-size:14px;
  flex-shrink:0;
}
.card-head h3 { font-size:14px; font-weight:600; flex:1 }
.card-meta { font-size:11.5px; color:var(--text3); display:flex; gap:10px }
.card-body { padding:14px 16px }
.card-body.nb { padding:0 }

/* accordion */
.card.accord { cursor:default }
.card.accord .card-head { cursor:pointer }
.card.accord .card-body { display:none }
.card.accord.open .card-body { display:block }
.card-arrow { color:var(--text3); transition:transform .25s; margin-left:auto }
.card.open .card-arrow { transform:rotate(90deg) }

/* ============================================================
   TABLE
   ============================================================ */
.tbl-wrap { overflow-x:auto }
table { width:100%; border-collapse:collapse; font-size:13px }
thead th {
  background:var(--card2); padding:10px 12px;
  text-align:left; color:var(--text2); font-weight:600;
  border-bottom:1px solid var(--border); white-space:nowrap;
  font-size:12px;
}
tbody td { padding:9px 12px; border-bottom:1px solid var(--border); vertical-align:middle }
tbody tr:last-child td { border-bottom:none }
tbody tr:hover td { background:var(--card2) }

/* ============================================================
   STATUS CHIPS
   ============================================================ */
.chip {
  display:inline-flex; align-items:center; gap:4px;
  font-size:11px; padding:2px 9px; border-radius:20px; font-weight:600;
  white-space:nowrap;
}
.chip-pass   { background:var(--success-bg); color:var(--success) }
.chip-fail   { background:var(--danger-bg);  color:var(--danger) }
.chip-pend   { background:var(--warning-bg); color:var(--warning) }
.chip-na     { background:var(--card2);      color:var(--text3) }
.chip-ha     { background:var(--purple-bg);  color:var(--purple); cursor:pointer }
.chip-strat  { background:var(--accent-bg);  color:var(--accent) }

/* ============================================================
   BUTTONS
   ============================================================ */
.btn {
  display:inline-flex; align-items:center; gap:5px;
  border:none; border-radius:var(--radius-sm);
  padding:7px 14px; font-family:var(--font); font-size:13px;
  font-weight:500; transition:all var(--transition); cursor:pointer;
}
.btn-primary { background:var(--accent2); color:#fff }
.btn-primary:hover { background:var(--accent) }
.btn-outline { background:none; border:1px solid var(--border); color:var(--text) }
.btn-outline:hover { border-color:var(--accent); color:var(--accent) }
.btn-success { background:var(--success-bg); color:var(--success); border:1px solid transparent }
.btn-danger  { background:var(--danger-bg);  color:var(--danger);  border:1px solid transparent }
.btn-sm { padding:4px 10px; font-size:12px }
.btn-xs { padding:3px 8px; font-size:11px }

/* ============================================================
   YEAR TABS
   ============================================================ */
.ytabs { display:flex; gap:5px; margin-bottom:14px; flex-wrap:wrap }
.ytab {
  padding:4px 13px; border-radius:16px; cursor:pointer;
  font-size:12px; font-weight:500; border:1px solid var(--border);
  color:var(--text3); transition:all var(--transition);
}
.ytab.active { background:var(--accent); color:#fff; border-color:var(--accent) }
.ytab:hover:not(.active) { border-color:var(--accent); color:var(--accent) }

/* part tabs */
.ptabs { display:flex; gap:0; flex-wrap:wrap; border-bottom:1px solid var(--border); margin-bottom:16px }
.ptab {
  padding:8px 16px; font-size:13px; font-weight:500;
  color:var(--text3); cursor:pointer;
  border-bottom:2px solid transparent; margin-bottom:-1px;
  transition:all var(--transition);
}
.ptab:hover { color:var(--text) }
.ptab.active { color:var(--accent); border-bottom-color:var(--accent) }

/* ============================================================
   SEARCH
   ============================================================ */
.search-box { position:relative; margin-bottom:14px }
.search-box input {
  width:100%; background:var(--card);
  border:1px solid var(--border); border-radius:var(--radius-sm);
  padding:9px 14px 9px 36px; color:var(--text);
  font-family:var(--font); font-size:13px; outline:none;
  transition:border var(--transition);
}
.search-box input:focus { border-color:var(--accent) }
.search-icon { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--text3); font-size:14px }

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
  padding:10px 14px; border-radius:var(--radius-sm);
  font-size:12.5px; margin-bottom:12px;
  display:flex; gap:8px; align-items:flex-start;
}
.alert-info    { background:var(--accent-bg);  border:1px solid rgba(56,189,248,.2); color:var(--accent) }
.alert-warn    { background:var(--warning-bg); border:1px solid rgba(245,158,11,.2); color:var(--warning) }
.alert-success { background:var(--success-bg); border:1px solid rgba(16,185,129,.2); color:var(--success) }
.alert-danger  { background:var(--danger-bg);  border:1px solid rgba(239,68,68,.2);  color:var(--danger) }

/* ============================================================
   MODAL
   ============================================================ */
#modalOverlay {
  display:none; position:fixed; inset:0;
  background:rgba(0,0,0,.55); z-index:300;
  align-items:center; justify-content:center;
  backdrop-filter:blur(3px);
}
#modalOverlay.open { display:flex }
.modal {
  background:var(--card); border:1px solid var(--border);
  border-radius:16px; width:540px; max-width:96vw;
  max-height:92vh; overflow-y:auto;
  box-shadow:var(--shadow);
  animation:modalIn .2s ease;
}
@keyframes modalIn {
  from { opacity:0; transform:scale(.96) translateY(-12px) }
  to   { opacity:1; transform:scale(1) translateY(0) }
}
.modal-head {
  padding:18px 22px 14px;
  border-bottom:1px solid var(--border);
  display:flex; justify-content:space-between; align-items:center;
  position:sticky; top:0; background:var(--card); z-index:1;
}
.modal-head h3 { font-size:16px; font-weight:700 }
.modal-close {
  background:none; border:none; color:var(--text3);
  font-size:20px; line-height:1; padding:2px;
  transition:color var(--transition);
}
.modal-close:hover { color:var(--danger) }
.modal-body { padding:18px 22px }
.modal-foot {
  padding:14px 22px; border-top:1px solid var(--border);
  display:flex; justify-content:flex-end; gap:8px;
  position:sticky; bottom:0; background:var(--card);
}

/* ============================================================
   FORM ELEMENTS
   ============================================================ */
.form-group { margin-bottom:14px }
.form-group label { display:block; font-size:12px; color:var(--text2); margin-bottom:5px; font-weight:500 }
.form-group input,
.form-group select,
.form-group textarea {
  width:100%; background:var(--card2);
  border:1px solid var(--border); border-radius:var(--radius-sm);
  padding:9px 12px; color:var(--text);
  font-family:var(--font); font-size:13px; outline:none;
  transition:border var(--transition);
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { border-color:var(--accent) }
.form-group select[multiple] { height:90px }
.row-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px }
.row-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:12px }

/* ============================================================
   CHART BOX
   ============================================================ */
.chart-wrap {
  background:var(--card); border:1px solid var(--border);
  border-radius:var(--radius); padding:16px;
}
.chart-wrap h4 { font-size:13px; font-weight:600; color:var(--text2); margin-bottom:12px }

/* ============================================================
   LOG TABLE
   ============================================================ */
.log-level-info    { color:var(--accent) }
.log-level-success { color:var(--success) }
.log-level-warn    { color:var(--warning) }
.log-level-danger  { color:var(--danger) }

/* ============================================================
   TOAST
   ============================================================ */
#toast {
  position:fixed; bottom:24px; right:24px;
  padding:11px 18px; border-radius:10px; font-size:13px;
  font-weight:500; z-index:999;
  transform:translateY(80px); opacity:0;
  transition:all .28s cubic-bezier(.34,1.56,.64,1);
  pointer-events:none; max-width:300px;
}
#toast.show { transform:translateY(0); opacity:1 }
#toast.t-success { background:var(--success); color:#fff }
#toast.t-error   { background:var(--danger);  color:#fff }
#toast.t-info    { background:var(--accent);  color:#fff }
#toast.t-warn    { background:var(--warning); color:#fff }

/* overlay for mobile sidebar */
#sbOverlay {
  display:none; position:fixed; inset:0;
  background:rgba(0,0,0,.5); z-index:99;
}
#sbOverlay.open { display:block }

/* overview watch list */
.watch-item {
  display:flex; align-items:center; gap:10px;
  padding:9px 0; border-bottom:1px solid var(--border);
}
.watch-item:last-child { border-bottom:none }
.watch-dot {
  width:28px; height:28px; border-radius:6px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; font-size:12px;
}

/* progress bar */
.prog { height:5px; background:var(--card2); border-radius:3px; overflow:hidden; margin-top:5px }
.prog-fill { height:100%; border-radius:3px; transition:width .6s ease }

/* login cover */
.need-login-cover {
  display:none; align-items:center; justify-content:center;
  padding:30px; text-align:center; flex-direction:column; gap:12px;
  background:var(--card2); border-radius:var(--radius);
  border:1px dashed var(--border2); margin-bottom:14px;
}
.need-login-cover p { color:var(--text3); font-size:13px }

/* section divider */
.proj-header {
  font-size:12px; font-weight:600; color:var(--text3);
  padding:8px 0 6px; border-bottom:1px solid var(--border);
  margin-bottom:8px; display:flex; align-items:center; gap:6px;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media(max-width:900px) {
  :root { --sidebar-w:0px }
  #sidebar { transform:translateX(-264px); width:264px; transition:transform .28s }
  #sidebar.sb-open { transform:translateX(0) }
  #topbar, #main { left:0; margin-left:0 }
  .hamburger { display:block }
  .g2, .g3 { grid-template-columns:1fr }
  .stat-grid { grid-template-columns:repeat(2,1fr) }
}
@media(max-width:520px) {
  .stat-grid { grid-template-columns:1fr }
  .page { padding:12px }
  .row-2, .row-3 { grid-template-columns:1fr }
  .user-info { display:none }
  .modal { max-height:80vh; border-radius:16px 16px 0 0; position:absolute; bottom:0; left:0; right:0; width:100% }
  #modalOverlay.open { align-items:flex-end }
}
</style>
</head>
<body>

<!-- ========== SIDEBAR OVERLAY (mobile) ========== -->
<div id="sbOverlay" onclick="closeSidebar()"></div>

<!-- ========== SIDEBAR ========== -->
<nav id="sidebar">
  <div class="sb-header">
    <div class="sb-logo-wrap">
      <img src="https://www.maetaeng.go.th/assets/3-DPQvDzlj.png"
           alt="โรงพยาบาลแม่แตง"
           onerror="this.parentElement.innerHTML='🏥'">
    </div>
    <div class="sb-title-wrap">
      <div class="t1">รพ.แม่แตง</div>
      <div class="t2">KPI Dashboard</div>
    </div>
  </div>

  <div class="nav-group">
    <div class="nav-lbl">หลัก</div>
    <div class="nav-item active" onclick="nav('overview')" data-page="overview">
      <span class="nav-icon">📊</span>
      <span class="nav-text">ภาพรวม Dashboard</span>
    </div>
  </div>

  <div class="nav-group">
    <div class="nav-lbl">KPI ยุทธศาสตร์</div>
    <div class="nav-item" id="ni-strat" onclick="toggleNavGroup('ni-strat','ns-strat')">
      <span class="nav-icon">🎯</span>
      <span class="nav-text">KPI ยุทธศาสตร์</span>
      <span class="nav-badge" id="stratBadge">36</span>
      <span class="nav-arrow">›</span>
    </div>
    <div class="nav-sub" id="ns-strat">
      <div class="nav-sub-item" onclick="nav('strat-all')" data-page="strat-all">📋 ตัวชี้วัดทั้งหมด</div>
      <div class="nav-sub-item" onclick="nav('strat-all')" data-page="strat-all">1⃣ ด้านบริการสุขภาพ</div>
      <div class="nav-sub-item" onclick="nav('strat-all')" data-page="strat-all">2⃣ ด้านคุณภาพมาตรฐาน</div>
      <div class="nav-sub-item" onclick="nav('strat-all')" data-page="strat-all">5⃣ ด้านการเงิน</div>
    </div>
  </div>

  <div class="nav-group">
    <div class="nav-lbl">KPI HA (สรพ.)</div>
    <div class="nav-item" id="ni-ha" onclick="toggleNavGroup('ni-ha','ns-ha')">
      <span class="nav-icon">🏆</span>
      <span class="nav-text">KPI HA</span>
      <span class="nav-arrow">›</span>
    </div>
    <div class="nav-sub" id="ns-ha">
      <div class="nav-sub-item" onclick="nav('ha-p1')" data-page="ha-p1">📌 Part 1 – ทีมนำ</div>
      <div class="nav-sub-item" onclick="nav('ha-p2')" data-page="ha-p2">⚙️ Part 2 – ระบบงานสำคัญ</div>
      <div class="nav-sub-item" onclick="nav('ha-p3')" data-page="ha-p3">🩺 Part 3 – PCT</div>
      <div class="nav-sub-item" onclick="nav('ha-p4')" data-page="ha-p4">📈 Part 4 – ผลลัพธ์</div>
    </div>
  </div>

  <div class="nav-group" id="adminNav" style="display:none">
    <div class="nav-lbl">จัดการระบบ</div>
    <div class="nav-item" onclick="nav('admin-users')" data-page="admin-users">
      <span class="nav-icon">👥</span><span class="nav-text">ผู้ใช้งาน</span>
    </div>
    <div class="nav-item" onclick="nav('admin-logs')" data-page="admin-logs">
      <span class="nav-icon">📜</span><span class="nav-text">Log การใช้งาน</span>
    </div>
    <div class="nav-item" onclick="nav('admin-settings')" data-page="admin-settings">
      <span class="nav-icon">⚙️</span><span class="nav-text">ตั้งค่าระบบ</span>
    </div>
  </div>

  <div class="sb-footer">
    <div>ปีงบประมาณ: <b id="sbYear">2568</b></div>
    <div style="margin-top:3px">v2.0 · PHP Edition</div>
  </div>
</nav>

<!-- ========== TOPBAR ========== -->
<div id="topbar">
  <div class="tb-left">
    <button class="hamburger" onclick="toggleSidebar()">☰</button>
    <div style="display:flex;align-items:center;gap:10px">
      <img src="https://www.maetaeng.go.th/assets/3-DPQvDzlj.png"
           alt="" style="width:32px;height:32px;object-fit:contain;display:none"
           id="topbarLogo" onerror="this.style.display='none'">
      <span class="tb-title" id="topbarTitle">ภาพรวม Dashboard</span>
    </div>
  </div>
  <div class="tb-right">
    <!-- Theme toggle -->
    <div class="theme-toggle">
      <button class="theme-btn" data-t="dark"  onclick="setTheme('dark')"  title="Dark">🌙</button>
      <button class="theme-btn" data-t="light" onclick="setTheme('light')" title="Light">☀️</button>
      <button class="theme-btn active" data-t="auto" onclick="setTheme('auto')"  title="Auto">🔄</button>
    </div>
    <!-- Guest: show login btn -->
    <div id="guestArea">
      <button class="btn-login-top" onclick="openLoginModal()">🔐 เข้าสู่ระบบ</button>
    </div>
    <!-- Logged in: user chip -->
    <div id="userArea" style="display:none;align-items:center;gap:8px">
      <div class="user-chip">
        <div class="user-av" id="userAv">SA</div>
        <div class="user-info">
          <div class="uname" id="userNameDisp">-</div>
          <div><span class="role-badge" id="roleBadgeDisp">-</span></div>
        </div>
      </div>
      <button class="btn-logout" onclick="doLogout()">ออก</button>
    </div>
  </div>
</div>

<!-- ========== MAIN ========== -->
<div id="main">

  <!-- === PAGE: OVERVIEW === -->
  <div class="page active" id="page-overview">
    <div class="ph">
      <h2>📊 ภาพรวม KPI Dashboard</h2>
      <p id="ovSubtitle">โรงพยาบาลแม่แตง · ปีงบประมาณ <span id="ovYear">2568</span> · อัปเดต: <span id="ovUpdate">-</span></p>
    </div>
    <div class="stat-grid">
      <div class="stat-card sc-blue">
        <div class="stat-lbl">KPI ยุทธศาสตร์ทั้งหมด</div>
        <div class="stat-val" id="ov-total">36</div>
        <div class="stat-sub">12 แผนงาน · 28 โครงการ</div>
        <div class="stat-icon">🎯</div>
        <div class="prog"><div class="prog-fill" id="ov-prog-total" style="width:100%;background:var(--accent)"></div></div>
      </div>
      <div class="stat-card sc-green">
        <div class="stat-lbl">ผ่านเป้าหมาย</div>
        <div class="stat-val" id="ov-pass" style="color:var(--success)">–</div>
        <div class="stat-sub">KPI ยุทธศาสตร์</div>
        <div class="stat-icon">✅</div>
        <div class="prog"><div class="prog-fill" id="ov-prog-pass" style="background:var(--success)"></div></div>
      </div>
      <div class="stat-card sc-red">
        <div class="stat-lbl">ไม่ผ่านเป้าหมาย</div>
        <div class="stat-val" id="ov-fail" style="color:var(--danger)">–</div>
        <div class="stat-sub">KPI ยุทธศาสตร์</div>
        <div class="stat-icon">⚠️</div>
        <div class="prog"><div class="prog-fill" id="ov-prog-fail" style="background:var(--danger)"></div></div>
      </div>
      <div class="stat-card sc-yellow">
        <div class="stat-lbl">รอบันทึกข้อมูล</div>
        <div class="stat-val" id="ov-pend" style="color:var(--warning)">–</div>
        <div class="stat-sub">KPI ยุทธศาสตร์</div>
        <div class="stat-icon">📝</div>
        <div class="prog"><div class="prog-fill" id="ov-prog-pend" style="background:var(--warning)"></div></div>
      </div>
      <div class="stat-card sc-purple">
        <div class="stat-lbl">KPI HA ทั้งหมด</div>
        <div class="stat-val" id="ov-ha">–</div>
        <div class="stat-sub">Part 1 + 2 + 4</div>
        <div class="stat-icon">🏆</div>
      </div>
    </div>
    <div class="g2">
      <div class="chart-wrap">
        <h4>📈 สถานะ KPI ยุทธศาสตร์ ปี <span id="chartYearLbl">2568</span></h4>
        <canvas id="chartDonut" height="180"></canvas>
      </div>
      <div class="chart-wrap">
        <h4>📊 HA – อุบัติการณ์ Part 2 RM (2565–2569)</h4>
        <canvas id="chartBar" height="180"></canvas>
      </div>
    </div>
    <div class="g2">
      <div class="card">
        <div class="card-head">
          <div class="card-icon" style="background:var(--danger-bg)">⚠️</div>
          <h3>KPI ยุทธศาสตร์ที่ต้องติดตาม</h3>
        </div>
        <div class="card-body" id="watchStrat" style="max-height:260px;overflow-y:auto">
          <div style="color:var(--text3);text-align:center;padding:20px">กำลังโหลด...</div>
        </div>
      </div>
      <div class="card">
        <div class="card-head">
          <div class="card-icon" style="background:var(--warning-bg)">📝</div>
          <h3>HA KPI รอบันทึกปี <span id="haPendYear">2568</span></h3>
        </div>
        <div class="card-body" id="watchHA" style="max-height:260px;overflow-y:auto">
          <div style="color:var(--text3);text-align:center;padding:20px">กำลังโหลด...</div>
        </div>
      </div>
    </div>
  </div>

  <!-- === PAGE: STRATEGIC === -->
  <div class="page" id="page-strat-all">
    <div class="ph">
      <h2>🎯 KPI ยุทธศาสตร์ รพ.แม่แตง</h2>
      <p>12 แผนงาน · 28 โครงการ · 36 ตัวชี้วัด | ปีงบ <span id="stratYearLbl">2568</span></p>
    </div>
    <div class="alert alert-info">ℹ️ ตัวชี้วัดที่มีป้าย <span class="chip chip-ha">🔗 HA</span> — เมื่อบันทึกข้อมูลใน KPI HA จะ Sync มาอัตโนมัติ (ทิศทาง HA → ยุทธศาสตร์ เท่านั้น)</div>
    <div class="ytabs" id="stratYearTabs"></div>
    <div class="search-box"><span class="search-icon">🔍</span><input type="text" placeholder="ค้นหาตัวชี้วัด..." oninput="filterStrat(this.value)"></div>
    <div id="stratAccordion"></div>
  </div>

  <!-- === PAGE: HA PART 1 === -->
  <div class="page" id="page-ha-p1">
    <div class="ph">
      <h2>📌 KPI HA — Part 1: ทีมนำ</h2>
      <p>SAR Part 1 · การนำ กลยุทธ์ ผู้ป่วย การวัด บุคลากร การปฏิบัติการ</p>
    </div>
    <div class="alert alert-warn">⚡ บันทึก KPI HA ที่เชื่อมโยงกับ KPI ยุทธศาสตร์ — ระบบจะ Sync อัตโนมัติ · ต้อง Login เพื่อบันทึก/แก้ไข</div>
    <div class="ptabs" id="p1Tabs"></div>
    <div class="ytabs" id="p1YearTabs"></div>
    <div id="p1Content"></div>
  </div>

  <!-- === PAGE: HA PART 2 === -->
  <div class="page" id="page-ha-p2">
    <div class="ph">
      <h2>⚙️ KPI HA — Part 2: ระบบงานสำคัญ</h2>
      <p>RM · NSO · MSO · ENV · IC · IM · PTC · LAB+X-ray · เฝ้าระวังโรค · ชุมชน</p>
    </div>
    <div class="alert alert-warn">⚡ บันทึก KPI HA ที่เชื่อมโยงกับ KPI ยุทธศาสตร์ — ระบบจะ Sync อัตโนมัติ · ต้อง Login เพื่อบันทึก/แก้ไข</div>
    <div class="ptabs" id="p2Tabs"></div>
    <div class="ytabs" id="p2YearTabs"></div>
    <div id="p2Content"></div>
  </div>

  <!-- === PAGE: HA PART 3 === -->
  <div class="page" id="page-ha-p3">
    <div class="ph">
      <h2>🩺 KPI HA — Part 3: PCT / กลุ่มผู้ป่วย</h2>
    </div>
    <div class="alert alert-info">📌 Part 3 อยู่ระหว่างรวบรวมข้อมูลจากทีม PCT กรุณาส่งข้อมูลให้ Super Admin เพื่อนำเข้าระบบ</div>
    <div class="card">
      <div class="card-body" style="text-align:center;padding:40px;color:var(--text3)">
        🏗️ กำลังเตรียมข้อมูล Part 3<br><small>โปรดติดต่อเจ้าหน้าที่ IT เพื่อนำเข้าข้อมูล</small>
      </div>
    </div>
  </div>

  <!-- === PAGE: HA PART 4 === -->
  <div class="page" id="page-ha-p4">
    <div class="ph">
      <h2>📈 KPI HA — Part 4: ผลลัพธ์</h2>
      <p>SAR Part 4 · ผลลัพธ์ทางคลินิก ความปลอดภัย และประสิทธิภาพ</p>
    </div>
    <div class="alert alert-warn">⚡ บันทึก KPI HA ที่เชื่อมโยงกับ KPI ยุทธศาสตร์ — ระบบจะ Sync อัตโนมัติ · ต้อง Login เพื่อบันทึก/แก้ไข</div>
    <div class="ptabs" id="p4Tabs"></div>
    <div class="ytabs" id="p4YearTabs"></div>
    <div id="p4Content"></div>
  </div>

  <!-- === PAGE: ADMIN – USERS === -->
  <div class="page" id="page-admin-users">
    <div class="ph"><h2>👥 จัดการผู้ใช้งาน</h2></div>
    <div id="userListWrap"></div>
  </div>

  <!-- === PAGE: ADMIN – LOGS === -->
  <div class="page" id="page-admin-logs">
    <div class="ph"><h2>📜 Log การใช้งานระบบ</h2><p>บันทึกการ Login · บันทึกข้อมูล · แก้ไขข้อมูล</p></div>
    <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
      <button class="btn btn-primary btn-sm" onclick="loadLogs()">🔄 โหลด Log</button>
      <select id="logLevelFilter" class="form-group" style="margin:0;width:auto" onchange="filterLogs()">
        <option value="">ทุก Level</option>
        <option value="info">Info</option>
        <option value="success">Success</option>
        <option value="warn">Warn</option>
        <option value="danger">Danger</option>
      </select>
      <div class="search-box" style="margin:0;flex:1;min-width:200px">
        <span class="search-icon">🔍</span>
        <input type="text" placeholder="ค้นหา Log..." oninput="filterLogs()">
      </div>
    </div>
    <div class="card"><div class="card-body nb">
      <div class="tbl-wrap">
        <table id="logTable">
          <thead><tr>
            <th>เวลา</th><th>ผู้ใช้</th><th>Role</th>
            <th>การดำเนินการ</th><th>รายละเอียด</th><th>IP</th><th>Level</th>
          </tr></thead>
          <tbody id="logBody"><tr><td colspan="7" style="text-align:center;color:var(--text3);padding:20px">กด "โหลด Log" เพื่อแสดงข้อมูล</td></tr></tbody>
        </table>
      </div>
    </div></div>
  </div>

  <!-- === PAGE: ADMIN – SETTINGS === -->
  <div class="page" id="page-admin-settings">
    <div class="ph"><h2>⚙️ ตั้งค่าระบบ</h2></div>
    <div class="card">
      <div class="card-head"><div class="card-icon" style="background:var(--accent-bg)">⚙️</div><h3>ข้อมูลทั่วไป</h3></div>
      <div class="card-body">
        <div class="row-2">
          <div class="form-group"><label>ชื่อโรงพยาบาล</label><input type="text" id="setHospName" value="<?= htmlspecialchars($settings['hospitalName']) ?>"></div>
          <div class="form-group"><label>ปีงบประมาณปัจจุบัน</label>
            <select id="setYear">
              <?php foreach(['2565','2566','2567','2568','2569'] as $y): ?>
              <option value="<?=$y?>"<?=$y===$settings['activeYear']?' selected':'??>><?=$y?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <button class="btn btn-primary" onclick="saveSettings()">💾 บันทึกการตั้งค่า</button>
      </div>
    </div>
  </div>

</div><!-- /main -->

<!-- ========== LOGIN MODAL ========== -->
<div id="modalOverlay">
  <div class="modal" id="modalBox" style="max-width:460px">
    <div class="modal-head">
      <h3 id="modalTitle">🔐 เข้าสู่ระบบ</h3>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body" id="modalBody">
      <div style="display:flex;justify-content:center;margin-bottom:18px">
        <img src="https://www.maetaeng.go.th/assets/3-DPQvDzlj.png"
             alt="Logo" style="width:60px;height:60px;object-fit:contain"
             onerror="this.style.display='none'">
      </div>
    </div>
    <div class="modal-foot" id="modalFoot">
      <button class="btn btn-outline" onclick="closeModal()">ยกเลิก</button>
      <button class="btn btn-primary" id="modalSaveBtn" onclick="modalSave()">ยืนยัน</button>
    </div>
  </div>
</div>

<!-- ========== TOAST ========== -->
<div id="toast"></div>

<script>
/* ================================================================
   THEME SYSTEM
   ================================================================ */
const THEME_KEY = 'mt_theme';
let _currentTheme = localStorage.getItem(THEME_KEY) || 'auto';

function applyTheme(t) {
  let resolved = t;
  if (t === 'auto') {
    const hr = new Date().getHours();
    const sysDark = window.matchMedia('(prefers-color-scheme:dark)').matches;
    resolved = (hr >= 6 && hr < 18) ? 'light' : 'dark';
    if (sysDark && !(hr >= 6 && hr < 18)) resolved = 'dark';
  }
  document.documentElement.setAttribute('data-theme', resolved);
  document.querySelectorAll('.theme-btn').forEach(b => b.classList.toggle('active', b.dataset.t === t));
}
function setTheme(t) {
  _currentTheme = t; localStorage.setItem(THEME_KEY, t); applyTheme(t);
}
applyTheme(_currentTheme);
// auto-update every 5 min
setInterval(() => { if(_currentTheme === 'auto') applyTheme('auto'); }, 300000);

/* ================================================================
   NAVIGATION
   ================================================================ */
const PAGE_TITLES = {
  'overview':'ภาพรวม Dashboard','strat-all':'KPI ยุทธศาสตร์ – ทั้งหมด',
  'ha-p1':'KPI HA – Part 1 ทีมนำ','ha-p2':'KPI HA – Part 2 ระบบงานสำคัญ',
  'ha-p3':'KPI HA – Part 3 PCT','ha-p4':'KPI HA – Part 4 ผลลัพธ์',
  'admin-users':'จัดการผู้ใช้งาน','admin-logs':'Log การใช้งาน','admin-settings':'ตั้งค่าระบบ',
};
function nav(pid) {
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('[data-page]').forEach(n=>n.classList.remove('active'));
  const pg = document.getElementById('page-'+pid);
  if(pg) pg.classList.add('active');
  document.querySelectorAll(`[data-page="${pid}"]`).forEach(n=>n.classList.add('active'));
  document.getElementById('topbarTitle').textContent = PAGE_TITLES[pid] || pid;
  closeSidebar();
}
function toggleNavGroup(itemId, subId) {
  const item = document.getElementById(itemId);
  const sub  = document.getElementById(subId);
  item.classList.toggle('open');
  sub.classList.toggle('open');
}
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('sb-open');
  document.getElementById('sbOverlay').classList.toggle('open');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('sb-open');
  document.getElementById('sbOverlay').classList.remove('open');
}

/* ================================================================
   AUTH
   ================================================================ */
let CU = null; // current user

async function checkSession() {
  const res = await api({action:'check'});
  if(res.user) setUser(res.user);
  else clearUser();
}
function setUser(u) {
  CU = u;
  document.getElementById('guestArea').style.display = 'none';
  document.getElementById('userArea').style.display = 'flex';
  document.getElementById('userAv').textContent = u.name.slice(0,2);
  document.getElementById('userNameDisp').textContent = u.name;
  const rb = document.getElementById('roleBadgeDisp');
  rb.textContent = u.role==='superadmin'?'Super Admin':u.role==='admin'?'Admin':'User';
  rb.className = 'role-badge role-'+u.role;
  if(u.role==='superadmin') document.getElementById('adminNav').style.display='block';
}
function clearUser() {
  CU = null;
  document.getElementById('guestArea').style.display = 'block';
  document.getElementById('userArea').style.display = 'none';
  document.getElementById('adminNav').style.display = 'none';
}
async function doLogout() {
  await api({action:'logout'});
  clearUser(); toast('ออกจากระบบเรียบร้อย','t-info');
  nav('overview');
}
function canEdit(section) {
  if(!CU) return false;
  if(CU.role==='superadmin') return true;
  if(CU.role==='admin') return (CU.canEdit||[]).some(x=>section.startsWith(x));
  return false;
}

/* ================================================================
   MODAL SYSTEM
   ================================================================ */
let _modalMode = null, _modalCtx = null;

function openModal(title, bodyHtml, saveLabel, mode, ctx) {
  _modalMode=mode; _modalCtx=ctx;
  document.getElementById('modalTitle').textContent = title;
  document.getElementById('modalBody').innerHTML = bodyHtml;
  document.getElementById('modalSaveBtn').textContent = saveLabel || 'ยืนยัน';
  document.getElementById('modalOverlay').classList.add('open');
}
function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
  _modalMode=null; _modalCtx=null;
}
document.getElementById('modalOverlay').addEventListener('click', e=>{
  if(e.target===document.getElementById('modalOverlay')) closeModal();
});

/* login modal */
function openLoginModal(afterLoginFn) {
  _loginAfterFn = afterLoginFn || null;
  openModal('🔐 เข้าสู่ระบบ – รพ.แม่แตง', `
    <div style="text-align:center;margin-bottom:20px">
      <img src="https://www.maetaeng.go.th/assets/3-DPQvDzlj.png"
           alt="" style="width:56px;height:56px;object-fit:contain;margin:0 auto"
           onerror="this.style.display='none'">
      <p style="color:var(--text3);font-size:12px;margin-top:8px">กรุณาเข้าสู่ระบบเพื่อบันทึก/แก้ไขข้อมูล</p>
    </div>
    <div class="form-group"><label>ชื่อผู้ใช้</label>
      <input type="text" id="li-user" placeholder="username" autocomplete="username" autofocus></div>
    <div class="form-group"><label>รหัสผ่าน</label>
      <input type="password" id="li-pass" placeholder="password" autocomplete="current-password"
             onkeydown="if(event.key==='Enter')modalSave()"></div>
    <div id="li-err" style="color:var(--danger);font-size:12px;display:none;margin-top:-6px"></div>
    <div style="margin-top:12px;padding:10px;background:var(--card2);border-radius:8px;font-size:11px;color:var(--text3)">
      <b style="color:var(--accent)">บัญชีทดสอบ:</b> superadmin / Admin@1234 &nbsp;|&nbsp; viewer / View@123
    </div>
  `, '🔐 เข้าสู่ระบบ', 'login', null);
  setTimeout(()=>document.getElementById('li-user')?.focus(), 100);
}
let _loginAfterFn = null;

async function modalSave() {
  if(_modalMode==='login') {
    const u = document.getElementById('li-user').value.trim();
    const p = document.getElementById('li-pass').value;
    const res = await api({action:'login', username:u, password:p});
    if(res.ok) {
      setUser(res.user); closeModal();
      toast('ยินดีต้อนรับ '+res.user.name+' 🎉','t-success');
      if(_loginAfterFn) { _loginAfterFn(); _loginAfterFn=null; }
      renderAll();
    } else {
      document.getElementById('li-err').style.display='block';
      document.getElementById('li-err').textContent = res.msg;
    }
  } else if(_modalMode==='editHA') {
    await saveHAData(_modalCtx);
  } else if(_modalMode==='editStrat') {
    await saveStratData(_modalCtx);
  } else if(_modalMode==='addUser') {
    await doAddUser();
  }
}

/* ================================================================
   API HELPER
   ================================================================ */
async function api(params) {
  const form = new FormData();
  for(const [k,v] of Object.entries(params)) form.append(k,v);
  const r = await fetch('', {method:'POST', body:form});
  return r.json();
}

/* ================================================================
   KPI DATA (defaults same as before)
   ================================================================ */
const YEARS = ['2565','2566','2567','2568','2569'];
let activeYear = '<?= $settings['activeYear'] ?>';
let KD = null; // kpi data cache

function defaultKD() {
  return {
    strategic: {plans: getDefaultStrategic()},
    ha: {part1: getDefaultP1(), part2: getDefaultP2(), part4: getDefaultP4()},
    lastUpdate: new Date().toISOString()
  };
}
async function loadKD() {
  const res = await api({action:'loadKPI'});
  KD = res.data || defaultKD();
  if(!res.data) await api({action:'saveKPI', payload:JSON.stringify(KD), section:'init'});
}
async function saveKD(section='') {
  KD.lastUpdate = new Date().toISOString();
  await api({action:'saveKPI', payload:JSON.stringify(KD), section});
}

/* ================================================================
   STATUS HELPER
   ================================================================ */
function getStatus(v, target) {
  if(v===''||v===null||v===undefined) return 'pend';
  const vs = v.toString().toLowerCase().trim();
  if(vs==='na'||vs==='') return 'na';
  if(vs.includes('ผ่าน')&&!vs.includes('ไม่')) return 'pass';
  if(vs.includes('ไม่ผ่าน')) return 'fail';
  const numV = parseFloat(vs);
  if(isNaN(numV)) return 'na';
  const t = target.toString();
  const numT = parseFloat(t.replace(/[^0-9.]/g,''));
  if(isNaN(numT)) return 'na';
  if(t.includes('>=')||t.startsWith('>')) return numV >= numT ? 'pass' : 'fail';
  if(t.includes('<=')||t.startsWith('<')) return numV <= numT ? 'pass' : 'fail';
  return 'na';
}
const SMAP = {pass:['✅ ผ่าน','chip-pass'],fail:['❌ ไม่ผ่าน','chip-fail'],pend:['⏳ รอข้อมูล','chip-pend'],na:['— N/A','chip-na']};
function statusChip(s){ const m=SMAP[s]||SMAP.na; return `<span class="chip ${m[1]}">${m[0]}</span>`; }
function valColor(v,t){const s=getStatus(v,t);return s==='pass'?'var(--success)':s==='fail'?'var(--danger)':'var(--text)';}

/* ================================================================
   OVERVIEW RENDER
   ================================================================ */
let chartDonut, chartBar;
function renderOverview() {
  if(!KD) return;
  const allI = KD.strategic.plans.flatMap(p=>p.projects.flatMap(pr=>pr.indicators));
  let pass=0,fail=0,pend=0;
  allI.forEach(i=>{ const s=getStatus(i.values[activeYear],i.target); if(s==='pass')pass++;else if(s==='fail')fail++;else pend++; });
  const total = allI.length;
  document.getElementById('ov-pass').textContent=pass;
  document.getElementById('ov-fail').textContent=fail;
  document.getElementById('ov-pend').textContent=pend;
  document.getElementById('ov-prog-pass').style.width=Math.round(pass/total*100)+'%';
  document.getElementById('ov-prog-fail').style.width=Math.round(fail/total*100)+'%';
  document.getElementById('ov-prog-pend').style.width=Math.round(pend/total*100)+'%';
  const haAll = [...KD.ha.part1.sections,...KD.ha.part2.sections,...KD.ha.part4.sections].flatMap(s=>s.indicators);
  document.getElementById('ov-ha').textContent=haAll.length;
  document.getElementById('ovYear').textContent=activeYear;
  document.getElementById('chartYearLbl').textContent=activeYear;
  document.getElementById('haPendYear').textContent=activeYear;
  document.getElementById('sbYear').textContent=activeYear;
  // watch strat (fails)
  const fails=allI.filter(i=>getStatus(i.values[activeYear],i.target)==='fail');
  const ws=document.getElementById('watchStrat');
  ws.innerHTML=fails.length?fails.map(i=>`<div class="watch-item"><div class="watch-dot" style="background:var(--danger-bg);color:var(--danger)">!</div><div style="flex:1;font-size:12.5px">${i.name}</div><b style="color:var(--danger);font-size:12px;flex-shrink:0">${i.values[activeYear]||'—'}</b></div>`).join('')
    :'<div style="text-align:center;padding:20px;color:var(--success)">🎉 ทุกตัวชี้วัดผ่านเป้าหมาย!</div>';
  // watch HA
  const haPend=haAll.filter(i=>!i.values[activeYear]);
  const wh=document.getElementById('watchHA');
  wh.innerHTML=haPend.length?haPend.map(i=>`<div class="watch-item"><div class="watch-dot" style="background:var(--warning-bg);color:var(--warning)">?</div><div style="flex:1;font-size:12.5px">${i.name}</div><span style="color:var(--text3);font-size:11px;flex-shrink:0">${i.responsible||''}</span></div>`).join('')
    :'<div style="text-align:center;padding:20px;color:var(--success)">🎉 บันทึกข้อมูลครบแล้ว!</div>';
  // last update
  const lu = KD.lastUpdate ? new Date(KD.lastUpdate).toLocaleString('th-TH') : '-';
  document.getElementById('ovUpdate').textContent=lu;
  // charts
  if(chartDonut) chartDonut.destroy();
  if(chartBar) chartBar.destroy();
  const cs = getComputedStyle(document.documentElement);
  const getV = v => cs.getPropertyValue(v).trim();
  chartDonut=new Chart(document.getElementById('chartDonut'),{type:'doughnut',data:{labels:['ผ่าน','ไม่ผ่าน','รอข้อมูล'],datasets:[{data:[pass,fail,pend],backgroundColor:[getV('--success'),getV('--danger'),getV('--warning')],borderWidth:0,hoverOffset:6}]},options:{responsive:true,cutout:'65%',plugins:{legend:{position:'bottom',labels:{color:getV('--text2'),font:{family:'Sarabun',size:11}}}}}});
  const rmS = KD.ha.part2.sections.find(s=>s.id==='RM');
  const rmI = rmS?.indicators.find(i=>i.id==='h2-rm-1');
  chartBar=new Chart(document.getElementById('chartBar'),{type:'bar',data:{labels:YEARS,datasets:[{label:'จำนวนรายงานอุบัติการณ์',data:YEARS.map(y=>parseInt(rmI?.values[y])||0),backgroundColor:'rgba(56,189,248,.65)',borderRadius:5,hoverBackgroundColor:getV('--accent')}]},options:{responsive:true,plugins:{legend:{labels:{color:getV('--text2'),font:{family:'Sarabun',size:11}}}},scales:{x:{ticks:{color:getV('--text2'),font:{family:'Sarabun'}},grid:{color:getV('--border')}},y:{ticks:{color:getV('--text2'),font:{family:'Sarabun'}},grid:{color:getV('--border')}}}}});
}

/* ================================================================
   STRATEGIC RENDER
   ================================================================ */
function renderStratYear() {
  const el=document.getElementById('stratYearTabs');
  el.innerHTML=YEARS.map(y=>`<div class="ytab${y===activeYear?' active':''}" onclick="setYear('${y}')">${y}</div>`).join('');
  document.getElementById('stratYearLbl').textContent=activeYear;
}
function renderStrategic() {
  if(!KD) return;
  renderStratYear();
  const c=document.getElementById('stratAccordion');
  c.innerHTML=KD.strategic.plans.map(plan=>{
    const allI=plan.projects.flatMap(p=>p.indicators);
    const pass=allI.filter(i=>getStatus(i.values[activeYear],i.target)==='pass').length;
    const pct=Math.round(pass/allI.length*100);
    return `<div class="card accord open" id="plan-${plan.id}" style="margin-bottom:10px">
      <div class="card-head" onclick="this.parentElement.classList.toggle('open')">
        <div class="card-icon" style="background:${plan.color}22;color:${plan.color}">${plan.icon}</div>
        <h3>${plan.name}</h3>
        <div class="card-meta"><span>${pass}/${allI.length} ผ่าน (${pct}%)</span><span>${plan.projects.length} โครงการ</span></div>
        <span class="card-arrow">›</span>
      </div>
      <div class="card-body">
        ${plan.projects.map(proj=>`
          <div class="proj-header">📁 ${proj.name}</div>
          <div class="tbl-wrap" style="margin-bottom:14px"><table>
            <thead><tr>
              <th style="width:60px">ที่</th><th>ตัวชี้วัด</th><th>เป้าหมาย</th>
              <th>ปี ${activeYear}</th><th>สถานะ</th><th>HA</th>
              ${canEdit('strategic')?'<th>จัดการ</th>':''}
            </tr></thead><tbody>
              ${proj.indicators.map(ind=>{
                const s=getStatus(ind.values[activeYear],ind.target);
                return `<tr>
                  <td style="color:var(--text3)">${ind.no}</td>
                  <td>${ind.name}</td>
                  <td style="color:var(--text3);font-size:12px;white-space:nowrap">${ind.target}</td>
                  <td style="font-weight:600;color:${valColor(ind.values[activeYear],ind.target)}">${ind.values[activeYear]||'—'}</td>
                  <td>${statusChip(s)}</td>
                  <td>${ind.haLink?'<span class="chip chip-ha">🔗 HA</span>':'<span style="color:var(--border2)">—</span>'}</td>
                  ${canEdit('strategic')?`<td><button class="btn btn-xs btn-outline" onclick="openEditStrat('${ind.id}')">✏️</button></td>`:''}
                </tr>`;
              }).join('')}
            </tbody>
          </table></div>
        `).join('')}
      </div>
    </div>`;
  }).join('');
}
function filterStrat(q) {
  document.querySelectorAll('#stratAccordion tbody tr').forEach(r=>{
    r.style.display=r.textContent.toLowerCase().includes(q.toLowerCase())?'':'none';
  });
}

/* ================================================================
   HA RENDER
   ================================================================ */
let p1Active='1-1', p2Active='RM', p4Active='4-1';
function renderHAPart(part, tabId, ytabId, contentId, activeS, setFn) {
  if(!KD) return;
  const sections = KD.ha[part].sections;
  document.getElementById(tabId).innerHTML=sections.map(s=>`<div class="ptab${s.id===activeS?' active':''}" onclick="${setFn}('${s.id}')">${s.name}</div>`).join('');
  document.getElementById(ytabId).innerHTML=YEARS.map(y=>`<div class="ytab${y===activeYear?' active':''}" onclick="setYear('${y}')">${y}</div>`).join('');
  const sec=sections.find(s=>s.id===activeS);
  renderHASection(contentId, sec, part);
}
function renderHASection(cid, sec, part) {
  if(!sec) return;
  const perm = 'ha-'+part.replace('part','p');
  const canE = canEdit(perm)||canEdit('ha-p'+part.replace('part','').replace('part',''));
  const supE = CU&&CU.role==='superadmin';
  const ae = canE||supE;
  const c=document.getElementById(cid);
  // mini charts (numeric only)
  const numInds = sec.indicators.filter(i=>YEARS.some(y=>!isNaN(parseFloat(i.values[y]))));
  c.innerHTML=`
    <div class="card">
      <div class="card-head" style="cursor:default">
        <div class="card-icon" style="background:var(--accent-bg)">📋</div>
        <h3>${sec.name}</h3>
        <div class="card-meta"><span>ผู้รับผิดชอบ: ${sec.responsible}</span></div>
      </div>
      <div class="card-body nb"><div class="tbl-wrap"><table>
        <thead><tr>
          <th>ที่</th><th>ตัวชี้วัด</th><th>เป้าหมาย</th><th>ผู้รับผิดชอบ</th>
          ${YEARS.map(y=>`<th style="text-align:center">${y}</th>`).join('')}
          <th>สถานะ</th><th>ยุทธศาสตร์</th>
          ${ae?'<th>จัดการ</th>':''}
        </tr></thead><tbody>
          ${sec.indicators.map(ind=>{
            const s=getStatus(ind.values[activeYear],ind.target);
            return `<tr>
              <td style="color:var(--text3)">${ind.no}</td>
              <td style="min-width:200px">${ind.name}</td>
              <td style="color:var(--text3);font-size:12px;white-space:nowrap">${ind.target}</td>
              <td style="font-size:12px;color:var(--text3)">${ind.responsible||''}</td>
              ${YEARS.map(y=>{const v=ind.values[y];return `<td style="text-align:center;font-weight:${v?'600':'400'};color:${v?valColor(v,ind.target):'var(--text3)'};">${v||'—'}</td>`;}).join('')}
              <td>${statusChip(s)}</td>
              <td>${ind.linkStrat?'<span class="chip chip-strat">🔗 ยุทธ</span>':'<span style="color:var(--border2)">—</span>'}</td>
              ${ae?`<td><button class="btn btn-xs btn-primary" onclick="openEditHA('${part}','${sec.id}','${ind.id}')">✏️</button></td>`:''}
            </tr>`;
          }).join('')}
        </tbody>
      </table></div></div>
    </div>
    ${numInds.length?`<div class="card">
      <div class="card-head" style="cursor:default"><div class="card-icon" style="background:var(--success-bg)">📈</div><h3>กราฟแนวโน้ม ${sec.name}</h3></div>
      <div class="card-body"><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
        ${numInds.slice(0,4).map((ind,i)=>`<div class="chart-wrap"><h4>${ind.no}. ${ind.name.slice(0,40)}${ind.name.length>40?'…':''}</h4><canvas id="mc-${cid}-${i}" height="110"></canvas></div>`).join('')}
      </div></div>
    </div>`:''}`;
  setTimeout(()=>{
    numInds.slice(0,4).forEach((ind,i)=>{
      const ctx=document.getElementById(`mc-${cid}-${i}`);
      if(!ctx) return;
      const cs=getComputedStyle(document.documentElement);
      new Chart(ctx,{type:'line',data:{labels:YEARS,datasets:[{label:ind.unit||'',data:YEARS.map(y=>parseFloat(ind.values[y])||null),borderColor:cs.getPropertyValue('--accent').trim(),backgroundColor:'rgba(56,189,248,.08)',tension:.4,fill:true,pointRadius:4,spanGaps:true}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:cs.getPropertyValue('--text2').trim(),font:{size:10,family:'Sarabun'}},grid:{color:cs.getPropertyValue('--border').trim()}},y:{ticks:{color:cs.getPropertyValue('--text2').trim(),font:{size:10,family:'Sarabun'}},grid:{color:cs.getPropertyValue('--border').trim()}}}}});
    });
  },80);
}
function setP1(id){p1Active=id;renderHAPart('part1','p1Tabs','p1YearTabs','p1Content',p1Active,'setP1');}
function setP2(id){p2Active=id;renderHAPart('part2','p2Tabs','p2YearTabs','p2Content',p2Active,'setP2');}
function setP4(id){p4Active=id;renderHAPart('part4','p4Tabs','p4YearTabs','p4Content',p4Active,'setP4');}

/* ================================================================
   YEAR SWITCHER
   ================================================================ */
function setYear(y) {
  activeYear=y;
  document.querySelectorAll('.ytab').forEach(t=>t.classList.toggle('active',t.textContent===y));
  renderAll();
}

/* ================================================================
   EDIT MODALS
   ================================================================ */
function requireLogin(fn) {
  if(!CU) { openLoginModal(fn); return false; }
  return true;
}

function openEditHA(part, secId, indId) {
  if(!requireLogin(()=>openEditHA(part,secId,indId))) return;
  const sec = KD.ha[part].sections.find(s=>s.id===secId);
  const ind = sec.indicators.find(i=>i.id===indId);
  const body = `
    <div class="alert alert-info" style="margin-bottom:14px">📋 <b>${ind.name}</b></div>
    <div style="font-size:12px;color:var(--text3);margin-bottom:14px">
      เป้าหมาย: <b>${ind.target}</b> | ผู้รับผิดชอบ: ${ind.responsible}
      ${ind.linkStrat?'<br><span class="chip chip-strat" style="margin-top:5px">🔗 เชื่อมโยง KPI ยุทธศาสตร์ – จะ Sync อัตโนมัติ</span>':''}
    </div>
    <div class="row-3">
    ${YEARS.map(y=>`<div class="form-group"><label>ปี ${y}</label>
      <input type="text" id="hv-${y}" value="${ind.values[y]||''}" placeholder="—"></div>`).join('')}
    </div>
    <div class="form-group"><label>หมายเหตุ</label><input type="text" id="h-note" placeholder="ถ้ามี"></div>`;
  openModal('✏️ บันทึก/แก้ไข KPI HA', body, '💾 บันทึก', 'editHA', {part,secId,indId});
}
async function saveHAData(ctx) {
  const {part,secId,indId}=ctx;
  const sec=KD.ha[part].sections.find(s=>s.id===secId);
  const ind=sec.indicators.find(i=>i.id===indId);
  YEARS.forEach(y=>{ const el=document.getElementById('hv-'+y); if(el) ind.values[y]=el.value.trim(); });
  // SYNC to strategic
  if(ind.linkStrat) {
    const sInd=KD.strategic.plans.flatMap(p=>p.projects.flatMap(pr=>pr.indicators)).find(i=>i.id===ind.linkStrat);
    if(sInd) { YEARS.forEach(y=>{ if(ind.values[y]) sInd.values[y]=ind.values[y]; }); toast('🔗 Sync KPI ยุทธศาสตร์ อัตโนมัติ ✅','t-success'); }
  }
  await saveKD('ha-'+part);
  closeModal(); renderAll(); toast('💾 บันทึกข้อมูลเรียบร้อย','t-success');
}

function openEditStrat(indId) {
  if(!requireLogin(()=>openEditStrat(indId))) return;
  const ind=KD.strategic.plans.flatMap(p=>p.projects.flatMap(pr=>pr.indicators)).find(i=>i.id===indId);
  const body=`
    <div class="alert alert-warn" style="margin-bottom:14px">⚠️ KPI ยุทธศาสตร์ ไม่สามารถ Sync ไปยัง KPI HA ได้ (ทิศทางเดียว)</div>
    <div class="alert alert-info" style="margin-bottom:14px">🎯 <b>${ind.name}</b><br><small>เป้าหมาย: ${ind.target}</small></div>
    <div class="row-3">
    ${YEARS.map(y=>`<div class="form-group"><label>ปี ${y}</label>
      <input type="text" id="sv-${y}" value="${ind.values[y]||''}" placeholder="—"></div>`).join('')}
    </div>`;
  openModal('✏️ บันทึก/แก้ไข KPI ยุทธศาสตร์', body, '💾 บันทึก', 'editStrat', {indId});
}
async function saveStratData(ctx) {
  const ind=KD.strategic.plans.flatMap(p=>p.projects.flatMap(pr=>pr.indicators)).find(i=>i.id===ctx.indId);
  YEARS.forEach(y=>{ const el=document.getElementById('sv-'+y); if(el) ind.values[y]=el.value.trim(); });
  await saveKD('strategic');
  closeModal(); renderAll(); toast('💾 บันทึกข้อมูลเรียบร้อย','t-success');
}

/* ================================================================
   ADMIN – USERS
   ================================================================ */
async function renderAdminUsers() {
  if(!CU||CU.role!=='superadmin') return;
  const res=await api({action:'getUsers'});
  if(!res.ok) return;
  const users=res.users;
  document.getElementById('userListWrap').innerHTML=`
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <b>รายการผู้ใช้งาน (${users.length} คน)</b>
      <button class="btn btn-primary btn-sm" onclick="openAddUser()">➕ เพิ่มผู้ใช้</button>
    </div>
    <div class="card"><div class="card-body nb"><div class="tbl-wrap"><table>
      <thead><tr><th>ชื่อ</th><th>Username</th><th>Role</th><th>แผนก</th><th>สิทธิ์แก้ไข</th><th>จัดการ</th></tr></thead>
      <tbody>
      ${users.map(u=>`<tr>
        <td>${u.name}</td><td style="color:var(--text3)">@${u.username}</td>
        <td><span class="role-badge role-${u.role}">${u.role==='superadmin'?'Super Admin':u.role==='admin'?'Admin':'User'}</span></td>
        <td style="color:var(--text3)">${u.dept}</td>
        <td style="font-size:11px;color:var(--text3)">${(u.canEdit||[]).join(', ')||'—'}</td>
        <td>${u.id!=='u1'?`<button class="btn btn-xs btn-danger" onclick="delUser('${u.id}')">🗑️</button>`:'—'}</td>
      </tr>`).join('')}
      </tbody>
    </table></div></div></div>`;
}
function openAddUser() {
  const body=`
    <div class="row-2">
      <div class="form-group"><label>ชื่อ-นามสกุล</label><input type="text" id="nu-name" placeholder="ชื่อผู้ใช้"></div>
      <div class="form-group"><label>Username</label><input type="text" id="nu-user" placeholder="username"></div>
    </div>
    <div class="row-2">
      <div class="form-group"><label>Password</label><input type="password" id="nu-pass" placeholder="รหัสผ่าน"></div>
      <div class="form-group"><label>แผนก</label><input type="text" id="nu-dept" placeholder="แผนก"></div>
    </div>
    <div class="form-group"><label>Role</label>
      <select id="nu-role">
        <option value="user">User – ดูได้อย่างเดียว</option>
        <option value="admin">Admin – บันทึก/แก้ไขของตนเอง</option>
        <option value="superadmin">Super Admin – ทุกสิทธิ์</option>
      </select></div>
    <div class="form-group"><label>สิทธิ์แก้ไข (Admin)</label>
      <select id="nu-perm" multiple>
        <option value="ha-part1">KPI HA Part 1</option>
        <option value="ha-part2">KPI HA Part 2</option>
        <option value="ha-part4">KPI HA Part 4</option>
        <option value="strategic">KPI ยุทธศาสตร์</option>
      </select></div>`;
  openModal('➕ เพิ่มผู้ใช้งาน', body, '✅ เพิ่ม', 'addUser', null);
}
async function doAddUser() {
  const nm=document.getElementById('nu-name').value.trim();
  const un=document.getElementById('nu-user').value.trim();
  const pw=document.getElementById('nu-pass').value;
  const dept=document.getElementById('nu-dept').value.trim();
  const role=document.getElementById('nu-role').value;
  const permEl=document.getElementById('nu-perm');
  const canEdit=permEl?[...permEl.selectedOptions].map(o=>o.value):[];
  if(!nm||!un||!pw){toast('❌ กรุณากรอกข้อมูลให้ครบ','t-error');return;}
  await api({action:'addUser',name:nm,username:un,password:pw,dept,role,canEdit:JSON.stringify(canEdit)});
  closeModal(); renderAdminUsers(); toast('✅ เพิ่มผู้ใช้งานเรียบร้อย','t-success');
}
async function delUser(uid) {
  if(!confirm('ยืนยันลบผู้ใช้งาน?')) return;
  await api({action:'deleteUser',uid});
  renderAdminUsers(); toast('🗑️ ลบเรียบร้อย','t-info');
}

/* ================================================================
   ADMIN – LOGS
   ================================================================ */
let _allLogs=[];
async function loadLogs() {
  const res=await api({action:'getLogs'});
  if(!res.ok){toast('กรุณาเข้าสู่ระบบก่อน','t-error');return;}
  _allLogs=res.logs;
  filterLogs();
}
function filterLogs() {
  const lvl=document.getElementById('logLevelFilter').value;
  const q=document.querySelector('#page-admin-logs .search-box input').value.toLowerCase();
  const filtered=_allLogs.filter(l=>{
    if(lvl&&l.level!==lvl) return false;
    if(q&&!JSON.stringify(l).toLowerCase().includes(q)) return false;
    return true;
  });
  document.getElementById('logBody').innerHTML=filtered.length
    ?filtered.map(l=>`<tr>
        <td style="white-space:nowrap;font-size:11px;color:var(--text3)">${l.ts}</td>
        <td style="font-weight:500">${l.user}</td>
        <td><span class="role-badge role-${l.role}">${l.role}</span></td>
        <td>${l.action}</td>
        <td style="color:var(--text3);font-size:12px">${l.detail||'—'}</td>
        <td style="font-size:11px;color:var(--text3)">${l.ip}</td>
        <td><span class="chip chip-${l.level==='success'?'pass':l.level==='danger'?'fail':l.level==='warn'?'pend':'na'} log-level-${l.level}">${l.level}</span></td>
      </tr>`).join('')
    :'<tr><td colspan="7" style="text-align:center;color:var(--text3);padding:20px">ไม่พบข้อมูล Log</td></tr>';
}

/* ================================================================
   SETTINGS
   ================================================================ */
async function saveSettings() {
  const payload={
    activeYear:document.getElementById('setYear').value,
    hospitalName:document.getElementById('setHospName').value.trim()
  };
  await api({action:'saveSettings',payload:JSON.stringify(payload)});
  activeYear=payload.activeYear;
  toast('💾 บันทึกการตั้งค่าเรียบร้อย','t-success');
  renderAll();
}

/* ================================================================
   TOAST
   ================================================================ */
let _toastTimer;
function toast(msg, cls='t-success') {
  const el=document.getElementById('toast');
  el.textContent=msg; el.className=cls;
  el.classList.add('show');
  clearTimeout(_toastTimer);
  _toastTimer=setTimeout(()=>el.classList.remove('show'),3200);
}

/* ================================================================
   RENDER ALL
   ================================================================ */
function renderAll() {
  renderOverview();
  renderStrategic();
  renderHAPart('part1','p1Tabs','p1YearTabs','p1Content',p1Active,'setP1');
  renderHAPart('part2','p2Tabs','p2YearTabs','p2Content',p2Active,'setP2');
  renderHAPart('part4','p4Tabs','p4YearTabs','p4Content',p4Active,'setP4');
  if(CU&&CU.role==='superadmin') renderAdminUsers();
}

/* ================================================================
   DEFAULT KPI DATA
   ================================================================ */
function getDefaultStrategic() {
  return [
{id:'p01',name:'แผนงานที่ 1 การพัฒนาระบบบริการสุขภาพและการเข้าถึงบริการ',color:'#38bdf8',icon:'🏥',
projects:[
  {id:'pr01',name:'โครงการที่ 1 พัฒนาระบบบริการผู้ป่วยนอกและผู้ป่วยใน',indicators:[
    {id:'i001',no:'1.1.1',name:'อัตราความพึงพอใจของผู้รับบริการผู้ป่วยนอก',target:'>ร้อยละ 80',unit:'ร้อยละ',haLink:'h1-3-1',values:{'2565':'82.5','2566':'84.1','2567':'85.0','2568':'','2569':''}},
    {id:'i002',no:'1.1.2',name:'อัตราความพึงพอใจของผู้รับบริการผู้ป่วยใน',target:'>ร้อยละ 80',unit:'ร้อยละ',haLink:'h1-3-2',values:{'2565':'83.2','2566':'85.5','2567':'86.2','2568':'','2569':''}},
  ]},
  {id:'pr02',name:'โครงการที่ 2 พัฒนาระบบส่งต่อและเครือข่ายบริการ',indicators:[
    {id:'i003',no:'1.2.1',name:'อัตราการส่งต่อผู้ป่วยฉุกเฉินภายใน 30 นาที',target:'>ร้อยละ 90',unit:'ร้อยละ',haLink:null,values:{'2565':'92.0','2566':'93.1','2567':'94.5','2568':'','2569':''}},
    {id:'i004',no:'1.2.2',name:'จำนวนเครือข่ายสุขภาพที่เชื่อมโยงข้อมูล',target:'>10 แห่ง',unit:'แห่ง',haLink:null,values:{'2565':'8','2566':'10','2567':'12','2568':'','2569':''}},
  ]},
  {id:'pr03',name:'โครงการที่ 3 พัฒนาคุณภาพการดูแลโรคเรื้อรัง (DM/HT)',indicators:[
    {id:'i005',no:'1.3.1',name:'อัตราผู้ป่วยเบาหวานควบคุมระดับน้ำตาลได้',target:'>ร้อยละ 40',unit:'ร้อยละ',haLink:'h4-2-2',values:{'2565':'38.2','2566':'41.5','2567':'43.0','2568':'','2569':''}},
    {id:'i006',no:'1.3.2',name:'อัตราผู้ป่วยความดันโลหิตสูงควบคุมได้',target:'>ร้อยละ 50',unit:'ร้อยละ',haLink:'h4-2-3',values:{'2565':'48.0','2566':'52.3','2567':'54.1','2568':'','2569':''}},
  ]},
]},
{id:'p02',name:'แผนงานที่ 2 การพัฒนาคุณภาพและความปลอดภัย',color:'#a78bfa',icon:'✅',
projects:[
  {id:'pr04',name:'โครงการที่ 4 พัฒนาระบบบริหารความเสี่ยงและความปลอดภัย',indicators:[
    {id:'i007',no:'2.1.1',name:'จำนวนการรายงานอุบัติการณ์',target:'เพิ่มขึ้น',unit:'ครั้ง',haLink:'h2-rm-1',values:{'2565':'3285','2566':'2250','2567':'2053','2568':'1049','2569':''}},
    {id:'i008',no:'2.1.2',name:'สัดส่วน Near Miss (A-B) / Miss (C ขึ้นไป)',target:'เพิ่มขึ้น',unit:'สัดส่วน',haLink:'h2-rm-2',values:{'2565':'7.55','2566':'3.47','2567':'3.38','2568':'2.48','2569':''}},
    {id:'i009',no:'2.1.3',name:'อัตราการรายงานอุบัติการณ์ระดับ A–B',target:'>ร้อยละ 80',unit:'ร้อยละ',haLink:'h2-rm-3',values:{'2565':'88.31','2566':'77.64','2567':'77.17','2568':'73.59','2569':''}},
  ]},
  {id:'pr05',name:'โครงการที่ 5 พัฒนาระบบป้องกันการติดเชื้อในโรงพยาบาล (IC)',indicators:[
    {id:'i010',no:'2.2.1',name:'อัตราการติดเชื้อในกระแสเลือด (BSI) ผู้ป่วย ICU',target:'<1 ต่อ 1,000 ventilator days',unit:'ต่อ 1,000',haLink:'h2-ic-1',values:{'2565':'0.8','2566':'0.5','2567':'0.3','2568':'','2569':''}},
    {id:'i011',no:'2.2.2',name:'อัตราการปฏิบัติตาม Hand Hygiene',target:'>ร้อยละ 90',unit:'ร้อยละ',haLink:'h2-ic-2',values:{'2565':'88.0','2566':'91.2','2567':'93.5','2568':'','2569':''}},
  ]},
  {id:'pr06',name:'โครงการที่ 6 พัฒนาและรับรองคุณภาพมาตรฐาน HA',indicators:[
    {id:'i012',no:'2.3.1',name:'ผลการประเมิน HA ระดับ สรพ.',target:'ผ่านการรับรอง',unit:'-',haLink:'h4-2-1',values:{'2565':'HA2','2566':'HA2','2567':'HA3','2568':'','2569':''}},
    {id:'i013',no:'2.3.2',name:'จำนวนข้อร้องเรียนด้านคุณภาพบริการ',target:'0 ต่อปี',unit:'เรื่อง',haLink:null,values:{'2565':'2','2566':'1','2567':'0','2568':'','2569':''}},
  ]},
]},
{id:'p03',name:'แผนงานที่ 3 การพัฒนาระบบข้อมูล IT และเทคโนโลยีสารสนเทศ',color:'#34d399',icon:'💻',
projects:[
  {id:'pr07',name:'โครงการที่ 7 พัฒนาระบบ HIS และฐานข้อมูลสุขภาพ',indicators:[
    {id:'i014',no:'3.1.1',name:'ร้อยละความครบถ้วนของการบันทึกข้อมูลผู้ป่วย HIS',target:'>ร้อยละ 95',unit:'ร้อยละ',haLink:'h1-4-1',values:{'2565':'92.0','2566':'94.5','2567':'96.2','2568':'','2569':''}},
    {id:'i015',no:'3.1.2',name:'อัตราความพร้อมใช้งานของระบบ HIS (Uptime)',target:'>ร้อยละ 99',unit:'ร้อยละ',haLink:null,values:{'2565':'98.5','2566':'99.1','2567':'99.5','2568':'','2569':''}},
  ]},
  {id:'pr08',name:'โครงการที่ 8 พัฒนา Dashboard และรายงานดิจิทัล',indicators:[
    {id:'i016',no:'3.2.1',name:'จำนวน Dashboard KPI ที่พัฒนาและใช้งาน',target:'>5 Dashboard',unit:'Dashboard',haLink:null,values:{'2565':'2','2566':'3','2567':'5','2568':'','2569':''}},
  ]},
]},
{id:'p04',name:'แผนงานที่ 4 การพัฒนาบุคลากรและองค์กร',color:'#fbbf24',icon:'👥',
projects:[
  {id:'pr09',name:'โครงการที่ 9 พัฒนาสมรรถนะบุคลากร',indicators:[
    {id:'i017',no:'4.1.1',name:'อัตราความผูกพันของบุคลากรต่อองค์กร',target:'>ร้อยละ 80',unit:'ร้อยละ',haLink:'h1-1-1',values:{'2565':'70.95','2566':'','2567':'','2568':'67.22','2569':''}},
    {id:'i018',no:'4.1.2',name:'ร้อยละบุคลากรได้รับการพัฒนาตามแผน',target:'>ร้อยละ 90',unit:'ร้อยละ',haLink:'h1-5-1',values:{'2565':'88.0','2566':'91.5','2567':'92.0','2568':'','2569':''}},
  ]},
  {id:'pr10',name:'โครงการที่ 10 ส่งเสริมสุขภาพและความผาสุกบุคลากร',indicators:[
    {id:'i019',no:'4.2.1',name:'ร้อยละผู้เข้าร่วมประชุมผู้นำพบเจ้าหน้าที่ (Town Hall)',target:'>ร้อยละ 50',unit:'ร้อยละ',haLink:'h1-1-2',values:{'2565':'','2566':'46.93','2567':'51.02','2568':'75','2569':''}},
    {id:'i020',no:'4.2.2',name:'อัตราการลาป่วยของบุคลากร',target:'<ร้อยละ 3',unit:'ร้อยละ',haLink:'h1-5-2',values:{'2565':'2.8','2566':'2.5','2567':'2.3','2568':'','2569':''}},
  ]},
]},
{id:'p05',name:'แผนงานที่ 5 การบริหารการเงินและงบประมาณ',color:'#f87171',icon:'💰',
projects:[
  {id:'pr11',name:'โครงการที่ 11 บริหารการเงินการคลังให้มั่นคง',indicators:[
    {id:'i021',no:'5.1.1',name:'การกำกับด้านการเงินตามเกณฑ์วิกฤต 7 ระดับ',target:'<ระดับ 3',unit:'ระดับ',haLink:'h1-1-3',values:{'2565':'ระดับ 0','2566':'ระดับ 0','2567':'ระดับ 1','2568':'ระดับ 3','2569':''}},
    {id:'i022',no:'5.1.2',name:'อัตราส่วนรายได้ต่อรายจ่าย',target:'>1',unit:'อัตราส่วน',haLink:'h4-3-1',values:{'2565':'1.05','2566':'1.02','2567':'0.98','2568':'','2569':''}},
    {id:'i023',no:'5.1.3',name:'มูลค่าสินทรัพย์สุทธิ (ล้านบาท)',target:'เพิ่มขึ้น',unit:'ล้านบาท',haLink:null,values:{'2565':'120','2566':'118','2567':'115','2568':'','2569':''}},
  ]},
]},
{id:'p06',name:'แผนงานที่ 6 การดูแลสิ่งแวดล้อมและความปลอดภัย',color:'#4ade80',icon:'🌿',
projects:[
  {id:'pr12',name:'โครงการที่ 12 จัดการสิ่งแวดล้อมและขยะติดเชื้อ',indicators:[
    {id:'i024',no:'6.1.1',name:'ผลการวิเคราะห์คุณภาพน้ำเสียที่ผ่านการบำบัด (13 Parameters)',target:'ผ่านเกณฑ์',unit:'-',haLink:'h1-1-5',values:{'2565':'ผ่าน','2566':'ผ่าน','2567':'ไม่ผ่าน','2568':'ไม่ผ่าน','2569':''}},
    {id:'i025',no:'6.1.2',name:'ข้อร้องเรียนของชุมชนเกี่ยวกับขยะติดเชื้อ/น้ำเสีย',target:'0 เรื่อง',unit:'เรื่อง',haLink:'h1-1-6',values:{'2565':'0','2566':'0','2567':'0','2568':'0','2569':''}},
  ]},
  {id:'pr13',name:'โครงการที่ 13 ลดการใช้พลังงานและเป็นมิตรต่อสิ่งแวดล้อม',indicators:[
    {id:'i026',no:'6.2.1',name:'ร้อยละการลดปริมาณขยะติดเชื้อ',target:'>ร้อยละ 5',unit:'ร้อยละ',haLink:null,values:{'2565':'3.0','2566':'5.5','2567':'6.2','2568':'','2569':''}},
  ]},
]},
{id:'p07',name:'แผนงานที่ 7 การส่งเสริมสุขภาพและป้องกันโรค',color:'#2dd4bf',icon:'💊',
projects:[
  {id:'pr14',name:'โครงการที่ 14 ส่งเสริมสุขภาพกลุ่มเสี่ยง',indicators:[
    {id:'i027',no:'7.1.1',name:'อัตราความครอบคลุมวัคซีนไข้หวัดใหญ่กลุ่มเสี่ยง',target:'>ร้อยละ 80',unit:'ร้อยละ',haLink:null,values:{'2565':'75.0','2566':'82.0','2567':'85.0','2568':'','2569':''}},
    {id:'i028',no:'7.1.2',name:'อัตราความครอบคลุมการตรวจสุขภาพผู้สูงอายุ',target:'>ร้อยละ 70',unit:'ร้อยละ',haLink:null,values:{'2565':'65.0','2566':'71.0','2567':'74.5','2568':'','2569':''}},
  ]},
  {id:'pr15',name:'โครงการที่ 15 เฝ้าระวังและควบคุมโรคในชุมชน',indicators:[
    {id:'i029',no:'7.2.1',name:'อัตราป่วยโรคไข้เลือดออก (ต่อ 100,000 ประชากร)',target:'<50',unit:'ต่อ 100,000',haLink:'h2-epi-1',values:{'2565':'42','2566':'35','2567':'28','2568':'','2569':''}},
  ]},
]},
{id:'p08',name:'แผนงานที่ 8 การธรรมาภิบาลและความโปร่งใส',color:'#fb923c',icon:'⚖️',
projects:[
  {id:'pr16',name:'โครงการที่ 16 เสริมสร้างธรรมาภิบาล ITA',indicators:[
    {id:'i030',no:'8.1.1',name:'อัตราผ่านการประเมินคุณธรรมและความโปร่งใส (ITA)',target:'>ร้อยละ 90',unit:'ร้อยละ',haLink:'h1-1-4',values:{'2565':'100','2566':'100','2567':'100','2568':'100','2569':''}},
  ]},
]},
{id:'p09',name:'แผนงานที่ 9 การพัฒนาระบบยาและเภสัชกรรม',color:'#818cf8',icon:'💉',
projects:[
  {id:'pr17',name:'โครงการที่ 17 ความปลอดภัยด้านยา',indicators:[
    {id:'i031',no:'9.1.1',name:'อัตรา Medication Error ระดับ C ขึ้นไป',target:'<0.01 ต่อ 1,000 วันนอน',unit:'ต่อ 1,000',haLink:'h2-ptc-1',values:{'2565':'0.005','2566':'0.003','2567':'0.002','2568':'','2569':''}},
    {id:'i032',no:'9.1.2',name:'อัตราการใช้ยา Antibiotics ใน OPD',target:'ลดลง',unit:'ร้อยละ',haLink:null,values:{'2565':'22.0','2566':'20.5','2567':'19.2','2568':'','2569':''}},
  ]},
]},
{id:'p10',name:'แผนงานที่ 10 การดูแลสุขภาพมารดาและเด็ก',color:'#f9a8d4',icon:'👶',
projects:[
  {id:'pr18',name:'โครงการที่ 18 พัฒนาระบบบริการมารดาและทารก',indicators:[
    {id:'i033',no:'10.1.1',name:'อัตราตายมารดา (ต่อ 100,000 การคลอดมีชีพ)',target:'0',unit:'ต่อ 100,000',haLink:'h4-1-1',values:{'2565':'0','2566':'0','2567':'0','2568':'','2569':''}},
    {id:'i034',no:'10.1.2',name:'อัตราทารกแรกเกิดน้ำหนักน้อยกว่า 2,500 กรัม',target:'<ร้อยละ 7',unit:'ร้อยละ',haLink:'h4-1-2',values:{'2565':'6.5','2566':'6.2','2567':'5.8','2568':'','2569':''}},
  ]},
]},
{id:'p11',name:'แผนงานที่ 11 การพัฒนาสถานที่และอาคารสถานที่',color:'#94a3b8',icon:'🏗️',
projects:[
  {id:'pr19',name:'โครงการที่ 19 ปรับปรุงโครงสร้างพื้นฐาน',indicators:[
    {id:'i035',no:'11.1.1',name:'ร้อยละโครงการก่อสร้าง/ปรับปรุงแล้วเสร็จตามแผน',target:'>ร้อยละ 80',unit:'ร้อยละ',haLink:null,values:{'2565':'75.0','2566':'82.0','2567':'85.0','2568':'','2569':''}},
  ]},
]},
{id:'p12',name:'แผนงานที่ 12 การพัฒนาชุมชนและพันธมิตรสุขภาพ',color:'#6ee7b7',icon:'🤝',
projects:[
  {id:'pr20',name:'โครงการที่ 20 พัฒนาเครือข่ายชุมชนและ อสม.',indicators:[
    {id:'i036',no:'12.1.1',name:'จำนวน อสม. ที่ผ่านการอบรมเชิงปฏิบัติการ',target:'>200 คน',unit:'คน',haLink:'h2-com-1',values:{'2565':'180','2566':'210','2567':'220','2568':'','2569':''}},
  ]},
]},
  ];
}
function getDefaultP1() {
  return {sections:[
    {id:'1-1',name:'1-1 การนำองค์กร',responsible:'HRD / บริหาร',indicators:[
      {id:'h1-1-1',no:'1',name:'อัตราความผูกพันของบุคลากรต่อองค์กร',target:'≥ ร้อยละ 80',unit:'ร้อยละ',responsible:'HRD',linkStrat:'i017',values:{'2565':'70.95','2566':'','2567':'','2568':'67.22','2569':''}},
      {id:'h1-1-2',no:'2',name:'ร้อยละผู้เข้าร่วมประชุมผู้นำพบเจ้าหน้าที่ (Town Hall meeting)',target:'≥ ร้อยละ 50',unit:'ร้อยละ',responsible:'HRD',linkStrat:'i019',values:{'2565':'','2566':'46.93','2567':'51.02','2568':'75','2569':''}},
      {id:'h1-1-3',no:'3',name:'การกำกับด้านการเงินตามเกณฑ์วิกฤต 7 ระดับ',target:'< ระดับ 3',unit:'ระดับ',responsible:'การเงิน (พี่อ๋อย)',linkStrat:'i021',values:{'2565':'ระดับ 0','2566':'ระดับ 0','2567':'ระดับ 1','2568':'ระดับ 3','2569':''}},
      {id:'h1-1-4',no:'4',name:'อัตราผ่านการประเมินคุณธรรมและความโปร่งใสในการดำเนินงานของหน่วยงานภาครัฐ (ITA)',target:'> ร้อยละ 90',unit:'ร้อยละ',responsible:'บริหาร/น้ำหวาน',linkStrat:'i030',values:{'2565':'100','2566':'100','2567':'100','2568':'100','2569':''}},
      {id:'h1-1-5',no:'5',name:'ผลการวิเคราะห์คุณภาพน้ำเสียที่ผ่านการบำบัด (13 Parameters)',target:'ผ่านเกณฑ์',unit:'-',responsible:'สมจิตร',linkStrat:'i024',values:{'2565':'ผ่าน','2566':'ผ่าน','2567':'ไม่ผ่าน','2568':'ไม่ผ่าน','2569':''}},
      {id:'h1-1-6',no:'6',name:'ข้อร้องเรียนของชุมชนเกี่ยวกับขยะติดเชื้อ/น้ำเสีย',target:'0 เรื่อง',unit:'เรื่อง',responsible:'สมจิตร',linkStrat:'i025',values:{'2565':'0','2566':'0','2567':'0','2568':'0','2569':''}},
    ]},
    {id:'1-2',name:'1-2 กลยุทธ์',responsible:'เลขาคณะกรรมการ',indicators:[
      {id:'h1-2-1',no:'1',name:'ร้อยละของตัวชี้วัดยุทธศาสตร์ที่ผ่านเป้าหมาย',target:'> ร้อยละ 80',unit:'ร้อยละ',responsible:'เลขาฯ',linkStrat:null,values:{'2565':'','2566':'','2567':'','2568':'','2569':''}},
      {id:'h1-2-2',no:'2',name:'จำนวนโครงการที่ดำเนินการได้ตามแผนยุทธศาสตร์',target:'≥ ร้อยละ 80',unit:'ร้อยละ',responsible:'เลขาฯ',linkStrat:null,values:{'2565':'','2566':'','2567':'','2568':'','2569':''}},
    ]},
    {id:'1-3',name:'1-3 ผู้ป่วย/ผู้รับผลงาน',responsible:'PCT / OPD',indicators:[
      {id:'h1-3-1',no:'1',name:'ร้อยละความพึงพอใจผู้รับบริการ OPD',target:'≥ ร้อยละ 80',unit:'ร้อยละ',responsible:'OPD',linkStrat:'i001',values:{'2565':'82.5','2566':'84.1','2567':'85.0','2568':'','2569':''}},
      {id:'h1-3-2',no:'2',name:'ร้อยละความพึงพอใจผู้รับบริการ IPD',target:'≥ ร้อยละ 80',unit:'ร้อยละ',responsible:'IPD',linkStrat:'i002',values:{'2565':'83.2','2566':'85.5','2567':'86.2','2568':'','2569':''}},
    ]},
    {id:'1-4',name:'1-4 การวัด/วิเคราะห์/KM',responsible:'IT / QMR',indicators:[
      {id:'h1-4-1',no:'1',name:'ร้อยละความครบถ้วนของการบันทึกข้อมูล HIS',target:'≥ ร้อยละ 95',unit:'ร้อยละ',responsible:'IT',linkStrat:'i014',values:{'2565':'92.0','2566':'94.5','2567':'96.2','2568':'','2569':''}},
    ]},
    {id:'1-5',name:'1-5 บุคลากร',responsible:'HRD',indicators:[
      {id:'h1-5-1',no:'1',name:'ร้อยละบุคลากรได้รับการพัฒนาตามแผน',target:'≥ ร้อยละ 90',unit:'ร้อยละ',responsible:'HRD',linkStrat:'i018',values:{'2565':'88.0','2566':'91.5','2567':'92.0','2568':'','2569':''}},
      {id:'h1-5-2',no:'2',name:'อัตราการลาป่วยของบุคลากร',target:'< ร้อยละ 3',unit:'ร้อยละ',responsible:'HRD',linkStrat:'i020',values:{'2565':'2.8','2566':'2.5','2567':'2.3','2568':'','2569':''}},
    ]},
    {id:'1-6',name:'1-6 การปฏิบัติการ',responsible:'RM / QMR',indicators:[
      {id:'h1-6-1',no:'1',name:'ร้อยละโครงการ/กิจกรรมที่ดำเนินการตามแผน',target:'≥ ร้อยละ 80',unit:'ร้อยละ',responsible:'QMR',linkStrat:null,values:{'2565':'','2566':'','2567':'','2568':'','2569':''}},
    ]},
  ]};
}
function getDefaultP2() {
  return {sections:[
    {id:'RM',name:'RM บริหารความเสี่ยง',responsible:'ติงลี่',indicators:[
      {id:'h2-rm-1',no:'1',name:'จำนวนการรายงานอุบัติการณ์',target:'เพิ่มขึ้น',unit:'ครั้ง',responsible:'ติงลี่',linkStrat:'i007',values:{'2565':'3285','2566':'2250','2567':'2053','2568':'1049','2569':'322'}},
      {id:'h2-rm-2',no:'2',name:'สัดส่วนเหตุการณ์ Near Miss (A-B) / Miss (C ขึ้นไป)',target:'เพิ่มขึ้น',unit:'สัดส่วน',responsible:'ติงลี่',linkStrat:'i008',values:{'2565':'7.55','2566':'3.47','2567':'3.38','2568':'2.48','2569':'3.74'}},
      {id:'h2-rm-3',no:'3',name:'อัตราการรายงานอุบัติการณ์ระดับ A–B',target:'> ร้อยละ 80',unit:'ร้อยละ',responsible:'ติงลี่',linkStrat:'i009',values:{'2565':'88.31','2566':'77.64','2567':'77.17','2568':'73.59','2569':'78.89'}},
      {id:'h2-rm-4',no:'4',name:'อัตราการทบทวนแก้ไขอุบัติการณ์ ระดับ A–D ในโปรแกรม NRLS',target:'> ร้อยละ 80',unit:'ร้อยละ',responsible:'ติงลี่',linkStrat:null,values:{'2565':'','2566':'','2567':'84','2568':'69.49','2569':'36.78'}},
      {id:'h2-rm-5',no:'5',name:'อัตราการทบทวนบริการด้าน Non clinic ระดับ 1-3',target:'ร้อยละ 100',unit:'ร้อยละ',responsible:'ติงลี่',linkStrat:null,values:{'2565':'','2566':'','2567':'60','2568':'47.61','2569':'6.66'}},
      {id:'h2-rm-6',no:'6',name:'อัตราการทบทวนอุบัติการณ์ ระดับ E–I และอุบัติการณ์ระดับ 4-5',target:'ร้อยละ 100',unit:'ร้อยละ',responsible:'ติงลี่',linkStrat:null,values:{'2565':'100','2566':'100','2567':'100','2568':'100','2569':''}},
      {id:'h2-rm-7',no:'7',name:'จำนวนอุบัติการณ์ E–I ที่เกิดซ้ำจากสาเหตุเดิม/ใหม่',target:'0 ครั้ง',unit:'ครั้ง',responsible:'ติงลี่',linkStrat:null,values:{'2565':'0','2566':'1','2567':'0','2568':'0','2569':'0'}},
      {id:'h2-rm-8',no:'8',name:'จำนวนข้อร้องเรียน social media ที่ส่งผลต่อภาพลักษณ์องค์กร (ระดับ 3 ขึ้นไป)',target:'0 เรื่อง',unit:'เรื่อง',responsible:'ติงลี่',linkStrat:null,values:{'2565':'1','2566':'1','2567':'0','2568':'0','2569':'0'}},
    ]},
    {id:'NSO',name:'NSO การพยาบาล',responsible:'หัวหน้าพยาบาล',indicators:[
      {id:'h2-nso-1',no:'1',name:'อัตราการเกิด Pressure Injury ระดับ 2 ขึ้นไป',target:'< 1 ต่อ 1,000 วันนอน',unit:'ต่อ 1,000 วัน',responsible:'NSO',linkStrat:null,values:{'2565':'','2566':'','2567':'','2568':'','2569':''}},
      {id:'h2-nso-2',no:'2',name:'อัตราการผูกยึดร่างกายผู้ป่วย',target:'ลดลง',unit:'ร้อยละ',responsible:'NSO',linkStrat:null,values:{'2565':'','2566':'','2567':'','2568':'','2569':''}},
    ]},
    {id:'MSO',name:'MSO บริการทางการแพทย์',responsible:'แพทย์ / QMR',indicators:[
      {id:'h2-mso-1',no:'1',name:'อัตราผู้ป่วยกลับมา Readmit ภายใน 28 วัน',target:'< ร้อยละ 5',unit:'ร้อยละ',responsible:'แพทย์',linkStrat:null,values:{'2565':'','2566':'','2567':'','2568':'','2569':''}},
    ]},
    {id:'ENV',name:'ENV สิ่งแวดล้อม',responsible:'สมจิตร',indicators:[
      {id:'h2-env-1',no:'1',name:'ผลการวิเคราะห์คุณภาพน้ำเสีย (13 Parameters)',target:'ผ่านเกณฑ์',unit:'-',responsible:'สมจิตร',linkStrat:'i024',values:{'2565':'ผ่าน','2566':'ผ่าน','2567':'ไม่ผ่าน','2568':'ไม่ผ่าน','2569':''}},
    ]},
    {id:'IC',name:'IC การป้องกันการติดเชื้อ',responsible:'IC nurse',indicators:[
      {id:'h2-ic-1',no:'1',name:'อัตราการติดเชื้อ BSI ผู้ป่วย ICU',target:'< 1 ต่อ 1,000 ventilator days',unit:'ต่อ 1,000',responsible:'IC',linkStrat:'i010',values:{'2565':'0.8','2566':'0.5','2567':'0.3','2568':'','2569':''}},
      {id:'h2-ic-2',no:'2',name:'อัตราการปฏิบัติ Hand Hygiene',target:'> ร้อยละ 90',unit:'ร้อยละ',responsible:'IC',linkStrat:'i011',values:{'2565':'88.0','2566':'91.2','2567':'93.5','2568':'','2569':''}},
    ]},
    {id:'IM',name:'IM เวชระเบียน',responsible:'IM',indicators:[
      {id:'h2-im-1',no:'1',name:'ร้อยละความครบถ้วนของการบันทึกเวชระเบียน',target:'> ร้อยละ 90',unit:'ร้อยละ',responsible:'IM',linkStrat:null,values:{'2565':'','2566':'','2567':'','2568':'','2569':''}},
    ]},
    {id:'PTC',name:'PTC เภสัชกรรม',responsible:'เภสัชกร',indicators:[
      {id:'h2-ptc-1',no:'1',name:'อัตรา Medication Error ระดับ C ขึ้นไป',target:'< 0.01 ต่อ 1,000 วันนอน',unit:'ต่อ 1,000',responsible:'เภสัชกร',linkStrat:'i031',values:{'2565':'0.005','2566':'0.003','2567':'0.002','2568':'','2569':''}},
    ]},
    {id:'LAB',name:'LAB+X-ray ห้องปฏิบัติการ',responsible:'LAB',indicators:[
      {id:'h2-lab-1',no:'1',name:'ร้อยละผลการทดสอบ proficiency testing ผ่านเกณฑ์',target:'> ร้อยละ 80',unit:'ร้อยละ',responsible:'LAB',linkStrat:null,values:{'2565':'','2566':'','2567':'','2568':'','2569':''}},
    ]},
    {id:'EPI',name:'เฝ้าระวังโรค',responsible:'งานระบาด',indicators:[
      {id:'h2-epi-1',no:'1',name:'อัตราป่วยโรคไข้เลือดออก (ต่อ 100,000 ประชากร)',target:'< 50 ต่อ 100,000',unit:'ต่อ 100,000',responsible:'ระบาด',linkStrat:'i029',values:{'2565':'42','2566':'35','2567':'28','2568':'','2569':''}},
    ]},
    {id:'COM',name:'การทำงานกับชุมชน',responsible:'ชุมชน/อสม.',indicators:[
      {id:'h2-com-1',no:'1',name:'จำนวน อสม. ที่ผ่านการอบรมเชิงปฏิบัติการ',target:'> 200 คน',unit:'คน',responsible:'ชุมชน',linkStrat:'i036',values:{'2565':'180','2566':'210','2567':'220','2568':'','2569':''}},
    ]},
  ]};
}
function getDefaultP4() {
  return {sections:[
    {id:'4-1',name:'4-1 ผลลัพธ์ด้านความปลอดภัย',responsible:'RM',indicators:[
      {id:'h4-1-1',no:'1',name:'อัตราตายมารดา (ต่อ 100,000 การคลอดมีชีพ)',target:'0',unit:'ต่อ 100,000',responsible:'OB',linkStrat:'i033',values:{'2565':'0','2566':'0','2567':'0','2568':'','2569':''}},
      {id:'h4-1-2',no:'2',name:'อัตราทารกแรกเกิดน้ำหนักน้อยกว่า 2,500 กรัม',target:'< ร้อยละ 7',unit:'ร้อยละ',responsible:'OB',linkStrat:'i034',values:{'2565':'6.5','2566':'6.2','2567':'5.8','2568':'','2569':''}},
    ]},
    {id:'4-2',name:'4-2 ผลลัพธ์ด้านคุณภาพ',responsible:'QMR',indicators:[
      {id:'h4-2-1',no:'1',name:'ผลการประเมิน HA ระดับ สรพ.',target:'ผ่านการรับรอง',unit:'-',responsible:'QMR',linkStrat:'i012',values:{'2565':'HA2','2566':'HA2','2567':'HA3','2568':'','2569':''}},
      {id:'h4-2-2',no:'2',name:'อัตราผู้ป่วยเบาหวานควบคุมระดับน้ำตาลได้',target:'> ร้อยละ 40',unit:'ร้อยละ',responsible:'DM Nurse',linkStrat:'i005',values:{'2565':'38.2','2566':'41.5','2567':'43.0','2568':'','2569':''}},
      {id:'h4-2-3',no:'3',name:'อัตราผู้ป่วยความดันโลหิตสูงควบคุมได้',target:'> ร้อยละ 50',unit:'ร้อยละ',responsible:'HT Nurse',linkStrat:'i006',values:{'2565':'48.0','2566':'52.3','2567':'54.1','2568':'','2569':''}},
    ]},
    {id:'4-3',name:'4-3 ผลลัพธ์ด้านประสิทธิภาพ',responsible:'การเงิน',indicators:[
      {id:'h4-3-1',no:'1',name:'อัตราส่วนรายได้ต่อรายจ่าย',target:'> 1',unit:'อัตราส่วน',responsible:'การเงิน',linkStrat:'i022',values:{'2565':'1.05','2566':'1.02','2567':'0.98','2568':'','2569':''}},
      {id:'h4-3-2',no:'2',name:'อัตราการส่งต่อผู้ป่วยฉุกเฉินภายใน 30 นาที',target:'> ร้อยละ 90',unit:'ร้อยละ',responsible:'ER',linkStrat:'i003',values:{'2565':'92.0','2566':'93.1','2567':'94.5','2568':'','2569':''}},
    ]},
  ]};
}

/* ================================================================
   BOOTSTRAP
   ================================================================ */
async function init() {
  await checkSession();
  await loadKD();
  renderAll();
  // show topbar logo after load
  document.getElementById('topbarLogo').style.display='block';
}
init();
</script>
</body>
</html>
