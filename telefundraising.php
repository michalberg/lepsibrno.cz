<?php
// ─────────────────────────────────────────────────────────────────────────────
// Telefundraising – nástroj pro volající (repo lepsibrno.cz).
// POZOR: kontakty (PII) NEJSOU v gitu. Seedují se z ../telefundraising_seed.json,
// který se nahrává RUČNĚ na server do adresáře nad webrootem (vedle donors.db).
// Přihlášení je SPOLEČNÉ s transakce.php (stejné heslo admin_password i cookie
// lb_admin) → kdo je přihlášený v jednom, je i v druhém. Mezi stránkami jsou odkazy.
// ─────────────────────────────────────────────────────────────────────────────
$config = require __DIR__ . '/config.php';
$adminPassword = (string)($config['admin_password'] ?? '');
$DB_PATH   = __DIR__ . '/../telefundraising.db';        // mimo webroot
$SEED_FILE = __DIR__ . '/../telefundraising_seed.json'; // mimo webroot, nahrát ručně

const COOKIE_NAME = 'lb_admin';                          // shodné s transakce.php (SSO)
const COOKIE_TTL  = 90 * 24 * 60 * 60;
const TF_CALLER   = 'lb_tf_caller';
function admin_token(string $pw): string { return hash_hmac('sha256', 'lb-admin-v1', $pw); }
function tf_logged_in(string $pw): bool {
    if ($pw === '') return false;
    $c = (string)($_COOKIE[COOKIE_NAME] ?? '');
    return $c !== '' && hash_equals(admin_token($pw), $c);
}

// ---- Přihlášení / odhlášení (shodné cookie jako transakce.php) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($adminPassword !== '' && hash_equals($adminPassword, (string)$_POST['password'])) {
        setcookie(COOKIE_NAME, admin_token($adminPassword),
            ['expires'=>time()+COOKIE_TTL, 'path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax']);
        // jméno volajícího: NENÍ httponly, ať jde změnit i z appky (JS)
        setcookie(TF_CALLER, trim((string)($_POST['caller'] ?? '')) ?: 'neznámý',
            ['expires'=>time()+COOKIE_TTL, 'path'=>'/', 'secure'=>true, 'httponly'=>false, 'samesite'=>'Lax']);
        header('Location: telefundraising.php'); exit;
    } else { $loginError = true; }
}
if (isset($_GET['logout'])) {
    setcookie(COOKIE_NAME, '', ['expires'=>time()-3600, 'path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax']);
    header('Location: telefundraising.php'); exit;
}
$ME = $_COOKIE[TF_CALLER] ?? 'neznámý';

function tf_db(): PDO {
    global $DB_PATH, $SEED_FILE;
    $db = new PDO('sqlite:' . $DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE IF NOT EXISTS tf_contacts (
        id INTEGER PRIMARY KEY, jmeno TEXT, tel TEXT, email TEXT, score INTEGER, prio TEXT,
        donor INTEGER, member INTEGER, dotaznik INTEGER, persona TEXT,
        t1 TEXT, t2 TEXT, eng TEXT, mesto TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS tf_calls (
        contact_id INTEGER PRIMARY KEY, result TEXT, note TEXT, amount TEXT,
        caller TEXT, updated_at TEXT)');
    // seed jednou ze souboru mimo git (pokud existuje a tabulka je prázdná)
    if ((int)$db->query('SELECT COUNT(*) FROM tf_contacts')->fetchColumn() === 0 && is_readable($SEED_FILE)) {
        $seed = json_decode((string)file_get_contents($SEED_FILE), true);
        if (is_array($seed)) {
            $ins = $db->prepare('INSERT INTO tf_contacts
                (id,jmeno,tel,email,score,prio,donor,member,dotaznik,persona,t1,t2,eng,mesto)
                VALUES (:id,:jmeno,:tel,:email,:score,:prio,:donor,:member,:dotaznik,:persona,:t1,:t2,:eng,:mesto)');
            $db->beginTransaction();
            foreach ($seed as $r) {
                $ins->execute([
                    ':id'=>$r['id'],':jmeno'=>$r['jmeno'],':tel'=>$r['tel'],':email'=>$r['email'],':score'=>$r['score'],
                    ':prio'=>$r['prio'],':donor'=>$r['donor']?1:0,':member'=>$r['member']?1:0,
                    ':dotaznik'=>$r['dotaznik']?1:0,':persona'=>$r['persona'],':t1'=>$r['t1'],
                    ':t2'=>$r['t2'],':eng'=>$r['eng'],':mesto'=>$r['mesto']]);
            }
            $db->commit();
        }
    }
    return $db;
}

// ---- API (jen po přihlášení) ----
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if (!tf_logged_in($adminPassword)) { http_response_code(403); echo json_encode(['error'=>'auth']); exit; }
    try {
        $db = tf_db();
        if ($_GET['action'] === 'list') {
            $rows = $db->query('SELECT c.*, k.result, k.note, k.amount, k.caller, k.updated_at
                FROM tf_contacts c LEFT JOIN tf_calls k ON k.contact_id=c.id
                ORDER BY c.score DESC')->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'rows'=>$rows,'me'=>$ME]);
        } elseif ($_GET['action'] === 'save') {
            $in = json_decode(file_get_contents('php://input'), true);
            $st = $db->prepare('INSERT INTO tf_calls (contact_id,result,note,amount,caller,updated_at)
                VALUES (:id,:r,:n,:a,:c,datetime("now","localtime"))
                ON CONFLICT(contact_id) DO UPDATE SET
                result=excluded.result, note=excluded.note, amount=excluded.amount,
                caller=excluded.caller, updated_at=excluded.updated_at');
            $st->execute([':id'=>(int)$in['id'],':r'=>$in['result'],':n'=>$in['note']??'',
                ':a'=>$in['amount']??'',':c'=>$ME]);
            echo json_encode(['ok'=>true]);
        } elseif ($_GET['action'] === 'clear') {
            $in = json_decode(file_get_contents('php://input'), true);
            $db->prepare('DELETE FROM tf_calls WHERE contact_id=:id')->execute([':id'=>(int)$in['id']]);
            echo json_encode(['ok'=>true]);
        } else { echo json_encode(['error'=>'unknown action']); }
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ---- Login obrazovka ----
if (!tf_logged_in($adminPassword)): ?>
<!DOCTYPE html><html lang="cs"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>Telefundraising – přihlášení</title>
<style>body{font:15px -apple-system,Arial,sans-serif;background:#1a2230;color:#fff;display:flex;
min-height:100vh;align-items:center;justify-content:center;margin:0}
form{background:#fff;color:#1a2230;padding:28px;border-radius:12px;width:300px}
h1{font-size:17px;margin:0 0 14px}input{width:100%;padding:10px;margin:6px 0;border:1px solid #ccd;
border-radius:7px;font-size:15px;box-sizing:border-box}button{width:100%;padding:11px;border:0;
border-radius:7px;background:#1f8f4e;color:#fff;font-weight:700;font-size:15px;cursor:pointer;margin-top:6px}
.err{color:#b4451f;font-size:13px}</style></head><body>
<form method="post"><h1>📞 Telefundraising</h1>
<?php if(!empty($loginError)):?><p class="err">Špatné heslo.</p><?php endif;?>
<?php if($adminPassword===''):?><p class="err">Heslo není nastavené (config['admin_password']).</p><?php endif;?>
<input name="caller" placeholder="Vaše jméno (volající)" autofocus autocomplete="name">
<input type="password" name="password" placeholder="Heslo" autocomplete="current-password">
<button type="submit">Vstoupit</button>
</form>
</body></html>
<?php exit; endif;
tf_db(); // zajisti schema + seed při prvním vstupu
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Telefundraising – skripty</title>
<style>
  :root{--bg:#f4f6f8;--card:#fff;--ink:#1a2230;--mut:#6a7686;--line:#e3e8ee;
        --a:#1f8f4e;--b:#c98a1a;--c:#8a93a0;--accent:#1f6feb;--warn:#b4451f;}
  *{box-sizing:border-box}
  body{margin:0;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:var(--ink);background:var(--bg)}
  header{background:var(--ink);color:#fff;padding:10px 16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap}
  header h1{font-size:16px;margin:0;font-weight:700}
  header .stat{font-size:12px;color:#aeb8c6}
  header .me{margin-left:auto;font-size:12px;color:#aeb8c6}
  header a{color:#aeb8c6}
  .wrap{display:grid;grid-template-columns:minmax(420px,1fr) minmax(380px,440px);gap:14px;padding:14px;align-items:start}
  @media(max-width:980px){.wrap{grid-template-columns:1fr}}
  .panel{background:var(--card);border:1px solid var(--line);border-radius:10px}
  .toolbar{display:flex;gap:8px;padding:10px;flex-wrap:wrap;border-bottom:1px solid var(--line)}
  input[type=search]{flex:1;min-width:160px;padding:8px 10px;border:1px solid var(--line);border-radius:7px;font-size:14px}
  .chip{padding:6px 10px;border:1px solid var(--line);border-radius:20px;background:#fff;cursor:pointer;font-size:13px;color:var(--mut)}
  .chip.on{background:var(--ink);color:#fff;border-color:var(--ink)}
  .tablewrap{max-height:calc(100vh - 150px);overflow:auto}
  table{border-collapse:collapse;width:100%;font-size:13.5px}
  th,td{padding:7px 9px;text-align:left;border-bottom:1px solid var(--line);white-space:nowrap}
  th{position:sticky;top:0;background:#fafbfc;cursor:pointer;font-size:12px;color:var(--mut);z-index:1}
  tbody tr{cursor:pointer}
  tbody tr:hover{background:#f0f6ff}
  tbody tr.sel{background:#e3efff}
  tbody tr.done{opacity:.5}
  .pr{display:inline-block;width:20px;height:20px;line-height:20px;text-align:center;border-radius:5px;color:#fff;font-weight:700;font-size:12px}
  .pr.A{background:var(--a)}.pr.B{background:var(--b)}.pr.C{background:var(--c)}
  .tag{font-size:11px;padding:1px 6px;border-radius:4px;background:#eef1f5;color:var(--mut)}
  .eg{font-size:11px;padding:1px 8px;border-radius:20px;font-weight:600;white-space:nowrap}
  .eg-h{background:#e7f6ed;color:#1f8f4e}.eg-m{background:#fdf1e0;color:#9a6712}.eg-l{background:#eef1f5;color:#6a7686}
  .pcell{cursor:help;border-bottom:1px dotted #9fb0c0}
  #ptip{position:fixed;z-index:50;max-width:300px;background:#1a2230;color:#fff;padding:9px 11px;border-radius:8px;font-size:12.5px;line-height:1.45;box-shadow:0 6px 22px rgba(0,0,0,.28);pointer-events:none;display:none}
  .scr{padding:16px;position:sticky;top:14px}
  .scr .who{font-size:20px;font-weight:800}
  .scr .meta{color:var(--mut);font-size:13px;margin:2px 0 6px}
  .scr a.tel{font-size:22px;font-weight:800;color:var(--accent);text-decoration:none}
  .badges{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0}
  .badge{font-size:12px;padding:3px 9px;border-radius:20px;font-weight:600}
  .badge.don{background:#e7f6ed;color:#1f8f4e}.badge.mem{background:#e8f0fe;color:#1f6feb}
  .badge.dot{background:#fdf1e0;color:#9a6712}.badge.kon{background:#eef1f5;color:#6a7686}
  .step{border:1px solid var(--line);border-radius:9px;padding:11px 13px;margin:10px 0;background:#fff}
  .step h3{margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--mut)}
  .say{background:#f6faf7;border-left:3px solid var(--a);padding:9px 11px;border-radius:0 6px 6px 0;margin:6px 0}
  .avoid{color:var(--warn);font-size:13px;margin-top:6px}
  .hint{font-size:12.5px;color:var(--mut);margin-top:6px}
  .log{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}
  .btn{padding:9px 12px;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer;font-size:13px;font-weight:600}
  .btn.ok{background:var(--a);color:#fff;border-color:var(--a)}
  .btn.no{background:#fff;color:var(--warn);border-color:#e7c9bd}
  .btn.cb{background:#fff;color:var(--b);border-color:#ecd9b0}
  .empty{padding:40px;text-align:center;color:var(--mut)}
  .objs summary{cursor:pointer;font-weight:600;color:var(--ink)}
  .objs p{margin:6px 0;font-size:12.5px;color:var(--mut)}
</style>
</head>
<body>
<div id="ptip"></div>
<header>
  <h1>📞 Telefundraising</h1>
  <span class="stat" id="stat">Načítám…</span>
  <span class="me" id="me" style="margin-left:auto"><a href="#" id="setme" style="color:#fff">Volá: …</a></span>
  <a href="transakce.php">💸 Transakce</a>
  <a href="?logout=1">odhlásit</a>
</header>
<div class="wrap">
  <div class="panel">
    <div class="toolbar">
      <input type="search" id="q" placeholder="Hledat jméno, město, personu…">
      <span class="chip on" data-f="all">Vše</span>
      <span class="chip" data-f="A">A</span><span class="chip" data-f="B">B</span><span class="chip" data-f="C">C</span>
      <span class="chip" data-f="todo">Nevolané</span>
    </div>
    <div class="tablewrap"><table>
      <thead><tr><th data-s="prio">P</th><th data-s="jmeno">Jméno</th><th data-s="score">Skóre</th>
      <th data-s="vztah">Vztah</th><th data-s="dotaznik">Dotazník</th><th data-s="eng">Zapojení</th>
      <th data-s="persona">Persona</th><th data-s="mesto">Město</th></tr></thead>
      <tbody id="tb"></tbody></table></div>
  </div>
  <div class="panel scr" id="scr"><div class="empty">Vyber člověka v tabulce vlevo.</div></div>
</div>
<script>
let DATA=[], ME='';
const PERSONA = {
  "Pěší nájemníci centra":{desc:"Mladí lidé bez auta a bez vlastního bytu, kteří chtějí Brno přívětivé pro chůzi a dostupné k životu.",talk:"dostupné nájemní bydlení a rozšiřování pěších zón",konkret:"městské nájemní byty a regulace krátkodobých pronájmů (Airbnb)",avoid:"bezpečnost a rodinná témata"},
  "Rodiče pro bezpečné čtvrti":{desc:"Rodiny s dětmi v severním a středním Brně; každodenní bezpečnost dětí na ulici je pro ně přednější než cokoli jiného.",talk:"bezpečné cesty dětí do školy a kapacity základních škol",konkret:"zklidnění dopravy a bezpečné přechody u škol",avoid:"abstraktní klima a aktivistická hesla"},
  "Nájemníci bez dětí pro pěší město":{desc:"Lidé ve středním věku bez dětí, bydlí v nájmu, chtějí Brno přívětivé pro chodce a dostupné k životu.",talk:"dostupné obecní byty a město pro pěší",konkret:"obecní nájemní byty a férové, transparentní přidělování",avoid:"„zelená“ rétorika a seniorská témata"},
  "Rodiče s nároky":{desc:"Rodiče ve středních letech; chtějí, aby Brno fungovalo pro rodiny – od pediatra po bezpečný chodník.",talk:"dostupnost dětských lékařů, bezpečné školní trasy a tábory od města",konkret:"pediatrická péče a bezpečné cesty do školy",avoid:"cyklo/pěší zóny jako hlavní téma a ideologii"},
  "Usazení měšťané bez dětí":{desc:"Ekonomicky zajištění Brňané středního věku; chtějí hezké a funkční město, ne spekulativní džungli.",talk:"aktivaci prázdných bytů, regulaci vizuálního smogu a kvalitu veřejného prostoru",konkret:"zdanění prázdných bytů a omezení reklamního smogu",avoid:"aktivismus, rodinná témata a cyklistiku"},
  "Spokojení vlastníci bez starosti":{desc:"Majitelé bytů a domů; v Brně se cítí bezpečně a bydlení jako problém nevidí. Persuadable – opatrně.",talk:"kvalitu parků a klima jako ochranu hodnoty nemovitosti",konkret:"revitalizaci zeleně a parků ve vaší čtvrti",avoid:"BYTOVOU KRIZI a nájemní politiku – to je odrazuje"},
  "":{desc:"",talk:"dostupné bydlení, dopravu a zeleň",konkret:"dostupné bydlení a kvalitní veřejný prostor",avoid:""}
};
function pdesc(p){return (PERSONA[p]&&PERSONA[p].desc)||"";}
function engBadge(e){const c=engClean(e);const cls=c==="high"?"eg-h":c==="medium"?"eg-m":"eg-l";
  const der=(e||"").indexOf("(")>-1;return '<span class="eg '+cls+'"'+(der?' title="odvozeno z vztahu (nevyplnil dotazník)"':'')+'>'+c+(der?'*':'')+'</span>';}
const truthy=v=>v==1||v===true||v==="1";
function vztahLabel(d){const dn=truthy(d.donor),m=truthy(d.member);return dn&&m?"dárce + člen":m?"člen":dn?"dříve dárce":"kontakt";}
function engClean(e){return (e||"").replace(/\s*\(.*\)/,"");}
function intro(d){const past=truthy(d.donor),mem=truthy(d.member);
  if(mem){
    let s="Dobrý den, tady "+ME+" z brněnských Zelených. Před komunálními volbami se ozýváme našim lidem – a vy jste náš člen(ka), tak volám rovnou vám.";
    if(past)s="Dobrý den, tady "+ME+" z brněnských Zelených. Před komunálními volbami se ozýváme našim lidem – jste náš člen(ka) a v minulosti jste nás i podpořil(a), za což díky. Tak volám rovnou vám.";
    return{say:s+" Máte teď chvilku?",tone:"Nejvřelejší – jsme jeden tým. Důvod hovoru je kampaň; členství/dar zmiň jen jako proč voláš jemu."};}
  if(past)return{say:"Dobrý den, tady "+ME+" z brněnských Zelených. Před komunálními volbami se ozýváme lidem, kteří nám fandí – vy jste nás v minulosti podpořil(a), za což díky, tak volám rovnou vám. Máte teď chvilku?",tone:"Reaktivace. Důvod = kampaň; dřívější dar je jen reference, proč voláš právě jemu (+ krátké díky). Po hovoru nech mluvit jeho."};
  return{say:"Dobrý den, tady "+ME+" z brněnských Zelených. Před komunálními volbami se ozýváme Brňanům, kterým není jedno, jak město vypadá – a vy jste mezi nimi. Máte teď chvilku?",tone:"Důvod = kampaň. Dotazník NEzmiňuj; jeho téma použij přirozeně až v dalším kroku."};}
// téma z dotazníku → konkrétní závazek z programu (aby „co chceme prosadit“ sedělo na jeho prioritu)
const TEMA={
 "bydlení":"opravit přes 1 500 prázdných obecních bytů a udělat z bydlení jasnou prioritu města",
 "parky a zeleň":"vysazovat a chránit stromy a kultivovat zanedbaná místa ve městě",
 "cyklodoprava":"bezpečnější a propojené ulice pro kola i pěší a zklidněné zóny",
 "tramvaje a MHD":"spolehlivou a kvalitní MHD",
 "nádraží":"kultivovat okolí hlavního nádraží a veřejný prostor kolem něj",
 "ZŠ":"kvalitní školy v dosahu domova a dost míst pro děti",
 "SŠ":"dostatečné kapacity středních škol",
 "vizuální smog":"zkrotit reklamní smog ve veřejném prostoru"
};
function middle(d){const p=PERSONA[d.persona]??PERSONA[""];
  if(truthy(d.dotaznik)&&d.t1){
    const temata="„"+d.t1+"“"+(d.t2?" a „"+d.t2+"“":"");
    const k=TEMA[d.t1];
    const say="Jedno z témat, které v Brně teď hodně řešíme, je "+d.t1+(d.t2?" a "+d.t2:"")+". "+(k?"Chceme "+k+".":"Přesně na tyhle věci se chceme zaměřit.")+" Je to něco, co vnímáte i vy?";
    return{say,talk:"jeho priority: "+d.t1+(d.t2?", "+d.t2:"")+" – použij jako přirozené téma, dotazník nezmiňuj",avoid:"",personalized:true};}
  return{say:"Hodně lidem v Brně jde o "+p.talk+". Chceme "+p.konkret+". Jak to vidíte vy?",talk:p.talk,avoid:p.avoid,personalized:false};}
function ask(d){const e=engClean(d.eng);const warm=truthy(d.donor)||truthy(d.member);
  let say,tone;
  if(warm){
    let primary=e==="high"?"pravidelným darem do voleb 339 Kč měsíčně – to je jako jedno předplatné streamovací služby jako je Netflix":e==="medium"?"pravidelným darem do voleb 199 Kč měsíčně":"jednorázovým darem 500 Kč";
    say="Před komunálními volbami rozjíždíme kampaň naplno a stojíme čistě na darech od lidí. Proto se ptám napřímo – podpořil(a) byste nás "+primary+"? Pravidelná podpora nám dává jistotu, se kterou se dá kampaň naplánovat.";
    tone="REAKTIVACE. Začni jedním číslem (ideálně měsíčním). Když souhlasí snadno, nadhoď vyšší tarif (599). Když zaváhá, nabídni nižší (199) nebo jednorázově. NEnabízej dvě čísla najednou.";}
  else if(truthy(d.dotaznik)){
    let primary=e==="low"?"jednorázově třeba 300 Kč":"jednorázově 500 Kč – nebo ještě líp pravidelným darem do voleb 199 Kč měsíčně";
    say="Celou kampaň platíme z malých darů od lidí, kterým na Brně záleží. Pomohl(a) byste nám "+primary+"?";
    tone="PRVNÍ dar. Volbu nech na něm.";}
  else{
    say="Kdybyste nás chtěl(a) před volbami podpořit, i drobnost pomáhá – klidně 200 Kč. Můžu vám poslat odkaz, ať to máte snadno, bez závazku.";
    tone="Měkká prosba, netlač.";}
  const how={high:"Sebevědomě a rovnou.",medium:"Napřed důvod, pak věcná prosba.",low:"Měkce, žádný tlak."};
  return{say,tone:tone+" Zapojení = "+d.eng+" → "+(how[e]||"")+" Po prosbě MLČ."};}

let curFilter='all',curSort='score',curDir=-1,curSel=null;
async function load(){
  const r=await fetch('?action=list'); const j=await r.json();
  if(!j.ok){document.getElementById('stat').textContent='Chyba načtení';return;}
  DATA=j.rows; ME=j.me;
  const a=document.getElementById('setme'); a.textContent='Volá: '+ME+' ✎';
  render();
}
// změna jména volajícího – uloží do cookie (čte ho server u zápisu hovoru)
document.getElementById('setme').addEventListener('click',e=>{
  e.preventDefault();
  const n=(prompt("Vaše jméno (volající):", ME==='neznámý'?'':ME)||'').trim();
  if(!n) return;
  document.cookie='lb_tf_caller='+encodeURIComponent(n)+';path=/;max-age='+(90*24*3600)+';samesite=Lax';
  location.reload();
});
function render(){
  const q=document.getElementById('q').value.trim().toLowerCase();
  let list=DATA.filter(d=>{
    if(['A','B','C'].includes(curFilter)&&d.prio!==curFilter)return false;
    if(curFilter==='todo'&&d.result)return false;
    if(q){const h=(d.jmeno+" "+d.mesto+" "+d.persona+" "+d.t1+" "+d.t2).toLowerCase();if(!h.includes(q))return false;}
    return true;});
  list.sort((a,b)=>{let va,vb;if(curSort==='vztah'){va=vztahLabel(a);vb=vztahLabel(b);}else{va=a[curSort];vb=b[curSort];}
    if(typeof va==='string'){va=va.toLowerCase();vb=(vb||'').toLowerCase();}return(va>vb?1:va<vb?-1:0)*curDir;});
  document.getElementById('tb').innerHTML=list.map(d=>`<tr data-id="${d.id}" class="${curSel==d.id?'sel':''} ${d.result?'done':''}">
    <td><span class="pr ${d.prio}">${d.prio}</span></td>
    <td>${d.jmeno}${d.result?' <span class="tag">'+d.result+(d.note?' – '+d.note:'')+(d.caller?' · '+d.caller:'')+'</span>':''}</td>
    <td>${d.score}</td><td>${vztahLabel(d)}</td>
    <td style="text-align:center">${truthy(d.dotaznik)?'✓':'<span class="tag">ne</span>'}</td>
    <td>${engBadge(d.eng)}</td>
    <td>${d.persona?'<span class="pcell" data-desc="'+pdesc(d.persona).replace(/"/g,'&quot;')+'">'+d.persona+'</span>':'<span class="tag">—</span>'}</td>
    <td>${d.mesto}</td></tr>`).join('');
  const done=DATA.filter(d=>d.result).length;
  document.getElementById('stat').textContent=list.length+" zobrazeno · "+done+" zpracováno · "+DATA.length+" celkem";
}
function showScript(id){curSel=id;render();const d=DATA.find(x=>x.id==id);
  const I=intro(d),M=middle(d),A=ask(d);const b=[];
  if(truthy(d.donor))b.push('<span class="badge don">dříve dárce</span>');
  if(truthy(d.member))b.push('<span class="badge mem">člen</span>');
  if(truthy(d.dotaznik))b.push('<span class="badge dot">vyplnil dotazník</span>');
  if(!truthy(d.donor)&&!truthy(d.member)&&!truthy(d.dotaznik))b.push('<span class="badge kon">studený kontakt</span>');
  const av=M.avoid?`<div class="avoid">⛔ <b>Nemluv o:</b> ${M.avoid}</div>`:'';
  const lg=d.result?`<div class="hint" style="margin-top:10px">✓ Naposledy: <b>${d.result}</b> ${d.caller?'('+d.caller+')':''} ${d.note?'– '+d.note:''} ${d.updated_at?'· '+d.updated_at:''}
    <button class="btn" style="margin-left:8px;padding:4px 9px;color:var(--warn);border-color:#e7c9bd" onclick="clearCall(${d.id})">✗ Zrušit příznak</button></div>`:'';
  document.getElementById('scr').innerHTML=`
    <div class="who">${d.jmeno}</div>
    <div class="meta">${d.persona||'bez persony'} · ${d.mesto||''} · skóre ${d.score} (${d.prio})</div>
    <a class="tel" href="tel:${(d.tel||'').replace(/\s/g,'')}">${d.tel||''}</a>
    <div class="meta" style="margin-top:4px">✉ ${d.email?'<a href="mailto:'+d.email+'">'+d.email+'</a>':'—'}</div>
    <div class="badges">${b.join('')}</div>
    <div class="step"><h3>1 · Úvod</h3><div class="say">${I.say}</div><div class="hint">💡 ${I.tone}</div></div>
    <div class="step"><h3>2 · Napojení na téma</h3><div class="say">${M.say}</div><div class="hint">✅ Mluv o: ${M.talk}</div>${av}</div>
    <div class="step"><h3>3 · Prosba o dar</h3><div class="say">${A.say}</div><div class="hint">💡 ${A.tone}</div></div>
    <div class="step"><h3>4 · Uzávěr + odkaz</h3>
      <div class="say"><b>ANO:</b> „Skvělé, moc děkuju! Pošlu vám odkaz hned teď, ať to máte snadno – chcete ho radši SMS, nebo na e‑mail?“</div>
      <div class="hint">🔁 <b>Pravidelný dar</b> (předplatné do voleb na lepší Brno): <b>lepsibrno.cz/?utm_source=telefon</b> — tarify 199 / 339 / 599 Kč měsíčně</div>
      <div class="hint">💸 <b>Jednorázový dar:</b> <b>dary.zeleni.cz/brno</b></div>
      <div class="hint">↳ Zopakuj, na čem jste se domluvili (částka, jednorázově/měsíčně) a odkaz pošli ideálně ještě během hovoru.</div>
      <div class="hint"><b>NE:</b> „Naprosto chápu, díky za čas. Můžu vám odkaz poslat aspoň pro případ, že si to rozmyslíte?“ – netlač, vztah je důležitější.</div></div>
    <details class="objs step"><summary>Časté námitky</summary>
      <p><b>Teď nemůžu/nemám.</b> → Chápu, pošlu odkaz, přispějete až se hodí – i 200 Kč pomůže.</p>
      <p><b>Na co to půjde?</b> → Na komunální kampaň v Brně – tisk, plakáty, akce. Žádní velcí sponzoři.</p>
      <p><b>Vždyť jsem už dal(a).</b> → Já vím a děkuju! Tohle je o dalším daru před volbami – bez tlaku.</p>
      <p><b>Kde máte moje číslo?</b> → Jste v naší databázi podporovatelů (dar v minulosti / dotazník). Když nechcete, hned to zařídím.</p>
    </details>
    <div class="log">
      <button class="btn ok" onclick="logCall(${d.id},'přislíbil')">✓ Přislíbil</button>
      <button class="btn cb" onclick="logCall(${d.id},'zavolat znovu')">↻ Zavolat znovu</button>
      <button class="btn no" onclick="logCall(${d.id},'odmítl')">✗ Odmítl</button>
      <button class="btn" onclick="logCall(${d.id},'nedovoláno')">… Nedovoláno</button>
    </div>${lg}`;
}
async function logCall(id,result){
  const note=prompt("Poznámka (částka, kdy zavolat…) – nepovinné:","")||"";
  await fetch('?action=save',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({id,result,note})});
  await load(); showScript(id);
}
async function clearCall(id){
  if(!confirm("Opravdu zrušit zaznamenaný status u tohoto kontaktu?")) return;
  await fetch('?action=clear',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({id})});
  await load(); showScript(id);
}
document.getElementById('q').addEventListener('input',render);
document.querySelectorAll('.chip').forEach(c=>c.onclick=()=>{document.querySelectorAll('.chip').forEach(x=>x.classList.remove('on'));c.classList.add('on');curFilter=c.dataset.f;render();});
document.querySelectorAll('th').forEach(t=>t.onclick=()=>{const s=t.dataset.s;if(s===curSort)curDir*=-1;else{curSort=s;curDir=(s==='jmeno'||s==='mesto'||s==='persona')?1:-1;}render();});
document.getElementById('tb').addEventListener('click',e=>{const tr=e.target.closest('tr');if(tr)showScript(+tr.dataset.id);});
// plovoucí tooltip s popisem persony (spolehlivější než nativní title)
(function(){const tip=document.getElementById('ptip');const tb=document.getElementById('tb');
  tb.addEventListener('mouseover',e=>{const el=e.target.closest('.pcell');if(!el)return;
    const t=el.getAttribute('data-desc');if(!t)return;
    tip.textContent=t;tip.style.display='block';
    const r=el.getBoundingClientRect();
    tip.style.left=Math.max(8,Math.min(r.left,window.innerWidth-310))+'px';
    tip.style.top=(r.bottom+6>window.innerHeight-90?r.top-tip.offsetHeight-6:r.bottom+6)+'px';});
  tb.addEventListener('mouseout',e=>{if(e.target.closest('.pcell'))tip.style.display='none';});
})();
load();
</script>
</body></html>