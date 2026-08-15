<?php
$file = 'C:\xampp\htdocs\welfare\treasurer\members.php';
$content = file_get_contents($file);

// Use exact line content from the file
$search = "                                    </td>" . "\n" . "                                    <td>" . "\n" . "                                         <div class=\"btn-group\">";
$replace = "                                    </td>" . "\n" . "                                    <td>" . "\n" . "                                        <?php echo getExecutiveBadge(\$member['executive_level'] ?? 'none'); ?>" . "\n" . "                                    </td>" . "\n" . "                                    <td>" . "\n" . "                                         <div class=\"btn-group\">";

echo "Search pattern length: " . strlen($search) . "\n";
echo "Content contains pattern: " . (strpos($content, $search) !== false ? 'YES' : 'NO') . "\n";

if (strpos($content, $search) !== false) {
    $content = str_replace($search, $replace, $content);
    echo "Added executive badge column\n";
} else {
    echo "WARNING: Pattern not found\n";
}

file_put_contents($file, $content);
echo "Done\n";
?>
