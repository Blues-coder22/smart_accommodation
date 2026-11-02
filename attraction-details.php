<?php
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
header('Location: tourist/attraction.php?id=' . $id, true, 302);
exit;
