<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

if (isset($_GET['delete'])) {
    require_admin();
    $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([(int)$_GET['delete']]);
    flash('Expense deleted.'); redirect('expenses.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare('INSERT INTO expenses (expense_date, category, description, amount) VALUES (?,?,?,?)')
        ->execute([$_POST['expense_date'], trim($_POST['category']), trim($_POST['description']), (float)$_POST['amount']]);
    flash('Expense recorded.'); redirect('expenses.php');
}
$month = $_GET['month'] ?? date('Y-m');
$st = $pdo->prepare("SELECT * FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m') = ? ORDER BY expense_date DESC");
$st->execute([$month]);
$rows = $st->fetchAll();
$total = array_sum(array_column($rows, 'amount'));
page_header('Expense Management');
?>
<div class="grid lg:grid-cols-3 gap-6">
  <form method="post" class="bg-white rounded-2xl shadow p-5 space-y-3 h-fit">
    <h2 class="font-bold text-gray-700">Record Expense</h2>
    <input name="expense_date" type="date" required value="<?= date('Y-m-d') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <input name="category" placeholder="Category (e.g. Rent, Salaries, Utilities)" class="w-full border rounded-lg px-3 py-2 text-sm">
    <textarea name="description" required placeholder="Description" class="w-full border rounded-lg px-3 py-2 text-sm" rows="2"></textarea>
    <input name="amount" type="number" step="0.01" min="0.01" required placeholder="Amount" class="w-full border rounded-lg px-3 py-2 text-sm">
    <button class="w-full bg-rose-600 hover:bg-rose-700 text-white rounded-lg py-2 text-sm font-medium"><i class="fa-solid fa-plus mr-1"></i> Add Expense</button>
  </form>
  <div class="lg:col-span-2 bg-white rounded-2xl shadow p-5 overflow-x-auto">
    <div class="flex items-center justify-between mb-3">
      <form method="get"><input type="month" name="month" value="<?= e($month) ?>" class="border rounded-lg px-3 py-1.5 text-sm" onchange="this.form.submit()"></form>
      <span class="text-sm bg-rose-50 text-rose-700 px-3 py-1.5 rounded-lg">Total: <strong><?= money($total) ?></strong></span>
    </div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b"><th class="py-2">Date</th><th>Category</th><th>Description</th><th class="text-right">Amount</th><th class="text-right"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="border-b hover:bg-gray-50">
          <td class="py-2"><?= e($r['expense_date']) ?></td>
          <td><span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-xs"><?= e($r['category']) ?></span></td>
          <td><?= e($r['description']) ?></td>
          <td class="text-right text-rose-600 font-medium"><?= money($r['amount']) ?></td>
          <td class="text-right"><a href="expenses.php?delete=<?= $r['id'] ?>" onclick="return confirm('Delete this expense?')" class="text-red-600"><i class="fa-solid fa-trash"></i></a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="py-6 text-center text-gray-400">No expenses this month.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>
