<?php
session_start();
require __DIR__ . '/../connection.php';
 // file kết nối MongoDB

$message = "";

// Nếu đã đăng nhập thì chuyển qua trang chủ
if (!empty($_SESSION['user_id'])) {
    header("Location: trangchu.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password_plain = $_POST['password'] ?? '';

    if ($username === '' || $password_plain === '') {
        $message = "Vui lòng nhập tên đăng nhập và mật khẩu.";
    } else {

        // Lấy collection users
        $collection = $db->users;

        // Tìm user theo username
        $user = $collection->findOne(['username' => $username]);

        if ($user && isset($user['password']) && password_verify($password_plain, $user['password'])) {

            // Password đúng → đăng nhập thành công
            session_regenerate_id(true);

            $_SESSION['user_id']  = (string)$user['_id'];     // ObjectId -> string
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];

            // Nếu bạn muốn admin vào trang quản trị → check tại đây
            // if ($user['role'] === 'admin') header("Location: admin/home.php");

            header("Location: trangchu.php");
            exit;

        } else {
            $message = "Sai tên đăng nhập hoặc mật khẩu.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Đăng nhập</title>
    <link rel="stylesheet" href="../css/dangnhap1.css">
</head>
<body>
<div class="page-overlay">
    <div class="login-container">
        <h2>Đăng nhập</h2>

        <?php if ($message !== ""): ?>
            <p style="color:red;">
                <?= htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
            </p>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <label for="username">Tên đăng nhập:</label>
            <input 
                type="text" 
                id="username" 
                name="username" 
                required
                value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : ''; ?>"
            >

            <label for="password">Mật khẩu:</label>
            <input type="password" id="password" name="password" required>

            <button type="submit">Đăng nhập</button>
        </form>

        <p>Chưa có tài khoản? <a href="dangky.php">Đăng ký tại đây</a></p>
    </div>
    </div> <!-- đóng page-overlay -->
</body>

</html>
