<?php
require './connection.php'; // đường dẫn tùy bạn

use MongoDB\BSON\UTCDateTime;

$username = "admin";
$password = "123456"; // NHỚ đổi mật khẩu sau khi test

$collection = $db->users;

// Kiểm tra admin đã tồn tại chưa
$exists = $collection->findOne(['username' => $username]);

if ($exists) {
    die("Admin đã tồn tại!");
}

// Tạo admin
$insert = $collection->insertOne([
    'username'      => $username,
    'display_name'  => $username,
    'password'      => password_hash($password, PASSWORD_DEFAULT),
    'role'          => 'admin',
    'balance'       => 0,
    'created_at'    => new UTCDateTime()
]);

if ($insert->getInsertedCount() > 0) {
    echo "Tạo admin thành công!";
} else {
    echo "Lỗi tạo admin!";
}
