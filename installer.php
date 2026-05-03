<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbhost = $_POST['dbhost'];
    $dbname = $_POST['dbname'];
    $dbuser = $_POST['dbuser'];
    $dbpass = $_POST['dbpass'];

    $conn = new mysqli($dbhost, $dbuser, $dbpass);
    if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

    // Create database if not exists
    $conn->query("CREATE DATABASE IF NOT EXISTS `$dbname`");
    $conn->select_db($dbname);

    // Import schema
    $schema = file_get_contents('users.sql');
    if (!$conn->multi_query($schema)) {
        die("Schema import failed: " . $conn->error);
    }
    while ($conn->more_results() && $conn->next_result()) {;}

    // Write config.php
    $config = "<?php\n"
            . "\$host = '$dbhost';\n"
            . "\$dbname = '$dbname';\n"
            . "\$dbuser = '$dbuser';\n"
            . "\$dbpass = '$dbpass';\n"
            . "\$conn = new mysqli(\$host, \$dbuser, \$dbpass, \$dbname);\n"
            . "if (\$conn->connect_error) die('Connection failed: ' . \$conn->connect_error);\n?>";

    file_put_contents('config.php', $config);
    echo "✅ Installation complete. <a href='register.php'>Create admin account</a>";
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Install Script</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
  <form method="POST" class="p-4 bg-white shadow rounded" style="min-width: 350px;">
    <h4 class="mb-3 text-center">Database Setup</h4>
    <input name="dbhost" class="form-control mb-2" placeholder="DB Host" value="localhost" required>
    <input name="dbname" class="form-control mb-2" placeholder="DB Name" required>
    <input name="dbuser" class="form-control mb-2" placeholder="DB User" required>
    <input name="dbpass" class="form-control mb-2" placeholder="DB Password" required>
    <button class="btn btn-primary w-100">Install</button>
  </form>
</body>
</html>