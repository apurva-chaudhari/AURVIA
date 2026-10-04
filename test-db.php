<?php

require_once "config/database.php";

echo "<!DOCTYPE html>";
echo "<html>";
echo "<head>";
echo "<title>AURVIA Database Test</title>";
echo "</head>";

echo "<body style='
    font-family: Arial, sans-serif;
    background: #f7f5f2;
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
'>";

echo "<div style='
    background: white;
    padding: 40px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.10);
    text-align: center;
'>";

echo "<h1 style='color:#3F3D56;'>AURVIA</h1>";

echo "<h2 style='color:#2E8B70;'>
        Database Connected Successfully!
      </h2>";

echo "<p>
        PHP is successfully connected to
        <strong>aurvia_db</strong>.
      </p>";

echo "<p>
        MySQL Port:
        <strong>3307</strong>
      </p>";

echo "</div>";

echo "</body>";
echo "</html>";

?>