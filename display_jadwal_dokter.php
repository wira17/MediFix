<?php
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

$hari_ini = strtoupper(date('l'));
$mapHari  = [
    'MONDAY'=>'SENIN','TUESDAY'=>'SELASA','WEDNESDAY'=>'RABU',
    'THURSDAY'=>'KAMIS','FRIDAY'=>'JUMAT','SATURDAY'=>'SABTU','SUNDAY'=>'MINGGU'
];
$hari_indo = $mapHari[$hari_ini] ?? 'SENIN';

$sql = "
    SELECT j.kd_dokter, d.nm_dokter, p.nm_poli,
           j.hari_kerja, j.jam_mulai, j.jam_selesai, j.kuota
    FROM jadwal j
    INNER JOIN dokter     d ON j.kd_dokter = d.kd_dokter
    INNER JOIN poliklinik p ON j.kd_poli   = p.kd_poli
    WHERE j.hari_kerja = '$hari_indo'
    ORDER BY p.nm_poli, d.nm_dokter
";
$jadwal    = $pdo_simrs->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$jumlah_pasien = [];
$tgl = date('Y-m-d');
$sq  = $pdo_simrs->prepare("SELECT kd_dokter, COUNT(no_rawat) AS total FROM reg_periksa WHERE tgl_registrasi=? GROUP BY kd_dokter");
$sq->execute([$tgl]);
foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $jumlah_pasien[$r['kd_dokter']] = (int)$r['total'];
}

$pages     = array_chunk($jadwal, 8);
$totalPage = count($pages);

// Hanya tentukan jumlah KOLOM — baris dibiarkan mengalir natural (auto-rows)
$cols_map = [1=>1, 2=>2, 3=>3, 4=>4, 5=>5, 6=>3, 7=>4, 8=>4];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jadwal Dokter — RS Permata Hati</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg:      #070d1a;
  --card:    #0f1e33;
  --border:  rgba(255,255,255,0.07);
  --gold:    #f0b429;
  --teal:    #06b6d4;
  --teal2:   #22d3ee;
  --emerald: #10b981;
  --rose:    #f43f5e;
  --amber:   #f59e0b;
  --text:    #e2eaf6;
  --muted:   #6b7f9a;
  --hdr:     74px;
  --ftr:     54px;
  --pad:     18px;
  --gap:     14px;
  --card-h:  240px;   /* ← tinggi kartu FIXED, tidak pernah berubah */
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden;background:var(--bg);font-family:'DM Sans',sans-serif;color:var(--text)}

body::before{
  content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:linear-gradient(rgba(6,182,212,.025) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(6,182,212,.025) 1px,transparent 1px);
  background-size:52px 52px;
}
.g1{position:fixed;width:600px;height:600px;top:-250px;left:-250px;border-radius:50%;
    background:radial-gradient(circle,rgba(6,182,212,.07) 0%,transparent 70%);pointer-events:none;z-index:0}
.g2{position:fixed;width:500px;height:500px;bottom:-200px;right:-150px;border-radius:50%;
    background:radial-gradient(circle,rgba(240,180,41,.05) 0%,transparent 70%);pointer-events:none;z-index:0}

/* HEADER */
.header{
  position:relative;z-index:100;height:var(--hdr);
  display:flex;align-items:center;justify-content:space-between;padding:0 24px;
  background:rgba(9,17,31,.97);border-bottom:1px solid rgba(6,182,212,.18);
  box-shadow:0 4px 30px rgba(0,0,0,.5);
}
.header::after{
  content:'';position:absolute;bottom:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,var(--teal) 30%,var(--gold) 70%,transparent);opacity:.5;
}
.hl{display:flex;align-items:center;gap:14px}
.hlogo{width:44px;height:44px;background:linear-gradient(135deg,var(--teal),#0891b2);
       border-radius:12px;display:flex;align-items:center;justify-content:center;
       font-size:20px;box-shadow:0 0 18px rgba(6,182,212,.35);flex-shrink:0}
.ht h1{font-family:'Syne',sans-serif;font-size:19px;font-weight:800;
        background:linear-gradient(90deg,#fff 55%,var(--teal2));
        -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.ht p{font-size:11px;color:var(--muted);font-weight:500;margin-top:1px}
.hr{display:flex;align-items:center;gap:14px}
.live-badge{display:flex;align-items:center;gap:7px;background:rgba(6,182,212,.08);
            border:1px solid rgba(6,182,212,.2);padding:7px 14px;border-radius:50px;
            font-size:12px;font-weight:600;color:var(--teal2);letter-spacing:.4px}
.live-dot{width:6px;height:6px;border-radius:50%;background:var(--teal);
          box-shadow:0 0 7px var(--teal);animation:pdot 2s infinite}
@keyframes pdot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(1.4)}}
.clock{font-family:'Syne',sans-serif;font-size:21px;font-weight:700;color:var(--gold);
       letter-spacing:2px;text-shadow:0 0 18px rgba(240,180,41,.4)}

/* STAGE */
.stage{
  position:relative;z-index:10;
  height:calc(100vh - var(--hdr) - var(--ftr));
  overflow:hidden;
}

/* SLIDE
   - hanya mengatur padding & gap
   - konten (kartu) ditumpuk dari atas (align-content: start)
   - tidak ada grid-template-rows → kartu pakai tinggi sendiri
*/
.slide{
  position:absolute;inset:0;
  padding:var(--pad);
  display:grid;
  /* grid-template-columns di-set via inline style */
  grid-auto-rows: var(--card-h);   /* ← setiap baris selalu setinggi --card-h */
  gap:var(--gap);
  align-content:start;             /* ← kartu mulai dari atas, tidak stretch */
  opacity:0;transform:translateX(60px);
  transition:opacity .5s cubic-bezier(.4,0,.2,1),transform .5s cubic-bezier(.4,0,.2,1);
  pointer-events:none;overflow:hidden;
}
.slide.active{opacity:1;transform:translateX(0);pointer-events:auto}
.slide.exit{opacity:0;transform:translateX(-60px)}

/* DOC CARD — tinggi dikontrol oleh grid-auto-rows, bukan kartu itu sendiri */
.doc-card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:16px;
  overflow:hidden;
  display:flex;flex-direction:column;
  position:relative;
  transition:transform .3s,box-shadow .3s;
}
.doc-card::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--teal),var(--gold));
  opacity:0;transition:opacity .3s;
}
.doc-card:hover{transform:translateY(-3px);box-shadow:0 10px 35px rgba(0,0,0,.45)}
.doc-card:hover::before{opacity:1}

.poli-strip{
  padding:8px 14px;flex-shrink:0;
  background:linear-gradient(135deg,rgba(6,182,212,.12),rgba(6,182,212,.04));
  border-bottom:1px solid rgba(6,182,212,.13);
  display:flex;align-items:center;gap:8px;
}
.poli-strip .pico{font-size:12px}
.poli-strip span.pnm{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--teal2)}

.cbody{flex:1;padding:10px 14px;display:flex;flex-direction:column;gap:8px;overflow:hidden;min-height:0}
.doc-name{
  font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;line-height:1.3;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
}

.stats{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;margin-top:auto}
.stat{border-radius:9px;padding:7px 10px;display:flex;flex-direction:column;gap:2px}
.stat.jam   {background:rgba(6,182,212,.08); border:1px solid rgba(6,182,212,.14)}
.stat.kuota {background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.14)}
.stat.reg   {background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.14)}
.stat.sisa  {background:rgba(244,63,94,.08); border:1px solid rgba(244,63,94,.14)}
.slbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)}
.sval{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;line-height:1.1}
.stat.jam  .sval{color:var(--teal2);font-family:'DM Sans',sans-serif;font-size:11px;font-weight:600}
.stat.kuota .sval{color:var(--emerald)}
.stat.reg   .sval{color:var(--amber)}
.stat.sisa  .sval{color:var(--rose)}
.kbar{height:3px;border-radius:2px;background:rgba(255,255,255,.06);margin-top:3px;overflow:hidden}
.kfill{height:100%;border-radius:2px;background:linear-gradient(90deg,var(--emerald),var(--teal))}

/* FOOTER */
.footer{
  position:relative;z-index:100;height:var(--ftr);
  background:rgba(9,17,31,.97);border-top:1px solid rgba(6,182,212,.15);
  display:flex;align-items:center;overflow:hidden;
}
.footer::before{
  content:'';position:absolute;top:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,var(--teal) 30%,var(--gold) 70%,transparent);opacity:.4;
}
.fside{flex-shrink:0;padding:0 18px;font-size:11px;font-weight:700;
       text-transform:uppercase;letter-spacing:.5px;white-space:nowrap}
.fside.left{color:var(--teal);border-right:1px solid var(--border)}
.fside.right{color:var(--gold);border-left:1px solid var(--border)}
.mwrap{flex:1;overflow:hidden;display:flex;align-items:center}
.mtrack{display:flex;gap:56px;white-space:nowrap;animation:scroll 32s linear infinite}
@keyframes scroll{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}
.mitem{display:flex;align-items:center;gap:7px;font-size:13px;color:#7a96b8;font-weight:500;flex-shrink:0}
.mitem .mi{color:var(--gold)}
.page-dots{display:flex;gap:5px;align-items:center}
.dot{width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.12);transition:all .4s}
.dot.on{background:var(--gold);box-shadow:0 0 8px var(--gold);width:16px;border-radius:3px}
.pgnum{font-size:11px;color:var(--muted);font-weight:600;margin-right:6px}
.pbar{position:absolute;bottom:0;left:0;height:2px;
      background:linear-gradient(90deg,var(--teal),var(--gold));width:0%}

/* Empty */
.empty{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;color:var(--muted);z-index:10}
.ei{width:70px;height:70px;background:rgba(6,182,212,.06);border:1px solid rgba(6,182,212,.15);
    border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px}
.empty h2{font-family:'Syne',sans-serif;font-size:20px;color:var(--text)}
</style>
</head>
<body>
<div class="g1"></div><div class="g2"></div>

<!-- HEADER -->
<div class="header">
  <div class="hl">
    <div class="hlogo">🏥</div>
    <div class="ht">
      <h1>Jadwal Dokter Hari Ini</h1>
      <p><?= ucfirst(strtolower($hari_indo)) ?>, <?= date('d F Y') ?></p>
    </div>
  </div>
  <div class="hr">
    <div class="live-badge"><span class="live-dot"></span>LIVE UPDATE</div>
    <div class="clock" id="clock">00:00:00</div>
  </div>
</div>

<!-- STAGE -->
<div class="stage">
<?php if (empty($jadwal)): ?>
  <div class="empty">
    <div class="ei">📅</div>
    <h2>Tidak Ada Jadwal</h2>
    <p>Tidak ada jadwal dokter untuk hari <?= $hari_indo ?></p>
  </div>
<?php else:
  foreach ($pages as $pi => $page):
    $cnt  = count($page);
    $cols = $cols_map[$cnt] ?? 4;
?>
  <div class="slide <?= $pi===0?'active':'' ?>"
       style="grid-template-columns:repeat(<?= $cols ?>,1fr);">
    <?php foreach ($page as $j):
      $pasien = $jumlah_pasien[$j['kd_dokter']] ?? 0;
      $kuota  = max(1,(int)$j['kuota']);
      $sisa   = max(0,$kuota-$pasien);
      $pct    = min(100,round($pasien/$kuota*100));
    ?>
    <div class="doc-card">
      <div class="poli-strip">
        <span class="pico">🏨</span>
        <span class="pnm"><?= htmlspecialchars($j['nm_poli']) ?></span>
      </div>
      <div class="cbody">
        <div class="doc-name"><?= htmlspecialchars($j['nm_dokter']) ?></div>
        <div class="stats">
          <div class="stat jam">
            <div class="slbl">⏰ Jam Praktik</div>
            <div class="sval"><?= substr($j['jam_mulai'],0,5).' – '.substr($j['jam_selesai'],0,5) ?></div>
          </div>
          <div class="stat kuota">
            <div class="slbl">👥 Kuota</div>
            <div class="sval"><?= $kuota ?></div>
            <div class="kbar"><div class="kfill" style="width:<?= $pct ?>%"></div></div>
          </div>
          <div class="stat reg">
            <div class="slbl">✅ Terdaftar</div>
            <div class="sval"><?= $pasien ?></div>
          </div>
          <div class="stat sisa">
            <div class="slbl">⏳ Sisa Kuota</div>
            <div class="sval"><?= $sisa ?></div>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; endif; ?>
</div>

<!-- FOOTER -->
<div class="footer">
  <div class="fside left">🏥 RS Permata Hati</div>
  <div class="mwrap">
    <div class="mtrack">
      <?php for($x=0;$x<2;$x++): ?>
      <div class="mitem"><span class="mi">✦</span> Jadwal diperbarui otomatis dari SIMRS</div>
      <div class="mitem"><span class="mi">❤️</span> Selamat datang, semoga lekas sembuh</div>
      <div class="mitem"><span class="mi">✦</span> Mohon hadir 15 menit sebelum waktu pemeriksaan</div>
      <div class="mitem"><span class="mi">📋</span> Bawa kartu berobat dan dokumen pendukung</div>
      <div class="mitem"><span class="mi">✦</span> <?= date('d F Y') ?></div>
      <?php endfor; ?>
    </div>
  </div>
  <?php if ($totalPage > 1): ?>
  <div class="fside right" style="display:flex;align-items:center;gap:10px;">
    <span class="pgnum">Hal <span id="pgNow">1</span>/<?= $totalPage ?></span>
    <div class="page-dots">
      <?php for($i=0;$i<$totalPage;$i++): ?>
      <div class="dot <?= $i===0?'on':'' ?>"></div>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
  <div class="pbar" id="pbar"></div>
</div>

<script>
(function tick(){
  const n=new Date();
  document.getElementById('clock').textContent=
    String(n.getHours()).padStart(2,'0')+':'+
    String(n.getMinutes()).padStart(2,'0')+':'+
    String(n.getSeconds()).padStart(2,'0');
  setTimeout(tick,1000);
})();

const DUR=12000, slides=document.querySelectorAll('.slide'),
      dots=document.querySelectorAll('.dot'),
      pgNow=document.getElementById('pgNow'),
      pbar=document.getElementById('pbar');
let cur=0,t;

function goTo(next){
  slides[cur].classList.remove('active');
  slides[cur].classList.add('exit');
  const prev=cur;
  setTimeout(()=>slides[prev].classList.remove('exit'),600);
  cur=next%slides.length;
  slides[cur].classList.add('active');
  dots.forEach(d=>d.classList.remove('on'));
  if(dots[cur]) dots[cur].classList.add('on');
  if(pgNow) pgNow.textContent=cur+1;
  startBar();
}

function startBar(){
  clearTimeout(t);
  pbar.style.transition='none'; pbar.style.width='0%';
  requestAnimationFrame(()=>{
    pbar.style.transition=`width ${DUR}ms linear`;
    pbar.style.width='100%';
  });
  if(slides.length>1) t=setTimeout(()=>goTo(cur+1),DUR);
}

startBar();
setTimeout(()=>location.reload(), 5*60*1000);
</script>
</body>
</html>