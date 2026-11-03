<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT i.*, v.reg_no, v.make, v.model, v.year, c.name AS customer, c.phone, c.address
  FROM invoices i JOIN vehicles v ON v.id=i.vehicle_id JOIN customers c ON c.id=v.customer_id WHERE i.id=?');
$st->execute([$id]);
$inv = $st->fetch();
if (!$inv) { flash('Invoice not found.', 'error'); redirect('invoices.php'); }
$items = $pdo->prepare('SELECT ii.*, p.name, p.part_no FROM invoice_items ii JOIN parts p ON p.id=ii.part_id WHERE ii.invoice_id=?');
$items->execute([$id]);
$items = $items->fetchAll();
$payments = $pdo->prepare('SELECT * FROM payments WHERE invoice_id=? ORDER BY payment_date');
$payments->execute([$id]);
$payments = $payments->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= e($inv['invoice_no']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="bg-gray-200 p-6">
<div class="max-w-3xl mx-auto bg-white shadow-lg p-8" id="printArea">
  <div class="flex justify-between items-start border-b pb-5">
    <div>
      <div class="text-2xl font-bold"><i class="fa-solid fa-car-side text-blue-600"></i> <?= COMPANY_NAME ?></div>
      <div class="text-sm text-gray-500">Vehicle Service & Repair</div>
    </div>
    <div class="text-right">
      <div class="text-xl font-bold text-gray-700">INVOICE</div>
      <div class="text-sm text-gray-500"><?= e($inv['invoice_no']) ?></div>
      <div class="text-sm text-gray-500"><?= e($inv['invoice_date']) ?></div>
    </div>
  </div>

  <div class="grid grid-cols-2 gap-6 py-5 text-sm">
    <div>
      <div class="text-gray-400 uppercase text-xs mb-1">Billed To</div>
      <div class="font-semibold"><?= e($inv['customer']) ?></div>
      <div><?= e($inv['phone']) ?></div>
      <div class="text-gray-500"><?= nl2br(e($inv['address'])) ?></div>
    </div>
    <div class="text-right">
      <div class="text-gray-400 uppercase text-xs mb-1">Vehicle</div>
      <div class="font-semibold"><?= e($inv['reg_no']) ?></div>
      <div class="text-gray-500"><?= e($inv['make']) ?> <?= e($inv['model']) ?> <?= e($inv['year']) ?></div>
    </div>
  </div>

  <table class="w-full text-sm mb-4">
    <thead><tr class="bg-gray-100 text-left">
      <th class="px-3 py-2">Description</th><th class="px-3 py-2 text-right">Qty</th><th class="px-3 py-2 text-right">Unit Price</th><th class="px-3 py-2 text-right">Amount</th>
    </tr></thead>
    <tbody>
      <tr class="border-b"><td class="px-3 py-2">Labor / Service charges</td><td class="px-3 py-2 text-right">1</td><td class="px-3 py-2 text-right"><?= number_format($inv['labor_total'],2) ?></td><td class="px-3 py-2 text-right"><?= number_format($inv['labor_total'],2) ?></td></tr>
      <?php foreach ($items as $it): ?>
      <tr class="border-b">
        <td class="px-3 py-2"><?= e($it['name']) ?> <span class="text-gray-400 text-xs">(<?= e($it['part_no']) ?>)</span></td>
        <td class="px-3 py-2 text-right"><?= (int)$it['quantity'] ?></td>
        <td class="px-3 py-2 text-right"><?= number_format($it['unit_price'],2) ?></td>
        <td class="px-3 py-2 text-right"><?= number_format($it['quantity'] * $it['unit_price'],2) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="flex justify-end">
    <div class="w-64 text-sm space-y-1">
      <div class="flex justify-between"><span class="text-gray-500">Parts subtotal</span><span><?= number_format($inv['parts_total'],2) ?></span></div>
      <div class="flex justify-between"><span class="text-gray-500">Labor</span><span><?= number_format($inv['labor_total'],2) ?></span></div>
      <div class="flex justify-between"><span class="text-gray-500">Discount</span><span>-<?= number_format($inv['discount'],2) ?></span></div>
      <div class="flex justify-between"><span class="text-gray-500">Tax</span><span><?= number_format($inv['tax'],2) ?></span></div>
      <div class="flex justify-between font-bold text-lg border-t pt-1"><span>Grand Total</span><span><?= CURRENCY ?> <?= number_format($inv['grand_total'],2) ?></span></div>
      <div class="flex justify-between text-green-600"><span>Paid</span><span><?= number_format($inv['paid_amount'],2) ?></span></div>
      <div class="flex justify-between font-semibold <?= $inv['grand_total'] - $inv['paid_amount'] > 0 ? 'text-red-600' : 'text-gray-400' ?>"><span>Balance</span><span><?= number_format($inv['grand_total'] - $inv['paid_amount'],2) ?></span></div>
    </div>
  </div>

  <?php if ($payments): ?>
  <div class="mt-6 text-sm">
    <div class="font-semibold mb-2">Payment History</div>
    <table class="w-full">
      <thead><tr class="text-left text-gray-400 text-xs border-b"><th class="py-1">Date</th><th>Method</th><th>Notes</th><th class="text-right">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr class="border-b"><td class="py-1.5"><?= e($p['payment_date']) ?></td><td><?= e($p['method']) ?></td><td><?= e($p['notes']) ?></td><td class="text-right"><?= number_format($p['amount'],2) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="mt-8 text-center text-xs text-gray-400">Thank you for your business! | <?= COMPANY_NAME ?></div>
</div>

<div class="max-w-3xl mx-auto flex gap-3 justify-center mt-5 no-print">
  <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-5 py-2 rounded-lg"><i class="fa-solid fa-print mr-1"></i> Print</button>
  <a href="invoices.php" class="bg-gray-700 hover:bg-gray-600 text-white text-sm font-medium px-5 py-2 rounded-lg">Back to Invoices</a>
</div>
<style>@media print { .no-print { display: none; } body { background: white; } }</style>
</body>
</html>
