<!DOCTYPE html>
<html>
<head>
    <title>MY MOTIVE</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/rm-form.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/rm-table.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/components/button.css">

    <style>

        :root{
            --accent-blue:#1E2FE0; --accent-blue-bg:#E6E8FD;
            --accent-teal:#0FA968; --accent-teal-bg:#E1F7EE;
            --accent-amber:#E68A1C; --accent-amber-bg:#FDF1DF;
            --accent-purple:#7C5CE0; --accent-purple-bg:#F0EBFD;
            --accent-cyan:#159AA8; --accent-cyan-bg:#E2F6F8;
            --accent-red:#E24B4A; --accent-red-bg:#FCEAEA;
            --ink:#1E2333; --muted:#8A90A3; --border-soft:#EEF1F8;
            --sidebar-bg:#020CCE; --sidebar-bg-active:#2A3AFA; --sidebar-width:250px;
        }

        body{
            margin:0;
            background:#F4F6FB;
            font-family:'Segoe UI', Arial, sans-serif;
            color:var(--ink);
        }

        .sidebar{
            height:100vh;
            position:fixed;
            background:var(--sidebar-bg);
            overflow-y:auto;
            padding-bottom:80px;
            width:var(--sidebar-width);
            z-index:1050;
            transition:transform .25s ease;
        }

        .sidebar h3{
            color:white;
            text-align:center;
            font-size:18px;
            font-weight:700;
            letter-spacing:.02em;
            padding:24px 16px;
            margin:0 0 12px;
            border-bottom:1px solid rgba(255,255,255,.08);
        }

        .sidebar a{
            display:flex;
            align-items:center;
            gap:10px;
            color:rgba(255,255,255,.7);
            text-decoration:none;
            font-size:14px;
            padding:11px 22px;
            margin:2px 10px;
            border-radius:10px;
            transition:.15s;
        }

        .sidebar a:hover{
            background:rgba(255,255,255,.06);
            color:#fff;
        }

        .sidebar a.active{
            background:var(--sidebar-bg-active);
            color:#fff;
        }

        .content{
            margin-left:var(--sidebar-width);
            padding:0 30px 30px;
        }

        .topbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
            height:64px;
            margin:0 -30px 24px;
            padding:0 30px;
            background:#fff;
            border-bottom:1px solid var(--border-soft);
            position:sticky;
            top:0;
            z-index:10;
        }

        .topbar-search{
            display:flex;
            align-items:center;
            gap:8px;
            background:#F4F6FB;
            border-radius:10px;
            padding:9px 14px;
            width:280px;
            max-width:50%;
        }

        .topbar-search i{ color:var(--muted); font-size:14px; }

        .topbar-search input{
            border:none;
            background:transparent;
            outline:none;
            font-size:13px;
            width:100%;
            color:var(--ink);
        }

        .topbar-icons{
            display:flex;
            align-items:center;
            gap:18px;
            flex-shrink:0;
        }

        .topbar-icon-btn{
            position:relative;
            color:var(--muted);
            font-size:18px;
            text-decoration:none;
        }

        .topbar-icon-btn:hover{ color:var(--accent-blue); }

        .topbar-badge{
            position:absolute;
            top:-6px;
            right:-8px;
            background:var(--accent-red);
            color:#fff;
            font-size:9px;
            font-weight:700;
            width:15px;
            height:15px;
            border-radius:50%;
            display:flex;
            align-items:center;
            justify-content:center;
        }

        .topbar-org{
            display:flex;
            align-items:center;
            gap:8px;
            padding-left:16px;
            border-left:1px solid var(--border-soft);
            font-size:13px;
            font-weight:500;
            color:var(--ink);
        }

        .topbar-org-avatar{
            width:32px;
            height:32px;
            border-radius:50%;
            background:var(--accent-blue-bg);
            color:var(--accent-blue);
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:14px;
        }

        .header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:20px;
        }

        table{
            width:100%;
            border-collapse:collapse;
            background:#fff;
            border-radius:16px;
            overflow:hidden;
            border:1px solid var(--border-soft);
        }

        table th,
        table td{
            border:none;
            border-bottom:1px solid var(--border-soft);
            padding:12px 16px;
            font-size:13px;
        }

        table th{
            background:#fff;
            color:var(--muted);
            text-transform:uppercase;
            font-size:11px;
            letter-spacing:.03em;
            font-weight:600;
            border-bottom:1px solid var(--border-soft);
        }

        table tr:last-child td{
            border-bottom:none;
        }

        table tbody tr:hover td{
            background:#FAFBFE;
        }

        .btn-custom{
            background:var(--accent-blue);
            color:white;
            padding:10px 18px;
            border-radius:10px;
            text-decoration:none;
            font-size:14px;
            font-weight:500;
            display:inline-block;
        }

        .btn-custom:hover{
            background:#141FA0;
            color:white;
        }

        /* ===== Mobile / tablet responsiveness ===== */

        .sidebar-overlay{
            display:none;
            position:fixed;
            inset:0;
            background:rgba(15,20,50,.45);
            z-index:1040;
        }

        .menu-toggle-btn{
            display:none;
            border:none;
            background:#F4F6FB;
            color:var(--ink);
            width:38px;
            height:38px;
            border-radius:10px;
            align-items:center;
            justify-content:center;
            font-size:18px;
            flex-shrink:0;
            cursor:pointer;
        }

        @media (max-width: 991px){
            .sidebar{
                transform:translateX(-100%);
            }
            .sidebar.is-open{
                transform:translateX(0);
            }
            .sidebar-overlay.is-open{
                display:block;
            }
            .content{
                margin-left:0;
                padding:0 16px 24px;
            }
            .topbar{
                margin:0 -16px 20px;
                padding:0 16px;
            }
            .menu-toggle-btn{
                display:flex;
            }
            .topbar-search{
                width:auto;
                max-width:none;
                flex:1;
            }
            .topbar-org span{
                display:none;
            }
        }

        @media (max-width: 575px){
            .topbar-search{
                display:none;
            }
        }

    </style>
    <link rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

</head>

<body>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script>
    function toggleSidebar() {
        document.querySelector('.sidebar').classList.toggle('is-open');
        document.getElementById('sidebarOverlay').classList.toggle('is-open');
    }
    document.addEventListener('DOMContentLoaded', function () {
        document.getElementById('sidebarOverlay').addEventListener('click', toggleSidebar);
    });
</script>