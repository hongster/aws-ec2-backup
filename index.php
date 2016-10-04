#!/usr/bin/php
<?php
require 'vendor/autoload.php';

use Aws\Credentials\CredentialProvider;
use Aws\Ec2\Ec2Client;

// Load global config
$config = include realpath(__DIR__.'/config.php');

/**
 * Delete snaphots older than `$daysToKeep` days. If this is the last backup, it will not
 * be deleted.
 * @param array $snapshots
 * @param Aws\Ec2\Ec2Client $client
 * @param int $daysToKeep Number of days to keep snapshots.
 */
function deleteExpiredSnapshots($snapshots, $client, $daysToKeep) {
	global $config;

	// Latest snapshot first, oldest snapshot last
	usort($snapshots, function($snapA, $snapB) {
		return $snapB['StartTime']->getTimestamp() - $snapA['StartTime']->getTimestamp();
	});

	$now = time();
	$expiry = $daysToKeep * 86400;
	for ($i = 0; $i < count($snapshots); $i++) {
		// Skip those that have not expired yet
		if ($now - $snapshots[$i]['StartTime']->getTimestamp() < $expiry)
			continue;

		if ($i == 0) {
			echo "All snapshots have expired, {$snapshots[$i]['SnapshotId']} will be preserved, and the rest will be deleted.\n";
			continue;
		}

		// Ref: http://docs.aws.amazon.com/aws-sdk-php/v3/api/api-ec2-2016-04-01.html#deletesnapshot
		echo "Deleting {$snapshots[$i]['SnapshotId']}.\n";
		$client->deleteSnapshot(['DryRun' => false, 'SnapshotId' => $snapshots[$i]['SnapshotId']]);
	}
}

/**
 * Create new snapshot if the latest snapshot is created > 24hr ago.
 * Ref: http://docs.aws.amazon.com/aws-sdk-php/v3/api/api-ec2-2016-04-01.html#createsnapshot
 * @param array $snpahosts Existing snapshots.
 * @param Aws\Ec2\Ec2Client $client
 * @param string $volumeId
 * @param Aws\Result Snapshot info.
 */
function createSnapshot($snapshots, $client, $volumeId) {
	$now = time();

	// Check if there is fresh snapshot
	// XXX Hardcode 24hr (86400s) here, plus 60s delayed-creation allowance
	foreach ($snapshots as $snapshot) {
		if ($now - $snapshot['StartTime']->getTimestamp() < 86340) {
			echo "Skipping snapshot creation, fresh snapshot created at ".$snapshot['StartTime'].".\n";
			return null;
		}
	}

	echo "Creating new snapshot.\n";
	return $client->createSnapshot([
		'DryRun' => false,
		'VolumeId' => $volumeId,
		'Description' => 'Created by backup script.',
	]);
}

/**
 * Add the following 2 tags
 * - Name: <credentialName>_<YYmmddHHiiss>
 * - Backup: true
 * @param Aws\Ec2\Ec2Client $client
 * @param string $credentialName
 * @param arrau $snapshot
 */
function addBackupTag($client, $credentialName, $snapshot) {
	echo "Tagging snapshot ".$snapshot->get('SnapshotId').".\n";
	$client->createTags([
		'DryRun' => false,
		'Resources' => [$snapshot->get('SnapshotId')],
		'Tags' => [
			['Key' => 'Name', 'Value' => $credentialName.'_'.date('ymdHi', $snapshot->get('StartTime')->getTimestamp())],
			['Key' => 'Backup', 'Value' => 'true'],
		],
	]);
}

/**
 * Pretty print list of snapshots. Useful for debugging purpose.
 * @uses AwEc2::describeSnapshots
 * @param Aws\Result List of snapshots.
 */
function printSnapshots($snapshots) {
	foreach ($snapshots as $snapshot) {
		echo "{$snapshot['SnapshotId']}\n";
		echo "{$snapshot['VolumeId']}\n";
		echo date('Y-m-d H:i:s').$snapshot['StartTime']->getTimestamp()."\n";

		if (isset($snapshot['Tags'])) {
			$tags = array_walk($snapshot['Tags'], function($tag, $index) {
				echo "{$tag['Key']}={$tag['Value']} | ";
			});
			echo "\n";
		}

		echo " = = = \n";
	}
}

/**
 * Main entry point of this backup script.
 */
function main() {
	global $config;

	require realpath(__DIR__.'/Logger.php');
	$logger = new Logger();
	$logger->setRecipients($config['log']['recipients']);
	$logger->startLogging();
	$logger->handleError();

	// Load credentials
	$credentials = include realpath(__DIR__.'/credentials.php');

	foreach ($credentials as $credentialName => $credential) {
		// Number of days to keep snaphots
		$daysToKeep = isset($credential['days_to_keep']) ? $credential['days_to_keep'] : 7;

		echo "Processing {$credentialName}.\n";
		$client = new Ec2Client([
			'version' => 'latest',
			'region' => isset($credential['region']) ? $credential['region'] : 'ap-southeast-1',
			'credentials' => $credential,
		]);

		$result = $client->describeSnapshots([
			'Filters' => [
				['Name' => 'volume-id', 'Values' => [$credential['volumeId']]],
				['Name' => 'tag:Backup', 'Values' => ['true']],
			],
		]);

		//printSnapshots($result->get('Snapshots'));

		$createdSnapShot = createSnapshot($result->get('Snapshots'), $client, $credential['volumeId']);
		if ($createdSnapShot) {
			addBackupTag($client, $credentialName, $createdSnapShot);
		}

		deleteExpiredSnapshots($result->get('Snapshots'), $client, $daysToKeep);
		echo "\n";
	}

	$logger->sendLog();
	$logger->endLogging();
}

// Let's start the party
main();
