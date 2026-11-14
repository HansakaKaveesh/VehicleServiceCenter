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
$st = $pdo->prepare("SELECT * FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m') = ? ORDER BY expense_date DESC, id DESC");
$st->execute([$month]);
$rows = $st->fetchAll();

$total  = array_sum(array_column($rows, 'amount'));
$count  = count($rows);
$largest = $count ? max(array_column($rows, 'amount')) : 0;
$avg    = $count ? $total / $count : 0;

// Prev / next month links
$d = DateTime::createFromFormat('Y-m', $month);
$prev = (clone $d)->modify('-1 month')->format('Y-m');
$next = (clone $d)->modify('+1 month')->format('Y-m');
$monthLabel = $d->format('F Y');

// Available categories for quick chips
$cats = $pdo->query("SELECT DISTINCT category FROM expenses ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

page_header('Expense Management');
?>
<div class="max-w-8xl mx-auto space-y-6">

  <!-- ===== Page header ===== -->
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
        <i class="fa-solid fa-money-bill-trend-up text-rose-600"></i> Expenses
      </h1>
      <p class="text-sm text-gray-500 mt-1">Track and manage office spending for <span class="font-medium text-gray-700"><?= e($monthLabel) ?></span></p>
    </div>
    <div class="flex items-center gap-2">
      <a href="expenses.php?month=<?= $prev ?>" class="w-9 h-9 flex items-center justify-center rounded-lg border bg-white text-gray-600 hover:bg-gray-50" title="Previous month"><i class="fa-solid fa-chevron-left"></i></a>
      <a href="expenses.php" class="px-3 h-9 flex items-center rounded-lg border bg-white text-sm text-gray-600 hover:bg-gray-50" title="Go to current month"><i class="fa-solid fa-calendar-day mr-2 text-rose-500"></i> Today</a>
      <a href="expenses.php?month=<?= $next ?>" class="w-9 h-9 flex items-center justify-center rounded-lg border bg-white text-gray-600 hover:bg-gray-50" title="Next month"><i class="fa-solid fa-chevron-right"></i></a>
    </div>
  </div>

  <!-- ===== Stat cards ===== -->
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div class="bg-gradient-to-br from-rose-500 to-rose-600 rounded-2xl shadow p-5 text-white">
      <div class="flex items-center justify-between">
        <div><p class="text-xs uppercase tracking-wide opacity-80">Total spent</p><p class="text-2xl font-bold mt-1"><?= money($total) ?></p></div>
        <div class="w-11 h-11 rounded-xl bg-white/20 flex items-center justify-center"><i class="fa-solid fa-sack-xmark text-lg"></i></div>
      </div>
    </div>
    <div class="bg-white rounded-2xl shadow p-5">
      <div class="flex items-center justify-between">
        <div><p class="text-xs uppercase tracking-wide text-gray-400">Entries</p><p class="text-2xl font-bold text-gray-800 mt-1"><?= $count ?></p></div>
        <div class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"><i class="fa-solid fa-receipt text-lg"></i></div>
      </div>
    </div>
    <div class="bg-white rounded-2xl shadow p-5">
      <div class="flex items-center justify-between">
        <div><p class="text-xs uppercase tracking-wide text-gray-400">Largest expense</p><p class="text-2xl font-bold text-gray-800 mt-1"><?= money($largest) ?></p></div>
        <div class="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center"><i class="fa-solid fa-arrow-trend-up text-lg"></i></div>
      </div>
      <?php if ($count): ?><p class="text-xs text-gray-400 mt-2">Average per entry: <?= money($avg) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="grid lg:grid-cols-3 gap-6">

    <!-- ===== Add expense form ===== -->
    <form method="post" class="bg-white rounded-2xl shadow p-5 space-y-4 h-fit border-t-4 border-rose-500">
      <h2 class="font-bold text-gray-700 flex items-center gap-2"><i class="fa-solid fa-circle-plus text-rose-500"></i> Record Expense</h2>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Date</label>
        <input name="expense_date" type="date" required value="<?= date('Y-m-d') ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-rose-200 focus:border-rose-400 outline-none">
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Category</label>
        <input id="catInput" name="category" list="catList" placeholder="e.g. Rent, Salaries, Utilities" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-rose-200 focus:border-rose-400 outline-none">
        <datalist id="catList"><?php foreach ($cats as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
        <?php if ($cats): ?>
        <div class="flex flex-wrap gap-1.5 mt-2">
          <?php foreach (array_slice($cats, 0, 6) as $c): ?>
            <button type="button" onclick="document.getElementById('catInput').value='<?= e(addslashes($c)) ?>'" class="text-xs bg-gray-100 hover:bg-rose-100 hover:text-rose-700 text-gray-600 px-2.5 py-1 rounded-full transition"><?= e($c) ?></button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Description</label>
        <textarea name="description" required placeholder="What was this expense for?" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-rose-200 focus:border-rose-400 outline-none" rows="2"></textarea>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Amount</label>
        <div class="relative">
          <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"><i class="fa-solid fa-coins"></i></span>
          <input name="amount" type="number" step="0.01" min="0.01" required placeholder="0.00" class="w-full border border-gray-200 rounded-lg pl-9 pr-3 py-2 text-sm focus:ring-2 focus:ring-rose-200 focus:border-rose-400 outline-none">
        </div>
      </div>
      <button class="w-full bg-rose-600 hover:bg-rose-700 active:scale-[.98] transition text-white rounded-lg py-2.5 text-sm font-medium shadow-sm"><i class="fa-solid fa-plus mr-1"></i> Add Expense</button>
    </form>

    <!-- ===== Expense table ===== -->
    <div class="lg:col-span-2 bg-white rounded-2xl shadow overflow-hidden">
      <!-- Table toolbar -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-5 border-b">
        <form method="get" class="flex items-center gap-2">
          <i class="fa-regular fa-calendar text-gray-400"></i>
          <input type="month" name="month" value="<?= e($month) ?>" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-rose-200 focus:border-rose-400 outline-none" onchange="this.form.submit()">
        </form>
        <div class="relative">
          <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
          <input id="searchBox" type="text" placeholder="Search expenses…" class="w-full sm:w-56 border border-gray-200 rounded-lg pl-8 pr-3 py-1.5 text-sm focus:ring-2 focus:ring-rose-200 focus:border-rose-400 outline-none">
        </div>
      </div>

      <div class="overflow-x-auto">
        <table id="expTable" class="w-full text-sm">
          <thead><tr class="text-left text-gray-400 text-xs uppercase tracking-wide bg-gray-50">
            <th class="py-3 px-5 font-medium">Date</th><th class="px-3 font-medium">Category</th><th class="px-3 font-medium">Description</th><th class="px-3 font-medium text-right">Amount</th><th class="px-5"></th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr class="border-t hover:bg-rose-50/40 transition row-item">
              <td class="py-3 px-5 whitespace-nowrap">
                <div class="font-medium text-gray-700"><?= e(date('d M', strtotime($r['expense_date']))) ?></div>
                <div class="text-xs text-gray-400"><?= e(date('Y', strtotime($r['expense_date']))) ?></div>
              </td>
              <td class="px-3"><span class="bg-rose-50 text-rose-700 px-2.5 py-1 rounded-full text-xs font-medium"><?= e($r['category'] ?: '—') ?></span></td>
              <td class="px-3 text-gray-600"><?= e($r['description']) ?></td>
              <td class="px-3 text-right text-gray-800 font-semibold whitespace-nowrap"><?= money($r['amount']) ?></td>
              <td class="px-5 text-right">
                <a href="expenses.php?delete=<?= $r['id'] ?>" onclick="return confirm('Delete this expense (<?= money($r['amount']) ?> — <?= e($r['description']) ?>)?')" title="Delete" class="inline-flex w-8 h-8 items-center justify-center rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition"><i class="fa-solid fa-trash"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr class="border-t bg-gray-50">
            <td colspan="3" class="py-3 px-5 text-xs uppercase tracking-wide text-gray-400 font-medium">Total for <?= e($monthLabel) ?></td>
            <td class="py-3 px-3 text-right font-bold text-rose-600"><?= money($total) ?></td><td class="px-5"></td>
          </tr></tfoot>
        </table>
        <?php if (!$rows): ?>
        <div class="py-14 text-center text-gray-400">
          <i class="fa-solid fa-folder-open text-3xl mb-3 block"></i>
          No expenses recorded in <?= e($monthLabel) ?> yet.<br>
          <span class="text-sm">Use the form on the left to add your first expense.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
// Live client-side search across date/category/description/amount
document.getElementById('searchBox')?.addEventListener('input', function () {
  const q = this.value.toLowerCase();
  document.querySelectorAll('#expTable tbody .row-item').forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
});
</script>
<?php page_footer(); ?>