<?php
// Serkin — закрытый учёт расходов. PHP 8.2 (Timeweb).
// Данные и пароль хранятся ВЫШЕ публичной папки (не скачать по ссылке).
session_start();
$BASE = dirname(__DIR__, 2); // /home/c/cgXXXXX  (public_html/uchet -> на два уровня выше)
$AUTH_FILE = $BASE . '/uchet_auth.json';
$DATA_FILE = $BASE . '/uchet_store.json';

function read_json($f){ if(!file_exists($f)) return null; $d=json_decode((string)file_get_contents($f), true); return is_array($d)?$d:null; }
function write_json($f,$d){ file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX); }

$auth = read_json($AUTH_FILE);

// ---------- API ----------
if (isset($_GET['action'])) {
  header('Content-Type: application/json; charset=utf-8');
  $a = $_GET['action'];
  $in = json_decode((string)file_get_contents('php://input'), true) ?: [];

  if ($a === 'setup') {
    if ($auth) { echo json_encode(['ok'=>false,'err'=>'already']); exit; }
    $pw = (string)($in['password'] ?? '');
    if (mb_strlen($pw) < 6) { echo json_encode(['ok'=>false,'err'=>'short']); exit; }
    write_json($AUTH_FILE, ['hash'=>password_hash($pw, PASSWORD_DEFAULT)]);
    $_SESSION['ok']=true; echo json_encode(['ok'=>true]); exit;
  }
  if ($a === 'login') {
    $pw = (string)($in['password'] ?? '');
    if ($auth && password_verify($pw, $auth['hash'])) { $_SESSION['ok']=true; echo json_encode(['ok'=>true]); }
    else { echo json_encode(['ok'=>false]); }
    exit;
  }
  if ($a === 'logout') { $_SESSION=[]; session_destroy(); echo json_encode(['ok'=>true]); exit; }

  if (empty($_SESSION['ok'])) { http_response_code(401); echo json_encode(['ok'=>false,'err'=>'auth']); exit; }

  if ($a === 'load') { echo json_encode(['ok'=>true,'data'=>(read_json($DATA_FILE) ?: ['cars'=>[],'expenses'=>[]])]); exit; }
  if ($a === 'save') {
    write_json($DATA_FILE, ['cars'=>($in['cars']??[]), 'expenses'=>($in['expenses']??[])]);
    echo json_encode(['ok'=>true]); exit;
  }
  echo json_encode(['ok'=>false]); exit;
}

$state = !$auth ? 'setup' : (empty($_SESSION['ok']) ? 'login' : 'app');
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
h1{font-size:22px;margin:0}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}
.brand{font-weight:800;letter-spacing:-.01em}
.brand span{color:var(--accent)}
button{font-family:inherit;cursor:pointer;border:0;border-radius:10px;padding:10px 16px;font-weight:650;font-size:14px}
.btn{background:var(--accent);color:#fff}
.btn:hover{filter:brightness(1.06)}
.btn-ghost{background:transparent;border:1px solid var(--line);color:var(--ink)}
.tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.tab{background:var(--card);border:1px solid var(--line);color:var(--muted);border-radius:20px;padding:8px 16px;font-weight:600}
.tab.active{background:var(--ink);color:var(--bg);border-color:var(--ink)}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin-bottom:14px}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.stat{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.stat b{display:block;font-size:24px;font-variant-numeric:tabular-nums;letter-spacing:-.02em;margin-top:4px}
.stat span{font-size:12.5px;color:var(--muted)}
.stat.good b{color:var(--good)} .stat.bad b{color:var(--bad)} .stat.acc b{color:var(--accent)}
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
.del{background:transparent;color:var(--faint);border:0;font-size:18px;padding:2px 8px}
.del:hover{color:var(--bad)}
.center{min-height:100vh;display:grid;place-items:center;padding:20px}
.login-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:28px;width:min(400px,92vw);text-align:center}
.login-card h2{margin:0 0 6px}.login-card p{color:var(--muted);margin:0 0 18px;font-size:14px}
.login-card input{margin-bottom:12px}
.err{color:var(--bad);font-size:13px;min-height:18px}
.muted{color:var(--muted)} .hide{display:none}
.save-hint{font-size:12.5px;color:var(--faint)}
@media(max-width:760px){.grid{grid-template-columns:repeat(2,1fr)}.frm{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<?php if ($state === 'setup' || $state === 'login'): ?>
<div class="center"><div class="login-card">
  <h2>Serkin · <span style="color:var(--accent)">Учёт</span></h2>
  <?php if ($state==='setup'): ?>
    <p>Первый вход. Придумайте пароль для доступа к учёту (мин. 6 символов). Запомните его — сбросить можно только на сервере.</p>
    <input id="pw" type="password" placeholder="Новый пароль" autocomplete="new-password">
    <input id="pw2" type="password" placeholder="Повторите пароль" autocomplete="new-password">
    <div class="err" id="err"></div>
    <button class="btn" style="width:100%" onclick="doSetup()">Создать пароль</button>
  <?php else: ?>
    <p>Введите пароль для доступа к учёту расходов.</p>
    <input id="pw" type="password" placeholder="Пароль" autocomplete="current-password" onkeydown="if(event.key==='Enter')doLogin()">
    <div class="err" id="err"></div>
    <button class="btn" style="width:100%" onclick="doLogin()">Войти</button>
  <?php endif; ?>
</div></div>
<script>
async function api(action,body){const r=await fetch('?action='+action,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body||{})});return r.json();}
async function doSetup(){const p=pw.value,p2=pw2.value;err.textContent='';if(p.length<6){err.textContent='Пароль минимум 6 символов';return;}if(p!==p2){err.textContent='Пароли не совпадают';return;}const r=await api('setup',{password:p});if(r.ok)location.reload();else err.textContent='Ошибка: '+(r.err||'');}
async function doLogin(){err.textContent='';const r=await api('login',{password:pw.value});if(r.ok)location.reload();else err.textContent='Неверный пароль';}
</script>
<?php else: ?>
<div class="wrap">
  <div class="top">
    <div class="brand">Serkin · <span>Учёт расходов</span></div>
    <div style="display:flex;gap:10px;align-items:center">
      <span class="save-hint" id="saveHint">Сохранено ✓</span>
      <button class="btn-ghost" onclick="logout()">Выйти</button>
    </div>
  </div>

  <div class="grid" id="summary"></div>

  <div class="tabs">
    <button class="tab active" data-t="cars" onclick="tab('cars')">🚗 Машины</button>
    <button class="tab" data-t="exp" onclick="tab('exp')">📋 Общие расходы</button>
  </div>

  <!-- CARS -->
  <div id="pane-cars">
    <div class="card">
      <div class="frm">
        <div><label>Модель</label><input id="c_name" placeholder="напр. Toyota bZ3X"></div>
        <div><label>Страна</label>
          <select id="c_country"><option>Китай</option><option>США</option><option>Корея</option><option>Япония</option><option>Другое</option></select></div>
        <div><label>Дата</label><input id="c_date" type="date"></div>
        <div><label>Закупка, ₽</label><input id="c_purchase" type="number" min="0" placeholder="0"></div>
        <div><label>Логистика, ₽</label><input id="c_delivery" type="number" min="0" placeholder="0"></div>
        <div><label>Растаможка, ₽</label><input id="c_customs" type="number" min="0" placeholder="0"></div>
        <div><label>Утиль, ₽</label><input id="c_util" type="number" min="0" placeholder="0"></div>
        <div><label>Прочее, ₽</label><input id="c_other" type="number" min="0" placeholder="0"></div>
        <div><label>Продажа (под ключ), ₽</label><input id="c_sale" type="number" min="0" placeholder="0"></div>
        <div style="grid-column:span 2"><label>Заметка</label><input id="c_note" placeholder="клиент, город, статус…"></div>
        <div><button class="btn" style="width:100%" onclick="addCar()">Добавить авто</button></div>
      </div>
    </div>
    <div class="card tbl-wrap">
      <table><thead><tr>
        <th>Модель</th><th>Страна</th><th>Дата</th>
        <th class="num">Закупка</th><th class="num">Логист.</th><th class="num">Растам.</th><th class="num">Утиль</th><th class="num">Прочее</th>
        <th class="num">Затраты</th><th class="num">Продажа</th><th class="num">Прибыль</th><th></th>
      </tr></thead><tbody id="cars-body"></tbody></table>
    </div>
  </div>

  <!-- EXPENSES -->
  <div id="pane-exp" class="hide">
    <div class="card">
      <div class="frm">
        <div><label>Дата</label><input id="e_date" type="date"></div>
        <div><label>Категория</label>
          <select id="e_cat"><option>Реклама</option><option>Логистика</option><option>Зарплата</option><option>Комиссии</option><option>Прочее</option></select></div>
        <div><label>Сумма, ₽</label><input id="e_amount" type="number" min="0" placeholder="0"></div>
        <div><label>Комментарий</label><input id="e_note" placeholder="за что"></div>
        <div><button class="btn" style="width:100%" onclick="addExp()">Добавить расход</button></div>
      </div>
    </div>
    <div class="card tbl-wrap">
      <table><thead><tr><th>Дата</th><th>Категория</th><th class="num">Сумма</th><th>Комментарий</th><th></th></tr></thead>
      <tbody id="exp-body"></tbody></table>
    </div>
  </div>
</div>

<script>
let DATA={cars:[],expenses:[]};
const fmt=n=>((+n||0).toLocaleString('ru-RU'))+' ₽';
const uid=()=>Date.now().toString(36)+Math.random().toString(36).slice(2,6);
async function api(action,body){const r=await fetch('?action='+action,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body||{})});if(r.status===401){location.reload();return{ok:false};}return r.json();}
async function logout(){await api('logout');location.reload();}

let saveTimer=null;
function save(){document.getElementById('saveHint').textContent='Сохранение…';clearTimeout(saveTimer);saveTimer=setTimeout(async()=>{const r=await api('save',DATA);document.getElementById('saveHint').textContent=r.ok?'Сохранено ✓':'Ошибка сохранения';},400);}

function carCosts(c){return (+c.purchase||0)+(+c.delivery||0)+(+c.customs||0)+(+c.util||0)+(+c.other||0);}
function carProfit(c){return (+c.sale||0)-carCosts(c);}

function renderCars(){
  const b=document.getElementById('cars-body');b.innerHTML='';
  DATA.cars.forEach(c=>{
    const p=carProfit(c);
    const tr=document.createElement('tr');
    tr.innerHTML=`<td>${esc(c.name)}</td><td>${esc(c.country||'')}</td><td>${esc(c.date||'')}</td>
      <td class="num">${fmt(c.purchase)}</td><td class="num">${fmt(c.delivery)}</td><td class="num">${fmt(c.customs)}</td><td class="num">${fmt(c.util)}</td><td class="num">${fmt(c.other)}</td>
      <td class="num">${fmt(carCosts(c))}</td><td class="num">${fmt(c.sale)}</td>
      <td class="num ${p>=0?'profit-pos':'profit-neg'}">${fmt(p)}</td>
      <td><button class="del" title="Удалить" onclick="delCar('${c.id}')">✕</button></td>`;
    b.appendChild(tr);
  });
  renderSummary();
}
function renderExp(){
  const b=document.getElementById('exp-body');b.innerHTML='';
  DATA.expenses.forEach(e=>{
    const tr=document.createElement('tr');
    tr.innerHTML=`<td>${esc(e.date||'')}</td><td>${esc(e.cat||'')}</td><td class="num">${fmt(e.amount)}</td><td>${esc(e.note||'')}</td>
      <td><button class="del" onclick="delExp('${e.id}')">✕</button></td>`;
    b.appendChild(tr);
  });
  renderSummary();
}
function renderSummary(){
  const carsProfit=DATA.cars.reduce((s,c)=>s+carProfit(c),0);
  const exp=DATA.expenses.reduce((s,e)=>s+(+e.amount||0),0);
  const net=carsProfit-exp;
  document.getElementById('summary').innerHTML=`
    <div class="stat"><span>Машин в учёте</span><b>${DATA.cars.length}</b></div>
    <div class="stat good"><span>Прибыль с машин</span><b>${fmt(carsProfit)}</b></div>
    <div class="stat bad"><span>Общие расходы</span><b>${fmt(exp)}</b></div>
    <div class="stat ${net>=0?'acc':'bad'}"><span>Чистая прибыль</span><b>${fmt(net)}</b></div>`;
}
function esc(s){return String(s??'').replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));}

function addCar(){
  const g=id=>document.getElementById(id);
  if(!g('c_name').value.trim()){g('c_name').focus();return;}
  DATA.cars.unshift({id:uid(),name:g('c_name').value.trim(),country:g('c_country').value,date:g('c_date').value,
    purchase:+g('c_purchase').value||0,delivery:+g('c_delivery').value||0,customs:+g('c_customs').value||0,
    util:+g('c_util').value||0,other:+g('c_other').value||0,sale:+g('c_sale').value||0,note:g('c_note').value.trim()});
  ['c_name','c_purchase','c_delivery','c_customs','c_util','c_other','c_sale','c_note'].forEach(i=>g(i).value='');
  renderCars();save();
}
function delCar(id){if(!confirm('Удалить авто из учёта?'))return;DATA.cars=DATA.cars.filter(c=>c.id!==id);renderCars();save();}
function addExp(){
  const g=id=>document.getElementById(id);
  if(!(+g('e_amount').value)){g('e_amount').focus();return;}
  DATA.expenses.unshift({id:uid(),date:g('e_date').value,cat:g('e_cat').value,amount:+g('e_amount').value||0,note:g('e_note').value.trim()});
  ['e_amount','e_note'].forEach(i=>g(i).value='');
  renderExp();save();
}
function delExp(id){if(!confirm('Удалить расход?'))return;DATA.expenses=DATA.expenses.filter(e=>e.id!==id);renderExp();save();}
function tab(t){document.querySelectorAll('.tab').forEach(x=>x.classList.toggle('active',x.dataset.t===t));
  document.getElementById('pane-cars').classList.toggle('hide',t!=='cars');
  document.getElementById('pane-exp').classList.toggle('hide',t!=='exp');}

(async()=>{const r=await api('load');if(r.ok){DATA=r.data;if(!DATA.cars)DATA.cars=[];if(!DATA.expenses)DATA.expenses=[];}
  renderCars();renderExp();
  document.getElementById('c_date').value=new Date().toISOString().slice(0,10);
  document.getElementById('e_date').value=new Date().toISOString().slice(0,10);})();
</script>
<?php endif; ?>
</body></html>
