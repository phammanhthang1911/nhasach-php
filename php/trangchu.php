<?php
session_start();

// Ngăn cache trình duyệt
header("Expires: Tue, 03 Jul 2001 06:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Connection: close");

// Kiểm tra đăng nhập
if (empty($_SESSION['user_id'])) {
    header("Location: dangnhap.php");
    exit();
}

// Xử lý logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: dangnhap.php");
    exit();
}

// Lấy thông tin user
$username = $_SESSION['username'] ?? "Khách";
$role     = $_SESSION['role'] ?? "customer";

?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Trang chủ - Nhà Sách</title>
    <link rel="stylesheet" href="../css/trangchu1.css">

    <script>
        window.addEventListener("pageshow", function (event) {
            if (event.persisted) {
                location.reload();
            }
        });
    </script>
</head>

<body>
<div class="page-overlay">
    <div class="navbar">
        <div class="nav-menu">
    <a href="trangchu.php">Trang chủ</a>

    <?php if ($role === 'customer'): ?>
        <!-- Menu cho khách hàng -->
        <a href="danhsachsach.php">Danh sách sách</a>
        <a href="giohang.php">Giỏ hàng</a>
        <a href="lichsumuahang.php">Lịch sử đơn hàng</a>
    <?php elseif ($role === 'admin'): ?>
        <!-- Menu cho admin -->
        <a href="quanlysach.php">Quản lý sách</a>
        <a href="quanlynguoidung.php">Quản lý người dùng</a>
      <!--  <a href="donhangmoi.php">Đơn hàng mới</a> -->
        <!-- <a href="quanlytrasach.php">Quản lý trả sách</a> -->
    <?php endif; ?>
</div>
        <div class="nav-user">
            <!-- Nút Profile -->
            <a class="btn-profile" href="profile.php">
                Xin chào, <?= htmlspecialchars($username, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
            </a>

            <!-- Nút Đăng xuất -->
            <a class="btn-logout" href="trangchu.php?action=logout">Đăng xuất</a>
        </div>
    </div>

    <!-- chỗ này sau này bạn thêm nội dung trang chủ -->
</div>
</body>
</html>
