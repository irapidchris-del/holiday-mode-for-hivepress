<?php
/**
 * Runs the whole test matrix.
 *
 * The plugin behaves differently depending on which HivePress extensions are
 * present, so the logic tests are run once per meaningful combination rather
 * than only in the default one.
 *
 * Usage: php tests/run.php
 *
 * @package Holiday_Mode_For_HivePress
 */

$runs = [
	'logic: baseline'                 => [ 'logic-tests.php', [] ],
	'logic: Badges active'            => [ 'logic-tests.php', [ 'HM_BADGES' => '1' ] ],
	'logic: Messages active'          => [ 'logic-tests.php', [ 'HM_MESSAGES' => '1' ] ],
	'logic: no Subscriptions, no Vendor model' => [ 'logic-tests.php', [ 'HM_WCS' => 'absent', 'HM_VENDOR' => 'absent' ] ],
	'updater'                         => [ 'updater-tests.php', [] ],
];

$total_passed = 0;
$total_failed = 0;
$failed_runs  = [];

foreach ( $runs as $label => $run ) {
	list( $script, $env ) = $run;

	$prefix = '';

	foreach ( $env as $key => $value ) {
		// Only the value is escaped: a shell assignment's name must be bare.
		$prefix .= $key . '=' . escapeshellarg( $value ) . ' ';
	}

	$command = $prefix . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/' . $script );

	exec( $command . ' 2>&1', $output, $status );

	$summary = '';

	foreach ( $output as $line ) {
		if ( 0 === strpos( $line, 'RESULT:' ) ) {
			$summary = $line;
		}
	}

	if ( preg_match( '/RESULT: (\d+) passed, (\d+) failed/', $summary, $m ) ) {
		$total_passed += (int) $m[1];
		$total_failed += (int) $m[2];
	}

	if ( 0 !== $status ) {
		$failed_runs[] = $label;

		// Show the detail only for runs that failed, so a green matrix stays
		// readable but a failure is never silent.
		echo "\n--- $label ---\n" . implode( "\n", $output ) . "\n";
	}

	printf( "%-45s %s\n", $label, 0 === $status ? 'OK   ' . $summary : 'FAIL ' . $summary );

	$output = [];
}

echo str_repeat( '-', 60 ) . "\n";
printf( "TOTAL: %d passed, %d failed\n", $total_passed, $total_failed );

if ( $failed_runs ) {
	echo 'Failing runs: ' . implode( ', ', $failed_runs ) . "\n";
}

exit( $failed_runs || $total_failed ? 1 : 0 );
