<?php
require_once __DIR__ . '/../app/bootstrap.php';
if (!current_user()) { redirect('login.php'); }
redirect('tickets.php');

