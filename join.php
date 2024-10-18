<?php
// Database connection
$host = "host";
$dbname = "db_name";
$user = "db_user";
$pass = "";

$conn = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);

// Join server by token
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    
    $stmt = $conn->prepare("SELECT server_id FROM invites WHERE invite_code = :code");
    $stmt->execute(['code' => $code]);
    $invite = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($invite) {
        session_start();
        $username = $_SESSION['username'] ?? null;
        
        if ($username) {
            $server_id = $invite['server_id'];

            // Add user to server members
            $stmt = $conn->prepare("INSERT INTO server_members (server_id, username) VALUES (:server_id, :username)");
            $stmt->execute(['server_id' => $server_id, 'username' => $username]);

            echo "You have successfully joined the server!";
        } else {
            echo "Please log in to join the server.";
        }
    } else {
        echo "Invalid or expired invite code.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Server</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #36393f;
            color: #ffffff;
            margin: 0;
            padding: 20px;
        }
        h2 {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <h2>Join Server</h2>
    <p><?php echo isset($_GET['code']) ? "Trying to join server..." : "No code provided."; ?></p>
</body>
</html>
