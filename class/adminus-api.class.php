<?php
class AdminusAPI
{
private $host;
private $user;
private $pass;

function __construct($host, $user, $pass, $cookie = false)
{
	$this->host = $host;
	$this->user = $user;
	$this->pass = $pass;
	$this->cookie = $cookie;
}

function loadJson($query)
{
	// API authorization
	if (!function_exists('curl_init'))
	{
		$context = stream_context_create(array(
		    'http' => array(
		        'header'  => "Authorization: Basic " . base64_encode($this->user . ':' . $this->pass),
			'ignore_errors' => true // because Adminus returns 404 if "No items found"
		    )
		));
		$json = @file_get_contents($this->host . $query, false, $context);
	}
	else
	{
		$curl = curl_init($this->host . $query);
		curl_setopt($curl, CURLOPT_USERPWD, $this->user . ':' . $this->pass);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		if ($this->cookie) curl_setopt($curl, CURLOPT_COOKIEJAR, $this->cookie);
		$json = curl_exec($curl);
	}

	$decode = json_decode($json);
	if (isset($decode->code) && ($decode->code == 200)) return $decode;
	if (isset($decode->code) && ($decode->code == 404)) return $decode;
	return false;
}

function parseContractDetailsForRadius($contract)
{
	$radius = new stdClass();
	$radius->contract_id = $contract->id;
	$radius->customer_id = $contract->customer_id;
	$radius->customer_card = $contract->customer_card;

	$radius->auth = new stdClass();
	if (is_array($contract->attributes)) foreach ($contract->attributes as $attribute)
	{
		if ($attribute->name == ATTRIBUTE_USER) $radius->auth->username = $attribute->value;
		if ($attribute->name == ATTRIBUTE_PASS) $radius->auth->password = $attribute->value;
	}

	$radius->network = new stdClass();
	$radius->network->ipv4 = new stdClass();
	$radius->network->ipv4->address = array();
	$radius->network->ipv4->prefix = array();
	$radius->network->ipv6 = new stdClass();
	$radius->network->ipv6->address = array();
	$radius->network->ipv6->prefix = array();
	if (is_array($contract->ip_addresses)) foreach ($contract->ip_addresses as $ip)
	{
		@list($address, $mask) = explode('/', $ip->ip);

		if ($addressx = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
		{
			if ($maskx = filter_var($mask, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => 32))))
			{
				$radius->network->ipv4->prefix[] = $addressx . '/' . $maskx;
			}
			else
			{
				$radius->network->ipv4->address[] = $addressx;
			}
		}
		if ($addressx = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
		{
			if ($maskx = filter_var($mask, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => 128))))
			{
				$radius->network->ipv6->prefix[] = $addressx . '/' . $maskx;
			}
			else
			{
				$radius->network->ipv6->address[] = $addressx;
			}
		}

	}
	return $radius;
}
}
?>