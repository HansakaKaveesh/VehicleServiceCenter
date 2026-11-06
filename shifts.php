<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

$role = $_SESSION['user_role'] ?? 'staff';
$uid = (int)$_SESSION['user_id'];
$today = date('Y-m-d');
$now = date('H:i:s');

// ---- Handle shift actions (staff & stock_manager only) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['staff', 'stock_manager'], true)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'start') {
        try {
            $pdo->prepare('INSERT INTO shifts (user_id, shift_date, start_time) VALUES (?,?,?)')
                ->execute([$uid, $today, $now]);
            flash('Shift started at ' . date('h:i A'));
        } catch (PDOException $e) {
            flash('You already started a shift today.', 'error');
        }
    } elseif ($action === 'end') {
        $pdo->prepare('UPDATE shifts SET end_time = ? WHERE user_id = ? AND shift_date = ? AND end_time IS NULL')
            ->execute([$now, $uid, $today]);
        flash('Shift ended at ' . date('h:i A'));
    }
    redirect('shifts.php');
}

// ---- Today's shift for current user ----
$my_shift = null;
if (in_array($role, ['staff', 'stock_manager'], true)) {
    $st = $pdo->prepare('SELECT * FROM shifts WHERE user_id = ? AND shift_date = ?');
    $st->execute([$uid, $today]);
    $my_shift = $st->fetch();
}

// ---- Shift list ----
// Admin sees everyone (with optional filter); staff/stock_manager see their own history
$user_filter = (int)($_GET['user_id'] ?? 0);
$date_from = $_GET['from'] ?? '';
$date_to = $_GET['to'] ?? '';

$where = [];
$params = [];
if ($role !== 'admin') {
    $where[] = 's.user_id = ?';
    $params[] = $uid;
} elseif ($user_filter) {
    $where[] = 's.user_id = ?';
    $params[] = $user_filter;
}
if ($date_from) { $where[] = 's.shift_date >= ?'; $params[] = $date_from; }
if ($date_to)   { $where[] = 's.shift_date <= ?'; $params[] = $date_to; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT s.*, u.name, u.role,
        TIMEDIFF(COALESCE(s.end_time, ?), s.start_time) AS duration
        FROM shifts s JOIN users u ON u.id = s.user_id
        $where_sql ORDER BY s.shift_date DESC, s.start_time DESC LIMIT 200";
$st = $pdo->prepare($sql);
$st->execute(array_merge([$now], $params));
$shifts = $st->fetchAll();

$staff_list = $pdo->query("SELECT id, name FROM users WHERE role IN ('staff','stock_manager') ORDER BY name")->fetchAll();

page_header('Shift Times');
?>

<?php if (in_array($role, ['staff', 'stock_manager'], true)): ?>
<!-- Clock In / Out card -->
<div class="bg-white rounded-2xl shadow p-5 mb-6 flex flex-col sm:flex-row sm:items-center gap-4">
  <div class="flex items-center gap-3 flex-1">
    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white flex items-center justify-center shadow-lg">
      <i class="fa-solid fa-clock text-lg"></i>
    </div>
    <div>
      <div class="text-xs text-gray-400">Today, <?= date('l, d M Y') ?></div>
      <div class="text-lg font-bold text-gray-800">
        <?= date('h:i A') ?>
        <?php if ($my_shift && !$my_shift['end_time']): ?>
          <span class="ml-2 text-xs font-semibold text-green-600 bg-green-50 px-2 py-0.5 rounded-full">On Shift</span>
        <?php elseif ($my_shift): ?>
          <span class="text-xs font-semibold text-gray-400">(shift completed)</span>
        <?php else: ?>
          <span class="text-xs font-semibold text-gray-400">(not started)</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <form method="post" class="flex gap-2">
    <?php if (!$my_shift): ?>
    <button name="action" value="start" class="bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-5 py-2 text-sm font-medium">
      <i class="fa-solid fa-play mr-1"></i> Start Shift
    </button>
    <?php elseif (!$my_shift['end_time']): ?>
    <button name="action" value="end" onclick="return confirm('End your shift now?')" class="bg-rose-600 hover:bg-rose-700 text-white rounded-lg px-5 py-2 text-sm font-medium">
      <i class="fa-solid fa-stop mr-1"></i> End Shift
    </button>
    <?php else: ?>
    <span class="text-sm text-gray-400 self-center">Shift: <?= date('h:i A', strtotime($my_shift['start_time'])) ?> – <?= date('h:i A', strtotime($my_shift['end_time'])) ?></span>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>

<!-- Filters (admin) -->
<?php if ($role === 'admin'): ?>
<form method="get" class="bg-white rounded-2xl shadow p-4 mb-6 flex flex-wrap items-center gap-3">
  <span class="text-sm font-semibold text-gray-600"><i class="fa-solid fa-filter mr-1 text-blue-500"></i> Filter</span>
  <select name="user_id" class="border rounded-lg px-3 py-1.5 text-sm bg-white">
    <option value="">All staff</option>
    <?php foreach ($staff_list as $u): ?>
    <option value="<?= $u['id'] ?>" <?= $user_filter === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" name="from" value="<?= e($date_from) ?>" class="border rounded-lg px-3 py-1.5 text-sm">
  <span class="text-gray-400 text-xs">to</span>
  <input type="date" name="to" value="<?= e($date_to) ?>" class="border rounded-lg px-3 py-1.5 text-sm">
  <button class="bg-blue-600 hover:bg-blue-700 text-white rounded-lg px-4 py-1.5 text-sm font-medium">Apply</button>
  <a href="shifts.php" class="text-sm text-gray-400 hover:text-gray-600">Reset</a>
</form>
<?php endif; ?>

<!-- Shift records -->
<div class="bg-white rounded-2xl shadow p-5 overflow-x-auto">
  <h2 class="font-bold text-gray-700 mb-3">
    <?= $role === 'admin' ? 'All Shift Records' : 'My Shift History' ?>
  </h2>
  <table class="w-full text-sm">
    <thead><tr class="text-left text-gray-500 border-b">
      <?php if ($role === 'admin'): ?><th class="py-2">Staff Member</th><?php endif; ?>
      <th class="py-2">Date</th><th>Start</th><th>End</th><th>Duration</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($shifts as $s): 
        $open = $s['end_time'] === null;
        $mins = (int)round(((strtotime($s['shift_date'].' '.($s['end_time'] ?? $now)) - strtotime($s['shift_date'].' '.$s['start_time'])) / 60));
        $dur = sprintf('%dh %02dm', intdiv($mins, 60), $mins % 60);
    ?>
      <tr class="border-b hover:bg-gray-50">
        <?php if ($role === 'admin'): ?>
        <td class="py-2">
          <span class="font-medium"><?= e($s['name']) ?></span>
          <span class="text-[10px] px-1.5 py-0.5 rounded-full ml-1 <?= $s['role']==='stock_manager' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-500' ?>"><?= e(str_replace('_',' ',$s['role'])) ?></span>
        </td>
        <?php endif; ?>
        <td class="py-2"><?= e($s['shift_date']) ?></td>
        <td><?= date('h:i A', strtotime($s['start_time'])) ?></td>
        <td><?= $open ? '—' : date('h:i A', strtotime($s['end_time'])) ?></td>
        <td class="text-gray-600"><?= $dur ?></td>
        <td>
          <?php if ($open): ?>
          <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700">
            <span class="w-1.5 h-1.5 rounded-full bg-green-600 animate-pulse"></span> On Shift
          </span>
          <?php else: ?>
          <span class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">Completed</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$shifts): ?>
      <tr><td colspan="<?= $role === 'admin' ? 6 : 5 ?>" class="py-6 text-center text-gray-400">No shift records found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php page_footer(); ?>