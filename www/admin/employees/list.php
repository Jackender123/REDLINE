<?php
/** Legacy Staff & Team URL. Staff creation now lives in Technicians & Assignments. */
declare(strict_types=1);
header('Location: ../technicians/assignments.php', true, 303);
exit;
