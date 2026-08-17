<?php

// The same transition, but attempted after the page has already started
// printing. A new identifier can only travel in a header, so this cannot work;
// what matters is that the page still finishes and the client is told nothing.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_report.php');

header('Content-Type: text/plain; charset=utf-8');

$before = session_id();

// Make sure the output really has been sent: with a buffer still open PHP would
// consider the headers unsent, and the case would prove nothing.
while (ob_get_level() > 0) {
	ob_end_flush();
}
echo "output_started=yes\n";
flush();

$renewed = mcm_session_regenerate_id();

echo 'session_id_before=' . $before . "\n";
echo 'regenerated=' . mcm_probe_flag($renewed) . "\n";
echo 'session_id=' . session_id() . "\n";
echo "request_completed=yes\n";
