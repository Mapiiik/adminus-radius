<?php
require_once('config/config.php');
require_once('class/adminus-api.class.php');

$timestamp = time();

echo '************************************************************' . "\n";
echo '* Updating all contracts in Radius (full reset and reload) *' . "\n";
echo '************************************************************' . "\n";

// Connect to Radius DB
echo 'Connecting to Radius DB... ';
if ($db = new PDO(RADIUS_DB_PDO))
{
	echo 'OK' . "\n";
}
else
{
	die('Problem with DB connection' . "\n");
}

// START load data from Adminus CRM
echo 'Loading active contracts from Adminus CRM... ' . "\n";
$api = new AdminusAPI(API_HOST, API_USER, API_PASS, COOKIE_FILE);
$contracts = $api->loadJson('/contract-detail/only-active');

if (!(isset($contracts->data) && is_array($contracts->data))) die('Failure when loading active contracts from Adminus CRM' . "\n");

$radius = array();
foreach($contracts->data as $contract)
{
	if ($contract->_state_is_active == 1 && $contract->customer_is_active == 1)
	{
		echo 'Processing customer ' . $contract->customer_id . ' - contract ' . $contract->id . ' ... ';

		$radius_item = $api->parseContractDetailsForRadius($contract);

		if (!(isset($radius_item->auth->username) && is_string($radius_item->auth->username) && $radius_item->auth->username <> ''))
		{
			echo 'OK (no username)' . "\n";
			continue;
		}

		$parameters = $api->loadJson('/adminus-parameters/contract/' . $contract->id);
		if (is_object($parameters->data))
		{
			$radius_item->speed = new stdClass();
			if (is_numeric($parameters->data->{PARAMETER_SPEED_UPLOAD})) $radius_item->speed->upload = $parameters->data->{PARAMETER_SPEED_UPLOAD};
			if (is_numeric($parameters->data->{PARAMETER_SPEED_DOWNLOAD})) $radius_item->speed->download = $parameters->data->{PARAMETER_SPEED_DOWNLOAD};
		}
		else
		{
			die('Failure when loading technical parameters from Adminus CRM' . "\n");
		}
		unset($parameters);
		$radius[$contract->id] = $radius_item;
		unset($radius_item);
		echo 'OK' . "\n";
	}
	else echo 'Skipping customer ' . $contract->customer_id . ' - contract ' . $contract->id . ' ... OK (not active)' . "\n";
}
echo 'Loading done' . "\n";
unset($contracts);
unset($api);
// END load data from Adminus CRM

// START save data do Radius DB
if ($db)
{
	echo 'Saving data to Radius DB... ';
	$db->beginTransaction();
	$truncate_radcheck = $db->prepare('TRUNCATE TABLE radcheck;');
	if (!$truncate_radcheck->execute()) die($truncate_radcheck->errorInfo()[2]);

	$truncate_radreply = $db->prepare('TRUNCATE TABLE radreply;');
	if (!$truncate_radreply->execute()) die($truncate_radreply->errorInfo()[2]);

	if (RADIUS_DB_RESET_SEQUENCES)
	{
		$sequence_restart_radcheck = $db->prepare('ALTER SEQUENCE radcheck_id_seq RESTART WITH 1;');
		if (!$sequence_restart_radcheck->execute()) die($sequence_restart_radcheck->errorInfo()[2]);

		$sequence_restart_radreply = $db->prepare('ALTER SEQUENCE radreply_id_seq RESTART WITH 1;');
		if (!$sequence_restart_radreply->execute()) die($sequence_restart_radreply->errorInfo()[2]);
	}

	$insert_radcheck = $db->prepare('INSERT INTO radcheck(username, attribute, op, value, "customerId", "contractId") VALUES (:username, :attribute, \':=\', :value, :customerId, :contractId);');
	$insert_radreply = $db->prepare('INSERT INTO radreply(username, attribute, op, value) VALUES (:username, :attribute, \'=\', :value);');

	foreach ($radius as $radius_item)
	{
		if (!$insert_radcheck->execute(array(
			':username' => $radius_item->auth->username,
			':attribute' => RADIUS_ATTRIBUTE_PASSWORD,
			':value' => $radius_item->auth->password,
			':customerId' => $radius_item->customer_id,
			':contractId' => $radius_item->contract_id,
		))) die($insert_radcheck->errorInfo()[2]);


		foreach ($radius_item->network->ipv4->address as $ipv4)
		{
			if (!$insert_radreply->execute(array(
				':username' => $radius_item->auth->username,
				':attribute' => RADIUS_ATTRIBUTE_IPV4_ADDRESS,
				':value' => $ipv4
			))) die($insert_radreply->errorInfo()[2]);
		}

		foreach ($radius_item->network->ipv6->prefix as $ipv6prefix)
		{
			if (!$insert_radreply->execute(array(
				':username' => $radius_item->auth->username,
				':attribute' => RADIUS_ATTRIBUTE_IPV6_PREFIX,
				':value' => $ipv6prefix
			))) die($insert_radreply->errorInfo()[2]);
		}

		if ((is_numeric($radius_item->speed->upload) && is_numeric($radius_item->speed->download)) && ($radius_item->speed->upload <> 0 || $radius_item->speed->download <> 0))
		{
			if (!$insert_radreply->execute(array(
				':username' => $radius_item->auth->username,
				':attribute' => RADIUS_ATTRIBUTE_SPEED_LIMIT,
				':value' => $radius_item->speed->upload . 'M/' . $radius_item->speed->download . 'M'
			))) die($insert_radreply->errorInfo()[2]);
		}
	}

	$db->commit();
	file_put_contents(LAST_SYNC_FILE, $timestamp);
	echo 'OK' . "\n";
}
else echo 'Problem with DB connection' . "\n";
// END save data do Radius DB
?>