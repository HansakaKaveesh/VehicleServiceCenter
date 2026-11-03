<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([trim($_POST['username'] ?? '')]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'] ?? '', $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        redirect('index.php');
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - <?= COMPANY_NAME ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
  body {
    background-image: linear-gradient(rgba(17, 24, 39, 0.75), rgba(17, 24, 39, 0.75)),
                      url('assets/images/bg.jpg');
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    background-attachment: fixed;
  }
</style>
</head>
<body class="flex flex-col items-center justify-center min-h-screen">

<!-- Login Card -->
<div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-8">
  <div class="text-center mb-6">
    <img src="assets/images/logo.png" alt="<?= e(COMPANY_NAME) ?> Logo"
         class="w-16 h-16 object-contain mx-auto">
    <h1 class="text-xl font-bold mt-3"><?= COMPANY_NAME ?></h1>
    <p class="text-gray-500 text-sm">Service Center Management System</p>
  </div>
  <?php if ($error): ?><div class="mb-4 text-sm bg-red-100 text-red-700 rounded-lg px-4 py-2"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="space-y-4">
    <div>
      <label class="text-sm text-gray-600">Username</label>
      <input name="username" required class="mt-1 w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
    </div>
    <div>
      <label class="text-sm text-gray-600">Password</label>
      <input name="password" type="password" required class="mt-1 w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
    </div>
    <button class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg transition">
      <i class="fa-solid fa-right-to-bracket mr-1"></i> Sign In
    </button>
  </form>
</div>

<!-- Credit Footer -->
<footer class="mt-6 flex flex-col items-center gap-1.5 text-center">
  <div class="text-gray-300 text-xs">Designed &amp; Developed by</div>
  <img src="assets/images/company.png" alt="Developer Logo" class="h-8 object-contain">
  <p class="text-gray-400 text-xs">© <?= date('Y') ?> All rights reserved.</p>
</footer>

</body>
</html>