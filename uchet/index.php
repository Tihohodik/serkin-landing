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
function ai_ads($cfg, $c){
  $name = trim((string)($c['name'] ?? ''));
  if ($name === '') return ['err'=>'нет модели авто'];
  $facts = "Модель: $name\n";
  $map = ['country'=>'Страна вывоза','sale'=>'Цена под ключ, ₽','note'=>'Заметка/контекст'];
  foreach ($map as $k=>$lbl){ if (!empty($c[$k])) $facts .= "$lbl: " . trim((string)$c[$k]) . "\n"; }
  $sys = "Ты пишешь посты за Евгения Серкина (Иркутск) — он лично подбирает и привозит авто из-за границы под ключ (выбор → выкуп → доставка → растаможка → утильсбор → пригон к дому), упор на Китай и Америку.\n".
    "ГОЛОС: живой, от первого лица, будто Евгений сам рассказывает другу про машину, которую только что пригнал. Тепло, спокойная уверенность, лёгкая гордость за работу. Клиенты приходят по рекомендации — это круг доверия, «клуб, а не магазин».\n".
    "ИЗБЕГАЙ штампов и рекламного пафоса: «воплощение надёжности», «идеальный выбор», «автомобиль вашей мечты», «не упустите», «спешите», «по доступной цене», сухих перечислений характеристик. Лучше живая деталь, честная интонация, конкретика: откуда авто, чем хорош, кому подойдёт.\n".
    "Не выдумывай характеристики, которых нет в данных. Никогда не упоминай себестоимость, наценку, прибыль. Если цена не указана — «цена под ключ по запросу». Финал — мягкий призыв написать Евгению в личку или Telegram.\n".
    "Слоган можно ненавязчиво обыграть, но не в каждом посте: «Подбираю · проверяю · доставляю · вручаю… скучаю…».\n".
    "Эмодзи — умеренно, 2–4 на пост, к месту, не в каждой строке.\n".
    "Верни СТРОГО JSON без markdown-обёрток и без текста вокруг: {\"telegram\":\"...\",\"vk\":\"...\",\"instagram\":\"...\"}.\n".
    "telegram: 450–750 знаков, короткие абзацы, в конце призыв и 3–4 хэштега.\n".
    "vk: 600–950 знаков, чуть подробнее и спокойнее, призыв и 3–4 хэштега.\n".
    "instagram: живой кэпшн 350–600 знаков, 5–7 хэштегов, без ссылок (в Instagram они не кликаются).\n".
    "Пиши по-русски, разными формулировками для каждой площадки — не копируй один текст в три.";
  $usr = "Данные автомобиля:\n$facts\nСгенерируй три рекламных поста об этом авто для Telegram, VK и Instagram.";
  if (!function_exists('curl_init')) return ['err'=>'на хостинге нет curl'];
  $provider = strtolower((string)($cfg['provider'] ?? 'anthropic'));
  $key = (string)($cfg['key'] ?? '');
  $model = (string)($cfg['model'] ?? '');
  if ($provider === 'openai') {
    $base = rtrim((string)($cfg['base'] ?? ''), '/');
    if ($base === '') return ['err'=>'не указан base для Qwen/openai-провайдера'];
    if ($model === '') $model = 'qwen-plus';
    $authScheme = (string)($cfg['auth'] ?? 'Bearer');
    $hdr = ['content-type: application/json','authorization: '.$authScheme.' '.$key];
    if (!empty($cfg['folder'])) $hdr[] = 'x-folder-id: '.$cfg['folder'];
    $payload = json_encode(['model'=>$model,'max_tokens'=>2000,'messages'=>[['role'=>'system','content'=>$sys],['role'=>'user','content'=>$usr]]], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($base.'/chat/completions');
    curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>$hdr,CURLOPT_POSTFIELDS=>$payload]);
    $res = curl_exec($ch); $cerr = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($res === false) return ['err'=>'сеть: '.($cerr ?: 'нет ответа')];
    $j = json_decode($res, true);
    if ($code !== 200) { $m = $j['error']['message'] ?? substr(strip_tags((string)$res),0,180); return ['err'=>"API $code: $m"]; }
    $raw = $j['choices'][0]['message']['content'] ?? '';
  } else {
    if ($model === '') $model = 'claude-haiku-4-5';
    $payload = json_encode(['model'=>$model,'max_tokens'=>2000,'system'=>$sys,'messages'=>[['role'=>'user','content'=>$usr]]], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>['content-type: application/json','x-api-key: '.$key,'anthropic-version: 2023-06-01'],CURLOPT_POSTFIELDS=>$payload]);
    $res = curl_exec($ch); $cerr = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($res === false) return ['err'=>'сеть: '.($cerr ?: 'нет ответа (хостинг может блокировать исходящие)')];
    $j = json_decode($res, true);
    if ($code !== 200) { $m = $j['error']['message'] ?? substr(strip_tags((string)$res),0,180); return ['err'=>"API $code: $m"]; }
    $raw = $j['content'][0]['text'] ?? '';
  }
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
  if ($a==='save'){
    $cars=$in['cars']??[];
    $store=['cars'=>$cars,'expenses'=>($in['expenses']??[])];
    if(isset($in['categories'])&&is_array($in['categories'])) $store['categories']=$in['categories'];
    $ok=write_json($DATA_FILE,$store);
    // публичный список для лендинга — только помеченные "publish", без себестоимости/прибыли
    $pub=[];
    foreach($cars as $c){ if(!empty($c['publish'])){
      $ph=(isset($c['photos'])&&is_array($c['photos']))?array_values($c['photos']):(!empty($c['photo'])?[$c['photo']]:[]);
      $ph=array_map(fn($u)=>(is_string($u)&&(strncmp($u,'/',1)===0||strncmp($u,'http',4)===0))?$u:'/uchet/'.$u, $ph);
      $pub[]=['name'=>(string)($c['name']??''),'country'=>(string)($c['country']??''),'sale'=>(int)($c['sale']??0),'note'=>(string)($c['note']??''),'photos'=>$ph];
    } }
    @file_put_contents(__DIR__.'/cars.json', json_encode(['cars'=>$pub,'updated'=>date('c')], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
    echo json_encode(['ok'=>$ok,'err'=>$ok?null:'write']); exit;
  }
  if ($a==='gen_ads'){
    $cfgf=__DIR__.'/aiconfig.php';
    if(!file_exists($cfgf)){ echo json_encode(['ok'=>false,'err'=>'nokey']); exit; }
    if(!defined('UCHET')) define('UCHET',1);
    $cfg=@include $cfgf; if(!is_array($cfg)) $cfg=[];
    if(empty($cfg['key'])){ echo json_encode(['ok'=>false,'err'=>'nokey']); exit; }
    $r=ai_ads($cfg,($in['car']??[]));
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
  if ($a==='tg_conf'){
    $cfgf=__DIR__.'/tgconfig.php';
    if(!file_exists($cfgf)){ echo json_encode(['ok'=>false,'err'=>'no config']); exit; }
    if(!defined('UCHET')) define('UCHET',1);
    $cfg=@include $cfgf; if(!is_array($cfg))$cfg=[];
    if(empty($cfg['token'])||empty($cfg['chat'])){ echo json_encode(['ok'=>false,'err'=>'no config']); exit; }
    echo json_encode(['ok'=>true,'token'=>$cfg['token'],'chat'=>$cfg['chat']]); exit;
  }
  if ($a==='tg_post'){
    $cfgf=__DIR__.'/tgconfig.php';
    if(!file_exists($cfgf)){ echo json_encode(['ok'=>false,'err'=>'Telegram-бот не настроен (нет tgconfig.php)']); exit; }
    if(!defined('UCHET')) define('UCHET',1);
    $cfg=@include $cfgf; if(!is_array($cfg))$cfg=[];
    $token=(string)($cfg['token']??''); $chat=(string)($cfg['chat']??'');
    if(!$token||!$chat){ echo json_encode(['ok'=>false,'err'=>'В tgconfig.php нет token или chat']); exit; }
    $text=trim((string)($in['text']??'')); if($text===''){ echo json_encode(['ok'=>false,'err'=>'пустой текст']); exit; }
    $base='https://'.($_SERVER['HTTP_HOST']??'serkinauto.ru');
    $urls=[];
    foreach((array)($in['photos']??[]) as $p){ $p=(string)$p; if($p==='')continue; if(strncmp($p,'http',4)===0)$urls[]=$p; else $urls[]=$base.'/uchet/'.ltrim(preg_replace('#^/uchet/#','',$p),'/'); }
    $api='https://api.telegram.org/bot'.$token.'/';
    $call=function($method,$params) use($api){ $ch=curl_init($api.$method); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,CURLOPT_POSTFIELDS=>$params]); $r=curl_exec($ch); $e=curl_error($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [json_decode($r,true),$r,$e,$code]; };
    if(count($urls)===0){ [$j,$raw,$cerr,$code]=$call('sendMessage',['chat_id'=>$chat,'text'=>$text]); }
    elseif(count($urls)===1){ [$j,$raw,$cerr,$code]=$call('sendPhoto',['chat_id'=>$chat,'photo'=>$urls[0],'caption'=>mb_substr($text,0,1024)]); }
    else { $media=[]; foreach(array_slice($urls,0,10) as $i=>$u){ $m=['type'=>'photo','media'=>$u]; if($i===0)$m['caption']=mb_substr($text,0,1024); $media[]=$m; } [$j,$raw,$cerr,$code]=$call('sendMediaGroup',['chat_id'=>$chat,'media'=>json_encode($media,JSON_UNESCAPED_UNICODE)]); }
    if(is_array($j)&&!empty($j['ok'])){ echo json_encode(['ok'=>true]); }
    elseif($raw===false||$raw===null||$raw===''){ echo json_encode(['ok'=>false,'err'=>($cerr?('сеть: '.$cerr):'нет ответа от api.telegram.org (хостинг может блокировать)')]); }
    elseif(is_array($j)){ echo json_encode(['ok'=>false,'err'=>'Telegram: '.($j['description']??('код '.$code))]); }
    else { echo json_encode(['ok'=>false,'err'=>'Telegram HTTP '.$code.': '.substr(strip_tags((string)$raw),0,160)]); }
    exit;
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
        <button class="btn-ghost btn-sm" onclick="postTelegram()" title="Опубликовать текст Telegram-вкладки с фото авто в канал">📢 В Telegram</button>
        <span class="save-hint" id="ad_hint"></span>
      </div>
    </div>
  </div>
  <div class="tabs">
    <button class="tab active" data-t="cars" onclick="tab('cars')">🚗 Машины</button>
    <button class="tab" data-t="exp" onclick="tab('exp')">📋 Общие расходы</button>
    <?php if($isAdmin): ?><button class="tab" data-t="users" onclick="tab('users')">👤 Пользователи</button><button class="tab" data-t="settings" onclick="tab('settings')">⚙️ Настройки</button><?php endif; ?>
  </div>

  <div id="costModal" style="position:fixed;inset:0;background:rgba(30,20,12,.5);z-index:50;display:none;place-items:center;padding:16px">
    <div class="card" style="width:min(720px,96vw);max-height:92vh;overflow:auto;margin:0">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px">
        <b id="cost_title">Расходы по авто</b>
        <button class="del" onclick="closeCost()">✕</button>
      </div>
      <div class="frm" style="grid-template-columns:repeat(3,1fr)">
        <div><label>Тип расхода</label><select id="ct_type"></select></div>
        <div><label>Дата оплаты</label><input id="ct_date" type="date" onchange="updateCbr()"></div>
        <div><label>Валюта</label><select id="ct_cur" onchange="costCurChange()"></select></div>
        <div><label>Сумма</label><input id="ct_amount" type="number" min="0" step="0.01" placeholder="0" oninput="costCalc()"></div>
        <div id="ct_rate_wrap"><label>Курс, ₽ за 1</label><input id="ct_rate" type="number" min="0" step="0.0001" placeholder="0" oninput="costCalc()"><div id="ct_cbr" style="font-size:11.5px;color:var(--muted);margin-top:4px"></div></div>
        <div><label>Итого, ₽</label><input id="ct_rub" readonly style="background:var(--bg);font-weight:700"></div>
        <div style="grid-column:span 2"><label>Заметка</label><input id="ct_note" placeholder="необязательно"></div>
        <div><label>&nbsp;</label><button class="btn" id="ct_addbtn" style="width:100%" onclick="saveCostLine()">＋ Добавить</button></div>
      </div>
      <div class="tbl-wrap" style="margin-top:14px"><table><thead><tr><th>Тип</th><th>Дата</th><th class="num">Сумма</th><th>Вал.</th><th class="num">Курс</th><th class="num">₽</th><th></th></tr></thead><tbody id="cost-body"></tbody></table></div>
      <div style="text-align:right;margin-top:10px;font-weight:800">Итого затрат по авто: <span id="cost-total" style="color:var(--accent)"></span></div>
    </div>
  </div>

  <div id="pane-cars">
    <div class="card"><div class="frm">
      <div><label>Модель</label><input id="c_name" placeholder="напр. Toyota bZ3X"></div>
      <div><label>Страна</label><select id="c_country"><option>Китай</option><option>США</option><option>Корея</option><option>Япония</option><option>Другое</option></select></div>
      <div><label>Дата</label><input id="c_date" type="date"></div>
      <div><label>Продажа (под ключ), ₽</label><input id="c_sale" type="number" min="0" placeholder="0"></div>
      <div style="grid-column:span 2"><label>Заметка</label><input id="c_note" placeholder="клиент, город, статус…"></div>
      <div style="grid-column:span 2"><label>Фото авто (можно несколько — превью и реклама)</label><input id="c_photo" type="file" accept="image/*" multiple onchange="pickPhotos(this)"><div id="c_photo_prev" style="display:none;margin-top:6px"></div></div>
      <div style="grid-column:span 2"><label>Публикация</label><label style="display:flex;align-items:center;gap:8px;font-size:14px;color:var(--ink);cursor:pointer;padding:9px 0"><input type="checkbox" id="c_publish" style="width:auto;margin:0"> Показывать это авто на лендинге</label></div>
      <div><button class="btn" id="carBtn" style="width:100%" onclick="addCar()">Добавить авто</button></div>
    </div>
    <p class="muted" style="font-size:13px;margin:12px 0 0">Затраты (закупка, логистика, растаможка, утиль, прочее) вносятся по факту кнопкой <b>＋ Расход</b> в строке авто — можно в валюте с курсом на день оплаты.</p></div>
    <div class="card tbl-wrap"><table><thead><tr>
      <th>Модель</th><th>Страна</th><th>Дата</th><th class="num">Затраты</th><th class="num">Продажа</th><th class="num">Прибыль</th><th></th>
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
const COST_TYPES=['Закупка','Логистика','Растаможка','Утиль','Прочее'];
const CUR=[['RUB','₽ рубль'],['USD','$ доллар'],['CNY','¥ юань'],['EUR','€ евро'],['KRW','₩ вона'],['JPY','¥ иена']];
function curRub(x){return (x.currency==='RUB'||!x.currency)?Math.round(+x.amount||0):Math.round((+x.amount||0)*(+x.rate||0));}
function migrateCar(c){if(!Array.isArray(c.costs)){c.costs=[];[['purchase','Закупка'],['delivery','Логистика'],['customs','Растаможка'],['util','Утиль'],['other','Прочее']].forEach(function(p){if(+c[p[0]])c.costs.push({id:uid(),type:p[1],date:c.date||'',amount:+c[p[0]],currency:'RUB',rate:1,rub:+c[p[0]],note:''});});}['purchase','delivery','customs','util','other'].forEach(k=>delete c[k]);return c;}
function carCosts(c){return (c.costs||[]).reduce((s,x)=>s+(+x.rub||0),0);}
function costByType(c,t){return (c.costs||[]).reduce((s,x)=>s+(x.type===t?(+x.rub||0):0),0);}
function carProfit(c){return (+c.sale||0)-carCosts(c);}
function renderCars(){const b=document.getElementById('cars-body');b.innerHTML='';DATA.cars.forEach(c=>{const p=carProfit(c);const ps=carPhotos(c);const thumb=ps.length?`<img src="${esc(ps[0])}" alt="" style="height:30px;width:46px;object-fit:cover;border-radius:5px;vertical-align:middle;margin-right:7px">`:'';const more=ps.length>1?` <span style="font-size:11px;color:var(--faint)">+${ps.length-1}</span>`:'';const pub=`<button class="btn-ghost btn-sm" onclick="togglePublish('${c.id}')" title="${c.publish?'На лендинге — нажмите, чтобы скрыть':'Скрыто — нажмите, чтобы показать на лендинге'}" style="${c.publish?'background:var(--accent);color:#fff;border-color:var(--accent)':'opacity:.55'}">🌐</button>`;const tr=document.createElement('tr');tr.innerHTML=`<td>${thumb}${esc(c.name)}${more}</td><td>${esc(c.country||'')}</td><td>${esc(c.date||'')}</td><td class="num">${fmt(carCosts(c))}</td><td class="num">${fmt(c.sale)}</td><td class="num ${p>=0?'profit-pos':'profit-neg'}">${fmt(p)}</td><td style="white-space:nowrap;text-align:right"><button class="btn-ghost btn-sm" onclick="openCost('${c.id}')" title="Добавить расход">＋ Расход</button> ${pub} <button class="btn-ghost btn-sm" onclick="genAds('${c.id}')" title="Сгенерировать объявление">✨${c.ads&&(c.ads.telegram||c.ads.vk||c.ads.instagram)?' ✓':''}</button> <button class="btn-ghost btn-sm" onclick="editCar('${c.id}')" title="Редактировать">✎</button> <button class="del" onclick="delCar('${c.id}')" title="Удалить">✕</button></td>`;b.appendChild(tr);});renderSummary();}
function renderExp(){const b=document.getElementById('exp-body');b.innerHTML='';DATA.expenses.forEach(e=>{const tr=document.createElement('tr');tr.innerHTML=`<td>${esc(e.date||'')}</td><td>${esc(e.cat||'')}</td><td class="num">${fmt(e.amount)}</td><td>${esc(e.note||'')}</td><td><button class="del" onclick="delExp('${e.id}')">✕</button></td>`;b.appendChild(tr);});renderSummary();}
function renderSummary(){const cp=DATA.cars.reduce((s,c)=>s+carProfit(c),0);const ex=DATA.expenses.reduce((s,e)=>s+(+e.amount||0),0);const net=cp-ex;document.getElementById('summary').innerHTML=`<div class="stat"><span>Машин в учёте</span><b>${DATA.cars.length}</b></div><div class="stat good"><span>Прибыль с машин</span><b>${fmt(cp)}</b></div><div class="stat bad"><span>Общие расходы</span><b>${fmt(ex)}</b></div><div class="stat ${net>=0?'acc':'bad'}"><span>Чистая прибыль</span><b>${fmt(net)}</b></div>`;}
let editingCarId=null, pendingPhotos=[], editingPhotos=[];
function carFormData(){const g=id=>document.getElementById(id);return {name:g('c_name').value.trim(),country:g('c_country').value,date:g('c_date').value,sale:+g('c_sale').value||0,note:g('c_note').value.trim(),publish:g('c_publish').checked};}
function carPhotos(c){return (c.photos&&c.photos.length)?c.photos:(c.photo?[c.photo]:[]);}
function renderPhotoPrev(){const box=document.getElementById('c_photo_prev');box.innerHTML='';const mk=(src,onx)=>{const w=document.createElement('span');w.style.cssText='position:relative;display:inline-block;margin:4px 8px 0 0';const im=document.createElement('img');im.src=src;im.style.cssText='height:54px;width:74px;object-fit:cover;border-radius:6px;display:block;border:1px solid var(--line)';const b=document.createElement('button');b.textContent='✕';b.type='button';b.style.cssText='position:absolute;top:-7px;right:-7px;background:#c0432c;color:#fff;border:0;border-radius:50%;width:19px;height:19px;font-size:11px;line-height:1;cursor:pointer;padding:0';b.onclick=onx;w.appendChild(im);w.appendChild(b);box.appendChild(w);};editingPhotos.forEach((u,i)=>mk(u,()=>{editingPhotos.splice(i,1);renderPhotoPrev();}));pendingPhotos.forEach((d,i)=>mk(d,()=>{pendingPhotos.splice(i,1);renderPhotoPrev();}));box.style.display=(editingPhotos.length||pendingPhotos.length)?'block':'none';}
function clearCarForm(){const g=id=>document.getElementById(id);['c_name','c_sale','c_note'].forEach(i=>g(i).value='');g('c_date').value=new Date().toISOString().slice(0,10);g('c_photo').value='';g('c_publish').checked=false;pendingPhotos=[];editingPhotos=[];renderPhotoPrev();}
function downscale(file){return new Promise(res=>{const rd=new FileReader();rd.onload=()=>{const img=new Image();img.onload=()=>{const max=1200;let w=img.width,h=img.height;if(w>max||h>max){if(w>=h){h=Math.round(h*max/w);w=max;}else{w=Math.round(w*max/h);h=max;}}const cv=document.createElement('canvas');cv.width=w;cv.height=h;cv.getContext('2d').drawImage(img,0,0,w,h);res(cv.toDataURL('image/jpeg',0.82));};img.src=rd.result;};rd.readAsDataURL(file);});}
async function pickPhotos(inp){const fs=Array.from(inp.files||[]);for(const f of fs){pendingPhotos.push(await downscale(f));}inp.value='';renderPhotoPrev();}
async function addCar(){const g=id=>document.getElementById(id);if(!g('c_name').value.trim()){g('c_name').focus();return;}const d=carFormData();const btn=g('carBtn');let urls=[];if(pendingPhotos.length){const t0=btn.textContent;btn.textContent='Загрузка фото…';for(const dp of pendingPhotos){const r=await api('photo_up',{data:dp});if(!r.ok){alert('Фото не загрузилось: '+(r.err||''));btn.textContent=t0;return;}urls.push(r.url);}btn.textContent=t0;}const photos=editingPhotos.concat(urls);if(editingCarId){const c=DATA.cars.find(x=>x.id===editingCarId);if(c){Object.assign(c,d);c.photos=photos;delete c.photo;}editingCarId=null;btn.textContent='Добавить авто';}else{const car={id:uid(),...d};if(photos.length)car.photos=photos;DATA.cars.unshift(car);}clearCarForm();renderCars();save();}
function togglePublish(id){const c=DATA.cars.find(x=>x.id===id);if(!c)return;c.publish=!c.publish;renderCars();save();}
let costCarId=null, editingCostId=null, cbrToken=0;
function openCost(id){const c=DATA.cars.find(x=>x.id===id);if(!c)return;if(!Array.isArray(c.costs))c.costs=[];costCarId=id;editingCostId=null;document.getElementById('cost_title').textContent='Расходы · '+c.name;document.getElementById('ct_type').innerHTML=COST_TYPES.map(x=>`<option>${x}</option>`).join('');document.getElementById('ct_cur').innerHTML=CUR.map(p=>`<option value="${p[0]}">${p[1]}</option>`).join('');document.getElementById('ct_date').value=new Date().toISOString().slice(0,10);document.getElementById('ct_amount').value='';document.getElementById('ct_rate').value='';document.getElementById('ct_note').value='';document.getElementById('ct_addbtn').textContent='＋ Добавить';costCurChange();renderCostRows();document.getElementById('costModal').style.display='grid';}
function closeCost(){document.getElementById('costModal').style.display='none';costCarId=null;editingCostId=null;}
function costCurChange(){const isR=document.getElementById('ct_cur').value==='RUB';document.getElementById('ct_rate_wrap').style.display=isR?'none':'';if(isR){document.getElementById('ct_rate').value='';document.getElementById('ct_cbr').textContent='';}costCalc();updateCbr();}
function costCalc(){const cur=document.getElementById('ct_cur').value;const a=+document.getElementById('ct_amount').value||0;const r=cur==='RUB'?1:(+document.getElementById('ct_rate').value||0);document.getElementById('ct_rub').value=fmt(Math.round(a*r));}
async function fetchCbrRate(dateStr,cur){if(!dateStr||!cur||cur==='RUB')return null;const p=dateStr.split('-');if(p.length!==3)return null;const urls=[`https://www.cbr-xml-daily.ru/archive/${p[0]}/${p[1]}/${p[2]}/daily_json.js`,'https://www.cbr-xml-daily.ru/daily_json.js'];for(let i=0;i<urls.length;i++){try{const r=await fetch(urls[i]);if(!r.ok)continue;const j=await r.json();const v=j.Valute&&j.Valute[cur];if(v&&v.Value&&v.Nominal)return {rate:v.Value/v.Nominal,date:(j.Date||'').slice(0,10),fallback:i===1};}catch(e){}}return null;}
async function updateCbr(){const cur=document.getElementById('ct_cur').value;const el=document.getElementById('ct_cbr');if(!el)return;if(cur==='RUB'){el.textContent='';return;}const date=document.getElementById('ct_date').value;el.textContent='Курс ЦБ…';const my=++cbrToken;const res=await fetchCbrRate(date,cur);if(my!==cbrToken)return;if(res){const rv=Math.round(res.rate*10000)/10000;el.innerHTML='Курс ЦБ'+(res.fallback?' (последний доступный)':' на '+(res.date||date))+': <b>'+String(rv).replace('.',',')+' ₽</b> · <a href="#" style="color:var(--accent)" onclick="applyCbr('+rv+');return false;">подставить</a>';}else el.textContent='Курс ЦБ на эту дату недоступен — впишите вручную';}
function applyCbr(v){document.getElementById('ct_rate').value=v;costCalc();}
function renderCostRows(){const c=DATA.cars.find(x=>x.id===costCarId);if(!c)return;const b=document.getElementById('cost-body');b.innerHTML='';(c.costs||[]).forEach(x=>{const tr=document.createElement('tr');if(x.id===editingCostId)tr.style.background='color-mix(in srgb,var(--accent) 9%,transparent)';tr.innerHTML=`<td>${esc(x.type)}</td><td>${esc(x.date||'')}</td><td class="num">${(+x.amount||0).toLocaleString('ru-RU')}</td><td>${esc(x.currency||'RUB')}</td><td class="num">${(x.currency&&x.currency!=='RUB')?(+x.rate||0):'—'}</td><td class="num">${fmt(x.rub)}</td><td style="white-space:nowrap"><button class="btn-ghost btn-sm" onclick="editCostLine('${x.id}')" title="Редактировать">✎</button> <button class="del" onclick="delCostLine('${x.id}')" title="Удалить">✕</button></td>`;b.appendChild(tr);});document.getElementById('cost-total').textContent=fmt(carCosts(c));}
function editCostLine(cid){const c=DATA.cars.find(x=>x.id===costCarId);if(!c)return;const x=(c.costs||[]).find(i=>i.id===cid);if(!x)return;editingCostId=cid;document.getElementById('ct_type').value=x.type;document.getElementById('ct_date').value=x.date||'';document.getElementById('ct_cur').value=x.currency||'RUB';document.getElementById('ct_amount').value=x.amount;costCurChange();document.getElementById('ct_rate').value=(x.currency&&x.currency!=='RUB')?x.rate:'';document.getElementById('ct_note').value=x.note||'';document.getElementById('ct_addbtn').textContent='Сохранить изменения';costCalc();renderCostRows();}
function saveCostLine(){const c=DATA.cars.find(x=>x.id===costCarId);if(!c)return;const a=+document.getElementById('ct_amount').value||0;if(!a){document.getElementById('ct_amount').focus();return;}const cur=document.getElementById('ct_cur').value;const rate=cur==='RUB'?1:(+document.getElementById('ct_rate').value||0);if(cur!=='RUB'&&!rate){document.getElementById('ct_rate').focus();return;}const data={type:document.getElementById('ct_type').value,date:document.getElementById('ct_date').value,amount:a,currency:cur,rate:rate,note:document.getElementById('ct_note').value.trim()};data.rub=curRub(data);if(!Array.isArray(c.costs))c.costs=[];if(editingCostId){const x=c.costs.find(i=>i.id===editingCostId);if(x)Object.assign(x,data);editingCostId=null;document.getElementById('ct_addbtn').textContent='＋ Добавить';}else{data.id=uid();c.costs.push(data);}document.getElementById('ct_amount').value='';document.getElementById('ct_note').value='';costCalc();renderCostRows();renderCars();save();}
function delCostLine(cid){const c=DATA.cars.find(x=>x.id===costCarId);if(!c)return;c.costs=(c.costs||[]).filter(x=>x.id!==cid);if(cid===editingCostId){editingCostId=null;document.getElementById('ct_addbtn').textContent='＋ Добавить';}renderCostRows();renderCars();save();}
function editCar(id){const c=DATA.cars.find(x=>x.id===id);if(!c)return;const el=x=>document.getElementById(x);el('c_name').value=c.name||'';el('c_country').value=c.country||'Китай';el('c_date').value=c.date||'';el('c_sale').value=c.sale||'';el('c_note').value=c.note||'';el('c_publish').checked=!!c.publish;pendingPhotos=[];editingPhotos=carPhotos(c).slice();el('c_photo').value='';renderPhotoPrev();editingCarId=id;el('carBtn').textContent='Сохранить изменения';window.scrollTo({top:0,behavior:'smooth'});}
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
let TGCONF=null;
async function tgConf(){if(TGCONF)return TGCONF;const r=await api('tg_conf');if(r.ok){TGCONF={token:r.token,chat:r.chat};return TGCONF;}return null;}
function tgUrl(p){p=String(p);if(/^https?:/i.test(p))return p;return location.origin+'/uchet/'+p.replace(/^\/uchet\//,'').replace(/^\//,'');}
async function tgCall(token,method,params){const body=new FormData();for(const k in params){body.append(k,typeof params[k]==='object'?JSON.stringify(params[k]):params[k]);}const r=await fetch('https://api.telegram.org/bot'+token+'/'+method,{method:'POST',body:body});return r.json();}
async function postTelegram(){adData[adTabCur]=document.getElementById('ad_text').value;const c=DATA.cars.find(x=>x.id===adCarId);const text=(adData.telegram||'').trim()||document.getElementById('ad_text').value.trim();const hint=document.getElementById('ad_hint');if(!text){hint.textContent='Сначала текст на вкладке Telegram';return;}const conf=await tgConf();if(!conf||!conf.token||!conf.chat){hint.textContent='Telegram-бот не настроен (tgconfig.php)';return;}const photos=(c?carPhotos(c):[]).map(tgUrl);if(!confirm('Опубликовать пост'+(photos.length?(' с '+photos.length+' фото'):' без фото')+' в Telegram-канал '+conf.chat+'?'))return;hint.textContent='Публикую в Telegram…';try{let j;if(photos.length===0){j=await tgCall(conf.token,'sendMessage',{chat_id:conf.chat,text:text});}else if(photos.length===1){j=await tgCall(conf.token,'sendPhoto',{chat_id:conf.chat,photo:photos[0],caption:text.slice(0,1024)});}else{const media=photos.slice(0,10).map((u,i)=>i===0?{type:'photo',media:u,caption:text.slice(0,1024)}:{type:'photo',media:u});j=await tgCall(conf.token,'sendMediaGroup',{chat_id:conf.chat,media:media});}hint.textContent=(j&&j.ok)?'Опубликовано в Telegram ✓':('Ошибка — Telegram: '+((j&&j.description)||'неизвестно'));}catch(e){hint.textContent='Ошибка сети (браузер не достучался до Telegram): '+e.message;}}
function renderCatSelect(){const sel=document.getElementById('e_cat');if(!sel)return;const cur=sel.value;sel.innerHTML='';(DATA.categories||[]).forEach(c=>{const o=document.createElement('option');o.textContent=c;sel.appendChild(o);});if(cur&&(DATA.categories||[]).includes(cur))sel.value=cur;}
function renderCats(){const box=document.getElementById('cats-list');if(!box)return;box.innerHTML='';(DATA.categories||[]).forEach((c,i)=>{const chip=document.createElement('span');chip.className='badge user';chip.style.cssText='display:inline-flex;align-items:center;gap:6px;font-size:13px;padding:6px 10px';chip.innerHTML=esc(c)+' <button class="del" style="font-size:14px;padding:0 2px" onclick="delCat('+i+')">✕</button>';box.appendChild(chip);});}
function addCat(){const inp=document.getElementById('cat_new');const v=inp.value.trim();if(!v)return;if(!DATA.categories)DATA.categories=[];if(DATA.categories.some(c=>c.toLowerCase()===v.toLowerCase())){alert('Такая категория уже есть');return;}DATA.categories.push(v);inp.value='';renderCats();renderCatSelect();save();}
function delCat(i){const c=(DATA.categories||[])[i];if(c==null)return;if(!confirm('Удалить категорию «'+c+'»?'))return;DATA.categories.splice(i,1);renderCats();renderCatSelect();save();}
(async()=>{const r=await api('load');if(r.ok){DATA=r.data;if(!DATA.cars)DATA.cars=[];if(!DATA.expenses)DATA.expenses=[];}DATA.cars.forEach(migrateCar);if(!Array.isArray(DATA.categories)||!DATA.categories.length)DATA.categories=['Реклама / площадка','Домен+хостинг','Логистика','Зарплата','Комиссии','Прочее'];renderCars();renderExp();renderCatSelect();if(IS_ADMIN)renderCats();const t=new Date().toISOString().slice(0,10);document.getElementById('c_date').value=t;document.getElementById('e_date').value=t;})();
</script>
<?php endif; ?>
</body></html>
