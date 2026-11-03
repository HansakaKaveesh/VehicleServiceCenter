<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_admin();

$message = '';

// --- Backup: export all tables as SQL ---
if (isset($_GET['download'])) {
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="vscms_backup_' . date('Y-m-d_His') . '.sql"');
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "-- VSCMS Database Backup - " . date('Y-m-d H:i:s') . "\n\n";
    foreach ($tables as $t) {
        echo "DROP TABLE IF EXISTS `$t`;\n";
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch();
        echo $create['Create Table'] . ";\n\n";
        $rows = $pdo->query("SELECT * FROM `$t`");
        while ($r = $rows->fetch(PDO::FETCH_NUM)) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), $r);
            echo "INSERT INTO `$t` VALUES (" . implode(',', $vals) . ");\n";
        }
        echo "\n";
    }
    exit;
}

// --- Restore ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sqlfile'])) {
    $file = $_FILES['sqlfile']['tmp_name'];
    if ($file && is_uploaded_file($file)) {
        $sql = file_get_contents($file);
        if (strpos($sql, 'DROP TABLE') === false || strpos($sql, 'CREATE TABLE') === false) {
            $message = '<div class="mb-4 px-4 py-3 rounded-lg text-sm bg-red-100 text-red-800">Invalid backup file. Restore aborted for safety.</div>';
        } else {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                $pdo->exec($sql);
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                flash('Database restored successfully from backup.');
                redirect('backup.php');
            } catch (Exception $ex) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                $message = '<div class="mb-4 px-4 py-3 rounded-lg text-sm bg-red-100 text-red-800">Restore failed: ' . e($ex->getMessage()) . '</div>';
            }
        }
    }
}
page_header('Backup & Restore');
?>
<?= $message ?>
<div class="grid md:grid-cols-2 gap-6 max-w-4xl">
  <div class="bg-white rounded-2xl shadow p-6">
    <h2 class="font-bold text-gray-700 mb-2"><i class="fa-solid fa-download text-blue-600 mr-2"></i>Download Backup</h2>
    <p class="text-sm text-gray-500 mb-4">Creates a complete SQL dump of all tables and data. Store it in a safe location.</p>
    <a href="backup.php?download=1" class="inline-block bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg">
      <i class="fa-solid fa-database mr-1"></i> Download Backup (.sql)
    </a>
    <div class="mt-4 text-xs text-gray-400 bg-gray-50 rounded-lg p-3">
      <strong>Recovery guidance:</strong> To restore, use the form on the right, or import the file via phpMyAdmin / command line:<br>
      <code class="text-gray-600">mysql -u root -p vscms &lt; backup.sql</code>
    </div>
  </div>
  <div class="bg-white rounded-2xl shadow p-6">
    <h2 class="font-bold text-gray-700 mb-2"><i class="fa-solid fa-upload text-emerald-600 mr-2"></i>Restore from Backup</h2>
    <p class="text-sm text-gray-500 mb-4">Upload a previously downloaded <code>.sql</code> backup file. <strong class="text-red-600">This will overwrite all current data.</strong></p>
    <form method="post" enctype="multipart/form-data" class="space-y-3">
      <input type="file" name="sqlfile" accept=".sql" required class="w-full text-sm border rounded-lg px-3 py-2 bg-white">
      <button onclick="return confirm('This will REPLACE all current data. Continue?')" class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg">
        <i class="fa-solid fa-rotate-left mr-1"></i> Restore Database
      </button>
    </form>
    <div class="mt-4 text-xs text-amber-600 bg-amber-50 rounded-lg p-3">
      <i class="fa-solid fa-triangle-exclamation mr-1"></i> Always download a fresh backup before restoring an older one.
    </div>
  </div>
</div>
<?php page_footer(); ?>
