<?php

// The site's root page has no content of its own -- it only decides where to send a
// visitor next, depending on whether they are already signed in.
require_once __DIR__ . '/../app/bootstrap.php';
if (!current_user()) { redirect('login.php'); }
redirect('dashboard.php');
