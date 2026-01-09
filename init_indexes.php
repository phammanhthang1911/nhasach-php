<?php
// CHẠY FILE NÀY 1 LẦN ĐỂ TẠO INDEX CHO HỆ THỐNG

require 'connection.php';


echo "<pre>";

try {
    // ===== USERS: index username =====
    $users = $db->users;
    $users->createIndex(
        ['username' => 1],
        ['unique' => true, 'name' => 'idx_username_unique']
    );
    echo "✔ users: tạo index unique cho username\n";

    // ===== BOOKS: các index quan trọng =====
    $books = $db->books;

    // Mã sách: unique toàn hệ thống
    $books->createIndex(
        ['bookCode' => 1],
        ['unique' => true, 'name' => 'idx_bookCode_unique']
    );
    echo "✔ books: tạo index unique cho bookCode\n";

    // Tên sách + khu vực: unique trong cùng chi nhánh
    $books->createIndex(
        ['location' => 1, 'bookName' => 1],
        ['unique' => true, 'name' => 'idx_location_bookName_unique']
    );
    echo "✔ books: tạo index unique cho (location, bookName)\n";

    // Nhóm sách (lọc theo nhóm)
    $books->createIndex(
        ['bookGroup' => 1],
        ['name' => 'idx_bookGroup']
    );
    echo "✔ books: tạo index cho bookGroup\n";

    // Khu vực
    $books->createIndex(
        ['location' => 1],
        ['name' => 'idx_location']
    );
    echo "✔ books: tạo index cho location\n";

    // Trạng thái
    $books->createIndex(
        ['status' => 1],
        ['name' => 'idx_status']
    );
    echo "✔ books: tạo index cho status\n";

    // Lượt mượn (để sau này sort theo độ hot)
    $books->createIndex(
        ['borrowCount' => -1],
        ['name' => 'idx_borrowCount_desc']
    );
    echo "✔ books: tạo index cho borrowCount\n";

    // FULL-TEXT SEARCH: tên sách + nhóm sách
    $books->createIndex(
        ['bookName' => 'text', 'bookGroup' => 'text'],
        ['name' => 'idx_books_text_search']
    );
    echo "✔ books: tạo TEXT INDEX cho (bookName, bookGroup)\n";

    echo "\nHOÀN TẤT TẠO INDEX.\n";
} catch (Exception $e) {
    echo "LỖI: " . $e->getMessage() . "\n";
}

echo "</pre>";
