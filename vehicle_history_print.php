<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

$vid = (int)($_GET['vehicle_id'] ?? 0);
$st = $pdo->prepare('SELECT v.*, c.name AS customer, c.phone FROM vehicles v JOIN customers c ON c.id=v.customer_id WHERE v.id=?');
$st->execute([$vid]);
$vehicle = $st->fetch();
if (!$vehicle) { flash('Vehicle not found.', 'error'); redirect('vehicles.php'); }

$st = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM invoices i WHERE i.service_id = s.id) AS has_invoice FROM services s WHERE s.vehicle_id=? ORDER BY s.service_date DESC');
$st->execute([$vid]);
$services = $st->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Service History - <?= e($vehicle['reg_no']) ?></title>
<style>
  body { font-family: Arial, sans-serif; font-size: 12px; margin: 30px; }
  h1 { font-size: 18px; } h2 { font-size: 14px; margin: 4px 0; }
  table { width: 100%; border-collapse: collapse; margin-top: 15px; }
  th, td { border: 1px solid #999; padding: 6px 8px; text-align: left; vertical-align: top; }
  th { background: #eee; }
  .meta { margin-bottom: 15px; }
  @media print { .no-print { display: none; } }
</style>
</head>
<body>
<button class="no-print" onclick="window.print()" style="padding:8px 16px;margin-bottom:15px;cursor:pointer;">Print / Save as PDF</button>
<h1>Vehicle Service History</h1>
<div class="meta">
  <h2>Owner: <?= e($vehicle['customer']) ?> (<?= e($vehicle['phone']) ?>)</h2>
  <h2>Vehicle: <?= e($vehicle['make']) ?> <?= e($vehicle['model']) ?> <?= e($vehicle['year']) ?> — <?= e($vehicle['reg_no']) ?></h2>
  <h2>Current Mileage: <?= number_format((int)$vehicle['mileage']) ?> km</h2>
</div>
<table>
  <thead><tr><th>Date</th><th>Mileage</th><th>Description</th><th>Labor</th><th>Status</th><th>Next Service</th><th>Invoice</th></tr></thead>
  <tbody>
  <?php foreach ($services as $s): ?>
    <tr>
      <td><?= e($s['service_date']) ?></td>
      <td><?= number_format((int)$s['mileage']) ?> km</td>
      <td><?= nl2br(e($s['description'])) ?><?= $s['notes'] ? '<br><small><em>Note: ' . e($s['notes']) . '</em></small>' : '' ?></td>
      <td><?= money($s['labor_charge']) ?></td>
      <td><?= e($s['status']) ?></td>
      <td><?= $s['next_service_date'] ? e($s['next_service_date']) : '-' ?><?= $s['next_service_mileage'] ? '<br>' . number_format((int)$s['next_service_mileage']) . ' km' : '' ?></td>
      <td><?= $s['has_invoice'] ? 'Created' : 'Not created' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$services): ?><tr><td colspan="7" style="text-align:center;color:#888;">No service records.</td></tr><?php endif; ?>
  </tbody>
</table>
</body>
</html>
<?php exit; ?>