<?php
require_once __DIR__ . '/config.php';
session_start();

$pdo = get_db();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['password'] ?? '') === ADMIN_PASSWORD) {
        $_SESSION['urbank_admin'] = true;
    }
    if (!empty($_SESSION['urbank_admin']) && isset($_POST['price_per_gallon'])) {
        $price = (float) $_POST['price_per_gallon'];
        if ($price > 0) {
            $stmt = $pdo->prepare("UPDATE commodities SET manual_price_usd = :p WHERE slug = 'water'");
            $stmt->execute([':p' => $price]);
            $message = 'Water price updated to $' . number_format($price, 4) . ' / gallon. It will apply on the next fetch_prices.php run.';
        }
    }
}

$authed = !empty($_SESSION['urbank_admin']);
$current = $pdo->query("SELECT manual_price_usd FROM commodities WHERE slug = 'water'")->fetchColumn();
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>UMU — Water price admin</title>
<style>
  body{background:#14171A;color:#EDE7D9;font-family:Inter,sans-serif;max-width:480px;margin:60px auto;padding:0 20px;}
  input{width:100%;padding:10px;margin:8px 0 16px;background:#1B1F23;border:1px solid #2A2F34;color:#EDE7D9;border-radius:6px;font-size:14px;}
  button{padding:10px 18px;background:#C9A227;border:none;border-radius:6px;color:#14171A;font-weight:600;cursor:pointer;}
  .msg{color:#4E9F8B;font-size:13px;margin-bottom:16px;}
  .cur{color:#8B9199;font-size:13px;margin-bottom:20px;}
</style></head><body>
<h2>UMU — manual water price</h2>
<p class="cur">Current: $<?= number_format((float)$current, 4) ?> / gallon</p>
<?php if ($message): ?><p class="msg"><?= htmlspecialchars($message) ?></p><?php endif; ?>
<form method="post">
  <?php if (!$authed): ?>
    <label>Admin password</label>
    <input type="password" name="password" required>
  <?php endif; ?>
  <label>New price per gallon (USD)</label>
  <input type="number" step="0.0001" name="price_per_gallon" required>
  <button type="submit">Update</button>
</form>
</body></html>
