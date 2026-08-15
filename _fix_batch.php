<?php
$file = 'C:\xampp\htdocs\welfare\api\transactions.php';
$lines = file($file);
$newLines = [];
$i = 0;
while ($i < count($lines)) {
    if (strpos($lines[$i], 'Annual limit check per member') !== false) {
        $newLines[] = $lines[$i]; $i++;
        $newLines[] = $lines[$i]; $i++;
        $newLines[] = $lines[$i]; $i++;
        $newLines[] = $lines[$i]; $i++;
        $newLines[] = $lines[$i]; $i++;
        $newLines[] = $lines[$i]; $i++;
        $newLines[] = "                \$member_exec = \$db->prepare(\"SELECT executive_level FROM members WHERE member_id = :mid\")->execute([':mid' => \$mid]);\n";
        $newLines[] = "                \$exec_level = \$member_exec ? (\$member_exec['executive_level'] ?? 'none') : 'none';\n";
        $newLines[] = "                if (\$exec_level === 'gold') {\n";
        $newLines[] = "                    \$annual_limit = \$year_target['executive_gold_annual'];\n";
        $newLines[] = "                } elseif (\$exec_level === 'silver') {\n";
        $newLines[] = "                    \$annual_limit = \$year_target['executive_silver_annual'];\n";
        $newLines[] = "                } else {\n";
        $newLines[] = "                    \$annual_limit = \$year_target['annual_amount'];\n";
        $newLines[] = "                }\n";
        continue;
    }
    $newLines[] = $lines[$i];
    $i++;
}
file_put_contents($file, $newLines);
echo "Fixed batch payment annual limit\n";
?>
