import pathlib
p = pathlib.Path(r'C:\xampp\htdocs\welfare\treasurer\members.php')
c = p.read_text(encoding='utf-8')

# Fix 1: executive badge column
old1 = '                                    </td>' + '\n' + '                                    <td>' + '\n' + '                                         <div class="btn-group">'
new1 = '                                    </td>' + '\n' + '                                    <td>' + '\n' + '                                        <?php echo getExecutiveBadge($member[' + "'" + 'executive_level' + "'" + '] ?? ' + "'" + 'none' + "'" + '); ?>' + '\n' + '                                    </td>' + '\n' + '                                    <td>' + '\n' + '                                         <div class="btn-group">'

print('exec found:', old1 in c)
c = c.replace(old1, new1)
print('exec done:', new1 in c)

# Fix 2: row click handler
old2 = '    // Promote to executive'
new2 = '    // Make entire member row clickable to open member detail' + '\n' + '    document.querySelectorAll("#membersTable tbody tr").forEach(function(row) {' + '\n' + '        row.style.cursor = "pointer";' + '\n' + '        row.addEventListener("click", function(e) {' + '\n' + '            if (e.target.closest("button, a, input, select, textarea")) {' + '\n' + '                return;' + '\n' + '            }' + '\n' + '            var memberId = row.getAttribute("data-member-id");' + '\n' + '            if (memberId) {' + '\n' + '                window.location.href = "<?php echo APP_URL; ?>/treasurer/member_detail.php?member_id=" + encodeURIComponent(memberId);' + '\n' + '            }' + '\n' + '        });' + '\n' + '    });' + '\n\n' + '    // Promote to executive'

print('js found:', old2 in c)
c = c.replace(old2, new2)
print('js done:', new2 in c)

p.write_text(c, encoding='utf-8')
print('DONE')
