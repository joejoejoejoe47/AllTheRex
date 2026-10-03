<?php
require_once __DIR__ . '/config.php';

$pdo = get_db();

// Latest price per commodity
$commodities = $pdo->query("
    SELECT c.*, cp.price_per_basket_unit, cp.fetched_at
    FROM commodities c
    LEFT JOIN commodity_prices cp ON cp.id = (
        SELECT id FROM commodity_prices
        WHERE commodity_id = c.id
        ORDER BY fetched_at DESC LIMIT 1
    )
    WHERE c.active = 1
    ORDER BY c.sort_order
")->fetchAll();

$umuValue = 0.0;
$rows = [];
$latestFetch = null;
foreach ($commodities as $c) {
    $price = (float) ($c['price_per_basket_unit'] ?? 0);
    $contribution = $price * (float) $c['basket_quantity'];
    $umuValue += $contribution;
    if ($c['fetched_at'] && (!$latestFetch || $c['fetched_at'] > $latestFetch)) {
        $latestFetch = $c['fetched_at'];
    }
    $rows[] = [
        'slug'         => $c['slug'],
        'name'         => $c['display_name'],
        'qty'          => $c['basket_quantity'],
        'unit'         => $c['basket_unit'],
        'price'        => $price,
        'contribution' => $contribution,
    ];
}

// History for the chart (last 200 points)
$history = $pdo->query("
    SELECT value_usd, computed_at FROM umu_value_history
    ORDER BY computed_at DESC LIMIT 200
")->fetchAll();
$history = array_reverse($history);

$chartLabels = array_map(fn($h) => $h['computed_at'], $history);
$chartValues = array_map(fn($h) => (float) $h['value_usd'], $history);

$hasData = $umuValue > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>UMU — Ur Monetary Unit Basket Tracker</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Zilla+Slab:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<style>
  :root{
    --bg:#14171A;
    --panel:#1B1F23;
    --panel-line:#2A2F34;
    --ink:#EDE7D9;
    --muted:#8B9199;
    --brass:#C9A227;
    --brass-dim:#8A7325;
    --verdigris:#4E9F8B;
    --rust:#B5533C;
  }
  *{box-sizing:border-box;}
  body{
    margin:0;
    background:var(--bg);
    color:var(--ink);
    font-family:'Inter',sans-serif;
    -webkit-font-smoothing:antialiased;
  }
  .wrap{max-width:920px;margin:0 auto;padding:56px 24px 80px;}
  .eyebrow{
    font-family:'IBM Plex Mono',monospace;
    font-size:12px;
    letter-spacing:.14em;
    text-transform:uppercase;
    color:var(--brass);
    margin-bottom:10px;
  }
  h1{
    font-family:'Zilla Slab',serif;
    font-weight:600;
    font-size:20px;
    margin:0 0 40px;
    color:var(--muted);
    letter-spacing:.01em;
  }
  .hero-value{
    font-family:'Zilla Slab',serif;
    font-weight:700;
    font-size:clamp(48px,9vw,92px);
    line-height:1;
    margin:0;
    letter-spacing:-.01em;
  }
  .hero-sub{
    font-family:'IBM Plex Mono',monospace;
    color:var(--muted);
    font-size:13px;
    margin-top:14px;
  }
  .basket-formula{
    font-family:'IBM Plex Mono',monospace;
    font-size:12.5px;
    color:var(--muted);
    margin-top:6px;
    line-height:1.7;
  }

  /* composition dial — the signature element */
  .dial{
    display:flex;
    width:100%;
    height:14px;
    border-radius:7px;
    overflow:hidden;
    margin:36px 0 10px;
    border:1px solid var(--panel-line);
  }
  .dial-seg{height:100%;transition:filter .15s;}
  .dial-seg:hover{filter:brightness(1.25);}
  .dial-legend{
    display:flex;
    flex-wrap:wrap;
    gap:14px 22px;
    font-family:'IBM Plex Mono',monospace;
    font-size:11.5px;
    color:var(--muted);
    margin-bottom:44px;
  }
  .dial-legend span{display:inline-flex;align-items:center;gap:6px;}
  .swatch{width:9px;height:9px;border-radius:2px;display:inline-block;}

  .panel{
    background:var(--panel);
    border:1px solid var(--panel-line);
    border-radius:10px;
    padding:28px;
    margin-bottom:28px;
  }
  .panel h2{
    font-family:'Zilla Slab',serif;
    font-size:16px;
    font-weight:600;
    margin:0 0 20px;
    color:var(--ink);
  }
  table{width:100%;border-collapse:collapse;}
  th{
    text-align:left;
    font-family:'IBM Plex Mono',monospace;
    font-size:11px;
    letter-spacing:.08em;
    text-transform:uppercase;
    color:var(--muted);
    font-weight:500;
    padding:0 12px 10px 0;
    border-bottom:1px solid var(--panel-line);
  }
  td{
    padding:12px 12px 12px 0;
    border-bottom:1px solid var(--panel-line);
    font-size:14px;
  }
  tr:last-child td{border-bottom:none;}
  td.num, th.num{text-align:right;font-family:'IBM Plex Mono',monospace;}
  .commodity-name{font-weight:500;}
  .commodity-unit{color:var(--muted);font-size:12px;}

  #chart-wrap{height:260px;}

  .note{
    font-size:12.5px;
    color:var(--muted);
    line-height:1.6;
    margin-top:6px;
  }
  .warn{
    background:#2A1F16;
    border:1px solid var(--brass-dim);
    color:var(--brass);
    padding:16px 20px;
    border-radius:8px;
    font-family:'IBM Plex Mono',monospace;
    font-size:13px;
    margin-bottom:28px;
  }
  footer{
    font-family:'IBM Plex Mono',monospace;
    font-size:11.5px;
    color:var(--muted);
    margin-top:40px;
    border-top:1px solid var(--panel-line);
    padding-top:20px;
  }
</style>
</head>
<body>
<div class="wrap">

  <div class="eyebrow">Ur Monetary Unit · Basket Ledger</div>
  <h1>alltherex.com/urbank — a fully-backed commodity basket, priced live</h1>

  <?php if (!$hasData): ?>
    <div class="warn">
      No prices recorded yet. Run <code>fetch_prices.php</code> once (via cron or CLI) to populate the database, then reload this page.
    </div>
  <?php endif; ?>

  <p class="hero-value">1 UMU = $<?= number_format($umuValue, 4) ?></p>
  <p class="hero-sub">
    <?= $latestFetch ? 'Last priced ' . date('M j, Y g:i A T', strtotime($latestFetch)) : 'Awaiting first price fetch' ?>
  </p>
  <p class="basket-formula">
    0.00025 troy oz gold · 0.02 troy oz silver · 0.02 bbl WTI crude · 0.3 MMBtu natural gas ·
    0.0001 t copper · 0.0004 t aluminum · 0.004 t wheat · 0.005 t corn ·
    1.2 lb cotton · 6 lb sugar · 0.3 lb coffee
  </p>

  <?php
    $colors = ['#C9A227','#B8B8B8','#3A3F44','#4E9F8B','#8A6A4A','#9AA5AD','#7A8F3F','#D2B65A','#C77B4A','#B5533C','#6B5B95'];
    $i = 0;
  ?>
  <div class="dial">
    <?php foreach ($rows as $r):
        $pct = $umuValue > 0 ? ($r['contribution'] / $umuValue * 100) : 0;
        $color = $colors[$i % count($colors)]; $i++;
    ?>
      <div class="dial-seg" style="width:<?= max($pct,0.5) ?>%;background:<?= $color ?>" title="<?= htmlspecialchars($r['name']) ?>: <?= number_format($pct,1) ?>%"></div>
    <?php endforeach; ?>
  </div>
  <div class="dial-legend">
    <?php $i = 0; foreach ($rows as $r):
        $pct = $umuValue > 0 ? ($r['contribution'] / $umuValue * 100) : 0;
        $color = $colors[$i % count($colors)]; $i++;
    ?>
      <span><span class="swatch" style="background:<?= $color ?>"></span><?= htmlspecialchars($r['name']) ?> · <?= number_format($pct,1) ?>%</span>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h2>Basket composition</h2>
    <table>
      <thead>
        <tr>
          <th>Commodity</th>
          <th class="num">Quantity / UMU</th>
          <th class="num">Price / unit</th>
          <th class="num">Contribution</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="commodity-name"><?= htmlspecialchars($r['name']) ?></td>
          <td class="num"><?= rtrim(rtrim(number_format($r['qty'],3),'0'),'.') ?> <span class="commodity-unit"><?= htmlspecialchars($r['unit']) ?></span></td>
          <td class="num">$<?= number_format($r['price'], 4) ?></td>
          <td class="num">$<?= number_format($r['contribution'], 4) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="note">All prices come from Alpha Vantage. Gold, silver, WTI crude, and natural gas update daily; copper, aluminum, wheat, corn, cotton, sugar, and coffee are IMF/FRED-sourced global price indices that update monthly.</p>
  </div>

  <div class="panel">
    <h2>UMU value in USD over time</h2>
    <div id="chart-wrap"><canvas id="umuChart"></canvas></div>
  </div>

  <footer>
    UMU is a proposed fully-backed commodity currency. Prices shown are derived from futures markets and are illustrative, not an offer or redemption guarantee.
  </footer>
</div>

<script>
const ctx = document.getElementById('umuChart');
new Chart(ctx, {
  type: 'line',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [{
      label: 'UMU (USD)',
      data: <?= json_encode($chartValues) ?>,
      borderColor: '#C9A227',
      backgroundColor: 'rgba(201,162,39,0.08)',
      borderWidth: 2,
      pointRadius: 0,
      tension: 0.25,
      fill: true,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { ticks: { color: '#8B9199', maxTicksLimit: 6 }, grid: { color: '#2A2F34' } },
      y: { ticks: { color: '#8B9199' }, grid: { color: '#2A2F34' } }
    }
  }
});
</script>
</body>
</html>