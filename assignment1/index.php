<?php

/**
 * Notes:
 * - Not using short open tags, might not be supported on all hosts
 * - Added total bookings & total turnover for convenience
 * - Added a PuPHPet configured vagrant box, as I don't need webserver etc on Windows. I always use vagrant.
 */

// Set timezone we are working in, should be aligned with db or else set an offset to the db connection
date_default_timezone_set('Europe/Amsterdam');

// Load database connection, helpers, etc.
require_once(__DIR__ . '/errors.php');
require_once(__DIR__ . '/include.php');

// Vars
$period = 12; // Life-Time of 12 months
$commission = 0.10; // 10% commission

// Find bookers with their first booking <= T - $period
$timestamp = mktime(0, 0, 0, date('M') - $period, 1); // Use a predefined timestamp, faster than letting MySQL analyze each row
$periodBookers = [];
$sql = 'SELECT `bookers`.id, strftime(\'%m-%Y\', datetime(MIN(bookingitems.end_timestamp), \'unixepoch\')) AS firstBookingMonth
		FROM `bookers`
		INNER JOIN `bookings` ON `bookings`.`booker_id` = `bookers`.`id`
		INNER JOIN `bookingitems` ON `bookingitems`.`booking_id` = `bookings`.`id`
		WHERE `bookingitems`.`end_timestamp` <= ' . $timestamp . '
		GROUP BY user_id';
$results = $db
	->prepare($sql)
	->run()
;
foreach($results->fetchAll() as $row) {
	$periodBookers[$row->id] = $row->firstBookingMonth;
}

// For all users found, calculate their LTV, and add to their respective month
$periodLTV = [];
$periodCount = [];
$sql = 'SELECT bookings.booker_id, SUM(locked_total_price) AS lockedTotalPrice, COUNT(bookingitems.id) AS totalBookings
		FROM bookingitems
		INNER JOIN bookings ON bookings.id = bookingitems.booking_id
		WHERE bookings.booker_id IN(' . implode(', ', array_keys($periodBookers)) . ')
		GROUP BY bookings.booker_id';
$results = $db->prepare($sql)
	->run();
foreach($results->fetchAll() as $row) {
	// Find month
	$month = $periodBookers[$row->booker_id];

	// Add LTV
	if(!array_key_exists($month, $periodLTV)) {
		$periodLTV[$month] = 0;
		$periodCount[$month] = 0;
		$periodBookers[$month] = 0;
	}
	$periodLTV[$month] += $row->lockedTotalPrice;
	$periodCount[$month] += $row->totalBookings;
	$periodBookers[$month]++;
}

// Sort $periodLTV, else it just looks random, should be sorted on months
// Ksort doesnt work, as it doesnt sort correctly when same month in multiple years is present
uksort($periodLTV, function($a, $b) {
	$a = DateTime::createFromFormat('m-Y', $a);
	$b = DateTime::createFromFormat('m-Y', $b);

	return ($a == $b ? 0 : $a < $b ? -1 : 1);
});

?>
<!doctype html>
<html>
	<head>
		<title>Assignment 1: Create a Report (SQL)</title>
		<meta http-equiv="content-type" content="text/html; charset=utf-8" />
		<style type="text/css">
			.report-table
			{
				width: 100%;
				border: 1px solid #000000;
			}
			.report-table td,
			.report-table th
			{
				text-align: left;
				border: 1px solid #000000;
				padding: 5px;
			}
			.report-table .right
			{
				text-align: right;
			}
		</style>
	</head>
	<body>
		<h1>Report:</h1>
		<h2>Period: <?php echo $period; ?></h2>
		<table class="report-table">
			<thead>
				<tr>
					<th>Start</th>
					<th>Bookers</th>
					<th># of bookings</th>
					<th># of bookings (avg)</th>
					<th>Turnover</th>
					<th>Turnover (avg)</th>
					<th>LTV</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($periodLTV as $month => $turnover): ?>
					<tr>
						<td><?php echo $month; ?></td>
						<td><?php echo $periodBookers[$month]; ?></td>
						<td><?php echo number_format($periodCount[$month], 0); ?></td>
						<td><?php echo number_format($periodCount[$month] / $periodBookers[$month], 2, ',', '.'); ?></td>
						<td><?php echo number_format($turnover, 2, ',', '.'); ?></td>
						<td><?php echo number_format($turnover / $periodCount[$month], 2, ',', '.'); ?></td>
						<td><?php echo number_format($turnover * $commission, 2, ',', '.'); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<td colspan="6" class="right"><strong>Total rows:</strong></td>
					<td><?php echo count($periodLTV); ?></td>
				</tr>
			</tfoot>
		</table>
	</body>
</html>
