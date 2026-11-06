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
    background-image: linear-gradient(rgba(10, 15, 30, 0.70), rgba(10, 15, 30, 0.85)),
                      url('assets/images/bg.jpg');
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    background-attachment: fixed;
  }

  /* Glass card */
  .glass-card {
    background: rgba(255, 255, 255, 0.96);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.55), 0 0 0 1px rgba(255, 255, 255, 0.12);
    animation: cardIn 0.6s cubic-bezier(0.22, 1, 0.36, 1) both;
  }

  @keyframes cardIn {
    from { opacity: 0; transform: translateY(24px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
  }

  /* Logo ring */
  .logo-wrap {
    position: relative;
    display: inline-block;
  }
  .logo-ring {
    position: absolute; inset: -8px;
    border-radius: 9999px;
    background: conic-gradient(from 0deg, #3b82f6, #8b5cf6, #06b6d4, #3b82f6);
    opacity: 0.25;
    filter: blur(6px);
    animation: spin 6s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }

  /* Inputs */
  .input-wrap { position: relative; }
  .input-icon {
    position: absolute; top: 50%; transform: translateY(-50%);
    color: #9ca3af; pointer-events: none;
  }
  .field {
    transition: border-color .2s, box-shadow .2s, background .2s;
    background: #f9fafb;
  }
  .field:focus {
    background: #fff;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
  }

  /* Sign-in button */
  .btn-login {
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, #2563eb, #4f46e5);
    transition: transform .15s, box-shadow .25s;
    box-shadow: 0 8px 20px -6px rgba(37, 99, 235, 0.5);
  }
  .btn-login:hover { box-shadow: 0 12px 28px -6px rgba(37, 99, 235, 0.65); transform: translateY(-1px); }
  .btn-login:active { transform: translateY(0); }
  .btn-login::after {
    content: '';
    position: absolute; top: 0; left: -80%;
    width: 40%; height: 100%;
    background: linear-gradient(105deg, transparent, rgba(255,255,255,0.35), transparent);
    transform: skewX(-20deg);
    transition: left .5s;
  }
  .btn-login:hover::after { left: 130%; }

  /* Error shake */
  .error-box { animation: shake 0.4s ease; }
  @keyframes shake {
    0%, 100% { transform: translateX(0); }
    20% { transform: translateX(-6px); }
    40% { transform: translateX(6px); }
    60% { transform: translateX(-4px); }
    80% { transform: translateX(4px); }
  }

  .fade-up { opacity: 0; animation: cardIn 0.6s cubic-bezier(0.22,1,0.36,1) forwards; }
  .d-1 { animation-delay: .1s; } .d-2 { animation-delay: .2s; }
  .d-3 { animation-delay: .3s; } .d-4 { animation-delay: .4s; }
</style>
</head>
<body class="flex flex-col items-center justify-center min-h-screen px-4">

<!-- Decorative glow blobs -->
<div class="pointer-events-none fixed -z-10 top-10 -left-16 w-72 h-72 bg-blue-500/20 rounded-full blur-3xl"></div>
<div class="pointer-events-none fixed -z-10 bottom-10 -right-16 w-72 h-72 bg-violet-500/20 rounded-full blur-3xl"></div>

<!-- Login Card -->
<div class="glass-card rounded-3xl w-full max-w-sm p-8 md:p-9">
  <div class="text-center mb-7 fade-up">
    <div class="logo-wrap mb-4">
      <span class="logo-ring"></span>
      <img src="assets/images/logo.png" alt="<?= e(COMPANY_NAME) ?> Logo"
           class="relative w-16 h-16 object-contain">
    </div>
    <h1 class="text-2xl font-extrabold tracking-tight bg-gradient-to-r from-blue-600 to-violet-600 bg-clip-text text-transparent">
      <?= e(COMPANY_NAME) ?>
    </h1>
    <p class="text-gray-500 text-sm mt-1">Service Center Management System</p>
  </div>

  <?php if ($error): ?>
    <div class="error-box mb-5 flex items-center gap-2 text-sm bg-red-50 text-red-600 border border-red-200 rounded-xl px-4 py-3">
      <i class="fa-solid fa-circle-exclamation"></i><?= e($error) ?>
    </div>
  <?php endif; ?>

  <form method="post" class="space-y-5">
    <div class="fade-up d-1">
      <label class="text-sm font-medium text-gray-600">Username</label>
      <div class="input-wrap mt-1.5">
        <i class="fa-solid fa-user input-icon left-3.5 text-sm"></i>
        <input name="username" required autofocus
               class="field w-full border border-gray-200 rounded-xl pl-10 pr-3 py-2.5 text-sm outline-none focus:ring-0 focus:border-blue-500">
      </div>
    </div>

    <div class="fade-up d-2">
      <label class="text-sm font-medium text-gray-600">Password</label>
      <div class="input-wrap mt-1.5">
        <i class="fa-solid fa-lock input-icon left-3.5 text-sm"></i>
        <input id="password" name="password" type="password" required
               class="field w-full border border-gray-200 rounded-xl pl-10 pr-10 py-2.5 text-sm outline-none focus:ring-0 focus:border-blue-500">
        <button type="button" onclick="togglePw()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition">
          <i id="pwIcon" class="fa-solid fa-eye text-sm"></i>
        </button>
      </div>
    </div>

    <button class="btn-login fade-up d-3 w-full text-white font-semibold py-3 rounded-xl transition">
      <i class="fa-solid fa-right-to-bracket mr-2"></i>Sign In
    </button>
  </form>
</div>

<!-- Credit Footer -->
<footer class="mt-7 flex flex-col items-center gap-1.5 text-center fade-up d-4">
  <div class="text-gray-300/90 text-xs tracking-wide uppercase">Designed &amp; Developed by</div>
  <img src="assets/images/company.png" alt="Developer Logo" class="h-8 object-contain">
  <p class="text-gray-400/80 text-xs">© <?= date('Y') ?> All rights reserved.</p>
</footer>

<script>
function togglePw() {
    const pw = document.getElementById('password');
    const icon = document.getElementById('pwIcon');
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    icon.className = show ? 'fa-solid fa-eye-slash text-sm' : 'fa-solid fa-eye text-sm';
}
</script>

</body>
</html>