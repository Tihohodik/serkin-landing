<?php
// Serkin — закрытый учёт расходов. PHP 8.2. Пользователи с самостоятельной установкой пароля (invite/activate).
session_start();
$GUARD = "<?php http_response_code(403); die('Forbidden'); ?>\n";
$AUTH_FILE = __DIR__ . '/auth.php';
$DATA_FILE = __DIR__ . '/store.php';

function read_json($f){ if(!file_exists($f)) return null; $c=(string)file_get_contents($f); $p=strpos($c,"\n"); if($p!==false)$c=substr($c,$p+1); $d=json_decode($c,true); return is_array($d)?$d:null; }
function write_json($f,$d){ global $GUARD; $r=@file_put_contents($f, $GUARD.json_encode($d, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX); return $r!==false; }
function tok(){ return bin2hex(random_bytes(8)); }

// --- Генерация рекламных текстов через Claude API ---
function claude_ads($key, $model, $c){
  $name = trim((string)($c['name'] ?? ''));
  if ($name === '') return ['err'=>'нет модели авто'];
  $facts = "Модель: $name\n";
  $map = ['country'=>'Страна вывоза','sale'=>'Цена под ключ, ₽','note'=>'Заметка/контекст'];
  foreach ($map as $k=>$lbl){ if (!empty($c[$k])) $facts .= "$lbl: " . trim((string)$c[$k]) . "\n"; }
  $sys = "Ты — маркетолог автоподбора под ключ бренда «Евгений Серкин» (Иркутск). Пишешь тёплые, человечные посты о конкретном привезённом авто.\n".
    "Тон: «клуб, а не магазин» — клиенты приходят по рекомендации и становятся друзьями. Без канцелярита и агрессивных продаж, по-доброму и с заботой, от первого лица (я подобрал, я привёз).\n".
    "Услуга: подбор и доставка авто из-за границы под ключ (выбор → выкуп → доставка → растаможка → утильсбор → пригон к дому). Упор — Китай и Америка.\n".
    "Слоган бренда, который можно мягко обыгрывать: «Подбираю · проверяю · доставляю · вручаю… скучаю…».\n".
    "СТРОГИЕ ПРАВИЛА: не выдумывай характеристики, которых нет в данных; никогда не упоминай себестоимость, наценку или прибыль; если цена не указана — пиши «цена под ключ — по запросу». Призыв — написать Евгению в личку/Telegram.\n".
    "Верни СТРОГО JSON без markdown-обёрток и без текста вокруг: {\"telegram\":\"...\",\"vk\":\"...\",\"instagram\":\"...\"}.\n".
    "telegram: 500–800 знаков, уместные эмодзи, короткие абзацы, в конце призыв и 3–5 хэштегов.\n".
    "vk: 600–1000 знаков, чуть спокойнее, призыв и 3–5 хэштегов.\n".
    "instagram: цепляющий кэпшн 400–700 знаков, эмодзи, 5–8 хэштегов; без ссылок (в Instagram ссылки в тексте не кликаются).\n".
    "Пиши на русском.";
  $usr = "Данные автомобиля:\n$facts\nСгенерируй три рекламных поста об этом авто для Telegram, VK и Instagram.";
  $payload = json_encode([
    'model'=>$model, 'max_tokens'=>2000, 'system'=>$sys,
    'messages'=>[['role'=>'user','content'=>$usr]],
  ], JSON_UNESCAPED_UNICODE);
  if (!function_exists('curl_init')) return ['err'=>'на хостинге нет curl'];
  $ch = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60,
    CURLOPT_HTTPHEADER=>['content-type: application/json','x-api-key: '.$key,'anthropic-version: 2023-06-01'],
    CURLOPT_POSTFIELDS=>$payload,
  ]);
  $res = curl_exec($ch); $cerr = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($res === false) return ['err'=>'сеть: '.($cerr ?: 'нет ответа (хостинг может блокировать исходящие)')];
  if ($code !== 200) { $j = json_decode($res, true); $m = $j['error']['message'] ?? substr(strip_tags($res), 0, 180); return ['err'=>"API $code: $m"]; }
  $j = json_decode($res, true);
  $raw = $j['content'][0]['text'] ?? '';
  $clean = trim(preg_replace('/^```[a-z]*\s*|\s*```$/m', '', trim($raw)));
  $ads = json_decode($clean, true);
  if (!is_array($ads)) return ['ads'=>['telegram'=>$raw,'vk'=>'','instagram'=>''], 'err'=>'модель вернула не-JSON (текст в Telegram)'];
  return ['ads'=>['telegram'=>(string)($ads['telegram']??''),'vk'=>(string)($ads['vk']??''),'instagram'=>(string)($ads['instagram']??'')]];
}

$auth = read_json($AUTH_FILE);
if ($auth && isset($auth['hash']) && !isset($auth['users'])) { // миграция старого одиночного пароля
  $auth = ['users'=>[['login'=>'admin','name'=>'Администратор','hash'=>$auth['hash'],'role'=>'admin','active'=>true,'invite'=>null]]];
  write_json($AUTH_FILE, $auth);
}
$users = $auth['users'] ?? [];
$idx_by_login = function($login) use (&$users){ foreach($users as $i=>$u){ if(isset($u['login'])&&mb_strtolower($u['login'])===mb_strtolower(trim($login))) return $i; } return -1; };
$idx_by_invite = function($t) use (&$users){ if(!$t) return -1; foreach($users as $i=>$u){ if(!empty($u['invite'])&&hash_equals((string)$u['invite'],(string)$t)) return $i; } return -1; };

// ---------- API ----------
if (isset($_GET['action'])) {
  header('Content-Type: application/json; charset=utf-8');
  $a=$_GET['action']; $in=json_decode((string)file_get_contents('php://input'),true)?:[];

  // Первый администратор задаёт СВОЙ пароль сам
  if ($a==='setup') {
    if (count($users)>0){ echo json_encode(['ok'=>false,'err'=>'already']); exit; }
    $login=trim((string)($in['login']??'')); $name=trim((string)($in['name']??'')); $pw=(string)($in['password']??'');
    if($login===''||mb_strlen($pw)<6){ echo json_encode(['ok'=>false,'err'=>'invalid']); exit; }
    $u=['login'=>$login,'name'=>($name?:$login),'hash'=>password_hash($pw,PASSWORD_DEFAULT),'role'=>'admin','active'=>true,'invite'=>null];
    if(!write_json($AUTH_FILE,['users'=>[$u]])){ echo json_encode(['ok'=>false,'err'=>'write']); exit; }
    $_SESSION['user']=['login'=>$u['login'],'name'=>$u['name'],'role'=>'admin'];
    echo json_encode(['ok'=>true]); exit;
  }
  // Информация по приглашению (публично, секрет = токен)
  if ($a==='invite_info') {
    $i=$idx_by_invite($in['token']??''); if($i<0){ echo json_encode(['ok'=>false]); exit; }
    echo json_encode(['ok'=>true,'login'=>$users[$i]['login'],'name'=>$users[$i]['name']]); exit;
  }
  // Пользователь сам задаёт пароль по приглашению
  if ($a==='activate') {
    $i=$idx_by_invite($in['token']??''); $pw=(string)($in['password']??'');
    if($i<0){ echo json_encode(['ok'=>false,'err'=>'badtoken']); exit; }
    if(mb_strlen($pw)<6){ echo json_encode(['ok'=>false,'err'=>'short']); exit; }
    $users[$i]['hash']=password_hash($pw,PASSWORD_DEFAULT); $users[$i]['invite']=null; $users[$i]['active']=true;
    if(!write_json($AUTH_FILE,['users'=>$users])){ echo json_encode(['ok'=>false,'err'=>'write']); exit; }
    $_SESSION['user']=['login'=>$users[$i]['login'],'name'=>$users[$i]['name'],'role'=>$users[$i]['role']];
    echo json_encode(['ok'=>true]); exit;
  }
  if ($a==='login') {
    $i=$idx_by_login($in['login']??''); $pw=(string)($in['password']??'');
    if($i<0){ echo json_encode(['ok'=>false]); exit; }
    if(empty($users[$i]['hash'])){ echo json_encode(['ok'=>false,'err'=>'pending']); exit; }
    if(($users[$i]['active']??true) && password_verify($pw,$users[$i]['hash'])){ $_SESSION['user']=['login'=>$users[$i]['login'],'name'=>$users[$i]['name'],'role'=>$users[$i]['role']]; echo json_encode(['ok'=>true]); }
    else echo json_encode(['ok'=>false]);
    exit;
  }
  if ($a==='logout'){ $_SESSION=[]; session_destroy(); echo json_encode(['ok'=>true]); exit; }

  if (empty($_SESSION['user'])){ http_response_code(401); echo json_encode(['ok'=>false,'err'=>'auth']); exit; }
  $me=$_SESSION['user'];

  if ($a==='load'){ echo json_encode(['ok'=>true,'data'=>(read_json($DATA_FILE)?:['cars'=>[],'expenses'=>[]])]); exit; }
  if ($a==='save'){ $ok=write_json($DATA_FILE,['cars'=>($in['cars']??[]),'expenses'=>($in['expenses']??[])]); echo json_encode(['ok'=>$ok,'err'=>$ok?null:'write']); exit; }
  if ($a==='gen_ads'){
    $cfgf=__DIR__.'/aiconfig.php';
    if(!file_exists($cfgf)){ echo json_encode(['ok'=>false,'err'=>'nokey']); exit; }
    if(!defined('UCHET')) define('UCHET',1);
    $cfg=@include $cfgf; $key=is_array($cfg)?($cfg['key']??''):''; $model=is_array($cfg)?($cfg['model']??'claude-haiku-4-5'):'claude-haiku-4-5';
    if(!$key){ echo json_encode(['ok'=>false,'err'=>'nokey']); exit; }
    $r=claude_ads($key,$model,($in['car']??[]));
    if(isset($r['ads'])){ echo json_encode(['ok'=>true,'ads'=>$r['ads'],'note'=>($r['err']??null)]); exit; }
    echo json_encode(['ok'=>false,'err'=>'api','detail'=>($r['err']??'')]); exit;
  }
  if ($a==='photo_up'){
    $d=(string)($in['data']??'');
    if(!preg_match('#^data:image/(jpe?g|png|webp);base64,#i',$d)){ echo json_encode(['ok'=>false,'err'=>'формат не картинка']); exit; }
    $bin=base64_decode(substr($d,strpos($d,',')+1));
    if($bin===false){ echo json_encode(['ok'=>false,'err'=>'битый файл']); exit; }
    if(strlen($bin)>6*1024*1024){ echo json_encode(['ok'=>false,'err'=>'файл больше 6 МБ']); exit; }
    $dir=__DIR__.'/uploads'; if(!is_dir($dir)) @mkdir($dir,0755,true);
    if(!is_dir($dir)){ echo json_encode(['ok'=>false,'err'=>'нет папки uploads']); exit; }
    $fn='car_'.bin2hex(random_bytes(6)).'.jpg';
    if(@file_put_contents($dir.'/'.$fn,$bin)===false){ echo json_encode(['ok'=>false,'err'=>'не записалось']); exit; }
    echo json_encode(['ok'=>true,'url'=>'uploads/'.$fn]); exit;
  }

  // --- только админ ---
  if ($me['role']!=='admin'){ http_response_code(403); echo json_encode(['ok'=>false,'err'=>'forbidden']); exit; }

  if ($a==='users_list'){ echo json_encode(['ok'=>true,'users'=>array_map(fn($u)=>['login'=>$u['login'],'name'=>$u['name'],'role'=>$u['role'],'pending'=>empty($u['hash']),'invite'=>($u['invite']??null)],$users)]); exit; }
  if ($a==='user_add'){
    $login=trim((string)($in['login']??'')); $name=trim((string)($in['name']??'')); $role=(($in['role']??'user')==='admin')?'admin':'user';
    if($login===''){ echo json_encode(['ok'=>false,'err'=>'invalid']); exit; }
    if($idx_by_login($login)>=0){ echo json_encode(['ok'=>false,'err'=>'exists']); exit; }
    $t=tok();
    $users[]=['login'=>$login,'name'=>($name?:$login),'hash'=>null,'role'=>$role,'active'=>true,'invite'=>$t];
    if(!write_json($AUTH_FILE,['users'=>$users])){ echo json_encode(['ok'=>false,'err'=>'write']); exit; }
    echo json_encode(['ok'=>true,'invite'=>$t]); exit;
  }
  if ($a==='user_reset'){
    $i=$idx_by_login($in['login']??'');
    if($i<0){ echo json_encode(['ok'=>false,'err'=>'nouser']); exit; }
    if(mb_strtolower($users[$i]['login'])===mb_strtolower($me['login'])){ echo json_encode(['ok'=>false,'err'=>'self']); exit; }
    $t=tok(); $users[$i]['hash']=null; $users[$i]['invite']=$t;
    if(!write_json($AUTH_FILE,['users'=>$users])){ echo json_encode(['ok'=>false,'err'=>'write']); exit; }
    echo json_encode(['ok'=>true,'invite'=>$t]); exit;
  }
  if ($a==='user_del'){
    $i=$idx_by_login($in['login']??'');
    if($i<0){ echo json_encode(['ok'=>false,'err'=>'nouser']); exit; }
    if(mb_strtolower($users[$i]['login'])===mb_strtolower($me['login'])){ echo json_encode(['ok'=>false,'err'=>'self']); exit; }
    $admins=array_filter($users,fn($u)=>$u['role']==='admin'&&!empty($u['hash']));
    if($users[$i]['role']==='admin' && count($admins)<=1){ echo json_encode(['ok'=>false,'err'=>'lastadmin']); exit; }
    array_splice($users,$i,1);
    if(!write_json($AUTH_FILE,['users'=>$users])){ echo json_encode(['ok'=>false,'err'=>'write']); exit; }
    echo json_encode(['ok'=>true]); exit;
  }
  echo json_encode(['ok'=>false]); exit;
}

$invite = $_GET['invite'] ?? '';
$state = $invite!=='' ? 'activate' : (count($users)===0 ? 'setup' : (empty($_SESSION['user']) ? 'login' : 'app'));
$isAdmin = !empty($_SESSION['user']) && $_SESSION['user']['role']==='admin';
$meName = $_SESSION['user']['name'] ?? '';
?><!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Serkin · Учёт</title>
<style>
:root{--bg:#F4ECE0;--card:#FFFCF6;--ink:#2B2420;--muted:#6E6157;--faint:#9C8C7C;--line:#E6DAC8;--accent:#D0612C;--good:#2f8f57;--bad:#c0432c;--sans:"Segoe UI",system-ui,-apple-system,Arial,sans-serif}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--sans);font-size:15px}
.wrap{width:min(1100px,94vw);margin:0 auto;padding:24px 0 80px}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}
.brand{font-weight:800}.brand span{color:var(--accent)}
button{font-family:inherit;cursor:pointer;border:0;border-radius:10px;padding:10px 16px;font-weight:650;font-size:14px}
.btn{background:var(--accent);color:#fff}.btn:hover{filter:brightness(1.06)}
.btn-ghost{background:transparent;border:1px solid var(--line);color:var(--ink)}
.btn-sm{padding:6px 12px;font-size:13px}
.tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.tab{background:var(--card);border:1px solid var(--line);color:var(--muted);border-radius:20px;padding:8px 16px;font-weight:600}
.tab.active{background:var(--ink);color:var(--bg);border-color:var(--ink)}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin-bottom:14px}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:14px}
.stat{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.stat b{display:block;font-size:24px;font-variant-numeric:tabular-nums;letter-spacing:-.02em;margin-top:4px}
.stat span{font-size:12.5px;color:var(--muted)}
.stat.good b{color:var(--good)}.stat.bad b{color:var(--bad)}.stat.acc b{color:var(--accent)}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--line);vertical-align:middle}
th{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--faint);font-weight:700}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
.tbl-wrap{overflow-x:auto}
input,select{font-family:inherit;font-size:14px;padding:9px 10px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);width:100%}
input:focus,select:focus{outline:2px solid color-mix(in srgb,var(--accent) 40%,transparent);border-color:var(--accent)}
.frm{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;align-items:end}
.frm label{font-size:12px;color:var(--muted);display:block;margin-bottom:4px}
.profit-pos{color:var(--good);font-weight:700}.profit-neg{color:var(--bad);font-weight:700}
.del{background:transparent;color:var(--faint);border:0;font-size:18px;padding:2px 8px}.del:hover{color:var(--bad)}
.badge{font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px}
.badge.admin{background:color-mix(in srgb,var(--accent) 18%,transparent);color:var(--accent)}
.badge.user{background:var(--line);color:var(--muted)}
.badge.pend{background:#fdeede;color:#b06a1a}
.center{min-height:100vh;display:grid;place-items:center;padding:20px}
.login-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:28px;width:min(400px,92vw);text-align:center}
.login-card h2{margin:0 0 6px}.login-card p{color:var(--muted);margin:0 0 18px;font-size:14px}
.login-card input{margin-bottom:12px;text-align:left}
.err{color:var(--bad);font-size:13px;min-height:18px}
.hide{display:none}.save-hint{font-size:12.5px;color:var(--faint)}.muted{color:var(--muted)}
.invbox{background:#fbf3e7;border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin-top:12px;font-size:13px}
.invbox code{background:#fff;border:1px solid var(--line);border-radius:6px;padding:6px 8px;display:block;margin:6px 0;word-break:break-all;font-family:monospace}
@media(max-width:760px){.grid{grid-template-columns:repeat(2,1fr)}.frm{grid-template-columns:repeat(2,1fr)}}
</style></head><body>
<?php if ($state==='setup' || $state==='login' || $state==='activate'): ?>
<div class="center"><div class="login-card">
  <h2>Serkin · <span style="color:var(--accent)">Учёт</span></h2>
  <?php if ($state==='setup'): ?>
    <p>Первый вход. Создайте администратора: логин, имя и пароль (мин. 6 символов).</p>
    <input id="login" placeholder="Логин (напр. evgeny)" autocomplete="username">
    <input id="name" placeholder="Имя (напр. Евгений)">
    <input id="pw" type="password" placeholder="Пароль" autocomplete="new-password">
    <input id="pw2" type="password" placeholder="Повторите пароль" autocomplete="new-password">
    <div class="err" id="err"></div>
    <button class="btn" style="width:100%" onclick="doSetup()">Создать администратора</button>
  <?php elseif ($state==='activate'): ?>
    <p id="actHi">Установка пароля…</p>
    <input id="pw" type="password" placeholder="Придумайте пароль" autocomplete="new-password">
    <input id="pw2" type="password" placeholder="Повторите пароль" autocomplete="new-password">
    <div class="err" id="err"></div>
    <button class="btn" style="width:100%" onclick="doActivate()">Сохранить пароль и войти</button>
    <script>const INVITE=<?=json_encode($invite)?>;</script>
  <?php else: ?>
    <p>Вход в учёт расходов.</p>
    <input id="login" placeholder="Логин" autocomplete="username">
    <input id="pw" type="password" placeholder="Пароль" autocomplete="current-password" onkeydown="if(event.key==='Enter')doLogin()">
    <div class="err" id="err"></div>
    <button class="btn" style="width:100%" onclick="doLogin()">Войти</button>
  <?php endif; ?>
</div></div>
<script>
async function api(a,b){const r=await fetch('?action='+a,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b||{})});return r.json();}
async function doSetup(){const l=login.value.trim(),n=name.value.trim(),p=pw.value,p2=pw2.value;err.textContent='';if(!l){err.textContent='Введите логин';return;}if(p.length<6){err.textContent='Пароль минимум 6 символов';return;}if(p!==p2){err.textContent='Пароли не совпадают';return;}const r=await api('setup',{login:l,name:n,password:p});if(r.ok)location.href=location.pathname;else err.textContent='Ошибка: '+(r.err||'');}
async function doLogin(){err.textContent='';const r=await api('login',{login:login.value.trim(),password:pw.value});if(r.ok)location.reload();else err.textContent=r.err==='pending'?'Аккаунт не активирован — откройте ссылку-приглашение':'Неверный логин или пароль';}
async function doActivate(){err.textContent='';const p=pw.value,p2=pw2.value;if(p.length<6){err.textContent='Пароль минимум 6 символов';return;}if(p!==p2){err.textContent='Пароли не совпадают';return;}const r=await api('activate',{token:INVITE,password:p});if(r.ok)location.href=location.pathname;else err.textContent=r.err==='badtoken'?'Ссылка недействительна или уже использована':'Ошибка';}
<?php if($state==='activate'): ?>(async()=>{const r=await api('invite_info',{token:INVITE});const hi=document.getElementById('actHi');if(r.ok)hi.textContent='Здравствуйте, '+r.name+'! Придумайте пароль для входа (мин. 6 символов).';else{hi.textContent='Ссылка недействительна или уже использована.';document.getElementById('pw').disabled=true;document.getElementById('pw2').disabled=true;}})();<?php endif; ?>
</script>
<?php else: ?>
<div class="wrap">
  <div class="top">
    <div class="brand">Serkin · <span>Учёт расходов</span></div>
    <div style="display:flex;gap:10px;align-items:center">
      <span class="save-hint" id="saveHint">Сохранено ✓</span>
      <span class="muted" style="font-size:13px"><?=htmlspecialchars($meName)?></span>
      <button class="btn-ghost btn-sm" onclick="logout()">Выйти</button>
    </div>
  </div>
  <div class="grid" id="summary"></div>

  <div id="adModal" style="position:fixed;inset:0;background:rgba(30,20,12,.5);z-index:50;display:none;place-items:center;padding:16px">
    <div class="card" style="width:min(700px,96vw);max-height:92vh;overflow:auto;margin:0">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px">
        <b id="ad_title">Объявление</b>
        <button class="del" onclick="closeAds()">✕</button>
      </div>
      <div class="tabs" style="margin-bottom:10px">
        <button class="tab active" data-at="telegram" onclick="adTab('telegram')">Telegram</button>
        <button class="tab" data-at="vk" onclick="adTab('vk')">VK</button>
        <button class="tab" data-at="instagram" onclick="adTab('instagram')">Instagram</button>
      </div>
      <div id="ad_loading" class="muted hide" style="padding:24px 0;text-align:center">✨ Генерирую тексты… (5–15 сек)</div>
      <textarea id="ad_text" style="width:100%;min-height:250px;font-family:inherit;font-size:14px;line-height:1.5;padding:11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);resize:vertical"></textarea>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:center">
        <button class="btn btn-sm" onclick="copyAd()">Скопировать</button>
        <button class="btn-ghost btn-sm" onclick="regenAds()">✨ Заново</button>
        <button class="btn-ghost btn-sm" onclick="saveAds()">Сохранить в карточке</button>
        <span class="save-hint" id="ad_hint"></span>
      </div>
    </div>
  </div>
  <div class="tabs">
    <button class="tab active" data-t="cars" onclick="tab('cars')">🚗 Машины</button>
    <button class="tab" data-t="exp" onclick="tab('exp')">📋 Общие расходы</button>
    <?php if($isAdmin): ?><button class="tab" data-t="users" onclick="tab('users')">👤 Пользователи</button><button class="tab" data-t="settings" onclick="tab('settings')">⚙️ Настройки</button><?php endif; ?>
  </div>

  <div id="pane-cars">
    <div class="card"><div class="frm">
      <div><label>Модель</label><input id="c_name" placeholder="напр. Toyota bZ3X"></div>
      <div><label>Страна</label><select id="c_country"><option>Китай</option><option>США</option><option>Корея</option><option>Япония</option><option>Другое</option></select></div>
      <div><label>Дата</label><input id="c_date" type="date"></div>
      <div><label>Закупка, ₽</label><input id="c_purchase" type="number" min="0" placeholder="0"></div>
      <div><label>Логистика, ₽</label><input id="c_delivery" type="number" min="0" placeholder="0"></div>
      <div><label>Растаможка, ₽</label><input id="c_customs" type="number" min="0" placeholder="0"></div>
      <div><label>Утиль, ₽</label><input id="c_util" type="number" min="0" placeholder="0"></div>
      <div><label>Прочее, ₽</label><input id="c_other" type="number" min="0" placeholder="0"></div>
      <div><label>Продажа (под ключ), ₽</label><input id="c_sale" type="number" min="0" placeholder="0"></div>
      <div style="grid-column:span 2"><label>Заметка</label><input id="c_note" placeholder="клиент, город, статус…"></div>
      <div style="grid-column:span 2"><label>Фото авто (превью + для рекламы)</label><input id="c_photo" type="file" accept="image/*" onchange="pickPhoto(this)"><img id="c_photo_prev" alt="" style="display:none;max-height:52px;border-radius:6px;margin-top:6px"></div>
      <div><button class="btn" id="carBtn" style="width:100%" onclick="addCar()">Добавить авто</button></div>
    </div></div>
    <div class="card tbl-wrap"><table><thead><tr>
      <th>Модель</th><th>Страна</th><th>Дата</th><th class="num">Закупка</th><th class="num">Логист.</th><th class="num">Растам.</th><th class="num">Утиль</th><th class="num">Прочее</th><th class="num">Затраты</th><th class="num">Продажа</th><th class="num">Прибыль</th><th></th>
    </tr></thead><tbody id="cars-body"></tbody></table></div>
  </div>

  <div id="pane-exp" class="hide">
    <div class="card"><div class="frm">
      <div><label>Дата</label><input id="e_date" type="date"></div>
      <div><label>Категория</label><select id="e_cat"></select></div>
      <div><label>Сумма, ₽</label><input id="e_amount" type="number" min="0" placeholder="0"></div>
      <div><label>Комментарий</label><input id="e_note" placeholder="за что"></div>
      <div><button class="btn" style="width:100%" onclick="addExp()">Добавить расход</button></div>
    </div></div>
    <div class="card tbl-wrap"><table><thead><tr><th>Дата</th><th>Категория</th><th class="num">Сумма</th><th>Комментарий</th><th></th></tr></thead><tbody id="exp-body"></tbody></table></div>
  </div>

  <?php if($isAdmin): ?>
  <div id="pane-users" class="hide">
    <div class="card"><div class="frm" style="grid-template-columns:repeat(3,1fr) auto">
      <div><label>Логин</label><input id="u_login" placeholder="ivan"></div>
      <div><label>Имя</label><input id="u_name" placeholder="Иван"></div>
      <div><label>Роль</label><select id="u_role"><option value="user">Пользователь</option><option value="admin">Администратор</option></select></div>
      <div><button class="btn" onclick="addUser()">Создать</button></div>
    </div>
    <div class="err" id="u_err" style="margin-top:8px"></div>
    <div class="invbox hide" id="invbox"></div>
    <p class="muted" style="font-size:13px;margin-bottom:0">Пароль пользователь задаёт сам по ссылке-приглашению — вы его не знаете.</p>
    </div>
    <div class="card tbl-wrap"><table><thead><tr><th>Имя</th><th>Логин</th><th>Роль</th><th>Статус</th><th></th></tr></thead><tbody id="users-body"></tbody></table></div>
  </div>

  <div id="pane-settings" class="hide">
    <div class="card">
      <h3 style="margin:0 0 12px;font-size:16px">Категории расходов</h3>
      <div class="frm" style="grid-template-columns:1fr auto">
        <div><label>Новая категория</label><input id="cat_new" placeholder="напр. Реклама / площадка" onkeydown="if(event.key==='Enter')addCat()"></div>
        <div><button class="btn" onclick="addCat()">Добавить</button></div>
      </div>
      <div id="cats-list" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px"></div>
    </div>
    <p class="muted" style="font-size:13px">Категории используются в «Общих расходах». Изменения видят все. Позже сюда добавим и другие настройки.</p>
    <p class="muted" style="font-size:13px"><b>Забыли пароль администратора?</b> Зайдите на сервер по SSH и выполните:<br>
      <code style="background:#fff;border:1px solid var(--line);border-radius:6px;padding:6px 8px;display:inline-block;margin-top:4px;font-family:monospace">php /home/c/cg146190/public_html/uchet/reset.php ЛОГИН</code><br>
      Команда выдаст ссылку — по ней зададите новый пароль. В веб-браузере этот скрипт не работает.</p>
  </div>
  <?php endif; ?>
</div>

<script>
const IS_ADMIN=<?=$isAdmin?'true':'false'?>;
const INVBASE=location.origin+location.pathname.replace(/[^/]*$/,'');
let DATA={cars:[],expenses:[]};
const fmt=n=>((+n||0).toLocaleString('ru-RU'))+' ₽';
const uid=()=>Date.now().toString(36)+Math.random().toString(36).slice(2,6);
const esc=s=>String(s??'').replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
async function api(a,b){const r=await fetch('?action='+a,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b||{})});if(r.status===401){location.reload();return{ok:false};}return r.json();}
async function logout(){await api('logout');location.reload();}
let saveTimer=null;
function save(){document.getElementById('saveHint').textContent='Сохранение…';clearTimeout(saveTimer);saveTimer=setTimeout(async()=>{const r=await api('save',DATA);document.getElementById('saveHint').textContent=r.ok?'Сохранено ✓':'Ошибка сохранения';},400);}
function carCosts(c){return (+c.purchase||0)+(+c.delivery||0)+(+c.customs||0)+(+c.util||0)+(+c.other||0);}
function carProfit(c){return (+c.sale||0)-carCosts(c);}
function renderCars(){const b=document.getElementById('cars-body');b.innerHTML='';DATA.cars.forEach(c=>{const p=carProfit(c);const tr=document.createElement('tr');tr.innerHTML=`<td>${c.photo?`<img src="${esc(c.photo)}" alt="" style="height:30px;width:46px;object-fit:cover;border-radius:5px;vertical-align:middle;margin-right:7px">`:''}${esc(c.name)}</td><td>${esc(c.country||'')}</td><td>${esc(c.date||'')}</td><td class="num">${fmt(c.purchase)}</td><td class="num">${fmt(c.delivery)}</td><td class="num">${fmt(c.customs)}</td><td class="num">${fmt(c.util)}</td><td class="num">${fmt(c.other)}</td><td class="num">${fmt(carCosts(c))}</td><td class="num">${fmt(c.sale)}</td><td class="num ${p>=0?'profit-pos':'profit-neg'}">${fmt(p)}</td><td style="white-space:nowrap"><button class="btn-ghost btn-sm" onclick="editCar('${c.id}')">✎</button> <button class="btn-ghost btn-sm" onclick="genAds('${c.id}')">✨ Текст${c.ads&&(c.ads.telegram||c.ads.vk||c.ads.instagram)?' ✓':''}</button> <button class="del" onclick="delCar('${c.id}')">✕</button></td>`;b.appendChild(tr);});renderSummary();}
function renderExp(){const b=document.getElementById('exp-body');b.innerHTML='';DATA.expenses.forEach(e=>{const tr=document.createElement('tr');tr.innerHTML=`<td>${esc(e.date||'')}</td><td>${esc(e.cat||'')}</td><td class="num">${fmt(e.amount)}</td><td>${esc(e.note||'')}</td><td><button class="del" onclick="delExp('${e.id}')">✕</button></td>`;b.appendChild(tr);});renderSummary();}
function renderSummary(){const cp=DATA.cars.reduce((s,c)=>s+carProfit(c),0);const ex=DATA.expenses.reduce((s,e)=>s+(+e.amount||0),0);const net=cp-ex;document.getElementById('summary').innerHTML=`<div class="stat"><span>Машин в учёте</span><b>${DATA.cars.length}</b></div><div class="stat good"><span>Прибыль с машин</span><b>${fmt(cp)}</b></div><div class="stat bad"><span>Общие расходы</span><b>${fmt(ex)}</b></div><div class="stat ${net>=0?'acc':'bad'}"><span>Чистая прибыль</span><b>${fmt(net)}</b></div>`;}
let editingCarId=null, pendingPhoto=null;
function carFormData(){const g=id=>document.getElementById(id);return {name:g('c_name').value.trim(),country:g('c_country').value,date:g('c_date').value,purchase:+g('c_purchase').value||0,delivery:+g('c_delivery').value||0,customs:+g('c_customs').value||0,util:+g('c_util').value||0,other:+g('c_other').value||0,sale:+g('c_sale').value||0,note:g('c_note').value.trim()};}
function clearCarForm(){const g=id=>document.getElementById(id);['c_name','c_purchase','c_delivery','c_customs','c_util','c_other','c_sale','c_note'].forEach(i=>g(i).value='');g('c_date').value=new Date().toISOString().slice(0,10);g('c_photo').value='';pendingPhoto=null;const p=g('c_photo_prev');p.style.display='none';p.src='';}
function pickPhoto(inp){const f=inp.files&&inp.files[0];if(!f)return;const img=new Image();const rd=new FileReader();rd.onload=()=>{img.onload=()=>{const max=1200;let w=img.width,h=img.height;if(w>max||h>max){if(w>=h){h=Math.round(h*max/w);w=max;}else{w=Math.round(w*max/h);h=max;}}const cv=document.createElement('canvas');cv.width=w;cv.height=h;cv.getContext('2d').drawImage(img,0,0,w,h);pendingPhoto=cv.toDataURL('image/jpeg',0.82);const p=document.getElementById('c_photo_prev');p.src=pendingPhoto;p.style.display='block';};img.src=rd.result;};rd.readAsDataURL(f);}
async function addCar(){const g=id=>document.getElementById(id);if(!g('c_name').value.trim()){g('c_name').focus();return;}const d=carFormData();let photoUrl=null;if(pendingPhoto){const btn=g('carBtn');const t0=btn.textContent;btn.textContent='Загрузка фото…';const r=await api('photo_up',{data:pendingPhoto});btn.textContent=t0;if(r.ok)photoUrl=r.url;else{alert('Фото не загрузилось: '+(r.err||''));return;}}if(editingCarId){const c=DATA.cars.find(x=>x.id===editingCarId);if(c){Object.assign(c,d);if(photoUrl)c.photo=photoUrl;}editingCarId=null;g('carBtn').textContent='Добавить авто';}else{const car={id:uid(),...d};if(photoUrl)car.photo=photoUrl;DATA.cars.unshift(car);}clearCarForm();renderCars();save();}
function editCar(id){const c=DATA.cars.find(x=>x.id===id);if(!c)return;const el=x=>document.getElementById(x);el('c_name').value=c.name||'';el('c_country').value=c.country||'Китай';el('c_date').value=c.date||'';el('c_purchase').value=c.purchase||'';el('c_delivery').value=c.delivery||'';el('c_customs').value=c.customs||'';el('c_util').value=c.util||'';el('c_other').value=c.other||'';el('c_sale').value=c.sale||'';el('c_note').value=c.note||'';pendingPhoto=null;el('c_photo').value='';const p=el('c_photo_prev');if(c.photo){p.src=c.photo;p.style.display='block';}else{p.style.display='none';p.src='';}editingCarId=id;el('carBtn').textContent='Сохранить изменения';window.scrollTo({top:0,behavior:'smooth'});}
function delCar(id){if(!confirm('Удалить авто?'))return;DATA.cars=DATA.cars.filter(c=>c.id!==id);renderCars();save();}
function addExp(){const g=id=>document.getElementById(id);if(!(+g('e_amount').value)){g('e_amount').focus();return;}DATA.expenses.unshift({id:uid(),date:g('e_date').value,cat:g('e_cat').value,amount:+g('e_amount').value||0,note:g('e_note').value.trim()});['e_amount','e_note'].forEach(i=>g(i).value='');renderExp();save();}
function delExp(id){if(!confirm('Удалить расход?'))return;DATA.expenses=DATA.expenses.filter(e=>e.id!==id);renderExp();save();}
function tab(t){document.querySelectorAll('.tab').forEach(x=>x.classList.toggle('active',x.dataset.t===t));document.getElementById('pane-cars').classList.toggle('hide',t!=='cars');document.getElementById('pane-exp').classList.toggle('hide',t!=='exp');const pu=document.getElementById('pane-users');if(pu)pu.classList.toggle('hide',t!=='users');const ps=document.getElementById('pane-settings');if(ps)ps.classList.toggle('hide',t!=='settings');if(t==='users')loadUsers();if(t==='settings')renderCats();}
function showInvite(login,token){const box=document.getElementById('invbox');box.classList.remove('hide');const url=INVBASE+'?invite='+token;box.innerHTML='Ссылка для установки пароля для <b>'+esc(login)+'</b> (отправьте её пользователю):<code id="invurl">'+esc(url)+'</code><button class="btn btn-sm" onclick="copyInv()">Скопировать ссылку</button>';}
function copyInv(){const t=document.getElementById('invurl').textContent;navigator.clipboard.writeText(t).then(()=>{},()=>{});const b=event.target;b.textContent='Скопировано ✓';setTimeout(()=>b.textContent='Скопировать ссылку',1500);}
async function loadUsers(){const r=await api('users_list');if(!r.ok)return;const b=document.getElementById('users-body');b.innerHTML='';r.users.forEach(u=>{const tr=document.createElement('tr');const status=u.pending?'<span class="badge pend">ожидает пароль</span>':'<span class="badge user">активен</span>';const acts=(u.pending&&u.invite?`<button class="btn-ghost btn-sm" onclick="showInvite('${esc(u.login)}','${esc(u.invite)}')">Ссылка</button> `:`<button class="btn-ghost btn-sm" onclick="resetUser('${esc(u.login)}')">Сбросить пароль</button> `)+`<button class="del" onclick="delUser('${esc(u.login)}')">✕</button>`;tr.innerHTML=`<td>${esc(u.name)}</td><td>${esc(u.login)}</td><td><span class="badge ${u.role}">${u.role==='admin'?'Админ':'Пользователь'}</span></td><td>${status}</td><td>${acts}</td>`;b.appendChild(tr);});}
async function addUser(){const g=id=>document.getElementById(id);const err=document.getElementById('u_err');err.textContent='';const login=g('u_login').value.trim(),name=g('u_name').value.trim(),role=g('u_role').value;if(!login){err.textContent='Введите логин';return;}const r=await api('user_add',{login,name,role});if(r.ok){['u_login','u_name'].forEach(i=>g(i).value='');loadUsers();showInvite(login,r.invite);}else{err.textContent={exists:'Такой логин уже есть',invalid:'Проверьте логин',write:'Ошибка записи'}[r.err]||'Ошибка';}}
async function resetUser(login){if(!confirm('Сбросить пароль пользователя '+login+'? Он задаст новый по ссылке.'))return;const r=await api('user_reset',{login});if(r.ok){loadUsers();showInvite(login,r.invite);}else alert({self:'Свой пароль сбросьте через SSH (reset.php)'}[r.err]||'Ошибка');}
async function delUser(login){if(!confirm('Удалить пользователя '+login+'?'))return;const r=await api('user_del',{login});if(r.ok)loadUsers();else alert({self:'Нельзя удалить самого себя',lastadmin:'Нельзя удалить последнего админа'}[r.err]||'Ошибка');}
let adCarId=null, adData={telegram:'',vk:'',instagram:''}, adTabCur='telegram';
function openAdModal(){document.getElementById('adModal').style.display='grid';}
function closeAds(){document.getElementById('adModal').style.display='none';adCarId=null;}
function fillAd(){document.getElementById('ad_text').value=adData[adTabCur]||'';}
function adTab(t){adData[adTabCur]=document.getElementById('ad_text').value;adTabCur=t;document.querySelectorAll('#adModal .tab').forEach(x=>x.classList.toggle('active',x.dataset.at===t));fillAd();}
async function genAds(id){const c=DATA.cars.find(x=>x.id===id);if(!c)return;adCarId=id;adTabCur='telegram';document.querySelectorAll('#adModal .tab').forEach(x=>x.classList.toggle('active',x.dataset.at==='telegram'));document.getElementById('ad_title').textContent='Объявление · '+c.name;document.getElementById('ad_hint').textContent='';openAdModal();if(c.ads&&(c.ads.telegram||c.ads.vk||c.ads.instagram)){adData={telegram:c.ads.telegram||'',vk:c.ads.vk||'',instagram:c.ads.instagram||''};fillAd();}else{await doGen(c);}}
async function regenAds(){const c=DATA.cars.find(x=>x.id===adCarId);if(c)await doGen(c);}
async function doGen(c){const L=document.getElementById('ad_loading'),T=document.getElementById('ad_text');L.classList.remove('hide');T.classList.add('hide');document.getElementById('ad_hint').textContent='';const r=await api('gen_ads',{car:{name:c.name,country:c.country,sale:c.sale,note:c.note}});L.classList.add('hide');T.classList.remove('hide');if(r.ok){adData={telegram:r.ads.telegram||'',vk:r.ads.vk||'',instagram:r.ads.instagram||''};fillAd();}else{const base={nokey:'API-ключ не настроен на сервере (см. инструкцию).',api:'Нейросеть не ответила'}[r.err]||'Ошибка генерации';document.getElementById('ad_hint').textContent=base+(r.detail?(' — '+r.detail):'');}}
function copyAd(){navigator.clipboard.writeText(document.getElementById('ad_text').value).then(()=>{document.getElementById('ad_hint').textContent='Скопировано ✓';},()=>{});}
function saveAds(){adData[adTabCur]=document.getElementById('ad_text').value;const c=DATA.cars.find(x=>x.id===adCarId);if(!c)return;c.ads={telegram:adData.telegram,vk:adData.vk,instagram:adData.instagram,generated_at:new Date().toISOString()};renderCars();save();document.getElementById('ad_hint').textContent='Сохранено в карточке ✓';}
function renderCatSelect(){const sel=document.getElementById('e_cat');if(!sel)return;const cur=sel.value;sel.innerHTML='';(DATA.categories||[]).forEach(c=>{const o=document.createElement('option');o.textContent=c;sel.appendChild(o);});if(cur&&(DATA.categories||[]).includes(cur))sel.value=cur;}
function renderCats(){const box=document.getElementById('cats-list');if(!box)return;box.innerHTML='';(DATA.categories||[]).forEach((c,i)=>{const chip=document.createElement('span');chip.className='badge user';chip.style.cssText='display:inline-flex;align-items:center;gap:6px;font-size:13px;padding:6px 10px';chip.innerHTML=esc(c)+' <button class="del" style="font-size:14px;padding:0 2px" onclick="delCat('+i+')">✕</button>';box.appendChild(chip);});}
function addCat(){const inp=document.getElementById('cat_new');const v=inp.value.trim();if(!v)return;if(!DATA.categories)DATA.categories=[];if(DATA.categories.some(c=>c.toLowerCase()===v.toLowerCase())){alert('Такая категория уже есть');return;}DATA.categories.push(v);inp.value='';renderCats();renderCatSelect();save();}
function delCat(i){const c=(DATA.categories||[])[i];if(c==null)return;if(!confirm('Удалить категорию «'+c+'»?'))return;DATA.categories.splice(i,1);renderCats();renderCatSelect();save();}
(async()=>{const r=await api('load');if(r.ok){DATA=r.data;if(!DATA.cars)DATA.cars=[];if(!DATA.expenses)DATA.expenses=[];}if(!Array.isArray(DATA.categories)||!DATA.categories.length)DATA.categories=['Реклама / площадка','Домен+хостинг','Логистика','Зарплата','Комиссии','Прочее'];renderCars();renderExp();renderCatSelect();if(IS_ADMIN)renderCats();const t=new Date().toISOString().slice(0,10);document.getElementById('c_date').value=t;document.getElementById('e_date').value=t;})();
</script>
<?php endif; ?>
</body></html>
