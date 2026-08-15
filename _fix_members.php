<?php
$file = 'C:\xampp\htdocs\welfare\treasurer\members.php';
$content = file_get_contents($file);

// Fix 1: Add executive badge column
$search1 = "                                    </td>" . "\n" . "                                    <td>" . "\n" . "                                         <div class=\"btn-group\">";
$replace1 = "                                    </td>" . "\n" . "                                    <td>" . "\n" . "                                        <?php echo getExecutiveBadge(\$member['executive_level'] ?? 'none'); ?>" . "\n" . "                                    </td>" . "\n" . "                                    <td>" . "\n" . "                                         <div class=\"btn-group\">";

if (strpos($content, $search1) !== false) {
    $content = str_replace($search1, $replace1, $content);
    echo "Added executive badge column" . "\n";
} else {
    echo "WARNING: Could not find executive column insertion point" . "\n";
}

// Fix 2: Add row click handler
$search2 = "    // Promote to executive";
$replace2 = "    // Make entire member row clickable to open member detail" . "\n" . "    document.querySelectorAll('#membersTable tbody tr').forEach(function(row) {" . "\n" . "        row.style.cursor = 'pointer';" . "\n" . "        row.addEventListener('click', function(e) {" . "\n" . "            if (e.target.closest('button, a, input, select, textarea')) {" . "\n" . "                return;" . "\n" . "            }" . "\n" . "            var memberId = row.getAttribute('data-member-id');" . "\n" . "            if (memberId) {" . "\n" . "                window.location.href = '<?php echo APP_URL; ?>/treasurer/member_detail.php?member_id=' + encodeURIComponent(memberId);" . "\n" . "            }" . "\n" . "        });" . "\n" . "    });" . "\n\n" . "    // Promote to executive";

if (strpos($content, $search2) !== false) {
    $content = str_replace($search2, $replace2, $content);
    echo "Added row click navigation" . "\n";
} else {
    echo "WARNING: Could not find promote executive comment" . "\n";
}

file_put_contents($file, $content);
echo "Done" . "\n";
?>
