<?php
session_start();
require '../connection.php';

use MongoDB\BSON\Regex;

// ✅ Chỉ cho admin (trung tâm) vào
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: trangchu.php");
    exit();
}

// Ở TRUNG TÂM: dùng customers + orders_central
$customersCol     = $db->customers;
$ordersCentralCol = $db->orders_central;

// ====== LỌC / TÌM KIẾM ======
$searchText   = trim($_GET['q']      ?? '');   // username / display_name
$searchBranch = trim($_GET['branch'] ?? '');   // HN, HCM...

$filter = [];

if ($searchText !== '') {
    $regex = new Regex($searchText, 'i');
    $filter['$or'] = [
        ['username'     => $regex],
        ['display_name' => $regex],
    ];
}

if ($searchBranch !== '' && $searchBranch !== 'all') {
    $filter['branch_id'] = $searchBranch;
}

// ====== PHÂN TRANG ======
$perPage = 20;
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$skip    = ($page - 1) * $perPage;

$totalCustomers = $customersCol->count($filter);
$totalPages     = max(1, ceil($totalCustomers / $perPage));

$cursor    = $customersCol->find(
    $filter,
    [
        'sort'  => ['branch_id' => 1, 'username' => 1],
        'skip'  => $skip,
        'limit' => $perPage
    ]
);
$customers = $cursor->toArray();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản lý khách hàng (Trung tâm)</title>
    <link rel="stylesheet" href="../css/lichsumuahang.css">
    <style>
        .user-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .user-table th, .user-table td { border: 1px solid #ddd; padding: 8px; }
        .user-table th { background: #f2f2f2; }
        .btn-small { padding: 4px 8px; border-radius: 4px; text-decoration: none;
                     border: 1px solid #c49b63; font-size: 13px; }
        .btn-history { background: #f8f1e7; }
        .filter-form input, .filter-form select { padding: 5px 8px; margin-right: 6px; }
        .filter-form button { padding: 6px 10px; }
        .page-link { padding: 4px 8px; margin: 0 2px; text-decoration: none;
                     border: 1px solid #ccc; border-radius: 4px; }
        .page-link.active { background: #c49b63; color: #fff; border-color: #c49b63; }
    </style>
</head>
<body>
<div class="page-overlay">
    <div class="container">

        <a href="trangchu.php" class="btn-back">⬅ Về Trang chủ</a>

        <h2>📚 Quản lý khách hàng toàn hệ thống (Trung tâm)</h2>

        <!-- FORM TÌM KIẾM / LỌC -->
        <form method="get" class="filter-form">
            <input type="text" name="q"
                   placeholder="Tìm theo username / tên hiển thị..."
                   value="<?= htmlspecialchars($searchText, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">

            <select name="branch">
                <option value="all">-- Tất cả chi nhánh --</option>
                <option value="HN"  <?= $searchBranch === 'HN'  ? 'selected' : ''; ?>>Hà Nội</option>
                <option value="HCM" <?= $searchBranch === 'HCM' ? 'selected' : ''; ?>>TP. HCM</option>
                <option value="DN"  <?= $searchBranch === 'DN'  ? 'selected' : ''; ?>>Đà Nẵng</option>
            </select>

            <button type="submit">🔍 Tìm kiếm</button>
            <a href="quanlynguoidung.php" class="page-link">Xóa lọc</a>
        </form>

        <p>Tổng khách hàng (theo bộ lọc): <strong><?= (int)$totalCustomers; ?></strong></p>

        <?php if (empty($customers)): ?>
            <p>Không tìm thấy khách hàng nào.</p>
        <?php else: ?>
            <table class="user-table">
                <thead>
                <tr>
                    <th>Username</th>
                    <th>Tên hiển thị</th>
                    <th>Chi nhánh</th>
                    <th>Số dư (đ)</th>
                    <th>Tổng đơn</th>
                    <th>Đang mượn</th>
                    <th>Hành động</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $c): ?>
                    <?php
                    $username    = $c['username']     ?? '';
                    $displayName = $c['display_name'] ?? '';
                    $branchId    = $c['branch_id']    ?? 'HN';
                    $balance     = (int)($c['balance'] ?? 0);

                    $totalOrders = $ordersCentralCol->count([
                        'username'  => $username,
                        'branch_id' => $branchId,
                    ]);

                    $currentBorrow = $ordersCentralCol->count([
                        'username'  => $username,
                        'branch_id' => $branchId,
                        'status'    => ['$ne' => 'returned'],
                    ]);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($username, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($displayName, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($branchId, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                        <td><?= number_format($balance, 0, ',', '.'); ?></td>
                        <td><?= (int)$totalOrders; ?></td>
                        <td><?= (int)$currentBorrow; ?></td>
                        <td>
                            <a class="btn-small btn-history"
                               href="lichsumuahangadmin.php?username=<?= urlencode($username); ?>&branch=<?= urlencode($branchId); ?>">
                                📜 Xem lịch sử mượn
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div class="pagination" style="margin-top:10px;">
                    <?php if ($page > 1): ?>
                        <?php $q = $_GET; $q['page'] = $page - 1; ?>
                        <a class="page-link"
                           href="quanlynguoidung.php?<?= htmlspecialchars(http_build_query($q), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">&laquo; Trước</a>
                    <?php endif; ?>

                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <?php $q = $_GET; $q['page'] = $p; ?>
                        <a class="page-link <?= $p == $page ? 'active' : ''; ?>"
                           href="quanlynguoidung.php?<?= htmlspecialchars(http_build_query($q), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">
                            <?= $p; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <?php $q = $_GET; $q['page'] = $page + 1; ?>
                        <a class="page-link"
                           href="quanlynguoidung.php?<?= htmlspecialchars(http_build_query($q), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">Sau &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>
</body>
</html>
