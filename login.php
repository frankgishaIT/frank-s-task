<?php
define('RM_OS_ALLOW_GUEST', true);
require 'config/db.php';

session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: modules/dashboard/index.php');
    exit;
}

if (isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $statement = mysqli_prepare($conn, 'SELECT id, names, email, password_hash, role FROM users WHERE email = ? AND is_active = 1');
    mysqli_stmt_bind_param($statement, 's', $email);
    mysqli_stmt_execute($statement);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['names'];
        $_SESSION['user_role'] = $user['role'];
        header('Location: modules/dashboard/index.php');
        exit;
    }

    $error = 'Invalid email or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - MY MOTIVE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --accent-blue:#3B4FE0;
            --accent-blue-dark:#2E3FC0;
            --ink:#1E2333;
            --muted:#8A90A3;
            --border-soft:#EDEFF5;
        }

        *{ font-family:'Inter', system-ui, sans-serif; }

        body{
            min-height:100vh;
            margin:0;
            background:#F6F7FB;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:24px;
        }

        .login-shell{
            width:100%;
            max-width:960px;
            min-height:600px;
            background:#fff;
            border-radius:24px;
            overflow:hidden;
            box-shadow:0 20px 60px rgba(30,35,51,.08);
            display:flex;
        }

        /* Left brand panel */
        .brand-panel{
            flex:1;
            background:linear-gradient(160deg, #2E3FC0 0%, #3B4FE0 55%, #5865F2 100%);
            color:#fff;
            padding:56px 48px;
            display:flex;
            flex-direction:column;
            justify-content:space-between;
            position:relative;
            overflow:hidden;
        }

        .brand-panel::before{
            content:'';
            position:absolute;
            width:420px; height:420px;
            border-radius:50%;
            background:rgba(255,255,255,.06);
            top:-140px; right:-140px;
        }
        .brand-panel::after{
            content:'';
            position:absolute;
            width:280px; height:280px;
            border-radius:50%;
            background:rgba(255,255,255,.05);
            bottom:-100px; left:-80px;
        }

        .brand-logo{
            display:flex;
            align-items:center;
            gap:12px;
            position:relative;
            z-index:1;
        }
        .brand-logo .mark{
            width:40px; height:40px;
            border-radius:11px;
            background:rgba(255,255,255,.16);
            display:flex;
            align-items:center;
            justify-content:center;
            font-weight:800;
            font-size:16px;
        }
        .brand-logo span{
            font-weight:700;
            font-size:16px;
            letter-spacing:.02em;
        }

        .brand-copy{
            position:relative;
            z-index:1;
        }
        .brand-copy h1{
            font-size:30px;
            font-weight:700;
            line-height:1.3;
            margin-bottom:14px;
        }
        .brand-copy p{
            font-size:14px;
            color:rgba(255,255,255,.75);
            line-height:1.6;
            max-width:340px;
        }

        .brand-foot{
            position:relative;
            z-index:1;
            font-size:12px;
            color:rgba(255,255,255,.55);
        }

        /* Right form panel */
        .form-panel{
            flex:1;
            padding:56px 56px;
            display:flex;
            flex-direction:column;
            justify-content:center;
        }

        .form-panel h2{
            font-size:24px;
            font-weight:700;
            color:var(--ink);
            margin-bottom:6px;
        }
        .form-panel .subtitle{
            font-size:14px;
            color:var(--muted);
            margin-bottom:32px;
        }

        .form-label{
            font-size:13px;
            font-weight:600;
            color:var(--ink);
            margin-bottom:6px;
        }

        .input-group-rm{
            position:relative;
        }
        .input-group-rm i{
            position:absolute;
            left:16px;
            top:50%;
            transform:translateY(-50%);
            color:var(--muted);
            font-size:15px;
        }
        .input-group-rm input{
            width:100%;
            padding:12px 16px 12px 44px;
            border:1px solid var(--border-soft);
            border-radius:12px;
            font-size:14px;
            background:#FAFBFE;
            transition:.15s;
        }
        .input-group-rm input:focus{
            outline:none;
            border-color:var(--accent-blue);
            background:#fff;
            box-shadow:0 0 0 3px rgba(59,79,224,.10);
        }

        .btn-signin{
            width:100%;
            padding:13px;
            border:none;
            border-radius:12px;
            background:var(--accent-blue);
            color:#fff;
            font-weight:600;
            font-size:14px;
            transition:.15s;
        }
        .btn-signin:hover{
            background:var(--accent-blue-dark);
        }

        .alert-rm{
            display:flex;
            align-items:center;
            gap:8px;
            border:none;
            border-radius:10px;
            background:#FCEAEA;
            color:#C0392B;
            font-size:13px;
            padding:11px 14px;
            margin-bottom:20px;
        }

        @media (max-width: 860px){
            .brand-panel{ display:none; }
            .login-shell{ max-width:440px; min-height:auto; }
            .form-panel{ padding:44px 32px; }
        }
    </style>
</head>
<body>

    <div class="login-shell">

        <div class="brand-panel">
            <div class="brand-logo">
                <img src="assets/images/logo.jpg" alt="Rise Motive Logo" style="width:40px; height:40px; object-fit:contain; border-radius:11px; background:rgba(255,255,255,.16); padding:4px;">
                <span>MY MOTIVE</span>
            </div>

            <div class="brand-copy">
                <h1>Run your business,<br>all in one place.</h1>
                <p>Manage employees, projects, sales, and finances from a single dashboard built for growing teams.</p>
            </div>

            <div class="brand-foot">
                &copy; <?= date('Y'); ?> My Motive. All rights reserved.
            </div>
        </div>

        <div class="form-panel">
            <img src="assets/images/logo.jpg" alt="Rise Motive Logo" 
            style="width:44px; height:44px; object-fit:contain; border-radius:12px; margin-bottom:20px;">
            <h2>Welcome back</h2>
            <p class="subtitle">Sign in to your account to continue.</p>

            <?php if (isset($error)) { ?>
            <div class="alert-rm">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php } ?>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Email address</label>
                    <div class="input-group-rm">
                        <i class="bi bi-envelope"></i>
                        <input type="email" name="email" placeholder="you@company.com" required autofocus>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <div class="input-group-rm">
                        <i class="bi bi-lock"></i>
                        <input type="password" name="password" placeholder="••••••••" required>
                    </div>
                </div>

                <button type="submit" name="login" class="btn-signin">Sign in</button>
            </form>
        </div>

    </div>

</body>
</html>