<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

// --- Stock movement (Purchase / Usage / Adjustment) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'movement') {
    $part_id = (int)$_POST['part_id'];
    $type = $_POST['movement_type'];
    $qty = (int)$_POST['quantity'];
    $price = (float)($_POST['unit_price'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO stock_movements (part_id, movement_type, quantity, unit_price, reference, notes) VALUES (?,?,?,?,?,?)')
            ->execute([$part_id, $type, $qty, $price, trim($_POST['reference'] ?? ''), $notes]);
        if ($type === 'Purchase') {
            $pdo->prepare('UPDATE parts SET stock_qty = stock_qty + ?, unit_price = IF(? > 0, ?, unit_price) WHERE id = ?')->execute([$qty, $price, $price, $part_id]);
        } elseif ($type === 'Usage') {
            $pdo->prepare('UPDATE parts SET stock_qty = GREATEST(0, stock_qty - ?) WHERE id = ?')->execute([$qty, $part_id]);
        } else { // Adjustment (set absolute)
            $pdo->prepare('UPDATE parts SET stock_qty = ? WHERE id = ?')->execute([$qty, $part_id]);
        }
        $pdo->commit();
        flash("Stock movement recorded ($type).");
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error recording movement.', 'error');
    }
    redirect('parts.php');
}

// --- Add / edit part ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $data = [trim($_POST['name']), trim($_POST['part_no']), trim($_POST['category']), (float)$_POST['unit_price'],
             (int)$_POST['stock_qty'], (int)$_POST['min_stock'], $_POST['supplier_id'] ?: null];
    if ($id) {
        $pdo->prepare('UPDATE parts SET name=?, part_no=?, category=?, unit_price=?, stock_qty=?, min_stock=?, supplier_id=? WHERE id=?')->execute([...$data, $id]);
        flash('Part updated.');
    } else {
        $pdo->prepare('INSERT INTO parts (name, part_no, category, unit_price, stock_qty, min_stock, supplier_id) VALUES (?,?,?,?,?,?,?)')->execute($data);
        flash('Part added.');
    }
    redirect('parts.php');
}

if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM parts WHERE id = ?')->execute([(int)$_GET['delete']]);
    flash('Part deleted.'); redirect('parts.php');
}

$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();
$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM parts WHERE id=?'); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch();
}
$view = $_GET['view'] ?? 'parts';
page_header('Spare Parts Inventory');
?>
<div class="flex gap-2 mb-5">
  <a href="parts.php" class="px-4 py-2 rounded-lg text-sm font-medium <?= $view==='parts' ? 'bg-blue-600 text-white' : 'bg-white shadow text-gray-600' ?>"><i class="fa-solid fa-gears mr-1"></i> Parts</a>
  <a href="parts.php?view=movements" class="px-4 py-2 rounded-lg text-sm font-medium <?= $view==='movements' ? 'bg-blue-600 text-white' : 'bg-white shadow text-gray-600' ?>"><i class="fa-solid fa-arrow-right-arrow-left mr-1"></i> Stock Movements</a>
  <a href="parts.php?view=lowstock" class="px-4 py-2 rounded-lg text-sm font-medium <?= $view==='lowstock' ? 'bg-blue-600 text-white' : 'bg-white shadow text-gray-600' ?>"><i class="fa-solid fa-triangle-exclamation mr-1"></i> Low Stock</a>
</div>

<?php if ($view === 'parts'): $parts = $pdo->query('SELECT p.*, s.name AS supplier FROM parts p LEFT JOIN suppliers s ON s.id=p.supplier_id ORDER BY p.id DESC')->fetchAll(); ?>
<div class="grid lg:grid-cols-3 gap-6">
  <form method="post" class="bg-white rounded-2xl shadow p-5 space-y-3 h-fit">
    <h2 class="font-bold text-gray-700"><?= $edit ? 'Edit Part' : 'Add Part' ?></h2>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
    <input name="name" required placeholder="Part name" value="<?= e($edit['name'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <div class="grid grid-cols-2 gap-2">
      <input name="part_no" placeholder="Part No" value="<?= e($edit['part_no'] ?? '') ?>" class="border rounded-lg px-3 py-2 text-sm">
      <input name="category" placeholder="Category" value="<?= e($edit['category'] ?? '') ?>" class="border rounded-lg px-3 py-2 text-sm">
    </div>
    <div class="grid grid-cols-2 gap-2">
      <input name="unit_price" type="number" step="0.01" min="0" placeholder="Unit price" value="<?= e($edit['unit_price'] ?? '') ?>" class="border rounded-lg px-3 py-2 text-sm">
      <input name="stock_qty" type="number" min="0" placeholder="Stock qty" value="<?= e($edit['stock_qty'] ?? '0') ?>" class="border rounded-lg px-3 py-2 text-sm">
    </div>
    <div class="grid grid-cols-2 gap-2">
      <input name="min_stock" type="number" min="0" placeholder="Min stock (alert level)" value="<?= e($edit['min_stock'] ?? '5') ?>" class="border rounded-lg px-3 py-2 text-sm">
      <select name="supplier_id" class="border rounded-lg px-3 py-2 text-sm bg-white">
        <option value="">-- Supplier --</option>
        <?php foreach ($suppliers as $s): ?>
        <option value="<?= $s['id'] ?>" <?= ($edit['supplier_id'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2 text-sm font-medium"><i class="fa-solid fa-floppy-disk mr-1"></i> <?= $edit ? 'Update' : 'Add Part' ?></button>
  </form>
  <div class="lg:col-span-2 space-y-4">
    <form method="post" class="bg-white rounded-2xl shadow p-5 grid md:grid-cols-6 gap-3 items-end">
      <h3 class="md:col-span-6 font-bold text-gray-700"><i class="fa-solid fa-arrow-right-arrow-left text-blue-600 mr-2"></i>Record Stock Movement</h3>
      <input type="hidden" name="action" value="movement">
      <select name="part_id" required class="border rounded-lg px-3 py-2 text-sm bg-white">
        <option value="">-- Part --</option>
        <?php foreach ($parts as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= (int)$p['stock_qty'] ?>)</option><?php endforeach; ?>
      </select>
      <select name="movement_type" class="border rounded-lg px-3 py-2 text-sm bg-white">
        <option>Purchase</option><option>Usage</option><option>Adjustment</option>
      </select>
      <input name="quantity" type="number" min="1" required placeholder="Qty" class="border rounded-lg px-3 py-2 text-sm">
      <input name="unit_price" type="number" step="0.01" min="0" placeholder="Unit price" class="border rounded-lg px-3 py-2 text-sm">
      <input name="reference" placeholder="Reference / PO No" class="border rounded-lg px-3 py-2 text-sm">
      <button class="bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg py-2 text-sm font-medium"><i class="fa-solid fa-plus mr-1"></i> Record</button>
    </form>
    <div class="bg-white rounded-2xl shadow p-5 overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b">
          <th class="py-2">Part</th><th>Part No</th><th>Category</th><th>Price</th><th>Stock</th><th>Supplier</th><th class="text-right">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($parts as $p): $low = $p['stock_qty'] <= $p['min_stock']; ?>
          <tr class="border-b hover:bg-gray-50">
            <td class="py-2 font-medium"><?= e($p['name']) ?></td>
            <td><?= e($p['part_no']) ?></td>
            <td><?= e($p['category']) ?></td>
            <td><?= money($p['unit_price']) ?></td>
            <td><span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $low ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' ?>"><?= (int)$p['stock_qty'] ?> <?= $low ? '(low)' : '' ?></span></td>
            <td><?= e($p['supplier']) ?></td>
            <td class="text-right space-x-2">
              <a href="parts.php?edit=<?= $p['id'] ?>" class="text-blue-600"><i class="fa-solid fa-pen"></i></a>
              <a href="parts.php?delete=<?= $p['id'] ?>" onclick="return confirm('Delete this part?')" class="text-red-600"><i class="fa-solid fa-trash"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$parts): ?><tr><td colspan="7" class="py-6 text-center text-gray-400">No parts yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif ($view === 'movements'): $rows = $pdo->query('SELECT m.*, p.name AS part, p.part_no FROM stock_movements m JOIN parts p ON p.id=m.part_id ORDER BY m.id DESC LIMIT 200')->fetchAll(); ?>
<div class="bg-white rounded-2xl shadow p-5 overflow-x-auto">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-gray-500 border-b">
      <th class="py-2">Date</th><th>Part</th><th>Type</th><th>Qty</th><th>Unit Price</th><th>Reference</th><th>Notes</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $m): $c = ['Purchase'=>'text-emerald-600','Usage'=>'text-red-600','Adjustment'=>'text-amber-600'][$m['movement_type']]; ?>
      <tr class="border-b hover:bg-gray-50">
        <td class="py-2"><?= e($m['created_at']) ?></td>
        <td><?= e($m['part']) ?> <span class="text-gray-400">(<?= e($m['part_no']) ?>)</span></td>
        <td class="font-medium <?= $c ?>"><?= $m['movement_type'] ?></td>
        <td><?= (int)$m['quantity'] ?></td>
        <td><?= money($m['unit_price']) ?></td>
        <td><?= e($m['reference']) ?></td>
        <td><?= e($m['notes']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="py-6 text-center text-gray-400">No movements recorded.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php else: $rows = $pdo->query('SELECT p.*, s.name AS supplier FROM parts p LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE p.stock_qty <= p.min_stock ORDER BY p.stock_qty')->fetchAll(); ?>
<div class="bg-white rounded-2xl shadow p-5 overflow-x-auto">
  <h2 class="font-bold text-gray-700 mb-3"><i class="fa-solid fa-triangle-exclamation text-amber-500 mr-2"></i>Low Stock Report (stock &le; minimum level)</h2>
  <table class="w-full text-sm">
    <thead><tr class="text-left text-gray-500 border-b"><th class="py-2">Part</th><th>Part No</th><th>Current Stock</th><th>Min Level</th><th>Shortage</th><th>Supplier</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="border-b hover:bg-gray-50">
        <td class="py-2 font-medium"><?= e($r['name']) ?></td>
        <td><?= e($r['part_no']) ?></td>
        <td class="text-red-600 font-semibold"><?= (int)$r['stock_qty'] ?></td>
        <td><?= (int)$r['min_stock'] ?></td>
        <td class="text-red-600"><?= max(0, (int)$r['min_stock'] - (int)$r['stock_qty']) ?></td>
        <td><?= e($r['supplier']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="py-6 text-center text-gray-400">No low-stock items. Inventory is healthy.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php page_footer(); ?>
