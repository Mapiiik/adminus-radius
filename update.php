<?php
require_once('config/config.php');
require_once('class/adminus-api.class.php');

$last_sync = file_get_contents(LAST_SYNC_FILE);
$timestamp = time();

echo '****************************************' . "\n";
echo '* Updating changed contracts in Radius *' . "\n";
echo '****************************************' . "\n";

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
echo 'Loading changed customers since ' . date(DATE_ATOM, $last_sync) . ' from Adminus CRM... ' . "\n";
$api = new AdminusAPI(API_HOST, API_USER, API_PASS, COOKIE_FILE);

$customers = $api->loadJson('/customer-detail/by-last-change-from/' . $last_sync);
if ($customers === false) die('Failure when loading changed users from Adminus CRM' . "\n");

$radius = array();
if (!is_array($customers->data)) echo 'No items found' . "\n";
else foreach($customers->data as $customer)
{
	// create array of processed customers - logins for their deactivated contracts will be removed
	$radius[$customer->id] = array();

	echo 'Loading contracts for customer ' . $customer->id . ' ... ' . "\n";
	$contracts = $api->loadJson('/contract-detail/by-customer/' . $customer->id);
	if ($contracts === false) die('Failure when loading customers contracts from Adminus CRM' . "\n");

	if (!is_array($contracts->data)) echo 'No items found' . "\n";
	else foreach($contracts->data as $contract)
	{
		if ($contract->_state_is_active == 1 && $contract->customer_is_active == 1)
		{
			echo 'Processing customer ' . $contract->customer_id . ' - contract ' . $contract->id . ' ... ';

			$radius_customer = $api->parseContractDetailsForRadius($contract);

			if (!(isset($radius_customer->auth->username) && is_string($radius_customer->auth->username) && $radius_customer->auth->username <> ''))
			{
				echo 'OK (no username)' . "\n";
				continue;
			}

			$parameters = $api->loadJson('/adminus-parameters/contract/' . $contract->id);
			var_dump($parameters);
			if (is_object($parameters->data))
			{
				$radius_customer->speed = new stdClass();
				if (is_numeric($parameters->data->{PARAMETER_SPEED_UPLOAD})) $radius_customer->speed->upload = $parameters->data->{PARAMETER_SPEED_UPLOAD};
				if (is_numeric($parameters->data->{PARAMETER_SPEED_DOWNLOAD})) $radius_customer->speed->download = $parameters->data->{PARAMETER_SPEED_DOWNLOAD};
			}
			else
			{
				die('Failure when loading technical parameters from Adminus CRM' . "\n");
			}
			$radius[$contract->customer_id][$contract->id] = $radius_customer;
			echo 'OK' . "\n";
		}
		else echo 'Skipping customer ' . $contract->customer_id . ' - contract ' . $contract->id . ' ... OK (not active)' . "\n";
	}
}
echo 'Loading done' . "\n";
// END load data from Adminus CRM

// START update data in Radius DB
if ($db)
{
	echo 'Updating data in Radius DB... ';
	$db->beginTransaction();

	$insert_radcheck = $db->prepare('INSERT INTO radcheck(username, attribute, op, value, "insertId", "insertDate", "typeId", "customerId", "contractId") VALUES (:username, \'Password\', \'==\', :password, 0, NOW(), 0, :customerId, :contractId);');
	$insert_radreply = $db->prepare('INSERT INTO radreply(username, attribute, op, value) VALUES (:username, :attribute, \'=\', :value);');

	foreach ($radius as $customerId => $customer)
	{
		// remove auth data for customer
		$delete_from_radreply = $db->prepare('DELETE FROM radreply WHERE username IN (SELECT username FROM radcheck WHERE "customerId" = :customer);');
		if (!$delete_from_radreply->execute(array(':customer' => $customerId))) die($delete_from_radreply->errorInfo()[2]);

		$delete_from_radcheck = $db->prepare('DELETE FROM radcheck WHERE "customerId" = :customer;');
		if (!$delete_from_radcheck->execute(array(':customer' => $customerId))) die($delete_from_radcheck->errorInfo()[2]);

		foreach ($customer as $contract)
		{
			$insert_radcheck->bindParam(':username', $contract->auth->username);
			$insert_radcheck->bindParam(':password', $contract->auth->password);
			$insert_radcheck->bindParam(':customerId', $contract->customer_id);
			$insert_radcheck->bindParam(':contractId', $contract->contract_id);
			if (!$insert_radcheck->execute()) die($insert_radcheck->errorInfo()[2]);

			foreach ($contract->network->ipv4->address as $ipv4)
			{
				if (!$insert_radreply->execute(array(
					':username' => $contract->auth->username,
					':attribute' => RADIUS_ATTRIBUTE_IPV4_ADDRESS,
					':value' => $ipv4
				))) die($insert_radreply->errorInfo()[2]);
			}

			foreach ($contract->network->ipv6->prefix as $ipv6prefix)
			{
				if (!$insert_radreply->execute(array(
					':username' => $contract->auth->username,
					':attribute' => RADIUS_ATTRIBUTE_IPV6_PREFIX,
					':value' => $ipv6prefix
				))) die($insert_radreply->errorInfo()[2]);
			}

			if ((is_numeric($contract->speed->upload) && is_numeric($contract->speed->download)) && ($contract->speed->upload <> 0 || $contract->speed->download <> 0))
			{
				if (!$insert_radreply->execute(array(
					':username' => $contract->auth->username,
					':attribute' => RADIUS_ATTRIBUTE_SPEED_LIMIT,
					':value' => $contract->speed->upload . 'M/' . $contract->speed->download . 'M'
				))) die($insert_radreply->errorInfo()[2]);
			}
		}
	}

	$db->commit();
	file_put_contents(LAST_SYNC_FILE, $timestamp);
	echo 'OK' . "\n";
}
else echo 'Problem with DB connection' . "\n";
// END update data in Radius DB
?>