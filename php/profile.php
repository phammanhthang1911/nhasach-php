<?php
session_start();
require __DIR__ . '/../connection.php'; // kết nối MongoDB

// Chặn người chưa đăng nhập
if (empty($_SESSION['user_id'])) {
    header("Location: dangnhap.php");
    exit();
}

// Lấy thông tin session
$userId   = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Khách';
$role     = $_SESSION['role'] ?? 'customer';

// Lấy thông tin chi tiết user từ MongoDB
$collection = $db->users;

// ObjectId trong MongoDB
$user = $collection->findOne([
    '_id' => new MongoDB\BSON\ObjectId($userId)
]);

if (!$user) {
    // Nếu có vấn đề (user không tồn tại nữa)
    session_unset();
    session_destroy();
    header("Location: dangnhap.php");
    exit();
}

// Lấy số dư (mặc định 0 nếu chưa có)
$balance = isset($user['balance']) ? (int)$user['balance'] : 0;

// Xử lý (tạm thời) nút "Nạp tiền" – demo
$notify = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'nap_tien') {
    // Ở đây bạn tự xử lý logic nạp tiền thực tế sau
    $notify = "Chức năng nạp tiền đang được phát triển. Vui lòng liên hệ quản trị viên.";
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Thông tin tài khoản</title>
    <link rel="stylesheet" href="../css/profile1.css">
</head>
<body>
<div class="page-overlay">

    <div class="profile-container">
        <h2>Thông tin tài khoản</h2>

        <?php if ($notify !== ""): ?>
            <p class="notify"><?= htmlspecialchars($notify, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></p>
        <?php endif; ?>

        <div class="profile-row">
            <span class="label">Tên đăng nhập:</span>
            <span class="value"><?= htmlspecialchars($username, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></span>
        </div>

        <div class="profile-row">
            <span class="label">Vai trò:</span>
            <span class="value">
                <?= $role === 'admin' ? 'Quản trị viên' : 'Khách hàng'; ?>
            </span>
        </div>

        <div class="profile-row">
            <span class="label">Số dư tài khoản:</span>
            <span class="value highlight">
                <?= number_format($balance, 0, ',', '.') ?> VNĐ
            </span>
        </div>

        <form method="post" class="profile-actions">
            <input type="hidden" name="action" value="nap_tien">
            <button type="submit" class="btn-topup">Nạp tiền</button>
            <a href="trangchu.php" class="btn-back">Về trang chủ</a>
        </form>
    </div>

</div>
</body>
</html>
